<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoViaje;
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
        description: 'Los últimos `limite` mensajes del viaje (100 por defecto), ordenados del más antiguo al más reciente. Usar "antes_de_id" para pedir la página anterior (mensajes más viejos). Para recibir mensajes nuevos en tiempo real, suscribirse al canal privado viaje.{viajeId} (evento "mensaje.nuevo").',
        tags: ['Mensajes'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'viaje', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'limite', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 100, maximum: 200)),
            new OA\Parameter(name: 'antes_de_id', in: 'query', required: false, description: 'Devuelve mensajes con id menor a este (página anterior/más vieja).', schema: new OA\Schema(type: 'integer')),
        ],
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
        $this->authorize('ver', $viaje);

        // Corrección de auditoría (sección 7, rendimiento): antes se
        // devolvía TODO el historial de mensajes del viaje sin límite
        // (Mensaje::delViaje($viaje->id)->oldest()->get()). Para viajes con
        // chats largos esto puede volverse una respuesta pesada. Se acota a
        // los últimos $limite (100 por defecto) y se permite paginar hacia
        // atrás con "antes_de_id" sin cambiar la forma de la respuesta.
        $validated = $request->validate([
            'limite' => ['nullable', 'integer', 'min:1', 'max:200'],
            'antes_de_id' => ['nullable', 'integer'],
        ]);

        $limite = $validated['limite'] ?? 100;

        $query = Mensaje::query()
            ->with(['emisor', 'receptor'])
            ->delViaje($viaje->id);

        if (! empty($validated['antes_de_id'])) {
            $query->where('id', '<', $validated['antes_de_id']);
        }

        $mensajes = $query->latest('id')
            ->limit($limite)
            ->get()
            ->sortBy('id')
            ->values();

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
            new OA\Response(response: 409, description: 'El viaje todavía no tiene conductor asignado, o no está en un estado activo (aceptado/en_curso).', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function store(Request $request, Viaje $viaje): JsonResponse
    {
        $this->authorize('ver', $viaje);

        if (! $viaje->conductor_id) {
            return response()->json([
                'message' => 'No se puede enviar mensajes a un viaje sin conductor asignado.',
            ], Response::HTTP_CONFLICT);
        }

        // Corrección de auditoría (HALL-015): antes solo se verificaba que
        // el viaje tuviera conductor asignado, sin mirar su estado. Eso
        // permitía seguir chateando en viajes ya cancelados o finalizados
        // (por ejemplo, un cliente insistiendo con un conductor después de
        // cancelar, o mensajes fuera de contexto días después de terminado
        // el viaje).
        if (! in_array($viaje->estado, [EstadoViaje::Aceptado, EstadoViaje::EnCurso], true)) {
            return response()->json([
                'message' => 'Solo se puede chatear en viajes activos (aceptado o en curso).',
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
        $this->authorize('ver', $viaje);

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
}