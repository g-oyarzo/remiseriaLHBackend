<?php

declare(strict_types=1);

namespace App\Services\Geolocalizacion;

use App\ValueObjects\Coordinate;
use Carbon\CarbonImmutable;

/**
 * Último ping GPS conocido de un conductor y su antigüedad.
 */
final readonly class UbicacionRegistrada
{
    public function __construct(
        public Coordinate $coordenada,
        public CarbonImmutable $registradaEn,
    ) {}

    public function antiguedadSegundos(): int
    {
        return (int) max(0, $this->registradaEn->diffInSeconds(now(), absolute: false));
    }

    /**
     * @return array{lat: float, lng: float, ts: int}
     */
    public function toCache(): array
    {
        return $this->coordenada->toArray() + ['ts' => $this->registradaEn->getTimestamp()];
    }

    /**
     * @param  array{lat: float, lng: float, ts: int}  $datos
     */
    public static function fromCache(array $datos): self
    {
        return new self(
            Coordinate::fromArray($datos),
            CarbonImmutable::createFromTimestamp($datos['ts'], config('app.timezone')),
        );
    }
}
