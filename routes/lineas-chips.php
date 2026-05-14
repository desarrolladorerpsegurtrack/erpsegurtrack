<?php

use App\Http\Controllers\LineasChipsController;
use App\Http\Controllers\BulkDestroyController;
use Illuminate\Support\Facades\Route;

Route::middleware('erp.module:lineas_chips')->group(function () {
    Route::get('/modulos/lineas-chips', [LineasChipsController::class, 'index'])->name('modules.lineas-chips');

    Route::get('/modulos/lineas-chips/numeros-telefonico', [LineasChipsController::class, 'numerosTelefonicoIndex'])->name('modules.lineas-chips.numeros-telefonico.index');
    Route::get('/modulos/lineas-chips/numeros-telefonico/export/{format}', [LineasChipsController::class, 'numerosTelefonicoExport'])->name('modules.lineas-chips.numeros-telefonico.export')->where('format', 'pdf|xlsx');
    Route::get('/modulos/lineas-chips/numeros-telefonico/crear', [LineasChipsController::class, 'numerosTelefonicoCreate'])->name('modules.lineas-chips.numeros-telefonico.create');
    Route::post('/modulos/lineas-chips/numeros-telefonico', [LineasChipsController::class, 'numerosTelefonicoStore'])->name('modules.lineas-chips.numeros-telefonico.store');
    Route::delete('/modulos/lineas-chips/numeros-telefonico/bulk-destroy', [LineasChipsController::class, 'numerosTelefonicoBulkDestroy'])->name('modules.lineas-chips.numeros-telefonico.bulk-destroy');
    Route::get('/modulos/lineas-chips/numeros-telefonico/{id}/editar', [LineasChipsController::class, 'numerosTelefonicoEdit'])->name('modules.lineas-chips.numeros-telefonico.edit');
    Route::put('/modulos/lineas-chips/numeros-telefonico/{id}', [LineasChipsController::class, 'numerosTelefonicoUpdate'])->name('modules.lineas-chips.numeros-telefonico.update');
    Route::delete('/modulos/lineas-chips/numeros-telefonico/{id}', [LineasChipsController::class, 'numerosTelefonicoDestroy'])->name('modules.lineas-chips.numeros-telefonico.destroy');

    Route::get('/modulos/lineas-chips/numeros-dispositivo', [LineasChipsController::class, 'numerosDispositivoIndex'])->name('modules.lineas-chips.numeros-dispositivo.index');
    Route::get('/modulos/lineas-chips/numeros-dispositivo/export/{format}', [LineasChipsController::class, 'numerosDispositivoExport'])->name('modules.lineas-chips.numeros-dispositivo.export')->where('format', 'pdf|xlsx');
    Route::get('/modulos/lineas-chips/numeros-dispositivo/crear', [LineasChipsController::class, 'numerosDispositivoCreate'])->name('modules.lineas-chips.numeros-dispositivo.create');
    Route::post('/modulos/lineas-chips/numeros-dispositivo', [LineasChipsController::class, 'numerosDispositivoStore'])->name('modules.lineas-chips.numeros-dispositivo.store');
    Route::delete('/modulos/lineas-chips/numeros-dispositivo/bulk-destroy', [LineasChipsController::class, 'numerosDispositivoBulkDestroy'])->name('modules.lineas-chips.numeros-dispositivo.bulk-destroy');
    Route::delete('/modulos/lineas-chips/numeros-dispositivo/{id}', [LineasChipsController::class, 'numerosDispositivoDestroy'])->name('modules.lineas-chips.numeros-dispositivo.destroy');

    Route::get('/modulos/lineas-chips/simcard', [LineasChipsController::class, 'simcardIndex'])->name('modules.lineas-chips.simcard.index');
    Route::get('/modulos/lineas-chips/simcard/export/{format}', [LineasChipsController::class, 'simcardExport'])->name('modules.lineas-chips.simcard.export')->where('format', 'pdf|xlsx');
    Route::get('/modulos/lineas-chips/simcard/crear', [LineasChipsController::class, 'simcardCreate'])->name('modules.lineas-chips.simcard.create');
    Route::post('/modulos/lineas-chips/simcard', [LineasChipsController::class, 'simcardStore'])->name('modules.lineas-chips.simcard.store');
    Route::get('/modulos/lineas-chips/simcard/{id}/editar', [LineasChipsController::class, 'simcardEdit'])->name('modules.lineas-chips.simcard.edit');
    Route::put('/modulos/lineas-chips/simcard/{id}', [LineasChipsController::class, 'simcardUpdate'])->name('modules.lineas-chips.simcard.update');
    Route::delete('/modulos/lineas-chips/simcard/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.lineas-chips.simcard.bulk-destroy');
    Route::delete('/modulos/lineas-chips/simcard/{id}', [LineasChipsController::class, 'simcardDestroy'])->name('modules.lineas-chips.simcard.destroy');

    Route::get('/modulos/lineas-chips/detallesimcard', [LineasChipsController::class, 'detallesimcardIndex'])->name('modules.lineas-chips.detallesimcard.index');
    Route::get('/modulos/lineas-chips/detallesimcard/export/{format}', [LineasChipsController::class, 'detallesimcardExport'])->name('modules.lineas-chips.detallesimcard.export')->where('format', 'pdf|xlsx');
    Route::get('/modulos/lineas-chips/detallesimcard/crear', [LineasChipsController::class, 'detallesimcardCreate'])->name('modules.lineas-chips.detallesimcard.create');
    Route::post('/modulos/lineas-chips/detallesimcard', [LineasChipsController::class, 'detallesimcardStore'])->name('modules.lineas-chips.detallesimcard.store');
    Route::post('/modulos/lineas-chips/detallesimcard/bulk-deactivate', [LineasChipsController::class, 'detallesimcardBulkDeactivate'])->name('modules.lineas-chips.detallesimcard.bulk-deactivate');
    Route::post('/modulos/lineas-chips/detallesimcard/bulk-deactivate/parse-file', [LineasChipsController::class, 'detallesimcardBulkDeactivateParseFile'])->name('modules.lineas-chips.detallesimcard.bulk-deactivate.parse-file');
    Route::post('/modulos/lineas-chips/detallesimcard/import/preview', [LineasChipsController::class, 'detallesimcardImportPreview'])->name('modules.lineas-chips.detallesimcard.import.preview');
    Route::post('/modulos/lineas-chips/detallesimcard/import/process', [LineasChipsController::class, 'detallesimcardImportProcess'])->name('modules.lineas-chips.detallesimcard.import.process');
    Route::post('/modulos/lineas-chips/detallesimcard/preview/export/{type}', [LineasChipsController::class, 'detallesimcardPreviewExport'])->name('modules.lineas-chips.detallesimcard.preview.export')->where('type', 'bulk|import');
    Route::delete('/modulos/lineas-chips/detallesimcard/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.lineas-chips.detallesimcard.bulk-destroy');
    Route::delete('/modulos/lineas-chips/detallesimcard/{id}', [LineasChipsController::class, 'detallesimcardDestroy'])->name('modules.lineas-chips.detallesimcard.destroy');
});
