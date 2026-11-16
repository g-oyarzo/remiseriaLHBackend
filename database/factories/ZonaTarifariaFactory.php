<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ZonaTarifaria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ZonaTarifaria>
 */
class ZonaTarifariaFactory extends Factory
{
    protected $model = ZonaTarifaria::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => 'Zona '.$this->faker->unique()->numberBetween(1, 9999),
            'distancia_max_km' => $this->faker->randomFloat(2, 2, 30),
            'monto' => $this->faker->randomFloat(2, 2000, 15000),
            'activa' => true,
        ];
    }
}
