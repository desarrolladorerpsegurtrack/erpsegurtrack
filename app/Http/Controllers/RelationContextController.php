<?php

namespace App\Http\Controllers;

use App\Support\RelationContext;
use Illuminate\Http\JsonResponse;

class RelationContextController extends Controller
{
    public function show(string $resource, string $id): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'data' => RelationContext::summarize($resource, $id),
        ]);
    }
}