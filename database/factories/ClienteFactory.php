<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cliente;
use App\Models\Persona;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cliente>
 */
class ClienteFactory extends Factory
{
    protected $model = Cliente::class;

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
