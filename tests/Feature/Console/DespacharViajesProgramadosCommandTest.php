<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\EstadoViaje;
use App\Enums\TipoViaje;
use App\Events\ViajeListoParaDespacho;
use App\Jobs\DespacharReservaJob;
use App\Models\Viaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Despacho de reservas (CU 09): reservado -> solicitado al entrar en la
 * ventana configurada, con la asignación disparada vía ViajeListoParaDespacho.
 */
class DespacharViajesProgramadosCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['remiseria.ventana_despacho_programados_minutos' => 15]);
        Event::fake([ViajeListoParaDespacho::class]);
    }

    private function reserva(\DateTimeInterface $fecha, EstadoViaje $estado = EstadoViaje::Reservado): Viaje
    {
        return Viaje::factory()->create([
            'estado' => $estado,
            'tipo' => TipoViaje::Programado,
            'fecha_viaje' => $fecha,
        ]);
    }

    public function test_despacha_la_reserva_que_entro_en_la_ventana(): void
    {
        $viaje = $this->reserva(now()->addMinutes(10));

        $this->artisan('viajes:despachar-programados')->assertSuccessful();

        $viaje->refresh();
        $this->assertSame(EstadoViaje::Solicitado, $viaje->estado);
        $this->assertNotNull($viaje->despachado_en);
        Event::assertDispatched(ViajeListoParaDespacho::class, fn (ViajeListoParaDespacho $e): bool => $e->viajeId === $viaje->id);
    }

    public function test_no_despacha_una_reserva_fuera_de_la_ventana(): void
    {
        $viaje = $this->reserva(now()->addHours(3));

        $this->artisan('viajes:despachar-programados')->assertSuccessful();

        $this->assertSame(EstadoViaje::Reservado, $viaje->fresh()->estado);
        Event::assertNotDispatched(ViajeListoParaDespacho::class);
    }

    public function test_no_despacha_una_reserva_cancelada(): void
    {
        $this->reserva(now()->addMinutes(5), EstadoViaje::Cancelado);

        $this->artisan('viajes:despachar-programados')->assertSuccessful();

        Event::assertNotDispatched(ViajeListoParaDespacho::class);
    }

    public function test_es_idempotente(): void
    {
        $this->reserva(now()->addMinutes(5));

        $this->artisan('viajes:despachar-programados')->assertSuccessful();
        $this->artisan('viajes:despachar-programados')->assertSuccessful();

        Event::assertDispatchedTimes(ViajeListoParaDespacho::class, 1);
    }

    public function test_no_toca_solicitudes_inmediatas(): void
    {
        $viaje = Viaje::factory()->create(['estado' => EstadoViaje::Solicitado, 'tipo' => TipoViaje::Actual]);

        $this->artisan('viajes:despachar-programados')->assertSuccessful();

        $this->assertSame(EstadoViaje::Solicitado, $viaje->fresh()->estado);
        Event::assertNotDispatched(ViajeListoParaDespacho::class);
    }

    public function test_el_job_programado_no_despacha_antes_de_tiempo(): void
    {
        // Con la cola sync el job se ejecuta sin la demora configurada: debe
        // ser un no-op si la reserva todavía no entró en la ventana.
        $viaje = $this->reserva(now()->addHours(2));

        DespacharReservaJob::dispatchSync($viaje->id);

        $this->assertSame(EstadoViaje::Reservado, $viaje->fresh()->estado);
        Event::assertNotDispatched(ViajeListoParaDespacho::class);
    }

    public function test_el_job_programado_despacha_al_llegar_la_ventana(): void
    {
        $viaje = $this->reserva(now()->addHours(2));

        $this->travel(110)->minutes();
        DespacharReservaJob::dispatchSync($viaje->id);

        $this->assertSame(EstadoViaje::Solicitado, $viaje->fresh()->estado);
        Event::assertDispatched(ViajeListoParaDespacho::class);
    }
}
