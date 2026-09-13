<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\EstadoViaje;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Corrección de auditoría (sección 4.2, "Sin eventos de dominio en
 * transiciones de estado"): antes, cuando un viaje cambiaba de estado
 * (aceptado, iniciado, finalizado, cancelado), no se disparaba ningún
 * evento; toda la lógica quedaba atada directamente a cada método del
 * controlador. Eso impedía agregar suscriptores futuros (notificaciones
 * push, auditoría, estadísticas) sin modificar cada controlador uno por
 * uno.
 *
 * Este evento se dispara automáticamente desde Viaje::booted() cada vez que
 * cambia el atributo `estado`, sin importar desde qué controlador o
 * comando se haya originado el cambio. No implementa ShouldBroadcast: es un
 * evento de dominio interno para listeners de la aplicación (colas de
 * notificaciones, auditoría, etc.), no un mensaje en tiempo real hacia el
 * cliente — para eso ya existen NuevoMensajeViaje y
 * UbicacionConductorActualizada.
 *
 * No incluye el estado anterior a propósito: para los casos de uso típicos
 * (notificar, auditar, contar) alcanza con saber a qué estado llegó el
 * viaje; quien necesite el historial completo puede consultar la tabla
 * `viajes` o, más adelante, una tabla de auditoría dedicada.
 */
class ViajeCambioEstado
{
    use Dispatchable;

    public function __construct(
        public readonly int $viajeId,
        public readonly EstadoViaje $estadoNuevo,
    ) {
    }
}
