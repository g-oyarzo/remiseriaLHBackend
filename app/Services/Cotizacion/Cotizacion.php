<?php

declare(strict_types=1);

namespace App\Services\Cotizacion;

use App\Enums\MetodoCotizacion;

/**
 * Resultado inmutable de una cotización, con los valores de tarifa usados
 * para calcularla. Se persiste en el viaje para que un cambio posterior de
 * la tarifa no altere retroactivamente lo cotizado.
 */
final readonly class Cotizacion
{
    public function __construct(
        public string $monto,
        public float $distanciaKm,
        public ?int $duracionMinutos,
        public MetodoCotizacion $metodo,
        public int $tarifaId,
        public string $precioBase,
        public string $precioPorKm,
        public ?int $zonaTarifariaId = null,
        public ?string $proveedorMapas = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'monto' => $this->monto,
            'distancia_km' => $this->distanciaKm,
            'duracion_minutos' => $this->duracionMinutos,
            'metodo' => $this->metodo->value,
            'tarifa' => [
                'id' => $this->tarifaId,
                'precio_base' => $this->precioBase,
                'precio_por_km' => $this->precioPorKm,
            ],
            'zona_tarifaria_id' => $this->zonaTarifariaId,
        ];
    }

    /**
     * Columnas del viaje donde se guarda la cotización (snapshot histórico).
     *
     * @return array<string, mixed>
     */
    public function atributosViaje(): array
    {
        return [
            'tarifa_id' => $this->tarifaId,
            'costo' => $this->monto,
            'precio_base_aplicado' => $this->precioBase,
            'precio_por_km_aplicado' => $this->precioPorKm,
            'distancia_estimada_km' => $this->distanciaKm,
            'duracion_estimada_min' => $this->duracionMinutos,
            'metodo_cotizacion' => $this->metodo,
            'zona_tarifaria_id' => $this->zonaTarifariaId,
        ];
    }
}
