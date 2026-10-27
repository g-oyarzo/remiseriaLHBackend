<?php

declare(strict_types=1);

namespace App\Services\Legacy;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Str;

/**
 * Normalización y validación de los campos del sistema anterior. Cada
 * método devuelve el valor normalizado o lanza FilaInvalida con un motivo
 * que no incluye el dato completo (los logs no deben exponer PII).
 */
final class NormalizadorLegacy
{
    public function texto(?string $valor, string $campo, int $maximo, bool $obligatorio = true): ?string
    {
        $limpio = trim(preg_replace('/\s+/u', ' ', (string) $valor) ?? '');
        $limpio = preg_replace('/[\p{C}]/u', '', $limpio) ?? '';

        if ($limpio === '') {
            if ($obligatorio) {
                throw new FilaInvalida("{$campo} vacío");
            }

            return null;
        }

        if (mb_strlen($limpio) > $maximo) {
            throw new FilaInvalida("{$campo} excede {$maximo} caracteres");
        }

        return $limpio;
    }

    public function nombre(?string $valor, string $campo): string
    {
        $texto = $this->texto($valor, $campo, 100);

        if (! preg_match("/^[\p{L}][\p{L} '.-]*$/u", $texto)) {
            throw new FilaInvalida("{$campo} contiene caracteres inválidos");
        }

        return mb_convert_case(mb_strtolower($texto), MB_CASE_TITLE, 'UTF-8');
    }

    public function dni(?string $valor): string
    {
        $dni = preg_replace('/\D+/', '', (string) $valor) ?? '';

        if (! preg_match('/^\d{7,8}$/', $dni)) {
            throw new FilaInvalida('DNI inválido (se esperan 7 u 8 dígitos)');
        }

        return $dni;
    }

    /**
     * CUIL con dígito verificador (módulo 11), con formato XX-XXXXXXXX-X.
     */
    public function cuil(?string $valor, string $dni): string
    {
        $cuil = preg_replace('/\D+/', '', (string) $valor) ?? '';

        if (! preg_match('/^\d{11}$/', $cuil)) {
            throw new FilaInvalida('CUIL inválido (se esperan 11 dígitos)');
        }

        $pesos = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $suma = 0;
        foreach ($pesos as $i => $peso) {
            $suma += (int) $cuil[$i] * $peso;
        }
        $verificador = 11 - ($suma % 11);
        $verificador = $verificador === 11 ? 0 : $verificador;

        if ($verificador === 10 || $verificador !== (int) $cuil[10]) {
            throw new FilaInvalida('CUIL con dígito verificador inválido');
        }

        if (ltrim(substr($cuil, 2, 8), '0') !== ltrim($dni, '0')) {
            throw new FilaInvalida('el CUIL no corresponde al DNI');
        }

        return substr($cuil, 0, 2).'-'.substr($cuil, 2, 8).'-'.$cuil[10];
    }

    public function telefono(?string $valor): ?string
    {
        $telefono = $this->texto($valor, 'teléfono', 30, obligatorio: false);

        if ($telefono !== null && ! preg_match('/^\+?[\d\s\-()]{6,30}$/', $telefono)) {
            throw new FilaInvalida('teléfono inválido');
        }

        return $telefono;
    }

    public function fechaNacimiento(?string $valor): string
    {
        $texto = trim((string) $valor);

        foreach (['d/m/Y', 'Y-m-d', 'd-m-Y', 'd/m/y'] as $formato) {
            try {
                $fecha = CarbonImmutable::createFromFormat('!'.$formato, $texto);
            } catch (InvalidFormatException) {
                continue;
            }

            if ($fecha !== null && $fecha->format($formato) === $texto) {
                if ($fecha->greaterThan(now()->subYears(18))) {
                    throw new FilaInvalida('el conductor debe ser mayor de 18 años');
                }

                if ($fecha->lessThan(now()->subYears(100))) {
                    throw new FilaInvalida('fecha de nacimiento fuera de rango');
                }

                return $fecha->toDateString();
            }
        }

        throw new FilaInvalida('fecha de nacimiento con formato inválido');
    }

    /**
     * DNI enmascarado para logs: solo los últimos 3 dígitos.
     */
    public static function enmascarar(?string $dni): string
    {
        $digitos = preg_replace('/\D+/', '', (string) $dni) ?? '';

        return $digitos === '' ? '-' : Str::mask($digitos, '*', 0, max(0, strlen($digitos) - 3));
    }
}
