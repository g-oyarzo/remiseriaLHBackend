<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\RolPersona;
use App\Models\Cliente;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Viaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HALL-007: verifica que un conductor no pueda ver el detalle de un viaje
 * que no le fue asignado (protección IDOR), y que un cliente no pueda ver
 * el viaje de otro cliente. Desde la refactorización de la sección 4.3,
 * esta regla vive centralizada en App\Policies\ViajePolicy::ver().
 */
class ViajeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_conductor_no_asignado_no_puede_ver_el_detalle_del_viaje(): void
    {
        $clienteCuenta = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $clienteCuenta->persona_id]);

        $conductorAsignado = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->create(['persona_id' => $conductorAsignado->persona_id]);

        $viaje = Viaje::factory()->create([
            'cliente_id' => $clienteCuenta->persona_id,
            'conductor_id' => $conductorAsignado->persona_id,
        ]);

        $conductorAjeno = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->create(['persona_id' => $conductorAjeno->persona_id]);

        $response = $this->actingAs($conductorAjeno)->getJson("/api/v1/viajes/{$viaje->id}");

        $response->assertStatus(403);
    }

    public function test_cliente_no_puede_ver_el_viaje_de_otro_cliente(): void
    {
        $clienteDueno = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $clienteDueno->persona_id]);

        $viaje = Viaje::factory()->create(['cliente_id' => $clienteDueno->persona_id]);

        $otroCliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $otroCliente->persona_id]);

        $response = $this->actingAs($otroCliente)->getJson("/api/v1/viajes/{$viaje->id}");

        $response->assertStatus(403);
    }

    public function test_conductor_asignado_si_puede_ver_el_detalle_del_viaje(): void
    {
        $clienteCuenta = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $clienteCuenta->persona_id]);

        $conductorCuenta = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->create(['persona_id' => $conductorCuenta->persona_id]);

        $viaje = Viaje::factory()->create([
            'cliente_id' => $clienteCuenta->persona_id,
            'conductor_id' => $conductorCuenta->persona_id,
        ]);

        $response = $this->actingAs($conductorCuenta)->getJson("/api/v1/viajes/{$viaje->id}");

        $response->assertStatus(200);
    }

    public function test_admin_puede_ver_cualquier_viaje(): void
    {
        $viaje = Viaje::factory()->create();
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);

        $response = $this->actingAs($admin)->getJson("/api/v1/viajes/{$viaje->id}");

        $response->assertStatus(200);
    }
}
