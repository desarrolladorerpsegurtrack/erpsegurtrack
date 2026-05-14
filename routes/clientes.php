<?php

use App\Http\Controllers\ClientesController;
use App\Http\Controllers\GrupoClienteController;
use App\Http\Controllers\BulkDestroyController;
use Illuminate\Support\Facades\Route;

Route::middleware('erp.module:clientes')->group(function () {
    Route::get('/modulos/clientes', [ClientesController::class, 'index'])->name('modules.clientes');
    Route::get('/modulos/clientes/export/{format}', [ClientesController::class, 'export'])->name('modules.clientes.export')->where('format', 'pdf|xlsx');
    Route::get('/modulos/clientes/registros/crear', [ClientesController::class, 'create'])->name('modules.clientes.create');
    Route::post('/modulos/clientes/registros', [ClientesController::class, 'store'])->name('modules.clientes.store');
    Route::get('/modulos/clientes/registros/{cliente}/editar', [ClientesController::class, 'edit'])->name('modules.clientes.edit');
    Route::get('/modulos/clientes/registros/{cliente}/lock-status', [ClientesController::class, 'lockStatus'])->name('modules.clientes.lock-status');
    Route::post('/modulos/clientes/registros/{cliente}/lock', [ClientesController::class, 'acquireLock'])->name('modules.clientes.lock');
    Route::post('/modulos/clientes/registros/{cliente}/unlock', [ClientesController::class, 'releaseLock'])->name('modules.clientes.unlock');
    Route::put('/modulos/clientes/registros/{cliente}', [ClientesController::class, 'update'])->name('modules.clientes.update');
    Route::delete('/modulos/clientes/registros/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.clientes.bulk-destroy');
    Route::delete('/modulos/clientes/registros/{cliente}', [ClientesController::class, 'destroy'])->name('modules.clientes.destroy');

    Route::get('/modulos/clientes/direcciones/opciones', [ClientesController::class, 'direccionesOpciones'])->name('modules.clientes.direcciones.opciones');
    Route::get('/modulos/clientes/{cliente}/direcciones/export/{format}', [ClientesController::class, 'direccionesExport'])->name('modules.clientes.direcciones.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/clientes/{cliente}/direcciones/crear-rapido', [ClientesController::class, 'direccionesCrearRapido'])->name('modules.clientes.direcciones.crear-rapido');
    Route::put('/modulos/clientes/direcciones/{direccion}/actualizar-rapido', [ClientesController::class, 'direccionesActualizarRapido'])->name('modules.clientes.direcciones.actualizar-rapido');
    Route::delete('/modulos/clientes/direcciones/{direccion}/eliminar-rapido', [ClientesController::class, 'direccionesEliminarRapido'])->name('modules.clientes.direcciones.eliminar-rapido');
    Route::get('/modulos/clientes/{cliente}/contactos/opciones', [ClientesController::class, 'contactosOpciones'])->name('modules.clientes.contactos.opciones');
    Route::get('/modulos/clientes/{cliente}/contactos/export/{format}', [ClientesController::class, 'contactosExport'])->name('modules.clientes.contactos.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/clientes/{cliente}/contactos/crear-rapido', [ClientesController::class, 'contactosCrearRapido'])->name('modules.clientes.contactos.crear-rapido');
    Route::put('/modulos/clientes/{cliente}/contactos/{contacto}/actualizar-rapido', [ClientesController::class, 'contactosActualizarRapido'])->name('modules.clientes.contactos.actualizar-rapido');
    Route::delete('/modulos/clientes/{cliente}/contactos/{contacto}/eliminar-rapido', [ClientesController::class, 'contactosEliminarRapido'])->name('modules.clientes.contactos.eliminar-rapido');
    Route::get('/modulos/clientes/{cliente}/credenciales/opciones', [ClientesController::class, 'credencialesOpciones'])->name('modules.clientes.credenciales.opciones');
    Route::get('/modulos/clientes/{cliente}/credenciales/export/{format}', [ClientesController::class, 'credencialesExport'])->name('modules.clientes.credenciales.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/clientes/{cliente}/credenciales/crear-rapido', [ClientesController::class, 'credencialesCrearRapido'])->name('modules.clientes.credenciales.crear-rapido');
    Route::put('/modulos/clientes/{cliente}/credenciales/{credencial}/actualizar-rapido', [ClientesController::class, 'credencialesActualizarRapido'])->name('modules.clientes.credenciales.actualizar-rapido');
    Route::delete('/modulos/clientes/{cliente}/credenciales/{credencial}/eliminar-rapido', [ClientesController::class, 'credencialesEliminarRapido'])->name('modules.clientes.credenciales.eliminar-rapido');

    Route::get('/modulos/clientes/grupos', [GrupoClienteController::class, 'index'])->name('modules.clientes.grupos.index');
    Route::get('/modulos/clientes/grupos/crear', [GrupoClienteController::class, 'create'])->name('modules.clientes.grupos.create');
    Route::post('/modulos/clientes/grupos', [GrupoClienteController::class, 'store'])->name('modules.clientes.grupos.store');
    Route::get('/modulos/clientes/grupos/{id}/editar', [GrupoClienteController::class, 'edit'])->name('modules.clientes.grupos.edit');
    Route::put('/modulos/clientes/grupos/{id}', [GrupoClienteController::class, 'update'])->name('modules.clientes.grupos.update');
    Route::delete('/modulos/clientes/grupos/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.clientes.grupos.bulk-destroy');
    Route::delete('/modulos/clientes/grupos/{id}', [GrupoClienteController::class, 'destroy'])->name('modules.clientes.grupos.destroy');
    Route::get('/modulos/clientes/grupos/export/{format}', [GrupoClienteController::class, 'export'])->name('modules.clientes.grupos.export')->where('format', 'pdf|xlsx');
});
