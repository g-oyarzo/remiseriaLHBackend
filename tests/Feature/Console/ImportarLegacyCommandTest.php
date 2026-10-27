<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Conductor;
use App\Models\Persona;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * legacy:importar - migración de clientes y conductores desde CSV.
 */
class ImportarLegacyCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $archivos = [];

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = storage_path('logs/importacion-legacy-'.now()->format('Y-m-d').'.log');
        @unlink($this->log);
    }

    protected function tearDown(): void
    {
        foreach ($this->archivos as $archivo) {
            @unlink($archivo);
        }
        @unlink($this->log);

        parent::tearDown();
    }

    private function csv(string $contenido, string $extension = 'csv'): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'legacy').'.'.$extension;
        file_put_contents($ruta, $contenido);
        $this->archivos[] = $ruta;

        return $ruta;
    }

    public function test_importa_clientes_validos_y_registra_las_filas_con_error(): void
    {
        Persona::factory()->create(['dni' => '30.111.222']);

        $archivo = $this->csv(implode("\n", [
            'DNI;Nombre;Apellido;Teléfono',
            '20.123.456;  ana  maría ;PÉREZ;+54 221 555-1234',
            '1234;Juan;Gómez;',             // DNI inválido
            '20123456;Ana;Pérez;',           // duplicada en el archivo
            '30111222;Luis;Díaz;',           // ya existe (guardada con puntos)
            '25999888;Eva;López;',
        ]));

        $this->artisan('legacy:importar', ['archivo' => $archivo, '--tipo' => 'clientes', '--delimitador' => ';'])
            ->assertSuccessful();

        $ana = Persona::query()->where('dni', '20123456')->firstOrFail();
        $this->assertSame('Ana María', $ana->nombre);
        $this->assertSame('Pérez', $ana->apellido);
        $this->assertNotNull($ana->cliente);
        $this->assertNull($ana->cuenta);
        $this->assertNotNull(Persona::query()->where('dni', '25999888')->first()?->cliente);
        $this->assertSame(1, Persona::query()->whereIn('dni', ['30111222', '30.111.222'])->count());

        $log = file_get_contents($this->log);
        $this->assertStringContainsString('Fila 3 no migrada: DNI inválido', $log);
        $this->assertStringContainsString('Fila 4 no migrada: duplicada dentro del archivo', $log);
        $this->assertStringContainsString('Fila 5 omitida', $log);
        // El log no contiene DNIs completos.
        $this->assertStringNotContainsString('20123456', $log);
        $this->assertStringContainsString('*****456', $log);
    }

    public function test_importa_conductores_validando_cuil_y_edad(): void
    {
        $archivo = $this->csv(implode("\n", [
            'dni,nombre,apellido,telefono,cuil,fecha_nacimiento,domicilio_localidad,domicilio_calle,domicilio_numero',
            '12345678,Carlos,Ruiz,2215550000,20-12345678-6,15/03/1980,La Plata,Calle 7,800',
            '30123456,Marta,Sosa,,27301234568,1985-11-02,La Plata,Calle 50,1200',
            '11222333,Pedro,Gil,,20-11222333-0,01/01/1970,La Plata,Calle 1,1',  // dígito verificador inválido
            '22333444,Rosa,Paz,,20-99999998-1,01/01/1970,La Plata,Calle 1,1',  // CUIL de otro DNI
            '33444555,Teo,Luna,,20-33444555-1,'.now()->subYears(17)->format('d/m/Y').',La Plata,Calle 1,1',
        ]));

        $this->artisan('legacy:importar', ['archivo' => $archivo, '--tipo' => 'conductores'])->assertSuccessful();

        $carlos = Conductor::query()->where('cuil', '20-12345678-6')->firstOrFail();
        $this->assertSame('1980-03-15', $carlos->fecha_nacimiento->toDateString());
        $this->assertFalse($carlos->en_servicio);
        $this->assertNull($carlos->vehiculo_id);
        $this->assertNotNull(Conductor::query()->where('cuil', '27-30123456-8')->first());
        $this->assertSame(2, Conductor::query()->count());

        $log = file_get_contents($this->log);
        $this->assertStringContainsString('dígito verificador inválido', $log);
        $this->assertStringContainsString('el CUIL no corresponde al DNI', $log);
        $this->assertStringContainsString('mayor de 18 años', $log);
    }

    public function test_la_simulacion_no_guarda_nada(): void
    {
        $archivo = $this->csv("dni,nombre,apellido\n20123456,Ana,Pérez\n");

        $this->artisan('legacy:importar', ['archivo' => $archivo, '--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseCount('personas', 0);
    }

    public function test_repetir_la_importacion_no_duplica_ni_modifica(): void
    {
        $archivo = $this->csv("dni,nombre,apellido\n20123456,Ana,Pérez\n");

        $this->artisan('legacy:importar', ['archivo' => $archivo])->assertSuccessful();
        Persona::query()->where('dni', '20123456')->update(['nombre' => 'Ana Editada']);
        $this->artisan('legacy:importar', ['archivo' => $archivo])->assertSuccessful();

        $this->assertSame(1, Persona::query()->count());
        $this->assertSame('Ana Editada', Persona::query()->first()->nombre);
    }

    public function test_puede_reanudar_desde_una_fila(): void
    {
        $archivo = $this->csv("dni,nombre,apellido\n20123456,Ana,Pérez\n25999888,Eva,López\n");

        $this->artisan('legacy:importar', ['archivo' => $archivo, '--desde-fila' => 3])->assertSuccessful();

        $this->assertNull(Persona::query()->where('dni', '20123456')->first());
        $this->assertNotNull(Persona::query()->where('dni', '25999888')->first());
    }

    public function test_convierte_archivos_en_windows_1252(): void
    {
        $archivo = $this->csv(mb_convert_encoding("dni,nombre,apellido\n20123456,José,Muñoz\n", 'Windows-1252', 'UTF-8'));

        $this->artisan('legacy:importar', ['archivo' => $archivo, '--codificacion' => 'Windows-1252'])->assertSuccessful();

        $this->assertSame('Muñoz', Persona::query()->where('dni', '20123456')->firstOrFail()->apellido);
    }

    public function test_rechaza_archivos_sin_las_columnas_obligatorias_o_inexistentes(): void
    {
        $this->artisan('legacy:importar', ['archivo' => $this->csv("dni,nombre\n20123456,Ana\n")])->assertFailed();
        $this->artisan('legacy:importar', ['archivo' => 'C:/no/existe.csv'])->assertFailed();
        $this->artisan('legacy:importar', ['archivo' => $this->csv("dni,nombre,apellido\n", 'php')])->assertFailed();
        $this->artisan('legacy:importar', ['archivo' => $this->csv("dni,nombre,apellido\n"), '--tipo' => 'vehiculos'])->assertFailed();

        $this->assertDatabaseCount('personas', 0);
    }
}
