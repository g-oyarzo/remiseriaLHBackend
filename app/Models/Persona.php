<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PersonaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Persona extends Model
{
    /** @use HasFactory<PersonaFactory> */
    use HasFactory;

    protected $table = 'personas';

    /** @var list<string> */
    protected $fillable = [
        'dni',
        'nombre',
        'apellido',
        'telefono',
    ];

    public function cuenta(): HasOne
    {
        return $this->hasOne(Cuenta::class);
    }

    public function cliente(): HasOne
    {
        return $this->hasOne(Cliente::class, 'persona_id');
    }

    public function conductor(): HasOne
    {
        return $this->hasOne(Conductor::class, 'persona_id');
    }

    public function administrador(): HasOne
    {
        return $this->hasOne(Administrador::class, 'persona_id');
    }

    public function mensajesEnviados(): HasMany
    {
        return $this->hasMany(Mensaje::class, 'emisor_persona_id');
    }

    public function mensajesRecibidos(): HasMany
    {
        return $this->hasMany(Mensaje::class, 'receptor_persona_id');
    }

    public function nombreCompleto(): string
    {
        return trim("{$this->nombre} {$this->apellido}");
    }

    /**
     * @param  Builder<Persona>  $query
     * @return Builder<Persona>
     */
    public function scopeBuscarPorDni(Builder $query, string $dni): Builder
    {
        return $query->where('dni', $dni);
    }
}