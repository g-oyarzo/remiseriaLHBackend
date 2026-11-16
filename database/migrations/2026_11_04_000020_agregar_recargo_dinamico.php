<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Precio dinámico (decisión del negocio: "el precio puede variar según la
 * demanda actual y el clima").
 *
 * tarifas: parámetros del recargo, versionados junto con la tarifa base.
 * Los valores por defecto (0 %) no aplican ningún recargo: el negocio los
 * configura desde PUT /admin/tarifas.
 * - demanda_umbral_ratio: solicitudes pendientes por conductor disponible
 *   (cerca del origen) a partir de la cual hay alta demanda. NULL = sin
 *   recargo por demanda.
 * - lluvia_umbral_mm: precipitación en el origen (último intervalo
 *   informado por el servicio de clima) a partir de la cual se aplica el
 *   recargo por clima.
 * - recargo_maximo_pct: tope del recargo combinado.
 *
 * viajes: snapshot del recargo aplicado al cotizar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tarifas', function (Blueprint $table) {
            $table->decimal('demanda_umbral_ratio', 5, 2)->nullable()->after('precio_por_km');
            $table->decimal('demanda_recargo_pct', 5, 2)->default(0)->after('demanda_umbral_ratio');
            $table->decimal('lluvia_umbral_mm', 5, 2)->default(0.1)->after('demanda_recargo_pct');
            $table->decimal('lluvia_recargo_pct', 5, 2)->default(0)->after('lluvia_umbral_mm');
            $table->decimal('recargo_maximo_pct', 5, 2)->default(50)->after('lluvia_recargo_pct');
        });

        Schema::table('viajes', function (Blueprint $table) {
            $table->decimal('monto_base_cotizado', 10, 2)->nullable()->after('costo');
            $table->decimal('recargo_dinamico_pct', 5, 2)->nullable()->after('monto_base_cotizado');
            $table->json('recargo_dinamico_detalle')->nullable()->after('recargo_dinamico_pct');
        });
    }

    public function down(): void
    {
        Schema::table('viajes', function (Blueprint $table) {
            $table->dropColumn(['monto_base_cotizado', 'recargo_dinamico_pct', 'recargo_dinamico_detalle']);
        });

        Schema::table('tarifas', function (Blueprint $table) {
            $table->dropColumn(['demanda_umbral_ratio', 'demanda_recargo_pct', 'lluvia_umbral_mm', 'lluvia_recargo_pct', 'recargo_maximo_pct']);
        });
    }
};
