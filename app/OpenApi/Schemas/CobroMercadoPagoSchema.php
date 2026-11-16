<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use App\Enums\EstadoPago;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CobroMercadoPago',
    type: 'object',
    properties: [
        new OA\Property(property: 'pago_id', type: 'integer', example: 42),
        new OA\Property(property: 'viaje_id', type: 'integer', example: 100),
        new OA\Property(property: 'monto', type: 'string', example: '2450.00', description: 'Importe exacto del viaje (precio cerrado cotizado).'),
        new OA\Property(property: 'moneda', type: 'string', example: 'ARS'),
        new OA\Property(property: 'estado', ref: EstadoPago::class),
        new OA\Property(property: 'enlace_pago', type: 'string', format: 'uri', example: 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=123-abc', description: 'Checkout Pro de Mercado Pago: abrirlo o mostrarlo como QR al pasajero.'),
        new OA\Property(property: 'vigente_hasta', type: 'string', format: 'date-time', description: 'Vencimiento del enlace; después se genera uno nuevo con el mismo endpoint.'),
    ],
)]
final class CobroMercadoPagoSchema {}
