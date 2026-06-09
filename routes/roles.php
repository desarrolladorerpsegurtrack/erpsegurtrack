<?php

use App\Http\Controllers\RolesController;
use App\Http\Controllers\BulkDestroyController;
use Illuminate\Support\Facades\Route;

Route::middleware('erp.module:roles')->group(function () {
    Route::get('/modulos/roles', [RolesController::class, 'index'])->name('modules.roles');
    Route::get('/modulos/roles/export/{format}', [RolesController::class, 'export'])->name('modules.roles.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/roles/export/{format}', [RolesController::class, 'export'])->name('modules.roles.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/roles/crear', [RolesController::class, 'create'])->name('modules.roles.create');
    Route::post('/modulos/roles', [RolesController::class, 'store'])->name('modules.roles.store');
    Route::get('/modulos/roles/{id}/editar', [RolesController::class, 'edit'])->name('modules.roles.edit');
    Route::put('/modulos/roles/{id}', [RolesController::class, 'update'])->name('modules.roles.update');
    Route::delete('/modulos/roles/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.roles.bulk-destroy');
    Route::delete('/modulos/roles/{id}', [RolesController::class, 'destroy'])->name('modules.roles.destroy');
});
