<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:notificar-servicios-por-vencer')->everyMinute();
Schedule::command('cxc:verificar-vencidos')->dailyAt('00:05')->withoutOverlapping();
Schedule::command('servicios:procesar-bajas-programadas')->dailyAt('00:10')->withoutOverlapping();
Schedule::command('cxc:generar-periodos')->monthlyOn(1, '07:00')->withoutOverlapping();

Schedule::call(function () {
    Log::info('El programador de Laravel se ejecutó correctamente.');
})->everyMinute();