<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\ValueObjects\Coordinate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ping GPS del conductor. "precision_metros" es la precisión que informa el
 * dispositivo (opcional).
 */
class ActualizarUbicacionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'precision_metros' => ['nullable', 'numeric', 'min:0', 'max:10000'],
        ];
    }

    public function coordenada(): Coordinate
    {
        return new Coordinate(lat: (float) $this->validated('lat'), lng: (float) $this->validated('lng'));
    }

    public function precision(): ?float
    {
        $precision = $this->validated('precision_metros');

        return $precision !== null ? (float) $precision : null;
    }
}
