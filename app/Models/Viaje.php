<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PointCast;
use App\Enums\EstadoViaje;
use App\Enums\TipoViaje;
use Database\Factories\ViajeFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Viaje extends Model
{
    /** @use HasFactory<ViajeFactory> */
    use HasFactory;

    protected $table = 'viajes';

    /** @var list<string> */
    protected $fillable = [
        'cliente_id',
        'conductor_id',
        'vehiculo_id',
        'tarifa_id',
        'origen',
        'destino',
        'origen_localidad',
        'origen_calle',
        'origen_numero',
        'estado',
        'tipo',
        'costo',
        'calificacion',
        'fecha_viaje',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origen' => PointCast::class,
            'destino' => PointCast::class,
            'estado' => EstadoViaje::class,
            'tipo' => TipoViaje::class,
            'costo' => 'decimal:2',
            'calificacion' => 'integer',
            'fecha_viaje' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function conductor(): BelongsTo
    {
        return $this->belongsTo(Conductor::class, 'conductor_id');
    }

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class);
    }

    public function tarifa(): BelongsTo
    {
        return $this->belongsTo(Tarifa::class);
    }

    public function pago(): HasOne
    {
        return $this->hasOne(Pago::class);
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(Mensaje::class);
    }

    /**
     * @param  Builder<Viaje>  $query
     * @return Builder<Viaje>
     */
    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('estado', EstadoViaje::Solicitado);
    }

    /**
     * @param  Builder<Viaje>  $query
     * @return Builder<Viaje>
     */
    public function scopeEnCurso(Builder $query): Builder
    {
        return $query->where('estado', EstadoViaje::EnCurso);
    }

    /**
     * @param  Builder<Viaje>  $query
     * @return Builder<Viaje>
     */
    public function scopeFinalizados(Builder $query): Builder
    {
        return $query->where('estado', EstadoViaje::Finalizado);
    }

    /**
     * @param  Builder<Viaje>  $query
     * @return Builder<Viaje>
     */
    public function scopeCancelados(Builder $query): Builder
    {
        return $query->where('estado', EstadoViaje::Cancelado);
    }

    /**
     * @param  Builder<Viaje>  $query
     * @return Builder<Viaje>
     */
    public function scopeProgramados(Builder $query): Builder
    {
        return $query->where('tipo', TipoViaje::Programado);
    }

    /**
     * @param  Builder<Viaje>  $query
     * @return Builder<Viaje>
     */
    public function scopeDelConductor(Builder $query, int $conductorId): Builder
    {
        return $query->where('conductor_id', $conductorId);
    }

    /**
     * @param  Builder<Viaje>  $query
     * @return Builder<Viaje>
     */
    public function scopeEntreFechas(Builder $query, DateTimeInterface $desde, DateTimeInterface $hasta): Builder
    {
        return $query->whereBetween('fecha_viaje', [$desde, $hasta]);
    }
}
