<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TarifaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Tarifa extends Model
{
    /** @use HasFactory<TarifaFactory> */
    use HasFactory;

    protected $table = 'tarifas';

    private const CACHE_KEY_VIGENTE = 'tarifa:vigente';

    /** @var list<string> */
    protected $fillable = [
        'precio_base',
        'precio_por_km',
        'demanda_umbral_ratio',
        'demanda_recargo_pct',
        'lluvia_umbral_mm',
        'lluvia_recargo_pct',
        'recargo_maximo_pct',
        'zona',
        'activa',
        'vigente_desde',
        'vigente_hasta',
        'creada_por_persona_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'precio_base' => 'decimal:2',
            'precio_por_km' => 'decimal:2',
            'demanda_umbral_ratio' => 'decimal:2',
            'demanda_recargo_pct' => 'decimal:2',
            'lluvia_umbral_mm' => 'decimal:2',
            'lluvia_recargo_pct' => 'decimal:2',
            'recargo_maximo_pct' => 'decimal:2',
            'activa' => 'boolean',
            'vigente_desde' => 'datetime',
            'vigente_hasta' => 'datetime',
        ];
    }

    public function viajes(): HasMany
    {
        return $this->hasMany(Viaje::class);
    }

    /**
     * @param  Builder<Tarifa>  $query
     * @return Builder<Tarifa>
     */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activa', true);
    }

    /**
     * Devuelve la tarifa vigente en este momento (CU 02, CU 24).
     *
     * Fase 4 (rendimiento): esta consulta se ejecuta en cada solicitud de
     * viaje (ViajeController::store) y en /tarifas/vigente, así que bajo
     * volumen (~500 llamadas/minuto según la simulación de Mes 4 de la
     * auditoría) puede volverse un cuello de botella innecesario, dado que
     * la tarifa activa cambia con muy poca frecuencia. Se cachea con un TTL
     * corto como red de seguridad (por si alguna vía de escritura futura
     * olvida invalidar la cache) y se invalida explícitamente en
     * TarifaController::store() cada vez que se configura una tarifa nueva.
     */
    public static function vigente(): ?self
    {
        // La clave incluye un número de versión que se incrementa al cambiar
        // la tarifa. Con un simple forget(), una lectura concurrente que
        // consultó la fila vieja mientras la transacción del cambio seguía
        // abierta podía volver a guardar la tarifa anterior en cache justo
        // después del forget, y las cotizaciones seguían usando el precio
        // viejo hasta que venciera el TTL. Con la clave versionada, ese
        // valor queda guardado bajo la versión anterior y nadie lo vuelve a
        // leer.
        $version = (int) Cache::get(self::CACHE_KEY_VIGENTE.':version', 0);

        // Se guardan los atributos (un array), no el modelo: la cache no
        // deserializa objetos (cache.serializable_classes = false, protección
        // contra cadenas de deserialización si se filtra APP_KEY). Guardar el
        // modelo devolvía __PHP_Incomplete_Class con Redis o la base de datos.
        // Clave ":datos:" distinta de la del formato anterior (":v{n}", que
        // guardaba el modelo serializado): tras desplegar, las entradas
        // viejas que siguen en Redis se ignoran en lugar de leerse como
        // "sin tarifa vigente".
        $clave = self::CACHE_KEY_VIGENTE.':datos:v'.$version;
        $consultar = fn (): ?array => self::query()->activas()->latest('vigente_desde')->latest('id')->first()?->getAttributes();

        $atributos = Cache::remember($clave, now()->addMinutes(10), $consultar);

        if ($atributos !== null && ! is_array($atributos)) {
            // Valor ilegible en la cache: se descarta y se consulta la base.
            Cache::forget($clave);
            $atributos = $consultar();
        }

        return is_array($atributos) ? (new self)->newFromBuilder($atributos) : null;
    }

    /**
     * Invalida la cache de la tarifa vigente. Debe llamarse después de
     * confirmar la transacción que crea, actualiza o desactiva una tarifa
     * (ver TarifaController::crearNuevaVersion()).
     */
    public static function olvidarVigenteEnCache(): void
    {
        Cache::add(self::CACHE_KEY_VIGENTE.':version', 0);
        Cache::increment(self::CACHE_KEY_VIGENTE.':version');
    }
}
