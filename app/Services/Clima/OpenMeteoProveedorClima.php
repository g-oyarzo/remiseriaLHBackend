<?php

declare(strict_types=1);

namespace App\Services\Clima;

use App\ValueObjects\Coordinate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Open-Meteo (https://open-meteo.com): precipitación actual ("current").
 *
 * La API pública gratuita es solo para uso no comercial; para uso comercial
 * Open-Meteo exige un plan con API key (CLIMA_OPENMETEO_API_KEY, contra
 * customer-api.open-meteo.com). La respuesta se cachea por zona (~11 km) para
 * no consultar el servicio en cada cotización.
 */
final class OpenMeteoProveedorClima implements ProveedorClima
{
    public function __construct(
        private readonly string $url,
        private readonly ?string $apiKey = null,
    ) {}

    public function precipitacionMm(Coordinate $punto): ?float
    {
        $lat = round($punto->lat, 1);
        $lng = round($punto->lng, 1);
        $minutos = max(1, (int) config('services.clima.cache_minutos'));

        try {
            return Cache::remember(
                sprintf('clima:precipitacion:%.1F,%.1F', $lat, $lng),
                now()->addMinutes($minutos),
                fn (): ?float => $this->consultar($lat, $lng),
            );
        } catch (Throwable $e) {
            // La URL puede incluir la API key: no se registra el mensaje.
            Log::warning('Servicio de clima no disponible; no se aplica recargo por clima.', ['error' => class_basename($e)]);

            return null;
        }
    }

    private function consultar(float $lat, float $lng): ?float
    {
        $timeout = (float) config('services.clima.timeout_segundos');

        $respuesta = Http::acceptJson()
            ->timeout($timeout)
            ->connectTimeout($timeout)
            ->get($this->url, array_filter([
                'latitude' => $lat,
                'longitude' => $lng,
                'current' => 'precipitation',
                'apikey' => $this->apiKey,
            ]));

        $valor = $respuesta->successful() ? $respuesta->json('current.precipitation') : null;

        if (! is_numeric($valor) || $valor < 0 || $valor > 500) {
            throw new \UnexpectedValueException('Respuesta de clima inválida.');
        }

        return (float) $valor;
    }
}
