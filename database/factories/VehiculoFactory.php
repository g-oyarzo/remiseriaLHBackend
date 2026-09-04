<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoVehiculo;
use App\Models\Marca;
use App\Models\Vehiculo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehiculo>
 */
class VehiculoFactory extends Factory
{
    protected $model = Vehiculo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'marca_id' => Marca::factory(),
            'modelo' => $this->faker->word(),
            'patente' => strtoupper($this->faker->unique()->bothify('??###??')),
            'color' => $this->faker->safeColorName(),
            'anio' => $this->faker->numberBetween(2010, 2025),
            'estado' => EstadoVehiculo::Operando,
        ];
    }
}