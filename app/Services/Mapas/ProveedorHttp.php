<?php

declare(strict_types=1);

namespace App\Services\Mapas;

use App\ValueObjects\Coordinate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Base común de los proveedores HTTP: timeout corto, reintentos acotados,
 * cache por par de coordenadas y validación de la respuesta.
 *
 * Los errores se registran sin la URL ni el cuerpo de la respuesta, porque
 * ambos pueden contener credenciales o coordenadas de pasajeros.
 */
abstract class ProveedorHttp implements ProveedorDistancia
{
    /** Cota de cordura: una ruta de más de 2.000 km no es un viaje de remís. */
    private const DISTANCIA_MAXIMA_METROS = 2_000_000;

    abstract protected function nombre(): string;

    /**
     * Ejecuta la consulta y devuelve [distanciaMetros, duracionSegundos].
     *
     * @return array{0: mixed, 1: mixed}
     */
    abstract protected function consultar(PendingRequest $http, Coordinate $origen, Coordinate $destino): array;

    public function calcularRuta(Coordinate $origen, Coordinate $destino): Ruta
    {
        $minutos = (int) config('services.mapas.cache_minutos');

        if ($minutos <= 0) {
            return $this->calcularSinCache($origen, $destino);
        }

        // ~1 m de precisión: dos cotizaciones del mismo trayecto en pocos
        // minutos no vuelven a consumir cuota del proveedor.
        $clave = sprintf(
            'mapas:%s:%.5F,%.5F:%.5F,%.5F',
            $this->nombre(), $origen->lat, $origen->lng, $destino->lat, $destino->lng,
        );

        return Cache::remember($clave, now()->addMinutes($minutos), fn (): Ruta => $this->calcularSinCache($origen, $destino));
    }

    private function calcularSinCache(Coordinate $origen, Coordinate $destino): Ruta
    {
        $timeout = (float) config('services.mapas.timeout_segundos');
        $http = Http::acceptJson()
            ->timeout($timeout)
            ->connectTimeout($timeout)
            ->retry(
                max(1, 1 + (int) config('services.mapas.reintentos')),
                200,
                fn (Throwable $e): bool => $e instanceof ConnectionException,
                throw: false,
            );

        try {
            [$metros, $segundos] = $this->consultar($http, $origen, $destino);
        } catch (ServicioMapasNoDisponible $e) {
            $this->registrarFalla($e->getMessage());

            throw $e;
        } catch (Throwable $e) {
            // ConnectionException incluye la URL completa en el mensaje.
            $this->registrarFalla(class_basename($e));

            throw new ServicioMapasNoDisponible("El servicio de mapas {$this->nombre()} no respondió.");
        }

        if (! is_numeric($metros) || ! is_numeric($segundos)
            || $metros < 0 || $segundos < 0 || $metros > self::DISTANCIA_MAXIMA_METROS) {
            $this->registrarFalla('respuesta con valores inválidos');

            throw new ServicioMapasNoDisponible("El servicio de mapas {$this->nombre()} devolvió valores inválidos.");
        }

        return new Ruta((int) round((float) $metros), (int) round((float) $segundos), $this->nombre());
    }

    protected function exigirExito(Response $respuesta): Response
    {
        if (! $respuesta->successful()) {
            throw new ServicioMapasNoDisponible("El servicio de mapas {$this->nombre()} respondió HTTP {$respuesta->status()}.");
        }

        return $respuesta;
    }

    private function registrarFalla(string $motivo): void
    {
        Log::warning('Falla del servicio de mapas; se usará la contingencia por zonas.', [
            'proveedor' => $this->nombre(),
            'motivo' => $motivo,
        ]);
    }
}
