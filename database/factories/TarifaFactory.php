<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tarifa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tarifa>
 */
class TarifaFactory extends Factory
{
    protected $model = Tarifa::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'precio_base' => $this->faker->randomFloat(2, 1000, 2000),
            'precio_por_km' => $this->faker->randomFloat(2, 200, 500),
            'zona' => 'La Plata',
            'activa' => true,
            'vigente_desde' => now(),
            'vigente_hasta' => null,
        ];
    }
}
