<?php

declare(strict_types=1);

namespace App\Services\Pagos\MercadoPago;

use App\Jobs\DesvincularMedioDePagoRemotoJob;
use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\MedioPagoGuardado;
use App\Services\Pagos\PagoRechazado;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tarjetas guardadas de los pasajeros, tokenizadas por Mercado Pago.
 *
 * Dos mecanismos oficiales, que NO son equivalentes:
 *
 * - Tarjeta guardada (Customers & Cards, disponible para cualquier
 *   integración): el pasajero no vuelve a cargar los datos, pero cada cobro
 *   pide el CVV (Mercado Pago no lo almacena). Es un cobro iniciado por el
 *   pasajero (CIT).
 * - Cobro automático (perfil de pago, producto "Pagos automáticos" con la
 *   Orders API): permite cobrar al finalizar el viaje sin CVV (MIT). Exige
 *   la autorización del equipo comercial de Mercado Pago; por eso está
 *   detrás de MERCADOPAGO_PAGOS_AUTOMATICOS (false por defecto).
 *
 * El backend nunca recibe números de tarjeta ni CVV: solo el token de un
 * solo uso que genera MercadoPago.js (o los SDK móviles) en el dispositivo.
 */
final class MediosDePagoGuardados
{
    public function __construct(private readonly MercadoPagoCliente $cliente) {}

    /**
     * @return Collection<int, MedioPagoGuardado>
     */
    public function listar(int $clienteId): Collection
    {
        return MedioPagoGuardado::query()->activos()
            ->where('cliente_id', $clienteId)
            ->orderByDesc('predeterminado')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Vincula una tarjeta tokenizada a la cuenta del pasajero.
     *
     * @param  array{token: string, payment_method_id: string, tipo: string, cobro_automatico: bool, predeterminado: bool}  $datos
     *
     * @throws PagoRechazado
     * @throws MercadoPagoNoDisponible
     */
    public function vincular(Cuenta $cuenta, array $datos, ?string $ip): MedioPagoGuardado
    {
        if (! CobrosMercadoPago::habilitado()) {
            throw new MercadoPagoNoDisponible('Los cobros con Mercado Pago no están habilitados.');
        }

        if ($datos['cobro_automatico'] && ! CobrosMercadoPago::pagosAutomaticosHabilitados()) {
            throw new PagoRechazado('Los cobros automáticos todavía no están habilitados. Podés guardar la tarjeta sin cobro automático.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $maximo = max(1, (int) config('services.mercadopago.max_medios_por_cliente'));

        if (MedioPagoGuardado::query()->activos()->where('cliente_id', $cuenta->persona_id)->count() >= $maximo) {
            throw new PagoRechazado("Podés tener hasta {$maximo} tarjetas guardadas. Desvinculá una para agregar otra.", Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $customerId = $this->asegurarCustomer($cuenta);

        try {
            $atributos = $datos['cobro_automatico']
                ? $this->crearPerfil($customerId, $datos)
                : $this->guardarTarjeta($customerId, $datos);
        } catch (MercadoPagoNoDisponible $e) {
            if ($e->getCode() >= 400 && $e->getCode() < 500) {
                throw new PagoRechazado('Mercado Pago no pudo validar la tarjeta. Revisá los datos e intentá de nuevo.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            throw $e;
        }

        return DB::transaction(function () use ($cuenta, $customerId, $atributos, $datos, $ip): MedioPagoGuardado {
            // Lock sobre el cliente: serializa los cambios de predeterminado.
            Cliente::query()->lockForUpdate()->findOrFail($cuenta->persona_id);

            // Mercado Pago deduplica: guardar otra vez la misma tarjeta
            // devuelve el mismo card_id.
            $existente = MedioPagoGuardado::query()->lockForUpdate()
                ->when($atributos['mp_card_id'] ?? null, fn ($q, $id) => $q->where('mp_card_id', $id))
                ->when($atributos['mp_payment_profile_id'] ?? null, fn ($q, $id) => $q->where('mp_payment_profile_id', $id))
                ->first();

            if ($existente !== null && ($existente->cliente_id !== $cuenta->persona_id || $existente->desvinculado_en === null)) {
                throw new PagoRechazado('Esta tarjeta ya está vinculada.');
            }

            $activos = MedioPagoGuardado::query()->activos()->where('cliente_id', $cuenta->persona_id);
            $predeterminado = $datos['predeterminado'] || ! $activos->exists();

            if ($predeterminado) {
                (clone $activos)->update(['predeterminado' => false]);
            }

            if ($existente !== null) {
                // Tarjeta desvinculada antes y vuelta a vincular: se reactiva
                // con el consentimiento nuevo.
                $existente->fill($atributos + [
                    'predeterminado' => $predeterminado,
                    'cobro_automatico' => $datos['cobro_automatico'],
                    'consentimiento_en' => now(),
                    'consentimiento_ip' => $ip,
                    'consentimiento_version' => (string) config('services.mercadopago.consentimiento_version'),
                    'desvinculado_en' => null,
                ])->save();

                return $existente;
            }

            return MedioPagoGuardado::query()->create($atributos + [
                'cliente_id' => $cuenta->persona_id,
                'proveedor' => CobrosMercadoPago::PROVEEDOR,
                'mp_customer_id' => $customerId,
                'predeterminado' => $predeterminado,
                'cobro_automatico' => $datos['cobro_automatico'],
                'consentimiento_en' => now(),
                'consentimiento_ip' => $ip,
                'consentimiento_version' => (string) config('services.mercadopago.consentimiento_version'),
            ]);
        });
    }

    public function predeterminar(MedioPagoGuardado $medio): MedioPagoGuardado
    {
        return DB::transaction(function () use ($medio): MedioPagoGuardado {
            Cliente::query()->lockForUpdate()->findOrFail($medio->cliente_id);

            MedioPagoGuardado::query()->activos()->where('cliente_id', $medio->cliente_id)->update(['predeterminado' => false]);
            $medio = MedioPagoGuardado::query()->activos()->findOrFail($medio->id);
            $medio->update(['predeterminado' => true]);

            return $medio;
        });
    }

    /**
     * Baja inmediata en el sistema (deja de usarse para cobrar) y baja en
     * Mercado Pago en segundo plano, con reintentos.
     */
    public function desvincular(MedioPagoGuardado $medio): void
    {
        DB::transaction(function () use ($medio): void {
            Cliente::query()->lockForUpdate()->findOrFail($medio->cliente_id);

            $medio = MedioPagoGuardado::query()->activos()->lockForUpdate()->findOrFail($medio->id);
            $eraPredeterminado = $medio->predeterminado;
            $medio->update(['desvinculado_en' => now(), 'predeterminado' => false, 'cobro_automatico' => false]);

            if ($eraPredeterminado) {
                MedioPagoGuardado::query()->activos()
                    ->where('cliente_id', $medio->cliente_id)
                    ->orderByDesc('id')
                    ->first()
                    ?->update(['predeterminado' => true]);
            }
        });

        DesvincularMedioDePagoRemotoJob::dispatch($medio->id);
    }

    /**
     * Borra la tarjeta o cancela el perfil en Mercado Pago (lo llama el job).
     *
     * @throws MercadoPagoNoDisponible
     */
    public function desvincularEnProveedor(MedioPagoGuardado $medio): void
    {
        if ($medio->mp_card_id !== null) {
            $this->cliente->eliminarTarjeta($medio->mp_customer_id, $medio->mp_card_id);
        }

        if ($medio->mp_payment_profile_id !== null) {
            $this->cliente->cancelarPerfilDePago($medio->mp_customer_id, $medio->mp_payment_profile_id);
        }
    }

    /**
     * @throws MercadoPagoNoDisponible
     */
    private function asegurarCustomer(Cuenta $cuenta): string
    {
        $cliente = Cliente::query()->with('persona')->findOrFail($cuenta->persona_id);

        if (filled($cliente->mercadopago_customer_id)) {
            return $cliente->mercadopago_customer_id;
        }

        // Sin datos personales más allá de los necesarios (e-mail y nombre).
        $customerId = $this->cliente->obtenerOCrearCliente(array_filter([
            'email' => $cuenta->email,
            'first_name' => $cliente->persona?->nombre,
            'last_name' => $cliente->persona?->apellido,
        ]));

        return DB::transaction(function () use ($cliente, $customerId): string {
            $cliente = Cliente::query()->lockForUpdate()->findOrFail($cliente->persona_id);

            if (blank($cliente->mercadopago_customer_id)) {
                $cliente->update(['mercadopago_customer_id' => $customerId]);
            }

            return $cliente->mercadopago_customer_id;
        });
    }

    /**
     * @param  array{token: string, payment_method_id: string, tipo: string}  $datos
     * @return array<string, mixed>
     */
    private function guardarTarjeta(string $customerId, array $datos): array
    {
        $tarjeta = $this->cliente->guardarTarjeta($customerId, $datos['token']);

        return [
            'mp_card_id' => (string) $tarjeta['id'],
            'mp_payment_method_id' => self::texto($tarjeta['payment_method']['id'] ?? null, 30) ?? $datos['payment_method_id'],
            'tipo' => self::texto($tarjeta['payment_method']['payment_type_id'] ?? null, 20) ?? $datos['tipo'],
            'ultimos_cuatro' => self::ultimosCuatro($tarjeta['last_four_digits'] ?? null),
            'vencimiento_mes' => self::entero($tarjeta['expiration_month'] ?? null, 1, 12),
            'vencimiento_anio' => self::entero($tarjeta['expiration_year'] ?? null, 2000, 2100),
            'emisor' => self::texto($tarjeta['issuer']['name'] ?? null, 80),
        ];
    }

    /**
     * @param  array{token: string, payment_method_id: string, tipo: string}  $datos
     * @return array<string, mixed>
     */
    private function crearPerfil(string $customerId, array $datos): array
    {
        $perfil = $this->cliente->crearPerfilDePago($customerId, [
            'description' => 'Cobro de viajes '.config('app.name'),
            'statement_descriptor' => (string) config('services.mercadopago.descriptor'),
            'payment_methods' => [[
                'id' => $datos['payment_method_id'],
                'type' => $datos['tipo'],
                'token' => $datos['token'],
                'default_method' => true,
            ]],
        ], (string) Str::uuid());

        $metodo = $perfil['payment_methods'][0] ?? [];

        if (($perfil['status'] ?? null) !== 'READY') {
            Log::warning('Mercado Pago creó un perfil de pago que no quedó listo.', ['status' => is_string($perfil['status'] ?? null) ? $perfil['status'] : null]);
        }

        return [
            'mp_payment_profile_id' => $perfil['id'],
            'mp_payment_method_id' => $datos['payment_method_id'],
            'tipo' => $datos['tipo'],
            'ultimos_cuatro' => self::ultimosCuatro(is_array($metodo) ? ($metodo['last_four_digits'] ?? null) : null),
            'vencimiento_mes' => null,
            'vencimiento_anio' => null,
            'emisor' => null,
        ];
    }

    private static function ultimosCuatro(mixed $valor): ?string
    {
        return is_scalar($valor) && preg_match('/^\d{4}$/', (string) $valor) ? (string) $valor : null;
    }

    private static function entero(mixed $valor, int $minimo, int $maximo): ?int
    {
        return is_numeric($valor) && (int) $valor >= $minimo && (int) $valor <= $maximo ? (int) $valor : null;
    }

    private static function texto(mixed $valor, int $largo): ?string
    {
        return is_string($valor) && $valor !== '' ? mb_substr($valor, 0, $largo) : null;
    }
}
