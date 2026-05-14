<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureErpRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $authData = $request->session()->get('erp_auth');

        if (!$authData) {
            return redirect()->guest(route('login'));
        }

        if ($roles === []) {
            return $next($request);
        }

        $userRoles = collect($authData['roles'] ?? [])
            ->map(fn ($role) => mb_strtolower(trim((string) $role)))
            ->filter();

        $requiredRoles = collect($roles)
            ->map(fn ($role) => mb_strtolower(trim($role)))
            ->filter();

        if ($userRoles->intersect($requiredRoles)->isEmpty()) {
            abort(403, 'No tienes acceso a este módulo.');
        }

        return $next($request);
    }
}
