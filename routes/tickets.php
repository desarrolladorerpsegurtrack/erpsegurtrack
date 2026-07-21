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
    Route::post('/modulos/tickets/vehiculos/guardar', [TicketsController::class, 'storeVehiculo'])->name('modules.tickets.vehiculos.store');
    // Ruta temporal de depuración: enviar notificación al usuario de la sesión
    Route::get('/debug/notify-me', function () {
        $user = request()->session()->get('erp_auth.usuario', 'anonimo');
        $wsUrl = config('locks.ws_server_url', env('WS_SERVER_URL', 'http://127.0.0.1:6001'));
        $payload = [
            'type' => 'resource.changed',
            'resource' => 'notification',
            'id' => $user,
            'usuario' => $user,
            'action' => 'created',
            'meta' => [
                'message' => 'Tienes una nueva gestión para atender.',
                'ticketId' => null,
                'url' => null,
            ],
        ];

        try {
            \Illuminate\Support\Facades\Http::timeout(2)->post(rtrim($wsUrl, '/').'/publish', $payload);
            return response()->json(['success' => true, 'sent_to' => $user]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    });
});
