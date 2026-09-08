<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_las_tablas_esenciales_existen(): void
    {
        $tablas = [
            'personas',
            'cuentas',
            'clientes',
            'conductores',
            'administradores',
            'vehiculos',
            'marcas',
            'viajes',
            'tarifas',
            'pagos',
            'mensajes',
            'personal_access_tokens', // Sanctum
        ];

        foreach ($tablas as $tabla) {
            $this->assertTrue(
                Schema::hasTable($tabla),
                "La tabla '{$tabla}' debería existir en la base de datos."
            );
        }
    }
}
