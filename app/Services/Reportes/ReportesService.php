<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use App\Enums\EstadoPago;
use App\Enums\EstadoViaje;
use App\Models\Pago;
use App\Models\Viaje;
use App\Services\Viajes\ClienteTelefonicoService;
use App\Support\Dinero;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * CU 15 - Reporte estadístico sobre el historial de viajes.
 *
 * Todas las métricas se calculan con agregados SQL sobre el mismo conjunto
 * filtrado de viajes. La facturación suma solo pagos confirmados; como cada
 * viaje tiene a lo sumo un pago (unique en la base), no hay doble conteo.
 */
final class ReportesService
{
    /**
     * @return array<string, mixed>
     */
    public function estadisticas(FiltrosReporte $filtros): array
    {
        $porEstado = $this->viajes($filtros)
            ->toBase()
            ->selectRaw('estado, COUNT(*) AS cantidad')
            ->groupBy('estado')
            ->pluck('cantidad', 'estado')
            ->map(fn ($cantidad) => (int) $cantidad);

        $pagos = Pago::query()
            ->whereIn('viaje_id', $this->viajes($filtros)->select('id'))
            ->toBase()
            ->selectRaw('estado, COUNT(*) AS cantidad, COALESCE(SUM(monto), 0) AS total')
            ->groupBy('estado')
            ->get()
            ->keyBy('estado');

        $confirmados = $pagos->get(EstadoPago::Confirmado->value);
        $pendientes = $pagos->get(EstadoPago::Pendiente->value);
        // SUM() sobre DECIMAL(10,2) devuelve un decimal exacto como string.
        $facturacionCentavos = $confirmados ? Dinero::aCentavos((string) $confirmados->total) : 0;
        $pagosConfirmados = (int) ($confirmados->cantidad ?? 0);

        $distanciaKm = (float) $this->viajes($filtros)
            ->where('estado', EstadoViaje::Finalizado)
            ->sum('distancia_final_km');

        return [
            'filtros' => $filtros->toArray(),
            'total_viajes' => $porEstado->sum(),
            'por_estado' => collect(EstadoViaje::cases())
                ->mapWithKeys(fn (EstadoViaje $e) => [$e->value => $porEstado->get($e->value, 0)])
                ->all(),
            'viajes_finalizados' => $porEstado->get(EstadoViaje::Finalizado->value, 0),
            'viajes_cancelados' => $porEstado->get(EstadoViaje::Cancelado->value, 0),
            'facturacion' => Dinero::desdeCentavos($facturacionCentavos),
            'pagos_confirmados' => $pagosConfirmados,
            'cobros_pendientes' => Dinero::desdeCentavos($pendientes ? Dinero::aCentavos((string) $pendientes->total) : 0),
            'ticket_promedio' => $pagosConfirmados > 0
                ? Dinero::desdeCentavos(intdiv($facturacionCentavos + intdiv($pagosConfirmados, 2), $pagosConfirmados))
                : null,
            'distancia_total_km' => round($distanciaKm, 3),
        ];
    }

    /**
     * Detalle de viajes para exportar, acotado a $limite filas.
     *
     * @return Collection<int, Viaje>
     */
    public function detalle(FiltrosReporte $filtros, int $limite): Collection
    {
        return $this->viajes($filtros)
            ->with(['conductor.persona', 'cliente.persona', 'pago'])
            ->orderBy('fecha_viaje')
            ->orderBy('id')
            ->limit($limite)
            ->get();
    }

    public function contar(FiltrosReporte $filtros): int
    {
        return $this->viajes($filtros)->count();
    }

    /**
     * @return Builder<Viaje>
     */
    private function viajes(FiltrosReporte $filtros): Builder
    {
        return Viaje::query()
            ->whereBetween('fecha_viaje', [$filtros->desde, $filtros->hasta])
            ->when($filtros->estado, fn (Builder $q, EstadoViaje $estado) => $q->where('estado', $estado))
            ->when($filtros->conductorDni, function (Builder $q, string $dni): void {
                $dnis = array_unique([$dni, ClienteTelefonicoService::normalizarDni($dni)]);
                $q->whereHas('conductor.persona', fn (Builder $p) => $p->whereIn('dni', $dnis));
            });
    }
}
