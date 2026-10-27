<?php

declare(strict_types=1);

use App\Enums\EstadoAsignacion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ofertas de viaje a conductores del algoritmo de asignación (CU 03).
 *
 * Invariantes garantizadas por la base de datos (además de los bloqueos de
 * fila del servicio):
 * - unique(viaje_id, conductor_id): a un conductor que rechazó o dejó
 *   vencer una oferta nunca se le vuelve a ofrecer el mismo viaje.
 * - a lo sumo una oferta pendiente por viaje y una por conductor: MySQL no
 *   tiene índices parciales, así que se usan columnas generadas que valen
 *   NULL salvo cuando la oferta está pendiente (unique admite varios NULL).
 *   Son VIRTUAL y no STORED porque MySQL no admite columnas generadas
 *   STORED sobre columnas con claves foráneas ON DELETE CASCADE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asignaciones_viaje', function (Blueprint $table) {
            $table->id();
            $table->foreignId('viaje_id')->constrained('viajes')->cascadeOnDelete();
            $table->foreignId('conductor_id')->constrained('conductores', 'persona_id')->cascadeOnDelete();
            $table->enum('estado', array_column(EstadoAsignacion::cases(), 'value'))
                ->default(EstadoAsignacion::Pendiente->value);
            $table->unsignedSmallInteger('intento');
            $table->unsignedInteger('distancia_metros')->nullable();
            $table->decimal('puntaje', 10, 4)->nullable();
            $table->dateTime('expira_en');
            $table->dateTime('respondida_en')->nullable();
            $table->timestamps();

            $table->unique(['viaje_id', 'conductor_id']);
            $table->index(['estado', 'expira_en']);
            $table->index(['conductor_id', 'estado']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE asignaciones_viaje
                ADD COLUMN viaje_pendiente_id BIGINT UNSIGNED AS (IF(estado = 'pendiente', viaje_id, NULL)) VIRTUAL,
                ADD COLUMN conductor_pendiente_id BIGINT UNSIGNED AS (IF(estado = 'pendiente', conductor_id, NULL)) VIRTUAL,
                ADD UNIQUE INDEX asignaciones_una_pendiente_por_viaje (viaje_pendiente_id),
                ADD UNIQUE INDEX asignaciones_una_pendiente_por_conductor (conductor_pendiente_id)");
        }

        Schema::table('conductores', function (Blueprint $table) {
            // Momento del último ping GPS: una ubicación vieja no se usa
            // para asignar viajes por cercanía.
            $table->dateTime('ubicacion_actualizada_en')->nullable()->after('ubicacion_actual');
            $table->index('ubicacion_actualizada_en');
        });

        Schema::table('viajes', function (Blueprint $table) {
            // Se completa cuando se agotan los candidatos o los reintentos;
            // el viaje queda solicitado y el administrador decide.
            $table->dateTime('busqueda_agotada_en')->nullable()->after('despachado_en');
        });
    }

    public function down(): void
    {
        Schema::table('viajes', function (Blueprint $table) {
            $table->dropColumn('busqueda_agotada_en');
        });

        Schema::table('conductores', function (Blueprint $table) {
            $table->dropIndex(['ubicacion_actualizada_en']);
            $table->dropColumn('ubicacion_actualizada_en');
        });

        Schema::dropIfExists('asignaciones_viaje');
    }
};
