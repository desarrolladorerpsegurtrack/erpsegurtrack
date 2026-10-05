<?php

use App\Http\Controllers\CuentasPorCobrarController;
use App\Http\Controllers\BulkDestroyController;
use Illuminate\Support\Facades\Route;

Route::middleware('erp.module:cuentasporcobrar')->group(function () {
    Route::get('/modulos/cuentas-por-cobrar', [CuentasPorCobrarController::class, 'index'])->name('modules.cuentasporcobrar');
    Route::get('/modulos/cuentas-por-cobrar/export/{format}', [CuentasPorCobrarController::class, 'export'])->name('modules.cuentasporcobrar.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/cuentas-por-cobrar/export/{format}', [CuentasPorCobrarController::class, 'export'])->name('modules.cuentasporcobrar.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/cuentas-por-cobrar/cotizaciones/export/{format}', [CuentasPorCobrarController::class, 'exportQuotations'])->name('modules.cuentasporcobrar.quotations.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/cuentas-por-cobrar/cotizaciones/export/{format}', [CuentasPorCobrarController::class, 'exportQuotations'])->name('modules.cuentasporcobrar.quotations.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/cuentas-por-cobrar/crear', [CuentasPorCobrarController::class, 'create'])->name('modules.cuentasporcobrar.create');
    Route::post('/modulos/cuentas-por-cobrar', [CuentasPorCobrarController::class, 'store'])->name('modules.cuentasporcobrar.store');
    Route::post('/modulos/cuentas-por-cobrar/cobrar-servicios', [CuentasPorCobrarController::class, 'cobrarServicios'])->name('modules.cuentasporcobrar.cobrar-servicios');
    Route::patch('/modulos/cuentas-por-cobrar/servicio/{id}/precio', [CuentasPorCobrarController::class, 'updateServicePrice'])
        ->middleware('erp.action:cuentasporcobrar,editar')
        ->name('modules.cuentasporcobrar.servicios.precio');
    Route::post('/modulos/cuentas-por-cobrar/{id}/facturar', [CuentasPorCobrarController::class, 'registrarFactura'])->name('modules.cuentasporcobrar.facturar');
    Route::post('/modulos/cuentas-por-cobrar/{id}/revertir-pago', [CuentasPorCobrarController::class, 'revertirPago'])->name('modules.cuentasporcobrar.revertir-pago');
    Route::post('/modulos/cuentas-por-cobrar/dar-de-baja-servicios', [CuentasPorCobrarController::class, 'darDeBajaServicios'])->name('modules.cuentasporcobrar.dar-de-baja-servicios');
    Route::get('/modulos/cuentas-por-cobrar/{id}/detalle', [CuentasPorCobrarController::class, 'getPaymentDetails'])->name('modules.cuentasporcobrar.detalle');
    Route::get('/modulos/cuentas-por-cobrar/cliente/{id}/deudas', [CuentasPorCobrarController::class, 'getDeudasCliente'])->name('modules.cuentasporcobrar.cliente.deudas');
    Route::get('/modulos/cuentas-por-cobrar/{id}/editar', [CuentasPorCobrarController::class, 'edit'])->name('modules.cuentasporcobrar.edit');
    Route::post('/modulos/cuentas-por-cobrar/cotizacion/{id}/aprobar', [CuentasPorCobrarController::class, 'approveQuotation'])->name('modules.cuentasporcobrar.quotations.approve');
    Route::get('/modulos/cuentas-por-cobrar/{id}/lock-status', [CuentasPorCobrarController::class, 'lockStatus'])->name('modules.cuentasporcobrar.lock-status');
    Route::post('/modulos/cuentas-por-cobrar/{id}/lock', [CuentasPorCobrarController::class, 'acquireLock'])->name('modules.cuentasporcobrar.lock');
    Route::post('/modulos/cuentas-por-cobrar/{id}/unlock', [CuentasPorCobrarController::class, 'releaseLock'])->name('modules.cuentasporcobrar.unlock');
    Route::put('/modulos/cuentas-por-cobrar/{id}', [CuentasPorCobrarController::class, 'update'])->name('modules.cuentasporcobrar.update');
    Route::delete('/modulos/cuentas-por-cobrar/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.cuentasporcobrar.bulk-destroy');
    Route::delete('/modulos/cuentas-por-cobrar/{id}', [CuentasPorCobrarController::class, 'destroy'])->name('modules.cuentasporcobrar.destroy');
});
