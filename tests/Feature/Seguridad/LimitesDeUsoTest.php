<?php

declare(strict_types=1);

namespace Tests\Feature\Seguridad;

use App\Enums\RolPersona;
use App\Events\UbicacionConductorActualizada;
use App\Models\Conductor;
use App\Models\Cuenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Límites de paginación y de frecuencia de solicitudes.
 */
class LimitesDeUsoTest extends TestCase
{
    use RefreshDatabase;

    public function test_per_page_se_acota_a_100(): void
    {
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);

        $this->actingAs($admin)
            ->getJson('/api/v1/viajes?per_page=100000')
            ->assertOk()
            ->assertJsonPath('per_page', 100);
    }

    public function test_los_pings_de_gps_tienen_limite_de_frecuencia(): void
    {
        Event::fake([UbicacionConductorActualizada::class]);
        config(['remiseria.rate_limits.ubicacion' => 2]);
        $conductorCuenta = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->create(['persona_id' => $conductorCuenta->persona_id]);

        $payload = ['lat' => -34.9214, 'lng' => -57.9544];

        $this->actingAs($conductorCuenta)->patchJson('/api/v1/conductor/ubicacion', $payload)->assertOk();
        $this->actingAs($conductorCuenta)->patchJson('/api/v1/conductor/ubicacion', $payload)->assertOk();
        $this->actingAs($conductorCuenta)->patchJson('/api/v1/conductor/ubicacion', $payload)->assertStatus(429);
    }
}
