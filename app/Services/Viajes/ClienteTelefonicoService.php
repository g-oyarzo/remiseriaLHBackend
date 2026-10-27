<?php

declare(strict_types=1);

namespace App\Services\Viajes;

use App\Models\Cliente;
use App\Models\Persona;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Resuelve el cliente de un viaje cargado por teléfono (CU 10).
 *
 * - Si existe una persona con ese DNI, se reutiliza (y se le agrega el
 *   rol cliente si no lo tenía), siempre que el nombre y el apellido
 *   coincidan: así un error de tipeo en el DNI no asocia el viaje a otra
 *   persona. Nunca se sobrescriben sus datos.
 * - Si no existe, se crea Persona + Cliente sin cuenta de acceso (el
 *   cliente telefónico no usa la app).
 */
final class ClienteTelefonicoService
{
    /**
     * @param  array{dni: string, nombre: string, apellido: string, telefono?: ?string}  $datos
     */
    public function resolver(array $datos): Cliente
    {
        $dni = self::normalizarDni($datos['dni']);

        return DB::transaction(function () use ($datos, $dni): Cliente {
            // El registro desde la app no normaliza el DNI, así que también se
            // busca tal como fue tipeado (p. ej. "30.123.456").
            $persona = Persona::query()
                ->whereIn('dni', array_unique([$dni, trim($datos['dni'])]))
                ->lockForUpdate()
                ->first();

            if ($persona === null) {
                $persona = Persona::query()->create([
                    'dni' => $dni,
                    'nombre' => trim($datos['nombre']),
                    'apellido' => trim($datos['apellido']),
                    'telefono' => $datos['telefono'] ?? null,
                ]);
            } elseif (! self::mismoNombre($persona, $datos)) {
                throw ValidationException::withMessages([
                    'cliente.dni' => ['El DNI ya está registrado a nombre de otra persona. Verifique los datos.'],
                ]);
            }

            return Cliente::query()->firstOrCreate(['persona_id' => $persona->id]);
        });
    }

    public static function normalizarDni(string $dni): string
    {
        return preg_replace('/\D+/', '', $dni) ?? '';
    }

    /**
     * @param  array{nombre: string, apellido: string}  $datos
     */
    private static function mismoNombre(Persona $persona, array $datos): bool
    {
        $normalizar = fn (string $texto): string => Str::lower(Str::ascii(preg_replace('/\s+/', ' ', trim($texto)) ?? ''));

        return $normalizar($persona->nombre) === $normalizar($datos['nombre'])
            && $normalizar($persona->apellido) === $normalizar($datos['apellido']);
    }
}
