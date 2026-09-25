<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Events\NuevoMensajeViaje;
use App\Http\Controllers\Controller;
use App\Models\Mensaje;
use App\Models\Viaje;
use App\OpenApi\Schemas\EnviarMensajeRequest as EnviarMensajeRequestSchema;
use App\OpenApi\Schemas\ErrorResponse;
use App\OpenApi\Schemas\MensajeSchema;
use App\OpenApi\Schemas\SimpleMessageResponse;
use App\OpenApi\Schemas\ValidationErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class MensajeController extends Controller
{
    /**
     * GET /api/v1/viajes/{viaje}/mensajes
     *
     * Obtiene los mensajes de un viaje.
     */
    #[OA\Get(
        path: '/viajes/{viaje}/mensajes',
        summary: 'Listar mensajes de un viaje',
        description: 'Todo el historial de chat del viaje, ordenado del más antiguo al más reciente. Para recibir mensajes nuevos en tiempo real, suscribirse al canal privado viaje.{viajeId} (evento "mensaje.nuevo").',
        tags: ['Mensajes'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'viaje', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'OK.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: MensajeSchema::class)),
            ])),
            new OA\Response(response: 403, description: 'No tiene acceso a los mensajes de este viaje.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'Viaje inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function index(Request $request, Viaje $viaje): JsonResponse
    {
        $this->authorizeAccess($request, $viaje);

        $mensajes = Mensaje::query()
            ->with(['emisor', 'receptor'])
            ->delViaje($viaje->id)
            ->oldest()
            ->get();

        return response()->json(['data' => $mensajes]);
    }

    /**
     * POST /api/v1/viajes/{viaje}/mensajes
     *
     * Envía un mensaje. Si envía el cliente, el receptor es el conductor y viceversa.
     */
    #[OA\Post(
        path: '/viajes/{viaje}/mensajes',
        summary: 'Enviar un mensaje de chat',
        description: 'El receptor se infiere automáticamente (si emite el cliente, recibe el conductor asignado, y viceversa). Emite el evento "mensaje.nuevo" en el canal privado viaje.{viajeId} vía WebSockets (Reverb).',
        tags: ['Mensajes'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'viaje', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: EnviarMensajeRequestSchema::class)),
        responses: [
            new OA\Response(response: 201, description: 'Mensaje enviado.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Mensaje enviado.'),
                new OA\Property(property: 'data', ref: MensajeSchema::class),
            ])),
            new OA\Response(response: 403, description: 'No tiene acceso a los mensajes de este viaje.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'Viaje inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 409, description: 'El viaje todavía no tiene conductor asignado.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function store(Request $request, Viaje $viaje): JsonResponse
    {
        $this->authorizeAccess($request, $viaje);

        if (! $viaje->conductor_id) {
            return response()->json([
                'message' => 'No se puede enviar mensajes a un viaje sin conductor asignado.',
            ], Response::HTTP_CONFLICT);
        }

        $validated = $request->validate([
            'contenido' => ['required', 'string', 'max:1000'],
        ]);

        $cuenta = $request->user();
        $emisorId = $cuenta->persona_id;
        $receptorId = ($emisorId === $viaje->cliente_id) ? $viaje->conductor_id : $viaje->cliente_id;

        $mensaje = Mensaje::query()->create([
            'viaje_id' => $viaje->id,
            'emisor_persona_id' => $emisorId,
            'receptor_persona_id' => $receptorId,
            'contenido' => $validated['contenido'],
        ]);

        $mensaje->load(['emisor', 'receptor']);

        NuevoMensajeViaje::dispatch($mensaje);

        return response()->json([
            'message' => 'Mensaje enviado.',
            'data' => $mensaje,
        ], Response::HTTP_CREATED);
    }

    /**
     * PATCH /api/v1/viajes/{viaje}/mensajes/marcar-leidos
     */
    #[OA\Patch(
        path: '/viajes/{viaje}/mensajes/marcar-leidos',
        summary: 'Marcar mensajes como leídos',
        description: 'Marca como leídos (leido = true) todos los mensajes no leídos del viaje cuyo receptor es la cuenta autenticada.',
        tags: ['Mensajes'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'viaje', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Mensajes marcados como leídos.', content: new OA\JsonContent(ref: SimpleMessageResponse::class)),
            new OA\Response(response: 403, description: 'No tiene acceso a los mensajes de este viaje.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'Viaje inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function marcarLeidos(Request $request, Viaje $viaje): JsonResponse
    {
        $this->authorizeAccess($request, $viaje);

        $cuenta = $request->user();

        Mensaje::query()
            ->delViaje($viaje->id)
            ->where('receptor_persona_id', $cuenta->persona_id)
            ->noLeidos()
            ->update(['leido' => true]);

        return response()->json([
            'message' => 'Mensajes marcados como leídos.',
        ]);
    }

    private function authorizeAccess(Request $request, Viaje $viaje): void
    {
        $cuenta = $request->user();

        $esParticipante = ($cuenta->persona_id === $viaje->cliente_id) || ($cuenta->persona_id === $viaje->conductor_id);

        if (! $esParticipante && $cuenta->rol->value !== 'administrador') {
            abort(Response::HTTP_FORBIDDEN, 'No tiene acceso a los mensajes de este viaje.');
        }
    }
}