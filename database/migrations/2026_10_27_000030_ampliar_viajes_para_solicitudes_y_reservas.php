<?php

declare(strict_types=1);

use App\Enums\MetodoCotizacion;
use App\Enums\MetodoPago;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Datos que el flujo de solicitud/reserva necesita guardar en el viaje:
 *
 * - estado "reservado" (CU 09): la reserva queda fuera del despacho hasta
 *   que entra en la ventana configurada antes de fecha_viaje.
 * - metodo_pago elegido por el cliente (CU 21) y dirección de destino en
 *   texto (para viajes cargados por teléfono, CU 10).
 * - canal y administrador que registró el viaje (trazabilidad de CU 10).
 * - snapshot de la cotización: los valores de tarifa, distancia y método
 *   con los que se cotizó, para que un cambio posterior de tarifa no altere
 *   retroactivamente el viaje.
 *
 * Todas las columnas nuevas son nulables: las filas existentes no tienen
 * esos datos y no se inventan.
 */
return new class extends Migration
{
    private const ESTADOS_ANTERIORES = ['solicitado', 'aceptado', 'en_curso', 'finalizado', 'cancelado'];

    private const ESTADOS_NUEVOS = ['reservado', 'solicitado', 'aceptado', 'en_curso', 'finalizado', 'cancelado'];

    public function up(): void
    {
        $this->redefinirEstados(self::ESTADOS_NUEVOS);

        Schema::table('viajes', function (Blueprint $table) {
            $table->string('destino_localidad', 100)->nullable()->after('origen_numero');
            $table->string('destino_calle', 100)->nullable()->after('destino_localidad');
            $table->string('destino_numero', 15)->nullable()->after('destino_calle');

            $table->enum('metodo_pago', array_column(MetodoPago::cases(), 'value'))->nullable()->after('tipo');
            $table->enum('canal', ['app', 'telefono'])->default('app')->after('metodo_pago');
            $table->foreignId('registrado_por_persona_id')
                ->nullable()
                ->after('canal')
                ->constrained('personas')
                ->nullOnDelete();

            $table->decimal('precio_base_aplicado', 10, 2)->nullable()->after('costo');
            $table->decimal('precio_por_km_aplicado', 10, 2)->nullable()->after('precio_base_aplicado');
            $table->decimal('distancia_estimada_km', 8, 3)->nullable()->after('precio_por_km_aplicado');
            $table->unsignedSmallInteger('duracion_estimada_min')->nullable()->after('distancia_estimada_km');
            $table->enum('metodo_cotizacion', array_column(MetodoCotizacion::cases(), 'value'))
                ->nullable()
                ->after('duracion_estimada_min');
            $table->foreignId('zona_tarifaria_id')
                ->nullable()
                ->after('metodo_cotizacion')
                ->constrained('zonas_tarifarias')
                ->nullOnDelete();

            // Momento en que una reserva pasó al despacho (estado solicitado).
            $table->dateTime('despachado_en')->nullable()->after('fecha_viaje');

            $table->index(['estado', 'fecha_viaje'], 'viajes_estado_fecha_index');
        });
    }

    public function down(): void
    {
        Schema::table('viajes', function (Blueprint $table) {
            $table->dropIndex('viajes_estado_fecha_index');
            $table->dropConstrainedForeignId('zona_tarifaria_id');
            $table->dropConstrainedForeignId('registrado_por_persona_id');
            $table->dropColumn([
                'destino_localidad', 'destino_calle', 'destino_numero',
                'metodo_pago', 'canal',
                'precio_base_aplicado', 'precio_por_km_aplicado', 'distancia_estimada_km',
                'duracion_estimada_min', 'metodo_cotizacion', 'despachado_en',
            ]);
        });

        // Las reservas pendientes vuelven a la representación anterior
        // (viaje programado en estado solicitado) para no perderlas.
        DB::table('viajes')->where('estado', 'reservado')->update(['estado' => 'solicitado']);

        $this->redefinirEstados(self::ESTADOS_ANTERIORES);
    }

    /**
     * @param  list<string>  $estados
     */
    private function redefinirEstados(array $estados): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $lista = implode(', ', array_map(fn (string $e): string => "'{$e}'", $estados));

        DB::statement("ALTER TABLE viajes MODIFY estado ENUM({$lista}) NOT NULL DEFAULT 'solicitado'");
    }
};
