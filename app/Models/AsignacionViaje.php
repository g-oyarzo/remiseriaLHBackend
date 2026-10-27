<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoAsignacion;
use Database\Factories\AsignacionViajeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Oferta de un viaje a un conductor, con vencimiento (CU 03).
 */
class AsignacionViaje extends Model
{
    /** @use HasFactory<AsignacionViajeFactory> */
    use HasFactory;

    protected $table = 'asignaciones_viaje';

    /** @var list<string> */
    protected $fillable = [
        'viaje_id',
        'conductor_id',
        'estado',
        'intento',
        'distancia_metros',
        'puntaje',
        'expira_en',
        'respondida_en',
    ];

    /** @var list<string> */
    protected $hidden = [
        'viaje_pendiente_id',
        'conductor_pendiente_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado' => EstadoAsignacion::class,
            'intento' => 'integer',
            'distancia_metros' => 'integer',
            'puntaje' => 'float',
            'expira_en' => 'datetime',
            'respondida_en' => 'datetime',
        ];
    }

    public function viaje(): BelongsTo
    {
        return $this->belongsTo(Viaje::class);
    }

    public function conductor(): BelongsTo
    {
        return $this->belongsTo(Conductor::class, 'conductor_id');
    }

    /**
     * @param  Builder<AsignacionViaje>  $query
     * @return Builder<AsignacionViaje>
     */
    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('estado', EstadoAsignacion::Pendiente);
    }

    public function vencida(): bool
    {
        return $this->expira_en->isPast();
    }
}
