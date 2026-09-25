<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrección de auditoría (HALL-008): `fecha_pago` tenía ->useCurrent() y la
 * aplicación nunca la pasaba explícitamente al crear el registro. Eso hacía
 * imposible representar un pago realmente "pendiente" (sin fecha de
 * confirmación) o registrar una fecha de confirmación distinta a la de
 * creación de la fila.
 *
 * Desde este cambio, PagoController::store() fija `fecha_pago` en el momento
 * en que el pago se confirma. La columna pasa a ser nullable y sin default.
 *
 * Nota: se usa DB::statement() con SQL nativo (en lugar de
 * Schema::table(...)->change()) porque este proyecto no tiene instalado
 * doctrine/dbal (requerido por Laravel para el método change()). El proyecto
 * ya depende de características específicas de MySQL (geography, ST_*), así
 * que no se pierde portabilidad real al asumir el driver mysql aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE pagos MODIFY fecha_pago DATETIME NULL DEFAULT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('UPDATE pagos SET fecha_pago = CURRENT_TIMESTAMP WHERE fecha_pago IS NULL');
            DB::statement('ALTER TABLE pagos MODIFY fecha_pago DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
        }
    }
};
