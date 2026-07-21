<?php

use App\Http\Controllers\AlmacenController;
use App\Http\Controllers\AlmacenNotaIngresoController;
use App\Http\Controllers\AlmacenNotaSalidaController;
use App\Http\Controllers\BulkDestroyController;
use App\Http\Controllers\ElementoAlmacenController;
use Illuminate\Support\Facades\Route;

Route::middleware('erp.module:almacen')->group(function () {
    Route::get('/modulos/almacen', [AlmacenController::class, 'index'])->name('modules.almacen');
    Route::get('/modulos/almacen/export/{format}', [AlmacenController::class, 'export'])->name('modules.almacen.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/almacen/export/{format}', [AlmacenController::class, 'export'])->name('modules.almacen.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/almacen/crear', [AlmacenController::class, 'create'])->name('modules.almacen.create');
    Route::post('/modulos/almacen', [AlmacenController::class, 'store'])->name('modules.almacen.store');
    Route::get('/modulos/almacen/{id}/editar', [AlmacenController::class, 'edit'])->name('modules.almacen.edit');
    Route::put('/modulos/almacen/{id}', [AlmacenController::class, 'update'])->name('modules.almacen.update');
    Route::delete('/modulos/almacen/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.almacen.bulk-destroy');
    Route::delete('/modulos/almacen/{id}', [AlmacenController::class, 'destroy'])->name('modules.almacen.destroy');
});

Route::middleware('erp.module:almacen.elemento_almacen')->group(function () {
    Route::get('/modulos/almacen/elemento-almacen', [ElementoAlmacenController::class, 'elementoAlmacenIndex'])->name('modules.almacen.elemento-almacen.index');
    Route::get('/modulos/almacen/elemento-almacen/export/{format}', [ElementoAlmacenController::class, 'elementoAlmacenExport'])->name('modules.almacen.elemento-almacen.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/almacen/elemento-almacen/export/{format}', [ElementoAlmacenController::class, 'elementoAlmacenExport'])->name('modules.almacen.elemento-almacen.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/almacen/elemento-almacen/crear', [ElementoAlmacenController::class, 'elementoAlmacenCreate'])->name('modules.almacen.elemento-almacen.create');
    Route::post('/modulos/almacen/elemento-almacen', [ElementoAlmacenController::class, 'elementoAlmacenStore'])->name('modules.almacen.elemento-almacen.store');
    Route::get('/modulos/almacen/elemento-almacen/{id}/editar', [ElementoAlmacenController::class, 'elementoAlmacenEdit'])->name('modules.almacen.elemento-almacen.edit');
    Route::put('/modulos/almacen/elemento-almacen/{id}', [ElementoAlmacenController::class, 'elementoAlmacenUpdate'])->name('modules.almacen.elemento-almacen.update');
    Route::delete('/modulos/almacen/elemento-almacen/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.almacen.elemento-almacen.bulk-destroy');
    Route::delete('/modulos/almacen/elemento-almacen/{id}', [ElementoAlmacenController::class, 'elementoAlmacenDestroy'])->name('modules.almacen.elemento-almacen.destroy');
});

Route::middleware('erp.module:almacen.nota_ingreso')->group(function () {
    Route::get('/modulos/almacen/nota-ingreso', [AlmacenNotaIngresoController::class, 'index'])->name('modules.almacen.nota-ingreso.index');
    Route::get('/modulos/almacen/nota-ingreso/export/{format}', [AlmacenNotaIngresoController::class, 'export'])->name('modules.almacen.nota-ingreso.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/almacen/nota-ingreso/export/{format}', [AlmacenNotaIngresoController::class, 'export'])->name('modules.almacen.nota-ingreso.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/almacen/nota-ingreso/{id}/pdf', [AlmacenNotaIngresoController::class, 'downloadPdf'])->name('modules.almacen.nota-ingreso.pdf');
    Route::get('/modulos/almacen/nota-ingreso/crear', [AlmacenNotaIngresoController::class, 'create'])->name('modules.almacen.nota-ingreso.create');
    Route::get('/modulos/almacen/nota-ingreso/imeis-preview', [AlmacenNotaIngresoController::class, 'imeisPreview'])->name('modules.almacen.nota-ingreso.imeis-preview');
    Route::post('/modulos/almacen/nota-ingreso', [AlmacenNotaIngresoController::class, 'store'])->name('modules.almacen.nota-ingreso.store');
    Route::get('/modulos/almacen/nota-ingreso/{id}/editar', [AlmacenNotaIngresoController::class, 'edit'])->name('modules.almacen.nota-ingreso.edit');
    Route::put('/modulos/almacen/nota-ingreso/{id}', [AlmacenNotaIngresoController::class, 'update'])->name('modules.almacen.nota-ingreso.update');
    Route::delete('/modulos/almacen/nota-ingreso/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.almacen.nota-ingreso.bulk-destroy');
    Route::delete('/modulos/almacen/nota-ingreso/{id}', [AlmacenNotaIngresoController::class, 'destroy'])->name('modules.almacen.nota-ingreso.destroy');
});

Route::middleware('erp.module:almacen.nota_salida')->group(function () {
    Route::get('/modulos/almacen/nota-salida', [AlmacenNotaSalidaController::class, 'index'])->name('modules.almacen.nota-salida.index');
    Route::get('/modulos/almacen/nota-salida/export/{format}', [AlmacenNotaSalidaController::class, 'export'])->name('modules.almacen.nota-salida.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/almacen/nota-salida/export/{format}', [AlmacenNotaSalidaController::class, 'export'])->name('modules.almacen.nota-salida.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/almacen/nota-salida/{id}/pdf', [AlmacenNotaSalidaController::class, 'downloadPdf'])->name('modules.almacen.nota-salida.pdf');
    Route::get('/modulos/almacen/nota-salida/informe/export/{format}', [AlmacenNotaSalidaController::class, 'export'])->name('modules.almacen.nota-salida.report-export')->where('format', 'pdf|xlsx');
    Route::get('/modulos/almacen/nota-salida/imeis-preview', [AlmacenNotaSalidaController::class, 'imeisPreview'])->name('modules.almacen.nota-salida.imeis-preview');
    Route::get('/modulos/almacen/nota-salida/crear', [AlmacenNotaSalidaController::class, 'create'])->name('modules.almacen.nota-salida.create');
    Route::post('/modulos/almacen/nota-salida', [AlmacenNotaSalidaController::class, 'store'])->name('modules.almacen.nota-salida.store');
    Route::get('/modulos/almacen/nota-salida/{id}/editar', [AlmacenNotaSalidaController::class, 'edit'])->name('modules.almacen.nota-salida.edit');
    Route::put('/modulos/almacen/nota-salida/{id}', [AlmacenNotaSalidaController::class, 'update'])->name('modules.almacen.nota-salida.update');
    Route::delete('/modulos/almacen/nota-salida/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.almacen.nota-salida.bulk-destroy');
    Route::delete('/modulos/almacen/nota-salida/{id}', [AlmacenNotaSalidaController::class, 'destroy'])->name('modules.almacen.nota-salida.destroy');
});
