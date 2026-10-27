<?php

declare(strict_types=1);

namespace App\Services\Pagos;

use App\Enums\EstadoPago;
use App\Enums\EstadoViaje;
use App\Enums\MetodoPago;
use App\Models\Pago;
use App\Models\Viaje;
use App\Support\Dinero;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * CU 07 - Registrar pago, y conciliación administrativa de cobros digitales.
 *
 * Reglas:
 * - Solo viajes finalizados, por el importe exacto que calculó el backend
 *   (costo_final); nunca se acepta un monto distinto enviado por el cliente.
 * - Efectivo: el conductor declara haber recibido el dinero; es un registro
 *   interno y queda confirmado.
 * - QR y tarjetas: no hay integración con un proveedor de pagos, así que el
 *   backend no puede verificar el cobro. El pago queda "pendiente" con la
 *   referencia de la operación hasta que un administrador lo concilia
 *   contra el proveedor (confirmado o rechazado). Nunca se marca como
 *   exitoso un cobro digital no verificado.
 * - Un viaje tiene un único pago (unique en la base). Reenviar el mismo
 *   pago es idempotente; uno distinto se rechaza. Si un cobro digital se
 *   rechazó, puede volver a registrarse (queda la traza en el historial).
 */
final class RegistroDePagos
{
    /**
     * Importe a cobrar de un viaje: el costo final calculado al finalizar o,
     * para viajes finalizados antes de existir ese cálculo, lo cotizado.
     */
    public static function importeACobrar(Viaje $viaje): ?string
    {
        $importe = $viaje->costo_final ?? $viaje->costo;

        return $importe !== null ? Dinero::desdeCentavos(Dinero::aCentavos((string) $importe)) : null;
    }

    /**
     * @return array{0: Pago, 1: bool} el pago y si fue creado/actualizado (false = reintento idéntico)
     *
     * @throws PagoRechazado
     */
    public function registrar(int $viajeId, MetodoPago $metodo, string $monto, ?string $referencia, int $registradoPor): array
    {
        try {
            return DB::transaction(function () use ($viajeId, $metodo, $monto, $referencia, $registradoPor): array {
                $viaje = Viaje::query()->lockForUpdate()->findOrFail($viajeId);

                if ($viaje->estado !== EstadoViaje::Finalizado) {
                    throw new PagoRechazado('Solo se puede registrar un pago para viajes finalizados.');
                }

                $importe = self::importeACobrar($viaje)
                    ?? throw new PagoRechazado('El viaje no tiene un importe final calculado.');

                if (Dinero::aCentavos($monto) !== Dinero::aCentavos($importe)) {
                    throw new PagoRechazado(
                        'El monto no coincide con el importe del viaje.',
                        Response::HTTP_UNPROCESSABLE_ENTITY,
                        ['importe_esperado' => $importe],
                    );
                }

                $existente = Pago::query()->where('viaje_id', $viaje->id)->lockForUpdate()->first();

                if ($existente !== null && $existente->estado !== EstadoPago::Rechazado) {
                    if ($this->esElMismoPago($existente, $metodo, $importe, $referencia)) {
                        return [$existente, false];
                    }

                    throw new PagoRechazado('El viaje ya tiene un pago registrado.');
                }

                $pago = $existente ?? new Pago(['viaje_id' => $viaje->id]);
                $confirmado = $metodo === MetodoPago::Efectivo;

                $pago->fill([
                    'metodo_pago' => $metodo,
                    'monto' => $importe,
                    'referencia_externa' => $referencia,
                    'estado' => $confirmado ? EstadoPago::Confirmado : EstadoPago::Pendiente,
                    'fecha_pago' => $confirmado ? now() : null,
                    'registrado_por_persona_id' => $registradoPor,
                    'conciliado_por_persona_id' => null,
                    'conciliado_en' => null,
                ]);
                $pago->registrarEnHistorial($existente !== null ? 'reintento' : 'registro', $registradoPor);
                $pago->save();

                return [$pago, true];
            });
        } catch (UniqueConstraintViolationException) {
            // unique(metodo_pago, referencia_externa): el comprobante ya se
            // usó para otro viaje. (Un unique(viaje_id) en carrera también
            // cae acá, pero el lockForUpdate sobre el viaje lo previene.)
            throw new PagoRechazado('La referencia de la operación ya fue utilizada en otro pago.');
        }
    }

    /**
     * Confirma o rechaza un cobro digital pendiente tras verificarlo con el
     * proveedor (acción administrativa).
     *
     * @throws PagoRechazado
     */
    public function conciliar(int $pagoId, EstadoPago $resultado, int $administradorId, ?string $observacion): Pago
    {
        return DB::transaction(function () use ($pagoId, $resultado, $administradorId, $observacion): Pago {
            $pago = Pago::query()->lockForUpdate()->findOrFail($pagoId);

            if ($pago->estado !== EstadoPago::Pendiente) {
                throw new PagoRechazado('Solo se pueden conciliar pagos pendientes.');
            }

            $pago->fill([
                'estado' => $resultado,
                'fecha_pago' => $resultado === EstadoPago::Confirmado ? now() : null,
                'conciliado_por_persona_id' => $administradorId,
                'conciliado_en' => now(),
                'observacion' => $observacion,
            ]);
            $pago->registrarEnHistorial('conciliacion', $administradorId, ['observacion' => $observacion]);
            $pago->save();

            return $pago;
        });
    }

    private function esElMismoPago(Pago $pago, MetodoPago $metodo, string $importe, ?string $referencia): bool
    {
        return $pago->metodo_pago === $metodo
            && Dinero::aCentavos((string) $pago->monto) === Dinero::aCentavos($importe)
            && $pago->referencia_externa === $referencia;
    }
}
