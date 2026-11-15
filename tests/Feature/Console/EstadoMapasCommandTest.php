<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Mapas\GuardaDeProveedor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EstadoMapasCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mapas.driver' => 'ors',
            'services.mapas.ors.api_key' => 'k',
            'services.mapas.ors.url' => 'https://ors.test/openrouteservice',
            'services.mapas.cache_minutos' => 0,
            'services.mapas.reintentos' => 0,
            'services.mapas.cuotas.ors' => ['por_minuto' => 35, 'por_dia' => 1900, 'por_mes' => null],
        ]);
    }

    public function test_muestra_el_consumo_sin_llamar_al_proveedor(): void
    {
        Http::fake();
        (new GuardaDeProveedor('ors'))->registrarConsumo();

        $this->artisan('mapas:estado')
            ->expectsOutputToContain('Driver configurado: ors')
            ->expectsTable(['Período', 'Consultas', 'Límite local'], [
                ['Minuto actual', 1, 35],
                ['Día actual', 1, 1900],
                ['Mes actual', 1, 'sin límite'],
            ])
            ->expectsOutputToContain('Circuito: cerrado')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_la_prueba_real_informa_exito_o_falla(): void
    {
        Http::fake(['ors.test/*' => Http::sequence()
            ->push(['routes' => [['summary' => ['distance' => 3100, 'duration' => 420]]]])
            ->push('error', 503)]);

        $this->artisan('mapas:estado --probar')->expectsOutputToContain('3.1 km, 7 min')->assertSuccessful();
        $this->artisan('mapas:estado --probar')->expectsOutputToContain('contingencia por zonas')->assertFailed();
    }
}
