<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rol operativo asociado a una Cuenta de acceso al sistema.
 * No reemplaza a las tablas de subtipo (clientes/conductores/administradores);
 * se guarda además en `cuentas.rol` para poder resolver permisos de forma
 * inmediata en cada request sin tener que consultar las tres tablas de subtipo.
 */
enum RolPersona: string
{
    case Cliente = 'cliente';
    case Conductor = 'conductor';
    case Administrador = 'administrador';

    public function label(): string
    {
        return match ($this) {
            self::Cliente => 'Cliente',
            self::Conductor => 'Conductor',
            self::Administrador => 'Administrador',
        };
    }
}
