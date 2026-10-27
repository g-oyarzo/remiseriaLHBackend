<?php

declare(strict_types=1);

namespace Tests\Feature\Geolocalizacion;

use App\Enums\EstadoViaje;
use App\Enums\RolPersona;
use App\Events\UbicacionConductorActualizada;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Viaje;
use App\Services\Geolocalizacion\RegistroDeUbicaciones;
use App\ValueObjects\Coordinate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * POST /conductor/ubicacion: último punto en cache (Redis), respaldo en
 * MySQL, validación de saltos imposibles y seguimiento del recorrido.
 */
class UbicacionConductorTest extends TestCase
{
    use RefreshDatabase;

    private Cuenta $cuenta;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'remiseria.ubicacion.persistir_cada_segundos' => 15,
            'remiseria.ubicacion.velocidad_maxima_kmh' => 200,
            'remiseria.ubicacion.salto_minimo_metros' => 10,
            'remiseria.ubicacion.precision_maxima_metros' => 50,
        ]);

        $this->cuenta = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->enServicio()->create([
            'persona_id' => $this->cuenta->persona_id,
            'ubicacion_actualizada_en' => null,
        ]);
    }

    private function ping(float $lat, float $lng, array $extra = []): TestResponse
    {
        return $this->actingAs($this->cuenta)->postJson('/api/v1/conductor/ubicacion', ['lat' => $lat, 'lng' => $lng] + $extra);
    }

    private function conductor(): Conductor
    {
        return Conductor::query()->findOrFail($this->cuenta->persona_id);
    }

    public function test_registra_la_ubicacion_en_cache_y_en_la_base(): void
    {
        $this->freezeSecond();

        $this->ping(-34.9214, -57.9544)
            ->assertOk()
            ->assertJsonPath('data.lat', -34.9214)
            ->assertJsonPath('data.registrado_en', now()->toIso8601String());

        $this->assertSame(-34.9214, Cache::store('array')->get("ubicacion:conductor:{$this->cuenta->persona_id}")['lat']);
        $conductor = $this->conductor();
        $this->assertEqualsWithDelta(-34.9214, $conductor->ubicacion_actual->lat, 0.000001);
        $this->assertTrue($conductor->ubicacion_actualizada_en->equalTo(now()));
    }

    public function test_la_base_se_actualiza_a_lo_sumo_cada_n_segundos(): void
    {
        $this->ping(-34.9214, -57.9544)->assertOk();
        $persistida = $this->conductor()->ubicacion_actualizada_en;

        $this->travel(5)->seconds();
        $this->ping(-34.9215, -57.9544)->assertOk();
        $this->assertTrue($this->conductor()->ubicacion_actualizada_en->equalTo($persistida));

        $this->travel(15)->seconds();
        $this->ping(-34.9216, -57.9544)->assertOk();
        $this->assertTrue($this->conductor()->ubicacion_actualizada_en->greaterThan($persistida));
    }

    public function test_rechaza_un_salto_imposible(): void
    {
        $this->ping(-34.9214, -57.9544)->assertOk();
        $this->travel(5)->seconds();

        // ~11 km en 5 segundos.
        $this->ping(-34.8214, -57.9544)->assertUnprocessable();

        $this->assertSame(-34.9214, Cache::store('array')->get("ubicacion:conductor:{$this->cuenta->persona_id}")['lat']);
    }

    public function test_acepta_un_desplazamiento_plausible(): void
    {
        $this->ping(-34.9214, -57.9544)->assertOk();
        $this->travel(60)->seconds();

        // ~1,1 km en un minuto (66 km/h).
        $this->ping(-34.9314, -57.9544)->assertOk();
    }

    public function test_suma_el_recorrido_solo_durante_un_viaje_en_curso(): void
    {
        $viaje = Viaje::factory()->create(['conductor_id' => $this->cuenta->persona_id, 'estado' => EstadoViaje::Aceptado]);

        $this->ping(-34.9214, -57.9544)->assertOk();
        $this->travel(30)->seconds();
        $this->ping(-34.9234, -57.9544)->assertOk();
        $this->assertSame(0, $viaje->fresh()->distancia_recorrida_metros);

        $viaje->update(['estado' => EstadoViaje::EnCurso]);
        $this->travel(30)->seconds();
        $this->ping(-34.9254, -57.9544)->assertOk(); // ~222 m
        $this->travel(30)->seconds();
        $this->ping(-34.92545, -57.9544)->assertOk(); // ~5,5 m: ruido, no suma
        $this->travel(30)->seconds();
        $this->ping(-34.9275, -57.9544, ['precision_metros' => 300])->assertOk(); // impreciso, no suma

        $viaje->refresh();
        $this->assertEqualsWithDelta(222, $viaje->distancia_recorrida_metros, 2);
        $this->assertSame(1, $viaje->puntos_seguimiento);
    }

    public function test_si_el_store_no_esta_disponible_usa_la_base(): void
    {
        config(['remiseria.ubicacion.cache_store' => 'store-inexistente']);

        $this->ping(-34.9214, -57.9544)->assertOk();
        $this->travel(2)->seconds();
        $this->ping(-34.9215, -57.9544)->assertOk();

        // Sin cache se persiste cada ping y la última posición sale de MySQL.
        $this->assertEqualsWithDelta(-34.9215, $this->conductor()->ubicacion_actual->lat, 0.000001);
        $ultima = app(RegistroDeUbicaciones::class)->ultima($this->conductor());
        $this->assertEqualsWithDelta(-34.9215, $ultima->coordenada->lat, 0.000001);
    }

    public function test_difunde_la_posicion_con_su_marca_de_tiempo(): void
    {
        Event::fake([UbicacionConductorActualizada::class]);
        $viaje = Viaje::factory()->create(['conductor_id' => $this->cuenta->persona_id, 'estado' => EstadoViaje::Aceptado]);

        $this->ping(-34.9214, -57.9544)->assertOk();

        Event::assertDispatched(UbicacionConductorActualizada::class, function (UbicacionConductorActualizada $e) use ($viaje): bool {
            $canales = array_map(fn ($c) => $c->name, $e->broadcastOn());

            return $e->viajeActivoId === $viaje->id
                && $e->broadcastWith()['registrado_en'] !== null
                && in_array("private-viaje.{$viaje->id}", $canales, true)
                && in_array('private-admin.despacho', $canales, true);
        });
    }

    public function test_no_se_puede_informar_la_ubicacion_de_otro_conductor(): void
    {
        $otro = Conductor::factory()->create(['ubicacion_actual' => new Coordinate(-34.95, -57.95)]);

        $this->ping(-34.9214, -57.9544, ['conductor_id' => $otro->persona_id, 'persona_id' => $otro->persona_id])->assertOk();

        $this->assertEqualsWithDelta(-34.95, $otro->fresh()->ubicacion_actual->lat, 0.000001);
    }

    public function test_un_cliente_no_puede_informar_ubicaciones(): void
    {
        $cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);

        $this->actingAs($cliente)->postJson('/api/v1/conductor/ubicacion', ['lat' => -34.9, 'lng' => -57.9])->assertForbidden();
    }

    public function test_coordenadas_invalidas_devuelven_422(): void
    {
        $this->ping(95, -57.9)->assertUnprocessable()->assertJsonValidationErrors('lat');
        $this->ping(-34.9, -190)->assertUnprocessable()->assertJsonValidationErrors('lng');
    }

    public function test_un_conductor_dado_de_baja_no_puede_informar_ubicaciones(): void
    {
        $this->conductor()->update(['estado' => 'eliminado']);

        $this->ping(-34.9214, -57.9544)->assertForbidden();
    }

    public function test_el_endpoint_patch_sigue_disponible(): void
    {
        $this->actingAs($this->cuenta)->patchJson('/api/v1/conductor/ubicacion', ['lat' => -34.9214, 'lng' => -57.9544])->assertOk();
    }
}
