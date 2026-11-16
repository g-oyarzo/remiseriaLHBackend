<?php

declare(strict_types=1);

namespace App\Services\Pagos\MercadoPago;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente mínimo de la API REST de Mercado Pago: Checkout Pro
 * (preferencias), pagos (Payments API), reintegros, customers y tarjetas
 * guardadas, perfiles de pago y órdenes (Pagos automáticos, Orders API).
 *
 * Nunca registra el access token, el cuerpo de las respuestas ni tokens de
 * tarjeta: los logs solo contienen la operación y el código HTTP.
 *
 * @see https://www.mercadopago.com.ar/developers/es/reference
 */
class MercadoPagoCliente
{
    /** Identificadores de Mercado Pago aceptados en rutas (evita path traversal). */
    private const ID_SEGURO = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * Crea una preferencia de Checkout Pro.
     *
     * @param  array<string, mixed>  $preferencia
     * @return array{id: string, init_point: string}
     *
     * @throws MercadoPagoNoDisponible
     */
    public function crearPreferencia(array $preferencia, string $claveIdempotencia): array
    {
        $json = $this->enviar(
            fn (): Response => $this->http()->withHeaders(['X-Idempotency-Key' => $claveIdempotencia])->post('/checkout/preferences', $preferencia),
            'crear la preferencia',
        );

        $enlace = config('services.mercadopago.sandbox') ? ($json['sandbox_init_point'] ?? null) : ($json['init_point'] ?? null);

        if (! is_string($json['id'] ?? null) || ! is_string($enlace)) {
            throw new MercadoPagoNoDisponible('Mercado Pago devolvió una preferencia inválida.');
        }

        return ['id' => $json['id'], 'init_point' => $enlace];
    }

    /**
     * Vence una preferencia de Checkout Pro para que su enlace deje de
     * aceptar pagos (se usa al reemplazar el cobro por otro medio). Un pago
     * que el pasajero ya haya iniciado puede completarse igual: esos casos
     * los detecta aplicar() y se reintegran.
     *
     * @throws MercadoPagoNoDisponible
     */
    public function vencerPreferencia(string $preferenciaId): void
    {
        $this->exigirId($preferenciaId);

        $this->enviar(fn (): Response => $this->http()->put("/checkout/preferences/{$preferenciaId}", [
            'expires' => true,
            'expiration_date_to' => CarbonImmutable::now()->subMinute()->format('Y-m-d\TH:i:s.vP'),
        ]), 'vencer la preferencia');
    }

    /**
     * Consulta un pago por su id (fuente de verdad: nunca se confía en el
     * cuerpo de un webhook).
     *
     * @return array<string, mixed>
     *
     * @throws MercadoPagoNoDisponible
     */
    public function obtenerPago(string $pagoId): array
    {
        if (! preg_match('/^\d{1,30}$/', $pagoId)) {
            throw new MercadoPagoNoDisponible('Identificador de pago de Mercado Pago inválido.');
        }

        return $this->enviar(fn (): Response => $this->http()->get("/v1/payments/{$pagoId}"), 'consultar el pago');
    }

    /**
     * Pagos asociados a una referencia externa (conciliación periódica).
     *
     * @return list<array<string, mixed>>
     *
     * @throws MercadoPagoNoDisponible
     */
    public function buscarPagos(string $referenciaExterna): array
    {
        $json = $this->enviar(
            fn (): Response => $this->http()->get('/v1/payments/search', [
                'external_reference' => $referenciaExterna,
                'sort' => 'date_created',
                'criteria' => 'desc',
            ]),
            'buscar pagos',
        );

        return array_values(array_filter($json['results'] ?? [], 'is_array'));
    }

    /**
     * Crea un pago con una tarjeta tokenizada (Payments API). El token lo
     * genera el frontend con MercadoPago.js (para una tarjeta guardada, con
     * el card_id y el CVV que ingresa el pasajero).
     *
     * @param  array<string, mixed>  $pago
     * @return array<string, mixed>
     *
     * @throws MercadoPagoNoDisponible
     */
    public function crearPago(array $pago, string $claveIdempotencia): array
    {
        $json = $this->enviar(
            fn (): Response => $this->http()->withHeaders(['X-Idempotency-Key' => $claveIdempotencia])->post('/v1/payments', $pago),
            'crear el pago',
        );

        if (! isset($json['id']) || ! is_scalar($json['id'])) {
            throw new MercadoPagoNoDisponible('Mercado Pago devolvió un pago inválido.');
        }

        return $json;
    }

    /**
     * Reintegro total de un pago (Payments API).
     *
     * @return array<string, mixed>
     *
     * @throws MercadoPagoNoDisponible
     */
    public function reintegrarPago(string $pagoId, string $claveIdempotencia): array
    {
        if (! preg_match('/^\d{1,30}$/', $pagoId)) {
            throw new MercadoPagoNoDisponible('Identificador de pago de Mercado Pago inválido.');
        }

        return $this->enviar(
            fn (): Response => $this->http()->withHeaders(['X-Idempotency-Key' => $claveIdempotencia])->post("/v1/payments/{$pagoId}/refunds", (object) []),
            'reintegrar el pago',
        );
    }

    /**
     * Crea una orden (Orders API). Se usa para los cobros automáticos con un
     * perfil de pago (producto "Pagos automáticos").
     *
     * @param  array<string, mixed>  $orden
     * @return array<string, mixed>
     *
     * @throws MercadoPagoNoDisponible
     */
    public function crearOrden(array $orden, string $claveIdempotencia): array
    {
        $json = $this->enviar(
            fn (): Response => $this->http()->withHeaders(['X-Idempotency-Key' => $claveIdempotencia])->post('/v1/orders', $orden),
            'crear la orden',
        );

        if (! is_string($json['id'] ?? null)) {
            throw new MercadoPagoNoDisponible('Mercado Pago devolvió una orden inválida.');
        }

        return $json;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MercadoPagoNoDisponible
     */
    public function obtenerOrden(string $ordenId): array
    {
        $this->exigirId($ordenId);

        return $this->enviar(fn (): Response => $this->http()->get("/v1/orders/{$ordenId}"), 'consultar la orden');
    }

    /**
     * Reintegro total de una orden.
     *
     * @return array<string, mixed>
     *
     * @throws MercadoPagoNoDisponible
     */
    public function reintegrarOrden(string $ordenId, string $claveIdempotencia): array
    {
        $this->exigirId($ordenId);

        return $this->enviar(
            fn (): Response => $this->http()->withHeaders(['X-Idempotency-Key' => $claveIdempotencia])->post("/v1/orders/{$ordenId}/refund", (object) []),
            'reintegrar la orden',
        );
    }

    /**
     * Busca el customer por e-mail o lo crea.
     *
     * @param  array{email: string, first_name?: string, last_name?: string}  $datos
     *
     * @throws MercadoPagoNoDisponible
     */
    public function obtenerOCrearCliente(array $datos): string
    {
        $busqueda = $this->enviar(
            fn (): Response => $this->http()->get('/v1/customers/search', ['email' => $datos['email']]),
            'buscar el cliente',
        );

        $existente = $busqueda['results'][0]['id'] ?? null;

        if (is_string($existente) && preg_match(self::ID_SEGURO, $existente)) {
            return $existente;
        }

        $json = $this->enviar(fn (): Response => $this->http()->post('/v1/customers', $datos), 'crear el cliente');

        if (! is_string($json['id'] ?? null) || ! preg_match(self::ID_SEGURO, $json['id'])) {
            throw new MercadoPagoNoDisponible('Mercado Pago devolvió un cliente inválido.');
        }

        return $json['id'];
    }

    /**
     * Guarda una tarjeta tokenizada en el customer (Customers & Cards).
     *
     * @return array<string, mixed>
     *
     * @throws MercadoPagoNoDisponible
     */
    public function guardarTarjeta(string $customerId, string $token): array
    {
        $this->exigirId($customerId);

        $json = $this->enviar(
            fn (): Response => $this->http()->post("/v1/customers/{$customerId}/cards", ['token' => $token]),
            'guardar la tarjeta',
        );

        if (! is_scalar($json['id'] ?? null)) {
            throw new MercadoPagoNoDisponible('Mercado Pago devolvió una tarjeta inválida.');
        }

        return $json;
    }

    /**
     * @throws MercadoPagoNoDisponible
     */
    public function eliminarTarjeta(string $customerId, string $cardId): void
    {
        $this->exigirId($customerId);
        $this->exigirId($cardId);

        $this->enviar(
            fn (): Response => $this->http()->delete("/v1/customers/{$customerId}/cards/{$cardId}"),
            'eliminar la tarjeta',
            aceptar404: true,
        );
    }

    /**
     * Crea un perfil de pago para cobros automáticos sin CVV.
     *
     * @param  array<string, mixed>  $perfil
     * @return array<string, mixed>
     *
     * @throws MercadoPagoNoDisponible
     */
    public function crearPerfilDePago(string $customerId, array $perfil, string $claveIdempotencia): array
    {
        $this->exigirId($customerId);

        $json = $this->enviar(
            fn (): Response => $this->http()->withHeaders(['X-Idempotency-Key' => $claveIdempotencia])
                ->post("/v1/customers/{$customerId}/payment-profiles", $perfil),
            'crear el perfil de pago',
        );

        if (! is_string($json['id'] ?? null)) {
            throw new MercadoPagoNoDisponible('Mercado Pago devolvió un perfil de pago inválido.');
        }

        return $json;
    }

    /**
     * @throws MercadoPagoNoDisponible
     */
    public function cancelarPerfilDePago(string $customerId, string $perfilId): void
    {
        $this->exigirId($customerId);
        $this->exigirId($perfilId);

        $this->enviar(
            fn (): Response => $this->http()->post("/v1/customers/{$customerId}/payment-profiles/{$perfilId}/cancel"),
            'cancelar el perfil de pago',
            aceptar404: true,
        );
    }

    private function exigirId(string $id): void
    {
        if (! preg_match(self::ID_SEGURO, $id)) {
            throw new MercadoPagoNoDisponible('Identificador de Mercado Pago inválido.');
        }
    }

    private function http(): PendingRequest
    {
        $token = config('services.mercadopago.access_token');

        if (! filled($token)) {
            throw new MercadoPagoNoDisponible('Mercado Pago no está configurado (falta MERCADOPAGO_ACCESS_TOKEN).');
        }

        $timeout = (float) config('services.mercadopago.timeout_segundos');

        return Http::baseUrl(rtrim((string) config('services.mercadopago.base_url'), '/'))
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->connectTimeout($timeout);
    }

    /**
     * @param  callable(): Response  $solicitud
     * @return array<string, mixed>
     */
    private function enviar(callable $solicitud, string $operacion, bool $aceptar404 = false): array
    {
        try {
            $respuesta = $solicitud();
        } catch (MercadoPagoNoDisponible $e) {
            throw $e;
        } catch (Throwable $e) {
            // Las excepciones de conexión incluyen la URL; no se registran.
            Log::warning("Mercado Pago no respondió al {$operacion}.", ['error' => class_basename($e)]);

            throw new MercadoPagoNoDisponible("Mercado Pago no respondió al {$operacion}.");
        }

        if ($aceptar404 && $respuesta->status() === 404) {
            return [];
        }

        if (! $respuesta->successful()) {
            Log::warning("Mercado Pago rechazó la solicitud al {$operacion}.", ['status' => $respuesta->status()]);

            throw new MercadoPagoNoDisponible(
                "Mercado Pago rechazó la solicitud al {$operacion} (HTTP {$respuesta->status()}).",
                $respuesta->status(),
            );
        }

        $json = $respuesta->json();

        if ($respuesta->status() !== 204 && ! is_array($json) && ! in_array($respuesta->body(), ['', 'null'], true)) {
            Log::warning("Mercado Pago devolvió una respuesta ilegible al {$operacion}.", ['status' => $respuesta->status()]);

            throw new MercadoPagoNoDisponible("Mercado Pago devolvió una respuesta ilegible al {$operacion}.");
        }

        return is_array($json) ? $json : [];
    }
}
