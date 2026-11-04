<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoPago;
use App\Enums\MetodoPago;
use App\Events\PagoActualizado;
use App\Events\PagoConfirmado;
use Database\Factories\PagoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Pago extends Model
{
    /** @use HasFactory<PagoFactory> */
    use HasFactory;

    protected $table = 'pagos';

    /**
     * Cada vez que un pago se crea o cambia de estado se difunde
     * PagoActualizado en el canal del viaje; si pasa a "confirmado", por
     * cualquier vía, se emite además PagoConfirmado (después del commit)
     * para emitir el comprobante.
     */
    protected static function booted(): void
    {
        static::saved(function (self $pago): void {
            if (! $pago->wasRecentlyCreated && ! $pago->wasChanged('estado')) {
                return;
            }

            PagoActualizado::dispatch($pago->viaje_id, $pago->id, $pago->estado);

            if ($pago->estado === EstadoPago::Confirmado) {
                PagoConfirmado::dispatch($pago->id);
            }
        });
    }

    /** @var list<string> */
    protected $fillable = [
        'viaje_id',
        'metodo_pago',
        'monto',
        'referencia_externa',
        'proveedor',
        'preferencia_id',
        'enlace_pago',
        'preferencia_expira_en',
        'estado',
        'registrado_por_persona_id',
        'conciliado_por_persona_id',
        'conciliado_en',
        'observacion',
        'historial',
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
            'conciliado_en' => 'datetime',
            'preferencia_expira_en' => 'datetime',
            'historial' => 'array',
        ];
    }

    public function viaje(): BelongsTo
    {
        return $this->belongsTo(Viaje::class);
    }

    public function comprobante(): HasOne
    {
        return $this->hasOne(Comprobante::class);
    }

    /**
     * Agrega una entrada al historial de transiciones del pago.
     */
    public function registrarEnHistorial(string $accion, ?int $personaId, array $extra = []): void
    {
        $historial = $this->historial ?? [];
        $historial[] = [
            'accion' => $accion,
            'estado' => $this->estado?->value,
            'metodo_pago' => $this->metodo_pago?->value,
            'monto' => $this->monto !== null ? (string) $this->monto : null,
            'referencia_externa' => $this->referencia_externa,
            'persona_id' => $personaId,
            'en' => now()->toIso8601String(),
        ] + $extra;

        $this->historial = $historial;
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
