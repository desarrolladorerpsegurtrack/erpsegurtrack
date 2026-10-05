<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

Schedule::command('app:notificar-servicios-por-vencer')->everyMinute();
Schedule::command('cxc:verificar-vencidos')->dailyAt('00:05')->withoutOverlapping();
Schedule::command('cxc:generar-periodos')->cron('0 */3 1 * *')->withoutOverlapping();

