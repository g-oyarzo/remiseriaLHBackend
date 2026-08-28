<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('viajes', function (Blueprint $table) {
            // Índice compuesto para consultas de viajes pendientes/activos por conductor (Conductor::scopeDisponibles)
            $table->index(['conductor_id', 'estado'], 'viajes_conductor_estado_index');
            
            // Índice explícito en cliente_id para consultas frecuentes (viajes por cliente)
            $table->index('cliente_id', 'viajes_cliente_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('viajes', function (Blueprint $table) {
            $table->dropIndex('viajes_conductor_estado_index');
            $table->dropIndex('viajes_cliente_id_index');
        });
    }
};
