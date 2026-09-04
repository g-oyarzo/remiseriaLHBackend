<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RolPersona;
use App\Models\Administrador;
use App\Models\Cuenta;
use App\Models\Persona;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $persona = Persona::query()->firstOrCreate(
            ['dni' => '00000000'],
            [
                'nombre' => 'Administrador',
                'apellido' => 'Remisería LH',
                'telefono' => '2211234567',
            ]
        );

        Administrador::query()->firstOrCreate(['persona_id' => $persona->id]);

        Cuenta::query()->firstOrCreate(
            ['email' => 'admin@remiserialh.com'],
            [
                'persona_id' => $persona->id,
                'password' => Hash::make('password'),
                'rol' => RolPersona::Administrador,
                'email_verified_at' => now(),
            ]
        );
    }
}