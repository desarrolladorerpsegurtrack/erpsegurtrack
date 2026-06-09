<?php

use App\Http\Controllers\VehiculosController;
use App\Http\Controllers\BulkDestroyController;
use Illuminate\Support\Facades\Route;

Route::middleware('erp.module:vehiculos')->group(function () {
    Route::get('/modulos/vehiculos', [VehiculosController::class, 'index'])->name('modules.vehiculos');
    Route::get('/modulos/vehiculos/export/{format}', [VehiculosController::class, 'export'])->name('modules.vehiculos.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/vehiculos/export/{format}', [VehiculosController::class, 'export'])->name('modules.vehiculos.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/vehiculos/crear', [VehiculosController::class, 'create'])->name('modules.vehiculos.create');
    Route::post('/modulos/vehiculos', [VehiculosController::class, 'store'])->name('modules.vehiculos.store');
    Route::get('/modulos/vehiculos/{placa}/editar', [VehiculosController::class, 'edit'])->name('modules.vehiculos.edit');
    Route::get('/modulos/vehiculos/{placa}/lock-status', [VehiculosController::class, 'lockStatus'])->name('modules.vehiculos.lock-status');
    Route::post('/modulos/vehiculos/{placa}/lock', [VehiculosController::class, 'acquireLock'])->name('modules.vehiculos.lock');
    Route::post('/modulos/vehiculos/{placa}/unlock', [VehiculosController::class, 'releaseLock'])->name('modules.vehiculos.unlock');

    Route::put('/modulos/vehiculos/{placa}', [VehiculosController::class, 'update'])->name('modules.vehiculos.update');
    Route::delete('/modulos/vehiculos/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.vehiculos.bulk-destroy');
    Route::delete('/modulos/vehiculos/{placa}', [VehiculosController::class, 'destroy'])->name('modules.vehiculos.destroy');
});