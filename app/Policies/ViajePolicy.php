<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RolPersona;
use App\Models\Cuenta;
use App\Models\Viaje;
use Illuminate\Auth\Access\Response;

/**
 * Corrección de auditoría (sección 4.3, "Autorización manual y duplicada"):
 * antes ViajeController y MensajeController tenían cada uno su propio
 * método privado `authorizeAccess()` con la misma lógica copiada y pegada.
 * Cualquier cambio a las reglas de acceso a un viaje (por ejemplo, si en el
 * futuro se agrega un rol "soporte") había que replicarlo manualmente en
 * los dos lugares, con el riesgo de que quedaran desincronizados.
 *
 * Ahora la regla vive en un único lugar y se invoca con
 * `$this->authorize('ver', $viaje)` (trait AuthorizesRequests en
 * App\Http\Controllers\Controller), que Laravel resuelve automáticamente a
 * este método por convención de nombres (Viaje -> ViajePolicy).
 */
class ViajePolicy
{
    /**
     * ¿Puede $cuenta ver/actuar sobre $viaje?
     *
     * Administrador: siempre. Cliente: solo si es el dueño del viaje.
     * Conductor: solo si es el conductor asignado.
     */
    public function ver(Cuenta $cuenta, Viaje $viaje): Response
    {
        $autorizado = match ($cuenta->rol) {
            RolPersona::Administrador => true,
            RolPersona::Cliente => $viaje->cliente_id === $cuenta->persona_id,
            RolPersona::Conductor => $viaje->conductor_id === $cuenta->persona_id,
        };

        return $autorizado
            ? Response::allow()
            : Response::deny('No tiene acceso a este viaje.');
    }
}
