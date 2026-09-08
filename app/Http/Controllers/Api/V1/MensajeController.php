<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Mensaje;
use App\Models\Viaje;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MensajeController extends Controller
{
    /**
     * GET /api/v1/viajes/{viaje}/mensajes
     *
     * Obtiene los mensajes de un viaje.
     */
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

        return response()->json([
            'message' => 'Mensaje enviado.',
            'data' => $mensaje,
        ], Response::HTTP_CREATED);
    }

    /**
     * PATCH /api/v1/viajes/{viaje}/mensajes/marcar-leidos
     */
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
