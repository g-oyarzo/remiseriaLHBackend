<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoPago;
use App\Enums\MetodoPago;
use App\Models\Pago;
use App\Models\Viaje;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pago>
 */
class PagoFactory extends Factory
{
    protected $model = Pago::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'viaje_id' => Viaje::factory(),
            'metodo_pago' => $this->faker->randomElement(MetodoPago::cases()),
            'monto' => $this->faker->randomFloat(2, 1500, 6000),
            'estado' => EstadoPago::Confirmado,
            'fecha_pago' => now(),
        ];
    }
}