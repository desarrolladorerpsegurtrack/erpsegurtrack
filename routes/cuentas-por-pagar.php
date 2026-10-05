<?php

use App\Http\Controllers\CuentasPorPagarController;
use App\Http\Controllers\BulkDestroyController;
use Illuminate\Support\Facades\Route;

Route::middleware('erp.module:cuentasporpagar')->group(function () {
    Route::get('/modulos/cuentas-por-pagar', [CuentasPorPagarController::class, 'index'])->name('modules.cuentasporpagar');
    Route::get('/modulos/cuentas-por-pagar/export/{format}', [CuentasPorPagarController::class, 'export'])->name('modules.cuentasporpagar.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/cuentas-por-pagar/export/{format}', [CuentasPorPagarController::class, 'export'])->name('modules.cuentasporpagar.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/cuentas-por-pagar/crear', [CuentasPorPagarController::class, 'create'])->name('modules.cuentasporpagar.create');
    Route::post('/modulos/cuentas-por-pagar', [CuentasPorPagarController::class, 'store'])->name('modules.cuentasporpagar.store');
    Route::get('/modulos/cuentas-por-pagar/{id}/editar', [CuentasPorPagarController::class, 'edit'])->name('modules.cuentasporpagar.edit');
    Route::get('/modulos/cuentas-por-pagar/{id}/lock-status', [CuentasPorPagarController::class, 'lockStatus'])->name('modules.cuentasporpagar.lock-status');
    Route::post('/modulos/cuentas-por-pagar/{id}/lock', [CuentasPorPagarController::class, 'acquireLock'])->name('modules.cuentasporpagar.lock');
    Route::post('/modulos/cuentas-por-pagar/{id}/unlock', [CuentasPorPagarController::class, 'releaseLock'])->name('modules.cuentasporpagar.unlock');
    Route::put('/modulos/cuentas-por-pagar/{id}', [CuentasPorPagarController::class, 'update'])->name('modules.cuentasporpagar.update');
    Route::delete('/modulos/cuentas-por-pagar/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.cuentasporpagar.bulk-destroy');
    Route::delete('/modulos/cuentas-por-pagar/{id}', [CuentasPorPagarController::class, 'destroy'])->name('modules.cuentasporpagar.destroy');
});
