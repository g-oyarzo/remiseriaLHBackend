<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\RolPersona;
use App\Models\Cuenta;
use App\Models\Tarifa;
use App\Models\ZonaTarifaria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * GET/PUT /admin/tarifas (CU 24) y zonas tarifarias de contingencia.
 */
class TarifaAdministracionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Cuenta
    {
        return Cuenta::factory()->create(['rol' => RolPersona::Administrador]);
    }

    public function test_get_devuelve_el_ultimo_registro_activo(): void
    {
        Tarifa::factory()->create(['activa' => false, 'precio_base' => 900, 'vigente_desde' => now()->subDays(10)]);
        $vigente = Tarifa::factory()->create(['activa' => true, 'precio_base' => 1500, 'precio_por_km' => 350]);

        $this->actingAs($this->admin())->getJson('/api/v1/admin/tarifas')
            ->assertOk()
            ->assertJsonPath('data.id', $vigente->id)
            ->assertJsonPath('data.precio_base', '1500.00')
            ->assertJsonPath('data.precio_por_km', '350.00');
    }

    public function test_get_sin_tarifa_configurada_devuelve_404(): void
    {
        $this->actingAs($this->admin())->getJson('/api/v1/admin/tarifas')->assertNotFound();
    }

    public function test_put_crea_una_nueva_version_y_cierra_la_anterior(): void
    {
        $admin = $this->admin();
        $anterior = Tarifa::factory()->create(['activa' => true, 'precio_base' => 1000]);

        $this->actingAs($admin)->putJson('/api/v1/admin/tarifas', [
            'precio_base' => '1800.50',
            'precio_por_km' => 420,
        ])
            ->assertOk()
            ->assertJsonPath('data.precio_base', '1800.50')
            ->assertJsonPath('data.precio_por_km', '420.00')
            ->assertJsonPath('data.activa', true)
            ->assertJsonPath('data.creada_por_persona_id', $admin->persona_id);

        $this->assertFalse($anterior->fresh()->activa);
        $this->assertNotNull($anterior->fresh()->vigente_hasta);
        // La versión anterior se conserva para el historial.
        $this->assertSame(2, Tarifa::query()->count());
        $this->assertSame(1, Tarifa::query()->where('activa', true)->count());
    }

    public function test_put_afecta_la_tarifa_vigente_de_las_cotizaciones_futuras(): void
    {
        Tarifa::factory()->create(['activa' => true, 'precio_base' => 1000]);
        $this->assertSame('1000.00', Tarifa::vigente()->precio_base);

        $this->actingAs($this->admin())->putJson('/api/v1/admin/tarifas', [
            'precio_base' => 2500,
            'precio_por_km' => 500,
        ])->assertOk();

        $this->assertSame('2500.00', Tarifa::vigente()->precio_base);
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function montosInvalidos(): array
    {
        return [
            'base cero' => [0, 400],
            'km cero' => [1500, 0],
            'base negativa' => [-10, 400],
            'km negativo' => [1500, -1],
            'texto' => ['mil', 400],
            'tres decimales' => [1500.123, 400],
            'excede decimal(10,2)' => [100000000, 400],
            'ausente' => [null, 400],
        ];
    }

    #[DataProvider('montosInvalidos')]
    public function test_put_rechaza_montos_invalidos(mixed $base, mixed $porKm): void
    {
        Tarifa::factory()->create(['activa' => true, 'precio_base' => 1000]);

        $this->actingAs($this->admin())->putJson('/api/v1/admin/tarifas', array_filter([
            'precio_base' => $base,
            'precio_por_km' => $porKm,
        ], fn ($valor) => $valor !== null))->assertUnprocessable();

        // No se creó ninguna versión nueva ni se cerró la vigente.
        $this->assertSame(1, Tarifa::query()->count());
        $this->assertTrue(Tarifa::query()->first()->activa);
    }

    public function test_solo_administradores_pueden_consultar_o_modificar(): void
    {
        foreach ([RolPersona::Cliente, RolPersona::Conductor] as $rol) {
            $cuenta = Cuenta::factory()->create(['rol' => $rol]);

            $this->actingAs($cuenta)->getJson('/api/v1/admin/tarifas')->assertForbidden();
            $this->actingAs($cuenta)->putJson('/api/v1/admin/tarifas', [
                'precio_base' => 1500,
                'precio_por_km' => 400,
            ])->assertForbidden();
        }

        $this->assertDatabaseCount('tarifas', 0);
    }

    public function test_put_sin_autenticacion_devuelve_401(): void
    {
        $this->putJson('/api/v1/admin/tarifas', ['precio_base' => 1, 'precio_por_km' => 1])->assertUnauthorized();
    }

    public function test_historial_lista_todas_las_versiones(): void
    {
        Tarifa::factory()->count(3)->create(['activa' => false]);
        Tarifa::factory()->create(['activa' => true]);

        $this->actingAs($this->admin())->getJson('/api/v1/admin/tarifas/historial')
            ->assertOk()
            ->assertJsonPath('total', 4);
    }

    public function test_crud_de_zonas_tarifarias(): void
    {
        $admin = $this->admin();

        $id = $this->actingAs($admin)->postJson('/api/v1/admin/zonas-tarifarias', [
            'nombre' => 'Casco urbano',
            'distancia_max_km' => 5,
            'monto' => 3500,
        ])->assertCreated()
            ->assertJsonPath('data.monto', '3500.00')
            ->json('data.id');

        $this->actingAs($admin)->putJson("/api/v1/admin/zonas-tarifarias/{$id}", [
            'nombre' => 'Casco urbano',
            'distancia_max_km' => 6,
            'monto' => 3800,
        ])->assertOk()->assertJsonPath('data.distancia_max_km', '6.00');

        $this->actingAs($admin)->deleteJson("/api/v1/admin/zonas-tarifarias/{$id}")
            ->assertOk()
            ->assertJsonPath('data.activa', false);

        $this->actingAs($admin)->getJson('/api/v1/admin/zonas-tarifarias')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_zona_con_monto_no_positivo_se_rechaza(): void
    {
        $this->actingAs($this->admin())->postJson('/api/v1/admin/zonas-tarifarias', [
            'nombre' => 'Zona inválida',
            'distancia_max_km' => 5,
            'monto' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('monto');
    }

    public function test_un_cliente_no_puede_administrar_zonas(): void
    {
        $cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);

        $this->actingAs($cliente)->postJson('/api/v1/admin/zonas-tarifarias', [
            'nombre' => 'Zona',
            'distancia_max_km' => 5,
            'monto' => 1000,
        ])->assertForbidden();
    }

    public function test_para_distancia_elige_la_banda_mas_chica_que_cubre_el_viaje(): void
    {
        ZonaTarifaria::factory()->create(['nombre' => 'Corta', 'distancia_max_km' => 3, 'monto' => 2000]);
        $media = ZonaTarifaria::factory()->create(['nombre' => 'Media', 'distancia_max_km' => 8, 'monto' => 4000]);
        ZonaTarifaria::factory()->create(['nombre' => 'Larga', 'distancia_max_km' => 20, 'monto' => 9000]);
        ZonaTarifaria::factory()->create(['nombre' => 'Inactiva', 'distancia_max_km' => 5, 'monto' => 1, 'activa' => false]);

        $this->assertSame($media->id, ZonaTarifaria::paraDistancia(5.5)?->id);
        $this->assertSame($media->id, ZonaTarifaria::paraDistancia(8.0)?->id);
        $this->assertNull(ZonaTarifaria::paraDistancia(25));
    }
}
