<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tarjeta vinculada por un pasajero (tokenizada por Mercado Pago). Solo
 * contiene identificadores del proveedor y datos de presentación.
 *
 * @property int $id
 * @property int $cliente_id
 * @property string $mp_customer_id
 * @property ?string $mp_card_id
 * @property ?string $mp_payment_profile_id
 * @property string $mp_payment_method_id
 * @property string $tipo
 * @property bool $predeterminado
 * @property bool $cobro_automatico
 */
class MedioPagoGuardado extends Model
{
    protected $table = 'medios_pago_guardados';

    /** @var list<string> */
    protected $fillable = [
        'cliente_id',
        'proveedor',
        'mp_customer_id',
        'mp_card_id',
        'mp_payment_profile_id',
        'mp_payment_method_id',
        'tipo',
        'ultimos_cuatro',
        'vencimiento_mes',
        'vencimiento_anio',
        'emisor',
        'predeterminado',
        'cobro_automatico',
        'consentimiento_en',
        'consentimiento_ip',
        'consentimiento_version',
        'desvinculado_en',
    ];

    /**
     * Los identificadores del proveedor no se exponen en la API.
     *
     * @var list<string>
     */
    protected $hidden = [
        'mp_customer_id',
        'mp_card_id',
        'mp_payment_profile_id',
        'consentimiento_ip',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'predeterminado' => 'boolean',
            'cobro_automatico' => 'boolean',
            'vencimiento_mes' => 'integer',
            'vencimiento_anio' => 'integer',
            'consentimiento_en' => 'datetime',
            'desvinculado_en' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    /**
     * @param  Builder<MedioPagoGuardado>  $query
     * @return Builder<MedioPagoGuardado>
     */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->whereNull('desvinculado_en');
    }

    /**
     * Representación pública (sin identificadores del proveedor).
     *
     * @return array<string, mixed>
     */
    public function resumen(): array
    {
        return [
            'id' => $this->id,
            // Lo necesita MercadoPago.js (createCardToken({cardId}) + CVV)
            // para pagar con la tarjeta guardada. No es secreto: sin el
            // access token del backend y el CVV no permite cobrar.
            'card_id' => $this->mp_card_id,
            'marca' => $this->mp_payment_method_id,
            'tipo' => $this->tipo,
            'ultimos_cuatro' => $this->ultimos_cuatro,
            'vencimiento_mes' => $this->vencimiento_mes,
            'vencimiento_anio' => $this->vencimiento_anio,
            'emisor' => $this->emisor,
            'predeterminado' => $this->predeterminado,
            'cobro_automatico' => $this->cobro_automatico,
            'vinculado_en' => $this->created_at?->toIso8601String(),
        ];
    }
}
