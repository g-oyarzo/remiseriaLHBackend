<?php

declare(strict_types=1);

namespace App\Services\Legacy;

use App\Models\Cliente;
use App\Models\Conductor;
use App\Models\Persona;
use Illuminate\Support\Facades\DB;

/**
 * Importa una fila ya leída del CSV legacy (clientes o conductores).
 *
 * Política de no destrucción: si la persona (DNI) o el CUIL ya existen, la
 * fila se omite y no se modifica nada de lo existente. Por eso repetir una
 * importación es seguro. No se crean cuentas de acceso: las contraseñas del
 * sistema anterior no se pueden migrar de forma segura, así que el
 * administrador habilita el acceso de cada conductor (PUT /admin/conductores/{id}
 * con password) y los clientes se registran desde la app.
 */
final class ImportadorLegacy
{
    public const OMITIDA = 'omitida';

    public const IMPORTADA = 'importada';

    /** @var array<string, true> DNIs y CUILs ya vistos en este archivo */
    private array $vistos = [];

    public function __construct(private readonly NormalizadorLegacy $normalizador) {}

    /**
     * @param  'clientes'|'conductores'  $tipo
     * @param  array<string, string|null>  $fila  columnas por nombre normalizado
     * @return array{0: string, 1: string} [resultado, detalle]
     *
     * @throws FilaInvalida
     */
    public function importar(string $tipo, array $fila, bool $simulacion): array
    {
        $datos = $tipo === 'conductores' ? $this->datosConductor($fila) : $this->datosPersona($fila);

        foreach (array_filter([$datos['dni'], $datos['cuil'] ?? null]) as $clave) {
            if (isset($this->vistos[$clave])) {
                throw new FilaInvalida('duplicada dentro del archivo');
            }
            $this->vistos[$clave] = true;
        }

        // El registro desde la app no normaliza el DNI: también se busca con
        // puntos ("30.123.456") para no duplicar a una persona existente.
        $formasDni = [$datos['dni'], number_format((int) $datos['dni'], 0, ',', '.')];

        if (Persona::query()->whereIn('dni', $formasDni)->exists()) {
            return [self::OMITIDA, 'ya existe una persona con ese DNI; no se modifica'];
        }

        if (isset($datos['cuil']) && Conductor::query()->where('cuil', $datos['cuil'])->exists()) {
            return [self::OMITIDA, 'ya existe un conductor con ese CUIL; no se modifica'];
        }

        if ($simulacion) {
            return [self::IMPORTADA, 'válida (simulación, no se guardó)'];
        }

        DB::transaction(function () use ($tipo, $datos): void {
            $persona = Persona::query()->create([
                'dni' => $datos['dni'],
                'nombre' => $datos['nombre'],
                'apellido' => $datos['apellido'],
                'telefono' => $datos['telefono'],
            ]);

            if ($tipo === 'clientes') {
                Cliente::query()->create(['persona_id' => $persona->id]);

                return;
            }

            // Se importa fuera de servicio y sin vehículo: el administrador
            // completa el alta operativa desde el panel.
            Conductor::query()->create([
                'persona_id' => $persona->id,
                'cuil' => $datos['cuil'],
                'fecha_nacimiento' => $datos['fecha_nacimiento'],
                'domicilio_localidad' => $datos['domicilio_localidad'],
                'domicilio_calle' => $datos['domicilio_calle'],
                'domicilio_numero' => $datos['domicilio_numero'],
                'en_servicio' => false,
            ]);
        });

        return [self::IMPORTADA, 'importada'];
    }

    /**
     * @param  array<string, string|null>  $fila
     * @return array<string, string|null>
     */
    private function datosPersona(array $fila): array
    {
        return [
            'dni' => $this->normalizador->dni($fila['dni'] ?? null),
            'nombre' => $this->normalizador->nombre($fila['nombre'] ?? null, 'nombre'),
            'apellido' => $this->normalizador->nombre($fila['apellido'] ?? null, 'apellido'),
            'telefono' => $this->normalizador->telefono($fila['telefono'] ?? null),
        ];
    }

    /**
     * @param  array<string, string|null>  $fila
     * @return array<string, string|null>
     */
    private function datosConductor(array $fila): array
    {
        $persona = $this->datosPersona($fila);

        return $persona + [
            'cuil' => $this->normalizador->cuil($fila['cuil'] ?? null, $persona['dni']),
            'fecha_nacimiento' => $this->normalizador->fechaNacimiento($fila['fecha_nacimiento'] ?? null),
            'domicilio_localidad' => $this->normalizador->texto($fila['domicilio_localidad'] ?? null, 'localidad', 100),
            'domicilio_calle' => $this->normalizador->texto($fila['domicilio_calle'] ?? null, 'calle', 100),
            'domicilio_numero' => $this->normalizador->texto($fila['domicilio_numero'] ?? null, 'número de domicilio', 15),
        ];
    }
}
