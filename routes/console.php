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