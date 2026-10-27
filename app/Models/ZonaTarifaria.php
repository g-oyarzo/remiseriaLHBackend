<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ZonaTarifariaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Banda de distancia con monto fijo, usada por el cotizador como
 * contingencia cuando el servicio de mapas no está disponible (CU 02, 1b).
 * Una zona cubre los viajes cuya distancia en línea recta es menor o igual
 * a distancia_max_km; se aplica la banda más chica que cubra el viaje.
 */
class ZonaTarifaria extends Model
{
    /** @use HasFactory<ZonaTarifariaFactory> */
    use HasFactory;

    protected $table = 'zonas_tarifarias';

    /** @var list<string> */
    protected $fillable = [
        'nombre',
        'distancia_max_km',
        'monto',
        'activa',
        'actualizada_por_persona_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'distancia_max_km' => 'decimal:2',
            'monto' => 'decimal:2',
            'activa' => 'boolean',
        ];
    }

    /**
     * @param  Builder<ZonaTarifaria>  $query
     * @return Builder<ZonaTarifaria>
     */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activa', true);
    }

    /**
     * Zona activa más chica que cubre la distancia dada, o null si ninguna
     * banda configurada llega a cubrirla.
     */
    public static function paraDistancia(float $distanciaKm): ?self
    {
        return self::query()
            ->activas()
            ->where('distancia_max_km', '>=', $distanciaKm)
            ->orderBy('distancia_max_km')
            ->first();
    }
}
