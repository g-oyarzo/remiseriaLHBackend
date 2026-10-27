<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\MetodoPago;
use App\Support\Dinero;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * CU 07: método aplicado y monto recaudado. El monto se valida contra el
 * importe del viaje en el servicio; acá solo su formato.
 */
class RegistrarPagoRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'metodo_pago' => ['required', new Enum(MetodoPago::class)],
            'monto' => Dinero::reglasMontoPositivo(),
            // Número de operación del QR o de la terminal: obligatorio para
            // cobros digitales (permite conciliarlos y evita reutilizar un
            // mismo comprobante); no aplica al efectivo.
            'referencia_externa' => [
                'required_unless:metodo_pago,'.MetodoPago::Efectivo->value,
                'prohibited_if:metodo_pago,'.MetodoPago::Efectivo->value,
                'nullable',
                'string',
                'regex:/^[A-Za-z0-9._\-]{4,100}$/',
            ],
        ];
    }

    public function metodo(): MetodoPago
    {
        return MetodoPago::from($this->validated('metodo_pago'));
    }

    public function monto(): string
    {
        return Dinero::desdeCentavos(Dinero::aCentavos((string) $this->validated('monto')));
    }
}
