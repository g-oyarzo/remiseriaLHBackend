<?php

declare(strict_types=1);

namespace App\Services\Mapas;

use App\ValueObjects\Coordinate;
use Illuminate\Http\Client\PendingRequest;

/**
 * openrouteservice (HeiGIT, datos de OpenStreetMap), perfil driving-car.
 *
 * - URL base vigente: https://api.heigit.org/openrouteservice (el dominio
 *   api.openrouteservice.org fue dado de baja en noviembre de 2026).
 * - La API key viaja en el header Authorization (sin "Bearer"), nunca en la
 *   query string.
 * - Plan Standard (gratuito): 2.000 consultas de Directions por día y 40 por
 *   minuto; los límites locales se configuran por debajo
 *   (services.mapas.cuotas.ors).
 * - Los resultados se publican bajo CC-BY 4.0 y los datos de OSM bajo ODbL:
 *   los frontends deben mostrar la atribución (ver docs/cambios-para-frontend.md).
 *
 * Errores relevantes del cuerpo {"error": {"code": N}}:
 * 2009 (no se encontró ruta) y 2010 (un punto no está cerca de ninguna calle)
 * significan que no hay recorrido; 2004 (límite de distancia del servicio)
 * se trata como falla para usar la contingencia.
 *
 * @see https://giscience.github.io/openrouteservice/api-reference/endpoints/directions/
 */
final class OpenRouteServiceProveedorDistancia extends ProveedorHttp
{
    /** Códigos de error de ORS que indican que no hay ruta entre los puntos. */
    private const CODIGOS_SIN_RUTA = [2009, 2010];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $urlBase,
        private readonly string $perfil = 'driving-car',
    ) {}

    protected function nombre(): string
    {
        return 'ors';
    }

    protected function consultar(PendingRequest $http, Coordinate $origen, Coordinate $destino): array
    {
        $respuesta = $http
            ->withHeaders(['Authorization' => $this->apiKey])
            ->post(rtrim($this->urlBase, '/')."/v2/directions/{$this->perfil}", [
                // ORS usa el orden [longitud, latitud].
                'coordinates' => [[$origen->lng, $origen->lat], [$destino->lng, $destino->lat]],
                'units' => 'm',
                'instructions' => false,
                'geometry' => false,
            ]);

        $codigo = $respuesta->json('error.code');

        if (is_numeric($codigo) && in_array((int) $codigo, self::CODIGOS_SIN_RUTA, true)) {
            throw new RutaInexistente("openrouteservice no encontró un recorrido por calles entre los puntos (código {$codigo}).");
        }

        $resumen = $this->exigirExito($respuesta)->json('routes.0.summary');

        if (! is_array($resumen)) {
            throw new ServicioMapasNoDisponible('openrouteservice devolvió una respuesta sin rutas.');
        }

        return [$resumen['distance'] ?? null, $resumen['duration'] ?? null];
    }
}
