<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RolPersona;
use App\Models\Cuenta;
use App\Models\Persona;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Cuenta>
 */
class CuentaFactory extends Factory
{
    protected $model = Cuenta::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'persona_id' => Persona::factory(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'rol' => RolPersona::Cliente,
            'email_verified_at' => now(),
        ];
    }

    public function rol(RolPersona $rol): static
    {
        return $this->state(fn (array $attributes): array => ['rol' => $rol]);
    }
}