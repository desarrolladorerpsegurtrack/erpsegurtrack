<?php

namespace App\Http\Middleware;

use App\Support\ErpPermission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureErpAction
{
    public function handle(Request $request, Closure $next, ?string $module = null, ?string $action = null): Response
    {
        $authData = $request->session()->get('erp_auth');
        $routeName = (string) optional($request->route())->getName();

        if (!$authData) {
            return redirect()->guest(route('login'));
        }

        $userRoles = collect($authData['roles'] ?? [])
            ->map(fn ($role) => mb_strtolower(trim((string) $role)))
            ->filter();

        if ($userRoles->contains('admin')) {
            return $next($request);
        }

        [$resolvedPermissionKey, $resolvedAction] = $this->resolveRequirement($request, $module, $action);

        if ($resolvedPermissionKey === null || $resolvedAction === null) {
            if ($routeName !== '' && str_starts_with($routeName, 'modules.')) {
                abort(403, 'No se pudo resolver el permiso de la ruta.');
            }

            return $next($request);
        }

        $permissionsMap = collect($authData['permissions'] ?? []);

        $userActions = collect($permissionsMap->get($resolvedPermissionKey, []))
            ->map(fn ($value) => ErpPermission::normalizeAction((string) $value))
            ->filter();

        if (
            str_contains($resolvedPermissionKey, '.')
            && $userActions->isEmpty()
        ) {
            $parentModule = ErpPermission::permissionKeyToModule($resolvedPermissionKey);

            if ($parentModule !== null) {
                $hasGranularPermissions = $permissionsMap
                    ->keys()
                    ->contains(
                        fn ($key) => is_string($key)
                            && str_starts_with(mb_strtolower(trim((string) $key)), $parentModule . '.')
                    );

                if (!$hasGranularPermissions) {
                    $userActions = collect($permissionsMap->get($parentModule, []))
                        ->map(fn ($value) => ErpPermission::normalizeAction((string) $value))
                        ->filter();
                }
            }
        }

        if ($userActions->contains($resolvedAction)) {
            return $next($request);
        }

        abort(403, 'No tienes acceso a esta acción.');
    }

    private function resolveRequirement(Request $request, ?string $module, ?string $action): array
    {
        $resolvedPermissionKey = ErpPermission::normalizePermissionKey($module);
        $resolvedAction = ErpPermission::normalizeAction($action);

        $routeName = (string) optional($request->route())->getName();

        if ($resolvedPermissionKey === null) {
            $resolvedPermissionKey = ErpPermission::resolvePermissionKeyFromRouteName($routeName);
        }

        if ($resolvedAction === null) {
            $resolvedAction = ErpPermission::inferActionFromRouteName($routeName, $request->method());
        }

        if ($resolvedAction === null) {
            $resolvedAction = ErpPermission::normalizeAction(optional($request->route())->getActionMethod());
        }

        return [$resolvedPermissionKey, $resolvedAction];
    }
}
