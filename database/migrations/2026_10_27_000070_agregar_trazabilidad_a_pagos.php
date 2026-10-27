<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad e integridad de los cobros (CU 07):
 *
 * - referencia_externa: número de operación del QR/terminal informado por
 *   el conductor. unique(metodo_pago, referencia_externa) impide reutilizar
 *   el mismo comprobante para dos viajes (MySQL admite varios NULL, así que
 *   no afecta a los pagos en efectivo).
 * - registrado_por / conciliado_por / conciliado_en: quién registró el
 *   cobro y quién lo confirmó o rechazó contra el proveedor.
 * - historial: transiciones del pago (estado, método, monto, referencia,
 *   quién y cuándo), para no perder la traza de un cobro rechazado que
 *   luego se vuelve a registrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->string('referencia_externa', 100)->nullable()->after('monto');
            $table->foreignId('registrado_por_persona_id')->nullable()->after('estado')
                ->constrained('personas')->nullOnDelete();
            $table->foreignId('conciliado_por_persona_id')->nullable()->after('registrado_por_persona_id')
                ->constrained('personas')->nullOnDelete();
            $table->dateTime('conciliado_en')->nullable()->after('conciliado_por_persona_id');
            $table->string('observacion', 255)->nullable()->after('conciliado_en');
            $table->json('historial')->nullable()->after('observacion');

            $table->unique(['metodo_pago', 'referencia_externa'], 'pagos_referencia_unica');
            $table->index('fecha_pago');
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropUnique('pagos_referencia_unica');
            $table->dropIndex(['fecha_pago']);
            $table->dropConstrainedForeignId('conciliado_por_persona_id');
            $table->dropConstrainedForeignId('registrado_por_persona_id');
            $table->dropColumn(['referencia_externa', 'conciliado_en', 'observacion', 'historial']);
        });
    }
};
