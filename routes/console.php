<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

Schedule::command('app:notificar-servicios-por-vencer')->everyMinute();
Schedule::command('cxc:verificar-vencidos')->dailyAt('00:05')->withoutOverlapping();
Schedule::command('servicios:procesar-bajas-programadas')->dailyAt('00:10')->withoutOverlapping();
Schedule::command('cxc:generar-periodos')->monthlyOn(1, '07:00')->withoutOverlapping();
