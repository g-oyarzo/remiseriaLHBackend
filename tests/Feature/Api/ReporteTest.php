<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\EstadoPago;
use App\Enums\EstadoViaje;
use App\Enums\MetodoPago;
use App\Enums\RolPersona;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Pago;
use App\Models\Persona;
use App\Models\Viaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * CU 15 (reporte estadístico) y CU 16 (exportación).
 */
class ReporteTest extends TestCase
{
    use RefreshDatabase;

    private Cuenta $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);

        $a = Conductor::factory()->create(['persona_id' => Persona::factory()->create(['dni' => '30111222'])->id]);
        $b = Conductor::factory()->create(['persona_id' => Persona::factory()->create(['dni' => '28999888'])->id]);

        $this->viaje($a, EstadoViaje::Finalizado, now()->subDays(2), '1000.50', EstadoPago::Confirmado);
        $this->viaje($a, EstadoViaje::Finalizado, now()->subDays(3), '2000.25', EstadoPago::Confirmado);
        $this->viaje($a, EstadoViaje::Finalizado, now()->subDays(4), '500.00', EstadoPago::Pendiente);
        $this->viaje($a, EstadoViaje::Cancelado, now()->subDays(5));
        $this->viaje($b, EstadoViaje::Finalizado, now()->subDays(1), '700.00', EstadoPago::Confirmado);
        // Fuera del período por defecto (últimos 30 días).
        $this->viaje($b, EstadoViaje::Finalizado, now()->subDays(60), '9999.00', EstadoPago::Confirmado);
    }

    private function viaje(Conductor $conductor, EstadoViaje $estado, \DateTimeInterface $fecha, ?string $importe = null, ?EstadoPago $pago = null): Viaje
    {
        $viaje = Viaje::factory()->create([
            'conductor_id' => $conductor->persona_id,
            'estado' => $estado,
            'fecha_viaje' => $fecha,
            'costo_final' => $importe,
            'distancia_final_km' => $estado === EstadoViaje::Finalizado ? 5.5 : null,
        ]);

        if ($pago !== null) {
            Pago::factory()->create([
                'viaje_id' => $viaje->id,
                'metodo_pago' => MetodoPago::Efectivo,
                'monto' => $importe,
                'estado' => $pago,
            ]);
        }

        return $viaje;
    }

    public function test_calcula_las_metricas_del_periodo_por_defecto(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/admin/reportes/estadisticas')
            ->assertOk()
            ->assertJsonPath('data.total_viajes', 5)
            ->assertJsonPath('data.viajes_finalizados', 4)
            ->assertJsonPath('data.viajes_cancelados', 1)
            ->assertJsonPath('data.por_estado.finalizado', 4)
            ->assertJsonPath('data.por_estado.solicitado', 0)
            // Solo pagos confirmados: 1000,50 + 2000,25 + 700 (el pendiente no suma).
            ->assertJsonPath('data.facturacion', '3700.75')
            ->assertJsonPath('data.pagos_confirmados', 3)
            ->assertJsonPath('data.cobros_pendientes', '500.00')
            ->assertJsonPath('data.ticket_promedio', '1233.58')
            ->assertJsonPath('data.distancia_total_km', 22);
    }

    public function test_filtra_por_dni_del_conductor_con_o_sin_puntos(): void
    {
        foreach (['30111222', '30.111.222'] as $dni) {
            $this->actingAs($this->admin)->getJson("/api/v1/admin/reportes/estadisticas?conductor_dni={$dni}")
                ->assertOk()
                ->assertJsonPath('data.total_viajes', 4)
                ->assertJsonPath('data.facturacion', '3000.75');
        }
    }

    public function test_filtra_por_estado_y_por_fechas(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/admin/reportes/estadisticas?estado=cancelado')
            ->assertOk()
            ->assertJsonPath('data.total_viajes', 1)
            ->assertJsonPath('data.facturacion', '0.00')
            ->assertJsonPath('data.ticket_promedio', null);

        $desde = now()->subDays(70)->toDateString();
        $hasta = now()->subDays(50)->toDateString();
        $this->actingAs($this->admin)->getJson("/api/v1/admin/reportes/estadisticas?fecha_desde={$desde}&fecha_hasta={$hasta}")
            ->assertOk()
            ->assertJsonPath('data.total_viajes', 1)
            ->assertJsonPath('data.facturacion', '9999.00');
    }

    public function test_valida_el_rango_de_fechas(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/admin/reportes/estadisticas?fecha_desde=2026-05-10&fecha_hasta=2026-05-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fecha_hasta');

        $this->actingAs($this->admin)->getJson('/api/v1/admin/reportes/estadisticas?fecha_desde=2024-01-01&fecha_hasta=2026-01-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fecha_desde');

        $this->actingAs($this->admin)->getJson('/api/v1/admin/reportes/estadisticas?estado=inexistente')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('estado');

        $this->actingAs($this->admin)->getJson("/api/v1/admin/reportes/estadisticas?conductor_dni=1' OR '1'='1")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('conductor_dni');
    }

    public function test_solo_administradores(): void
    {
        foreach ([RolPersona::Cliente, RolPersona::Conductor] as $rol) {
            $cuenta = Cuenta::factory()->create(['rol' => $rol]);
            $this->actingAs($cuenta)->getJson('/api/v1/admin/reportes/estadisticas')->assertForbidden();
            $this->actingAs($cuenta)->get('/api/v1/admin/reportes/exportar?formato=pdf')->assertForbidden();
        }
    }

    public function test_exporta_a_pdf(): void
    {
        $pdf = $this->actingAs($this->admin)->get('/api/v1/admin/reportes/exportar?formato=pdf')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->getContent();

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('3700.75', $pdf);
    }

    public function test_exporta_a_excel(): void
    {
        $contenido = $this->actingAs($this->admin)->get('/api/v1/admin/reportes/exportar?formato=excel')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->getContent();

        $ruta = tempnam(sys_get_temp_dir(), 'test-xlsx');
        file_put_contents($ruta, $contenido);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta));
        $detalle = $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();
        unlink($ruta);

        // Encabezado + 5 viajes del período.
        $this->assertSame(6, substr_count($detalle, '<row '));
        $this->assertStringContainsString('<v>1000.5</v>', $detalle);
    }

    public function test_formato_invalido_o_demasiadas_filas_devuelven_422(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/admin/reportes/exportar?formato=csv')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('formato');

        config(['remiseria.reportes.max_filas_exportacion' => 3]);
        $this->actingAs($this->admin)->getJson('/api/v1/admin/reportes/exportar?formato=pdf')->assertUnprocessable();
    }
}
