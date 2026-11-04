<?php

declare(strict_types=1);

namespace App\Services\Comprobantes;

use App\Enums\MetodoCotizacion;
use App\Models\Pago;
use App\Models\Viaje;
use App\Services\Cotizacion\CotizadorService;
use App\Support\Dinero;
use App\Support\Pdf\DocumentoPdf;

/**
 * Comprobante PDF de un viaje pagado (CU 07 paso 4, CU 17).
 *
 * Usa los datos históricos guardados en el viaje y en el pago (tarifa
 * aplicada, distancia cotizada, importe), no la tarifa vigente. Del cliente y del
 * conductor muestra solo nombre y apellido: el comprobante puede circular,
 * así que no incluye DNI, teléfono ni domicilio. La referencia de la
 * operación se muestra parcialmente enmascarada.
 *
 * No es una factura electrónica: el sistema no está integrado con AFIP.
 */
final class ComprobanteViaje
{
    public function generar(Viaje $viaje): string
    {
        $viaje->loadMissing(['cliente.persona', 'conductor.persona', 'vehiculo.marca', 'pago']);
        /** @var Pago $pago */
        $pago = $viaje->pago;

        $pdf = new DocumentoPdf("Comprobante de viaje {$viaje->id}");

        $pdf->titulo('Remisería LH - Comprobante de viaje')
            ->texto('Comprobante interno del servicio. No válido como factura.', tamano: 8)
            ->espacio(6)
            ->par('Comprobante N°', sprintf('%08d', $pago->id))
            ->par('Emitido', now()->format('d/m/Y H:i'))
            ->par('Estado del pago', ucfirst($pago->estado->label()))
            ->separador();

        $pdf->subtitulo('Viaje')
            ->par('Viaje N°', (string) $viaje->id)
            ->par('Fecha', $viaje->fecha_viaje->format('d/m/Y H:i'))
            ->par('Origen', $this->direccion($viaje->origen_calle, $viaje->origen_numero, $viaje->origen_localidad, $viaje->origen?->toArray()))
            ->par('Destino', $this->direccion($viaje->destino_calle, $viaje->destino_numero, $viaje->destino_localidad, $viaje->destino?->toArray()))
            ->par('Distancia', $viaje->distancia_final_km !== null ? number_format($viaje->distancia_final_km, 2, ',', '.').' km' : '-')
            ->par('Duración', $viaje->duracion_final_min !== null ? "{$viaje->duracion_final_min} min" : '-');

        $pdf->subtitulo('Partes')
            ->par('Pasajero', $this->nombre($viaje->cliente?->persona))
            ->par('Conductor', $this->nombre($viaje->conductor?->persona))
            ->par('Vehículo', $viaje->vehiculo !== null
                ? trim(($viaje->vehiculo->marca?->nombre ?? '').' '.$viaje->vehiculo->modelo).' - '.$viaje->vehiculo->patente
                : '-');

        $pdf->subtitulo('Detalle del importe');
        foreach ($this->desglose($viaje, $pago) as [$concepto, $importe]) {
            $pdf->par($concepto, $importe);
        }

        $recargo = $viaje->monto_base_cotizado !== null && $viaje->costo !== null
            ? Dinero::aCentavos((string) $viaje->costo) - Dinero::aCentavos((string) $viaje->monto_base_cotizado)
            : 0;
        if ($recargo > 0) {
            $pdf->par("Recargo dinámico ({$viaje->recargo_dinamico_pct} %)", '$ '.$this->pesos(Dinero::desdeCentavos($recargo)));
        }

        $pdf->separador()
            ->par('Total', '$ '.$this->pesos((string) $pago->monto), tamano: 12)
            ->par('Método de pago', $pago->metodo_pago->label())
            ->par('Referencia', $this->enmascarar($pago->referencia_externa))
            ->par('Fecha de cobro', $pago->fecha_pago?->format('d/m/Y H:i') ?? '-');

        return $pdf->generar();
    }

    /**
     * Desglose de la cotización con la que se cobró el viaje (precio cerrado).
     *
     * @return list<array{0: string, 1: string}>
     */
    private function desglose(Viaje $viaje, Pago $pago): array
    {
        if ($viaje->metodo_cotizacion === MetodoCotizacion::Zona || $viaje->costo_final_origen === 'zona') {
            return [['Tarifa fija por zona', '$ '.$this->pesos((string) $pago->monto)]];
        }

        $base = $viaje->precio_base_aplicado;
        $porKm = $viaje->precio_por_km_aplicado;

        if ($base === null || $porKm === null || $viaje->distancia_estimada_km === null) {
            return [['Importe del viaje', '$ '.$this->pesos((string) $pago->monto)]];
        }

        $metros = (int) round(((float) $viaje->distancia_estimada_km) * 1000);
        $porDistancia = Dinero::aCentavos(CotizadorService::montoParaDistancia((string) $base, (string) $porKm, $metros))
            - Dinero::aCentavos((string) $base);

        return [
            ['Tarifa base', '$ '.$this->pesos((string) $base)],
            [
                sprintf('Distancia cotizada (%s km x $ %s)', number_format($metros / 1000, 2, ',', '.'), $this->pesos((string) $porKm)),
                '$ '.$this->pesos(Dinero::desdeCentavos($porDistancia)),
            ],
        ];
    }

    /**
     * @param  array{lat: float, lng: float}|null  $coordenada
     */
    private function direccion(?string $calle, ?string $numero, ?string $localidad, ?array $coordenada): string
    {
        $texto = trim(implode(' ', array_filter([$calle, $numero])));
        $texto = trim(implode(', ', array_filter([$texto, $localidad])));

        if ($texto !== '') {
            return $texto;
        }

        return $coordenada !== null ? sprintf('%.5F, %.5F', $coordenada['lat'], $coordenada['lng']) : '-';
    }

    private function nombre(?object $persona): string
    {
        return $persona !== null ? trim("{$persona->nombre} {$persona->apellido}") : '-';
    }

    private function pesos(string $monto): string
    {
        return number_format(Dinero::aCentavos($monto) / 100, 2, ',', '.');
    }

    private function enmascarar(?string $referencia): string
    {
        if ($referencia === null || $referencia === '') {
            return '-';
        }

        return str_repeat('*', max(0, strlen($referencia) - 4)).substr($referencia, -4);
    }
}
