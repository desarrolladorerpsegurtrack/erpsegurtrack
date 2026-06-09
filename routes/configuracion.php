<?php

use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\BulkDestroyController;
use Illuminate\Support\Facades\Route;

Route::middleware('erp.module:configuracion')->group(function () {
    Route::get('/modulos/configuracion', [ConfiguracionController::class, 'index'])->name('modules.configuracion');

    Route::get('/modulos/configuracion/estado-cliente', [ConfiguracionController::class, 'estadosIndex'])->name('modules.configuracion.estados.index');
    Route::get('/modulos/configuracion/estado-cliente/export/{format}', [ConfiguracionController::class, 'estadosExport'])->name('modules.configuracion.estados.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/estado-cliente/export/{format}', [ConfiguracionController::class, 'estadosExport'])->name('modules.configuracion.estados.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/estado-cliente/crear', [ConfiguracionController::class, 'estadosCreate'])->name('modules.configuracion.estados.create');
    Route::post('/modulos/configuracion/estado-cliente', [ConfiguracionController::class, 'estadosStore'])->name('modules.configuracion.estados.store');
    Route::get('/modulos/configuracion/estado-cliente/{id}/editar', [ConfiguracionController::class, 'estadosEdit'])->name('modules.configuracion.estados.edit');
    Route::put('/modulos/configuracion/estado-cliente/{id}', [ConfiguracionController::class, 'estadosUpdate'])->name('modules.configuracion.estados.update');
    Route::delete('/modulos/configuracion/estado-cliente/bulk-destroy', [ConfiguracionController::class, 'estadosBulkDestroy'])->name('modules.configuracion.estados.bulk-destroy');
    Route::delete('/modulos/configuracion/estado-cliente/{id}', [ConfiguracionController::class, 'estadosDestroy'])->name('modules.configuracion.estados.destroy');

    Route::get('/modulos/configuracion/tipo-contacto', [ConfiguracionController::class, 'tiposContactoIndex'])->name('modules.configuracion.tipos-contacto.index');
    Route::get('/modulos/configuracion/tipo-contacto/export/{format}', [ConfiguracionController::class, 'tiposContactoExport'])->name('modules.configuracion.tipos-contacto.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/tipo-contacto/export/{format}', [ConfiguracionController::class, 'tiposContactoExport'])->name('modules.configuracion.tipos-contacto.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/tipo-contacto/crear', [ConfiguracionController::class, 'tiposContactoCreate'])->name('modules.configuracion.tipos-contacto.create');
    Route::post('/modulos/configuracion/tipo-contacto', [ConfiguracionController::class, 'tiposContactoStore'])->name('modules.configuracion.tipos-contacto.store');
    Route::get('/modulos/configuracion/tipo-contacto/{id}/editar', [ConfiguracionController::class, 'tiposContactoEdit'])->name('modules.configuracion.tipos-contacto.edit');
    Route::put('/modulos/configuracion/tipo-contacto/{id}', [ConfiguracionController::class, 'tiposContactoUpdate'])->name('modules.configuracion.tipos-contacto.update');
    Route::delete('/modulos/configuracion/tipo-contacto/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.tipos-contacto.bulk-destroy');
    Route::delete('/modulos/configuracion/tipo-contacto/{id}', [ConfiguracionController::class, 'tiposContactoDestroy'])->name('modules.configuracion.tipos-contacto.destroy');

    Route::get('/modulos/configuracion/moneda', [ConfiguracionController::class, 'monedasIndex'])->name('modules.configuracion.monedas.index');
    Route::get('/modulos/configuracion/moneda/export/{format}', [ConfiguracionController::class, 'monedasExport'])->name('modules.configuracion.monedas.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/moneda/export/{format}', [ConfiguracionController::class, 'monedasExport'])->name('modules.configuracion.monedas.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/moneda/crear', [ConfiguracionController::class, 'monedasCreate'])->name('modules.configuracion.monedas.create');
    Route::post('/modulos/configuracion/moneda', [ConfiguracionController::class, 'monedasStore'])->name('modules.configuracion.monedas.store');
    Route::get('/modulos/configuracion/moneda/{id}/editar', [ConfiguracionController::class, 'monedasEdit'])->name('modules.configuracion.monedas.edit');
    Route::put('/modulos/configuracion/moneda/{id}', [ConfiguracionController::class, 'monedasUpdate'])->name('modules.configuracion.monedas.update');
    Route::delete('/modulos/configuracion/moneda/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.monedas.bulk-destroy');
    Route::delete('/modulos/configuracion/moneda/{id}', [ConfiguracionController::class, 'monedasDestroy'])->name('modules.configuracion.monedas.destroy');

    Route::get('/modulos/configuracion/tributo', [ConfiguracionController::class, 'tributosIndex'])->name('modules.configuracion.tributos.index');
    Route::get('/modulos/configuracion/tributo/export/{format}', [ConfiguracionController::class, 'tributosExport'])->name('modules.configuracion.tributos.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/tributo/export/{format}', [ConfiguracionController::class, 'tributosExport'])->name('modules.configuracion.tributos.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/tributo/crear', [ConfiguracionController::class, 'tributosCreate'])->name('modules.configuracion.tributos.create');
    Route::post('/modulos/configuracion/tributo', [ConfiguracionController::class, 'tributosStore'])->name('modules.configuracion.tributos.store');
    Route::get('/modulos/configuracion/tributo/{id}/editar', [ConfiguracionController::class, 'tributosEdit'])->name('modules.configuracion.tributos.edit');
    Route::put('/modulos/configuracion/tributo/{id}', [ConfiguracionController::class, 'tributosUpdate'])->name('modules.configuracion.tributos.update');
    Route::delete('/modulos/configuracion/tributo/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.tributos.bulk-destroy');
    Route::delete('/modulos/configuracion/tributo/{id}', [ConfiguracionController::class, 'tributosDestroy'])->name('modules.configuracion.tributos.destroy');

    Route::get('/modulos/configuracion/unidad-medida', [ConfiguracionController::class, 'unidadMedidasIndex'])->name('modules.configuracion.unidad-medida.index');
    Route::get('/modulos/configuracion/unidad-medida/export/{format}', [ConfiguracionController::class, 'unidadMedidasExport'])->name('modules.configuracion.unidad-medida.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/unidad-medida/export/{format}', [ConfiguracionController::class, 'unidadMedidasExport'])->name('modules.configuracion.unidad-medida.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/unidad-medida/crear', [ConfiguracionController::class, 'unidadMedidasCreate'])->name('modules.configuracion.unidad-medida.create');
    Route::post('/modulos/configuracion/unidad-medida', [ConfiguracionController::class, 'unidadMedidasStore'])->name('modules.configuracion.unidad-medida.store');
    Route::get('/modulos/configuracion/unidad-medida/{id}/editar', [ConfiguracionController::class, 'unidadMedidasEdit'])->name('modules.configuracion.unidad-medida.edit');
    Route::put('/modulos/configuracion/unidad-medida/{id}', [ConfiguracionController::class, 'unidadMedidasUpdate'])->name('modules.configuracion.unidad-medida.update');
    Route::delete('/modulos/configuracion/unidad-medida/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.unidad-medida.bulk-destroy');
    Route::delete('/modulos/configuracion/unidad-medida/{id}', [ConfiguracionController::class, 'unidadMedidasDestroy'])->name('modules.configuracion.unidad-medida.destroy');

    Route::get('/modulos/configuracion/tipos-plataforma', [ConfiguracionController::class, 'tiposPlataformaIndex'])->name('modules.configuracion.tipos-plataforma.index');
    Route::get('/modulos/configuracion/tipos-plataforma/export/{format}', [ConfiguracionController::class, 'tiposPlataformaExport'])->name('modules.configuracion.tipos-plataforma.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/tipos-plataforma/export/{format}', [ConfiguracionController::class, 'tiposPlataformaExport'])->name('modules.configuracion.tipos-plataforma.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/tipos-plataforma/crear', [ConfiguracionController::class, 'tiposPlataformaCreate'])->name('modules.configuracion.tipos-plataforma.create');
    Route::post('/modulos/configuracion/tipos-plataforma', [ConfiguracionController::class, 'tiposPlataformaStore'])->name('modules.configuracion.tipos-plataforma.store');
    Route::get('/modulos/configuracion/tipos-plataforma/{id}/editar', [ConfiguracionController::class, 'tiposPlataformaEdit'])->name('modules.configuracion.tipos-plataforma.edit');
    Route::put('/modulos/configuracion/tipos-plataforma/{id}', [ConfiguracionController::class, 'tiposPlataformaUpdate'])->name('modules.configuracion.tipos-plataforma.update');
    Route::delete('/modulos/configuracion/tipos-plataforma/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.tipos-plataforma.bulk-destroy');
    Route::delete('/modulos/configuracion/tipos-plataforma/{id}', [ConfiguracionController::class, 'tiposPlataformaDestroy'])->name('modules.configuracion.tipos-plataforma.destroy');

    Route::get('/modulos/configuracion/plataforma', [ConfiguracionController::class, 'plataformaIndex'])->name('modules.configuracion.plataforma.index');
    Route::get('/modulos/configuracion/plataforma/export/{format}', [ConfiguracionController::class, 'plataformaExport'])->name('modules.configuracion.plataforma.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/plataforma/export/{format}', [ConfiguracionController::class, 'plataformaExport'])->name('modules.configuracion.plataforma.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/plataforma/crear', [ConfiguracionController::class, 'plataformaCreate'])->name('modules.configuracion.plataforma.create');
    Route::post('/modulos/configuracion/plataforma', [ConfiguracionController::class, 'plataformaStore'])->name('modules.configuracion.plataforma.store');
    Route::get('/modulos/configuracion/plataforma/{id}/editar', [ConfiguracionController::class, 'plataformaEdit'])->name('modules.configuracion.plataforma.edit');
    Route::put('/modulos/configuracion/plataforma/{id}', [ConfiguracionController::class, 'plataformaUpdate'])->name('modules.configuracion.plataforma.update');
    Route::delete('/modulos/configuracion/plataforma/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.plataforma.bulk-destroy');
    Route::delete('/modulos/configuracion/plataforma/{id}', [ConfiguracionController::class, 'plataformaDestroy'])->name('modules.configuracion.plataforma.destroy');

    Route::get('/modulos/configuracion/tipo-elemento', [ConfiguracionController::class, 'tipoElementoIndex'])->name('modules.configuracion.tipos-elemento.index');
    Route::get('/modulos/configuracion/tipo-elemento/export/{format}', [ConfiguracionController::class, 'tipoElementoExport'])->name('modules.configuracion.tipos-elemento.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/tipo-elemento/export/{format}', [ConfiguracionController::class, 'tipoElementoExport'])->name('modules.configuracion.tipos-elemento.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/tipo-elemento/crear', [ConfiguracionController::class, 'tipoElementoCreate'])->name('modules.configuracion.tipos-elemento.create');
    Route::post('/modulos/configuracion/tipo-elemento', [ConfiguracionController::class, 'tipoElementoStore'])->name('modules.configuracion.tipos-elemento.store');
    Route::get('/modulos/configuracion/tipo-elemento/{id}/editar', [ConfiguracionController::class, 'tipoElementoEdit'])->name('modules.configuracion.tipos-elemento.edit');
    Route::put('/modulos/configuracion/tipo-elemento/{id}', [ConfiguracionController::class, 'tipoElementoUpdate'])->name('modules.configuracion.tipos-elemento.update');
    Route::delete('/modulos/configuracion/tipo-elemento/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.tipos-elemento.bulk-destroy');
    Route::delete('/modulos/configuracion/tipo-elemento/{id}', [ConfiguracionController::class, 'tipoElementoDestroy'])->name('modules.configuracion.tipos-elemento.destroy');

    Route::get('/modulos/configuracion/tipos-documento', [ConfiguracionController::class, 'tiposDocumentoIndex'])->name('modules.configuracion.tipos-documento.index');
    Route::get('/modulos/configuracion/tipos-documento/export/{format}', [ConfiguracionController::class, 'tiposDocumentoExport'])->name('modules.configuracion.tipos-documento.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/tipos-documento/export/{format}', [ConfiguracionController::class, 'tiposDocumentoExport'])->name('modules.configuracion.tipos-documento.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/tipos-documento/crear', [ConfiguracionController::class, 'tiposDocumentoCreate'])->name('modules.configuracion.tipos-documento.create');
    Route::post('/modulos/configuracion/tipos-documento', [ConfiguracionController::class, 'tiposDocumentoStore'])->name('modules.configuracion.tipos-documento.store');
    Route::get('/modulos/configuracion/tipos-documento/{id}/editar', [ConfiguracionController::class, 'tiposDocumentoEdit'])->name('modules.configuracion.tipos-documento.edit');
    Route::put('/modulos/configuracion/tipos-documento/{id}', [ConfiguracionController::class, 'tiposDocumentoUpdate'])->name('modules.configuracion.tipos-documento.update');
    Route::delete('/modulos/configuracion/tipos-documento/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.tipos-documento.bulk-destroy');
    Route::delete('/modulos/configuracion/tipos-documento/{id}', [ConfiguracionController::class, 'tiposDocumentoDestroy'])->name('modules.configuracion.tipos-documento.destroy');

    Route::get('/modulos/configuracion/formas-pago', [ConfiguracionController::class, 'formasPagoIndex'])->name('modules.configuracion.formas-pago.index');
    Route::get('/modulos/configuracion/formas-pago/export/{format}', [ConfiguracionController::class, 'formasPagoExport'])->name('modules.configuracion.formas-pago.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/formas-pago/export/{format}', [ConfiguracionController::class, 'formasPagoExport'])->name('modules.configuracion.formas-pago.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/formas-pago/crear', [ConfiguracionController::class, 'formasPagoCreate'])->name('modules.configuracion.formas-pago.create');
    Route::post('/modulos/configuracion/formas-pago', [ConfiguracionController::class, 'formasPagoStore'])->name('modules.configuracion.formas-pago.store');
    Route::get('/modulos/configuracion/formas-pago/{id}/editar', [ConfiguracionController::class, 'formasPagoEdit'])->name('modules.configuracion.formas-pago.edit');
    Route::put('/modulos/configuracion/formas-pago/{id}', [ConfiguracionController::class, 'formasPagoUpdate'])->name('modules.configuracion.formas-pago.update');
    Route::delete('/modulos/configuracion/formas-pago/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.formas-pago.bulk-destroy');
    Route::delete('/modulos/configuracion/formas-pago/{id}', [ConfiguracionController::class, 'formasPagoDestroy'])->name('modules.configuracion.formas-pago.destroy');

    Route::get('/modulos/configuracion/entidades-bancarias', [ConfiguracionController::class, 'entidadesBancariasIndex'])->name('modules.configuracion.entidades-bancarias.index');
    Route::get('/modulos/configuracion/entidades-bancarias/export/{format}', [ConfiguracionController::class, 'entidadesBancariasExport'])->name('modules.configuracion.entidades-bancarias.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/entidades-bancarias/export/{format}', [ConfiguracionController::class, 'entidadesBancariasExport'])->name('modules.configuracion.entidades-bancarias.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/entidades-bancarias/crear', [ConfiguracionController::class, 'entidadesBancariasCreate'])->name('modules.configuracion.entidades-bancarias.create');
    Route::post('/modulos/configuracion/entidades-bancarias', [ConfiguracionController::class, 'entidadesBancariasStore'])->name('modules.configuracion.entidades-bancarias.store');
    Route::get('/modulos/configuracion/entidades-bancarias/{id}/editar', [ConfiguracionController::class, 'entidadesBancariasEdit'])->name('modules.configuracion.entidades-bancarias.edit');
    Route::put('/modulos/configuracion/entidades-bancarias/{id}', [ConfiguracionController::class, 'entidadesBancariasUpdate'])->name('modules.configuracion.entidades-bancarias.update');
    Route::delete('/modulos/configuracion/entidades-bancarias/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.entidades-bancarias.bulk-destroy');
    Route::delete('/modulos/configuracion/entidades-bancarias/{id}', [ConfiguracionController::class, 'entidadesBancariasDestroy'])->name('modules.configuracion.entidades-bancarias.destroy');

    Route::get('/modulos/configuracion/operador', [ConfiguracionController::class, 'operadoresIndex'])->name('modules.configuracion.operadores.index');
    Route::get('/modulos/configuracion/operador/export/{format}', [ConfiguracionController::class, 'operadoresExport'])->name('modules.configuracion.operadores.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/operador/export/{format}', [ConfiguracionController::class, 'operadoresExport'])->name('modules.configuracion.operadores.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/operador/crear', [ConfiguracionController::class, 'operadoresCreate'])->name('modules.configuracion.operadores.create');
    Route::post('/modulos/configuracion/operador', [ConfiguracionController::class, 'operadoresStore'])->name('modules.configuracion.operadores.store');
    Route::get('/modulos/configuracion/operador/{id}/editar', [ConfiguracionController::class, 'operadoresEdit'])->name('modules.configuracion.operadores.edit');
    Route::put('/modulos/configuracion/operador/{id}', [ConfiguracionController::class, 'operadoresUpdate'])->name('modules.configuracion.operadores.update');
    Route::delete('/modulos/configuracion/operador/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.operadores.bulk-destroy');
    Route::delete('/modulos/configuracion/operador/{id}', [ConfiguracionController::class, 'operadoresDestroy'])->name('modules.configuracion.operadores.destroy');

    Route::get('/modulos/configuracion/tipos-vehiculo', [ConfiguracionController::class, 'tiposVehiculoIndex'])->name('modules.configuracion.tipos-vehiculo.index');
    Route::get('/modulos/configuracion/tipos-vehiculo/export/{format}', [ConfiguracionController::class, 'tiposVehiculoExport'])->name('modules.configuracion.tipos-vehiculo.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/tipos-vehiculo/export/{format}', [ConfiguracionController::class, 'tiposVehiculoExport'])->name('modules.configuracion.tipos-vehiculo.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/tipos-vehiculo/crear', [ConfiguracionController::class, 'tiposVehiculoCreate'])->name('modules.configuracion.tipos-vehiculo.create');
    Route::post('/modulos/configuracion/tipos-vehiculo', [ConfiguracionController::class, 'tiposVehiculoStore'])->name('modules.configuracion.tipos-vehiculo.store');
    Route::get('/modulos/configuracion/tipos-vehiculo/{id}/editar', [ConfiguracionController::class, 'tiposVehiculoEdit'])->name('modules.configuracion.tipos-vehiculo.edit');
    Route::put('/modulos/configuracion/tipos-vehiculo/{id}', [ConfiguracionController::class, 'tiposVehiculoUpdate'])->name('modules.configuracion.tipos-vehiculo.update');
    Route::delete('/modulos/configuracion/tipos-vehiculo/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.tipos-vehiculo.bulk-destroy');
    Route::delete('/modulos/configuracion/tipos-vehiculo/{id}', [ConfiguracionController::class, 'tiposVehiculoDestroy'])->name('modules.configuracion.tipos-vehiculo.destroy');

    Route::get('/modulos/configuracion/tipos-operacion', [ConfiguracionController::class, 'tiposOperacionIndex'])->name('modules.configuracion.tipos-operacion.index');
    Route::get('/modulos/configuracion/tipos-operacion/export/{format}', [ConfiguracionController::class, 'tiposOperacionExport'])->name('modules.configuracion.tipos-operacion.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/tipos-operacion/export/{format}', [ConfiguracionController::class, 'tiposOperacionExport'])->name('modules.configuracion.tipos-operacion.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/tipos-operacion/crear', [ConfiguracionController::class, 'tiposOperacionCreate'])->name('modules.configuracion.tipos-operacion.create');
    Route::post('/modulos/configuracion/tipos-operacion', [ConfiguracionController::class, 'tiposOperacionStore'])->name('modules.configuracion.tipos-operacion.store');
    Route::get('/modulos/configuracion/tipos-operacion/{id}/editar', [ConfiguracionController::class, 'tiposOperacionEdit'])->name('modules.configuracion.tipos-operacion.edit');
    Route::put('/modulos/configuracion/tipos-operacion/{id}', [ConfiguracionController::class, 'tiposOperacionUpdate'])->name('modules.configuracion.tipos-operacion.update');
    Route::delete('/modulos/configuracion/tipos-operacion/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.tipos-operacion.bulk-destroy');
    Route::delete('/modulos/configuracion/tipos-operacion/{id}', [ConfiguracionController::class, 'tiposOperacionDestroy'])->name('modules.configuracion.tipos-operacion.destroy');



    Route::get('/modulos/configuracion/listas-precio', [ConfiguracionController::class, 'listaprecioIndex'])->name('modules.configuracion.listas-precio.index');
    Route::get('/modulos/configuracion/listas-precio/export/{format}', [ConfiguracionController::class, 'listaprecioExport'])->name('modules.configuracion.listas-precio.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/listas-precio/export/{format}', [ConfiguracionController::class, 'listaprecioExport'])->name('modules.configuracion.listas-precio.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/listas-precio/crear', [ConfiguracionController::class, 'listaprecioCreate'])->name('modules.configuracion.listas-precio.create');
    Route::post('/modulos/configuracion/listas-precio', [ConfiguracionController::class, 'listaprecioStore'])->name('modules.configuracion.listas-precio.store');
    Route::get('/modulos/configuracion/listas-precio/{id}/editar', [ConfiguracionController::class, 'listaprecioEdit'])->name('modules.configuracion.listas-precio.edit');
    Route::put('/modulos/configuracion/listas-precio/{id}', [ConfiguracionController::class, 'listaprecioUpdate'])->name('modules.configuracion.listas-precio.update');
    Route::delete('/modulos/configuracion/listas-precio/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.listas-precio.bulk-destroy');
    Route::delete('/modulos/configuracion/listas-precio/{id}', [ConfiguracionController::class, 'listaprecioDestroy'])->name('modules.configuracion.listas-precio.destroy');

    Route::get('/modulos/configuracion/detalle-lista-precio', [ConfiguracionController::class, 'detalleListaPrecioIndex'])->name('modules.configuracion.detalle-lista-precio.index');
    Route::get('/modulos/configuracion/detalle-lista-precio/export/{format}', [ConfiguracionController::class, 'detalleListaPrecioExport'])->name('modules.configuracion.detalle-lista-precio.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/detalle-lista-precio/export/{format}', [ConfiguracionController::class, 'detalleListaPrecioExport'])->name('modules.configuracion.detalle-lista-precio.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/detalle-lista-precio/crear', [ConfiguracionController::class, 'detalleListaPrecioCreate'])->name('modules.configuracion.detalle-lista-precio.create');
    Route::post('/modulos/configuracion/detalle-lista-precio', [ConfiguracionController::class, 'detalleListaPrecioStore'])->name('modules.configuracion.detalle-lista-precio.store');
    Route::get('/modulos/configuracion/detalle-lista-precio/{id}/editar', [ConfiguracionController::class, 'detalleListaPrecioEdit'])->name('modules.configuracion.detalle-lista-precio.edit');
    Route::put('/modulos/configuracion/detalle-lista-precio/{id}', [ConfiguracionController::class, 'detalleListaPrecioUpdate'])->name('modules.configuracion.detalle-lista-precio.update');
    Route::delete('/modulos/configuracion/detalle-lista-precio/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.detalle-lista-precio.bulk-destroy');
    Route::delete('/modulos/configuracion/detalle-lista-precio/{id}', [ConfiguracionController::class, 'detalleListaPrecioDestroy'])->name('modules.configuracion.detalle-lista-precio.destroy');

    Route::get('/modulos/configuracion/tipos-pedido', [ConfiguracionController::class, 'tipopedidoIndex'])->name('modules.configuracion.tipos-pedido.index');
    Route::get('/modulos/configuracion/tipos-pedido/export/{format}', [ConfiguracionController::class, 'tipopedidoExport'])->name('modules.configuracion.tipos-pedido.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/tipos-pedido/export/{format}', [ConfiguracionController::class, 'tipopedidoExport'])->name('modules.configuracion.tipos-pedido.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/tipos-pedido/crear', [ConfiguracionController::class, 'tipopedidoCreate'])->name('modules.configuracion.tipos-pedido.create');
    Route::post('/modulos/configuracion/tipos-pedido', [ConfiguracionController::class, 'tipopedidoStore'])->name('modules.configuracion.tipos-pedido.store');
    Route::get('/modulos/configuracion/tipos-pedido/{id}/editar', [ConfiguracionController::class, 'tipopedidoEdit'])->name('modules.configuracion.tipos-pedido.edit');
    Route::put('/modulos/configuracion/tipos-pedido/{id}', [ConfiguracionController::class, 'tipopedidoUpdate'])->name('modules.configuracion.tipos-pedido.update');
    Route::delete('/modulos/configuracion/tipos-pedido/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.tipos-pedido.bulk-destroy');
    Route::delete('/modulos/configuracion/tipos-pedido/{id}', [ConfiguracionController::class, 'tipopedidoDestroy'])->name('modules.configuracion.tipos-pedido.destroy');

    Route::get('/modulos/configuracion/proveedores', [ConfiguracionController::class, 'proveedorIndex'])->name('modules.configuracion.proveedores.index');
    Route::get('/modulos/configuracion/proveedores/export/{format}', [ConfiguracionController::class, 'proveedorExport'])->name('modules.configuracion.proveedores.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/proveedores/export/{format}', [ConfiguracionController::class, 'proveedorExport'])->name('modules.configuracion.proveedores.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/proveedores/crear', [ConfiguracionController::class, 'proveedorCreate'])->name('modules.configuracion.proveedores.create');
    Route::post('/modulos/configuracion/proveedores', [ConfiguracionController::class, 'proveedorStore'])->name('modules.configuracion.proveedores.store');
    Route::get('/modulos/configuracion/proveedores/{id}/editar', [ConfiguracionController::class, 'proveedorEdit'])->name('modules.configuracion.proveedores.edit');
    Route::put('/modulos/configuracion/proveedores/{id}', [ConfiguracionController::class, 'proveedorUpdate'])->name('modules.configuracion.proveedores.update');
    Route::delete('/modulos/configuracion/proveedores/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.proveedores.bulk-destroy');
    Route::delete('/modulos/configuracion/proveedores/{id}', [ConfiguracionController::class, 'proveedorDestroy'])->name('modules.configuracion.proveedores.destroy');

    Route::get('/modulos/configuracion/vigencias-oferta', [ConfiguracionController::class, 'vigenciaofertaIndex'])->name('modules.configuracion.vigencias-oferta.index');
    Route::get('/modulos/configuracion/vigencias-oferta/export/{format}', [ConfiguracionController::class, 'vigenciaofertaExport'])->name('modules.configuracion.vigencias-oferta.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/vigencias-oferta/export/{format}', [ConfiguracionController::class, 'vigenciaofertaExport'])->name('modules.configuracion.vigencias-oferta.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/vigencias-oferta/crear', [ConfiguracionController::class, 'vigenciaofertaCreate'])->name('modules.configuracion.vigencias-oferta.create');
    Route::post('/modulos/configuracion/vigencias-oferta', [ConfiguracionController::class, 'vigenciaofertaStore'])->name('modules.configuracion.vigencias-oferta.store');
    Route::get('/modulos/configuracion/vigencias-oferta/{id}/editar', [ConfiguracionController::class, 'vigenciaofertaEdit'])->name('modules.configuracion.vigencias-oferta.edit');
    Route::put('/modulos/configuracion/vigencias-oferta/{id}', [ConfiguracionController::class, 'vigenciaofertaUpdate'])->name('modules.configuracion.vigencias-oferta.update');
    Route::delete('/modulos/configuracion/vigencias-oferta/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.vigencias-oferta.bulk-destroy');
    Route::delete('/modulos/configuracion/vigencias-oferta/{id}', [ConfiguracionController::class, 'vigenciaofertaDestroy'])->name('modules.configuracion.vigencias-oferta.destroy');

    Route::get('/modulos/configuracion/certificados-sunat', [ConfiguracionController::class, 'certificadosUnatIndex'])->name('modules.configuracion.certificados-sunat.index');
    Route::get('/modulos/configuracion/certificados-sunat/export/{format}', [ConfiguracionController::class, 'certificadosUnatExport'])->name('modules.configuracion.certificados-sunat.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/certificados-sunat/export/{format}', [ConfiguracionController::class, 'certificadosUnatExport'])->name('modules.configuracion.certificados-sunat.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/certificados-sunat/crear', [ConfiguracionController::class, 'certificadosUnatCreate'])->name('modules.configuracion.certificados-sunat.create');
    Route::post('/modulos/configuracion/certificados-sunat', [ConfiguracionController::class, 'certificadosUnatStore'])->name('modules.configuracion.certificados-sunat.store');
    Route::get('/modulos/configuracion/certificados-sunat/{id}/editar', [ConfiguracionController::class, 'certificadosUnatEdit'])->name('modules.configuracion.certificados-sunat.edit');
    Route::put('/modulos/configuracion/certificados-sunat/{id}', [ConfiguracionController::class, 'certificadosUnatUpdate'])->name('modules.configuracion.certificados-sunat.update');
    Route::delete('/modulos/configuracion/certificados-sunat/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.certificados-sunat.bulk-destroy');
    Route::delete('/modulos/configuracion/certificados-sunat/{id}', [ConfiguracionController::class, 'certificadosUnatDestroy'])->name('modules.configuracion.certificados-sunat.destroy');

    Route::get('/modulos/configuracion/empresapropietaria', [ConfiguracionController::class, 'empresapropietariaIndex'])->name('modules.configuracion.empresapropietaria.index');
    Route::get('/modulos/configuracion/empresapropietaria/export/{format}', [ConfiguracionController::class, 'empresapropietariaExport'])->name('modules.configuracion.empresapropietaria.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/empresapropietaria/export/{format}', [ConfiguracionController::class, 'empresapropietariaExport'])->name('modules.configuracion.empresapropietaria.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/empresapropietaria/crear', [ConfiguracionController::class, 'empresapropietariaCreate'])->name('modules.configuracion.empresapropietaria.create');
    Route::post('/modulos/configuracion/empresapropietaria', [ConfiguracionController::class, 'empresapropietariaStore'])->name('modules.configuracion.empresapropietaria.store');
    Route::get('/modulos/configuracion/empresapropietaria/{id}/editar', [ConfiguracionController::class, 'empresapropietariaEdit'])->name('modules.configuracion.empresapropietaria.edit');
    Route::put('/modulos/configuracion/empresapropietaria/{id}', [ConfiguracionController::class, 'empresapropietariaUpdate'])->name('modules.configuracion.empresapropietaria.update');
    Route::delete('/modulos/configuracion/empresapropietaria/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.empresapropietaria.bulk-destroy');
    Route::delete('/modulos/configuracion/empresapropietaria/{id}', [ConfiguracionController::class, 'empresapropietariaDestroy'])->name('modules.configuracion.empresapropietaria.destroy');

    Route::get('/modulos/configuracion/modelo', [ConfiguracionController::class, 'modeloIndex'])->name('modules.configuracion.modelo.index');
    Route::get('/modulos/configuracion/modelo/export/{format}', [ConfiguracionController::class, 'modeloExport'])->name('modules.configuracion.modelo.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/modelo/export/{format}', [ConfiguracionController::class, 'modeloExport'])->name('modules.configuracion.modelo.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/modelo/crear', [ConfiguracionController::class, 'modeloCreate'])->name('modules.configuracion.modelo.create');
    Route::post('/modulos/configuracion/modelo', [ConfiguracionController::class, 'modeloStore'])->name('modules.configuracion.modelo.store');
    Route::get('/modulos/configuracion/modelo/{id}/editar', [ConfiguracionController::class, 'modeloEdit'])->name('modules.configuracion.modelo.edit');
    Route::put('/modulos/configuracion/modelo/{id}', [ConfiguracionController::class, 'modeloUpdate'])->name('modules.configuracion.modelo.update');
    Route::delete('/modulos/configuracion/modelo/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.modelo.bulk-destroy');
    Route::delete('/modulos/configuracion/modelo/{id}', [ConfiguracionController::class, 'modeloDestroy'])->name('modules.configuracion.modelo.destroy');

    Route::get('/modulos/configuracion/marca', [ConfiguracionController::class, 'marcasIndex'])->name('modules.configuracion.marcas.index');
    Route::get('/modulos/configuracion/marca/export/{format}', [ConfiguracionController::class, 'marcasExport'])->name('modules.configuracion.marcas.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/marca/export/{format}', [ConfiguracionController::class, 'marcasExport'])->name('modules.configuracion.marcas.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/marca/crear', [ConfiguracionController::class, 'marcasCreate'])->name('modules.configuracion.marcas.create');
    Route::post('/modulos/configuracion/marca', [ConfiguracionController::class, 'marcasStore'])->name('modules.configuracion.marcas.store');
    Route::get('/modulos/configuracion/marca/{id}/editar', [ConfiguracionController::class, 'marcasEdit'])->name('modules.configuracion.marcas.edit');
    Route::put('/modulos/configuracion/marca/{id}', [ConfiguracionController::class, 'marcasUpdate'])->name('modules.configuracion.marcas.update');
    Route::delete('/modulos/configuracion/marca/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.marcas.bulk-destroy');
    Route::delete('/modulos/configuracion/marca/{id}', [ConfiguracionController::class, 'marcasDestroy'])->name('modules.configuracion.marcas.destroy');

    Route::get('/modulos/configuracion/tecnologia', [ConfiguracionController::class, 'tecnologiasIndex'])->name('modules.configuracion.tecnologias.index');
    Route::get('/modulos/configuracion/tecnologia/export/{format}', [ConfiguracionController::class, 'tecnologiasExport'])->name('modules.configuracion.tecnologias.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/tecnologia/export/{format}', [ConfiguracionController::class, 'tecnologiasExport'])->name('modules.configuracion.tecnologias.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/tecnologia/crear', [ConfiguracionController::class, 'tecnologiasCreate'])->name('modules.configuracion.tecnologias.create');
    Route::post('/modulos/configuracion/tecnologia', [ConfiguracionController::class, 'tecnologiasStore'])->name('modules.configuracion.tecnologias.store');
    Route::get('/modulos/configuracion/tecnologia/{id}/editar', [ConfiguracionController::class, 'tecnologiasEdit'])->name('modules.configuracion.tecnologias.edit');
    Route::put('/modulos/configuracion/tecnologia/{id}', [ConfiguracionController::class, 'tecnologiasUpdate'])->name('modules.configuracion.tecnologias.update');
    Route::delete('/modulos/configuracion/tecnologia/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.tecnologias.bulk-destroy');
    Route::delete('/modulos/configuracion/tecnologia/{id}', [ConfiguracionController::class, 'tecnologiasDestroy'])->name('modules.configuracion.tecnologias.destroy');

    Route::get('/modulos/configuracion/tipos-gasto', [ConfiguracionController::class, 'tiposGastoIndex'])->name('modules.configuracion.tipos-gasto.index');
    Route::get('/modulos/configuracion/tipos-gasto/export/{format}', [ConfiguracionController::class, 'tiposGastoExport'])->name('modules.configuracion.tipos-gasto.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/tipos-gasto/export/{format}', [ConfiguracionController::class, 'tiposGastoExport'])->name('modules.configuracion.tipos-gasto.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/tipos-gasto/crear', [ConfiguracionController::class, 'tiposGastoCreate'])->name('modules.configuracion.tipos-gasto.create');
    Route::post('/modulos/configuracion/tipos-gasto', [ConfiguracionController::class, 'tiposGastoStore'])->name('modules.configuracion.tipos-gasto.store');
    Route::get('/modulos/configuracion/tipos-gasto/{id}/editar', [ConfiguracionController::class, 'tiposGastoEdit'])->name('modules.configuracion.tipos-gasto.edit');
    Route::put('/modulos/configuracion/tipos-gasto/{id}', [ConfiguracionController::class, 'tiposGastoUpdate'])->name('modules.configuracion.tipos-gasto.update');
    Route::delete('/modulos/configuracion/tipos-gasto/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.tipos-gasto.bulk-destroy');
    Route::delete('/modulos/configuracion/tipos-gasto/{id}', [ConfiguracionController::class, 'tiposGastoDestroy'])->name('modules.configuracion.tipos-gasto.destroy');

    Route::get('/modulos/configuracion/tipos-cobro', [ConfiguracionController::class, 'tiposCobroIndex'])->name('modules.configuracion.tipos-cobro.index');
    Route::get('/modulos/configuracion/tipos-cobro/export/{format}', [ConfiguracionController::class, 'tiposCobroExport'])->name('modules.configuracion.tipos-cobro.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/tipos-cobro/export/{format}', [ConfiguracionController::class, 'tiposCobroExport'])->name('modules.configuracion.tipos-cobro.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/tipos-cobro/crear', [ConfiguracionController::class, 'tiposCobroCreate'])->name('modules.configuracion.tipos-cobro.create');
    Route::post('/modulos/configuracion/tipos-cobro', [ConfiguracionController::class, 'tiposCobroStore'])->name('modules.configuracion.tipos-cobro.store');
    Route::get('/modulos/configuracion/tipos-cobro/{id}/editar', [ConfiguracionController::class, 'tiposCobroEdit'])->name('modules.configuracion.tipos-cobro.edit');
    Route::put('/modulos/configuracion/tipos-cobro/{id}', [ConfiguracionController::class, 'tiposCobroUpdate'])->name('modules.configuracion.tipos-cobro.update');
    Route::delete('/modulos/configuracion/tipos-cobro/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.tipos-cobro.bulk-destroy');
    Route::delete('/modulos/configuracion/tipos-cobro/{id}', [ConfiguracionController::class, 'tiposCobroDestroy'])->name('modules.configuracion.tipos-cobro.destroy');

    Route::get('/modulos/configuracion/ubigeo', [ConfiguracionController::class, 'ubigeosIndex'])->name('modules.configuracion.ubigeos.index');
    Route::get('/modulos/configuracion/ubigeo/export/{format}', [ConfiguracionController::class, 'ubigeosExport'])->name('modules.configuracion.ubigeos.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/ubigeo/export/{format}', [ConfiguracionController::class, 'ubigeosExport'])->name('modules.configuracion.ubigeos.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/ubigeo/crear', [ConfiguracionController::class, 'ubigeosCreate'])->name('modules.configuracion.ubigeos.create');
    Route::post('/modulos/configuracion/ubigeo', [ConfiguracionController::class, 'ubigeosStore'])->name('modules.configuracion.ubigeos.store');
    Route::get('/modulos/configuracion/ubigeo/{id}/editar', [ConfiguracionController::class, 'ubigeosEdit'])->name('modules.configuracion.ubigeos.edit');
    Route::put('/modulos/configuracion/ubigeo/{id}', [ConfiguracionController::class, 'ubigeosUpdate'])->name('modules.configuracion.ubigeos.update');
    Route::delete('/modulos/configuracion/ubigeo/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.ubigeos.bulk-destroy');
    Route::delete('/modulos/configuracion/ubigeo/{id}', [ConfiguracionController::class, 'ubigeosDestroy'])->name('modules.configuracion.ubigeos.destroy');

    Route::get('/modulos/configuracion/cargo', [ConfiguracionController::class, 'cargosIndex'])->name('modules.configuracion.cargos.index');
    Route::get('/modulos/configuracion/cargo/export/{format}', [ConfiguracionController::class, 'cargosExport'])->name('modules.configuracion.cargos.export')->where('format', 'pdf|xlsx');
    Route::post('/modulos/configuracion/cargo/export/{format}', [ConfiguracionController::class, 'cargosExport'])->name('modules.configuracion.cargos.export.post')->where('format', 'pdf|xlsx');
    Route::get('/modulos/configuracion/cargo/crear', [ConfiguracionController::class, 'cargosCreate'])->name('modules.configuracion.cargos.create');
    Route::post('/modulos/configuracion/cargo', [ConfiguracionController::class, 'cargosStore'])->name('modules.configuracion.cargos.store');
    Route::get('/modulos/configuracion/cargo/{id}/editar', [ConfiguracionController::class, 'cargosEdit'])->name('modules.configuracion.cargos.edit');
    Route::put('/modulos/configuracion/cargo/{id}', [ConfiguracionController::class, 'cargosUpdate'])->name('modules.configuracion.cargos.update');
    Route::delete('/modulos/configuracion/cargo/bulk-destroy', [BulkDestroyController::class, 'destroy'])->name('modules.configuracion.cargos.bulk-destroy');
    Route::delete('/modulos/configuracion/cargo/{id}', [ConfiguracionController::class, 'cargosDestroy'])->name('modules.configuracion.cargos.destroy');

    Route::get('/modulos/configuracion/auditoria', [ConfiguracionController::class, 'auditoriaIndex'])->name('modules.configuracion.auditoria.index');
    Route::get('/modulos/configuracion/auditoria/export/{format}', [ConfiguracionController::class, 'auditoriaExport'])->name('modules.configuracion.auditoria.export')->where('format', 'pdf|xlsx');
});
