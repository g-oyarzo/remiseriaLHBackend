<?php

declare(strict_types=1);

use App\Enums\EstadoPago;
use App\Enums\MetodoPago;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('viaje_id')
                ->unique()
                ->constrained('viajes')
                ->cascadeOnDelete();

            $table->enum('metodo_pago', array_column(MetodoPago::cases(), 'value'));
            $table->decimal('monto', 10, 2);

            $table->enum('estado', array_column(EstadoPago::cases(), 'value'))
                ->default(EstadoPago::Pendiente->value);

            $table->dateTime('fecha_pago')->useCurrent();
            $table->timestamps();

            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};
