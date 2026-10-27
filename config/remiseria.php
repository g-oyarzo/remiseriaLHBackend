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

    /*
    |--------------------------------------------------------------------------
    | Reservas (CU 09)
    |--------------------------------------------------------------------------
    |
    | Una reserva debe pedirse con al menos anticipacion_minima_minutos (un
    | viaje para dentro de menos tiempo es una solicitud inmediata) y con no
    | más de anticipacion_maxima_dias. Ambos valores son supuestos operativos
    | configurables, no reglas tomadas de la documentación del negocio.
    |
    */

    'reservas' => [
        'anticipacion_minima_minutos' => (int) env('RESERVA_ANTICIPACION_MINIMA_MINUTOS', 30),
        'anticipacion_maxima_dias' => (int) env('RESERVA_ANTICIPACION_MAXIMA_DIAS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cotización (CU 02)
    |--------------------------------------------------------------------------
    |
    | Límites de cordura del trayecto cotizado, medidos en línea recta:
    | origen y destino a menos de distancia_minima_metros se consideran el
    | mismo punto, y no se cotizan viajes más largos que distancia_maxima_km.
    | El servicio de mapas se configura en config/services.php ("mapas").
    |
    */

    'cotizacion' => [
        'distancia_minima_metros' => (float) env('COTIZACION_DISTANCIA_MINIMA_METROS', 50),
        'distancia_maxima_km' => (float) env('COTIZACION_DISTANCIA_MAXIMA_KM', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Límites de frecuencia (solicitudes por minuto)
    |--------------------------------------------------------------------------
    |
    | Se registran como RateLimiter con nombre en AppServiceProvider y se
    | aplican en routes/api.php con el middleware throttle:<nombre>. "api"
    | es el límite general de toda la API autenticada.
    |
    */

    'rate_limits' => [
        'api' => (int) env('RATE_LIMIT_API', 120),
        'ubicacion' => (int) env('RATE_LIMIT_UBICACION', 60),
        'chat' => (int) env('RATE_LIMIT_CHAT', 30),
        'cotizar' => (int) env('RATE_LIMIT_COTIZAR', 30),
        'viajes' => (int) env('RATE_LIMIT_VIAJES', 10),
        'pagos' => (int) env('RATE_LIMIT_PAGOS', 20),
        'reportes' => (int) env('RATE_LIMIT_REPORTES', 10),
    ],

];
