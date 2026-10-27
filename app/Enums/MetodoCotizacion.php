<?php

declare(strict_types=1);

namespace App\Enums;

use OpenApi\Attributes as OA;

/**
 * Origen de la distancia usada para cotizar un viaje (CU 02).
 *
 * - mapa: distancia de manejo calculada por el servicio externo de ruteo.
 * - zona: el servicio de mapas falló; se aplicó el monto fijo de la zona
 *   tarifaria que cubre la distancia en línea recta (excepción 1b).
 * - estimacion_lineal: el servicio falló y ninguna zona cubre el viaje; se
 *   aplicó la fórmula de la tarifa sobre la distancia en línea recta
 *   multiplicada por el factor de corrección configurado.
 */
#[OA\Schema(
    schema: 'MetodoCotizacionEnum',
    type: 'string',
    enum: ['mapa', 'zona', 'estimacion_lineal'],
    example: 'mapa',
)]
enum MetodoCotizacion: string
{
    case Mapa = 'mapa';
    case Zona = 'zona';
    case EstimacionLineal = 'estimacion_lineal';
}
