<?php

declare(strict_types=1);

use App\Enums\EstadoViaje;
use App\Enums\TipoViaje;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viajes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cliente_id')
                ->constrained('clientes', 'persona_id')
                ->restrictOnDelete();

            $table->foreignId('conductor_id')
                ->nullable()
                ->constrained('conductores', 'persona_id')
                ->nullOnDelete();

            $table->foreignId('vehiculo_id')
                ->nullable()
                ->constrained('vehiculos')
                ->nullOnDelete();

            $table->foreignId('tarifa_id')
                ->nullable()
                ->constrained('tarifas')
                ->nullOnDelete();

            // Origen/destino geoespaciales (SRID 4326), obligatorios: se
            // completan siempre al solicitar el viaje, sea desde la app
            // (GPS del cliente) o cargados por el administrador vía
            // geocodificación de la dirección (CU02/CU10).
            $table->geography('origen', subtype: 'point', srid: 4326);
            $table->geography('destino', subtype: 'point', srid: 4326);

            // Componentes de dirección en texto, usados principalmente en
            // solicitudes cargadas manualmente por canales telefónicos (CU10).
            $table->string('origen_localidad', 100)->nullable();
            $table->string('origen_calle', 100)->nullable();
            $table->string('origen_numero', 15)->nullable();

            $table->enum('estado', array_column(EstadoViaje::cases(), 'value'))
                ->default(EstadoViaje::Solicitado->value);

            $table->enum('tipo', array_column(TipoViaje::cases(), 'value'))
                ->default(TipoViaje::Actual->value);

            $table->decimal('costo', 10, 2)->nullable();
            $table->unsignedTinyInteger('calificacion')->nullable();
            $table->dateTime('fecha_viaje');

            $table->timestamps();

            $table->index('estado');
            $table->index('tipo');
            $table->index('fecha_viaje');
            if (DB::getDriverName() !== 'sqlite') {
                $table->spatialIndex('origen');
                $table->spatialIndex('destino');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viajes');
    }
};
