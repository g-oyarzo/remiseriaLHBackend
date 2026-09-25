<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\EstadoViaje;
use App\Enums\RolPersona;
use App\Enums\TipoViaje;
use App\Models\Cliente;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Viaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HALL-006: antes GET /conductor/viajes/pendientes mostraba TODOS los
 * viajes "solicitado" sin importar tipo ni fecha, así que un viaje
 * programado para dentro de varios días ya aparecía como disponible desde
 * el momento en que se solicitaba.
 */
class ViajePendientesTest extends TestCase
{
    use RefreshDatabase;

    private function crearConductorCuenta(): Cuenta
    {
        $cuenta = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->create(['persona_id' => $cuenta->persona_id]);

        return $cuenta;
    }

    private function crearCliente(): Cuenta
    {
        $cuenta = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $cuenta->persona_id]);

        return $cuenta;
    }

    public function test_viaje_actual_solicitado_es_visible_de_inmediato(): void
    {
        $conductorCuenta = $this->crearConductorCuenta();
        $cliente = $this->crearCliente();

        $viaje = Viaje::factory()->create([
            'estado' => EstadoViaje::Solicitado,
            'tipo' => TipoViaje::Actual,
            'cliente_id' => $cliente->persona_id,
            'conductor_id' => null,
            'fecha_viaje' => now(),
        ]);

        $response = $this->actingAs($conductorCuenta)->getJson('/api/v1/conductor/viajes/pendientes');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($viaje->id));
    }

    public function test_viaje_programado_lejano_no_es_visible_todavia(): void
    {
        $conductorCuenta = $this->crearConductorCuenta();
        $cliente = $this->crearCliente();

        $viajeLejano = Viaje::factory()->create([
            'estado' => EstadoViaje::Solicitado,
            'tipo' => TipoViaje::Programado,
            'cliente_id' => $cliente->persona_id,
            'conductor_id' => null,
            'fecha_viaje' => now()->addDays(2),
        ]);

        $response = $this->actingAs($conductorCuenta)->getJson('/api/v1/conductor/viajes/pendientes');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($viajeLejano->id));
    }

    public function test_viaje_programado_dentro_de_la_ventana_es_visible(): void
    {
        $conductorCuenta = $this->crearConductorCuenta();
        $cliente = $this->crearCliente();

        $viajeProximo = Viaje::factory()->create([
            'estado' => EstadoViaje::Solicitado,
            'tipo' => TipoViaje::Programado,
            'cliente_id' => $cliente->persona_id,
            'conductor_id' => null,
            'fecha_viaje' => now()->addMinutes(10),
        ]);

        $response = $this->actingAs($conductorCuenta)->getJson('/api/v1/conductor/viajes/pendientes');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($viajeProximo->id));
    }
}
