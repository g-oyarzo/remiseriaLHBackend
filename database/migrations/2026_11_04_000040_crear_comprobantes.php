<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de comprobantes internos emitidos (decisión del negocio: por
 * ahora no hay facturación AFIP; la administración consulta y descarga el
 * registro, sin modificarlo ni borrarlo).
 *
 * Cada pago confirmado emite un único comprobante (unique pago_id) cuyo PDF
 * queda guardado en un disco privado con su hash SHA-256, para verificar
 * que el archivo no fue alterado. Las claves foráneas son RESTRICT: no se
 * puede borrar un viaje o un pago con comprobante emitido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprobantes', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 20)->unique();
            $table->foreignId('pago_id')->unique()->constrained('pagos')->restrictOnDelete();
            $table->foreignId('viaje_id')->constrained('viajes')->restrictOnDelete();
            $table->decimal('total', 10, 2);
            $table->string('metodo_pago', 30);
            $table->string('cliente_nombre', 201)->nullable();
            $table->string('conductor_nombre', 201)->nullable();
            $table->dateTime('emitido_en');
            $table->string('archivo');
            $table->char('sha256', 64);
            $table->timestamps();

            $table->index('emitido_en');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobantes');
    }
};
