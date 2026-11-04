<?php

declare(strict_types=1);

namespace App\Services\Cotizacion;

use App\Support\Dinero;

/**
 * Recargo dinámico aplicado a una cotización. Los porcentajes se manejan
 * en puntos básicos (1 % = 100) para no operar con float sobre dinero.
 */
final readonly class Recargo
{
    /**
     * @param  list<string>  $motivos
     */
    public function __construct(
        public int $totalPb,
        public int $demandaPb = 0,
        public int $climaPb = 0,
        public ?float $ratioDemanda = null,
        public ?float $precipitacionMm = null,
        public array $motivos = [],
    ) {}

    public static function ninguno(array $motivos = []): self
    {
        return new self(0, motivos: $motivos);
    }

    /**
     * Aplica el recargo a un monto ("1234.56") redondeando al centavo.
     */
    public function aplicar(string $monto): string
    {
        $centavos = Dinero::aCentavos($monto);

        return Dinero::desdeCentavos(intdiv($centavos * (10_000 + $this->totalPb) + 5_000, 10_000));
    }

    public static function porcentaje(int $puntosBasicos): string
    {
        return sprintf('%d.%02d', intdiv($puntosBasicos, 100), $puntosBasicos % 100);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'porcentaje' => self::porcentaje($this->totalPb),
            'demanda_porcentaje' => self::porcentaje($this->demandaPb),
            'clima_porcentaje' => self::porcentaje($this->climaPb),
            'ratio_demanda' => $this->ratioDemanda,
            'precipitacion_mm' => $this->precipitacionMm,
            'motivos' => $this->motivos,
        ];
    }
}
