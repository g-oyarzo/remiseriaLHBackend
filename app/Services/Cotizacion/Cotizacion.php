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
        public ?string $montoBase = null,
        public ?Recargo $recargo = null,
    ) {}

    /**
     * Misma cotización con el recargo dinámico aplicado sobre el monto.
     */
    public function conRecargo(Recargo $recargo): self
    {
        return new self(
            monto: $recargo->aplicar($this->monto),
            distanciaKm: $this->distanciaKm,
            duracionMinutos: $this->duracionMinutos,
            metodo: $this->metodo,
            tarifaId: $this->tarifaId,
            precioBase: $this->precioBase,
            precioPorKm: $this->precioPorKm,
            zonaTarifariaId: $this->zonaTarifariaId,
            proveedorMapas: $this->proveedorMapas,
            montoBase: $this->monto,
            recargo: $recargo,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'monto' => $this->monto,
            'monto_base' => $this->montoBase ?? $this->monto,
            'recargo' => ($this->recargo ?? Recargo::ninguno())->toArray(),
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
            'monto_base_cotizado' => $this->montoBase ?? $this->monto,
            'recargo_dinamico_pct' => Recargo::porcentaje(($this->recargo ?? Recargo::ninguno())->totalPb),
            'recargo_dinamico_detalle' => ($this->recargo ?? Recargo::ninguno())->toArray(),
            'precio_base_aplicado' => $this->precioBase,
            'precio_por_km_aplicado' => $this->precioPorKm,
            'distancia_estimada_km' => $this->distanciaKm,
            'duracion_estimada_min' => $this->duracionMinutos,
            'metodo_cotizacion' => $this->metodo,
            'zona_tarifaria_id' => $this->zonaTarifariaId,
        ];
    }
}
