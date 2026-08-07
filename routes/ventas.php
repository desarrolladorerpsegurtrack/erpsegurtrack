<?php

use App\Http\Controllers\PlanesServiciosController;
use App\Http\Controllers\CotizacionController;
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

Route::middleware('erp.module:ventas.cotizaciones')->group(function () {
    Route::get('/modulos/ventas/cotizaciones', [CotizacionController::class, 'index'])->name('modules.ventas.cotizaciones.index');
    Route::get('/modulos/ventas/cotizaciones/export/{format}', [CotizacionController::class, 'export'])->name('modules.ventas.cotizaciones.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/ventas/cotizaciones/export/{format}', [CotizacionController::class, 'export'])->name('modules.ventas.cotizaciones.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/ventas/cotizaciones/{id}/pdf', [CotizacionController::class, 'downloadPdf'])->name('modules.ventas.cotizaciones.pdf');
    Route::get('/modulos/ventas/cotizaciones/grupo/{batch_id}/pdf', [CotizacionController::class, 'downloadGroupPdf'])->name('modules.ventas.cotizaciones.pdf-grupo');
    Route::get('/modulos/ventas/cotizaciones/crear', [CotizacionController::class, 'create'])->name('modules.ventas.cotizaciones.create');
    Route::get('/modulos/ventas/cotizaciones/cliente/{id}/info', [CotizacionController::class, 'clienteInfo'])->name('modules.ventas.cotizaciones.cliente-info');
    Route::post('/modulos/ventas/cotizaciones', [CotizacionController::class, 'store'])->name('modules.ventas.cotizaciones.store');
    Route::get('/modulos/ventas/cotizaciones/{id}/editar', [CotizacionController::class, 'edit'])->name('modules.ventas.cotizaciones.edit');
    Route::put('/modulos/ventas/cotizaciones/{id}', [CotizacionController::class, 'update'])->name('modules.ventas.cotizaciones.update');
    Route::post('/modulos/ventas/cotizaciones/{id}/anular', [CotizacionController::class, 'anular'])->name('modules.ventas.cotizaciones.anular');
    Route::post('/modulos/ventas/cotizaciones/{id}/aprobar', [CotizacionController::class, 'approve'])->name('modules.ventas.cotizaciones.approve');
    Route::delete('/modulos/ventas/cotizaciones/bulk-destroy', [\App\Http\Controllers\BulkDestroyController::class, 'destroy'])->name('modules.ventas.cotizaciones.bulk-destroy');
    Route::delete('/modulos/ventas/cotizaciones/{id}', [CotizacionController::class, 'destroy'])->name('modules.ventas.cotizaciones.destroy');
});
