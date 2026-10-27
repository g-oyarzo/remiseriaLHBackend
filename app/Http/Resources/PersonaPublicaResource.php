<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\RolPersona;
use App\Models\Persona;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Datos de una Persona que pueden mostrarse a la contraparte de un viaje
 * (CU 04 / CU 18): solo nombre y apellido. DNI y teléfono quedan reservados
 * para administradores y para la propia persona.
 *
 * @mixin Persona
 */
class PersonaPublicaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $cuenta = $request->user();
        $esAdmin = $cuenta?->rol === RolPersona::Administrador;
        $esPropia = $cuenta !== null && $cuenta->persona_id === $this->id;

        if ($esAdmin || $esPropia) {
            return $this->resource->attributesToArray();
        }

        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'apellido' => $this->apellido,
        ];
    }
}
