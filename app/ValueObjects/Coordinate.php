<?php

declare(strict_types=1);

namespace App\ValueObjects;

use JsonSerializable;

/**
 * Representa un punto geográfico (SRID 4326 / WGS84).
 *
 * Se usa como tipo de "vuelta" del cast App\Casts\PointCast para las columnas
 * `geography(... , subtype: 'point')` de `viajes.origen`, `viajes.destino` y
 * `conductores.ubicacion_actual`.
 */
final readonly class Coordinate implements JsonSerializable
{
    public function __construct(
        public float $lat,
        public float $lng,
    ) {
    }

    /**
     * @param  array{lat: float|string, lng: float|string}  $coordenadas
     */
    public static function fromArray(array $coordenadas): self
    {
        return new self(
            lat: (float) $coordenadas['lat'],
            lng: (float) $coordenadas['lng'],
        );
    }

    /**
     * @return array{lat: float, lng: float}
     */
    public function toArray(): array
    {
        return [
            'lat' => $this->lat,
            'lng' => $this->lng,
        ];
    }

    /**
     * Representación WKT (Well-Known Text) usada por ST_GeomFromText().
     * Importante: en WKT el orden es POINT(longitud latitud), no (lat, lng).
     */
    public function toWkt(): string
    {
        return sprintf('POINT(%F %F)', $this->lng, $this->lat);
    }

    /**
     * Distancia aproximada en metros hacia otra coordenada (fórmula de Haversine).
     * Es útil para cálculos puntuales en PHP; para búsquedas de cercanía sobre
     * muchos registros conviene usar ST_Distance_Sphere() directamente en MySQL
     * (ver App\Models\Conductor::scopeCercanos()).
     */
    public function distanciaEnMetrosHacia(self $otra): float
    {
        $radioTierraMetros = 6_371_000.0;

        $deltaLat = deg2rad($otra->lat - $this->lat);
        $deltaLng = deg2rad($otra->lng - $this->lng);

        $a = sin($deltaLat / 2) ** 2
            + cos(deg2rad($this->lat)) * cos(deg2rad($otra->lat)) * sin($deltaLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $radioTierraMetros * $c;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}