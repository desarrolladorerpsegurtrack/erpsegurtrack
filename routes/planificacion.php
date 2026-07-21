<?php

use App\Http\Controllers\PlanificacionController;
use Illuminate\Support\Facades\Route;

Route::get('/modulos/planificacion', [PlanificacionController::class, 'index'])
    ->name('modules.planificacion');
