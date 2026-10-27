<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EstadoViaje;
use App\Services\Reportes\FiltrosReporte;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * CU 15 - Parámetros del reporte estadístico. Todos opcionales; sin fechas
 * se toman los últimos 30 días, y el rango no puede superar el máximo
 * configurado para acotar el costo de la consulta.
 */
class ReporteRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fecha_desde' => ['nullable', 'date_format:Y-m-d'],
            'fecha_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_desde'],
            'conductor_dni' => ['nullable', 'string', 'regex:/^\d{1,2}\.?\d{3}\.?\d{3}$/'],
            'estado' => ['nullable', new Enum(EstadoViaje::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha_hasta.after_or_equal' => 'Rango de fechas inválido: la fecha límite final debe ser posterior o igual a la inicial.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $filtros = $this->filtros();
            $maximo = (int) config('remiseria.reportes.rango_maximo_dias');

            if ($filtros->desde->diffInDays($filtros->hasta) > $maximo) {
                $validator->errors()->add('fecha_desde', "El rango de fechas no puede superar los {$maximo} días.");
            }
        });
    }

    public function filtros(): FiltrosReporte
    {
        $zona = config('app.timezone');
        $hasta = $this->input('fecha_hasta')
            ? CarbonImmutable::createFromFormat('Y-m-d', $this->input('fecha_hasta'), $zona)->endOfDay()
            : CarbonImmutable::now($zona)->endOfDay();
        $desde = $this->input('fecha_desde')
            ? CarbonImmutable::createFromFormat('Y-m-d', $this->input('fecha_desde'), $zona)->startOfDay()
            : $hasta->subDays(29)->startOfDay();

        return new FiltrosReporte(
            desde: $desde,
            hasta: $hasta,
            conductorDni: $this->input('conductor_dni'),
            estado: $this->filled('estado') ? EstadoViaje::from($this->input('estado')) : null,
        );
    }
}
