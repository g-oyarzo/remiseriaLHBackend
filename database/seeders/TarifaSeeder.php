<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tarifa;
use Illuminate\Database\Seeder;

class TarifaSeeder extends Seeder
{
    public function run(): void
    {
        // Garantiza que exista una única tarifa activa a la vez.
        Tarifa::query()->where('activa', true)->update(['activa' => false]);

        Tarifa::query()->create([
            'precio_base' => 1500.00,
            'precio_por_km' => 350.00,
            'zona' => 'La Plata',
            'activa' => true,
            'vigente_desde' => now(),
            'vigente_hasta' => null,
        ]);

        Tarifa::olvidarVigenteEnCache();
    }
}