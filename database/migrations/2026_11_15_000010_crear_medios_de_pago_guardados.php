<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Medios de pago guardados con Mercado Pago (tarjetas tokenizadas).
 *
 * Nunca se guardan números de tarjeta, CVV ni credenciales: solo los
 * identificadores que devuelve Mercado Pago (customer, card, payment
 * profile) y datos de presentación (marca, últimos 4 dígitos, vencimiento).
 *
 * - clientes.mercadopago_customer_id: el "customer" de Mercado Pago del
 *   pasajero (uno por cliente).
 * - medios_pago_guardados: cada tarjeta vinculada. mp_card_id para
 *   tarjetas guardadas (Customers & Cards: cada cobro pide el CVV);
 *   mp_payment_profile_id para cobros automáticos sin CVV (producto "Pagos
 *   automáticos", requiere habilitación comercial de Mercado Pago).
 *   Consentimiento explícito con fecha, IP y versión del texto aceptado.
 *   La baja es lógica (desvinculado_en) para conservar la trazabilidad.
 * - pagos.mp_orden_id: orden de la API de Orders de un cobro automático.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('mercadopago_customer_id', 64)->nullable()->unique()->after('persona_id');
        });

        Schema::create('medios_pago_guardados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes', 'persona_id')->restrictOnDelete();
            $table->string('proveedor', 30)->default('mercadopago');
            $table->string('mp_customer_id', 64);
            $table->string('mp_card_id', 64)->nullable()->unique();
            $table->string('mp_payment_profile_id', 64)->nullable()->unique();
            $table->string('mp_payment_method_id', 30);
            $table->string('tipo', 20);
            $table->char('ultimos_cuatro', 4)->nullable();
            $table->unsignedTinyInteger('vencimiento_mes')->nullable();
            $table->unsignedSmallInteger('vencimiento_anio')->nullable();
            $table->string('emisor', 80)->nullable();
            $table->boolean('predeterminado')->default(false);
            $table->boolean('cobro_automatico')->default(false);
            $table->dateTime('consentimiento_en');
            $table->string('consentimiento_ip', 45)->nullable();
            $table->string('consentimiento_version', 20);
            $table->dateTime('desvinculado_en')->nullable();
            $table->timestamps();

            $table->index(['cliente_id', 'desvinculado_en']);
        });

        Schema::table('pagos', function (Blueprint $table) {
            $table->string('mp_orden_id', 64)->nullable()->unique()->after('preferencia_expira_en');
        });
    }

    public function down(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropUnique(['mp_orden_id']);
            $table->dropColumn('mp_orden_id');
        });

        Schema::dropIfExists('medios_pago_guardados');

        Schema::table('clientes', function (Blueprint $table) {
            $table->dropUnique(['mercadopago_customer_id']);
            $table->dropColumn('mercadopago_customer_id');
        });
    }
};
