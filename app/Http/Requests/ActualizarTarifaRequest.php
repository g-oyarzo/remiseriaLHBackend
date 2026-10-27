<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Dinero;
use Illuminate\Foundation\Http\FormRequest;

/**
 * CU 24 - Modificar tarifa. La autorización (rol administrador) la impone
 * el middleware role:administrador del grupo de rutas.
 */
class ActualizarTarifaRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'precio_base' => Dinero::reglasMontoPositivo(),
            'precio_por_km' => Dinero::reglasMontoPositivo(),
            'zona' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $mensaje = 'Formato de tarifa inválido: los montos obligatorios deben ser valores numéricos positivos mayores a cero, con hasta dos decimales.';

        return [
            'precio_base.gt' => $mensaje,
            'precio_base.numeric' => $mensaje,
            'precio_base.decimal' => $mensaje,
            'precio_por_km.gt' => $mensaje,
            'precio_por_km.numeric' => $mensaje,
            'precio_por_km.decimal' => $mensaje,
        ];
    }
}
