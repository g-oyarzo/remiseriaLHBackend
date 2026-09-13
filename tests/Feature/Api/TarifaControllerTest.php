<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\RolPersona;
use App\Models\Cuenta;
use App\Models\Tarifa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cobertura para HALL-009 (race condition al crear tarifas) y la cache de
 * Tarifa::vigente() (Fase 4, rendimiento). HALL-020 señalaba que no existía
 * ningún test de TarifaController.
 */
class TarifaControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_puede_crear_tarifa(): void
    {
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/tarifas', [
            'precio_base' => 1800,
            'precio_por_km' => 400,
            'zona' => 'La Plata',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('tarifas', [
            'precio_base' => 1800,
            'activa' => true,
        ]);
    }

    public function test_crear_una_tarifa_desactiva_la_anterior_y_solo_queda_una_activa(): void
    {
        // HALL-009: antes esto se hacía en dos pasos sueltos (leer la
        // vigente, actualizarla; crear la nueva) sin transacción ni lock.
        // Esta prueba no reproduce la concurrencia real (para eso haría
        // falta lanzar dos procesos separados contra la misma fila), pero
        // sí verifica la invariante de negocio que el fix debe sostener:
        // nunca puede quedar más de una tarifa activa.
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);

        $tarifaOriginal = Tarifa::factory()->create(['activa' => true]);

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/tarifas', [
            'precio_base' => 2000,
            'precio_por_km' => 450,
        ]);

        $response->assertStatus(201);

        $this->assertSame(1, Tarifa::query()->where('activa', true)->count());
        $this->assertFalse($tarifaOriginal->fresh()->activa);
        $this->assertNotNull($tarifaOriginal->fresh()->vigente_hasta);
    }

    public function test_tarifa_vigente_se_cachea_y_se_invalida_al_crear_una_nueva(): void
    {
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);
        $tarifaVieja = Tarifa::factory()->create(['activa' => true, 'precio_base' => 1000]);

        // Primera lectura: la cachea.
        $this->assertSame(1000.0, (float) Tarifa::vigente()->precio_base);

        $this->actingAs($admin)->postJson('/api/v1/admin/tarifas', [
            'precio_base' => 5000,
            'precio_por_km' => 900,
        ])->assertStatus(201);

        // Si la cache no se hubiera invalidado en TarifaController::store(),
        // esta lectura seguiría devolviendo la tarifa vieja (1000).
        $this->assertSame(5000.0, (float) Tarifa::vigente()->precio_base);
    }

    public function test_no_admin_no_puede_crear_tarifa(): void
    {
        $cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);

        $response = $this->actingAs($cliente)->postJson('/api/v1/admin/tarifas', [
            'precio_base' => 1800,
            'precio_por_km' => 400,
        ]);

        $response->assertStatus(403);
    }
}
