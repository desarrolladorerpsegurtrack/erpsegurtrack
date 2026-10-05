<?php

use App\Http\Controllers\BancosController;
use Illuminate\Support\Facades\Route;

Route::middleware('erp.module:finanzas')->group(function () {
    Route::get('/modulos/finanzas/estado-cuenta', [BancosController::class, 'estadoCuenta'])
        ->name('modules.finanzas.estado-cuenta.index');
    Route::get('/modulos/finanzas/estado-cuenta/export/{format}', [BancosController::class, 'exportEstadoCuenta'])
        ->name('modules.finanzas.estado-cuenta.export')
        ->where('format', 'pdf|xlsx');
    Route::post('/modulos/finanzas/estado-cuenta/export/{format}', [BancosController::class, 'exportEstadoCuenta'])
        ->name('modules.finanzas.estado-cuenta.export.post')
        ->where('format', 'pdf|xlsx');
    Route::get('/modulos/finanzas/bancos', [BancosController::class, 'index'])->name('modules.finanzas.bancos.index');
    Route::get('/modulos/finanzas/bancos/export/{format}', [BancosController::class, 'export'])
        ->name('modules.finanzas.bancos.export')
        ->where('format', 'pdf|xlsx');
    Route::post('/modulos/finanzas/bancos/export/{format}', [BancosController::class, 'export'])
        ->name('modules.finanzas.bancos.export.post')
        ->where('format', 'pdf|xlsx');
    Route::get('/modulos/finanzas/nota-creditos/export/{format}', [BancosController::class, 'exportNotaCreditos'])
        ->name('modules.finanzas.nota-creditos.export')
        ->where('format', 'pdf|xlsx');
    Route::post('/modulos/finanzas/nota-creditos/export/{format}', [BancosController::class, 'exportNotaCreditos'])
        ->name('modules.finanzas.nota-creditos.export.post')
        ->where('format', 'pdf|xlsx');
    Route::get('/modulos/finanzas/bancos/crear', [BancosController::class, 'create'])->name('modules.finanzas.bancos.create');
    Route::post('/modulos/finanzas/bancos', [BancosController::class, 'store'])->name('modules.finanzas.bancos.store');
    Route::get('/modulos/finanzas/bancos/{id}/editar', [BancosController::class, 'edit'])->name('modules.finanzas.bancos.edit');
    Route::put('/modulos/finanzas/bancos/{id}', [BancosController::class, 'update'])->name('modules.finanzas.bancos.update');
    Route::delete('/modulos/finanzas/bancos/bulk-destroy', [BancosController::class, 'bulkDestroy'])->name('modules.finanzas.bancos.bulk-destroy');
    Route::delete('/modulos/finanzas/bancos/{id}', [BancosController::class, 'destroy'])->name('modules.finanzas.bancos.destroy');
    Route::get('/modulos/finanzas/nota-creditos', [BancosController::class, 'notaCreditos'])
        ->name('modules.finanzas.nota-creditos.index');
});
