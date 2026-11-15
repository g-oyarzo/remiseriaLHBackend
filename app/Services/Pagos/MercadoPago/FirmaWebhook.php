<?php

declare(strict_types=1);

namespace App\Services\Pagos\MercadoPago;

/**
 * Validación de la firma de los webhooks de Mercado Pago.
 *
 * El header x-signature tiene el formato "ts=<timestamp>,v1=<hmac>". El
 * HMAC-SHA256 (hexadecimal), calculado con la clave secreta de Webhooks de
 * la aplicación, firma el texto
 *
 *     id:<data.id de la query>;request-id:<x-request-id>;ts:<ts>;
 *
 * donde data.id se usa en minúsculas si es alfanumérico, y se omite del
 * texto cualquier parte cuyo valor no venga en la notificación (regla de la
 * documentación de Mercado Pago). Las notificaciones de "Código QR" no se
 * pueden validar con la clave secreta; esta integración usa Checkout Pro,
 * Checkout API y Orders.
 *
 * Ventana temporal: además de la firma, se exige que ts esté dentro de
 * MERCADOPAGO_WEBHOOK_TOLERANCIA_SEGUNDOS respecto del reloj del servidor
 * (en cualquier dirección). La documentación dice que ts está en
 * milisegundos, pero algunos ejemplos lo muestran en segundos: se aceptan
 * ambos (más de 11 dígitos = milisegundos). Una notificación vieja
 * rechazada no se pierde: la conciliación periódica consulta los cobros
 * pendientes en la API.
 *
 * Verificar contra una notificación real de sandbox antes de producción.
 */
final class FirmaWebhook
{
    public const VALIDA = 'valida';

    public const INVALIDA = 'invalida';

    public const VENCIDA = 'vencida';

    /**
     * @return self::VALIDA|self::INVALIDA|self::VENCIDA
     */
    public static function verificar(?string $firma, ?string $requestId, ?string $dataId, ?string $secreto, int $toleranciaSegundos): string
    {
        if (! self::esValida($firma, $requestId, $dataId, $secreto)) {
            return self::INVALIDA;
        }

        if ($toleranciaSegundos <= 0) {
            return self::VALIDA;
        }

        $segundos = self::timestampEnSegundos((string) self::partes((string) $firma)['ts']);

        return $segundos !== null && abs(now()->getTimestamp() - $segundos) <= $toleranciaSegundos
            ? self::VALIDA
            : self::VENCIDA;
    }

    public static function esValida(?string $firma, ?string $requestId, ?string $dataId, ?string $secreto): bool
    {
        if (! filled($secreto) || ! filled($firma)) {
            return false;
        }

        $partes = self::partes($firma);
        $ts = $partes['ts'] ?? null;
        $v1 = $partes['v1'] ?? null;

        if (! filled($ts) || ! filled($v1) || ! ctype_digit($ts)) {
            return false;
        }

        // hash_equals: comparación en tiempo constante.
        return hash_equals(hash_hmac('sha256', self::manifiesto($dataId, $requestId, $ts), $secreto), strtolower($v1));
    }

    public static function manifiesto(?string $dataId, ?string $requestId, string $ts): string
    {
        $texto = '';

        if (filled($dataId)) {
            $texto .= 'id:'.(ctype_alnum($dataId) ? strtolower($dataId) : $dataId).';';
        }

        if (filled($requestId)) {
            $texto .= "request-id:{$requestId};";
        }

        return $texto."ts:{$ts};";
    }

    public static function timestampEnSegundos(string $ts): ?int
    {
        if (! ctype_digit($ts) || strlen($ts) > 16) {
            return null;
        }

        return strlen($ts) > 11 ? intdiv((int) $ts, 1000) : (int) $ts;
    }

    /**
     * @return array<string, string>
     */
    private static function partes(string $firma): array
    {
        $partes = [];

        foreach (explode(',', $firma) as $par) {
            [$clave, $valor] = array_pad(explode('=', trim($par), 2), 2, null);

            if ($clave !== null && $valor !== null) {
                $partes[trim($clave)] = trim($valor);
            }
        }

        return $partes;
    }
}
