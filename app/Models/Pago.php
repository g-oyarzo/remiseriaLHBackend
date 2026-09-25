<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoPago;
use App\Enums\MetodoPago;
use Database\Factories\PagoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pago extends Model
{
    /** @use HasFactory<PagoFactory> */
    use HasFactory;

    protected $table = 'pagos';

    /** @var list<string> */
    protected $fillable = [
        'viaje_id',
        'metodo_pago',
        'monto',
        'estado',
        'fecha_pago',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metodo_pago' => MetodoPago::class,
            'estado' => EstadoPago::class,
            'monto' => 'decimal:2',
            'fecha_pago' => 'datetime',
        ];
    }

    public function viaje(): BelongsTo
    {
        return $this->belongsTo(Viaje::class);
    }

    /**
     * @param  Builder<Pago>  $query
     * @return Builder<Pago>
     */
    public function scopeConfirmados(Builder $query): Builder
    {
        return $query->where('estado', EstadoPago::Confirmado);
    }

    /**
     * @param  Builder<Pago>  $query
     * @return Builder<Pago>
     */
    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('estado', EstadoPago::Pendiente);
    }
}