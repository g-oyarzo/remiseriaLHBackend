<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TarifaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tarifa extends Model
{
    /** @use HasFactory<TarifaFactory> */
    use HasFactory;

    protected $table = 'tarifas';

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
     */
    public static function vigente(): ?self
    {
        return self::query()->activas()->latest('vigente_desde')->first();
    }
}
