<?php

declare(strict_types=1);

namespace App\Services\Pagos\MercadoPago;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente mínimo de la API REST de Mercado Pago (Checkout Pro y pagos).
 *
 * @see https://www.mercadopago.com.ar/developers/es/reference
 */
class MercadoPagoCliente
{
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
    private function enviar(callable $solicitud, string $operacion): array
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

        if (! $respuesta->successful() || ! is_array($respuesta->json())) {
            Log::warning("Mercado Pago rechazó la solicitud al {$operacion}.", ['status' => $respuesta->status()]);

            throw new MercadoPagoNoDisponible("Mercado Pago rechazó la solicitud al {$operacion} (HTTP {$respuesta->status()}).");
        }

        return $respuesta->json();
    }
}
