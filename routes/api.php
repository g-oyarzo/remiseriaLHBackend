<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClienteController;
use App\Http\Controllers\Api\V1\ConductorController;
use App\Http\Controllers\Api\V1\MarcaController;
use App\Http\Controllers\Api\V1\MensajeController;
use App\Http\Controllers\Api\V1\PagoController;
use App\Http\Controllers\Api\V1\TarifaController;
use App\Http\Controllers\Api\V1\UbicacionController;
use App\Http\Controllers\Api\V1\VehiculoController;
use App\Http\Controllers\Api\V1\ViajeController;
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
    
    // Mensajes
    Route::get('/viajes/{viaje}/mensajes', [MensajeController::class, 'index']);
    Route::post('/viajes/{viaje}/mensajes', [MensajeController::class, 'store']);
    Route::patch('/viajes/{viaje}/mensajes/marcar-leidos', [MensajeController::class, 'marcarLeidos']);
});

// Rutas Cliente
Route::middleware(['auth:sanctum', 'role:cliente'])->prefix('cliente')->group(function () {
    Route::post('/viajes', [ViajeController::class, 'store']);
    Route::patch('/viajes/{viaje}/calificar', [ViajeController::class, 'calificar']);
});

// Rutas Conductor
Route::middleware(['auth:sanctum', 'role:conductor'])->prefix('conductor')->group(function () {
    Route::patch('/estado-servicio', [ConductorController::class, 'toggleServicio']);
    Route::patch('/ubicacion', [UbicacionController::class, 'update']);
    
    Route::get('/viajes/pendientes', [ViajeController::class, 'pendientes']);
    Route::patch('/viajes/{viaje}/aceptar', [ViajeController::class, 'aceptar']);
    Route::patch('/viajes/{viaje}/iniciar', [ViajeController::class, 'iniciar']);
    Route::patch('/viajes/{viaje}/finalizar', [ViajeController::class, 'finalizar']);
    Route::post('/viajes/{viaje}/pago', [PagoController::class, 'store']);
});

// Rutas Administrador
Route::middleware(['auth:sanctum', 'role:administrador'])->prefix('admin')->group(function () {
    Route::apiResource('/clientes', ClienteController::class)->only(['index', 'show']);
    Route::apiResource('/conductores', ConductorController::class)->only(['index', 'show']);
    Route::apiResource('/vehiculos', VehiculoController::class);
    
    Route::get('/tarifas', [TarifaController::class, 'index']);
    Route::post('/tarifas', [TarifaController::class, 'store']);
});