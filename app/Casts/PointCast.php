<?php

declare(strict_types=1);

namespace App\Casts;

use App\ValueObjects\Coordinate;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Castea columnas MySQL `geography(... , subtype: 'point', srid: 4326)`
 * hacia/desde App\ValueObjects\Coordinate.
 *
 * Lectura: el driver PDO devuelve las columnas espaciales de MySQL como
 * binario WKB precedido por 4 bytes de SRID. Se decodifica manualmente.
 *
 * Escritura: se devuelve una expresión SQL cruda con ST_GeomFromText(), que
 * Eloquent inserta/actualiza embebida en el SQL (no como parámetro ligado),
 * evitando el error "SRID does not match column SRID".
 *
 * @implements CastsAttributes<Coordinate|null, Coordinate|array{lat: float|string, lng: float|string}|null>
 */
final class PointCast implements CastsAttributes
{
    /**
     * Expresión SQL (con un placeholder para el WKT) que construye un punto
     * SRID 4326 a partir de Coordinate::toWkt().
     *
     * En MySQL 8 el SRS geográfico 4326 tiene orden de ejes latitud-longitud,
     * así que sin la opción 'axis-order=long-lat' el texto POINT(lng lat)
     * se interpretaba con los ejes invertidos: las coordenadas quedaban
     * guardadas al revés y ST_Distance_Sphere() devolvía distancias erróneas
     * (un desplazamiento real de ~1,1 km en latitud se medía como ~590 m).
     */
    public const SQL_PUNTO_DESDE_WKT = "ST_GeomFromText(?, 4326, 'axis-order=long-lat')";

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Coordinate
    {
        if ($value === null) {
            return null;
        }

        $binario = is_resource($value) ? stream_get_contents($value) : $value;

        if (! is_string($binario) || strlen($binario) < 25) {
            return null;
        }

        // Formato devuelto por MySQL para columnas POINT:
        // 4 bytes de SRID + 1 byte de orden de bytes + 4 bytes de tipo de
        // geometría + 8 bytes X (double) + 8 bytes Y (double).
        $datos = unpack('x4/Corden/Vtipo/ex/ey', $binario);

        if ($datos === false) {
            return null;
        }

        // En WKT, POINT(X Y) equivale a POINT(longitud latitud).
        return new Coordinate(lat: $datos['y'], lng: $datos['x']);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?Expression
    {
        if ($value === null) {
            return null;
        }

        $coordenada = match (true) {
            $value instanceof Coordinate => $value,
            is_array($value) && isset($value['lat'], $value['lng']) => Coordinate::fromArray($value),
            default => throw new InvalidArgumentException(
                'El valor asignado a un campo de tipo Point debe ser una instancia de '
                .Coordinate::class.' o un arreglo ["lat" => float, "lng" => float].'
            ),
        };

        // toWkt() solo produce dígitos, signo y punto decimal (formato %F),
        // por lo que embeber el valor en el SQL no abre una vía de inyección.
        return DB::raw(str_replace('?', "'{$coordenada->toWkt()}'", self::SQL_PUNTO_DESDE_WKT));
    }
}