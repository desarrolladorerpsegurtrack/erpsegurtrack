<?php

use App\Http\Controllers\SistemaController;
use App\Http\Controllers\BulkDestroyController;
use Illuminate\Support\Facades\Route;

Route::middleware('erp.module:sistema')->group(function () {
    Route::get('/modulos/sistema', [SistemaController::class, 'index'])->name('modules.sistema');

    Route::get('/modulos/sistema/vistas', [SistemaController::class, 'vistasIndex'])->name('modules.sistema.vistas.index');
    Route::get('/modulos/sistema/vistas/export/{format}', [SistemaController::class, 'vistasExport'])->name('modules.sistema.vistas.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/sistema/vistas/export/{format}', [SistemaController::class, 'vistasExport'])->name('modules.sistema.vistas.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/sistema/vistas/crear', [SistemaController::class, 'vistasCreate'])->name('modules.sistema.vistas.create');
    Route::post('/modulos/sistema/vistas', [SistemaController::class, 'vistasStore'])->name('modules.sistema.vistas.store');
    Route::get('/modulos/sistema/vistas/{id}/editar', [SistemaController::class, 'vistasEdit'])->name('modules.sistema.vistas.edit');
    Route::put('/modulos/sistema/vistas/{id}', [SistemaController::class, 'vistasUpdate'])->name('modules.sistema.vistas.update');
    Route::delete('/modulos/sistema/vistas/bulk-destroy', [SistemaController::class, 'vistasBulkDestroy'])->name('modules.sistema.vistas.bulk-destroy');
    Route::delete('/modulos/sistema/vistas/{id}', [SistemaController::class, 'vistasDestroy'])->name('modules.sistema.vistas.destroy');

    Route::get('/modulos/sistema/flujos', [SistemaController::class, 'flujosIndex'])->name('modules.sistema.flujos.index');
    Route::get('/modulos/sistema/flujos/export/{format}', [SistemaController::class, 'flujosExport'])->name('modules.sistema.flujos.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/sistema/flujos/export/{format}', [SistemaController::class, 'flujosExport'])->name('modules.sistema.flujos.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/sistema/flujos/crear', [SistemaController::class, 'flujosCreate'])->name('modules.sistema.flujos.create');
    Route::post('/modulos/sistema/flujos', [SistemaController::class, 'flujosStore'])->name('modules.sistema.flujos.store');
    Route::get('/modulos/sistema/flujos/{id}/editar', [SistemaController::class, 'flujosEdit'])->name('modules.sistema.flujos.edit');
    Route::put('/modulos/sistema/flujos/{id}', [SistemaController::class, 'flujosUpdate'])->name('modules.sistema.flujos.update');
    Route::delete('/modulos/sistema/flujos/bulk-destroy', [SistemaController::class, 'flujosBulkDestroy'])->name('modules.sistema.flujos.bulk-destroy');
    Route::delete('/modulos/sistema/flujos/{id}', [SistemaController::class, 'flujosDestroy'])->name('modules.sistema.flujos.destroy');

    Route::get('/modulos/sistema/flujo-reglas', [SistemaController::class, 'flujoReglasIndex'])->name('modules.sistema.flujo-reglas.index');
    Route::get('/modulos/sistema/flujo-reglas/export/{format}', [SistemaController::class, 'flujoReglasExport'])->name('modules.sistema.flujo-reglas.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/sistema/flujo-reglas/export/{format}', [SistemaController::class, 'flujoReglasExport'])->name('modules.sistema.flujo-reglas.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/sistema/flujo-reglas/crear', [SistemaController::class, 'flujoReglasCreate'])->name('modules.sistema.flujo-reglas.create');
    Route::post('/modulos/sistema/flujo-reglas', [SistemaController::class, 'flujoReglasStore'])->name('modules.sistema.flujo-reglas.store');
    Route::get('/modulos/sistema/flujo-reglas/{id}/editar', [SistemaController::class, 'flujoReglasEdit'])->name('modules.sistema.flujo-reglas.edit');
    Route::put('/modulos/sistema/flujo-reglas/{id}', [SistemaController::class, 'flujoReglasUpdate'])->name('modules.sistema.flujo-reglas.update');
    Route::delete('/modulos/sistema/flujo-reglas/bulk-destroy', [SistemaController::class, 'flujoReglasBulkDestroy'])->name('modules.sistema.flujo-reglas.bulk-destroy');
    Route::delete('/modulos/sistema/flujo-reglas/{id}', [SistemaController::class, 'flujoReglasDestroy'])->name('modules.sistema.flujo-reglas.destroy');

    Route::get('/modulos/sistema/historial-flujos', [SistemaController::class, 'historialFlujosIndex'])->name('modules.sistema.historial-flujos.index');
    Route::get('/modulos/sistema/historial-flujos/export/{format}', [SistemaController::class, 'historialFlujosExport'])->name('modules.sistema.historial-flujos.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/sistema/historial-flujos/export/{format}', [SistemaController::class, 'historialFlujosExport'])->name('modules.sistema.historial-flujos.export.post')->where('format', 'pdf|xlsx');
   
});
