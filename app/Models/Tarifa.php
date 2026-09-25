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
        'zona',
        'activa',
        'vigente_desde',
        'vigente_hasta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'precio_base' => 'decimal:2',
            'precio_por_km' => 'decimal:2',
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

    public function calcularCosto(float $kilometros): float
    {
        return (float) $this->precio_base + ($kilometros * (float) $this->precio_por_km);
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
        return Cache::remember(
            self::CACHE_KEY_VIGENTE,
            now()->addMinutes(10),
            fn () => self::query()->activas()->latest('vigente_desde')->first(),
        );
    }

    /**
     * Invalida la cache de la tarifa vigente. Debe llamarse siempre que se
     * cree, actualice o desactive una tarifa (ver TarifaController::store()).
     */
    public static function olvidarVigenteEnCache(): void
    {
        Cache::forget(self::CACHE_KEY_VIGENTE);
    }
}