<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MensajeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mensaje extends Model
{
    /** @use HasFactory<MensajeFactory> */
    use HasFactory;

    protected $table = 'mensajes';

    /** @var list<string> */
    protected $fillable = [
        'viaje_id',
        'emisor_persona_id',
        'receptor_persona_id',
        'contenido',
        'leido',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'leido' => 'boolean',
        ];
    }

    public function viaje(): BelongsTo
    {
        return $this->belongsTo(Viaje::class);
    }

    public function emisor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'emisor_persona_id');
    }

    public function receptor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'receptor_persona_id');
    }

    /**
     * @param  Builder<Mensaje>  $query
     * @return Builder<Mensaje>
     */
    public function scopeNoLeidos(Builder $query): Builder
    {
        return $query->where('leido', false);
    }

    /**
     * @param  Builder<Mensaje>  $query
     * @return Builder<Mensaje>
     */
    public function scopeDelViaje(Builder $query, int $viajeId): Builder
    {
        return $query->where('viaje_id', $viajeId);
    }
}
