<?php

namespace App\Providers;

use App\Events\Contracts\DifusionEnTiempoReal;
use App\Services\Clima\OpenMeteoProveedorClima;
use App\Services\Clima\ProveedorClima;
use App\Services\Clima\SinProveedorClima;
use App\Services\Mapas\GoogleRoutesProveedorDistancia;
use App\Services\Mapas\OpenRouteServiceProveedorDistancia;
use App\Services\Mapas\OsrmProveedorDistancia;
use App\Services\Mapas\ProveedorDistancia;
use App\Services\Mapas\SinProveedorDistancia;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ProveedorClima::class, fn (): ProveedorClima => config('services.clima.driver') === 'openmeteo'
            ? new OpenMeteoProveedorClima(config('services.clima.openmeteo.url'), config('services.clima.openmeteo.api_key'))
            : new SinProveedorClima);

        // Una configuración incompleta no debe dejar sin cotizaciones a la
        // operación: se registra el error y se usa la contingencia por zonas.
        $this->app->bind(ProveedorDistancia::class, function (): ProveedorDistancia {
            $config = config('services.mapas');

            $proveedor = match ($config['driver']) {
                'osrm' => filled($config['osrm']['url'] ?? null)
                    ? new OsrmProveedorDistancia($config['osrm']['url'])
                    : 'falta MAPAS_OSRM_URL',
                'ors' => filled($config['ors']['api_key'] ?? null)
                    ? new OpenRouteServiceProveedorDistancia($config['ors']['api_key'], $config['ors']['url'], $config['ors']['perfil'] ?? 'driving-car')
                    : 'falta ORS_API_KEY',
                'google' => filled($config['google']['api_key'] ?? null)
                    ? new GoogleRoutesProveedorDistancia($config['google']['api_key'], $config['google']['url'])
                    : 'falta GOOGLE_MAPS_API_KEY',
                'ninguno', 'zonas' => new SinProveedorDistancia,
                default => 'driver desconocido',
            };

            if (is_string($proveedor)) {
                Log::error('Servicio de mapas mal configurado; se usa la contingencia por zonas.', [
                    'driver' => $config['driver'],
                    'motivo' => $proveedor,
                ]);

                return new SinProveedorDistancia;
            }

            return $proveedor;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurarLimitesDeFrecuencia();

        if (filled($conexion = config('remiseria.difusion.conexion_cola'))) {
            Queue::route(DifusionEnTiempoReal::class, connection: $conexion);
        }
    }

    /**
     * Límites por usuario autenticado (o por IP si no hay sesión). Los
     * valores por defecto contemplan el uso normal: un ping GPS cada 2-4 s
     * (RNF02) entra holgado en 60/min, y un chat activo rara vez supera un
     * mensaje cada pocos segundos.
     */
    private function configurarLimitesDeFrecuencia(): void
    {
        foreach (array_keys(config('remiseria.rate_limits')) as $nombre) {
            RateLimiter::for($nombre, fn (Request $request): Limit => Limit::perMinute((int) config("remiseria.rate_limits.{$nombre}"))
                ->by(($request->user()?->getAuthIdentifier() ?? $request->ip()).'|'.$nombre));
        }
    }
}
