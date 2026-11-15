<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\RolPersona;
use App\Models\Viaje;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialización de un viaje con lista blanca de datos personales.
 *
 * Antes los controladores devolvían el modelo Eloquent completo con sus
 * relaciones, de modo que el cliente recibía CUIL, domicilio, fecha de
 * nacimiento y ubicación GPS del conductor, y el conductor recibía DNI y
 * teléfono del cliente. Se conserva la misma estructura de claves
 * (cliente.persona.nombre, conductor.persona.nombre, vehiculo.patente, ...)
 * para no romper a los frontends, pero la contraparte de un viaje solo ve
 * lo que necesita para identificar a la otra parte (CU 04 / CU 18).
 *
 * @mixin Viaje
 */
class ViajeResource extends JsonResource
{
    /**
     * Serializa un viaje como arreglo plano (misma forma que el JSON previo).
     *
     * @return array<string, mixed>
     */
    public static function arreglo(Viaje $viaje, Request $request): array
    {
        return self::make($viaje)->resolve($request);
    }

    /**
     * Transforma cada elemento de un paginador conservando el formato de
     * paginación de Laravel (current_page, data, per_page, total, ...).
     */
    public static function paginado(LengthAwarePaginator $paginador, Request $request): LengthAwarePaginator
    {
        return $paginador->through(fn (Viaje $viaje): array => self::arreglo($viaje, $request));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $cuenta = $request->user();
        $esAdmin = $cuenta?->rol === RolPersona::Administrador;

        return array_merge($this->resource->attributesToArray(), [
            'cliente' => $this->whenLoaded('cliente', function () use ($request, $esAdmin, $cuenta) {
                $cliente = $this->cliente;

                if ($cliente === null) {
                    return null;
                }

                $completo = $esAdmin || $cuenta?->persona_id === $cliente->persona_id;
                $datos = $completo
                    ? $cliente->attributesToArray()
                    : ['persona_id' => $cliente->persona_id];

                if ($cliente->relationLoaded('persona')) {
                    $datos['persona'] = PersonaPublicaResource::make($cliente->persona)->resolve($request);
                }

                return $datos;
            }),
            'conductor' => $this->whenLoaded('conductor', function () use ($request, $esAdmin, $cuenta) {
                $conductor = $this->conductor;

                if ($conductor === null) {
                    return null;
                }

                $completo = $esAdmin || $cuenta?->persona_id === $conductor->persona_id;
                $datos = $completo
                    ? $conductor->attributesToArray()
                    : [
                        'persona_id' => $conductor->persona_id,
                        'calificacion' => $conductor->calificacion,
                        'foto' => $conductor->foto,
                    ];

                if ($conductor->relationLoaded('persona')) {
                    $datos['persona'] = PersonaPublicaResource::make($conductor->persona)->resolve($request);
                }

                return $datos;
            }),
            'vehiculo' => $this->whenLoaded('vehiculo', fn () => $this->vehiculo?->toArray()),
            'tarifa' => $this->whenLoaded('tarifa', fn () => $this->tarifa?->toArray()),
            // SEC-03: el historial de auditoría y los datos internos del
            // pago son solo para administradores.
            'pago' => $this->whenLoaded('pago', fn () => $esAdmin ? $this->pago?->toArray() : $this->pago?->vistaPublica()),
            'mensajes' => $this->whenLoaded(
                'mensajes',
                fn () => MensajeResource::collection($this->mensajes)->resolve($request),
            ),
        ]);
    }
}
