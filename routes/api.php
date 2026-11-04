<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClienteController;
use App\Http\Controllers\Api\V1\ComprobanteController;
use App\Http\Controllers\Api\V1\ConductorController;
use App\Http\Controllers\Api\V1\CotizacionController;
use App\Http\Controllers\Api\V1\MarcaController;
use App\Http\Controllers\Api\V1\MensajeController;
use App\Http\Controllers\Api\V1\PagoController;
use App\Http\Controllers\Api\V1\RegistroComprobantesController;
use App\Http\Controllers\Api\V1\ReporteController;
use App\Http\Controllers\Api\V1\SolicitudViajeController;
use App\Http\Controllers\Api\V1\TarifaController;
use App\Http\Controllers\Api\V1\UbicacionController;
use App\Http\Controllers\Api\V1\VehiculoController;
use App\Http\Controllers\Api\V1\ViajeController;
use App\Http\Controllers\Api\V1\ZonaTarifariaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Prefix: /api/v1 (configured in bootstrap/app.php)
|
*/

// Rutas Públicas
Route::middleware('throttle:6,1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/register', [AuthController::class, 'register']);
});

// Rutas Autenticadas (cualquier rol)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/refresh-token', [AuthController::class, 'refreshToken']);

    Route::get('/marcas', [MarcaController::class, 'index']);
    Route::get('/tarifas/vigente', [TarifaController::class, 'vigente']);
    
    // Viajes (comunes)
    Route::get('/viajes', [ViajeController::class, 'index']);
    Route::get('/viajes/{viaje}', [ViajeController::class, 'show']);
    Route::patch('/viajes/{viaje}/cancelar', [ViajeController::class, 'cancelar']);
    Route::get('/viajes/{viaje}/comprobante', [ComprobanteController::class, 'show']);
    
    // Mensajes
    Route::get('/viajes/{viaje}/mensajes', [MensajeController::class, 'index']);
    Route::post('/viajes/{viaje}/mensajes', [MensajeController::class, 'store'])->middleware('throttle:chat');
    Route::patch('/viajes/{viaje}/mensajes/marcar-leidos', [MensajeController::class, 'marcarLeidos']);
});

// Cotización (CU 02): apps de clientes y panel administrativo. Tiene su
// propio límite porque cada llamada puede consumir cuota del servicio de
// mapas.
Route::middleware(['auth:sanctum', 'role:cliente,administrador', 'throttle:cotizar'])
    ->post('/viajes/cotizar', [CotizacionController::class, 'cotizar']);

// Rutas Cliente
Route::middleware(['auth:sanctum', 'role:cliente'])->prefix('cliente')->group(function () {
    Route::post('/viajes', [ViajeController::class, 'store'])->middleware('throttle:viajes');
    Route::post('/viajes/solicitar', [SolicitudViajeController::class, 'solicitar'])->middleware('throttle:viajes');
    Route::post('/viajes/reservar', [SolicitudViajeController::class, 'reservar'])->middleware('throttle:viajes');
    Route::patch('/viajes/{viaje}/calificar', [ViajeController::class, 'calificar']);
});

// Rutas Conductor
Route::middleware(['auth:sanctum', 'role:conductor'])->prefix('conductor')->group(function () {
    Route::patch('/estado-servicio', [ConductorController::class, 'toggleServicio']);
    Route::match(['post', 'patch'], '/ubicacion', [UbicacionController::class, 'update'])->middleware('throttle:ubicacion');

    Route::get('/viajes/pendientes', [ViajeController::class, 'pendientes']);
    // POST es el contrato de Hacer.txt; PATCH se conserva por compatibilidad.
    Route::match(['post', 'patch'], '/viajes/{viaje}/aceptar', [ViajeController::class, 'aceptar']);
    Route::match(['post', 'patch'], '/viajes/{viaje}/rechazar', [ViajeController::class, 'rechazar']);
    Route::patch('/viajes/{viaje}/iniciar', [ViajeController::class, 'iniciar']);
    Route::match(['post', 'patch'], '/viajes/{viaje}/finalizar', [ViajeController::class, 'finalizar']);
});

// Corrección: PagoController::store() está escrito para permitir tanto al
// conductor asignado como a un administrador ("Admin o el conductor del
// viaje pueden registrar el pago"), pero esta ruta vivía antes dentro del
// grupo role:conductor exclusivo de arriba, así que un admin nunca podía
// alcanzarla: el middleware lo bloqueaba con 403 antes de llegar al
// controlador, sin importar lo que dijera su lógica interna.
Route::middleware(['auth:sanctum', 'role:conductor,administrador', 'throttle:pagos'])
    ->post('/conductor/viajes/{viaje}/pago', [PagoController::class, 'store']);

// Rutas Administrador
Route::middleware(['auth:sanctum', 'role:administrador'])->group(function () {
    // Corrección: ConductorController::cercanos() estaba completamente
    // implementado (con su propio docblock "GET /api/v1/conductores/cercanos")
    // pero nunca se había registrado ninguna ruta hacia él, por lo que era
    // inalcanzable desde afuera. Se restringe a administradores porque su
    // propio docblock indica que es "para despachador".
    Route::get('/conductores/cercanos', [ConductorController::class, 'cercanos']);
});

Route::middleware(['auth:sanctum', 'role:administrador'])->prefix('admin')->group(function () {
    Route::apiResource('/clientes', ClienteController::class)->only(['index', 'show']);
    Route::apiResource('/conductores', ConductorController::class)
        ->only(['index', 'show', 'store', 'update', 'destroy'])
        // apiResource singulariza 'conductores' a '{conductore}'. Se mapea explícitamente
        // para que el binding de ConductorController ($conductor) funcione.
        ->parameters(['conductores' => 'conductor']);
    Route::patch('/conductores/{conductor}/estado', [ConductorController::class, 'actualizarEstado']);
    // Sin destroy: no hay baja de vehículos en los casos de uso y borrar uno
    // rompería el historial de viajes. Antes la ruta DELETE existía sin
    // método en el controlador y respondía 500.
    Route::apiResource('/vehiculos', VehiculoController::class)->only(['index', 'store', 'show', 'update']);

    Route::post('/viajes/registrar', [SolicitudViajeController::class, 'registrarTelefonico']);
    Route::post('/viajes/{viaje}/reasignar', [ViajeController::class, 'reasignar']);

    Route::get('/pagos', [PagoController::class, 'index']);
    Route::patch('/pagos/{pago}/conciliar', [PagoController::class, 'conciliar']);

    // Registro de comprobantes: solo lectura y descarga (sin rutas de
    // modificación ni borrado).
    Route::get('/comprobantes', [RegistroComprobantesController::class, 'index']);
    Route::get('/comprobantes/{comprobante}', [RegistroComprobantesController::class, 'show']);
    Route::get('/comprobantes/{comprobante}/descargar', [RegistroComprobantesController::class, 'descargar']);

    Route::get('/reportes/estadisticas', [ReporteController::class, 'estadisticas']);
    Route::get('/reportes/exportar', [ReporteController::class, 'exportar'])->middleware('throttle:reportes');
    
    Route::get('/tarifas', [TarifaController::class, 'index']);
    Route::put('/tarifas', [TarifaController::class, 'actualizar']);
    Route::post('/tarifas', [TarifaController::class, 'store']);
    Route::get('/tarifas/historial', [TarifaController::class, 'historial']);

    Route::apiResource('/zonas-tarifarias', ZonaTarifariaController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['zonas-tarifarias' => 'zona']);
});