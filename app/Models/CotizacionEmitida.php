<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cotización emitida con vigencia (precio cerrado). Ver
 * App\Services\Cotizacion\CotizacionesEmitidas.
 */
class CotizacionEmitida extends Model
{
    use HasUuids, Prunable;

    protected $table = 'cotizaciones';

    /** @var list<string> */
    protected $fillable = [
        'cuenta_id',
        'origen_lat',
        'origen_lng',
        'destino_lat',
        'destino_lng',
        'es_reserva',
        'monto',
        'atributos',
        'respuesta',
        'expira_en',
        'usada_en',
        'viaje_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origen_lat' => 'float',
            'origen_lng' => 'float',
            'destino_lat' => 'float',
            'destino_lng' => 'float',
            'es_reserva' => 'boolean',
            'monto' => 'decimal:2',
            'atributos' => 'array',
            'respuesta' => 'array',
            'expira_en' => 'datetime',
            'usada_en' => 'datetime',
        ];
    }

    public function viaje(): BelongsTo
    {
        return $this->belongsTo(Viaje::class);
    }

    /**
     * Se eliminan una semana después de vencidas (model:prune, diario). El
     * viaje conserva su propio snapshot de la cotización.
     *
     * @return Builder<CotizacionEmitida>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expira_en', '<', now()->subDays(7));
    }
}
