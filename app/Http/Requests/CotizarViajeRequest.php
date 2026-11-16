<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\ValueObjects\Coordinate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Origen y destino de un viaje. Las solicitudes, reservas y registros
 * telefónicos extienden estas reglas con sus propios campos.
 */
class CotizarViajeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'origen_lat' => ['required', 'numeric', 'between:-90,90'],
            'origen_lng' => ['required', 'numeric', 'between:-180,180'],
            'destino_lat' => ['required', 'numeric', 'between:-90,90'],
            'destino_lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    public function origen(): Coordinate
    {
        return new Coordinate(lat: (float) $this->validated('origen_lat'), lng: (float) $this->validated('origen_lng'));
    }

    public function destino(): Coordinate
    {
        return new Coordinate(lat: (float) $this->validated('destino_lat'), lng: (float) $this->validated('destino_lng'));
    }
}
