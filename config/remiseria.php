<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Factor de corrección de distancia
    |--------------------------------------------------------------------------
    |
    | Corrección de auditoría (HALL-010): el costo estimado de un viaje se
    | calcula con la distancia en línea recta (Haversine) entre origen y
    | destino (ver App\ValueObjects\Coordinate::distanciaEnMetrosHacia()).
    | En una ciudad con trazado en cuadrícula como La Plata, la distancia
    | real que recorre un vehículo suele ser entre un 40% y un 60% mayor a
    | la distancia en línea recta, por lo que sin corrección los clientes
    | terminan pagando sistemáticamente menos de lo que cuesta el viaje.
    |
    | Esto es una solución provisoria de bajo costo: la corrección ideal es
    | integrar una API de ruteo real (Google Maps Routes API, OSRM, etc.)
    | que calcule la distancia real de manejo. Mientras tanto, se aplica
    | este factor multiplicador configurable sobre la distancia en línea
    | recta antes de calcular el costo (ver ViajeController::store()).
    |
    */

    'factor_correccion_distancia' => (float) env('DISTANCIA_FACTOR_CORRECCION', 1.4),

    /*
    |--------------------------------------------------------------------------
    | Ventana de despacho de viajes programados
    |--------------------------------------------------------------------------
    |
    | Corrección de auditoría (HALL-006): los viajes tipo "programado" no
    | tenían ningún mecanismo que los hiciera visibles/asignables a
    | conductores cuando se acercaba su hora; quedaban en estado
    | "solicitado" indefinidamente. Este valor define, en minutos, con
    | cuánta anticipación a `fecha_viaje` un viaje programado empieza a
    | aparecer en GET /conductor/viajes/pendientes y a notificarse por
    | WebSocket a los conductores disponibles (ver
    | App\Console\Commands\DespacharViajesProgramadosCommand, corrido cada
    | minuto por el scheduler en routes/console.php).
    |
    */

    'ventana_despacho_programados_minutos' => (int) env('VENTANA_DESPACHO_PROGRAMADOS_MINUTOS', 15),

];
