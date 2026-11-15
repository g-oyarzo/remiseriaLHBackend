<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Persona;
use App\Models\Tarifa;
use App\Models\Viaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrepararCargaCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_datos_sinteticos_con_tokens_y_los_limpia(): void
    {
        $this->assertSame(0, Artisan::call('carga:preparar', ['--conductores' => 3, '--clientes' => 2]));
        $salida = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(3, $salida['conductores']);
        $this->assertCount(2, $salida['clientes']);
        $this->assertNotNull($salida['administrador']['token']);
        $this->assertSame(3, Conductor::query()->where('en_servicio', true)->count());
        $this->assertNotNull(Tarifa::vigente());

        // Los tokens sirven para la API real.
        $token = $salida['conductores'][0]['token'];
        $this->withToken($token)->postJson('/api/v1/conductor/ubicacion', [
            'lat' => $salida['conductores'][0]['lat'],
            'lng' => $salida['conductores'][0]['lng'],
        ])->assertOk();

        // Volver a preparar reemplaza los datos anteriores.
        Artisan::call('carga:preparar', ['--conductores' => 1, '--clientes' => 0]);
        $this->assertSame(2, Cuenta::query()->where('email', 'like', '%@carga.invalid')->count());

        $this->artisan('carga:preparar --limpiar')->assertSuccessful();
        $this->assertSame(0, Cuenta::query()->where('email', 'like', '%@carga.invalid')->count());
        $this->assertSame(0, Persona::query()->where('nombre', 'Carga')->count());
    }

    public function test_las_cuentas_sinteticas_con_viajes_se_retienen_sin_bloquear_la_limpieza(): void
    {
        Artisan::call('carga:preparar', ['--conductores' => 2, '--clientes' => 1]);
        $salida = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $viaje = Viaje::factory()->create([
            'cliente_id' => $salida['clientes'][0]['id'],
            'conductor_id' => $salida['conductores'][0]['id'],
        ]);

        $this->artisan('carga:preparar --limpiar')->assertSuccessful();

        // El viaje y sus personas se conservan, sin acceso y fuera de servicio.
        $this->assertNotNull($viaje->fresh());
        $this->assertSame(0, Cuenta::query()->where('email', 'like', '%@carga.invalid')->count());
        $this->assertSame(2, Cuenta::query()->where('email', 'like', '%@carga-retenida.invalid')->count());
        $this->assertFalse(Conductor::query()->findOrFail($salida['conductores'][0]['id'])->en_servicio);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());

        // Se puede volver a preparar sin choques de valores únicos.
        $this->assertSame(0, Artisan::call('carga:preparar', ['--conductores' => 2, '--clientes' => 1]));
    }

    public function test_no_corre_en_produccion(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->artisan('carga:preparar')->assertFailed();

        $this->assertSame(0, Cuenta::query()->count());
    }
}
