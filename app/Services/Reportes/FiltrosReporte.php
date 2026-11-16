<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use App\Enums\EstadoViaje;
use Carbon\CarbonImmutable;

final readonly class FiltrosReporte
{
    public function __construct(
        public CarbonImmutable $desde,
        public CarbonImmutable $hasta,
        public ?string $conductorDni = null,
        public ?EstadoViaje $estado = null,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'fecha_desde' => $this->desde->toDateString(),
            'fecha_hasta' => $this->hasta->toDateString(),
            'conductor_dni' => $this->conductorDni,
            'estado' => $this->estado?->value,
        ];
    }
}
