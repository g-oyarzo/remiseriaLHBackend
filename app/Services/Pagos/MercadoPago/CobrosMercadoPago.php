<?php

declare(strict_types=1);

namespace App\Services\Pagos\MercadoPago;

use App\Enums\EstadoPago;
use App\Enums\EstadoViaje;
use App\Enums\MetodoPago;
use App\Models\Pago;
use App\Models\Viaje;
use App\Services\Pagos\PagoRechazado;
use App\Services\Pagos\RegistroDePagos;
use App\Support\Dinero;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cobros digitales con Mercado Pago (Checkout Pro).
 *
 * Flujo:
 * 1. iniciarCobro(): con el viaje finalizado, se crea (o reutiliza) un pago
 *    "pendiente" con proveedor "mercadopago" por el importe exacto del
 *    viaje y una preferencia de Checkout Pro con
 *    external_reference "viaje-{id}-pago-{id}". El enlace se muestra al
 *    pasajero (link o QR).
 * 2. Mercado Pago notifica por webhook (firma verificada) o el comando de
 *    conciliación encuentra el pago. En ambos casos el backend CONSULTA el
 *    pago en la API de Mercado Pago (nunca confía en el cuerpo de la
 *    notificación) y aplicar() valida referencia, moneda e importe exacto
 *    antes de confirmar.
 *
 * aplicar() es idempotente: la misma notificación (id + estado) se procesa
 * una sola vez. Las anomalías (importe distinto, un segundo pago aprobado
 * para un viaje ya cobrado) nunca modifican el pago: quedan en el historial
 * y en un log crítico para que la administración reintegre o revise.
 */
final class CobrosMercadoPago
{
    public const PROVEEDOR = 'mercadopago';

    /** Margen mínimo de vigencia para reutilizar una preferencia existente. */
    private const MARGEN_REUTILIZACION_SEGUNDOS = 120;

    public function __construct(private readonly MercadoPagoCliente $cliente) {}

    public static function habilitado(): bool
    {
        return filled(config('services.mercadopago.access_token'));
    }

    public static function referenciaExterna(Pago $pago): string
    {
        return "viaje-{$pago->viaje_id}-pago-{$pago->id}";
    }

    /**
     * @return array{0: Pago, 1: bool} el pago y si se generó una preferencia nueva
     *
     * @throws PagoRechazado
     * @throws MercadoPagoNoDisponible
     */
    public function iniciarCobro(int $viajeId, int $personaId): array
    {
        if (! self::habilitado()) {
            throw new MercadoPagoNoDisponible('Los cobros con Mercado Pago no están habilitados.');
        }

        // 1) Pago pendiente reservado para Mercado Pago (bajo lock).
        $pago = DB::transaction(function () use ($viajeId, $personaId): Pago {
            $viaje = Viaje::query()->lockForUpdate()->findOrFail($viajeId);

            if ($viaje->estado !== EstadoViaje::Finalizado) {
                throw new PagoRechazado('Solo se puede cobrar un viaje finalizado.');
            }

            $importe = RegistroDePagos::importeACobrar($viaje)
                ?? throw new PagoRechazado('El viaje no tiene un importe final calculado.');

            $pago = Pago::query()->where('viaje_id', $viaje->id)->lockForUpdate()->first();

            if ($pago !== null) {
                match (true) {
                    $pago->estado === EstadoPago::Confirmado => throw new PagoRechazado('El viaje ya tiene un pago confirmado.'),
                    $pago->estado === EstadoPago::Reintegrado => throw new PagoRechazado('El pago del viaje fue reintegrado; requiere revisión administrativa.'),
                    $pago->estado === EstadoPago::Pendiente && $pago->proveedor !== self::PROVEEDOR => throw new PagoRechazado('El viaje ya tiene un cobro registrado pendiente de conciliación.'),
                    default => null,
                };

                if ($this->preferenciaReutilizable($pago, $importe)) {
                    return $pago;
                }
            }

            $pago ??= new Pago(['viaje_id' => $viaje->id]);
            $nuevo = ! $pago->exists;

            $pago->fill([
                // Provisorio hasta conocer el medio real (se actualiza al aprobarse).
                'metodo_pago' => MetodoPago::Qr,
                'monto' => $importe,
                'referencia_externa' => null,
                'proveedor' => self::PROVEEDOR,
                'preferencia_id' => null,
                'enlace_pago' => null,
                'preferencia_expira_en' => null,
                'estado' => EstadoPago::Pendiente,
                'fecha_pago' => null,
                'registrado_por_persona_id' => $personaId,
                'conciliado_por_persona_id' => null,
                'conciliado_en' => null,
            ]);
            $pago->registrarEnHistorial($nuevo ? 'inicio_cobro_mercadopago' : 'reintento_cobro_mercadopago', $personaId);
            $pago->save();

            return $pago;
        });

        if ($pago->preferencia_id !== null) {
            return [$pago, false];
        }

        // 2) Preferencia en Mercado Pago, fuera de la transacción (no se
        //    retienen locks mientras se espera a un servicio externo).
        $expira = CarbonImmutable::now()->addMinutes(max(5, (int) config('services.mercadopago.preferencia_vigencia_minutos')));
        $preferencia = $this->cliente->crearPreferencia(
            $this->datosPreferencia($pago, $expira),
            (string) Str::uuid(),
        );

        // 3) Se guarda la preferencia si el pago sigue pendiente.
        $pago = DB::transaction(function () use ($pago, $preferencia, $expira): Pago {
            $pago = Pago::query()->lockForUpdate()->findOrFail($pago->id);

            if ($pago->estado === EstadoPago::Pendiente && $pago->proveedor === self::PROVEEDOR) {
                $pago->fill([
                    'preferencia_id' => $preferencia['id'],
                    'enlace_pago' => $preferencia['init_point'],
                    'preferencia_expira_en' => $expira,
                ]);
                $pago->save();
            }

            return $pago;
        });

        return [$pago, true];
    }

    /**
     * Consulta el pago en Mercado Pago y lo aplica.
     *
     * @throws MercadoPagoNoDisponible
     */
    public function procesarPago(string $pagoMercadoPagoId): string
    {
        return $this->aplicar($this->cliente->obtenerPago($pagoMercadoPagoId));
    }

    /**
     * Concilia un pago pendiente buscando sus operaciones en Mercado Pago
     * (red de seguridad si un webhook se perdió).
     *
     * @return list<string> resultado por cada operación encontrada
     *
     * @throws MercadoPagoNoDisponible
     */
    public function conciliar(Pago $pago): array
    {
        return array_map(
            fn (array $datos): string => $this->aplicar($datos),
            $this->cliente->buscarPagos(self::referenciaExterna($pago)),
        );
    }

    /**
     * Aplica los datos de un pago OBTENIDOS DE LA API de Mercado Pago.
     *
     * @param  array<string, mixed>  $mp
     * @return string confirmado | reintegrado | registrado | duplicado | requiere_revision | ignorado
     */
    public function aplicar(array $mp): string
    {
        $mpId = isset($mp['id']) && is_scalar($mp['id']) ? (string) $mp['id'] : null;
        $estadoMp = is_string($mp['status'] ?? null) ? $mp['status'] : null;
        $referencia = is_string($mp['external_reference'] ?? null) ? $mp['external_reference'] : '';

        if ($mpId === null || $estadoMp === null || ! preg_match('/^viaje-(\d+)-pago-(\d+)$/', $referencia, $m)) {
            Log::info('Notificación de Mercado Pago ajena a los cobros de viajes.', ['mp_payment_id' => $mpId]);

            return 'ignorado';
        }

        try {
            return DB::transaction(function () use ($mp, $mpId, $estadoMp, $m): string {
                $pago = Pago::query()->lockForUpdate()->find((int) $m[2]);

                if ($pago === null || $pago->viaje_id !== (int) $m[1]) {
                    Log::critical('Pago de Mercado Pago con una referencia que no corresponde a ningún pago del sistema.', [
                        'mp_payment_id' => $mpId, 'pago_id' => (int) $m[2], 'viaje_id' => (int) $m[1],
                    ]);

                    return 'requiere_revision';
                }

                $extra = [
                    'mp_payment_id' => $mpId,
                    'mp_status' => $estadoMp,
                    'mp_status_detail' => is_string($mp['status_detail'] ?? null) ? $mp['status_detail'] : null,
                ];

                // Idempotencia: cada (operación, estado) se aplica una sola vez.
                foreach ($pago->historial ?? [] as $entrada) {
                    if (($entrada['mp_payment_id'] ?? null) === $mpId && ($entrada['mp_status'] ?? null) === $estadoMp) {
                        return 'duplicado';
                    }
                }

                $resultado = match ($estadoMp) {
                    'approved' => $this->aprobar($pago, $mp, $mpId, $extra),
                    'refunded', 'charged_back' => $this->reintegrar($pago, $mpId, $extra),
                    default => $this->registrarNovedad($pago, $extra),
                };

                $pago->save();

                return $resultado;
            });
        } catch (UniqueConstraintViolationException) {
            Log::critical('La operación de Mercado Pago ya está asociada a otro pago.', ['mp_payment_id' => $mpId]);

            return 'requiere_revision';
        }
    }

    /**
     * @param  array<string, mixed>  $mp
     * @param  array<string, mixed>  $extra
     */
    private function aprobar(Pago $pago, array $mp, string $mpId, array $extra): string
    {
        if ($pago->estado !== EstadoPago::Pendiente || $pago->proveedor !== self::PROVEEDOR) {
            // Por ejemplo, el pasajero pagó con Mercado Pago después de que
            // se registró el cobro en efectivo: hay que reintegrarle.
            Log::critical('Mercado Pago aprobó un pago para un viaje que no tiene un cobro de Mercado Pago pendiente; requiere reintegro.', [
                'pago_id' => $pago->id, 'viaje_id' => $pago->viaje_id, 'mp_payment_id' => $mpId, 'estado_pago' => $pago->estado->value,
            ]);
            $pago->registrarEnHistorial('mercadopago_aprobado_sin_aplicar', null, $extra);

            return 'requiere_revision';
        }

        $moneda = $mp['currency_id'] ?? null;
        $importe = $mp['transaction_amount'] ?? null;
        $importeCoincide = (is_int($importe) || is_float($importe) || (is_string($importe) && is_numeric($importe)))
            && $this->centavos($importe) === Dinero::aCentavos((string) $pago->monto);

        if ($moneda !== 'ARS' || ! $importeCoincide) {
            Log::critical('Mercado Pago aprobó un pago con moneda o importe distintos al del viaje; no se confirma.', [
                'pago_id' => $pago->id, 'viaje_id' => $pago->viaje_id, 'mp_payment_id' => $mpId,
                'moneda' => is_string($moneda) ? $moneda : null, 'importe_esperado' => (string) $pago->monto,
            ]);
            $pago->registrarEnHistorial('mercadopago_importe_no_coincide', null, $extra + [
                'mp_importe' => is_scalar($importe) ? (string) $importe : null,
            ]);

            return 'requiere_revision';
        }

        $pago->fill([
            'metodo_pago' => self::metodo($mp['payment_type_id'] ?? null),
            'referencia_externa' => $mpId,
            'estado' => EstadoPago::Confirmado,
            'fecha_pago' => $this->fecha($mp['date_approved'] ?? null),
            'conciliado_en' => now(),
            'conciliado_por_persona_id' => null,
        ]);
        $pago->registrarEnHistorial('confirmado_por_mercadopago', null, $extra);

        return 'confirmado';
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function reintegrar(Pago $pago, string $mpId, array $extra): string
    {
        if ($pago->estado === EstadoPago::Confirmado && $pago->proveedor === self::PROVEEDOR && $pago->referencia_externa === $mpId) {
            $pago->estado = EstadoPago::Reintegrado;
            $pago->registrarEnHistorial('reintegrado_por_mercadopago', null, $extra);
            Log::warning('Mercado Pago informó el reintegro o contracargo de un cobro confirmado.', [
                'pago_id' => $pago->id, 'viaje_id' => $pago->viaje_id, 'mp_payment_id' => $mpId,
            ]);

            return 'reintegrado';
        }

        return $this->registrarNovedad($pago, $extra);
    }

    /**
     * Estados sin efecto sobre el pago (rechazado, cancelado, en proceso,
     * etc.): solo quedan en el historial. Un pago rechazado deja el cobro
     * pendiente para que el pasajero reintente con el mismo enlace.
     *
     * @param  array<string, mixed>  $extra
     */
    private function registrarNovedad(Pago $pago, array $extra): string
    {
        $pago->registrarEnHistorial('novedad_mercadopago', null, $extra);

        return 'registrado';
    }

    private function preferenciaReutilizable(Pago $pago, string $importe): bool
    {
        return $pago->estado === EstadoPago::Pendiente
            && $pago->proveedor === self::PROVEEDOR
            && $pago->preferencia_id !== null
            && $pago->preferencia_expira_en?->isAfter(now()->addSeconds(self::MARGEN_REUTILIZACION_SEGUNDOS))
            && Dinero::aCentavos((string) $pago->monto) === Dinero::aCentavos($importe);
    }

    /**
     * @return array<string, mixed>
     */
    private function datosPreferencia(Pago $pago, CarbonImmutable $expira): array
    {
        $datos = [
            'items' => [[
                'id' => "viaje-{$pago->viaje_id}",
                'title' => "Viaje #{$pago->viaje_id} - ".config('app.name'),
                'quantity' => 1,
                'currency_id' => 'ARS',
                'unit_price' => Dinero::aCentavos((string) $pago->monto) / 100,
            ]],
            'external_reference' => self::referenciaExterna($pago),
            // Aprobado o rechazado al instante, sin estados intermedios.
            'binary_mode' => true,
            'expires' => true,
            'expiration_date_from' => CarbonImmutable::now()->format('Y-m-d\TH:i:s.vP'),
            'expiration_date_to' => $expira->format('Y-m-d\TH:i:s.vP'),
            'metadata' => ['pago_id' => $pago->id, 'viaje_id' => $pago->viaje_id],
        ];

        $url = config('services.mercadopago.notification_url');

        if (filled($url)) {
            // source_news=webhooks: solo notificaciones Webhooks (firmadas), no IPN.
            $datos['notification_url'] = $url.(str_contains($url, '?') ? '&' : '?').'source_news=webhooks';
        }

        return $datos;
    }

    private static function metodo(mixed $tipo): MetodoPago
    {
        return match ($tipo) {
            'credit_card' => MetodoPago::TarjetaCredito,
            'debit_card', 'prepaid_card' => MetodoPago::TarjetaDebito,
            // account_money (dinero en cuenta), QR y otros medios digitales.
            default => MetodoPago::Qr,
        };
    }

    private function centavos(int|float|string $importe): int
    {
        return Dinero::aCentavos(is_string($importe) ? $importe : number_format((float) $importe, 2, '.', ''));
    }

    private function fecha(mixed $valor): CarbonImmutable
    {
        if (is_string($valor)) {
            try {
                // Mercado Pago informa con offset (-04:00); se guarda en la zona de la app.
                return CarbonImmutable::parse($valor)->setTimezone(config('app.timezone'));
            } catch (Throwable) {
                // Se usa el momento de procesamiento.
            }
        }

        return CarbonImmutable::now();
    }
}
