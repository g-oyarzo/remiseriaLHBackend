<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - tarifas.creada_por_persona_id: trazabilidad de quién configuró cada
 *   versión de la tarifa (las versiones anteriores se conservan, CU 24).
 * - zonas_tarifarias: montos fijos por banda de distancia en línea recta,
 *   usados por el cotizador como contingencia cuando el servicio externo
 *   de mapas falla o no responde a tiempo (CU 02, excepción 1b).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tarifas', function (Blueprint $table) {
            $table->foreignId('creada_por_persona_id')
                ->nullable()
                ->after('vigente_hasta')
                ->constrained('personas')
                ->nullOnDelete();
        });

        Schema::create('zonas_tarifarias', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 60)->unique();
            // Límite superior (inclusive) de la banda, medido en línea recta
            // entre origen y destino.
            $table->decimal('distancia_max_km', 6, 2);
            $table->decimal('monto', 10, 2);
            $table->boolean('activa')->default(true);
            $table->foreignId('actualizada_por_persona_id')
                ->nullable()
                ->constrained('personas')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['activa', 'distancia_max_km']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zonas_tarifarias');

        Schema::table('tarifas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('creada_por_persona_id');
        });
    }
};
