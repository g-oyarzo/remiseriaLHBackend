<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Marca;
use Illuminate\Database\Seeder;

class MarcaSeeder extends Seeder
{
    public function run(): void
    {
        $marcas = [
            'Chevrolet',
            'Ford',
            'Volkswagen',
            'Renault',
            'Fiat',
            'Peugeot',
            'Toyota',
            'Citroën',
            'Honda',
            'Nissan',
        ];

        foreach ($marcas as $nombre) {
            Marca::query()->firstOrCreate(['nombre' => $nombre]);
        }
    }
}