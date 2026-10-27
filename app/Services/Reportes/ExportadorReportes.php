<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use App\Models\Viaje;
use App\Support\Dinero;
use App\Support\Pdf\DocumentoPdf;
use App\Support\Xlsx\LibroXlsx;
use Illuminate\Support\Collection;

/**
 * CU 16 - Descargar reporte en PDF o Excel: el resumen estadístico más el
 * detalle de viajes. Del cliente se exporta solo nombre y apellido.
 */
final class ExportadorReportes
{
    private const ENCABEZADOS = ['Viaje', 'Fecha', 'Estado', 'Conductor', 'Cliente', 'Importe', 'Pago', 'Método'];

    /**
     * @param  array<string, mixed>  $estadisticas
     * @param  Collection<int, Viaje>  $viajes
     */
    public function pdf(array $estadisticas, Collection $viajes): string
    {
        $pdf = new DocumentoPdf('Reporte de viajes');
        $pdf->titulo('Remisería LH - Reporte de viajes');

        foreach ($this->resumen($estadisticas) as [$etiqueta, $valor]) {
            $pdf->par($etiqueta, (string) $valor);
        }

        $pdf->subtitulo('Detalle de viajes');
        $anchos = [0.7, 1.3, 1, 1.6, 1.6, 1, 1, 1.2];
        $pdf->fila(self::ENCABEZADOS, $anchos, negrita: true)->separador();

        foreach ($viajes as $viaje) {
            $pdf->fila(array_map('strval', $this->fila($viaje)), $anchos);
        }

        if ($viajes->isEmpty()) {
            $pdf->texto('No se encontraron viajes registrados bajo los parámetros de búsqueda especificados.');
        }

        return $pdf->generar();
    }

    /**
     * @param  array<string, mixed>  $estadisticas
     * @param  Collection<int, Viaje>  $viajes
     */
    public function excel(array $estadisticas, Collection $viajes): string
    {
        $resumen = $this->resumen($estadisticas);

        $detalle = [self::ENCABEZADOS];
        foreach ($viajes as $viaje) {
            $detalle[] = $this->fila($viaje, numerico: true);
        }

        return (new LibroXlsx)
            ->hoja('Resumen', $resumen)
            ->hoja('Viajes', $detalle)
            ->generar();
    }

    /**
     * @param  array<string, mixed>  $e
     * @return list<array{0: string, 1: string|int|float}>
     */
    private function resumen(array $e): array
    {
        $f = $e['filtros'];
        $filas = [
            ['Período', "{$f['fecha_desde']} a {$f['fecha_hasta']}"],
            ['DNI del conductor', $f['conductor_dni'] ?? 'Todos'],
            ['Estado', $f['estado'] ?? 'Todos'],
            ['Total de viajes', $e['total_viajes']],
            ['Viajes finalizados', $e['viajes_finalizados']],
            ['Viajes cancelados', $e['viajes_cancelados']],
            ['Facturación (pagos confirmados)', $e['facturacion']],
            ['Cobros pendientes de conciliación', $e['cobros_pendientes']],
            ['Ticket promedio', $e['ticket_promedio'] ?? '-'],
            ['Distancia total (km)', $e['distancia_total_km']],
        ];

        return $filas;
    }

    /**
     * @return list<string|int|float|null>
     */
    private function fila(Viaje $viaje, bool $numerico = false): array
    {
        $importe = $viaje->costo_final ?? $viaje->costo;

        return [
            $viaje->id,
            $viaje->fecha_viaje->format('d/m/Y H:i'),
            $viaje->estado->label(),
            $this->nombre($viaje->conductor?->persona),
            $this->nombre($viaje->cliente?->persona),
            $importe === null ? null : ($numerico ? Dinero::aCentavos((string) $importe) / 100 : (string) $importe),
            $viaje->pago?->estado->label() ?? '-',
            $viaje->pago?->metodo_pago->label() ?? '-',
        ];
    }

    private function nombre(?object $persona): string
    {
        return $persona !== null ? trim("{$persona->nombre} {$persona->apellido}") : '-';
    }
}
