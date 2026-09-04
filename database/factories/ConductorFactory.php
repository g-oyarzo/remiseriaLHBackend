<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoConductor;
use App\Models\Conductor;
use App\Models\Persona;
use App\ValueObjects\Coordinate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conductor>
 */
class ConductorFactory extends Factory
{
    protected $model = Conductor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'persona_id' => Persona::factory(),
            'cuil' => $this->faker->unique()->numerify('20-########-#'),
            'fecha_nacimiento' => $this->faker->dateTimeBetween('-60 years', '-21 years'),
            'domicilio_localidad' => 'La Plata',
            'domicilio_calle' => $this->faker->streetName(),
            'domicilio_numero' => (string) $this->faker->numberBetween(1, 2000),
            'foto' => null,
            'calificacion' => $this->faker->randomFloat(2, 3.5, 5),
            'estado' => EstadoConductor::Activo,
            'en_servicio' => false,
            'ubicacion_actual' => new Coordinate(
                lat: (float) $this->faker->latitude(-35.05, -34.85),
                lng: (float) $this->faker->longitude(-58.05, -57.85),
            ),
        ];
    }

    public function enServicio(): static
    {
        return $this->state(fn (array $attributes): array => ['en_servicio' => true]);
    }

    public function eliminado(): static
    {
        return $this->state(fn (array $attributes): array => ['estado' => EstadoConductor::Eliminado]);
    }
}