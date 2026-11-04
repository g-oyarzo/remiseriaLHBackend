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
 * pueden validar con la clave secreta; esta integración usa Checkout Pro.
 *
 * Verificar contra una notificación real de sandbox antes de producción.
 */
final class FirmaWebhook
{
    public static function esValida(?string $firma, ?string $requestId, ?string $dataId, ?string $secreto): bool
    {
        if (! filled($secreto) || ! filled($firma)) {
            return false;
        }

        $partes = [];
        foreach (explode(',', $firma) as $par) {
            [$clave, $valor] = array_pad(explode('=', trim($par), 2), 2, null);
            if ($clave !== null && $valor !== null) {
                $partes[trim($clave)] = trim($valor);
            }
        }

        $ts = $partes['ts'] ?? null;
        $v1 = $partes['v1'] ?? null;

        if (! filled($ts) || ! filled($v1)) {
            return false;
        }

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
}
