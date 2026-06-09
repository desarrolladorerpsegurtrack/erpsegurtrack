<?php

use App\Http\Controllers\PlanesServiciosController;
use Illuminate\Support\Facades\Route;

Route::middleware('erp.module:ventas.planes_servicios')->group(function () {
    Route::get('/modulos/ventas', function () {
        return redirect()->route('modules.ventas.planes-servicios.index');
    })->name('modules.ventas');
    Route::get('/modulos/ventas/planes-servicios', [PlanesServiciosController::class, 'index'])->name('modules.ventas.planes-servicios.index');
    Route::get('/modulos/ventas/planes-servicios/export/{format}', [PlanesServiciosController::class, 'export'])->name('modules.ventas.planes-servicios.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/ventas/planes-servicios/export/{format}', [PlanesServiciosController::class, 'export'])->name('modules.ventas.planes-servicios.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/ventas/planes-servicios/crear', [PlanesServiciosController::class, 'create'])->name('modules.ventas.planes-servicios.create');
    Route::post('/modulos/ventas/planes-servicios', [PlanesServiciosController::class, 'store'])->name('modules.ventas.planes-servicios.store');
    Route::get('/modulos/ventas/planes-servicios/{id}/editar', [PlanesServiciosController::class, 'edit'])->name('modules.ventas.planes-servicios.edit');
    Route::put('/modulos/ventas/planes-servicios/{id}', [PlanesServiciosController::class, 'update'])->name('modules.ventas.planes-servicios.update');
    Route::delete('/modulos/ventas/planes-servicios/bulk-destroy', [\App\Http\Controllers\BulkDestroyController::class, 'destroy'])->name('modules.ventas.planes-servicios.bulk-destroy');
    Route::delete('/modulos/ventas/planes-servicios/{id}', [PlanesServiciosController::class, 'destroy'])->name('modules.ventas.planes-servicios.destroy');
});
