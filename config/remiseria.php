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
    | Geolocalización de conductores (RNF02)
    |--------------------------------------------------------------------------
    |
    | - cache_store: store de cache donde vive el último ping de cada
    |   conductor (Redis en producción). Si no está disponible se usa la
    |   base de datos como respaldo.
    | - persistir_cada_segundos: con el store disponible, la posición se
    |   persiste en MySQL (para la búsqueda espacial de la asignación) a lo
    |   sumo cada N segundos.
    | - velocidad_maxima_kmh: un ping que implica una velocidad mayor
    |   respecto del anterior se rechaza como inconsistente (salto de GPS o
    |   ubicación falsificada).
    | - salto_minimo_metros: desplazamientos menores se consideran ruido del
    |   GPS y no suman al recorrido del viaje.
    | - precision_maxima_metros: pings con una precisión informada peor que
    |   esto actualizan la posición pero no suman al recorrido.
    |
    | Límite de confianza: las coordenadas provienen del dispositivo del
    | conductor. Estas validaciones descartan errores groseros, pero no
    | pueden detectar una falsificación consistente del GPS.
    |
    */

    'ubicacion' => [
        'cache_store' => env('UBICACION_CACHE_STORE', 'redis'),
        'ttl_segundos' => (int) env('UBICACION_TTL_SEGUNDOS', 600),
        'persistir_cada_segundos' => (int) env('UBICACION_PERSISTIR_CADA_SEGUNDOS', 15),
        'velocidad_maxima_kmh' => (float) env('UBICACION_VELOCIDAD_MAXIMA_KMH', 200),
        'salto_minimo_metros' => (float) env('UBICACION_SALTO_MINIMO_METROS', 10),
        'precision_maxima_metros' => (float) env('UBICACION_PRECISION_MAXIMA_METROS', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Finalización de viajes (CU 06)
    |--------------------------------------------------------------------------
    |
    | - radio_tolerancia_metros: distancia máxima (ST_Distance_Sphere) entre
    |   la última posición del conductor y el destino para poder finalizar.
    | - ubicacion_vigencia_segundos: la última posición debe ser al menos así
    |   de reciente; si no, el conductor debe informar su posición actual.
    | - minimo_puntos_seguimiento: con menos tramos GPS registrados durante el
    |   viaje se considera que el recorrido no es confiable y se cobra el
    |   monto cotizado.
    |
    */

    'finalizacion' => [
        'radio_tolerancia_metros' => (float) env('FINALIZACION_RADIO_TOLERANCIA_METROS', 100),
        'ubicacion_vigencia_segundos' => (int) env('FINALIZACION_UBICACION_VIGENCIA_SEGUNDOS', 60),
        'minimo_puntos_seguimiento' => (int) env('FINALIZACION_MINIMO_PUNTOS_SEGUIMIENTO', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Asignación automática de conductores (CU 03)
    |--------------------------------------------------------------------------
    |
    | - tiempo_aceptacion_segundos: vencimiento de cada oferta.
    | - radio_busqueda_metros: solo se consideran conductores a esa distancia
    |   (en línea recta) del origen; es el criterio de "zona geográfica".
    |   Si no hay candidatos, cada reintento amplía el radio en
    |   radio_expansion_paso_metros hasta radio_maximo_metros.
    | - ubicacion_vigencia_segundos: un conductor cuyo último ping GPS es más
    |   viejo que esto no se considera (no se supone que siga cerca).
    | - max_ofertas: tope de conductores a los que se ofrece un mismo viaje.
    | - reintento_sin_candidatos_segundos / max_reintentos_sin_candidatos:
    |   si no hay candidatos se reintenta periódicamente; al agotarse, el
    |   viaje queda solicitado y se avisa al administrador.
    | - pesos: el puntaje es menor cuanto mejor es el candidato:
    |     distancia_km * distancia_km
    |   - calificacion * calificacion (1 a 5; sin historial = calificacion_sin_historial)
    |   - min(antigüedad en años, 10) * antiguedad_anio
    |   + viajes finalizados hoy * viaje_del_dia
    |   Son valores iniciales configurables, no reglas tomadas de la
    |   documentación del negocio.
    |
    */

    'asignacion' => [
        'tiempo_aceptacion_segundos' => (int) env('ASIGNACION_TIEMPO_ACEPTACION_SEGUNDOS', 30),
        'radio_busqueda_metros' => (int) env('ASIGNACION_RADIO_BUSQUEDA_METROS', 10000),
        'radio_expansion_paso_metros' => (int) env('ASIGNACION_RADIO_EXPANSION_PASO_METROS', 2500),
        'radio_maximo_metros' => (int) env('ASIGNACION_RADIO_MAXIMO_METROS', 20000),
        'ubicacion_vigencia_segundos' => (int) env('ASIGNACION_UBICACION_VIGENCIA_SEGUNDOS', 120),
        'max_ofertas' => (int) env('ASIGNACION_MAX_OFERTAS', 10),
        'reintento_sin_candidatos_segundos' => (int) env('ASIGNACION_REINTENTO_SEGUNDOS', 30),
        'max_reintentos_sin_candidatos' => (int) env('ASIGNACION_MAX_REINTENTOS', 20),
        'calificacion_sin_historial' => (float) env('ASIGNACION_CALIFICACION_SIN_HISTORIAL', 4.0),
        'pesos' => [
            'distancia_km' => (float) env('ASIGNACION_PESO_DISTANCIA_KM', 1.0),
            'calificacion' => (float) env('ASIGNACION_PESO_CALIFICACION', 0.5),
            'antiguedad_anio' => (float) env('ASIGNACION_PESO_ANTIGUEDAD_ANIO', 0.1),
            'viaje_del_dia' => (float) env('ASIGNACION_PESO_VIAJE_DEL_DIA', 0.2),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reservas (CU 09)
    |--------------------------------------------------------------------------
    |
    | Una reserva debe pedirse con al menos anticipacion_minima_minutos (un
    | viaje para dentro de menos tiempo es una solicitud inmediata) y con no
    | más de anticipacion_maxima_dias (definidos por el negocio: 60 minutos y
    | 30 días).
    |
    */

    'reservas' => [
        'anticipacion_minima_minutos' => (int) env('RESERVA_ANTICIPACION_MINIMA_MINUTOS', 60),
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
        // Vigencia del precio cerrado devuelto por POST /viajes/cotizar.
        'vigencia_minutos' => (int) env('COTIZACION_VIGENCIA_MINUTOS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reportes administrativos (CU 15 / CU 16)
    |--------------------------------------------------------------------------
    |
    | Cotas para que un reporte no se convierta en una consulta o un archivo
    | desproporcionado: rango máximo de fechas y filas máximas exportables.
    |
    */

    'reportes' => [
        'rango_maximo_dias' => (int) env('REPORTES_RANGO_MAXIMO_DIAS', 366),
        'max_filas_exportacion' => (int) env('REPORTES_MAX_FILAS_EXPORTACION', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Difusión en tiempo real
    |--------------------------------------------------------------------------
    |
    | Conexión de cola para los eventos App\Events\Contracts\DifusionEnTiempoReal
    | (estado del viaje, ofertas a conductores, chat). Vacío = conexión por
    | defecto (QUEUE_CONNECTION). En tests se usa "descartar".
    |
    */

    'difusion' => [
        'conexion_cola' => env('DIFUSION_CONEXION_COLA'),
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
        'webhooks' => (int) env('RATE_LIMIT_WEBHOOKS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Límites contra abuso (ventanas largas)
    |--------------------------------------------------------------------------
    |
    | - login: intentos fallidos por cuenta (email), además del límite por IP
    |   de la ruta. Frena la fuerza bruta distribuida desde muchas IP.
    | - vincular_tarjetas: tarjetas que una cuenta puede vincular por hora,
    |   para que el endpoint no sirva para validar tarjetas robadas
    |   ("card testing").
    |
    */

    'abuso' => [
        'login_intentos_por_cuenta' => (int) env('LOGIN_INTENTOS_POR_CUENTA', 10),
        'login_bloqueo_segundos' => (int) env('LOGIN_BLOQUEO_SEGUNDOS', 900),
        'vincular_tarjetas_por_hora' => (int) env('VINCULAR_TARJETAS_POR_HORA', 5),
    ],

];
