<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\VincularMedioPagoRequest;
use App\Models\MedioPagoGuardado;
use App\OpenApi\Schemas\ErrorResponse;
use App\OpenApi\Schemas\ValidationErrorResponse;
use App\Services\Pagos\MercadoPago\CobrosMercadoPago;
use App\Services\Pagos\MercadoPago\MediosDePagoGuardados;
use App\Services\Pagos\MercadoPago\MercadoPagoNoDisponible;
use App\Services\Pagos\PagoRechazado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tarjetas guardadas del pasajero (Mercado Pago). Las respuestas nunca
 * incluyen identificadores del proveedor ni datos sensibles: solo marca,
 * tipo, últimos 4 dígitos y vencimiento.
 */
class MedioPagoController extends Controller
{
    public function __construct(private readonly MediosDePagoGuardados $medios) {}

    #[OA\Get(
        path: '/pagos/mercadopago/configuracion',
        summary: 'Configuración pública de Mercado Pago para los frontends',
        description: 'Clave pública para inicializar MercadoPago.js o los SDK móviles (tokenización de tarjetas) y funciones habilitadas. La clave pública no es un secreto.',
        tags: ['Medios de pago'],
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'OK.', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'habilitado', type: 'boolean'),
                new OA\Property(property: 'public_key', type: 'string', nullable: true),
                new OA\Property(property: 'tarjetas_guardadas', type: 'boolean'),
                new OA\Property(property: 'pagos_automaticos', type: 'boolean'),
                new OA\Property(property: 'consentimiento_version', type: 'string'),
            ], type: 'object'),
        ]))],
    )]
    public function configuracion(): JsonResponse
    {
        $habilitado = CobrosMercadoPago::habilitado() && filled(config('services.mercadopago.public_key'));

        return response()->json(['data' => [
            'habilitado' => CobrosMercadoPago::habilitado(),
            'public_key' => $habilitado ? config('services.mercadopago.public_key') : null,
            'tarjetas_guardadas' => $habilitado,
            'pagos_automaticos' => $habilitado && CobrosMercadoPago::pagosAutomaticosHabilitados(),
            'consentimiento_version' => (string) config('services.mercadopago.consentimiento_version'),
        ]]);
    }

    #[OA\Get(
        path: '/cliente/medios-pago',
        summary: 'Listar las tarjetas guardadas del pasajero',
        tags: ['Medios de pago'],
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'OK (la predeterminada primero).')],
    )]
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->medios->listar($request->user()->persona_id)->map->resumen()->values(),
        ]);
    }

    #[OA\Post(
        path: '/cliente/medios-pago',
        summary: 'Vincular una tarjeta tokenizada',
        description: 'El frontend tokeniza la tarjeta con MercadoPago.js / SDK móvil y envía solo el token (un solo uso). Requiere acepto_guardar_tarjeta=true. Con cobro_automatico=true (y autorizo_cobro_automatico=true) se crea un perfil de pago para cobrar sin CVV al finalizar cada viaje; solo disponible si Mercado Pago habilitó "Pagos automáticos" a la cuenta.',
        tags: ['Medios de pago'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['token', 'payment_method_id', 'tipo', 'acepto_guardar_tarjeta'], properties: [
            new OA\Property(property: 'token', type: 'string', example: 'ff8080814c11e237014c1ff593b57b4d'),
            new OA\Property(property: 'payment_method_id', type: 'string', example: 'visa'),
            new OA\Property(property: 'tipo', type: 'string', enum: ['credit_card', 'debit_card', 'prepaid_card']),
            new OA\Property(property: 'predeterminado', type: 'boolean'),
            new OA\Property(property: 'acepto_guardar_tarjeta', type: 'boolean', example: true),
            new OA\Property(property: 'cobro_automatico', type: 'boolean', example: false),
            new OA\Property(property: 'autorizo_cobro_automatico', type: 'boolean'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Tarjeta vinculada.'),
            new OA\Response(response: 422, description: 'Datos inválidos, sin consentimiento, límite de tarjetas, Mercado Pago no pudo validar la tarjeta, o cobros automáticos no habilitados.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
            new OA\Response(response: 503, description: 'Mercado Pago no está habilitado o no respondió.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function store(VincularMedioPagoRequest $request): JsonResponse
    {
        // Seguridad (SEC-06): cada intento (exitoso o no) cuenta. Sin este
        // límite, el endpoint permitía probar tarjetas robadas en serie
        // ("card testing") a razón de 20 por minuto.
        $clave = 'vincular-tarjeta:'.$request->user()->id;
        $maximo = max(1, (int) config('remiseria.abuso.vincular_tarjetas_por_hora'));

        if (RateLimiter::tooManyAttempts($clave, $maximo)) {
            return response()->json([
                'message' => 'Alcanzaste el límite de intentos para vincular tarjetas. Probá de nuevo más tarde.',
            ], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) RateLimiter::availableIn($clave)]);
        }

        RateLimiter::hit($clave, 3600);

        try {
            $medio = $this->medios->vincular($request->user(), $request->datos(), $request->ip());
        } catch (PagoRechazado $e) {
            return response()->json(['message' => $e->getMessage()] + $e->datos, $e->status);
        } catch (MercadoPagoNoDisponible) {
            return response()->json(['message' => 'No se pudo vincular la tarjeta con Mercado Pago. Reintentá en unos minutos.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json(['message' => 'Tarjeta vinculada.', 'data' => $medio->resumen()], Response::HTTP_CREATED);
    }

    #[OA\Patch(
        path: '/cliente/medios-pago/{medio}/predeterminado',
        summary: 'Elegir la tarjeta predeterminada',
        tags: ['Medios de pago'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'medio', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'OK.'),
            new OA\Response(response: 404, description: 'La tarjeta no existe o no pertenece al pasajero.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function predeterminado(Request $request, int $medio): JsonResponse
    {
        $medio = $this->propio($request, $medio);

        return response()->json(['message' => 'Tarjeta predeterminada actualizada.', 'data' => $this->medios->predeterminar($medio)->resumen()]);
    }

    #[OA\Delete(
        path: '/cliente/medios-pago/{medio}',
        summary: 'Desvincular una tarjeta',
        description: 'Deja de usarse de inmediato (incluido el cobro automático) y se borra en Mercado Pago en segundo plano.',
        tags: ['Medios de pago'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'medio', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Tarjeta desvinculada.'),
            new OA\Response(response: 404, description: 'La tarjeta no existe o no pertenece al pasajero.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function destroy(Request $request, int $medio): JsonResponse
    {
        $this->medios->desvincular($this->propio($request, $medio));

        return response()->json(['message' => 'Tarjeta desvinculada.']);
    }

    /**
     * 404 (no 403) para no revelar si una tarjeta ajena existe.
     */
    private function propio(Request $request, int $id): MedioPagoGuardado
    {
        return MedioPagoGuardado::query()->activos()
            ->where('cliente_id', $request->user()->persona_id)
            ->findOrFail($id);
    }
}
