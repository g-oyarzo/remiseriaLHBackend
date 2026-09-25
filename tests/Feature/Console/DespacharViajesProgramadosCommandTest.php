<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\EstadoViaje;
use App\Enums\RolPersona;
use App\Enums\TipoViaje;
use App\Events\ViajeProgramadoDisponible;
use App\Models\Cliente;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Vehiculo;
use App\Models\Viaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * HALL-006: los viajes programados no tenían ningún mecanismo que los
 * hiciera visibles/asignables a conductores cuando se acercaba su hora.
 */
class DespacharViajesProgramadosCommandTest extends TestCase
{
    use RefreshDatabase;

    private function crearConductorDisponible(): Cuenta
    {
        $cuenta = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->create([
            'persona_id' => $cuenta->persona_id,
            'en_servicio' => true,
            'estado' => 'activo',
            // Conductor::scopeDisponibles() exige whereNotNull('vehiculo_id');
            // ConductorFactory no lo setea por default.
            'vehiculo_id' => Vehiculo::factory(),
        ]);

        return $cuenta;
    }

    private function crearViajeProgramado(\DateTimeInterface|string $fechaViaje): Viaje
    {
        $clienteCuenta = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $clienteCuenta->persona_id]);

        return Viaje::factory()->create([
            'estado' => EstadoViaje::Solicitado,
            'tipo' => TipoViaje::Programado,
            'cliente_id' => $clienteCuenta->persona_id,
            'conductor_id' => null,
            'fecha_viaje' => $fechaViaje,
        ]);
    }

    public function test_notifica_viaje_programado_dentro_de_la_ventana_de_despacho(): void
    {
        Event::fake([ViajeProgramadoDisponible::class]);

        $this->crearConductorDisponible();
        $viaje = $this->crearViajeProgramado(now()->addMinutes(10));

        $this->artisan('viajes:despachar-programados')->assertSuccessful();

        Event::assertDispatched(
            ViajeProgramadoDisponible::class,
            fn (ViajeProgramadoDisponible $event) => $event->viajeId === $viaje->id,
        );
    }

    public function test_no_notifica_viaje_programado_fuera_de_la_ventana_de_despacho(): void
    {
        Event::fake([ViajeProgramadoDisponible::class]);

        $this->crearConductorDisponible();
        $this->crearViajeProgramado(now()->addHours(5));

        $this->artisan('viajes:despachar-programados')->assertSuccessful();

        Event::assertNotDispatched(ViajeProgramadoDisponible::class);
    }

    public function test_no_notifica_si_no_hay_conductores_disponibles(): void
    {
        Event::fake([ViajeProgramadoDisponible::class]);

        // Ningún conductor en servicio.
        $this->crearViajeProgramado(now()->addMinutes(5));

        $this->artisan('viajes:despachar-programados')->assertSuccessful();

        Event::assertNotDispatched(ViajeProgramadoDisponible::class);
    }

    public function test_no_notifica_viaje_que_ya_tiene_conductor_asignado(): void
    {
        Event::fake([ViajeProgramadoDisponible::class]);

        $conductorCuenta = $this->crearConductorDisponible();
        $viaje = $this->crearViajeProgramado(now()->addMinutes(5));
        $viaje->update(['conductor_id' => $conductorCuenta->persona_id, 'estado' => EstadoViaje::Aceptado]);

        $this->artisan('viajes:despachar-programados')->assertSuccessful();

        Event::assertNotDispatched(ViajeProgramadoDisponible::class);
    }

    public function test_no_notifica_viajes_tipo_actual(): void
    {
        Event::fake([ViajeProgramadoDisponible::class]);

        $this->crearConductorDisponible();
        $clienteCuenta = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $clienteCuenta->persona_id]);

        Viaje::factory()->create([
            'estado' => EstadoViaje::Solicitado,
            'tipo' => TipoViaje::Actual,
            'cliente_id' => $clienteCuenta->persona_id,
            'conductor_id' => null,
            'fecha_viaje' => now(),
        ]);

        $this->artisan('viajes:despachar-programados')->assertSuccessful();

        // Los viajes "actual" ya son visibles de inmediato en
        // /conductor/viajes/pendientes (Viaje::scopeListosParaDespacho());
        // este comando es exclusivamente para "programado".
        Event::assertNotDispatched(ViajeProgramadoDisponible::class);
    }
}