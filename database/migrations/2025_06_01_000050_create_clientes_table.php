<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patrón de herencia por tabla de subtipo: `persona_id` es a la vez
     * clave primaria y clave foránea hacia `personas.id`. Esto evita
     * duplicar nombre/apellido/dni/teléfono y respeta la generalización
     * Persona -> Cliente del DER.
     */
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->foreignId('persona_id')
                ->constrained('personas')
                ->cascadeOnDelete();

            $table->primary('persona_id');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
