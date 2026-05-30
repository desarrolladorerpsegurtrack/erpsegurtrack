<?php

use App\Http\Controllers\TicketsController;
use Illuminate\Support\Facades\Route;

Route::middleware('erp.module:tickets')->group(function () {
    Route::get('/modulos/tickets', [TicketsController::class, 'index'])->name('modules.tickets');
    Route::get('/modulos/tickets/crear', [TicketsController::class, 'create'])->name('modules.tickets.create');
    Route::get('/modulos/tickets/latest-row', [TicketsController::class, 'latestRow'])->name('modules.tickets.latestRow');
    Route::post('/modulos/tickets', [TicketsController::class, 'store'])->name('modules.tickets.store');
    Route::get('/modulos/tickets/{ticketId}', [TicketsController::class, 'show'])->name('modules.tickets.show');
    Route::post('/modulos/tickets/{ticketId}/cancelar', [TicketsController::class, 'cancel'])->name('modules.tickets.cancel');
    Route::post('/modulos/tickets/{ticketId}/avanzar', [TicketsController::class, 'advance'])->name('modules.tickets.advance');
});
