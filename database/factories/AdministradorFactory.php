<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Administrador;
use App\Models\Persona;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Administrador>
 */
class AdministradorFactory extends Factory
{
    protected $model = Administrador::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'persona_id' => Persona::factory(),
        ];
    }
}