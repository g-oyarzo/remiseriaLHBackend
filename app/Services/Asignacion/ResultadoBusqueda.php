<?php

declare(strict_types=1);

namespace App\Services\Asignacion;

enum ResultadoBusqueda
{
    /** Se creó una oferta para un conductor. */
    case Ofrecida;

    /** El viaje ya tiene una oferta pendiente vigente; no se hace nada. */
    case OfertaEnCurso;

    /** El viaje ya no está buscando conductor (aceptado, cancelado, agotado). */
    case NoAplica;

    /** No hay candidatos en este momento; se puede reintentar más tarde. */
    case SinCandidatos;

    /** Se alcanzó el máximo de ofertas: la búsqueda quedó agotada. */
    case Agotada;
}
