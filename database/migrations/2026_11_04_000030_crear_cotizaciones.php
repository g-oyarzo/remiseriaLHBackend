<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cotizaciones emitidas con vigencia (precio cerrado): el monto que el
 * pasajero ve al cotizar es el que se registra al solicitar el viaje con
 * ese cotizacion_id, aunque el recargo dinámico haya cambiado mientras
 * tanto. Cada cotización es de un solo uso y pertenece a la cuenta que la
 * pidió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotizaciones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('cuenta_id')->constrained('cuentas')->cascadeOnDelete();
            $table->decimal('origen_lat', 10, 7);
            $table->decimal('origen_lng', 10, 7);
            $table->decimal('destino_lat', 10, 7);
            $table->decimal('destino_lng', 10, 7);
            $table->boolean('es_reserva')->default(false);
            $table->decimal('monto', 10, 2);
            // Columnas del viaje que se copian al usarla (snapshot completo).
            $table->json('atributos');
            // Respuesta original de la cotización (para auditoría).
            $table->json('respuesta');
            $table->dateTime('expira_en');
            $table->dateTime('usada_en')->nullable();
            $table->foreignId('viaje_id')->nullable()->constrained('viajes')->nullOnDelete();
            $table->timestamps();

            $table->index('expira_en');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotizaciones');
    }
};
