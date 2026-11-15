<?php

declare(strict_types=1);

namespace App\Services\Mapas;

/**
 * El proveedor respondió HTTP 429 (cuota diaria o ritmo por minuto
 * excedidos). Abre el circuito de inmediato para no insistir.
 */
final class LimiteDelProveedorAlcanzado extends ServicioMapasNoDisponible {}
