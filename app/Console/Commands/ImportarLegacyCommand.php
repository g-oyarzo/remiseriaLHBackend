<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Legacy\FilaInvalida;
use App\Services\Legacy\ImportadorLegacy;
use App\Services\Legacy\NormalizadorLegacy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use SplFileObject;
use Throwable;

/**
 * Migración de datos del sistema anterior (clientes y conductores) desde un
 * CSV con fila de encabezados.
 *
 * Columnas (sin distinguir mayúsculas; espacios y guiones equivalen a "_"):
 * - clientes:    dni, nombre, apellido, telefono
 * - conductores: dni, nombre, apellido, telefono, cuil, fecha_nacimiento,
 *                domicilio_localidad, domicilio_calle, domicilio_numero
 *
 * Cada fila se valida, normaliza e importa en su propia transacción: una
 * fila inválida no afecta a las demás. Las filas con DNI o CUIL ya
 * existentes se omiten sin modificar los datos, por lo que la importación
 * puede repetirse. Los errores se registran por número de fila, con el DNI
 * enmascarado, en storage/logs/importacion-legacy-*.log.
 *
 * Un volcado SQL del sistema anterior debe exportarse primero a CSV por
 * tabla: no se interpretan scripts SQL arbitrarios.
 */
class ImportarLegacyCommand extends Command
{
    protected $signature = 'legacy:importar
        {archivo : Ruta al CSV exportado del sistema anterior}
        {--tipo=clientes : clientes | conductores}
        {--delimitador=, : Separador de columnas}
        {--codificacion=UTF-8 : Codificación del archivo (p. ej. Windows-1252)}
        {--desde-fila=2 : Primera fila de datos a procesar (para reanudar)}
        {--dry-run : Valida y reporta sin guardar nada}';

    protected $description = 'Importa clientes o conductores del sistema anterior desde un CSV.';

    private const COLUMNAS = [
        'clientes' => ['dni', 'nombre', 'apellido'],
        'conductores' => ['dni', 'nombre', 'apellido', 'cuil', 'fecha_nacimiento', 'domicilio_localidad', 'domicilio_calle', 'domicilio_numero'],
    ];

    public function handle(ImportadorLegacy $importador): int
    {
        $tipo = (string) $this->option('tipo');
        $archivo = (string) $this->argument('archivo');
        $simulacion = (bool) $this->option('dry-run');
        $desdeFila = max(2, (int) $this->option('desde-fila'));

        if (! array_key_exists($tipo, self::COLUMNAS)) {
            $this->error('--tipo debe ser "clientes" o "conductores".');

            return self::INVALID;
        }

        if (! is_file($archivo) || ! is_readable($archivo) || ! in_array(strtolower(pathinfo($archivo, PATHINFO_EXTENSION)), ['csv', 'txt'], true)) {
            $this->error('El archivo no existe, no se puede leer o no es .csv/.txt.');

            return self::INVALID;
        }

        $csv = new SplFileObject($archivo);
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $csv->setCsvControl((string) $this->option('delimitador'), '"', '\\');

        $encabezados = null;
        $log = $this->logger();
        $totales = ['procesadas' => 0, ImportadorLegacy::IMPORTADA => 0, ImportadorLegacy::OMITIDA => 0, 'errores' => 0];

        $log->info('Inicio de importación legacy.', ['tipo' => $tipo, 'simulacion' => $simulacion, 'desde_fila' => $desdeFila]);

        foreach ($csv as $indice => $valores) {
            $numeroFila = $indice + 1;
            $valores = array_map(fn ($v) => $this->convertir($v), (array) $valores);

            if ($encabezados === null) {
                $encabezados = array_map(fn ($c) => $this->normalizarEncabezado((string) $c), $valores);
                $faltantes = array_diff(self::COLUMNAS[$tipo], $encabezados);

                if ($faltantes !== []) {
                    $this->error('Faltan columnas obligatorias: '.implode(', ', $faltantes));

                    return self::INVALID;
                }

                continue;
            }

            if ($numeroFila < $desdeFila || $valores === [null] || $valores === ['']) {
                continue;
            }

            $totales['procesadas']++;
            $fila = array_combine($encabezados, array_pad(array_slice($valores, 0, count($encabezados)), count($encabezados), null));

            try {
                [$resultado, $detalle] = $importador->importar($tipo, $fila, $simulacion);
                $totales[$resultado]++;

                if ($resultado === ImportadorLegacy::OMITIDA) {
                    $log->notice("Fila {$numeroFila} omitida: {$detalle}", ['dni' => NormalizadorLegacy::enmascarar($fila['dni'] ?? null)]);
                }
            } catch (FilaInvalida $e) {
                $totales['errores']++;
                $log->warning("Fila {$numeroFila} no migrada: {$e->getMessage()}", ['dni' => NormalizadorLegacy::enmascarar($fila['dni'] ?? null)]);
            } catch (Throwable $e) {
                $totales['errores']++;
                // Sin el mensaje de la excepción: puede contener el SQL con datos.
                $log->error("Fila {$numeroFila} no migrada por un error inesperado (".class_basename($e).').', ['dni' => NormalizadorLegacy::enmascarar($fila['dni'] ?? null)]);
            }
        }

        $log->info('Fin de importación legacy.', $totales);

        $this->table(['Procesadas', $simulacion ? 'Válidas' : 'Importadas', 'Omitidas (existentes)', 'Con error'], [[
            $totales['procesadas'], $totales[ImportadorLegacy::IMPORTADA], $totales[ImportadorLegacy::OMITIDA], $totales['errores'],
        ]]);

        if ($totales['errores'] > 0) {
            $this->warn('Hubo filas con errores; el detalle está en '.$this->rutaLog().'. Las demás filas se procesaron normalmente.');
        }

        return self::SUCCESS;
    }

    private function convertir(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $codificacion = (string) $this->option('codificacion');
        $texto = strcasecmp($codificacion, 'UTF-8') === 0 ? (string) $valor : mb_convert_encoding((string) $valor, 'UTF-8', $codificacion);

        // Quita el BOM de UTF-8 si el archivo lo trae.
        return preg_replace('/^\xEF\xBB\xBF/', '', $texto);
    }

    private function normalizarEncabezado(string $columna): string
    {
        return trim((string) preg_replace('/[\s\-]+/', '_', mb_strtolower(trim($columna))), '_');
    }

    private function rutaLog(): string
    {
        return storage_path('logs/importacion-legacy-'.now()->format('Y-m-d').'.log');
    }

    private function logger(): LoggerInterface
    {
        return Log::build(['driver' => 'single', 'path' => $this->rutaLog()]);
    }
}
