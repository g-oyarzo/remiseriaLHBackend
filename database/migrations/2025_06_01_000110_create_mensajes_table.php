<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mensajes', function (Blueprint $table) {
            $table->id();

            // Nullable para no acoplar el modelo a que TODO mensaje deba
            // pertenecer a un viaje (CU25/CU26 lo requieren, pero el
            // diagrama de clases contempla también mensajería
            // administrador-conductor fuera del contexto de un viaje).
            $table->foreignId('viaje_id')
                ->nullable()
                ->constrained('viajes')
                ->nullOnDelete();

            $table->foreignId('emisor_persona_id')
                ->constrained('personas')
                ->cascadeOnDelete();

            $table->foreignId('receptor_persona_id')
                ->constrained('personas')
                ->cascadeOnDelete();

            $table->text('contenido');
            $table->boolean('leido')->default(false);
            $table->timestamps();

            $table->index(['viaje_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mensajes');
    }
};