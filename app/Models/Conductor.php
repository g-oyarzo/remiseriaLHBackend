<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PointCast;
use App\Enums\EstadoConductor;
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
        'calificacion',
        'estado',
        'en_servicio',
        'ubicacion_actual',
        'vehiculo_id',
    ];

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
     * Conductores activos, en servicio y con un vehículo asignado: los
     * únicos candidatos válidos para el algoritmo de despacho (CU 03).
     *
     * @param  Builder<Conductor>  $query
     * @return Builder<Conductor>
     */
    public function scopeDisponibles(Builder $query): Builder
    {
        return $query->activos()->enServicio()->whereNotNull('vehiculo_id');
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
