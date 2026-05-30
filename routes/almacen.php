<?php

use App\Http\Controllers\AlmacenController;
use App\Http\Controllers\AlmacenNotaIngresoController;
use App\Http\Controllers\AlmacenNotaSalidaController;
use App\Http\Controllers\AlmacenPlanesServiciosController;
use Illuminate\Support\Facades\Route;

Route::middleware('erp.module:almacen')->group(function () {
    Route::get('/modulos/almacen', [AlmacenController::class, 'index'])->name('modules.almacen');
    Route::get('/modulos/almacen/export/{format}', [AlmacenController::class, 'export'])->name('modules.almacen.export')->where('format', 'pdf|xlsx');
    Route::get('/modulos/almacen/crear', [AlmacenController::class, 'create'])->name('modules.almacen.create');
    Route::post('/modulos/almacen', [AlmacenController::class, 'store'])->name('modules.almacen.store');
    Route::get('/modulos/almacen/{id}/editar', [AlmacenController::class, 'edit'])->name('modules.almacen.edit');
    Route::put('/modulos/almacen/{id}', [AlmacenController::class, 'update'])->name('modules.almacen.update');
    Route::delete('/modulos/almacen/bulk-destroy', [\App\Http\Controllers\BulkDestroyController::class, 'destroy'])->name('modules.almacen.bulk-destroy');
    Route::delete('/modulos/almacen/{id}', [AlmacenController::class, 'destroy'])->name('modules.almacen.destroy');
});

Route::middleware('erp.module:almacen.planes_servicios')->group(function () {
    Route::get('/modulos/almacen/planes-servicios', [AlmacenPlanesServiciosController::class, 'index'])->name('modules.almacen.planes-servicios.index');
    Route::get('/modulos/almacen/planes-servicios/export/{format}', [AlmacenPlanesServiciosController::class, 'export'])->name('modules.almacen.planes-servicios.export')->where('format', 'pdf|xlsx');
    Route::get('/modulos/almacen/planes-servicios/crear', [AlmacenPlanesServiciosController::class, 'create'])->name('modules.almacen.planes-servicios.create');
    Route::post('/modulos/almacen/planes-servicios', [AlmacenPlanesServiciosController::class, 'store'])->name('modules.almacen.planes-servicios.store');
    Route::get('/modulos/almacen/planes-servicios/{id}/editar', [AlmacenPlanesServiciosController::class, 'edit'])->name('modules.almacen.planes-servicios.edit');
    Route::put('/modulos/almacen/planes-servicios/{id}', [AlmacenPlanesServiciosController::class, 'update'])->name('modules.almacen.planes-servicios.update');
    Route::delete('/modulos/almacen/planes-servicios/bulk-destroy', [\App\Http\Controllers\BulkDestroyController::class, 'destroy'])->name('modules.almacen.planes-servicios.bulk-destroy');
    Route::delete('/modulos/almacen/planes-servicios/{id}', [AlmacenPlanesServiciosController::class, 'destroy'])->name('modules.almacen.planes-servicios.destroy');
});

Route::middleware('erp.module:almacen.nota_ingreso')->group(function () {
    Route::get('/modulos/almacen/nota-ingreso', [AlmacenNotaIngresoController::class, 'index'])->name('modules.almacen.nota-ingreso.index');
    Route::get('/modulos/almacen/nota-ingreso/export/{format}', [AlmacenNotaIngresoController::class, 'export'])->name('modules.almacen.nota-ingreso.export')->where('format', 'pdf|xlsx');
    Route::get('/modulos/almacen/nota-ingreso/crear', [AlmacenNotaIngresoController::class, 'create'])->name('modules.almacen.nota-ingreso.create');
    Route::post('/modulos/almacen/nota-ingreso', [AlmacenNotaIngresoController::class, 'store'])->name('modules.almacen.nota-ingreso.store');
    Route::get('/modulos/almacen/nota-ingreso/{id}/editar', [AlmacenNotaIngresoController::class, 'edit'])->name('modules.almacen.nota-ingreso.edit');
    Route::put('/modulos/almacen/nota-ingreso/{id}', [AlmacenNotaIngresoController::class, 'update'])->name('modules.almacen.nota-ingreso.update');
    Route::delete('/modulos/almacen/nota-ingreso/bulk-destroy', [\App\Http\Controllers\BulkDestroyController::class, 'destroy'])->name('modules.almacen.nota-ingreso.bulk-destroy');
    Route::delete('/modulos/almacen/nota-ingreso/{id}', [AlmacenNotaIngresoController::class, 'destroy'])->name('modules.almacen.nota-ingreso.destroy');
});

Route::middleware('erp.module:almacen.nota_salida')->group(function () {
    Route::get('/modulos/almacen/nota-salida', [AlmacenNotaSalidaController::class, 'index'])->name('modules.almacen.nota-salida.index');
    Route::get('/modulos/almacen/nota-salida/export/{format}', [AlmacenNotaSalidaController::class, 'export'])->name('modules.almacen.nota-salida.export')->where('format', 'pdf|xlsx');
    Route::get('/modulos/almacen/nota-salida/informe/export/{format}', [AlmacenNotaSalidaController::class, 'exportExecutionReport'])->name('modules.almacen.nota-salida.report-export')->where('format', 'pdf|xlsx');
    Route::get('/modulos/almacen/nota-salida/crear', [AlmacenNotaSalidaController::class, 'create'])->name('modules.almacen.nota-salida.create');
    Route::post('/modulos/almacen/nota-salida', [AlmacenNotaSalidaController::class, 'store'])->name('modules.almacen.nota-salida.store');
    Route::get('/modulos/almacen/nota-salida/{id}/editar', [AlmacenNotaSalidaController::class, 'edit'])->name('modules.almacen.nota-salida.edit');
    Route::put('/modulos/almacen/nota-salida/{id}', [AlmacenNotaSalidaController::class, 'update'])->name('modules.almacen.nota-salida.update');
    Route::delete('/modulos/almacen/nota-salida/bulk-destroy', [\App\Http\Controllers\BulkDestroyController::class, 'destroy'])->name('modules.almacen.nota-salida.bulk-destroy');
    Route::delete('/modulos/almacen/nota-salida/{id}', [AlmacenNotaSalidaController::class, 'destroy'])->name('modules.almacen.nota-salida.destroy');
});
