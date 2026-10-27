<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Utilidades para montos en pesos con dos decimales.
 *
 * Los cálculos se hacen en centavos enteros para no arrastrar errores de
 * punto flotante (por ejemplo 0.1 + 0.2), y los montos se intercambian con
 * la base y con la API como strings "1234.56", igual que el cast decimal:2
 * de Eloquent.
 */
final class Dinero
{
    /** Máximo representable en una columna DECIMAL(10, 2). */
    public const MAXIMO = '99999999.99';

    /**
     * Reglas de validación para un monto estrictamente positivo con, como
     * máximo, dos decimales.
     *
     * @return list<string>
     */
    public static function reglasMontoPositivo(): array
    {
        return ['required', 'numeric', 'gt:0', 'max:'.self::MAXIMO, 'decimal:0,2'];
    }

    /**
     * Convierte un monto decimal ("1234.5", 1234.5, "1234.56") a centavos.
     */
    public static function aCentavos(string|int|float $monto): int
    {
        $texto = is_float($monto) ? number_format($monto, 2, '.', '') : trim((string) $monto);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $texto, $partes)) {
            throw new InvalidArgumentException("Monto inválido: {$texto}");
        }

        $centavos = (int) $partes[2] * 100 + (int) str_pad($partes[3] ?? '0', 2, '0');

        return $partes[1] === '-' ? -$centavos : $centavos;
    }

    /**
     * Convierte centavos a string decimal con dos dígitos ("1234.56").
     */
    public static function desdeCentavos(int $centavos): string
    {
        $signo = $centavos < 0 ? '-' : '';
        $absoluto = abs($centavos);

        return sprintf('%s%d.%02d', $signo, intdiv($absoluto, 100), $absoluto % 100);
    }
}
