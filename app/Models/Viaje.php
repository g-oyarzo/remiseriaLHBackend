<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PointCast;
use App\Enums\EstadoViaje;
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
                event(new ViajeCambioEstado($viaje->id, $viaje->estado));
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
     * Viajes "solicitado" que un conductor debería poder ver y tomar ahora
     * mismo (ViajeController::pendientes()): los inmediatos siempre, y los
     * programados recién a partir de que entran en la ventana de despacho
     * configurada (ver config('remiseria.ventana_despacho_programados_minutos')
     * y App\Console\Commands\DespacharViajesProgramadosCommand).
     *
     * Corrección de auditoría (HALL-006): antes scopePendientes() no hacía
     * ninguna distinción por tipo/fecha, así que un viaje programado para
     * dentro de varios días ya aparecía como "pendiente" desde el momento
     * en que se solicitaba, pudiendo ser aceptado por un conductor mucho
     * antes de tiempo.
     *
     * @param  Builder<Viaje>  $query
     * @return Builder<Viaje>
     */
    public function scopeListosParaDespacho(Builder $query): Builder
    {
        $limite = now()->addMinutes((int) config('remiseria.ventana_despacho_programados_minutos'));

        return $query->pendientes()
            ->where(function (Builder $query) use ($limite): void {
                $query->where('tipo', TipoViaje::Actual)
                    ->orWhere(function (Builder $query) use ($limite): void {
                        $query->where('tipo', TipoViaje::Programado)
                            ->where('fecha_viaje', '<=', $limite);
                    });
            });
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