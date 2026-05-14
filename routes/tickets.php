<?php

use App\Http\Controllers\TicketsController;
use Illuminate\Support\Facades\Route;

Route::middleware('erp.module:tickets')->group(function () {
    Route::get('/modulos/tickets', [TicketsController::class, 'index'])->name('modules.tickets');
    Route::get('/modulos/tickets/crear', [TicketsController::class, 'create'])->name('modules.tickets.create');
    Route::post('/modulos/tickets', [TicketsController::class, 'store'])->name('modules.tickets.store');
    Route::get('/modulos/tickets/{ticket}/editar', [TicketsController::class, 'edit'])->name('modules.tickets.edit');
    Route::put('/modulos/tickets/{ticket}', [TicketsController::class, 'update'])->name('modules.tickets.update');
    Route::delete('/modulos/tickets/{ticket}', [TicketsController::class, 'destroy'])->name('modules.tickets.destroy');
    Route::get('/modulos/tickets/{ticket}/lock-status', [TicketsController::class, 'lockStatus'])->name('modules.tickets.lock-status');
    Route::post('/modulos/tickets/{ticket}/lock', [TicketsController::class, 'acquireLock'])->name('modules.tickets.lock');
    Route::post('/modulos/tickets/{ticket}/unlock', [TicketsController::class, 'releaseLock'])->name('modules.tickets.unlock');
});
