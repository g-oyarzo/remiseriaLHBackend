<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * CU 09 - Programar reserva: además de los datos de una solicitud, una
 * fecha y hora futuras dentro del rango configurado.
 */
class ReservarViajeRequest extends SolicitarViajeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'fecha_viaje' => array_merge(['required'], self::reglasFechaReserva()),
        ];
    }

    /**
     * @return list<string>
     */
    public static function reglasFechaReserva(): array
    {
        $minima = now()->addMinutes((int) config('remiseria.reservas.anticipacion_minima_minutos'));
        $maxima = now()->addDays((int) config('remiseria.reservas.anticipacion_maxima_dias'));

        return [
            'date',
            'after_or_equal:'.$minima->toDateTimeString(),
            'before_or_equal:'.$maxima->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha_viaje.after_or_equal' => 'La reserva debe hacerse con al menos '.config('remiseria.reservas.anticipacion_minima_minutos').' minutos de anticipación; para viajar antes, solicite un viaje inmediato.',
            'fecha_viaje.before_or_equal' => 'La reserva no puede hacerse con más de '.config('remiseria.reservas.anticipacion_maxima_dias').' días de anticipación.',
        ];
    }

    public function fechaReserva(): CarbonInterface
    {
        return CarbonImmutable::parse($this->validated('fecha_viaje'))->setTimezone(config('app.timezone'));
    }
}
