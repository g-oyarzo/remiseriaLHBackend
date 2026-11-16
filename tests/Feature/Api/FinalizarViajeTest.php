<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\EstadoViaje;
use App\Enums\MetodoCotizacion;
use App\Enums\RolPersona;
use App\Models\Cliente;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Tarifa;
use App\Models\Viaje;
use App\ValueObjects\Coordinate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * CU 06 - Finalizar viaje: geocerca de 100 m sobre la última posición
 * registrada, distancia/duración finales y precio con la tarifa del viaje.
 */
class FinalizarViajeTest extends TestCase
{
    use RefreshDatabase;

    private Coordinate $destino;

    private Cuenta $conductor;

    private Cuenta $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'remiseria.finalizacion.radio_tolerancia_metros' => 100,
            'remiseria.finalizacion.ubicacion_vigencia_segundos' => 60,
            'remiseria.finalizacion.minimo_puntos_seguimiento' => 3,
        ]);

        $this->destino = new Coordinate(lat: -34.9055, lng: -57.9325);
        $this->conductor = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->enServicio()->create(['persona_id' => $this->conductor->persona_id]);
        $this->cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $this->cliente->persona_id]);
    }

    private function viajeEnCurso(array $atributos = []): Viaje
    {
        return Viaje::factory()->create($atributos + [
            'cliente_id' => $this->cliente->persona_id,
            'conductor_id' => $this->conductor->persona_id,
            'estado' => EstadoViaje::EnCurso,
            'destino' => $this->destino,
            'costo' => '3000.00',
            'precio_base_aplicado' => '1500.00',
            'precio_por_km_aplicado' => '350.00',
            'distancia_estimada_km' => 4.2,
            'metodo_cotizacion' => MetodoCotizacion::Mapa,
            'iniciado_en' => now()->subMinutes(12),
            'distancia_recorrida_metros' => 5000,
            'puntos_seguimiento' => 20,
        ]);
    }

    /**
     * Punto a $metros al norte del destino.
     */
    private function aMetrosDelDestino(float $metros): array
    {
        return ['lat' => $this->destino->lat + $metros / 111_195, 'lng' => $this->destino->lng];
    }

    private function finalizar(Viaje $viaje, array $datos = []): TestResponse
    {
        return $this->actingAs($this->conductor)->postJson("/api/v1/conductor/viajes/{$viaje->id}/finalizar", $datos);
    }

    public function test_finaliza_dentro_del_radio_y_cobra_lo_cotizado(): void
    {
        $this->freezeSecond();
        $viaje = $this->viajeEnCurso();

        $this->finalizar($viaje, $this->aMetrosDelDestino(40))
            ->assertOk()
            ->assertJsonPath('data.estado', 'finalizado')
            // Precio cerrado: se cobra lo cotizado (3000), no la fórmula sobre
            // los 5 km recorridos (que daría 3250).
            ->assertJsonPath('data.costo_final', '3000.00')
            ->assertJsonPath('data.costo_final_origen', 'cotizado')
            ->assertJsonPath('data.duracion_final_min', 12);

        $viaje->refresh();
        $this->assertSame(5.0, $viaje->distancia_final_km);
        $this->assertEqualsWithDelta(40, $viaje->distancia_al_destino_metros, 2);
        $this->assertTrue($viaje->finalizado_en->equalTo(now()));
    }

    public function test_respeta_el_borde_del_radio_de_tolerancia(): void
    {
        $dentro = $this->viajeEnCurso();
        $this->finalizar($dentro, $this->aMetrosDelDestino(95))->assertOk();

        $this->travel(2)->minutes();
        $fuera = $this->viajeEnCurso();
        $this->finalizar($fuera, $this->aMetrosDelDestino(110))
            ->assertUnprocessable()
            ->assertJsonPath('distancia_al_destino_metros', 110);

        $this->assertSame(EstadoViaje::EnCurso, $fuera->fresh()->estado);
    }

    public function test_usa_la_ultima_posicion_registrada_si_no_se_envia_una(): void
    {
        $viaje = $this->viajeEnCurso();
        $this->actingAs($this->conductor)->postJson('/api/v1/conductor/ubicacion', $this->aMetrosDelDestino(20))->assertOk();

        $this->finalizar($viaje)->assertOk();
    }

    public function test_sin_una_ubicacion_reciente_no_se_puede_finalizar(): void
    {
        $viaje = $this->viajeEnCurso();
        $this->actingAs($this->conductor)->postJson('/api/v1/conductor/ubicacion', $this->aMetrosDelDestino(20))->assertOk();

        $this->travel(2)->minutes();

        $this->finalizar($viaje)->assertStatus(409);
        $this->assertSame(EstadoViaje::EnCurso, $viaje->fresh()->estado);
    }

    public function test_la_posicion_enviada_pasa_por_la_validacion_de_saltos_imposibles(): void
    {
        $viaje = $this->viajeEnCurso();
        // El conductor está a ~5 km del destino según su último ping...
        $this->actingAs($this->conductor)->postJson('/api/v1/conductor/ubicacion', $this->aMetrosDelDestino(5000))->assertOk();
        $this->travel(3)->seconds();

        // ...y 3 segundos después declara estar en el destino.
        $this->finalizar($viaje, $this->aMetrosDelDestino(0))->assertUnprocessable();
        $this->assertSame(EstadoViaje::EnCurso, $viaje->fresh()->estado);
    }

    public function test_con_seguimiento_insuficiente_la_distancia_final_es_la_estimada(): void
    {
        $viaje = $this->viajeEnCurso(['puntos_seguimiento' => 1, 'distancia_recorrida_metros' => 300]);

        $this->finalizar($viaje, $this->aMetrosDelDestino(10))
            ->assertOk()
            ->assertJsonPath('data.costo_final', '3000.00')
            ->assertJsonPath('data.costo_final_origen', 'cotizado')
            ->assertJsonPath('data.distancia_final_km', 4.2);
    }

    public function test_un_viaje_cotizado_por_zona_cobra_el_monto_fijo(): void
    {
        $viaje = $this->viajeEnCurso(['metodo_cotizacion' => MetodoCotizacion::Zona, 'costo' => '2800.00']);

        $this->finalizar($viaje, $this->aMetrosDelDestino(10))
            ->assertOk()
            ->assertJsonPath('data.costo_final', '2800.00')
            ->assertJsonPath('data.costo_final_origen', 'cotizado');
    }

    public function test_un_cambio_de_tarifa_durante_el_viaje_no_altera_el_precio_final(): void
    {
        Tarifa::factory()->create(['precio_base' => 9999, 'precio_por_km' => 9999]);
        Tarifa::olvidarVigenteEnCache();
        $viaje = $this->viajeEnCurso();

        $this->finalizar($viaje, $this->aMetrosDelDestino(10))->assertJsonPath('data.costo_final', '3000.00');
    }

    public function test_no_se_puede_finalizar_dos_veces(): void
    {
        $viaje = $this->viajeEnCurso();

        $this->finalizar($viaje, $this->aMetrosDelDestino(10))->assertOk();
        $this->finalizar($viaje, $this->aMetrosDelDestino(10))->assertStatus(409);
    }

    public function test_no_se_puede_finalizar_un_viaje_ajeno(): void
    {
        $viaje = $this->viajeEnCurso();
        $otro = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->enServicio()->create(['persona_id' => $otro->persona_id]);

        $this->actingAs($otro)->postJson("/api/v1/conductor/viajes/{$viaje->id}/finalizar", $this->aMetrosDelDestino(10))
            ->assertForbidden();
        $this->actingAs($this->cliente)->postJson("/api/v1/conductor/viajes/{$viaje->id}/finalizar", $this->aMetrosDelDestino(10))
            ->assertForbidden();
    }

    public function test_un_viaje_aceptado_no_se_puede_finalizar(): void
    {
        $viaje = $this->viajeEnCurso(['estado' => EstadoViaje::Aceptado]);

        $this->finalizar($viaje, $this->aMetrosDelDestino(10))->assertStatus(409);
    }

    public function test_un_viaje_cancelado_mientras_estaba_en_curso_no_se_finaliza(): void
    {
        $viaje = $this->viajeEnCurso();
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);
        $this->actingAs($admin)->patchJson("/api/v1/viajes/{$viaje->id}/cancelar")->assertOk();

        $this->finalizar($viaje, $this->aMetrosDelDestino(10))->assertStatus(409);
        $this->assertSame(EstadoViaje::Cancelado, $viaje->fresh()->estado);
    }
}
