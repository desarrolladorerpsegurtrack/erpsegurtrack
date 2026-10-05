<?php

namespace App\Http\Controllers;

use App\Support\RelationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RelationContextController extends Controller
{
    public function show(Request $request, string $resource, string $id): JsonResponse
    {
        $authData = $request->session()->get('erp_auth', []);
        $hasAuthenticatedUser = !empty($authData['usuario'] ?? $authData['user'] ?? $authData['roles'] ?? $authData['permissions'] ?? null);

        if (!$hasAuthenticatedUser) {
            abort(403, 'No tienes acceso a esta vista de relaciones.');
        }
        
        return response()->json([
            'ok' => true,
            'data' => RelationContext::summarize($resource, $id),
        ]);
    }
}