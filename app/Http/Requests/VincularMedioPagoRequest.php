<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Vincular una tarjeta: el frontend la tokeniza con MercadoPago.js (o el
 * SDK móvil) y envía solo el token de un solo uso. Nunca se aceptan número
 * de tarjeta, vencimiento ni CVV.
 */
class VincularMedioPagoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{16,64}$/'],
            'payment_method_id' => ['required', 'string', 'regex:/^[a-z0-9_]{2,30}$/'],
            'tipo' => ['required', 'string', 'in:credit_card,debit_card,prepaid_card'],
            'predeterminado' => ['sometimes', 'boolean'],
            // Consentimiento explícito para guardar la tarjeta.
            'acepto_guardar_tarjeta' => ['accepted'],
            'cobro_automatico' => ['sometimes', 'boolean'],
            // Autorización expresa para cobrar sin CVV al finalizar cada viaje.
            'autorizo_cobro_automatico' => ['accepted_if:cobro_automatico,true,1'],
        ];
    }

    /**
     * @return array{token: string, payment_method_id: string, tipo: string, cobro_automatico: bool, predeterminado: bool}
     */
    public function datos(): array
    {
        return [
            'token' => $this->validated('token'),
            'payment_method_id' => $this->validated('payment_method_id'),
            'tipo' => $this->validated('tipo'),
            'cobro_automatico' => $this->boolean('cobro_automatico'),
            'predeterminado' => $this->boolean('predeterminado'),
        ];
    }
}
