<?php

declare(strict_types=1);

namespace App\Services\Pagos\MercadoPago;

use App\Enums\EstadoPago;
use App\Enums\EstadoViaje;
use App\Enums\MetodoPago;
use App\Events\PagoActualizado;
use App\Jobs\ReintegrarOperacionMercadoPagoJob;
use App\Jobs\VencerPreferenciaMercadoPagoJob;
use App\Models\MedioPagoGuardado;
use App\Models\Pago;
use App\Models\Viaje;
use App\Services\Pagos\PagoRechazado;
use App\Services\Pagos\RegistroDePagos;
use App\Support\Dinero;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Cobros digitales con Mercado Pago.
 *
 * Modalidades (todas usan el mismo registro "pago" del viaje, con proveedor
 * "mercadopago" y external_reference "viaje-{id}-pago-{id}"):
 *
 * 1. Enlace de pago (Checkout Pro): iniciarCobro() crea o reutiliza una
 *    preferencia y el pasajero paga desde el link o QR.
 * 2. Tarjeta guardada (CIT): cobrarConTarjeta() crea un pago con un token
 *    que el frontend genera con el card_id y el CVV que ingresa el
 *    pasajero (Mercado Pago no guarda el CVV).
 * 3. Cobro automático (MIT, sin CVV): cobrarAutomaticamente() crea una
 *    orden con el perfil de pago autorizado por el pasajero. Requiere el
 *    producto "Pagos automáticos", habilitado por el equipo comercial de
 *    Mercado Pago (MERCADOPAGO_PAGOS_AUTOMATICOS=true).
 *
 * En todos los casos la confirmación sale de CONSULTAR la API (nunca del
 * cuerpo de un webhook ni de la respuesta de creación) y aplicar() valida
 * referencia, moneda e importe exacto antes de confirmar. aplicar() es
 * idempotente: cada (operación, estado) se procesa una sola vez.
 *
 * Las anomalías (importe distinto, un segundo pago aprobado para un viaje
 * ya cobrado o cobrado por otro medio) nunca modifican el pago: quedan en
 * el historial y en un log crítico. Un cobro aprobado que no se aplicó se
 * puede reintegrar (automáticamente con MERCADOPAGO_REINTEGRO_AUTOMATICO, o
 * por un administrador).
 */
final class CobrosMercadoPago
{
    public const PROVEEDOR = 'mercadopago';

    /** Margen mínimo de vigencia para reutilizar una preferencia existente. */
    private const MARGEN_REUTILIZACION_SEGUNDOS = 120;

    /** Acciones del historial que corresponden a operaciones aprobadas no aplicadas. */
    private const ACCIONES_REINTEGRABLES = ['mercadopago_aprobado_sin_aplicar', 'mercadopago_importe_no_coincide'];

    public function __construct(private readonly MercadoPagoCliente $cliente) {}

    public static function habilitado(): bool
    {
        return filled(config('services.mercadopago.access_token'));
    }

    public static function pagosAutomaticosHabilitados(): bool
    {
        return self::habilitado() && (bool) config('services.mercadopago.pagos_automaticos');
    }

    public static function referenciaExterna(Pago $pago): string
    {
        return "viaje-{$pago->viaje_id}-pago-{$pago->id}";
    }

    // ------------------------------------------------------------------
    // 1. Enlace de pago (Checkout Pro)
    // ------------------------------------------------------------------

    /**
     * @return array{0: Pago, 1: bool} el pago y si se generó una preferencia nueva
     *
     * @throws PagoRechazado
     * @throws MercadoPagoNoDisponible
     */
    public function iniciarCobro(int $viajeId, int $personaId): array
    {
        $this->exigirHabilitado();

        $pago = $this->reservar($viajeId, $personaId, MetodoPago::Qr, 'enlace');

        if ($pago->preferencia_id !== null) {
            return [$pago, false];
        }

        // Preferencia en Mercado Pago, fuera de la transacción (no se
        // retienen locks mientras se espera a un servicio externo).
        $expira = CarbonImmutable::now()->addMinutes(max(5, (int) config('services.mercadopago.preferencia_vigencia_minutos')));
        $preferencia = $this->cliente->crearPreferencia(
            $this->datosPreferencia($pago, $expira),
            (string) Str::uuid(),
        );

        // Se guarda la preferencia si el pago sigue pendiente; si no (por
        // ejemplo, se registró en efectivo mientras tanto), se la vence.
        $guardada = DB::transaction(function () use ($pago, $preferencia, $expira): Pago {
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

        if ($guardada->preferencia_id !== $preferencia['id']) {
            VencerPreferenciaMercadoPagoJob::dispatch($preferencia['id']);
        }

        return [$guardada, true];
    }

    // ------------------------------------------------------------------
    // 2. Tarjeta guardada (CIT, con CVV)
    // ------------------------------------------------------------------

    /**
     * Cobra el viaje con una tarjeta guardada del pasajero. $token es de un
     * solo uso y lo genera MercadoPago.js con el card_id y el CVV.
     *
     * @return array{pago: Pago, resultado: string, mp_status: ?string, mp_status_detail: ?string}
     *
     * @throws PagoRechazado
     * @throws MercadoPagoNoDisponible
     */
    public function cobrarConTarjeta(int $viajeId, int $personaId, MedioPagoGuardado $medio, string $token): array
    {
        $this->exigirHabilitado();

        if ($medio->mp_card_id === null) {
            throw new PagoRechazado('Este medio de pago solo admite cobros automáticos.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->conCandado($viajeId, function () use ($viajeId, $personaId, $medio, $token): array {
            $pago = $this->reservar($viajeId, $personaId, self::metodoDeTarjeta($medio->tipo), 'tarjeta', ['medio_pago_id' => $medio->id]);

            try {
                $creado = $this->cliente->crearPago(
                    $this->datosPagoConTarjeta($pago, $medio, $token),
                    'pago-'.$pago->id.'-tarjeta-'.hash('sha256', $token),
                );
            } catch (MercadoPagoNoDisponible $e) {
                if ($e->getCode() >= 400 && $e->getCode() < 500) {
                    // Token vencido o usado, datos de tarjeta inválidos, etc.
                    throw new PagoRechazado('Mercado Pago rechazó los datos de la tarjeta. Volvé a ingresar el código de seguridad.', Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                throw $e;
            }

            // Fuente de verdad: se consulta el pago recién creado.
            $mp = $this->cliente->obtenerPago((string) $creado['id']);
            $resultado = $this->aplicar($mp);

            return [
                'pago' => $pago->fresh(),
                'resultado' => $resultado,
                'mp_status' => is_string($mp['status'] ?? null) ? $mp['status'] : null,
                'mp_status_detail' => is_string($mp['status_detail'] ?? null) ? $mp['status_detail'] : null,
            ];
        });
    }

    // ------------------------------------------------------------------
    // 3. Cobro automático (MIT, sin CVV)
    // ------------------------------------------------------------------

    /**
     * Intenta cobrar automáticamente un viaje finalizado con el medio
     * predeterminado del pasajero, si este autorizó cobros automáticos y el
     * viaje se pidió con tarjeta. Se intenta una sola vez por viaje: si
     * falla, el pago queda pendiente para cobrarlo por otro medio.
     *
     * @return string confirmado | registrado | requiere_revision | no_aplica | ya_intentado
     *
     * @throws MercadoPagoNoDisponible
     */
    public function cobrarAutomaticamente(int $viajeId): string
    {
        if (! self::pagosAutomaticosHabilitados()) {
            return 'no_aplica';
        }

        $viaje = Viaje::query()->find($viajeId);

        if ($viaje === null || $viaje->estado !== EstadoViaje::Finalizado
            || ! in_array($viaje->metodo_pago, [MetodoPago::TarjetaCredito, MetodoPago::TarjetaDebito], true)) {
            return 'no_aplica';
        }

        $medio = MedioPagoGuardado::query()->activos()
            ->where('cliente_id', $viaje->cliente_id)
            ->where('predeterminado', true)
            ->where('cobro_automatico', true)
            ->whereNotNull('mp_payment_profile_id')
            ->first();

        if ($medio === null) {
            return 'no_aplica';
        }

        try {
            return $this->conCandado($viajeId, function () use ($viaje, $medio): string {
                // Dentro del candado (SEC-15): dos jobs simultáneos no pueden
                // pasar ambos este control.
                $existente = Pago::query()->where('viaje_id', $viaje->id)->first();

                if ($existente !== null && collect($existente->historial ?? [])->contains('accion', 'inicio_cobro_automatico')) {
                    return 'ya_intentado';
                }

                $pago = $this->reservar($viaje->id, (int) $viaje->cliente_id, self::metodoDeTarjeta($medio->tipo), 'automatico', ['medio_pago_id' => $medio->id]);

                $orden = $this->cliente->crearOrden($this->datosOrdenAutomatica($pago, $medio), 'cobro-automatico-pago-'.$pago->id);

                $pago = DB::transaction(function () use ($pago, $orden): Pago {
                    $pago = Pago::query()->lockForUpdate()->findOrFail($pago->id);

                    if ($pago->estado === EstadoPago::Pendiente && $pago->proveedor === self::PROVEEDOR) {
                        $pago->mp_orden_id = $orden['id'];
                        $pago->save();
                    }

                    return $pago;
                });

                $resultado = $this->aplicarOrden($this->cliente->obtenerOrden($orden['id']));

                if ($resultado !== 'confirmado') {
                    // El estado sigue "pendiente": se avisa igual a las apps
                    // para que ofrezcan otro medio de pago.
                    PagoActualizado::dispatch($pago->viaje_id, $pago->id, $pago->fresh()->estado);
                }

                return $resultado;
            });
        } catch (PagoRechazado) {
            // Ya cobrado o con otro cobro en curso: no corresponde.
            return 'no_aplica';
        }
    }

    // ------------------------------------------------------------------
    // Confirmación (webhooks y conciliación)
    // ------------------------------------------------------------------

    /**
     * Consulta un pago en Mercado Pago y lo aplica.
     *
     * @throws MercadoPagoNoDisponible
     */
    public function procesarPago(string $pagoMercadoPagoId): string
    {
        return $this->aplicar($this->cliente->obtenerPago($pagoMercadoPagoId));
    }

    /**
     * Consulta una orden (Orders API) y la aplica.
     *
     * @throws MercadoPagoNoDisponible
     */
    public function procesarOrden(string $ordenId): string
    {
        return $this->aplicarOrden($this->cliente->obtenerOrden($ordenId));
    }

    /**
     * Concilia un pago pendiente consultando sus operaciones en Mercado Pago
     * (red de seguridad si un webhook se perdió).
     *
     * @return list<string> resultado por cada operación encontrada
     *
     * @throws MercadoPagoNoDisponible
     */
    public function conciliar(Pago $pago): array
    {
        $resultados = array_map(
            fn (array $datos): string => $this->aplicar($datos),
            $this->cliente->buscarPagos(self::referenciaExterna($pago)),
        );

        if ($pago->mp_orden_id !== null) {
            $resultados[] = $this->procesarOrden($pago->mp_orden_id);
        }

        return $resultados;
    }

    /**
     * Traduce una orden de la Orders API al formato de un pago y la aplica
     * con las mismas validaciones.
     *
     * @param  array<string, mixed>  $orden
     */
    public function aplicarOrden(array $orden): string
    {
        $pagoMp = $orden['transactions']['payments'][0] ?? [];

        return $this->aplicar([
            'id' => $orden['id'] ?? null,
            'status' => match ($orden['status'] ?? null) {
                'processed' => 'approved',
                'refunded' => 'refunded',
                'charged_back' => 'charged_back',
                'failed' => 'rejected',
                'canceled', 'cancelled', 'expired' => 'cancelled',
                null => null,
                default => 'in_process',
            },
            'status_detail' => $orden['status_detail'] ?? null,
            'external_reference' => $orden['external_reference'] ?? null,
            // Las órdenes se crean en la moneda de la cuenta (ARS); si la
            // respuesta informa una moneda, se valida.
            'currency_id' => $orden['currency'] ?? 'ARS',
            'transaction_amount' => $orden['total_amount'] ?? null,
            'payment_type_id' => is_array($pagoMp) ? ($pagoMp['payment_method']['type'] ?? null) : null,
            'date_approved' => $orden['last_updated_date'] ?? null,
        ], 'order');
    }

    /**
     * Aplica los datos de una operación OBTENIDOS DE LA API de Mercado Pago.
     *
     * @param  array<string, mixed>  $mp
     * @param  'payment'|'order'  $tipo
     * @return string confirmado | reintegrado | registrado | duplicado | requiere_revision | ignorado
     */
    public function aplicar(array $mp, string $tipo = 'payment'): string
    {
        $mpId = isset($mp['id']) && is_scalar($mp['id']) ? (string) $mp['id'] : null;
        $estadoMp = is_string($mp['status'] ?? null) ? $mp['status'] : null;
        $referencia = is_string($mp['external_reference'] ?? null) ? $mp['external_reference'] : '';

        if ($mpId === null || $estadoMp === null || ! preg_match('/^viaje-(\d+)-pago-(\d+)$/', $referencia, $m)) {
            Log::info('Notificación de Mercado Pago ajena a los cobros de viajes.', ['mp_id' => $mpId, 'tipo' => $tipo]);

            return 'ignorado';
        }

        try {
            return DB::transaction(function () use ($mp, $mpId, $estadoMp, $m, $tipo): string {
                $pago = Pago::query()->lockForUpdate()->find((int) $m[2]);

                if ($pago === null || $pago->viaje_id !== (int) $m[1]
                    || ($tipo === 'order' && $pago->mp_orden_id !== null && $pago->mp_orden_id !== $mpId)) {
                    Log::critical('Operación de Mercado Pago con una referencia que no corresponde a ningún pago del sistema.', [
                        'mp_id' => $mpId, 'tipo' => $tipo, 'pago_id' => (int) $m[2], 'viaje_id' => (int) $m[1],
                    ]);

                    return 'requiere_revision';
                }

                $extra = [
                    'mp_payment_id' => $mpId,
                    'mp_tipo' => $tipo,
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
            Log::critical('La operación de Mercado Pago ya está asociada a otro pago.', ['mp_id' => $mpId]);

            return 'requiere_revision';
        }
    }

    // ------------------------------------------------------------------
    // Reintegros de operaciones no aplicadas
    // ------------------------------------------------------------------

    /**
     * Pide a Mercado Pago el reintegro total de una operación aprobada que
     * NO se aplicó al pago (el pasajero pagó dos veces, o pagó el enlace
     * después de que se registró otro medio). Nunca reintegra la operación
     * que confirmó el pago. Idempotente.
     *
     * @return string solicitado | ya_solicitado
     *
     * @throws PagoRechazado
     * @throws MercadoPagoNoDisponible
     */
    public function reintegrarOperacionNoAplicada(int $pagoId, string $mpId, ?int $personaId): string
    {
        $this->exigirHabilitado();

        $pago = Pago::query()->findOrFail($pagoId);
        $historial = collect($pago->historial ?? []);

        $operacion = $historial->first(fn (array $e): bool => in_array($e['accion'] ?? null, self::ACCIONES_REINTEGRABLES, true)
            && ($e['mp_payment_id'] ?? null) === $mpId);

        if ($operacion === null || ($pago->estado === EstadoPago::Confirmado && $pago->referencia_externa === $mpId && $pago->proveedor === self::PROVEEDOR)) {
            throw new PagoRechazado('La operación no figura como un cobro de Mercado Pago no aplicado a este pago.');
        }

        if ($historial->contains(fn (array $e): bool => ($e['accion'] ?? null) === 'reintegro_solicitado' && ($e['mp_payment_id'] ?? null) === $mpId)) {
            return 'ya_solicitado';
        }

        $clave = "reintegro-{$mpId}";
        $respuesta = ($operacion['mp_tipo'] ?? 'payment') === 'order'
            ? $this->cliente->reintegrarOrden($mpId, $clave)
            : $this->cliente->reintegrarPago($mpId, $clave);

        DB::transaction(function () use ($pagoId, $mpId, $personaId, $respuesta, $operacion): void {
            $pago = Pago::query()->lockForUpdate()->findOrFail($pagoId);
            $pago->registrarEnHistorial('reintegro_solicitado', $personaId, [
                'mp_payment_id' => $mpId,
                'mp_tipo' => $operacion['mp_tipo'] ?? 'payment',
                'mp_reintegro_id' => isset($respuesta['id']) && is_scalar($respuesta['id']) ? (string) $respuesta['id'] : null,
            ]);
            $pago->save();
        });

        Log::warning('Se solicitó el reintegro de una operación de Mercado Pago no aplicada.', [
            'pago_id' => $pagoId, 'mp_id' => $mpId, 'persona_id' => $personaId,
        ]);

        return 'solicitado';
    }

    /**
     * Vence en Mercado Pago la preferencia que reemplazó otro medio de pago.
     *
     * @throws MercadoPagoNoDisponible
     */
    public function vencerPreferencia(string $preferenciaId): void
    {
        $this->exigirHabilitado();
        $this->cliente->vencerPreferencia($preferenciaId);
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    /**
     * Crea o reutiliza el pago pendiente de Mercado Pago del viaje, bajo lock.
     *
     * Si el pago tenía un enlace de pago vigente y se cobra por otra vía (o
     * con otro importe), ese enlace se vence en Mercado Pago para evitar un
     * doble cobro.
     *
     * @param  'enlace'|'tarjeta'|'automatico'  $modalidad
     * @param  array<string, mixed>  $extraHistorial
     *
     * @throws PagoRechazado
     */
    private function reservar(int $viajeId, int $personaId, MetodoPago $metodo, string $modalidad, array $extraHistorial = []): Pago
    {
        $preferenciaAVencer = null;

        $pago = DB::transaction(function () use ($viajeId, $personaId, $metodo, $modalidad, $extraHistorial, &$preferenciaAVencer): Pago {
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

                if ($modalidad === 'enlace' && $this->preferenciaReutilizable($pago, $importe)) {
                    return $pago;
                }

                if ($pago->preferencia_id !== null && $pago->preferencia_expira_en?->isFuture()) {
                    $preferenciaAVencer = $pago->preferencia_id;
                }
            }

            $pago ??= new Pago(['viaje_id' => $viaje->id]);
            $nuevo = ! $pago->exists;

            $pago->fill([
                // En el enlace, provisorio hasta conocer el medio real (se
                // actualiza al aprobarse).
                'metodo_pago' => $metodo,
                'monto' => $importe,
                'referencia_externa' => null,
                'proveedor' => self::PROVEEDOR,
                'preferencia_id' => null,
                'enlace_pago' => null,
                'preferencia_expira_en' => null,
                'mp_orden_id' => null,
                'estado' => EstadoPago::Pendiente,
                'fecha_pago' => null,
                'registrado_por_persona_id' => $personaId,
                'conciliado_por_persona_id' => null,
                'conciliado_en' => null,
            ]);

            $accion = match ($modalidad) {
                'enlace' => $nuevo ? 'inicio_cobro_mercadopago' : 'reintento_cobro_mercadopago',
                'tarjeta' => 'inicio_cobro_tarjeta_guardada',
                'automatico' => 'inicio_cobro_automatico',
            };
            $pago->registrarEnHistorial($accion, $personaId, $extraHistorial + array_filter(['preferencia_vencida' => $preferenciaAVencer]));
            $pago->save();

            return $pago;
        });

        if ($preferenciaAVencer !== null) {
            VencerPreferenciaMercadoPagoJob::dispatch($preferenciaAVencer);
        }

        return $pago;
    }

    /**
     * Un solo cobro con tarjeta en curso por viaje (evita dos cargos si el
     * pasajero toca dos veces "Pagar": cada intento trae un token distinto).
     *
     * @template T
     *
     * @param  callable(): T  $cobro
     * @return T
     *
     * @throws PagoRechazado
     */
    private function conCandado(int $viajeId, callable $cobro): mixed
    {
        $candado = Cache::lock("pagos:viaje:{$viajeId}:cobro", 120);

        if (! $candado->get()) {
            throw new PagoRechazado('Ya hay un cobro en curso para este viaje. Esperá unos segundos y consultá su estado.');
        }

        try {
            return $cobro();
        } finally {
            $candado->release();
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
            // se registró el cobro en efectivo, o pagó dos veces.
            Log::critical('Mercado Pago aprobó un pago para un viaje que no tiene un cobro de Mercado Pago pendiente; requiere reintegro.', [
                'pago_id' => $pago->id, 'viaje_id' => $pago->viaje_id, 'mp_id' => $mpId, 'estado_pago' => $pago->estado->value,
            ]);
            $pago->registrarEnHistorial('mercadopago_aprobado_sin_aplicar', null, $extra);

            if (config('services.mercadopago.reintegro_automatico')) {
                ReintegrarOperacionMercadoPagoJob::dispatch($pago->id, $mpId)->afterCommit();
            }

            return 'requiere_revision';
        }

        $moneda = $mp['currency_id'] ?? null;
        $importe = $mp['transaction_amount'] ?? null;
        $importeCoincide = (is_int($importe) || is_float($importe) || (is_string($importe) && is_numeric($importe)))
            && $this->centavos($importe) === Dinero::aCentavos((string) $pago->monto);

        if ($moneda !== 'ARS' || ! $importeCoincide) {
            Log::critical('Mercado Pago aprobó un pago con moneda o importe distintos al del viaje; no se confirma.', [
                'pago_id' => $pago->id, 'viaje_id' => $pago->viaje_id, 'mp_id' => $mpId,
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
                'pago_id' => $pago->id, 'viaje_id' => $pago->viaje_id, 'mp_id' => $mpId,
            ]);

            return 'reintegrado';
        }

        return $this->registrarNovedad($pago, $extra);
    }

    /**
     * Estados sin efecto sobre el pago (rechazado, cancelado, en proceso,
     * etc.): solo quedan en el historial. Un pago rechazado deja el cobro
     * pendiente para que el pasajero reintente o pague por otro medio.
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

        return $datos + $this->notificacion();
    }

    /**
     * @return array<string, mixed>
     */
    private function datosPagoConTarjeta(Pago $pago, MedioPagoGuardado $medio, string $token): array
    {
        return [
            'transaction_amount' => Dinero::aCentavos((string) $pago->monto) / 100,
            'token' => $token,
            'description' => "Viaje #{$pago->viaje_id} - ".config('app.name'),
            'installments' => 1,
            'payment_method_id' => $medio->mp_payment_method_id,
            'payer' => ['type' => 'customer', 'id' => $medio->mp_customer_id],
            'external_reference' => self::referenciaExterna($pago),
            'binary_mode' => true,
            'statement_descriptor' => (string) config('services.mercadopago.descriptor'),
            'metadata' => ['pago_id' => $pago->id, 'viaje_id' => $pago->viaje_id],
        ] + $this->notificacion();
    }

    /**
     * @return array<string, mixed>
     */
    private function datosOrdenAutomatica(Pago $pago, MedioPagoGuardado $medio): array
    {
        $importe = Dinero::desdeCentavos(Dinero::aCentavos((string) $pago->monto));

        return [
            'type' => 'online',
            'processing_mode' => 'automatic',
            'total_amount' => $importe,
            'external_reference' => self::referenciaExterna($pago),
            'payer' => ['customer_id' => $medio->mp_customer_id],
            'transactions' => ['payments' => [[
                'amount' => $importe,
                'automatic_payments' => ['payment_profile_id' => $medio->mp_payment_profile_id],
                // Cobro iniciado por el comercio con una credencial guardada
                // y autorizada por el pasajero (MIT, card on file).
                'stored_credential' => ['payment_initiator' => 'merchant', 'reason' => 'card_on_file'],
            ]]],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function notificacion(): array
    {
        $url = config('services.mercadopago.notification_url');

        // source_news=webhooks: solo notificaciones Webhooks (firmadas), no IPN.
        return filled($url)
            ? ['notification_url' => $url.(str_contains($url, '?') ? '&' : '?').'source_news=webhooks']
            : [];
    }

    private function exigirHabilitado(): void
    {
        if (! self::habilitado()) {
            throw new MercadoPagoNoDisponible('Los cobros con Mercado Pago no están habilitados.');
        }
    }

    private static function metodoDeTarjeta(string $tipo): MetodoPago
    {
        return $tipo === 'credit_card' ? MetodoPago::TarjetaCredito : MetodoPago::TarjetaDebito;
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
