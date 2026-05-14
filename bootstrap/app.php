<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'erp.auth' => \App\Http\Middleware\EnsureErpAuthenticated::class,
            'erp.role' => \App\Http\Middleware\EnsureErpRole::class,
            'erp.module' => \App\Http\Middleware\EnsureErpModule::class,
            'erp.action' => \App\Http\Middleware\EnsureErpAction::class,
            'audit.log' => \App\Http\Middleware\AuditLog::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
