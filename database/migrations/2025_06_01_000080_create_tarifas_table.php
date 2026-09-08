<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Las tarifas no se actualizan in-place: cada cambio (CU 24) crea un
     * nuevo registro y desactiva el anterior, preservando el historial
     * exigido por la postcondición de "consistencia transaccional e histórica".
     */
    public function up(): void
    {
        Schema::create('tarifas', function (Blueprint $table) {
            $table->id();
            $table->decimal('precio_base', 10, 2);
            $table->decimal('precio_por_km', 10, 2);
            $table->string('zona', 60)->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamp('vigente_desde')->useCurrent();
            $table->timestamp('vigente_hasta')->nullable();
            $table->timestamps();

            $table->index('activa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tarifas');
    }
};
