<?php

declare(strict_types=1);

namespace App\Events\Contracts;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Contrato común de los eventos que se difunden por WebSockets (Reverb).
 *
 * - ShouldDispatchAfterCommit: nunca se difunde un cambio que luego se
 *   revierte.
 * - ShouldRescue: si Reverb o la cola no están disponibles, el error se
 *   registra (report()) pero no interrumpe la operación de negocio, que ya
 *   quedó confirmada en la base. Los clientes recuperan el estado vigente
 *   por REST al reconectarse.
 * - La conexión de cola se puede fijar con DIFUSION_CONEXION_COLA (ver
 *   AppServiceProvider); en tests apunta a una conexión que descarta los
 *   mensajes para no intentar conectarse a un Reverb real.
 */
interface DifusionEnTiempoReal extends ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue {}
