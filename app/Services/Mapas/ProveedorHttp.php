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
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Base común de los proveedores HTTP: timeout corto, reintentos acotados,
 * cache por par de coordenadas, validación de la respuesta, protección de
 * cuota y circuit breaker (ver GuardaDeProveedor).
 *
 * Orden de una consulta:
 * 1. Cache: si el trayecto se consultó hace poco, no se gasta cuota.
 * 2. Circuito abierto (fallas recientes o HTTP 429): no se llama al
 *    proveedor; el cotizador usa la contingencia por zonas.
 * 3. Cuota local agotada (por minuto, día o mes): ídem.
 * 4. Llamada con timeout corto; solo se reintenta ante fallas de conexión,
 *    y cada intento descuenta cuota.
 *
 * Los errores se registran sin la URL ni el cuerpo de la respuesta, porque
 * ambos pueden contener credenciales o coordenadas de pasajeros.
 */
abstract class ProveedorHttp implements ProveedorDistancia
{
    /** Cota de cordura: una ruta de más de 2.000 km no es un viaje de remís. */
    private const DISTANCIA_MAXIMA_METROS = 2_000_000;

    private ?GuardaDeProveedor $guarda = null;

    abstract protected function nombre(): string;

    /**
     * Ejecuta la consulta y devuelve [distanciaMetros, duracionSegundos].
     *
     * Debe lanzar RutaInexistente si el proveedor confirma que no hay un
     * recorrido por calles entre los puntos, y ServicioMapasNoDisponible
     * ante cualquier otra respuesta inutilizable.
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
            'mapas:ruta:%s:%.5F,%.5F:%.5F,%.5F',
            $this->nombre(), $origen->lat, $origen->lng, $destino->lat, $destino->lng,
        );

        // Se guarda un array (la cache no deserializa objetos: ver
        // cache.serializable_classes).
        $datos = Cache::get($clave);

        if (is_array($datos) && count($datos) === 3) {
            return new Ruta((int) $datos[0], (int) $datos[1], (string) $datos[2]);
        }

        $ruta = $this->calcularSinCache($origen, $destino);
        Cache::put($clave, [$ruta->distanciaMetros, $ruta->duracionSegundos, $ruta->proveedor], now()->addMinutes($minutos));

        return $ruta;
    }

    protected function guarda(): GuardaDeProveedor
    {
        return $this->guarda ??= new GuardaDeProveedor($this->nombre());
    }

    private function calcularSinCache(Coordinate $origen, Coordinate $destino): Ruta
    {
        $guarda = $this->guarda();

        if ($guarda->circuitoAbierto()) {
            // Sin log por consulta: se registró al abrirse el circuito.
            throw new ServicioMapasNoDisponible("El servicio de mapas {$this->nombre()} está suspendido temporalmente por fallas recientes.");
        }

        $intentos = max(1, 1 + (int) config('services.mapas.reintentos'));

        for ($intento = 1; ; $intento++) {
            if (($limite = $guarda->cuotaAgotada()) !== null) {
                $this->registrarFalla("cuota local agotada ({$limite})", 'notice');

                throw new ServicioMapasNoDisponible("Se alcanzó el límite de consultas configurado para el servicio de mapas {$this->nombre()}.");
            }

            $guarda->registrarConsumo();

            try {
                [$metros, $segundos] = $this->consultar($this->http(), $origen, $destino);

                break;
            } catch (ConnectionException $e) {
                if ($intento < $intentos) {
                    Sleep::usleep(200_000);

                    continue;
                }

                // El mensaje de ConnectionException incluye la URL completa.
                $this->fallaDelProveedor(class_basename($e));
            } catch (RutaInexistente $e) {
                // Respuesta válida del proveedor: no es una falla del servicio.
                $guarda->registrarExito();

                throw $e;
            } catch (LimiteDelProveedorAlcanzado $e) {
                $guarda->abrirCircuitoPorLimite();
                $this->registrarFalla($e->getMessage());

                throw new ServicioMapasNoDisponible($e->getMessage(), previous: $e);
            } catch (ServicioMapasNoDisponible $e) {
                $this->fallaDelProveedor($e->getMessage(), $e);
            } catch (Throwable $e) {
                $this->fallaDelProveedor(class_basename($e));
            }
        }

        if (! is_numeric($metros) || ! is_numeric($segundos)
            || $metros < 0 || $segundos < 0 || $metros > self::DISTANCIA_MAXIMA_METROS) {
            $this->fallaDelProveedor('respuesta con valores inválidos');
        }

        $guarda->registrarExito();

        return new Ruta((int) round((float) $metros), (int) round((float) $segundos), $this->nombre());
    }

    private function http(): PendingRequest
    {
        $timeout = (float) config('services.mapas.timeout_segundos');

        return Http::acceptJson()->timeout($timeout)->connectTimeout($timeout);
    }

    /**
     * Rechaza las respuestas no exitosas. HTTP 429 (cuota o ritmo del
     * proveedor excedidos) abre el circuito de inmediato.
     */
    protected function exigirExito(Response $respuesta): Response
    {
        if ($respuesta->status() === 429) {
            throw new LimiteDelProveedorAlcanzado("El servicio de mapas {$this->nombre()} informó que se excedió su cuota (HTTP 429).");
        }

        if (! $respuesta->successful()) {
            throw new ServicioMapasNoDisponible("El servicio de mapas {$this->nombre()} respondió HTTP {$respuesta->status()}.");
        }

        return $respuesta;
    }

    /**
     * @return never
     */
    private function fallaDelProveedor(string $motivo, ?Throwable $previa = null): void
    {
        $abrio = $this->guarda()->registrarFalla();
        $this->registrarFalla($motivo.($abrio ? ' (circuito abierto)' : ''));

        throw $previa instanceof ServicioMapasNoDisponible
            ? $previa
            : new ServicioMapasNoDisponible("El servicio de mapas {$this->nombre()} no respondió correctamente.");
    }

    private function registrarFalla(string $motivo, string $nivel = 'warning'): void
    {
        Log::log($nivel, 'Falla del servicio de mapas; se usará la contingencia por zonas.', [
            'proveedor' => $this->nombre(),
            'motivo' => $motivo,
        ]);
    }
}
