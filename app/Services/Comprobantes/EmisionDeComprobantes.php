<?php

declare(strict_types=1);

namespace App\Services\Comprobantes;

use App\Enums\EstadoPago;
use App\Models\Comprobante;
use App\Models\Pago;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Emisión y entrega de comprobantes internos (registro inmutable).
 *
 * emitir() es idempotente: con la fila del pago bloqueada, si ya existe el
 * comprobante lo devuelve; si no, genera el PDF una única vez, lo guarda en
 * el disco privado "comprobantes" y registra su SHA-256. El número de
 * comprobante es el id del pago con 8 dígitos (puede haber saltos por pagos
 * rechazados).
 */
final class EmisionDeComprobantes
{
    public function __construct(private readonly ComprobanteViaje $generador) {}

    /**
     * @throws ComprobanteNoDisponible
     */
    public function emitir(int $pagoId): Comprobante
    {
        return DB::transaction(function () use ($pagoId): Comprobante {
            $pago = Pago::query()->lockForUpdate()->findOrFail($pagoId);

            if ($pago->estado !== EstadoPago::Confirmado) {
                throw new ComprobanteNoDisponible('El comprobante se emite cuando el pago del viaje está confirmado.');
            }

            $existente = Comprobante::query()->where('pago_id', $pago->id)->first();
            if ($existente !== null) {
                return $existente;
            }

            $viaje = $pago->viaje()->with(['cliente.persona', 'conductor.persona', 'vehiculo.marca', 'pago'])->firstOrFail();
            $emitidoEn = now()->startOfSecond();
            $numero = sprintf('%08d', $pago->id);
            $contenido = $this->generador->generar($viaje, $emitidoEn);
            $archivo = $emitidoEn->format('Y/m')."/{$numero}.pdf";

            // Si la transacción se revierte después de escribir, queda un
            // archivo huérfano inofensivo que se sobrescribe al reemitir.
            self::disco()->put($archivo, $contenido);

            return Comprobante::query()->create([
                'numero' => $numero,
                'pago_id' => $pago->id,
                'viaje_id' => $viaje->id,
                'total' => $pago->monto,
                'metodo_pago' => $pago->metodo_pago->value,
                'cliente_nombre' => self::nombre($viaje->cliente?->persona),
                'conductor_nombre' => self::nombre($viaje->conductor?->persona),
                'emitido_en' => $emitidoEn,
                'archivo' => $archivo,
                'sha256' => hash('sha256', $contenido),
            ]);
        });
    }

    /**
     * Contenido del PDF guardado, verificado contra su hash.
     *
     * @throws ComprobanteNoDisponible
     */
    public function contenido(Comprobante $comprobante): string
    {
        $contenido = self::disco()->exists($comprobante->archivo) ? self::disco()->get($comprobante->archivo) : null;

        if ($contenido === null || ! hash_equals($comprobante->sha256, hash('sha256', $contenido))) {
            Log::critical('Comprobante faltante o alterado: el archivo no coincide con su hash.', [
                'comprobante_id' => $comprobante->id,
                'numero' => $comprobante->numero,
            ]);

            throw new ComprobanteNoDisponible('El archivo del comprobante no está disponible o no supera la verificación de integridad.');
        }

        return $contenido;
    }

    private static function disco(): Filesystem
    {
        return Storage::disk('comprobantes');
    }

    private static function nombre(?object $persona): ?string
    {
        return $persona !== null ? trim("{$persona->nombre} {$persona->apellido}") : null;
    }
}
