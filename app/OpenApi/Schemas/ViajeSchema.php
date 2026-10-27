<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use App\Enums\EstadoViaje;
use App\Enums\MetodoCotizacion;
use App\Enums\MetodoPago;
use App\Enums\TipoViaje;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ViajeResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 100),
        new OA\Property(property: 'cliente_id', type: 'integer', example: 5),
        new OA\Property(property: 'conductor_id', type: 'integer', nullable: true, example: 8),
        new OA\Property(property: 'vehiculo_id', type: 'integer', nullable: true, example: 10),
        new OA\Property(property: 'tarifa_id', type: 'integer', nullable: true, example: 3),
        new OA\Property(property: 'origen', ref: CoordenadaSchema::class),
        new OA\Property(property: 'destino', ref: CoordenadaSchema::class),
        new OA\Property(property: 'origen_localidad', type: 'string', nullable: true, example: 'La Plata'),
        new OA\Property(property: 'origen_calle', type: 'string', nullable: true, example: 'Calle 7'),
        new OA\Property(property: 'origen_numero', type: 'string', nullable: true, example: '800'),
        new OA\Property(property: 'destino_localidad', type: 'string', nullable: true, example: 'La Plata'),
        new OA\Property(property: 'destino_calle', type: 'string', nullable: true, example: 'Calle 50'),
        new OA\Property(property: 'destino_numero', type: 'string', nullable: true, example: '1200'),
        new OA\Property(property: 'estado', ref: EstadoViaje::class),
        new OA\Property(property: 'tipo', ref: TipoViaje::class),
        new OA\Property(property: 'metodo_pago', ref: MetodoPago::class, nullable: true, description: 'Método elegido por el cliente al solicitar (CU 21).'),
        new OA\Property(property: 'canal', type: 'string', enum: ['app', 'telefono'], example: 'app'),
        new OA\Property(property: 'costo', type: 'string', nullable: true, example: '2450.00', description: 'Monto cotizado al solicitar. Decimal serializado como string (cast decimal:2).'),
        new OA\Property(property: 'precio_base_aplicado', type: 'string', nullable: true, example: '1500.00', description: 'Tarifa base con la que se cotizó (snapshot).'),
        new OA\Property(property: 'precio_por_km_aplicado', type: 'string', nullable: true, example: '350.00', description: 'Precio por km con el que se cotizó (snapshot).'),
        new OA\Property(property: 'distancia_estimada_km', type: 'number', format: 'float', nullable: true, example: 2.714),
        new OA\Property(property: 'duracion_estimada_min', type: 'integer', nullable: true, example: 9),
        new OA\Property(property: 'metodo_cotizacion', ref: MetodoCotizacion::class, nullable: true),
        new OA\Property(property: 'calificacion', type: 'integer', nullable: true, minimum: 1, maximum: 5, example: 5),
        new OA\Property(property: 'fecha_viaje', type: 'string', format: 'date-time', example: '2026-09-01T14:30:00-03:00'),
        new OA\Property(property: 'despachado_en', type: 'string', format: 'date-time', nullable: true, description: 'Momento en que el viaje entró en búsqueda de conductor.'),
        new OA\Property(property: 'busqueda_agotada_en', type: 'string', format: 'date-time', nullable: true, description: 'Si no se encontró conductor tras agotar ofertas o reintentos.'),
        new OA\Property(property: 'iniciado_en', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'distancia_recorrida_metros', type: 'integer', example: 5230, description: 'Distancia acumulada por GPS durante el viaje en curso.'),
        new OA\Property(property: 'finalizado_en', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'distancia_final_km', type: 'number', format: 'float', nullable: true, example: 5.23),
        new OA\Property(property: 'duracion_final_min', type: 'integer', nullable: true, example: 14),
        new OA\Property(property: 'costo_final', type: 'string', nullable: true, example: '3330.50', description: 'Monto a cobrar, calculado por el backend al finalizar. El pago debe registrarse por este monto exacto.'),
        new OA\Property(property: 'costo_final_origen', type: 'string', enum: ['recorrido', 'estimado', 'zona'], nullable: true, description: 'recorrido: fórmula sobre la distancia GPS; estimado: seguimiento insuficiente, se cobra lo cotizado; zona: monto fijo de zona tarifaria.'),
        new OA\Property(property: 'distancia_al_destino_metros', type: 'integer', nullable: true, example: 35, description: 'Distancia al destino al momento de finalizar (geocerca de 100 m).'),
        new OA\Property(property: 'cliente', ref: ClienteSchema::class, nullable: true),
        new OA\Property(property: 'conductor', ref: ConductorSchema::class, nullable: true),
        new OA\Property(property: 'vehiculo', ref: VehiculoSchema::class, nullable: true),
        new OA\Property(property: 'tarifa', ref: TarifaSchema::class, nullable: true),
        new OA\Property(property: 'pago', ref: PagoSchema::class, nullable: true),
    ],
)]
final class ViajeSchema {}
