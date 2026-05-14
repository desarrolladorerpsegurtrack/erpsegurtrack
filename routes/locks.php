<?php

use App\Http\Controllers\LockController;
use Illuminate\Support\Facades\Route;

Route::post('/locks/{resource}/{id}/acquire', [LockController::class, 'acquire'])
    ->name('locks.acquire')
    ->where([ 'resource' => '[A-Za-z0-9._-]+', 'id' => '[^/]+' ]);

Route::post('/locks/{resource}/{id}/release', [LockController::class, 'release'])
    ->name('locks.release')
    ->where([ 'resource' => '[A-Za-z0-9._-]+', 'id' => '[^/]+' ]);

Route::get('/locks/{resource}/{id}/status', [LockController::class, 'status'])
    ->name('locks.status')
    ->where([ 'resource' => '[A-Za-z0-9._-]+', 'id' => '[^/]+' ]);
