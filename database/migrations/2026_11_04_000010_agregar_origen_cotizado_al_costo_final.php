<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Decisión del negocio: el pasajero paga siempre lo cotizado (precio
 * cerrado). costo_final_origen pasa a valer "cotizado" en los viajes nuevos;
 * los valores anteriores se conservan para no alterar filas existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE viajes MODIFY costo_final_origen ENUM('cotizado', 'recorrido', 'estimado', 'zona') NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('viajes')->where('costo_final_origen', 'cotizado')->update(['costo_final_origen' => 'estimado']);
            DB::statement("ALTER TABLE viajes MODIFY costo_final_origen ENUM('recorrido', 'estimado', 'zona') NULL");
        }
    }
};
