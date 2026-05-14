<?php

namespace App\Http\Controllers\Permission;

use App\Support\ResourceLock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

trait HandlesResourceLock
{
    private function publishWebSocketEvent(array $payload): void
    {
        if (!config('locks.enabled', true)) {
            return;
        }

        $wsUrl = config('locks.ws_server_url', env('WS_SERVER_URL', 'http://127.0.0.1:6001'));

        try {
            Http::timeout(2)->post(rtrim($wsUrl, '/').'/publish', $payload);
        } catch (\Throwable $e) {
            // Local WebSocket server not available, continue silently.
        }
    }

    private function publishLockEvent(string $resource, string $id, string $usuario, string $action, ?string $expiresAt): void
    {
        $this->publishWebSocketEvent([
            'type' => 'lock.changed',
            'resource' => $resource,
            'id' => $id,
            'usuario' => $usuario,
            'action' => $action,
            'expiresAt' => $expiresAt,
        ]);
    }

    protected function publishResourceEvent(string $resource, string $id, string $action, array $meta = []): void
    {
        $this->publishWebSocketEvent([
            'type' => 'resource.changed',
            'resource' => $resource,
            'id' => $id,
            'usuario' => $this->getCurrentLockUser(),
            'action' => $action,
            'meta' => $meta,
        ]);
    }

    protected function publishUserPermissionsChanged(string $usuario, array $meta = []): void
    {
        $this->publishWebSocketEvent([
            'type' => 'user.permissions.changed',
            'resource' => 'user',
            'id' => $usuario,
            'usuario' => $this->getCurrentLockUser(),
            'action' => 'updated',
            'meta' => $meta,
        ]);
    }

    protected function publishUsersAffectedByRole(int $roleId, array $meta = []): void
    {
        $usuarios = DB::table('detallerol')
            ->where('rol_idrol', $roleId)
            ->pluck('usuario_usuario')
            ->unique()
            ->values()
            ->all();

        foreach ($usuarios as $usuario) {
            $this->publishUserPermissionsChanged((string) $usuario, $meta);
        }
    }

    protected function getCurrentLockUser(): string
    {
        return request()->session()->get('erp_auth.usuario', 'anonimo');
    }

    protected function prepareLockViewData(string $resource, string $id): array
    {
        $currentUser = $this->getCurrentLockUser();
        $lockInfo = ResourceLock::status($resource, $id);
        $lockOwner = $lockInfo['usuario'] ?? null;

        return [
            'lockInfo' => $lockInfo,
            'lockBlocked' => $lockInfo && $lockOwner !== $currentUser,
            'lockOwner' => $lockOwner,
            'lockResource' => $resource,
            'lockId' => $id,
        ];
    }

    protected function assertLockAvailable(Request $request, string $resource, string $id, string $label, string $redirectRoute): ?RedirectResponse
    {
        $currentUser = $request->session()->get('erp_auth.usuario', 'anonimo');
        $lockInfo = ResourceLock::status($resource, $id);

        if ($lockInfo && $lockInfo['usuario'] !== $currentUser) {
            return redirect()
                ->route($redirectRoute)
                ->with('error', "El {$label} está siendo editado por {$lockInfo['usuario']} y no puede modificarse hasta que se libere.");
        }

        return null;
    }

    protected function releaseLockIfOwned(Request $request, string $resource, string $id): void
    {
        $currentUser = $request->session()->get('erp_auth.usuario', 'anonimo');
        $lockInfo = ResourceLock::status($resource, $id);

        if ($lockInfo && $lockInfo['usuario'] === $currentUser) {
            ResourceLock::release($resource, $id, $currentUser);
            $this->publishLockEvent($resource, $id, $currentUser, 'released', null);
        }
    }
}
