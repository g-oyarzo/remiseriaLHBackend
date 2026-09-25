<?php

declare(strict_types=1);

use App\Enums\EstadoViaje;
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
 * Canal privado del conductor: ubicación GPS (UbicacionConductorActualizada)
 * y despachos de viajes pendientes. Puede escuchar el propio conductor, un
 * administrador, o el cliente que tiene actualmente un viaje activo
 * (aceptado / en curso) asignado a ese conductor (para ver su posición en
 * el mapa en tiempo real).
 */
Broadcast::channel('conductor.{conductorId}', function (Cuenta $cuenta, int $conductorId) {
    if ($cuenta->rol === RolPersona::Administrador) {
        return true;
    }

    if ($cuenta->rol === RolPersona::Conductor) {
        return $cuenta->persona_id === $conductorId;
    }

    // Cliente: solo si tiene un viaje activo con ese conductor asignado.
    return Viaje::query()
        ->where('conductor_id', $conductorId)
        ->where('cliente_id', $cuenta->persona_id)
        ->whereIn('estado', [EstadoViaje::Aceptado, EstadoViaje::EnCurso])
        ->exists();
});