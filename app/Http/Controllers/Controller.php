<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Tamaño de página acotado a [1, 100]: sin tope, un per_page enorme
     * obligaba a la base a materializar y serializar tablas completas.
     */
    protected function porPagina(Request $request, int $porDefecto = 15): int
    {
        return max(1, min(100, (int) $request->input('per_page', $porDefecto)));
    }
}
