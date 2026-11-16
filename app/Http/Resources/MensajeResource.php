<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Mensaje;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Mensaje de chat con emisor/receptor reducidos a datos públicos: antes se
 * incluía la Persona completa (DNI y teléfono) de ambas partes.
 *
 * @mixin Mensaje
 */
class MensajeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge($this->resource->attributesToArray(), [
            'emisor' => $this->whenLoaded('emisor', fn () => PersonaPublicaResource::make($this->emisor)->resolve($request)),
            'receptor' => $this->whenLoaded('receptor', fn () => PersonaPublicaResource::make($this->receptor)->resolve($request)),
        ]);
    }
}
