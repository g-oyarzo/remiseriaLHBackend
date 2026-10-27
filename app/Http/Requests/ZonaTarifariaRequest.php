<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Dinero;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y modificación de una zona tarifaria de contingencia.
 */
class ZonaTarifariaRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $zona = $this->route('zona');

        return [
            'nombre' => ['required', 'string', 'max:60', Rule::unique('zonas_tarifarias', 'nombre')->ignore($zona)],
            'distancia_max_km' => ['required', 'numeric', 'gt:0', 'max:9999.99', 'decimal:0,2'],
            'monto' => Dinero::reglasMontoPositivo(),
            'activa' => ['sometimes', 'boolean'],
        ];
    }
}
