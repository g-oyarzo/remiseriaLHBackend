<?php

declare(strict_types=1);

namespace App\Services\Mapas;

/**
 * El proveedor respondió correctamente, pero no existe un recorrido por
 * calles entre los puntos (por ejemplo, un punto en el agua o en una zona
 * sin calles). No es una falla del servicio: no abre el circuito y, si
 * MAPAS_RECHAZAR_SIN_RUTA=true, el cotizador rechaza la cotización en lugar
 * de inventar un precio por zonas.
 */
final class RutaInexistente extends ServicioMapasNoDisponible {}
