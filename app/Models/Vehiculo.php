<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoVehiculo;
use Database\Factories\VehiculoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehiculo extends Model
{
    /** @use HasFactory<VehiculoFactory> */
    use HasFactory;

    protected $table = 'vehiculos';

    /** @var list<string> */
    protected $fillable = [
        'marca_id',
        'modelo',
        'patente',
        'color',
        'anio',
        'estado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'estado' => EstadoVehiculo::class,
        ];
    }

    public function marca(): BelongsTo
    {
        return $this->belongsTo(Marca::class);
    }

    /**
     * Historial de conductores que tuvieron este vehículo asignado.
     */
    public function conductores(): HasMany
    {
        return $this->hasMany(Conductor::class, 'vehiculo_id');
    }

    public function viajes(): HasMany
    {
        return $this->hasMany(Viaje::class);
    }

    /**
     * @param  Builder<Vehiculo>  $query
     * @return Builder<Vehiculo>
     */
    public function scopeOperando(Builder $query): Builder
    {
        return $query->where('estado', EstadoVehiculo::Operando);
    }
}
