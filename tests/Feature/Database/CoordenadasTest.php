<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Conductor;
use App\Models\Viaje;
use App\ValueObjects\Coordinate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regresión del orden de ejes en columnas POINT SRID 4326: MySQL 8 interpreta
 * el WKT en orden latitud-longitud salvo que se indique 'axis-order=long-lat'.
 */
class CoordenadasTest extends TestCase
{
    use RefreshDatabase;

    public function test_las_coordenadas_se_guardan_con_la_latitud_y_longitud_correctas(): void
    {
        $origen = new Coordinate(lat: -34.9214, lng: -57.9544);

        $viaje = Viaje::factory()->create(['origen' => $origen]);

        $fila = DB::selectOne('SELECT ST_Latitude(origen) AS lat, ST_Longitude(origen) AS lng FROM viajes WHERE id = ?', [$viaje->id]);

        $this->assertEqualsWithDelta(-34.9214, (float) $fila->lat, 0.000001);
        $this->assertEqualsWithDelta(-57.9544, (float) $fila->lng, 0.000001);
    }

    public function test_la_lectura_devuelve_la_misma_coordenada_que_se_escribio(): void
    {
        $destino = new Coordinate(lat: -34.9100, lng: -57.9500);

        $viaje = Viaje::factory()->create(['destino' => $destino]);

        $leido = $viaje->fresh()->destino;

        $this->assertEqualsWithDelta($destino->lat, $leido->lat, 0.000001);
        $this->assertEqualsWithDelta($destino->lng, $leido->lng, 0.000001);
    }

    public function test_st_distance_sphere_coincide_con_haversine(): void
    {
        $a = new Coordinate(lat: -34.9214, lng: -57.9544);
        $b = new Coordinate(lat: -34.9314, lng: -57.9544);

        $conductor = Conductor::factory()->create(['ubicacion_actual' => $a]);

        $distanciaSql = (float) Conductor::query()
            ->cercanos($b)
            ->whereKey($conductor->persona_id)
            ->value('distancia_metros');

        // ~1.112 m por cada 0,01° de latitud.
        $this->assertEqualsWithDelta($a->distanciaEnMetrosHacia($b), $distanciaSql, 5.0);
        $this->assertEqualsWithDelta(1112.0, $distanciaSql, 5.0);
    }

    public function test_el_default_de_ubicacion_actual_es_la_sede_en_la_plata(): void
    {
        $conductor = Conductor::factory()->create();
        DB::statement('UPDATE conductores SET ubicacion_actual = DEFAULT WHERE persona_id = ?', [$conductor->persona_id]);

        $ubicacion = $conductor->fresh()->ubicacion_actual;

        $this->assertEqualsWithDelta(-34.9214, $ubicacion->lat, 0.000001);
        $this->assertEqualsWithDelta(-57.9544, $ubicacion->lng, 0.000001);
    }
}
