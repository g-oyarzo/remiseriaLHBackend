<?php

declare(strict_types=1);

namespace App\Services\Geolocalizacion;

use App\Enums\EstadoViaje;
use App\Events\UbicacionConductorActualizada;
use App\Models\Conductor;
use App\Models\Viaje;
use App\ValueObjects\Coordinate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pings GPS de conductores (RNF02): último punto en Redis, persistencia
 * periódica en MySQL, seguimiento del recorrido de viajes en curso y
 * difusión por WebSocket.
 *
 * Si el store de cache (Redis) no responde, la posición se guarda y se lee
 * directamente de MySQL: el ping no falla por una caída de Redis.
 */
final class RegistroDeUbicaciones
{
    /**
     * @throws UbicacionInconsistente
     */
    public function registrar(Conductor $conductor, Coordinate $coordenada, ?float $precisionMetros = null): UbicacionRegistrada
    {
        $config = config('remiseria.ubicacion');
        $ahora = CarbonImmutable::now();
        $nueva = new UbicacionRegistrada($coordenada, $ahora);
        $anterior = $this->ultima($conductor);

        $desplazamientoMetros = $anterior?->coordenada->distanciaEnMetrosHacia($coordenada) ?? 0.0;

        if ($anterior !== null && $this->esSaltoImposible($anterior, $desplazamientoMetros, $ahora)) {
            throw new UbicacionInconsistente('La ubicación informada es inconsistente con la anterior.');
        }

        $guardadaEnCache = $this->escribirEnCache($conductor->persona_id, $nueva);

        $persistidaHace = $conductor->ubicacion_actualizada_en?->diffInSeconds($ahora);
        if (! $guardadaEnCache || $persistidaHace === null || $persistidaHace >= (int) $config['persistir_cada_segundos']) {
            $conductor->update(['ubicacion_actual' => $coordenada, 'ubicacion_actualizada_en' => $ahora]);
        }

        $viajeActivo = Viaje::query()
            ->where('conductor_id', $conductor->persona_id)
            ->whereIn('estado', [EstadoViaje::Aceptado, EstadoViaje::EnCurso])
            ->first(['id', 'estado']);

        if ($viajeActivo?->estado === EstadoViaje::EnCurso
            && $anterior !== null
            && $desplazamientoMetros >= (float) $config['salto_minimo_metros']
            && ($precisionMetros === null || $precisionMetros <= (float) $config['precision_maxima_metros'])) {
            // Incremento atómico: no hace falta bloquear la fila del viaje.
            Viaje::query()->whereKey($viajeActivo->id)->incrementEach([
                'distancia_recorrida_metros' => (int) round($desplazamientoMetros),
                'puntos_seguimiento' => 1,
            ]);
        }

        // ISO 8601 con milisegundos: permite medir la latencia de punta a
        // punta del seguimiento (RNF02, deploy/carga).
        UbicacionConductorActualizada::dispatch($conductor->persona_id, $coordenada, $viajeActivo?->id, $ahora->format('Y-m-d\TH:i:s.vP'));

        return $nueva;
    }

    /**
     * Último ping conocido: primero Redis y, si no está disponible o no tiene
     * el dato, la última posición persistida en MySQL.
     */
    public function ultima(Conductor $conductor): ?UbicacionRegistrada
    {
        try {
            $datos = $this->store()->get($this->clave($conductor->persona_id));

            if (is_array($datos)) {
                return UbicacionRegistrada::fromCache($datos);
            }
        } catch (Throwable $e) {
            $this->avisarFallaDelStore($e);
        }

        if ($conductor->ubicacion_actualizada_en === null || $conductor->ubicacion_actual === null) {
            return null;
        }

        return new UbicacionRegistrada($conductor->ubicacion_actual, $conductor->ubicacion_actualizada_en->toImmutable());
    }

    private function esSaltoImposible(UbicacionRegistrada $anterior, float $metros, CarbonImmutable $ahora): bool
    {
        // Pings casi simultáneos: un margen mínimo de 1 s evita dividir por cero.
        $segundos = max(1.0, (float) $anterior->registradaEn->diffInSeconds($ahora, absolute: true));
        $velocidadKmh = ($metros / 1000) / ($segundos / 3600);

        return $velocidadKmh > (float) config('remiseria.ubicacion.velocidad_maxima_kmh')
            && $metros > (float) config('remiseria.ubicacion.salto_minimo_metros');
    }

    private function escribirEnCache(int $conductorId, UbicacionRegistrada $ubicacion): bool
    {
        try {
            $this->store()->put($this->clave($conductorId), $ubicacion->toCache(), (int) config('remiseria.ubicacion.ttl_segundos'));

            return true;
        } catch (Throwable $e) {
            $this->avisarFallaDelStore($e);

            return false;
        }
    }

    private function store(): Repository
    {
        return Cache::store(config('remiseria.ubicacion.cache_store'));
    }

    private function clave(int $conductorId): string
    {
        return "ubicacion:conductor:{$conductorId}";
    }

    /**
     * Un aviso por minuto como máximo: con Redis caído cada ping fallaría.
     */
    private function avisarFallaDelStore(Throwable $e): void
    {
        try {
            $avisar = Cache::store('array')->add('ubicacion:aviso-falla-store', true, 60);
        } catch (Throwable) {
            $avisar = true;
        }

        if ($avisar) {
            Log::warning('Store de ubicaciones no disponible; se usa MySQL como respaldo.', [
                'store' => config('remiseria.ubicacion.cache_store'),
                'error' => class_basename($e),
            ]);
        }
    }
}
