<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige las coordenadas guardadas con los ejes invertidos.
 *
 * Hasta esta versión PointCast escribía ST_GeomFromText('POINT(lng lat)', 4326)
 * sin la opción 'axis-order=long-lat'. Con el SRS geográfico 4326 MySQL 8
 * interpreta el texto como (lat lng), así que cada punto quedó guardado con
 * latitud y longitud intercambiadas, y lo mismo ocurría con el DEFAULT de
 * conductores.ubicacion_actual.
 *
 * ST_SwapXY() intercambia los ejes de las filas existentes, y se redefine el
 * DEFAULT con el orden correcto. La operación es su propia inversa, por lo
 * que down() vuelve exactamente al estado anterior.
 *
 * Importante: ejecutar esta migración ANTES de servir tráfico con la versión
 * corregida de PointCast; si se escribieran filas nuevas con el cast
 * corregido y luego se migrara, esas filas quedarían invertidas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->intercambiarEjes();

        DB::statement("ALTER TABLE conductores ALTER COLUMN ubicacion_actual SET DEFAULT (ST_GeomFromText('POINT(-57.9544 -34.9214)', 4326, 'axis-order=long-lat'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->intercambiarEjes();

        DB::statement("ALTER TABLE conductores ALTER COLUMN ubicacion_actual SET DEFAULT (ST_GeomFromText('POINT(-57.9544 -34.9214)', 4326))");
    }

    private function intercambiarEjes(): void
    {
        DB::transaction(function (): void {
            DB::statement('UPDATE viajes SET origen = ST_SwapXY(origen), destino = ST_SwapXY(destino)');
            DB::statement('UPDATE conductores SET ubicacion_actual = ST_SwapXY(ubicacion_actual)');
        });
    }
};
