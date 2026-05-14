<?php

namespace App\Services;

use App\Support\RolePermissionMatrix;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UsuarioService
{
    public function getUserList(Request $request, int $perPage): LengthAwarePaginator
    {
        $query = $this->applyFilters($this->buildBaseQuery(), $this->extractFilters($request));

        $usuarios = $query
            ->paginate($perPage)
            ->withQueryString();

        $usernames = $usuarios->getCollection()->pluck('usuario')->filter()->values();
        $rolesByUser = collect();
        if ($usernames->isNotEmpty()) {
            $rolesByUser = $this->getRolesByUsernames($usernames);
        }

        $usuarios->setCollection(
            $usuarios->getCollection()->map(fn ($usuario) => $this->hydrateUserRow($usuario, $rolesByUser))
        );

        return $usuarios;
    }

    public function getUserStats(Request $request): array
    {
        $query = $this->applyFilters($this->buildBaseQuery(), $this->extractFilters($request));

        $totalUsuarios = (clone $query)->count();
        $activosUsuarios = (clone $query)->where('u.estado', '1')->count();

        return [
            'total' => $totalUsuarios,
            'active' => $activosUsuarios,
            'inactive' => max($totalUsuarios - $activosUsuarios, 0),
        ];
    }

    public function getUserExportRows(Request $request): Collection
    {
        $rows = $this->applyFilters($this->buildBaseQuery(), $this->extractFilters($request))
            ->orderBy('u.usuario')
            ->get();

        return $rows->map(fn ($usuario) => $this->hydrateExportUserRow($usuario));
    }

    public function getExportColumns(): array
    {
        return [
            ['key' => 'usuario', 'label' => 'Usuario'],
            ['key' => 'nombre_completo', 'label' => 'Nombre'],
            ['key' => 'personal_dniPersonal', 'label' => 'DNI'],
            ['key' => 'roles_text', 'label' => 'Roles'],
            ['key' => 'estado', 'label' => 'Estado', 'value' => fn ($row) => $row->estado === '1' ? 'Activo' : 'Inactivo'],
        ];
    }

    public function getRolesCatalog(): Collection
    {
        $submodulesSubquery = DB::table('inforol as i')
            ->select('i.rol_idrol', DB::raw("GROUP_CONCAT(DISTINCT i.modulo ORDER BY i.modulo SEPARATOR ', ') as submodulos"))
            ->groupBy('i.rol_idrol');

        $roles = DB::table('rol as r')
            ->leftJoinSub($submodulesSubquery, 'isub', function ($join) {
                $join->on('r.idrol', '=', 'isub.rol_idrol');
            })
            ->select('r.idrol', 'r.nombre', DB::raw("COALESCE(isub.submodulos, 'Sin submodulos') as submodulos"))
            ->where('r.tipo', 1)
            ->orderBy('r.nombre')
            ->get();

        $roleIds = $roles->pluck('idrol')->map(fn ($id) => (int) $id)->filter()->values();
        $permissionsByRole = collect();
        if ($roleIds->isNotEmpty()) {
            $permissionsByRole = DB::table('inforol')
                ->whereIn('rol_idrol', $roleIds)
                ->select('rol_idrol', 'modulo', 'accion')
                ->get()
                ->groupBy('rol_idrol');
        }

        return $roles->map(function ($role) use ($permissionsByRole) {
            $role->label = $role->nombre;
            $role->submodules_summary = $this->formatRoleSubmodules($role->submodulos);
            $role->permission_matrix = RolePermissionMatrix::matrixFromStoredPermissions(
                $permissionsByRole->get((int) $role->idrol, collect())
            );
            return $role;
        });
    }

    public function getPersonalesForCreate(): Collection
    {
        $assignedPersonalDnis = DB::table('usuario')
            ->whereNotNull('personal_dniPersonal')
            ->pluck('personal_dniPersonal')
            ->filter()
            ->all();

        return DB::table('personal')
            ->select('dniPersonal', 'nombre', 'apellido')
            ->when(count($assignedPersonalDnis) > 0, function ($query) use ($assignedPersonalDnis) {
                $query->whereNotIn('dniPersonal', $assignedPersonalDnis);
            })
            ->orderBy('apellido')
            ->orderBy('nombre')
            ->get();
    }

    public function createUser(array $validated, ?int $selectedRoleId, array $permissionPairs): void
    {
        DB::transaction(function () use ($validated, $selectedRoleId, $permissionPairs) {
            DB::table('usuario')->insert([
                'usuario' => $validated['usuario'],
                'personal_dniPersonal' => $validated['personal_dniPersonal'],
                'clave' => Hash::make($validated['clave']),
                'estado' => $validated['estado'] ?? '1',
            ]);

            if ($selectedRoleId !== null) {
                DB::table('detallerol')->insert([
                    'usuario_usuario' => $validated['usuario'],
                    'rol_idrol' => $selectedRoleId,
                ]);
                return;
            }

            $internalRoleId = $this->createInternalRoleWithPermissions(
                $validated['usuario'],
                (string) ($validated['estado'] ?? '1'),
                $permissionPairs
            );

            DB::table('detallerol')->insert([
                'usuario_usuario' => $validated['usuario'],
                'rol_idrol' => $internalRoleId,
            ]);
        });
    }

    public function getUserByUsuario(string $usuario)
    {
        return DB::table('usuario')->where('usuario', $usuario)->first();
    }

    public function getAssignedRoles(string $usuario): Collection
    {
        return DB::table('detallerol as dr')
            ->join('rol as r', 'dr.rol_idrol', '=', 'r.idrol')
            ->where('dr.usuario_usuario', $usuario)
            ->select('r.idrol', 'r.tipo')
            ->get();
    }

    public function getStoredPermissionsForRoleIds(Collection $roleIds): Collection
    {
        return DB::table('inforol')
            ->whereIn('rol_idrol', $roleIds->all())
            ->select('rol_idrol', 'modulo', 'accion')
            ->get()
            ->groupBy('rol_idrol');
    }

    public function resolveSelectedRoleId($roleInput): ?int
    {
        $roleIds = is_array($roleInput) ? $roleInput : [$roleInput];

        $selected = collect($roleIds)
            ->map(fn ($id) => is_numeric($id) ? (int) $id : null)
            ->filter(fn ($id) => $id !== null && $id > 0)
            ->unique()
            ->values()
            ->first();

        return $selected !== null ? (int) $selected : null;
    }

    public function updateUser(string $usuario, array $validated, ?int $selectedRoleId, array $permissionPairs): array
    {
        $result = ['newUsuario' => $usuario, 'oldInternalRoleId' => null];

        DB::transaction(function () use ($usuario, $validated, $selectedRoleId, $permissionPairs, &$result) {
            $assignedRoles = $this->getAssignedRoles($usuario);
            $result['oldInternalRoleId'] = $assignedRoles
                ->first(fn ($role) => (int) ($role->tipo ?? 1) === 0)?->idrol;

            $newUsuario = $validated['usuario'];
            $payload = [
                'usuario' => $newUsuario,
                'personal_dniPersonal' => $validated['personal_dniPersonal'],
                'estado' => $validated['estado'] ?? '1',
            ];

            if (!empty($validated['clave'])) {
                $payload['clave'] = Hash::make($validated['clave']);
            }

            DB::table('usuario')->where('usuario', $usuario)->update($payload);
            DB::table('detallerol')->where('usuario_usuario', $usuario)->delete();

            if ($selectedRoleId !== null) {
                DB::table('detallerol')->insert([
                    'usuario_usuario' => $newUsuario,
                    'rol_idrol' => $selectedRoleId,
                ]);
                $result['newUsuario'] = $newUsuario;
                return;
            }

            if ($result['oldInternalRoleId'] !== null) {
                DB::table('rol')
                    ->where('idrol', (int) $result['oldInternalRoleId'])
                    ->where('tipo', 0)
                    ->update([
                        'nombre' => $this->internalRoleNameForUser($newUsuario),
                        'estado' => $validated['estado'] ?? '1',
                    ]);

                DB::table('inforol')->where('rol_idrol', (int) $result['oldInternalRoleId'])->delete();
                DB::table('inforol')->insert(RolePermissionMatrix::buildInforolRows((int) $result['oldInternalRoleId'], $permissionPairs));
                DB::table('detallerol')->insert([
                    'usuario_usuario' => $newUsuario,
                    'rol_idrol' => (int) $result['oldInternalRoleId'],
                ]);
                $result['newUsuario'] = $newUsuario;
                return;
            }

            $internalRoleId = $this->createInternalRoleWithPermissions(
                $newUsuario,
                (string) ($validated['estado'] ?? '1'),
                $permissionPairs
            );

            DB::table('detallerol')->insert([
                'usuario_usuario' => $newUsuario,
                'rol_idrol' => $internalRoleId,
            ]);
            $result['newUsuario'] = $newUsuario;
        });

        return $result;
    }

    public function destroyUser(string $usuario): Collection
    {
        $internalRoleIds = $this->getAssignedRoles($usuario)
            ->filter(fn ($role) => (int) ($role->tipo ?? 1) === 0)
            ->pluck('idrol')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        DB::transaction(function () use ($usuario) {
            DB::table('detallerol')->where('usuario_usuario', $usuario)->delete();
            DB::table('usuario')->where('usuario', $usuario)->delete();
        });

        return $internalRoleIds;
    }

    public function cleanupInternalRoleIfOrphan(int $roleId): void
    {
        if ($roleId <= 0) {
            return;
        }

        $stillAssigned = DB::table('detallerol')->where('rol_idrol', $roleId)->exists();
        if ($stillAssigned) {
            return;
        }

        DB::table('inforol')->where('rol_idrol', $roleId)->delete();
        DB::table('rol')->where('idrol', $roleId)->where('tipo', 0)->delete();
    }

    private function buildBaseQuery(): Builder
    {
        return DB::table('usuario as u')
            ->leftJoin('personal as p', 'u.personal_dniPersonal', '=', 'p.dniPersonal')
            ->select('u.usuario', 'u.personal_dniPersonal', 'u.estado', 'p.nombre', 'p.apellido')
            ->orderBy('u.usuario');
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        if ($filters['search'] !== '') {
            $query->where(function (Builder $query) use ($filters) {
                $term = '%' . $filters['search'] . '%';
                $query
                    ->where('u.usuario', 'like', $term)
                    ->orWhere('u.personal_dniPersonal', 'like', $term)
                    ->orWhere('p.nombre', 'like', $term)
                    ->orWhere('p.apellido', 'like', $term);
            });
        }

        if ($filters['estado'] !== '') {
            $query->where('u.estado', $filters['estado']);
        }

        if ($filters['rol'] !== '') {
            $query->whereExists(function ($query) use ($filters) {
                $query
                    ->select(DB::raw(1))
                    ->from('detallerol as dr')
                    ->join('rol as r', 'dr.rol_idrol', '=', 'r.idrol')
                    ->whereColumn('dr.usuario_usuario', 'u.usuario')
                    ->where('r.nombre', 'like', '%' . $filters['rol'] . '%');
            });
        }

        if ($filters['nombre'] !== '') {
            $query->where(function (Builder $query) use ($filters) {
                $term = '%' . $filters['nombre'] . '%';
                $query
                    ->where('u.usuario', 'like', $term)
                    ->orWhere('p.nombre', 'like', $term)
                    ->orWhere('p.apellido', 'like', $term);
            });
        }

        return $query;
    }

    private function extractFilters(Request $request): array
    {
        return [
            'search' => trim((string) $request->input('q', '')),
            'estado' => trim((string) $request->input('estado', '')),
            'rol' => trim((string) $request->input('rol', '')),
            'nombre' => trim((string) $request->input('nombre', '')),
        ];
    }

    private function hydrateUserRow($usuario, Collection $rolesByUser)
    {
        $roles = $rolesByUser[$usuario->usuario] ?? collect();
        $usuario->roles_text = $roles
            ->map(function ($r) {
                $roleName = $this->normalizeInternalRoleDisplayName((string) ($r->nombre ?? ''));
                $modules = $this->formatRoleModulesShort((string) ($r->submodulos ?? ''));

                return $modules !== ''
                    ? "{$roleName} ({$modules})"
                    : "{$roleName}";
            })
            ->implode(', ') ?: 'Sin roles';
        $usuario->nombre_completo = trim(($usuario->nombre ?? '') . ' ' . ($usuario->apellido ?? ''));
        return $usuario;
    }

    private function hydrateExportUserRow($usuario)
    {
        $roles = DB::table('detallerol as dr')
            ->join('rol as r', 'dr.rol_idrol', '=', 'r.idrol')
            ->leftJoin('inforol as ir', 'r.idrol', '=', 'ir.rol_idrol')
            ->select('r.nombre', DB::raw("GROUP_CONCAT(DISTINCT ir.modulo ORDER BY ir.modulo SEPARATOR ', ') as submodulos"))
            ->where('dr.usuario_usuario', $usuario->usuario)
            ->groupBy('r.idrol', 'r.nombre')
            ->orderBy('r.nombre')
            ->get();

        $usuario->roles_text = $roles
            ->map(function ($r) {
                $roleName = $this->normalizeInternalRoleDisplayName((string) ($r->nombre ?? ''));
                $modules = $this->formatRoleModulesShort((string) ($r->submodulos ?? ''));

                return $modules !== ''
                    ? "{$roleName} ({$modules})"
                    : "{$roleName}";
            })
            ->implode(', ') ?: 'Sin roles';

        $usuario->nombre_completo = trim(($usuario->nombre ?? '') . ' ' . ($usuario->apellido ?? ''));
        return $usuario;
    }

    private function getRolesByUsernames(Collection $usernames): Collection
    {
        return DB::table('detallerol as dr')
            ->join('rol as r', 'dr.rol_idrol', '=', 'r.idrol')
            ->leftJoin('inforol as ir', 'r.idrol', '=', 'ir.rol_idrol')
            ->select(
                'dr.usuario_usuario',
                'r.idrol',
                'r.nombre',
                DB::raw("GROUP_CONCAT(DISTINCT ir.modulo ORDER BY ir.modulo SEPARATOR ', ') as submodulos")
            )
            ->whereIn('dr.usuario_usuario', $usernames)
            ->groupBy('dr.usuario_usuario', 'r.idrol', 'r.nombre')
            ->orderBy('r.nombre')
            ->get()
            ->groupBy('usuario_usuario');
    }

    private function createInternalRoleWithPermissions(string $usuario, string $estado, array $permissionPairs): int
    {
        $roleId = DB::table('rol')->insertGetId([
            'nombre' => $this->internalRoleNameForUser($usuario),
            'estado' => $estado,
            'tipo' => 0,
            'fechaCreacion' => now(),
        ]);

        DB::table('inforol')->insert(RolePermissionMatrix::buildInforolRows($roleId, $permissionPairs));

        return (int) $roleId;
    }

    private function internalRoleNameForUser(string $usuario): string
    {
        return mb_substr('rol_' . $usuario, 0, 50);
    }

    private function formatRoleSubmodules(string $submodules): string
    {
        $items = collect(explode(',', $submodules))
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->map(function ($item) {
                $parts = explode('.', $item, 2);
                return count($parts) === 2
                    ? ['module' => $parts[0], 'submodule' => $parts[1]]
                    : ['module' => $item, 'submodule' => ''];
            })
            ->groupBy('module')
            ->map(function ($group, $module) {
                $submodules = $group->pluck('submodule')->filter()->unique()->values();
                if ($submodules->isEmpty()) {
                    return $module;
                }
                return $module . ': ' . $submodules->implode(', ');
            })
            ->values();

        return $items->isEmpty() ? 'Sin submodulos' : $items->implode('. ');
    }

    private function formatRoleModulesShort(string $submodules, int $limit = 4): string
    {
        $modules = $this->extractRoleModuleNames($submodules);
        if (empty($modules)) {
            return '';
        }

        $visible = array_slice($modules, 0, $limit);
        $result = implode(', ', $visible);

        return count($modules) > $limit ? $result . ', ...' : $result;
    }

    private function normalizeInternalRoleDisplayName(string $roleName): string
    {
        $normalized = trim($roleName);
        if (str_starts_with($normalized, 'rol_interno_')) {
            return 'rol_' . substr($normalized, strlen('rol_interno_'));
        }

        return $normalized;
    }

    private function extractRoleModuleNames(string $submodules): array
    {
        return collect(explode(',', $submodules))
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->map(function ($item) {
                $parts = explode('.', $item, 2);
                return trim($parts[0]);
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
