<?php

namespace App\Http\Controllers;

use App\Support\ResourceLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class LockController extends Controller
{
    public function status(string $resource, string $id): JsonResponse
    {
        $status = ResourceLock::status($resource, $id);

        return response()->json([
            'locked' => $status !== null,
            'lock' => $status,
        ]);
    }

    public function acquire(Request $request, string $resource, string $id): JsonResponse
    {
        $usuario = $request->session()->get('erp_auth.usuario', 'anonimo');
        $result = ResourceLock::acquire($resource, $id, $usuario);

        if ($result['success']) {
            $this->publishLockEvent($resource, $id, $usuario, 'locked', $result['lock']['expires_at']);

            return response()->json([
                'success' => true,
                'lock' => $result['lock'],
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'El registro ya se encuentra bloqueado por otro usuario.',
            'lock' => $result['lock'],
        ], 409);
    }

    public function release(Request $request, string $resource, string $id): JsonResponse
    {
        $usuario = $request->session()->get('erp_auth.usuario', 'anonimo');
        $result = ResourceLock::release($resource, $id, $usuario);

        if ($result['success']) {
            $this->publishLockEvent($resource, $id, $usuario, 'released', null);
            $this->publishResourceEvent($resource, $id, 'released', ['source' => 'lock']);
            return response()->json([
                'success' => true,
                'lock' => $result['lock'],
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'No se pudo liberar el bloqueo o el bloqueo no pertenece al usuario actual.',
            'lock' => $result['lock'],
        ], 403);
    }

    protected function publishLockEvent(string $resource, string $id, string $usuario, string $action, ?string $expiresAt): void
    {
        $wsUrl = rtrim(config('locks.ws_server_url', env('WS_SERVER_URL', 'http://127.0.0.1:6001')), '/');

        try {
            Http::timeout(2)->post($wsUrl . '/publish', [
                'type' => 'lock.changed',
                'resource' => $resource,
                'id' => $id,
                'usuario' => $usuario,
                'action' => $action,
                'expiresAt' => $expiresAt,
            ]);
        } catch (\Throwable $e) {
            // Log the error but don't fail the request.
            \Log::warning('Failed to publish lock event: ' . $e->getMessage());
        }
    }

}
