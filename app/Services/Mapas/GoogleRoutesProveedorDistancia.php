<?php

declare(strict_types=1);

namespace App\Services\Mapas;

use App\ValueObjects\Coordinate;
use Illuminate\Http\Client\PendingRequest;

/**
 * Google Maps Routes API (computeRoutes).
 *
 * La API key viaja en el header X-Goog-Api-Key y no en la query string,
 * para que no quede en logs de proxies ni en mensajes de excepción.
 *
 * @see https://developers.google.com/maps/documentation/routes/compute_route_directions
 */
final class GoogleRoutesProveedorDistancia extends ProveedorHttp
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $url,
    ) {}

    protected function nombre(): string
    {
        return 'google';
    }

    protected function consultar(PendingRequest $http, Coordinate $origen, Coordinate $destino): array
    {
        $respuesta = $http
            ->withHeaders([
                'X-Goog-Api-Key' => $this->apiKey,
                'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration',
            ])
            ->post($this->url, [
                'origin' => ['location' => ['latLng' => ['latitude' => $origen->lat, 'longitude' => $origen->lng]]],
                'destination' => ['location' => ['latLng' => ['latitude' => $destino->lat, 'longitude' => $destino->lng]]],
                'travelMode' => 'DRIVE',
                'routingPreference' => 'TRAFFIC_UNAWARE',
                'units' => 'METRIC',
            ]);

        $json = $this->exigirExito($respuesta)->json();
        $ruta = is_array($json) ? ($json['routes'][0] ?? null) : null;

        if (! is_array($ruta)) {
            // Una respuesta 200 con cuerpo vacío ({}) significa que no hay ruta.
            throw $json === []
                ? new RutaInexistente('Google Routes no encontró un recorrido por calles entre los puntos.')
                : new ServicioMapasNoDisponible('Google Routes devolvió una respuesta sin rutas.');
        }

        // La duración llega como string con sufijo de segundos, p. ej. "754s".
        $duracion = $ruta['duration'] ?? null;
        $segundos = is_string($duracion) && preg_match('/^(\d+(?:\.\d+)?)s$/', $duracion, $m) ? $m[1] : null;

        return [$ruta['distanceMeters'] ?? null, $segundos];
    }
}
