<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Comprobante interno emitido (registro de solo lectura).
 *
 * Una vez creado no se modifica ni se elimina: la aplicación lo impide en
 * los eventos del modelo, la API no expone rutas de escritura y las claves
 * foráneas impiden borrar el viaje o el pago asociado. El PDF guardado se
 * verifica contra sha256 en cada descarga.
 */
class Comprobante extends Model
{
    protected $table = 'comprobantes';

    /** @var list<string> */
    protected $fillable = [
        'numero',
        'pago_id',
        'viaje_id',
        'total',
        'metodo_pago',
        'cliente_nombre',
        'conductor_nombre',
        'emitido_en',
        'archivo',
        'sha256',
    ];

    /** @var list<string> */
    protected $hidden = ['archivo'];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Los comprobantes emitidos no se pueden modificar.'));
        static::deleting(fn (): never => throw new LogicException('Los comprobantes emitidos no se pueden eliminar.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'emitido_en' => 'datetime',
        ];
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class);
    }

    public function viaje(): BelongsTo
    {
        return $this->belongsTo(Viaje::class);
    }
}
