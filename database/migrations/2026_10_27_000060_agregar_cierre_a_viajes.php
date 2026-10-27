<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos del cierre de un viaje (CU 06): distancia y duración finales, monto
 * final a cobrar y de dónde surge ese monto, y a qué distancia del destino
 * estaba el conductor al finalizar (auditoría de la geocerca de 100 m).
 *
 * costo_final_origen:
 * - recorrido: fórmula de la tarifa del viaje sobre la distancia seguida
 *   por GPS.
 * - estimado: el seguimiento GPS fue insuficiente; se cobra lo cotizado.
 * - zona: el viaje se cotizó con monto fijo de zona tarifaria (contingencia)
 *   y se respeta ese monto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viajes', function (Blueprint $table) {
            $table->dateTime('finalizado_en')->nullable()->after('puntos_seguimiento');
            $table->decimal('distancia_final_km', 8, 3)->nullable()->after('finalizado_en');
            $table->unsignedSmallInteger('duracion_final_min')->nullable()->after('distancia_final_km');
            $table->decimal('costo_final', 10, 2)->nullable()->after('duracion_final_min');
            $table->enum('costo_final_origen', ['recorrido', 'estimado', 'zona'])->nullable()->after('costo_final');
            $table->unsignedInteger('distancia_al_destino_metros')->nullable()->after('costo_final_origen');
        });
    }

    public function down(): void
    {
        Schema::table('viajes', function (Blueprint $table) {
            $table->dropColumn([
                'finalizado_en', 'distancia_final_km', 'duracion_final_min',
                'costo_final', 'costo_final_origen', 'distancia_al_destino_metros',
            ]);
        });
    }
};
