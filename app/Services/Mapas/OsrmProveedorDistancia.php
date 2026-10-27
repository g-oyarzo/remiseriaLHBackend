<?php

declare(strict_types=1);

namespace App\Services\Mapas;

use App\ValueObjects\Coordinate;
use Illuminate\Http\Client\PendingRequest;

/**
 * OSRM (Open Source Routing Machine, datos de OpenStreetMap).
 *
 * El servidor público router.project-osrm.org es solo para pruebas (su
 * política de uso no admite tráfico de producción); en producción se debe
 * apuntar MAPAS_OSRM_URL a una instancia propia.
 *
 * @see https://project-osrm.org/docs/v5.24.0/api/#route-service
 */
final class OsrmProveedorDistancia extends ProveedorHttp
{
    public function __construct(private readonly string $urlBase) {}

    protected function nombre(): string
    {
        return 'osrm';
    }

    protected function consultar(PendingRequest $http, Coordinate $origen, Coordinate $destino): array
    {
        // OSRM usa el orden longitud,latitud.
        $url = sprintf(
            '%s/route/v1/driving/%F,%F;%F,%F',
            rtrim($this->urlBase, '/'),
            $origen->lng, $origen->lat, $destino->lng, $destino->lat,
        );

        $json = $this->exigirExito($http->get($url, ['overview' => 'false']))->json();

        if (($json['code'] ?? null) !== 'Ok' || ! isset($json['routes'][0])) {
            throw new ServicioMapasNoDisponible('OSRM no encontró una ruta entre los puntos.');
        }

        return [$json['routes'][0]['distance'] ?? null, $json['routes'][0]['duration'] ?? null];
    }
}
