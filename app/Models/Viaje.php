<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PointCast;
use App\Enums\EstadoViaje;
use App\Enums\MetodoCotizacion;
use App\Enums\MetodoPago;
use App\Enums\TipoViaje;
use App\Events\ViajeCambioEstado;
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

    /**
     * Corrección de auditoría (sección 4.2): dispara ViajeCambioEstado cada
     * vez que cambia `estado`, sin importar desde qué controlador o
     * comando se origine el cambio (ver docblock del evento).
     */
    protected static function booted(): void
    {
        static::updated(function (self $viaje): void {
            if ($viaje->wasChanged('estado')) {
                event(new ViajeCambioEstado($viaje->id, $viaje->estado, $viaje->conductor_id));
            }
        });
    }

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
        'destino_localidad',
        'destino_calle',
        'destino_numero',
        'estado',
        'tipo',
        'metodo_pago',
        'canal',
        'registrado_por_persona_id',
        'costo',
        'precio_base_aplicado',
        'precio_por_km_aplicado',
        'distancia_estimada_km',
        'duracion_estimada_min',
        'metodo_cotizacion',
        'zona_tarifaria_id',
        'calificacion',
        'fecha_viaje',
        'despachado_en',
        'busqueda_agotada_en',
        'iniciado_en',
        'distancia_recorrida_metros',
        'puntos_seguimiento',
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
            'metodo_pago' => MetodoPago::class,
            'costo' => 'decimal:2',
            'precio_base_aplicado' => 'decimal:2',
            'precio_por_km_aplicado' => 'decimal:2',
            'distancia_estimada_km' => 'float',
            'duracion_estimada_min' => 'integer',
            'metodo_cotizacion' => MetodoCotizacion::class,
            'calificacion' => 'integer',
            'fecha_viaje' => 'datetime',
            'despachado_en' => 'datetime',
            'busqueda_agotada_en' => 'datetime',
            'iniciado_en' => 'datetime',
            'distancia_recorrida_metros' => 'integer',
            'puntos_seguimiento' => 'integer',
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

    public function zonaTarifaria(): BelongsTo
    {
        return $this->belongsTo(ZonaTarifaria::class);
    }

    public function pago(): HasOne
    {
        return $this->hasOne(Pago::class);
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(Mensaje::class);
    }

    public function asignaciones(): HasMany
    {
        return $this->hasMany(AsignacionViaje::class);
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
