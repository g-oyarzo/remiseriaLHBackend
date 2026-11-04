<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cobros con Mercado Pago (decisión del negocio: pagos digitales confirmados
 * automáticamente por webhooks).
 *
 * - proveedor: "mercadopago" para cobros iniciados por el backend; NULL
 *   para efectivo o cobros digitales registrados a mano.
 * - preferencia_id / enlace_pago / preferencia_expira_en: preferencia de
 *   Checkout Pro creada con el importe del viaje (el enlace se muestra al
 *   pasajero como QR o link).
 * - estado "reintegrado": un cobro confirmado que el proveedor devolvió
 *   (reintegro o contracargo). No suma a la facturación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->string('proveedor', 30)->nullable()->after('referencia_externa');
            $table->string('preferencia_id', 100)->nullable()->unique()->after('proveedor');
            $table->string('enlace_pago', 500)->nullable()->after('preferencia_id');
            $table->dateTime('preferencia_expira_en')->nullable()->after('enlace_pago');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE pagos MODIFY estado ENUM('pendiente', 'confirmado', 'rechazado', 'reintegrado') NOT NULL DEFAULT 'pendiente'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('pagos')->where('estado', 'reintegrado')->update(['estado' => 'rechazado']);
            DB::statement("ALTER TABLE pagos MODIFY estado ENUM('pendiente', 'confirmado', 'rechazado') NOT NULL DEFAULT 'pendiente'");
        }

        Schema::table('pagos', function (Blueprint $table) {
            $table->dropUnique(['preferencia_id']);
            $table->dropColumn(['proveedor', 'preferencia_id', 'enlace_pago', 'preferencia_expira_en']);
        });
    }
};
