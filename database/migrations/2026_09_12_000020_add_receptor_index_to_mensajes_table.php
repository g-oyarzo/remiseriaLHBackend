<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrección de auditoría (HALL-012).
 *
 * Nota: al revisar el esquema actual, la mayoría de los índices que pedía
 * el hallazgo original ya existen (viajes.estado, viajes.conductor_id,
 * viajes.cliente_id, viajes.fecha_viaje y compuestos, conductores.en_servicio
 * — ver 2025_06_01_000090_create_viajes_table.php y
 * 2025_06_01_000060_create_conductores_table.php). El único que realmente
 * faltaba es mensajes.receptor_persona_id, usado en
 * MensajeController::marcarLeidos() (WHERE receptor_persona_id = ? AND
 * leido = false) y en Persona::mensajesRecibidos().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mensajes', function (Blueprint $table) {
            $table->index(['receptor_persona_id', 'leido']);
        });
    }

    public function down(): void
    {
        Schema::table('mensajes', function (Blueprint $table) {
            $table->dropIndex(['receptor_persona_id', 'leido']);
        });
    }
};
