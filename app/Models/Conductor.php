<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PointCast;
use App\Enums\EstadoConductor;
use App\Enums\EstadoViaje;
use App\ValueObjects\Coordinate;
use Database\Factories\ConductorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conductor extends Model
{
    /** @use HasFactory<ConductorFactory> */
    use HasFactory;

    protected $table = 'conductores';

    protected $primaryKey = 'persona_id';

    public $incrementing = false;

    protected $keyType = 'int';

    /** @var list<string> */
    protected $fillable = [
        'persona_id',
        'cuil',
        'fecha_nacimiento',
        'domicilio_localidad',
        'domicilio_calle',
        'domicilio_numero',
        'foto',
        'estado',
        'en_servicio',
        'ubicacion_actual',
        'vehiculo_id',
    ];

    // HALL-017: 'calificacion' se removió deliberadamente de $fillable.
    // Se recalcula únicamente en ViajeController::calificar() vía
    // Conductor::query()->update(['calificacion' => ...]), que no pasa por
    // mass assignment de un modelo ya hidratado desde un request. Si algún
    // endpoint futuro permitiera actualizar el propio legajo del conductor
    // con datos del request, mantenerla fuera de $fillable evita que un
    // conductor pueda manipular directamente su propia calificación.

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'calificacion' => 'decimal:2',
            'estado' => EstadoConductor::class,
            'en_servicio' => 'boolean',
            'ubicacion_actual' => PointCast::class,
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class, 'vehiculo_id');
    }

    public function viajes(): HasMany
    {
        return $this->hasMany(Viaje::class, 'conductor_id');
    }

    /**
     * @param  Builder<Conductor>  $query
     * @return Builder<Conductor>
     */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('estado', EstadoConductor::Activo);
    }

    /**
     * @param  Builder<Conductor>  $query
     * @return Builder<Conductor>
     */
    public function scopeEnServicio(Builder $query): Builder
    {
        return $query->where('en_servicio', true);
    }

    /**
     * Conductores activos, en servicio, con un vehículo asignado y SIN un
     * viaje en curso: los únicos candidatos válidos para el algoritmo de
     * despacho (CU 03).
     *
     * Corrección de auditoría 2.2: antes esta condición no excluía a los
     * conductores que ya tenían un viaje "aceptado" o "en_curso" asignado,
     * lo que permitía que el despachador les ofreciera un segundo viaje en
     * simultáneo.
     *
     * Importante: este scope reduce el riesgo bajo uso normal, pero NO
     * elimina la condición de carrera bajo concurrencia real (dos
     * solicitudes de viaje llegando casi al mismo tiempo). Cuando se
     * implemente el servicio de asignación, la confirmación final del
     * conductor elegido debe hacerse dentro de una transacción con
     * `lockForUpdate()` sobre esa fila, re-chequeando que siga disponible
     * antes de escribir `viajes.conductor_id`.
     *
     * @param  Builder<Conductor>  $query
     * @return Builder<Conductor>
     */
    public function scopeDisponibles(Builder $query): Builder
    {
        return $query->activos()
            ->enServicio()
            ->whereNotNull('vehiculo_id')
            ->whereDoesntHave('viajes', function (Builder $query): void {
                $query->whereIn('estado', [EstadoViaje::Aceptado, EstadoViaje::EnCurso]);
            });
    }

    /**
     * Ordena y filtra conductores por cercanía real (metros) usando la
     * función nativa ST_Distance_Sphere() de MySQL sobre `ubicacion_actual`.
     *
     * @param  Builder<Conductor>  $query
     * @return Builder<Conductor>
     */
    public function scopeCercanos(Builder $query, Coordinate $origen, ?float $radioMetros = null): Builder
    {
        $punto = $origen->toWkt();

        $query = $query->selectRaw(
            'conductores.*, ST_Distance_Sphere(ubicacion_actual, ST_GeomFromText(?, 4326)) as distancia_metros',
            [$punto]
        )->orderBy('distancia_metros');

        if ($radioMetros !== null) {
            $query->havingRaw('distancia_metros <= ?', [$radioMetros]);
        }

        return $query;
    }
}