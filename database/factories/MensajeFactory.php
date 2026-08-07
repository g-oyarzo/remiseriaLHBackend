<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Mensaje;
use App\Models\Persona;
use App\Models\Viaje;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mensaje>
 */
class MensajeFactory extends Factory
{
    protected $model = Mensaje::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'viaje_id' => Viaje::factory(),
            'emisor_persona_id' => Persona::factory(),
            'receptor_persona_id' => Persona::factory(),
            'contenido' => $this->faker->sentence(),
            'leido' => false,
        ];
    }
}
