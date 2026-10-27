<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurarLimitesDeFrecuencia();
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
