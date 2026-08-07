<?php

declare(strict_types=1);

use App\Enums\EstadoConductor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conductores', function (Blueprint $table) {
            $table->foreignId('persona_id')
                ->constrained('personas')
                ->cascadeOnDelete();

            $table->primary('persona_id');

            $table->string('cuil', 15)->unique();
            $table->date('fecha_nacimiento');
            $table->string('domicilio_localidad', 100);
            $table->string('domicilio_calle', 100);
            $table->string('domicilio_numero', 15);
            $table->string('foto')->nullable();
            $table->decimal('calificacion', 3, 2)->nullable();

            $table->enum('estado', array_column(EstadoConductor::cases(), 'value'))
                ->default(EstadoConductor::Activo->value);

            $table->boolean('en_servicio')->default(false);

            // Último ping GPS del dispositivo del conductor (RNF02).
            // MySQL no permite SPATIAL INDEX sobre columnas nulables, por lo
            // que la columna es NOT NULL y se inicializa en la sede central
            // de la remisería en La Plata hasta que llegue el primer ping.
            $table->geography('ubicacion_actual', subtype: 'point', srid: 4326)
                ->default(DB::raw("(ST_GeomFromText('POINT(-57.9544 -34.9214)', 4326))"));

            $table->foreignId('vehiculo_id')
                ->nullable()
                ->constrained('vehiculos')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('estado');
            $table->index('en_servicio');
            $table->spatialIndex('ubicacion_actual');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conductores');
    }
};
