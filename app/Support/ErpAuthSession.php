<?php

namespace App\Support;

use App\Support\ErpPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ErpAuthSession
{
    private const DEFAULT_MODULES = ['personal', 'roles', 'usuarios', 'clientes', 'configuracion'];
    private const DEFAULT_ACTIONS = ['ver', 'crear', 'editar', 'eliminar'];

    public static function calculateAuthForUser(string $usuario): ?array
    {
        $user = DB::table('usuario')->where('usuario', $usuario)->first();

        if (!$user) {
            return null;
        }

        $roleRows = self::getUserRoleRows($usuario);
        $roles = self::resolveRoles($roleRows);
        $isAdmin = self::isAdmin($roles);
        $permissions = self::resolvePermissions($roleRows, $isAdmin);
        $modules = self::resolveModules($permissions, $isAdmin);

        return [
            'usuario' => $usuario,
            'personal_dni' => $user->personal_dniPersonal,
            'roles' => $roles,
            'modules' => $modules->all(),
            'permissions' => $permissions,
        ];
    }

    public static function ensureCurrentSessionMatchesDatabase(Request $request): bool
    {
        $usuario = $request->session()->get('erp_auth.usuario');
        if (!$usuario) {
            return false;
        }

        $freshAuth = self::calculateAuthForUser($usuario);
        if ($freshAuth === null) {
            $request->session()->forget('erp_auth');
            return false;
        }

        $currentAuth = $request->session()->get('erp_auth', []);
        if (self::authDataChanged($currentAuth, $freshAuth)) {
            $request->session()->put('erp_auth', $freshAuth);
        }

        return true;
    }

    private static function authDataChanged(array $currentAuth, array $freshAuth): bool
    {
        if (($currentAuth['usuario'] ?? '') !== ($freshAuth['usuario'] ?? '')) {
            return true;
        }

        if (($currentAuth['personal_dni'] ?? '') !== ($freshAuth['personal_dni'] ?? '')) {
            return true;
        }

        if (!self::arraysAreEquivalent($currentAuth['roles'] ?? [], $freshAuth['roles'] ?? [])) {
            return true;
        }

        if (!self::arraysAreEquivalent($currentAuth['modules'] ?? [], $freshAuth['modules'] ?? [])) {
            return true;
        }

        if (self::permissionsDiffer($currentAuth['permissions'] ?? [], $freshAuth['permissions'] ?? [])) {
            return true;
        }

        return false;
    }

    private static function arraysAreEquivalent(array $first, array $second): bool
    {
        sort($first);
        sort($second);

        return $first === $second;
    }

    private static function permissionsDiffer(array $currentPermissions, array $freshPermissions): bool
    {
        if (count($currentPermissions) !== count($freshPermissions)) {
            return true;
        }

        ksort($currentPermissions);
        ksort($freshPermissions);

        foreach ($freshPermissions as $permissionKey => $actions) {
            if (!isset($currentPermissions[$permissionKey])) {
                return true;
            }

            $currentActions = $currentPermissions[$permissionKey] ?? [];
            if (!self::arraysAreEquivalent($currentActions, $actions)) {
                return true;
            }
        }

        return false;
    }

    private static function getUserRoleRows(string $usuario): Collection
    {
        return DB::table('detallerol as dr')
            ->join('rol as r', 'dr.rol_idrol', '=', 'r.idrol')
            ->leftJoin('inforol as ir', 'r.idrol', '=', 'ir.rol_idrol')
            ->where('dr.usuario_usuario', $usuario)
            ->select('r.nombre', 'ir.modulo', 'ir.accion')
            ->get();
    }

    private static function resolveRoles(Collection $roleRows): array
    {
        return $roleRows
            ->pluck('nombre')
            ->map(fn ($role): string => trim((string) $role))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private static function isAdmin(array $roles): bool
    {
        return collect($roles)
            ->map(fn (string $role): string => mb_strtolower(trim($role)))
            ->contains('admin');
    }

    private static function resolveModules(array $permissions, bool $isAdmin): Collection
    {
        if ($isAdmin) {
            return collect(self::DEFAULT_MODULES);
        }

        return collect(array_keys($permissions))
            ->map(fn ($permissionKey): ?string => ErpPermission::permissionKeyToModule((string) $permissionKey))
            ->filter()
            ->unique()
            ->values();
    }

    private static function resolvePermissions(Collection $roleRows, bool $isAdmin): array
    {
        if ($isAdmin) {
            $permissions = collect(self::DEFAULT_MODULES)
                ->mapWithKeys(fn ($module): array => [$module => self::DEFAULT_ACTIONS])
                ->all();

            foreach (ErpPermission::allPermissionKeys() as $permissionKey) {
                $permissions[$permissionKey] = self::DEFAULT_ACTIONS;
                $parentModule = ErpPermission::permissionKeyToModule($permissionKey);
                if ($parentModule !== null) {
                    $permissions[$parentModule] = self::DEFAULT_ACTIONS;
                }
            }

            return $permissions;
        }

        $permissions = [];
        foreach ($roleRows as $row) {
            $action = ErpPermission::normalizeAction($row->accion ?? null);
            if ($action === null) {
                continue;
            }

            $permissionKeys = ErpPermission::expandPermissionKeys($row->modulo ?? null);
            foreach ($permissionKeys as $permissionKey) {
                $permissions[$permissionKey] ??= [];
                $permissions[$permissionKey][] = $action;

                $parentModule = ErpPermission::permissionKeyToModule($permissionKey);
                if ($parentModule !== null) {
                    $permissions[$parentModule] ??= [];
                    $permissions[$parentModule][] = $action;
                }
            }
        }

        return collect($permissions)
            ->map(fn ($actions): array => collect($actions)->unique()->values()->all())
            ->all();
    }
}
