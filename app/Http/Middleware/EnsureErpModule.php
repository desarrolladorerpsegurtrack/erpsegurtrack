<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureErpModule
{
    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        $authData = $request->session()->get('erp_auth');

        if (!$authData) {
            return redirect()->guest(route('login'));
        }

        if ($modules === []) {
            return $next($request);
        }

        $userRoles = collect($authData['roles'] ?? [])
            ->map(fn ($role) => mb_strtolower(trim((string) $role)))
            ->filter();

        if ($userRoles->contains('admin')) {
            return $next($request);
        }

        $userModules = collect($authData['modules'] ?? [])
            ->map(fn ($module) => mb_strtolower(trim((string) $module)))
            ->filter();

        $requiredModules = collect($modules)
            ->map(fn ($module) => mb_strtolower(trim($module)))
            ->filter();

        if ($userModules->intersect($requiredModules)->isEmpty()) {
            abort(403, 'No tienes acceso a este módulo.');
        }

        return $next($request);
    }
}
