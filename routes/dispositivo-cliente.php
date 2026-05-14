<?php

use App\Http\Controllers\DispositivoClienteController;
use App\Http\Controllers\BulkDestroyController;
use Illuminate\Support\Facades\Route;

Route::middleware('erp.module:dispositivo_cliente')->group(function () {
    Route::get('/modulos/dispositivo-cliente', [DispositivoClienteController::class, 'index'])->name('modules.dispositivo-cliente');
    Route::get('/modulos/dispositivo-cliente/export/{format}', [DispositivoClienteController::class, 'export'])->name('modules.dispositivo-cliente.export')->where('format', 'pdf|xlsx');
    Route::get('/modulos/dispositivo-cliente/crear', [DispositivoClienteController::class, 'create'])->name('modules.dispositivo-cliente.create');
    Route::post('/modulos/dispositivo-cliente', [DispositivoClienteController::class, 'store'])->name('modules.dispositivo-cliente.store');
    Route::get('/modulos/dispositivo-cliente/{id}/editar', [DispositivoClienteController::class, 'edit'])->name('modules.dispositivo-cliente.edit');
    Route::put('/modulos/dispositivo-cliente/{id}', [DispositivoClienteController::class, 'update'])->name('modules.dispositivo-cliente.update');
    Route::get('/modulos/dispositivo-cliente/{id}/lock-status', [DispositivoClienteController::class, 'lockStatus'])->name('modules.dispositivo-cliente.lock-status');
    Route::post('/modulos/dispositivo-cliente/{id}/lock', [DispositivoClienteController::class, 'acquireLock'])->name('modules.dispositivo-cliente.lock');
    Route::post('/modulos/dispositivo-cliente/{id}/unlock', [DispositivoClienteController::class, 'releaseLock'])->name('modules.dispositivo-cliente.unlock');
    Route::delete('/modulos/dispositivo-cliente/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.dispositivo-cliente.bulk-destroy');
    Route::delete('/modulos/dispositivo-cliente/{id}', [DispositivoClienteController::class, 'destroy'])->name('modules.dispositivo-cliente.destroy');
});
