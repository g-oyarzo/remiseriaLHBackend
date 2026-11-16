<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seguimiento del recorrido real durante un viaje en curso: distancia
 * acumulada entre pings GPS consecutivos y momento de inicio. Se usan al
 * finalizar el viaje para calcular distancia, duración y precio final.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viajes', function (Blueprint $table) {
            $table->dateTime('iniciado_en')->nullable()->after('busqueda_agotada_en');
            $table->unsignedInteger('distancia_recorrida_metros')->default(0)->after('iniciado_en');
            $table->unsignedInteger('puntos_seguimiento')->default(0)->after('distancia_recorrida_metros');
        });
    }

    public function down(): void
    {
        Schema::table('viajes', function (Blueprint $table) {
            $table->dropColumn(['iniciado_en', 'distancia_recorrida_metros', 'puntos_seguimiento']);
        });
    }
};
