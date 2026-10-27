<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * CU 10 - Registrar solicitud de viaje telefónica. El cliente se indica por
 * cliente_id (cliente existente) o por sus datos identificatorios (cliente
 * no registrado). Con fecha_viaje se registra como reserva.
 */
class RegistrarViajeTelefonicoRequest extends SolicitarViajeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'cliente_id' => ['nullable', 'integer', 'exists:clientes,persona_id', 'prohibits:cliente', 'required_without:cliente'],
            'cliente' => ['nullable', 'array', 'required_without:cliente_id'],
            'cliente.dni' => ['required_with:cliente', 'string', 'regex:/^\d{1,2}\.?\d{3}\.?\d{3}$/'],
            'cliente.nombre' => ['required_with:cliente', 'string', 'max:100'],
            'cliente.apellido' => ['required_with:cliente', 'string', 'max:100'],
            'cliente.telefono' => ['required_with:cliente', 'string', 'max:30', 'regex:/^\+?[\d\s\-()]{6,30}$/'],
            'fecha_viaje' => array_merge(['nullable'], ReservarViajeRequest::reglasFechaReserva()),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cliente.dni.regex' => 'El DNI debe tener entre 7 y 8 dígitos.',
        ];
    }

    public function fechaReserva(): ?CarbonInterface
    {
        $fecha = $this->validated('fecha_viaje');

        return $fecha !== null ? CarbonImmutable::parse($fecha)->setTimezone(config('app.timezone')) : null;
    }
}
