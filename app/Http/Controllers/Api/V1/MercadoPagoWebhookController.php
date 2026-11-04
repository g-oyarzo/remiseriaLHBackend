<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Pagos\MercadoPago\CobrosMercadoPago;
use App\Services\Pagos\MercadoPago\FirmaWebhook;
use App\Services\Pagos\MercadoPago\MercadoPagoNoDisponible;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class MercadoPagoWebhookController extends Controller
{
    /**
     * POST /api/v1/webhooks/mercadopago
     */
    #[OA\Post(
        path: '/webhooks/mercadopago',
        summary: 'Webhook de Mercado Pago (uso exclusivo del proveedor)',
        description: 'Público, pero solo acepta notificaciones con firma x-signature válida (HMAC-SHA256 con la clave secreta de Webhooks). No confía en el cuerpo: consulta el pago en la API de Mercado Pago y valida referencia, moneda e importe exacto antes de confirmarlo. Idempotente. Responde 503 si no pudo consultar a Mercado Pago, para que el proveedor reintente.',
        tags: ['Pagos'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'payment')),
            new OA\Parameter(name: 'data.id', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: '123456789')),
            new OA\Parameter(name: 'x-signature', in: 'header', required: true, schema: new OA\Schema(type: 'string', example: 'ts=1704908010,v1=618c8534...')),
            new OA\Parameter(name: 'x-request-id', in: 'header', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Notificación recibida (procesada o ignorada).'),
            new OA\Response(response: 401, description: 'Firma inválida o webhook no configurado.'),
            new OA\Response(response: 503, description: 'No se pudo consultar a Mercado Pago; el proveedor reintentará.'),
        ],
    )]
    public function __invoke(Request $request, CobrosMercadoPago $cobros): JsonResponse
    {
        $dataId = self::dataId($request);
        $secreto = config('services.mercadopago.webhook_secret');

        if (! filled($secreto)) {
            Log::error('Webhook de Mercado Pago recibido sin MERCADOPAGO_WEBHOOK_SECRET configurado.');

            return response()->json(['message' => 'Webhook no configurado.'], Response::HTTP_UNAUTHORIZED);
        }

        if (! FirmaWebhook::esValida($request->header('x-signature'), $request->header('x-request-id'), $dataId, $secreto)) {
            Log::warning('Webhook de Mercado Pago con firma inválida.', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Firma inválida.'], Response::HTTP_UNAUTHORIZED);
        }

        $tipo = $request->query('type') ?? $request->input('type');

        if ($tipo !== 'payment' || $dataId === null || ! preg_match('/^\d{1,30}$/', $dataId)) {
            return response()->json(['message' => 'Notificación ignorada.']);
        }

        try {
            $resultado = $cobros->procesarPago($dataId);
        } catch (MercadoPagoNoDisponible) {
            return response()->json(['message' => 'No se pudo verificar el pago; reintentar.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        Log::info('Webhook de Mercado Pago procesado.', ['mp_payment_id' => $dataId, 'resultado' => $resultado]);

        return response()->json(['message' => 'Notificación recibida.']);
    }

    /**
     * data.id de la query string. PHP convierte los puntos de los nombres de
     * parámetros en guiones bajos ($_GET['data_id']), así que primero se
     * busca el nombre original en la query cruda.
     */
    private static function dataId(Request $request): ?string
    {
        foreach (explode('&', (string) $request->server('QUERY_STRING')) as $par) {
            [$clave, $valor] = array_pad(explode('=', $par, 2), 2, '');

            if (urldecode($clave) === 'data.id') {
                return urldecode($valor);
            }
        }

        $valor = $request->query('data_id');

        return is_string($valor) ? $valor : null;
    }
}
