<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoAsignacion;
use App\Models\AsignacionViaje;
use App\Models\Conductor;
use App\Models\Viaje;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AsignacionViaje>
 */
class AsignacionViajeFactory extends Factory
{
    protected $model = AsignacionViaje::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'viaje_id' => Viaje::factory(),
            'conductor_id' => Conductor::factory(),
            'estado' => EstadoAsignacion::Pendiente,
            'intento' => 1,
            'distancia_metros' => $this->faker->numberBetween(100, 5000),
            'puntaje' => $this->faker->randomFloat(4, 0, 10),
            'expira_en' => now()->addSeconds(30),
        ];
    }
}
