<?php

declare(strict_types=1);

namespace App\Services\Mapas;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Protección de un proveedor de mapas externo:
 *
 * - Cuota local: cuenta las consultas por minuto, día y mes (hora de la
 *   aplicación) y deja de llamar al proveedor antes de alcanzar los límites
 *   del plan gratuito. Así un pico de cotizaciones nunca genera cargos
 *   (Google) ni bloqueos (OpenRouteService): el cotizador usa la
 *   contingencia por zonas hasta que se renueva la cuota.
 * - Circuit breaker: tras N fallas consecutivas, o ante un HTTP 429, deja
 *   de llamar al proveedor durante unos segundos. Al vencer, la siguiente
 *   consulta lo vuelve a probar; si responde bien, el circuito se cierra
 *   (recuperación automática).
 *
 * El estado se guarda en el store de cache configurado
 * (MAPAS_CACHE_STORE; en producción, Redis, compartido por todos los
 * procesos). Si el store falla, la guarda no bloquea: se prefiere intentar
 * la consulta antes que dejar sin cotización al pasajero.
 */
final class GuardaDeProveedor
{
    public function __construct(private readonly string $proveedor) {}

    /**
     * @return array{minuto: int, dia: int, mes: int, circuito_abierto_hasta: ?string, fallas_consecutivas: int}
     */
    public function estado(): array
    {
        $hasta = $this->leer($this->claveCircuito());

        return [
            'minuto' => (int) $this->leer($this->claveCuota('minuto')),
            'dia' => (int) $this->leer($this->claveCuota('dia')),
            'mes' => (int) $this->leer($this->claveCuota('mes')),
            'circuito_abierto_hasta' => is_numeric($hasta) && (int) $hasta > now()->getTimestamp()
                ? Carbon::createFromTimestamp((int) $hasta, config('app.timezone'))->toIso8601String()
                : null,
            'fallas_consecutivas' => (int) $this->leer($this->claveFallas()),
        ];
    }

    /**
     * @return array{minuto: ?int, dia: ?int, mes: ?int}
     */
    public function limites(): array
    {
        $config = config("services.mapas.cuotas.{$this->proveedor}", []);

        $limite = static fn (mixed $valor): ?int => is_numeric($valor) && (int) $valor > 0 ? (int) $valor : null;

        return [
            'minuto' => $limite($config['por_minuto'] ?? null),
            'dia' => $limite($config['por_dia'] ?? null),
            'mes' => $limite($config['por_mes'] ?? null),
        ];
    }

    /**
     * Devuelve el período cuya cuota está agotada, o null si se puede consultar.
     */
    public function cuotaAgotada(): ?string
    {
        foreach ($this->limites() as $periodo => $limite) {
            if ($limite !== null && (int) $this->leer($this->claveCuota($periodo)) >= $limite) {
                return $periodo;
            }
        }

        return null;
    }

    public function registrarConsumo(): void
    {
        foreach (['minuto' => 120, 'dia' => 2 * 86_400, 'mes' => 32 * 86_400] as $periodo => $ttl) {
            $this->incrementar($this->claveCuota($periodo), $ttl);
        }
    }

    public function circuitoAbierto(): bool
    {
        $hasta = $this->leer($this->claveCircuito());

        return is_numeric($hasta) && (int) $hasta > now()->getTimestamp();
    }

    /**
     * Registra una falla; devuelve true si con ella se abrió el circuito.
     */
    public function registrarFalla(): bool
    {
        $umbral = max(1, (int) config('services.mapas.circuito.umbral_fallas'));
        $fallas = $this->incrementar($this->claveFallas(), 3600);

        // Semiabierto: el circuito acaba de vencer (la marca sigue en cache
        // un minuto más) y la consulta de prueba falló: se reabre sin
        // esperar a acumular otras N fallas.
        $semiabierto = is_numeric($this->leer($this->claveCircuito()));

        if ($fallas < $umbral && ! $semiabierto) {
            return false;
        }

        $this->abrir((int) config('services.mapas.circuito.segundos_abierto'));

        return true;
    }

    public function abrirCircuitoPorLimite(): void
    {
        $this->abrir((int) config('services.mapas.circuito.segundos_tras_429'));
    }

    public function registrarExito(): void
    {
        $this->olvidar($this->claveFallas());
        $this->olvidar($this->claveCircuito());
    }

    private function abrir(int $segundos): void
    {
        $segundos = max(1, $segundos);
        $hasta = now()->getTimestamp() + $segundos;

        try {
            $this->store()->put($this->claveCircuito(), $hasta, $segundos + 60);
            // El próximo intento tras la pausa vuelve a evaluar desde cero.
            $this->store()->forget($this->claveFallas());
        } catch (\Throwable $e) {
            $this->registrarErrorDeStore($e);
        }

        Log::warning('Servicio de mapas suspendido temporalmente; se usa la contingencia por zonas.', [
            'proveedor' => $this->proveedor,
            'segundos' => $segundos,
        ]);
    }

    private function claveCuota(string $periodo): string
    {
        $ahora = now(config('app.timezone'));

        return "mapas:cuota:{$this->proveedor}:".match ($periodo) {
            'minuto' => $ahora->format('YmdHi'),
            'dia' => $ahora->format('Ymd'),
            default => $ahora->format('Ym'),
        };
    }

    private function claveCircuito(): string
    {
        return "mapas:circuito:{$this->proveedor}";
    }

    private function claveFallas(): string
    {
        return "mapas:fallas:{$this->proveedor}";
    }

    private function incrementar(string $clave, int $ttl): int
    {
        try {
            // add() es atómico: solo crea la clave (con su vencimiento) si no existe.
            $this->store()->add($clave, 0, $ttl);

            return (int) $this->store()->increment($clave);
        } catch (\Throwable $e) {
            $this->registrarErrorDeStore($e);

            return 0;
        }
    }

    private function leer(string $clave): mixed
    {
        try {
            return $this->store()->get($clave);
        } catch (\Throwable $e) {
            $this->registrarErrorDeStore($e);

            return null;
        }
    }

    private function olvidar(string $clave): void
    {
        try {
            $this->store()->forget($clave);
        } catch (\Throwable $e) {
            $this->registrarErrorDeStore($e);
        }
    }

    private function store(): Repository
    {
        return Cache::store(config('services.mapas.cache_store'));
    }

    private function registrarErrorDeStore(\Throwable $e): void
    {
        Log::error('No se pudo leer o actualizar el estado del servicio de mapas en la cache.', [
            'proveedor' => $this->proveedor,
            'error' => class_basename($e),
        ]);
    }
}
