<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoViaje;
use App\Enums\TipoViaje;
use App\Models\Cliente;
use App\Models\Conductor;
use App\Models\Tarifa;
use App\Models\Vehiculo;
use App\Models\Viaje;
use App\ValueObjects\Coordinate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Viaje>
 */
class ViajeFactory extends Factory
{
    protected $model = Viaje::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cliente_id' => Cliente::factory(),
            'conductor_id' => null,
            'vehiculo_id' => null,
            'tarifa_id' => Tarifa::factory(),
            'origen' => new Coordinate(
                lat: (float) $this->faker->latitude(-35.05, -34.85),
                lng: (float) $this->faker->longitude(-58.05, -57.85),
            ),
            'destino' => new Coordinate(
                lat: (float) $this->faker->latitude(-35.05, -34.85),
                lng: (float) $this->faker->longitude(-58.05, -57.85),
            ),
            'origen_localidad' => 'La Plata',
            'origen_calle' => $this->faker->streetName(),
            'origen_numero' => (string) $this->faker->numberBetween(1, 2000),
            'estado' => EstadoViaje::Solicitado,
            'tipo' => TipoViaje::Actual,
            'costo' => $this->faker->randomFloat(2, 1500, 6000),
            'calificacion' => null,
            'fecha_viaje' => now(),
        ];
    }

    public function asignado(): static
    {
        return $this->state(fn (array $attributes): array => [
            'conductor_id' => Conductor::factory(),
            'vehiculo_id' => Vehiculo::factory(),
            'estado' => EstadoViaje::Aceptado,
        ]);
    }

    public function finalizado(): static
    {
        return $this->state(fn (array $attributes): array => [
            'estado' => EstadoViaje::Finalizado,
            'calificacion' => $this->faker->numberBetween(3, 5),
        ]);
    }

    public function cancelado(): static
    {
        return $this->state(fn (array $attributes): array => [
            'estado' => EstadoViaje::Cancelado,
        ]);
    }
}
