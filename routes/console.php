<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduler
|--------------------------------------------------------------------------
|
| Corrección de auditoría (HALL-006): sin esto, los viajes "programado"
| quedaban en estado "solicitado" indefinidamente, sin ningún mecanismo que
| los hiciera visibles/asignables a conductores cuando se acercaba su hora.
|
| Requiere que el cron de Laravel esté corriendo en el servidor
| (`* * * * * php artisan schedule:run`) o, en Docker, un contenedor
| dedicado ejecutando `php artisan schedule:work` (ver compose.yaml).
| withoutOverlapping() evita que dos ejecuciones se pisen si una corrida
| tarda más de un minuto (además del lockForUpdate dentro del propio
| comando, que protege el caso en que igual lleguen a superponerse).
|
*/
Schedule::command('viajes:despachar-programados')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Red de seguridad de la asignación automática (CU 03): vence ofertas sin
// respuesta cuyo job se haya perdido y reactiva búsquedas detenidas. Ver
// App\Console\Commands\SupervisarDespachoCommand.
Schedule::command('viajes:supervisar-despacho')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Concilia contra la API de Mercado Pago los cobros pendientes cuyo webhook
// se haya perdido (App\Console\Commands\ConciliarMercadoPagoCommand). Sin
// MERCADOPAGO_ACCESS_TOKEN no hace nada.
Schedule::command('pagos:conciliar-mercadopago')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Elimina las cotizaciones emitidas vencidas hace más de una semana
// (App\Models\CotizacionEmitida::prunable()).
Schedule::command('model:prune', ['--model' => [App\Models\CotizacionEmitida::class]])
    ->daily()
    ->onOneServer();
