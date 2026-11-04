<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use App\Enums\EstadoPago;
use App\Enums\MetodoPago;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PagoResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 42),
        new OA\Property(property: 'viaje_id', type: 'integer', example: 100),
        new OA\Property(property: 'metodo_pago', ref: MetodoPago::class),
        new OA\Property(property: 'monto', type: 'string', example: '2450.00', description: 'Decimal serializado como string (cast decimal:2). Siempre igual al importe del viaje.'),
        new OA\Property(property: 'referencia_externa', type: 'string', nullable: true, example: '123456789', description: 'Número de operación del QR o la terminal (cobros digitales); en Mercado Pago, el id del pago aprobado.'),
        new OA\Property(property: 'proveedor', type: 'string', nullable: true, enum: ['mercadopago'], description: '"mercadopago" si el cobro se generó con Mercado Pago (se confirma automáticamente); null para efectivo o registros manuales.'),
        new OA\Property(property: 'preferencia_id', type: 'string', nullable: true, description: 'Preferencia de Checkout Pro (solo Mercado Pago).'),
        new OA\Property(property: 'enlace_pago', type: 'string', nullable: true, format: 'uri', description: 'Enlace de Checkout Pro para el pasajero (solo Mercado Pago).'),
        new OA\Property(property: 'preferencia_expira_en', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'estado', ref: EstadoPago::class, description: 'efectivo: confirmado al registrarse. Mercado Pago: pendiente hasta que el proveedor aprueba el pago (automático); reintegrado si luego se devolvió. QR/tarjeta manual: pendiente hasta la conciliación administrativa.'),
        new OA\Property(property: 'fecha_pago', type: 'string', format: 'date-time', nullable: true, description: 'Momento de confirmación del cobro; null mientras está pendiente o si fue rechazado.'),
        new OA\Property(property: 'registrado_por_persona_id', type: 'integer', nullable: true),
        new OA\Property(property: 'conciliado_por_persona_id', type: 'integer', nullable: true),
        new OA\Property(property: 'conciliado_en', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'observacion', type: 'string', nullable: true),
        new OA\Property(property: 'comprobante_url', type: 'string', nullable: true, description: 'Solo en las respuestas de registro y conciliación: URL de GET /viajes/{id}/comprobante cuando el pago está confirmado.'),
        new OA\Property(property: 'historial', type: 'array', nullable: true, items: new OA\Items(type: 'object'), description: 'Transiciones del pago (registro, reintento, conciliación, novedades de Mercado Pago con mp_payment_id y mp_status).'),
    ],
)]
final class PagoSchema {}
