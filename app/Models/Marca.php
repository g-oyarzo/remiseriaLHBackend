<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MarcaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Marca extends Model
{
    /** @use HasFactory<MarcaFactory> */
    use HasFactory;

    protected $table = 'marcas';

    /** @var list<string> */
    protected $fillable = [
        'nombre',
    ];

    public function vehiculos(): HasMany
    {
        return $this->hasMany(Vehiculo::class);
    }

    /**
     * @param  Builder<Marca>  $query
     * @return Builder<Marca>
     */
    public function scopeOrdenadasPorNombre(Builder $query): Builder
    {
        return $query->orderBy('nombre');
    }
}