<?php

use App\Http\Controllers\UsuariosController;
use App\Http\Controllers\BulkDestroyController;
use Illuminate\Support\Facades\Route;

Route::middleware('erp.module:usuarios')->group(function () {
    Route::get('/modulos/usuarios', [UsuariosController::class, 'index'])->name('modules.usuarios');
    Route::get('/modulos/usuarios/export/{format}', [UsuariosController::class, 'export'])->name('modules.usuarios.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/usuarios/export/{format}', [UsuariosController::class, 'export'])->name('modules.usuarios.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/usuarios/crear', [UsuariosController::class, 'create'])->name('modules.usuarios.create');
    Route::post('/modulos/usuarios', [UsuariosController::class, 'store'])->name('modules.usuarios.store');
    Route::get('/modulos/usuarios/{usuario}/editar', [UsuariosController::class, 'edit'])->name('modules.usuarios.edit');
    Route::put('/modulos/usuarios/{usuario}', [UsuariosController::class, 'update'])->name('modules.usuarios.update');
    Route::delete('/modulos/usuarios/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.usuarios.bulk-destroy');
    Route::delete('/modulos/usuarios/{usuario}', [UsuariosController::class, 'destroy'])->name('modules.usuarios.destroy');
});
