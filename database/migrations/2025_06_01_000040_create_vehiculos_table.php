<?php

declare(strict_types=1);

use App\Enums\EstadoVehiculo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehiculos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('marca_id')
                ->constrained('marcas')
                ->restrictOnDelete();

            $table->string('modelo', 60);
            $table->string('patente', 10)->unique();
            $table->string('color', 30);
            $table->unsignedSmallInteger('anio');

            $table->enum('estado', array_column(EstadoVehiculo::cases(), 'value'))
                ->default(EstadoVehiculo::Operando->value);

            $table->timestamps();

            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehiculos');
    }
};
