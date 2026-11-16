<?php

declare(strict_types=1);

use App\Enums\RolPersona;
use App\Models\Cuenta;
use App\Models\Viaje;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Aquí se registran las autorizaciones de los canales privados usados para
| el rastreo GPS de conductores y el chat interno de cada viaje. Todas las
| solicitudes de autorización llegan autenticadas vía Sanctum (ver
| bootstrap/app.php -> withBroadcasting), por lo que $cuenta siempre es la
| instancia de App\Models\Cuenta del usuario autenticado.
|
*/

/**
 * Canal privado del viaje: chat interno (NuevoMensajeViaje) y actualizaciones
 * de estado. Solo pueden escuchar el cliente que lo solicitó, el conductor
 * asignado o un administrador.
 */
Broadcast::channel('viaje.{viajeId}', function (Cuenta $cuenta, int $viajeId) {
    if ($cuenta->rol === RolPersona::Administrador) {
        return true;
    }

    $viaje = Viaje::query()->find($viajeId);

    if (! $viaje) {
        return false;
    }

    if ($cuenta->persona_id == $viaje->cliente_id) {
        return true;
    }

    if ($viaje->conductor_id !== null && $cuenta->persona_id == $viaje->conductor_id) {
        return true;
    }

    return false;
});

/**
 * Canal privado del conductor: su ubicación GPS y sus ofertas de viaje
 * (asignacion.ofrecida / asignacion.retirada, con origen y destino de otros
 * pasajeros). Solo el propio conductor y los administradores.
 *
 * Seguridad (SEC-01): antes también podía suscribirse el cliente con un viaje
 * activo con ese conductor. Reverb autoriza solo al suscribirse, así que al
 * terminar el viaje el cliente seguía recibiendo la posición del conductor y
 * las ofertas con direcciones de otros pasajeros. El pasajero recibe la
 * ubicación por el canal viaje.{id}, y solo mientras su viaje está activo.
 */
Broadcast::channel('conductor.{conductorId}', function (Cuenta $cuenta, int $conductorId): bool {
    return match ($cuenta->rol) {
        RolPersona::Administrador => true,
        RolPersona::Conductor => $cuenta->persona_id === $conductorId,
        default => false,
    };
});

/**
 * Canal del panel de despacho: alertas operativas (búsquedas de conductor
 * agotadas) y posiciones de la flota. Solo administradores.
 */
Broadcast::channel('admin.despacho', fn (Cuenta $cuenta): bool => $cuenta->rol === RolPersona::Administrador);
