<?php

use App\Http\Controllers\PersonalController;
use App\Http\Controllers\BulkDestroyController;
use Illuminate\Support\Facades\Route;

Route::middleware('erp.module:personal')->group(function () {
    Route::get('/modulos/personal', [PersonalController::class, 'index'])->name('modules.personal');
    Route::get('/modulos/personal/export/{format}', [PersonalController::class, 'export'])->name('modules.personal.export')->where('format', 'pdf|xlsx');
    Route::get('/modulos/personal/crear', [PersonalController::class, 'create'])->name('modules.personal.create');
    Route::post('/modulos/personal', [PersonalController::class, 'store'])->name('modules.personal.store');
    Route::get('/modulos/personal/{dni}/editar', [PersonalController::class, 'edit'])->name('modules.personal.edit');
    Route::put('/modulos/personal/{dni}', [PersonalController::class, 'update'])->name('modules.personal.update');
    Route::delete('/modulos/personal/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.personal.bulk-destroy');
    Route::delete('/modulos/personal/{dni}', [PersonalController::class, 'destroy'])->name('modules.personal.destroy');
});
