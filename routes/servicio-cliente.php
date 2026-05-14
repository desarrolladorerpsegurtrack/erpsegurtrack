<?php

use App\Http\Controllers\ServicioClienteController;
use App\Http\Controllers\BulkDestroyController;
use Illuminate\Support\Facades\Route;

Route::middleware('erp.module:servicio_cliente')->group(function () {
    Route::get('/modulos/servicio-cliente', [ServicioClienteController::class, 'index'])->name('modules.servicio-cliente');
    Route::get('/modulos/servicio-cliente/export/{format}', [ServicioClienteController::class, 'export'])->name('modules.servicio-cliente.export')->where('format', 'pdf|xlsx');
    Route::get('/modulos/servicio-cliente/crear', [ServicioClienteController::class, 'create'])->name('modules.servicio-cliente.create');
    Route::post('/modulos/servicio-cliente', [ServicioClienteController::class, 'store'])->name('modules.servicio-cliente.store');
    Route::get('/modulos/servicio-cliente/{id}/editar', [ServicioClienteController::class, 'edit'])->name('modules.servicio-cliente.edit');
    Route::get('/modulos/servicio-cliente/{id}/lock-status', [ServicioClienteController::class, 'lockStatus'])->name('modules.servicio-cliente.lock-status');
    Route::post('/modulos/servicio-cliente/{id}/lock', [ServicioClienteController::class, 'acquireLock'])->name('modules.servicio-cliente.lock');
    Route::post('/modulos/servicio-cliente/{id}/unlock', [ServicioClienteController::class, 'releaseLock'])->name('modules.servicio-cliente.unlock');
    Route::put('/modulos/servicio-cliente/{id}', [ServicioClienteController::class, 'update'])->name('modules.servicio-cliente.update');
    Route::delete('/modulos/servicio-cliente/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.servicio-cliente.bulk-destroy');
    Route::delete('/modulos/servicio-cliente/{id}', [ServicioClienteController::class, 'destroy'])->name('modules.servicio-cliente.destroy');
});