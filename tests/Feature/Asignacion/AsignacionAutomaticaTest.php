<?php

declare(strict_types=1);

namespace Tests\Feature\Asignacion;

use App\Enums\EstadoAsignacion;
use App\Enums\EstadoViaje;
use App\Enums\RolPersona;
use App\Events\AsignacionOfrecida;
use App\Events\AsignacionRetirada;
use App\Events\BusquedaDeConductorAgotada;
use App\Events\ViajeCambioEstado;
use App\Jobs\AsignarConductorJob;
use App\Jobs\ExpirarAsignacionJob;
use App\Models\AsignacionViaje;
use App\Models\Cliente;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Tarifa;
use App\Models\Viaje;
use App\Services\Asignacion\AsignadorConductores;
use App\Services\Asignacion\ResultadoBusqueda;
use App\ValueObjects\Coordinate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * CU 03 (asignación automática) y CU 05 (aceptar / rechazar).
 */
class AsignacionAutomaticaTest extends TestCase
{
    use RefreshDatabase;

    private Coordinate $origen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->origen = new Coordinate(lat: -34.9214, lng: -57.9544);
        config([
            'remiseria.asignacion.radio_busqueda_metros' => 10000,
            'remiseria.asignacion.radio_expansion_paso_metros' => 2500,
            'remiseria.asignacion.radio_maximo_metros' => 20000,
            'remiseria.asignacion.ubicacion_vigencia_segundos' => 120,
            'remiseria.asignacion.tiempo_aceptacion_segundos' => 30,
            'remiseria.asignacion.max_ofertas' => 10,
            // Con la cola sync los reintentos se ejecutarían en cadena.
            'remiseria.asignacion.max_reintentos_sin_candidatos' => 0,
        ]);
    }

    /**
     * Conductor disponible a $metrosAlNorte del origen del viaje.
     */
    private function conductor(int $metrosAlNorte, array $atributos = []): Cuenta
    {
        $cuenta = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        $ubicacion = new Coordinate(lat: $this->origen->lat + $metrosAlNorte / 111_195, lng: $this->origen->lng);

        // Calificación fija: la de la factory es aleatoria y alteraría el
        // orden de los candidatos entre ejecuciones.
        Conductor::factory()->disponibleEn($ubicacion)->create(['persona_id' => $cuenta->persona_id] + $atributos + ['calificacion' => 4.5]);

        return $cuenta;
    }

    private function viaje(): Viaje
    {
        return Viaje::factory()->create([
            'estado' => EstadoViaje::Solicitado,
            'origen' => $this->origen,
            'despachado_en' => now(),
        ]);
    }

    private function asignador(): AsignadorConductores
    {
        return app(AsignadorConductores::class);
    }

    private function ofertaPendiente(Viaje $viaje): ?AsignacionViaje
    {
        return $viaje->asignaciones()->pendientes()->first();
    }

    public function test_ofrece_el_viaje_al_conductor_mas_cercano(): void
    {
        Event::fake([AsignacionOfrecida::class]);
        $this->freezeSecond();
        $this->conductor(3000);
        $cerca = $this->conductor(500);
        $viaje = $this->viaje();

        $this->assertSame(ResultadoBusqueda::Ofrecida, $this->asignador()->buscarYOfrecer($viaje->id));

        $oferta = $this->ofertaPendiente($viaje);
        $this->assertSame($cerca->persona_id, $oferta->conductor_id);
        $this->assertEqualsWithDelta(500, $oferta->distancia_metros, 5);
        $this->assertSame(1, $oferta->intento);
        $this->assertTrue($oferta->expira_en->equalTo(now()->addSeconds(30)));
        Event::assertDispatched(AsignacionOfrecida::class, fn (AsignacionOfrecida $e): bool => $e->datos['conductor_id'] === $cerca->persona_id
            && $e->broadcastOn()[0]->name === "private-conductor.{$cerca->persona_id}"
            && ! array_key_exists('cliente', $e->datos));
    }

    public function test_a_igual_distancia_prioriza_la_mejor_calificacion(): void
    {
        $this->conductor(1000, ['calificacion' => 3.0]);
        $mejor = $this->conductor(1000, ['calificacion' => 5.0]);
        $viaje = $this->viaje();

        $this->asignador()->buscarYOfrecer($viaje->id);

        $this->assertSame($mejor->persona_id, $this->ofertaPendiente($viaje)->conductor_id);
    }

    public function test_el_radio_de_busqueda_se_amplia_en_cada_reintento_hasta_el_maximo(): void
    {
        $this->assertSame(10000, AsignadorConductores::radioDeBusqueda(0));
        $this->assertSame(12500, AsignadorConductores::radioDeBusqueda(1));
        $this->assertSame(17500, AsignadorConductores::radioDeBusqueda(3));
        $this->assertSame(20000, AsignadorConductores::radioDeBusqueda(10));
    }

    public function test_un_conductor_fuera_del_radio_inicial_se_encuentra_al_ampliarlo(): void
    {
        $lejos = $this->conductor(12000);
        $viaje = $this->viaje();

        $this->assertSame(ResultadoBusqueda::SinCandidatos, $this->asignador()->buscarYOfrecer($viaje->id, reintento: 0));
        $this->assertSame(ResultadoBusqueda::Ofrecida, $this->asignador()->buscarYOfrecer($viaje->id, reintento: 1));

        $this->assertSame($lejos->persona_id, $this->ofertaPendiente($viaje)->conductor_id);
    }

    public function test_el_job_amplia_el_radio_al_reintentar_sin_candidatos(): void
    {
        config(['remiseria.asignacion.max_reintentos_sin_candidatos' => 5]);
        $lejos = $this->conductor(16000);
        $viaje = $this->viaje();

        // Con la cola sync los reintentos corren en cadena: 10 km, 12,5 km,
        // 15 km y 17,5 km, donde aparece el conductor.
        AsignarConductorJob::dispatchSync($viaje->id);

        $this->assertSame($lejos->persona_id, $this->ofertaPendiente($viaje)?->conductor_id);
        $this->assertNull($viaje->fresh()->busqueda_agotada_en);
    }

    public function test_no_considera_conductores_con_ubicacion_vieja_ni_fuera_del_radio(): void
    {
        $viejo = $this->conductor(200);
        Conductor::query()->whereKey($viejo->persona_id)->update(['ubicacion_actualizada_en' => now()->subMinutes(10)]);
        $this->conductor(15000);
        $viaje = $this->viaje();

        $this->assertSame(ResultadoBusqueda::SinCandidatos, $this->asignador()->buscarYOfrecer($viaje->id));
        $this->assertSame(0, $viaje->asignaciones()->count());
    }

    public function test_no_considera_conductores_no_disponibles(): void
    {
        $this->conductor(100, ['en_servicio' => false]);
        $this->conductor(150, ['vehiculo_id' => null]);
        $this->conductor(200, ['estado' => 'eliminado']);
        $ocupado = $this->conductor(250);
        Viaje::factory()->create(['conductor_id' => $ocupado->persona_id, 'estado' => EstadoViaje::EnCurso]);
        $viaje = $this->viaje();

        $this->assertSame(ResultadoBusqueda::SinCandidatos, $this->asignador()->buscarYOfrecer($viaje->id));
    }

    public function test_un_conductor_con_una_oferta_pendiente_no_recibe_otra(): void
    {
        $unico = $this->conductor(300);
        $primero = $this->viaje();
        $segundo = $this->viaje();

        $this->asignador()->buscarYOfrecer($primero->id);

        $this->assertSame(ResultadoBusqueda::SinCandidatos, $this->asignador()->buscarYOfrecer($segundo->id));
        $this->assertSame($unico->persona_id, $this->ofertaPendiente($primero)->conductor_id);
    }

    public function test_un_viaje_nunca_tiene_dos_ofertas_pendientes(): void
    {
        $this->conductor(300);
        $this->conductor(600);
        $viaje = $this->viaje();

        $this->assertSame(ResultadoBusqueda::Ofrecida, $this->asignador()->buscarYOfrecer($viaje->id));
        $this->assertSame(ResultadoBusqueda::OfertaEnCurso, $this->asignador()->buscarYOfrecer($viaje->id));

        $this->assertSame(1, $viaje->asignaciones()->pendientes()->count());
    }

    public function test_la_base_impide_dos_ofertas_pendientes_para_el_mismo_viaje(): void
    {
        $viaje = $this->viaje();
        AsignacionViaje::factory()->create(['viaje_id' => $viaje->id]);

        $this->expectException(UniqueConstraintViolationException::class);

        AsignacionViaje::factory()->create(['viaje_id' => $viaje->id]);
    }

    public function test_la_base_impide_ofrecer_dos_veces_el_mismo_viaje_al_mismo_conductor(): void
    {
        $viaje = $this->viaje();
        $conductor = Conductor::factory()->create();
        AsignacionViaje::factory()->create(['viaje_id' => $viaje->id, 'conductor_id' => $conductor->persona_id, 'estado' => EstadoAsignacion::Rechazada]);

        $this->expectException(UniqueConstraintViolationException::class);

        AsignacionViaje::factory()->create(['viaje_id' => $viaje->id, 'conductor_id' => $conductor->persona_id]);
    }

    public function test_el_destinatario_acepta_y_el_viaje_queda_asignado(): void
    {
        Event::fake([ViajeCambioEstado::class]);
        $conductor = $this->conductor(400);
        $viaje = $this->viaje();
        $this->asignador()->buscarYOfrecer($viaje->id);

        $this->actingAs($conductor)->postJson("/api/v1/conductor/viajes/{$viaje->id}/aceptar")
            ->assertOk()
            ->assertJsonPath('data.estado', 'aceptado')
            ->assertJsonPath('data.conductor_id', $conductor->persona_id);

        $viaje->refresh();
        $this->assertSame(EstadoViaje::Aceptado, $viaje->estado);
        $this->assertSame($conductor->persona->conductor->vehiculo_id, $viaje->vehiculo_id);
        $this->assertSame(EstadoAsignacion::Aceptada, $viaje->asignaciones()->first()->estado);
        Event::assertDispatched(ViajeCambioEstado::class, fn (ViajeCambioEstado $e): bool => $e->viajeId === $viaje->id
            && $e->estadoNuevo === EstadoViaje::Aceptado
            && $e->broadcastOn()[0]->name === "private-viaje.{$viaje->id}");
    }

    public function test_un_conductor_sin_la_oferta_no_puede_aceptar(): void
    {
        $this->conductor(400);
        $intruso = $this->conductor(5000);
        $viaje = $this->viaje();
        $this->asignador()->buscarYOfrecer($viaje->id);

        $this->actingAs($intruso)->postJson("/api/v1/conductor/viajes/{$viaje->id}/aceptar")->assertForbidden();
        $this->assertSame(EstadoViaje::Solicitado, $viaje->fresh()->estado);
    }

    public function test_el_endpoint_patch_de_aceptar_sigue_disponible(): void
    {
        $conductor = $this->conductor(400);
        $viaje = $this->viaje();
        $this->asignador()->buscarYOfrecer($viaje->id);

        $this->actingAs($conductor)->patchJson("/api/v1/conductor/viajes/{$viaje->id}/aceptar")->assertOk();
    }

    public function test_aceptar_una_oferta_vencida_devuelve_409_y_reasigna(): void
    {
        $primero = $this->conductor(300);
        $segundo = $this->conductor(900);
        $viaje = $this->viaje();
        $this->asignador()->buscarYOfrecer($viaje->id);

        $this->travel(31)->seconds();

        $this->actingAs($primero)->postJson("/api/v1/conductor/viajes/{$viaje->id}/aceptar")->assertStatus(409);

        $this->assertSame(EstadoAsignacion::Expirada, $viaje->asignaciones()->where('conductor_id', $primero->persona_id)->first()->estado);
        // La reasignación se encola al confirmar (cola sync en tests).
        $this->assertSame($segundo->persona_id, $this->ofertaPendiente($viaje)->conductor_id);
    }

    public function test_rechazar_ofrece_el_viaje_al_siguiente_y_no_se_vuelve_a_ofrecer(): void
    {
        $primero = $this->conductor(300);
        $segundo = $this->conductor(900);
        $viaje = $this->viaje();
        $this->asignador()->buscarYOfrecer($viaje->id);

        $this->actingAs($primero)->postJson("/api/v1/conductor/viajes/{$viaje->id}/rechazar")->assertOk();

        $this->assertSame(EstadoAsignacion::Rechazada, $viaje->asignaciones()->where('conductor_id', $primero->persona_id)->first()->estado);
        $this->assertSame($segundo->persona_id, $this->ofertaPendiente($viaje)->conductor_id);

        // El segundo también rechaza: no hay más candidatos, el primero no
        // vuelve a recibir la oferta y la búsqueda se agota.
        Event::fake([BusquedaDeConductorAgotada::class]);
        $this->actingAs($segundo)->postJson("/api/v1/conductor/viajes/{$viaje->id}/rechazar")->assertOk();

        $this->assertNull($this->ofertaPendiente($viaje));
        $this->assertNotNull($viaje->fresh()->busqueda_agotada_en);
        Event::assertDispatched(BusquedaDeConductorAgotada::class);
    }

    public function test_rechazar_dos_veces_devuelve_409(): void
    {
        $conductor = $this->conductor(300);
        $viaje = $this->viaje();
        $this->asignador()->buscarYOfrecer($viaje->id);

        $this->actingAs($conductor)->postJson("/api/v1/conductor/viajes/{$viaje->id}/rechazar")->assertOk();
        $this->actingAs($conductor)->postJson("/api/v1/conductor/viajes/{$viaje->id}/rechazar")->assertStatus(409);
    }

    public function test_una_oferta_sin_respuesta_vence_y_se_reasigna(): void
    {
        Event::fake([AsignacionRetirada::class]);
        $primero = $this->conductor(300);
        $segundo = $this->conductor(900);
        $viaje = $this->viaje();
        $this->asignador()->buscarYOfrecer($viaje->id);
        $oferta = $this->ofertaPendiente($viaje);

        // Antes del vencimiento el job no hace nada.
        ExpirarAsignacionJob::dispatchSync($oferta->id);
        $this->assertSame(EstadoAsignacion::Pendiente, $oferta->fresh()->estado);

        $this->travel(31)->seconds();
        ExpirarAsignacionJob::dispatchSync($oferta->id);

        $this->assertSame(EstadoAsignacion::Expirada, $oferta->fresh()->estado);
        $this->assertSame($segundo->persona_id, $this->ofertaPendiente($viaje)->conductor_id);
        Event::assertDispatched(AsignacionRetirada::class, fn (AsignacionRetirada $e): bool => $e->conductorId === $primero->persona_id);
    }

    public function test_el_supervisor_vence_ofertas_y_reactiva_busquedas_detenidas(): void
    {
        $this->conductor(300);
        $conOferta = $this->viaje();
        $this->asignador()->buscarYOfrecer($conOferta->id);

        // Viaje solicitado cuyo job de asignación "se perdió".
        $huerfano = Viaje::factory()->create([
            'estado' => EstadoViaje::Solicitado,
            'origen' => $this->origen,
            'despachado_en' => now()->subMinutes(5),
        ]);
        $this->conductor(600);

        $this->travel(31)->seconds();
        $this->artisan('viajes:supervisar-despacho')->assertSuccessful();

        $this->assertSame(EstadoAsignacion::Expirada, $conOferta->asignaciones()->first()->estado);
        $this->assertNotNull($this->ofertaPendiente($huerfano));
    }

    public function test_al_alcanzar_el_maximo_de_ofertas_la_busqueda_se_agota(): void
    {
        Event::fake([BusquedaDeConductorAgotada::class]);
        config(['remiseria.asignacion.max_ofertas' => 1]);
        $primero = $this->conductor(300);
        $this->conductor(900);
        $viaje = $this->viaje();
        $this->asignador()->buscarYOfrecer($viaje->id);

        $this->actingAs($primero)->postJson("/api/v1/conductor/viajes/{$viaje->id}/rechazar")->assertOk();

        $this->assertSame(1, $viaje->asignaciones()->count());
        $this->assertNotNull($viaje->fresh()->busqueda_agotada_en);
        $this->assertSame(EstadoViaje::Solicitado, $viaje->fresh()->estado);
        Event::assertDispatched(BusquedaDeConductorAgotada::class, fn (BusquedaDeConductorAgotada $e): bool => $e->broadcastOn()[0]->name === 'private-admin.despacho');
    }

    public function test_sin_candidatos_reintenta_y_luego_se_agota(): void
    {
        config(['remiseria.asignacion.max_reintentos_sin_candidatos' => 2]);
        $viaje = $this->viaje();

        AsignarConductorJob::dispatchSync($viaje->id);

        // Con la cola sync los dos reintentos se ejecutan en cadena.
        $this->assertNotNull($viaje->fresh()->busqueda_agotada_en);
    }

    public function test_el_admin_puede_reiniciar_una_busqueda_agotada(): void
    {
        $viaje = $this->viaje();
        AsignarConductorJob::dispatchSync($viaje->id);
        $this->assertNotNull($viaje->fresh()->busqueda_agotada_en);

        $conductor = $this->conductor(300);
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);

        $this->actingAs($admin)->postJson("/api/v1/admin/viajes/{$viaje->id}/reasignar")->assertStatus(202);

        $this->assertNull($viaje->fresh()->busqueda_agotada_en);
        $this->assertSame($conductor->persona_id, $this->ofertaPendiente($viaje)->conductor_id);
    }

    public function test_cancelar_el_viaje_retira_la_oferta_pendiente(): void
    {
        $conductor = $this->conductor(300);
        $clienteCuenta = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $clienteCuenta->persona_id]);
        $viaje = Viaje::factory()->create([
            'cliente_id' => $clienteCuenta->persona_id,
            'estado' => EstadoViaje::Solicitado,
            'origen' => $this->origen,
        ]);
        $this->asignador()->buscarYOfrecer($viaje->id);

        $this->actingAs($clienteCuenta)->patchJson("/api/v1/viajes/{$viaje->id}/cancelar")->assertOk();

        $this->assertSame(EstadoAsignacion::Cancelada, $viaje->asignaciones()->first()->estado);
        $this->actingAs($conductor)->postJson("/api/v1/conductor/viajes/{$viaje->id}/aceptar")->assertStatus(409);
    }

    public function test_pendientes_lista_solo_las_ofertas_del_conductor(): void
    {
        $conductor = $this->conductor(300);
        $otro = $this->conductor(5000);
        $viaje = $this->viaje();
        $this->asignador()->buscarYOfrecer($viaje->id);

        $this->actingAs($conductor)->getJson('/api/v1/conductor/viajes/pendientes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $viaje->id)
            ->assertJsonPath('data.0.asignacion.id', $this->ofertaPendiente($viaje)->id);

        $this->actingAs($otro)->getJson('/api/v1/conductor/viajes/pendientes')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_el_destinatario_puede_ver_el_detalle_antes_de_aceptar(): void
    {
        $conductor = $this->conductor(300);
        $otro = $this->conductor(5000);
        $viaje = $this->viaje();
        $this->asignador()->buscarYOfrecer($viaje->id);

        $this->actingAs($conductor)->getJson("/api/v1/viajes/{$viaje->id}")->assertOk();
        $this->actingAs($otro)->getJson("/api/v1/viajes/{$viaje->id}")->assertForbidden();
    }

    public function test_flujo_completo_desde_la_solicitud_del_cliente(): void
    {
        Tarifa::factory()->create();
        $conductor = $this->conductor(400);
        $clienteCuenta = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $clienteCuenta->persona_id]);

        $id = $this->actingAs($clienteCuenta)->postJson('/api/v1/cliente/viajes/solicitar', [
            'origen_lat' => $this->origen->lat,
            'origen_lng' => $this->origen->lng,
            'destino_lat' => -34.9055,
            'destino_lng' => -57.9325,
            'metodo_pago' => 'efectivo',
        ])->assertCreated()->json('data.id');

        // ViajeListoParaDespacho -> IniciarBusquedaDeConductor -> AsignarConductorJob.
        $this->actingAs($conductor)->getJson('/api/v1/conductor/viajes/pendientes')->assertJsonPath('data.0.id', $id);
        $this->actingAs($conductor)->postJson("/api/v1/conductor/viajes/{$id}/aceptar")->assertOk();

        $this->assertSame(EstadoViaje::Aceptado, Viaje::query()->find($id)->estado);
    }
}
