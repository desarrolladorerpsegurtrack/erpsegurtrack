<?php

namespace App\Http\Middleware;

use App\Support\ErpAuthSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureErpAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->session()->has('erp_auth')) {
            return redirect()->guest(route('login'));
        }

        if (!ErpAuthSession::ensureCurrentSessionMatchesDatabase($request)) {
            return redirect()->guest(route('login'));
        }

        return $next($request);
    }
}
