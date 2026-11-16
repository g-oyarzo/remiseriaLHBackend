<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Pagos\MercadoPago\CobrosMercadoPago;
use App\Services\Pagos\MercadoPago\FirmaWebhook;
use App\Services\Pagos\MercadoPago\MercadoPagoNoDisponible;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        description: 'Público, pero solo acepta notificaciones con firma x-signature válida (HMAC-SHA256 con la clave secreta de Webhooks) y un ts dentro de la ventana MERCADOPAGO_WEBHOOK_TOLERANCIA_SEGUNDOS. Temas procesados: payment (Checkout Pro y tarjetas) y order (cobros automáticos). No confía en el cuerpo: consulta la operación en la API de Mercado Pago y valida referencia, moneda e importe exacto antes de confirmarla. Idempotente y con protección contra repeticiones. Responde 503 si no pudo consultar a Mercado Pago, para que el proveedor reintente.',
        tags: ['Pagos'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'payment')),
            new OA\Parameter(name: 'data.id', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: '123456789')),
            new OA\Parameter(name: 'x-signature', in: 'header', required: true, schema: new OA\Schema(type: 'string', example: 'ts=1704908010,v1=618c8534...')),
            new OA\Parameter(name: 'x-request-id', in: 'header', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Notificación recibida (procesada o ignorada).'),
            new OA\Response(response: 401, description: 'Firma inválida, fuera de la ventana de tiempo, o webhook no configurado.'),
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

        $firma = (string) $request->header('x-signature');
        $verificacion = FirmaWebhook::verificar(
            $firma,
            $request->header('x-request-id'),
            $dataId,
            $secreto,
            (int) config('services.mercadopago.webhook_tolerancia_segundos'),
        );

        if ($verificacion !== FirmaWebhook::VALIDA) {
            Log::warning('Webhook de Mercado Pago rechazado.', ['motivo' => $verificacion, 'ip' => $request->ip()]);

            return response()->json([
                'message' => $verificacion === FirmaWebhook::VENCIDA ? 'Notificación fuera de la ventana de tiempo permitida.' : 'Firma inválida.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $tipo = $request->query('type') ?? $request->input('type');

        $esPago = $tipo === 'payment' && $dataId !== null && preg_match('/^\d{1,30}$/', $dataId);
        $esOrden = $tipo === 'order' && $dataId !== null && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $dataId);

        if (! $esPago && ! $esOrden) {
            return response()->json(['message' => 'Notificación ignorada.']);
        }

        // Protección contra repeticiones: la misma firma ya procesada con
        // éxito no se vuelve a procesar (además de la idempotencia de
        // aplicar()). Se marca solo después de procesar, para que un
        // reintento tras un 503 sí se procese.
        $claveRepeticion = 'mp:webhook:'.hash('sha256', $firma.'|'.$dataId);

        if (Cache::has($claveRepeticion)) {
            return response()->json(['message' => 'Notificación ya procesada.']);
        }

        try {
            $resultado = $esPago ? $cobros->procesarPago($dataId) : $cobros->procesarOrden($dataId);
        } catch (MercadoPagoNoDisponible) {
            return response()->json(['message' => 'No se pudo verificar el pago; reintentar.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        Cache::put($claveRepeticion, true, now()->addSeconds(max(3600, 2 * (int) config('services.mercadopago.webhook_tolerancia_segundos'))));

        Log::info('Webhook de Mercado Pago procesado.', ['mp_id' => $dataId, 'tipo' => $tipo, 'resultado' => $resultado]);

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
