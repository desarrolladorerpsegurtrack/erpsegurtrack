<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Export\ExportableList;
use App\Services\UsuarioService;
use App\Support\RolePermissionMatrix;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UsuariosController extends Controller
{
    use ExportableList;

    private UsuarioService $usuarioService;

    public function __construct(UsuarioService $usuarioService)
    {
        $this->usuarioService = $usuarioService;
    }

    public function index(Request $request): View
    {
        $usuarios = $this->usuarioService->getUserList($request, $this->resolvePerPage($request));
        $stats = $this->usuarioService->getUserStats($request);
        $roles = $this->usuarioService->getRolesCatalog();

        return view('usuario.usuarios', [
            'title' => 'Módulo Usuarios',
            'singularTitle' => 'Usuario',
            'items' => $usuarios,
            'columns' => [
                ['key' => 'usuario', 'label' => 'Usuario', 'type' => 'text'],
                ['key' => 'nombre_completo', 'label' => 'Nombre', 'type' => 'text'],
                ['key' => 'personal_dniPersonal', 'label' => 'DNI', 'type' => 'text'],
                ['key' => 'roles_text', 'label' => 'Roles', 'type' => 'text'],
                ['key' => 'estado', 'label' => 'Estado', 'type' => 'status', 'value' => fn ($row) => $row->estado === '1' ? 'Activo' : 'Inactivo'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.usuarios.export', ['format' => 'pdf']),
                'xlsx' => route('modules.usuarios.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de usuarios', 'value' => $stats['total']],
                ['label' => 'Usuarios Activos', 'value' => $stats['active']],
                ['label' => 'Usuarios Inactivos', 'value' => $stats['inactive']],
            ],
            'filters' => [
                [
                    'name' => 'nombre',
                    'label' => 'Usuario/Nombre',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por usuario o nombre',
                ],
                [
                    'name' => 'estado',
                    'label' => 'Estado',
                    'options' => [
                        ['value' => '1', 'label' => 'Activo'],
                        ['value' => '0', 'label' => 'Inactivo'],
                    ],
                ],
                [
                    'name' => 'rol',
                    'label' => 'Rol',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por rol',
                ],
            ],
            'createRoute' => route('modules.usuarios.create'),
            'editRoute' => 'modules.usuarios.edit',
            'showRoute' => 'modules.usuarios.edit',
            'destroyRoute' => 'modules.usuarios.destroy',
            'identifierKey' => 'usuario',
            'lockResource' => 'usuarios',
        ]);
    }

    public function export(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $rows = $this->usuarioService->getUserExportRows($request);
        $columns = $this->usuarioService->getExportColumns();
        $filename = 'usuarios_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Usuarios', $filename);
    }

    public function create(): View
    {
        // Excluir personales que ya estén asignados a un usuario
        $personales = $this->usuarioService->getPersonalesForCreate();

        $roles = $this->usuarioService->getRolesCatalog()->map(function ($rol) {
            $rol->nombre = $rol->label;
            $rol->optionDescription = $rol->submodules_summary;
            $rol->permissionMatrix = $rol->permission_matrix ?? RolePermissionMatrix::defaultMatrix();
            return $rol;
        });

        $selectedRoleId = $this->usuarioService->resolveSelectedRoleId(old('role_ids', []));
        if ($selectedRoleId !== null && !$roles->contains(fn ($role) => (int) $role->idrol === $selectedRoleId)) {
            $selectedRoleId = null;
        }

        $manualPermissionsInput = old('permissions');
        $manualPermissionsMatrix = is_array($manualPermissionsInput)
            ? RolePermissionMatrix::matrixFromStoredPermissions(RolePermissionMatrix::extractSelectedPermissions($manualPermissionsInput))
            : RolePermissionMatrix::defaultMatrix();

        $selectedRole = $selectedRoleId !== null
            ? $roles->first(fn ($role) => (int) $role->idrol === $selectedRoleId)
            : null;

        $displayPermissionsMatrix = $selectedRole !== null && is_array($selectedRole->permissionMatrix ?? null)
            ? $selectedRole->permissionMatrix
            : $manualPermissionsMatrix;

        return view('usuario.usuarios-form', [
            'title' => 'Nuevo Usuario',
            'moduleTitle' => 'Módulo Usuarios',
            'mode' => 'create',
            'formAction' => route('modules.usuarios.store'),
            'backRoute' => route('modules.usuarios'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'usuario',
                    'type' => 'text',
                    'label' => 'Usuario',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'placeholder' => 'Ej: user.name',
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'personal_dniPersonal',
                    'type' => 'select',
                    'label' => 'Personal',
                    'required' => true,
                    'tomSelect' => true,
                    'optionsData' => $personales,
                    'optionKey' => 'dniPersonal',
                    'optionLabel' => 'dniPersonal',
                    'placeholder' => 'Selecciona personal',
                ],
                [
                    'name' => 'clave',
                    'type' => 'password',
                    'label' => 'Contraseña',
                    'required' => true,
                    'minlength' => 8,
                    'maxlength' => 500,
                    'helpText' => 'Mínimo 8 caracteres.',
                ],
                [
                    'name' => 'estado',
                    'type' => 'select',
                    'label' => 'Estado',
                    'required' => false,
                    'options' => ['1' => 'Activo', '0' => 'Inactivo'],
                ],
                [
                    'name' => 'role_ids',
                    'type' => 'checkbox-object',
                    'label' => 'Roles asignados',
                    'optionsData' => $roles,
                    'optionKey' => 'idrol',
                    'optionLabel' => 'nombre',
                    'checkboxGrid' => 'role-cards-grid',
                    'singleSelection' => true,
                    'value' => $selectedRoleId !== null ? [$selectedRoleId] : [],
                    'colSpan' => 2,
                ],
                [
                    'name' => 'permissions',
                    'type' => 'permissions-matrix',
                    'label' => 'Permisos del rol',
                    'required' => true,
                    'modules' => RolePermissionMatrix::modules(),
                    'actions' => RolePermissionMatrix::actions(),
                    'value' => $displayPermissionsMatrix,
                    'manualFallbackValue' => $manualPermissionsMatrix,
                    'roleAware' => true,
                    'lockedByRole' => $selectedRoleId !== null,
                    'colSpan' => 2,
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'usuario' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:usuario,usuario'],
            'personal_dniPersonal' => [
                'required',
                'string',
                'max:20',
                'regex:/^[0-9]+$/',
                'exists:personal,dniPersonal',
                'unique:usuario,personal_dniPersonal',
            ],
            'clave' => ['required', 'string', 'min:6', 'max:500'],
            'estado' => ['nullable', Rule::in(['0', '1'])],
            'role_ids' => ['nullable', 'array', 'max:1'],
            'role_ids.*' => ['integer', Rule::exists('rol', 'idrol')->where(fn ($query) => $query->where('tipo', 1))],
            'permissions' => ['nullable', 'array'],
        ], [
            'usuario.unique' => 'El nombre de usuario ya está en uso.',
            'personal_dniPersonal.unique' => 'Este personal ya tiene una cuenta de usuario asociada.',
            'personal_dniPersonal.exists' => 'El personal seleccionado no está registrado.',
        ]);

        $selectedRoleId = $this->resolveSelectedRoleId($validated['role_ids'] ?? []);
        $permissionPairs = RolePermissionMatrix::extractSelectedPermissions((array) ($request->input('permissions') ?? []));

        if ($selectedRoleId === null) {
            if ($permissionPairs === []) {
                return back()
                    ->withErrors(['permissions' => 'Debes seleccionar al menos un permiso cuando no hay un rol precreado asignado.'])
                    ->withInput();
            }

            $dependencyError = RolePermissionMatrix::validateDependencies($permissionPairs);
            if ($dependencyError !== null) {
                return back()
                    ->withErrors(['permissions' => $dependencyError])
                    ->withInput();
            }
        }

        $this->usuarioService->createUser($validated, $selectedRoleId, $permissionPairs);

        $this->publishResourceEvent('usuarios', $validated['usuario'], 'created');
        $this->publishUserPermissionsChanged($validated['usuario'], ['source' => 'user.create']);

        return redirect()
            ->route('modules.usuarios')
            ->with('success', 'Usuario creado correctamente.');
    }

    public function edit(string $usuario): View|RedirectResponse
    {
        $record = $this->usuarioService->getUserByUsuario($usuario);

        if (!$record) {
            return redirect()
                ->route('modules.usuarios')
                ->with('error', 'No se encontro el usuario solicitado.');
        }

        $personales = DB::table('personal')
            ->select('dniPersonal', 'nombre', 'apellido')
            ->orderBy('apellido')
            ->orderBy('nombre')
            ->get();

        $roles = $this->getRolesCatalog()->map(function ($rol) {
            $rol->nombre = $rol->label;
            $rol->optionDescription = $rol->submodules_summary;
            $rol->permissionMatrix = $rol->permission_matrix ?? RolePermissionMatrix::defaultMatrix();
            return $rol;
        });

        $assignedRoleRows = $this->usuarioService->getAssignedRoles($usuario);
        $assignedPublicRoleId = $assignedRoleRows
            ->first(fn ($role) => (int) ($role->tipo ?? 1) === 1)?->idrol;
        $assignedInternalRoleId = $assignedRoleRows
            ->first(fn ($role) => (int) ($role->tipo ?? 1) === 0)?->idrol;

        $assignedRoleIds = $assignedRoleRows->pluck('idrol')->map(fn ($id) => (int) $id)->filter()->values();
        $storedPermissionsByRole = $assignedRoleIds->isNotEmpty()
            ? $this->usuarioService->getStoredPermissionsForRoleIds($assignedRoleIds)
            : collect();

        $defaultManualMatrix = $assignedInternalRoleId !== null
            ? RolePermissionMatrix::matrixFromStoredPermissions($storedPermissionsByRole->get((int) $assignedInternalRoleId, collect()))
            : RolePermissionMatrix::defaultMatrix();

        $selectedRoleId = $this->usuarioService->resolveSelectedRoleId(old('role_ids', $assignedPublicRoleId !== null ? [$assignedPublicRoleId] : []));
        if ($selectedRoleId !== null && !$roles->contains(fn ($role) => (int) $role->idrol === $selectedRoleId)) {
            $selectedRoleId = null;
        }

        $manualPermissionsInput = old('permissions');
        $manualPermissionsMatrix = is_array($manualPermissionsInput)
            ? RolePermissionMatrix::matrixFromStoredPermissions(RolePermissionMatrix::extractSelectedPermissions($manualPermissionsInput))
            : $defaultManualMatrix;

        $selectedRole = $selectedRoleId !== null
            ? $roles->first(fn ($role) => (int) $role->idrol === $selectedRoleId)
            : null;

        $displayPermissionsMatrix = $selectedRole !== null && is_array($selectedRole->permissionMatrix ?? null)
            ? $selectedRole->permissionMatrix
            : $manualPermissionsMatrix;

        return view('usuario.usuarios-form', [
            'title' => 'Editar Usuario',
            'moduleTitle' => 'Módulo Usuarios',
            'mode' => 'edit',
            'formAction' => route('modules.usuarios.update', $usuario),
            'backRoute' => route('modules.usuarios'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'usuario',
                    'type' => 'text',
                    'label' => 'Usuario',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'personal_dniPersonal',
                    'type' => 'select',
                    'label' => 'Personal',
                    'required' => true,
                    'tomSelect' => true,
                    'optionsData' => $personales,
                    'optionKey' => 'dniPersonal',
                    'optionLabel' => 'dniPersonal',
                    'placeholder' => 'Selecciona personal',
                ],
                [
                    'name' => 'clave',
                    'type' => 'password',
                    'label' => 'Contraseña (dejar vacío para no cambiar)',
                    'required' => false,
                    'minlength' => 8,
                    'maxlength' => 500,
                    'helpText' => 'Mínimo 8 caracteres si se ingresa.',
                ],
                [
                    'name' => 'estado',
                    'type' => 'select',
                    'label' => 'Estado',
                    'required' => false,
                    'options' => ['1' => 'Activo', '0' => 'Inactivo'],
                ],
                [
                    'name' => 'role_ids',
                    'type' => 'checkbox-object',
                    'label' => 'Roles asignados',
                    'optionsData' => $roles,
                    'optionKey' => 'idrol',
                    'optionLabel' => 'nombre',
                    'checkboxGrid' => 'role-cards-grid',
                    'singleSelection' => true,
                    'value' => $selectedRoleId !== null ? [$selectedRoleId] : [],
                    'colSpan' => 2,
                ],
                [
                    'name' => 'permissions',
                    'type' => 'permissions-matrix',
                    'label' => 'Permisos del rol',
                    'required' => true,
                    'modules' => RolePermissionMatrix::modules(),
                    'actions' => RolePermissionMatrix::actions(),
                    'value' => $displayPermissionsMatrix,
                    'manualFallbackValue' => $manualPermissionsMatrix,
                    'roleAware' => true,
                    'lockedByRole' => $selectedRoleId !== null,
                    'colSpan' => 2,
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('usuarios', $usuario));
    }

    

    public function update(Request $request, string $usuario): RedirectResponse
    {
        $exists = DB::table('usuario')->where('usuario', $usuario)->exists();

        if (!$exists) {
            return redirect()
                ->route('modules.usuarios')
                ->with('error', 'No se encontro el usuario solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'usuarios', $usuario, 'usuario', 'modules.usuarios')) {
            return $redirect;
        }

        $validated = $request->validate([
            'usuario' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('usuario', 'usuario')->ignore($usuario, 'usuario')],
            'personal_dniPersonal' => [
                'required',
                'string',
                'max:20',
                'regex:/^[0-9]+$/',
                'exists:personal,dniPersonal',
                Rule::unique('usuario', 'personal_dniPersonal')->ignore($usuario, 'usuario'),
            ],
            'clave' => ['nullable', 'string', 'min:8', 'max:500'],
            'estado' => ['nullable', Rule::in(['0', '1'])],
            'role_ids' => ['nullable', 'array', 'max:1'],
            'role_ids.*' => ['integer', Rule::exists('rol', 'idrol')->where(fn ($query) => $query->where('tipo', 1))],
            'permissions' => ['nullable', 'array'],
        ], [
            'usuario.unique' => 'El nombre de usuario ya está en uso.',
            'personal_dniPersonal.unique' => 'Este personal ya tiene una cuenta de usuario asociada.',
            'personal_dniPersonal.exists' => 'El personal seleccionado no está registrado.',
        ]);

        $selectedRoleId = $this->resolveSelectedRoleId($validated['role_ids'] ?? []);
        $permissionPairs = RolePermissionMatrix::extractSelectedPermissions((array) ($request->input('permissions') ?? []));

        if ($selectedRoleId === null) {
            if ($permissionPairs === []) {
                return back()
                    ->withErrors(['permissions' => 'Debes seleccionar al menos un permiso cuando no hay un rol precreado asignado.'])
                    ->withInput();
            }

            $dependencyError = RolePermissionMatrix::validateDependencies($permissionPairs);
            if ($dependencyError !== null) {
                return back()
                    ->withErrors(['permissions' => $dependencyError])
                    ->withInput();
            }
        }

        $result = $this->usuarioService->updateUser($usuario, $validated, $selectedRoleId, $permissionPairs);

        if ($selectedRoleId !== null && $result['oldInternalRoleId'] !== null) {
            $this->usuarioService->cleanupInternalRoleIfOrphan((int) $result['oldInternalRoleId']);
        }

        $newUsuario = $result['newUsuario'];

        $this->publishResourceEvent('usuarios', $newUsuario, 'updated');
        $this->publishUserPermissionsChanged($newUsuario, ['source' => 'user.update']);

        if ($newUsuario !== $usuario) {
            $this->publishUserPermissionsChanged($usuario, ['source' => 'user.rename']);
        }

        $this->releaseLockIfOwned($request, 'usuarios', $usuario);

        return redirect()
            ->route('modules.usuarios')
            ->with('success', 'Usuario actualizado correctamente.');
    }

    public function destroy(Request $request, string $usuario): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'usuarios', $usuario, 'usuario', 'modules.usuarios')) {
            return $redirect;
        }

        try {
            $internalRoleIds = $this->usuarioService->destroyUser($usuario);

            foreach ($internalRoleIds as $roleId) {
                $this->usuarioService->cleanupInternalRoleIfOrphan((int) $roleId);
            }

            $this->publishResourceEvent('usuarios', $usuario, 'deleted');
            $this->publishUserPermissionsChanged($usuario, ['source' => 'user.delete']);
            $this->releaseLockIfOwned($request, 'usuarios', $usuario);

            return redirect()
                ->route('modules.usuarios')
                ->with('success', 'Usuario eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.usuarios')
                ->with('error', 'No se puede eliminar el usuario porque tiene registros relacionados.');
        }
    }

    private function getRolesCatalog()
    {
        $submodulesSubquery = DB::table('inforol as i')
            ->select('i.rol_idrol', DB::raw("GROUP_CONCAT(DISTINCT i.modulo ORDER BY i.modulo SEPARATOR ', ') as submodulos"))
            ->groupBy('i.rol_idrol');

        return DB::table('rol as r')
            ->leftJoinSub($submodulesSubquery, 'isub', function ($join) {
                $join->on('r.idrol', '=', 'isub.rol_idrol');
            })
            ->select('r.idrol', 'r.nombre', DB::raw("COALESCE(isub.submodulos, 'Sin submodulos') as submodulos"))
            ->where('r.tipo', 1)
            ->orderBy('r.nombre')
            ->get()
            ->map(function ($role) {
                $role->label = $role->nombre;
                $role->submodules_summary = $this->formatRoleSubmodules($role->submodulos);
                return $role;
            })
            ->pipe(function ($roles) {
                $roleIds = $roles->pluck('idrol')->map(fn ($id) => (int) $id)->filter()->values();
                if ($roleIds->isEmpty()) {
                    return $roles;
                }

                $permissionsByRole = DB::table('inforol')
                    ->whereIn('rol_idrol', $roleIds)
                    ->select('rol_idrol', 'modulo', 'accion')
                    ->get()
                    ->groupBy('rol_idrol');

                return $roles->map(function ($role) use ($permissionsByRole) {
                    $role->permission_matrix = RolePermissionMatrix::matrixFromStoredPermissions(
                        $permissionsByRole->get((int) $role->idrol, collect())
                    );
                    return $role;
                });
            });
    }

    private function resolveSelectedRoleId($roleInput): ?int
    {
        $roleIds = is_array($roleInput) ? $roleInput : [$roleInput];

        $selected = collect($roleIds)
            ->map(function ($id) {
                if ($id === null || $id === '') {
                    return null;
                }

                return (int) $id;
            })
            ->filter(fn ($id) => $id !== null && $id > 0)
            ->unique()
            ->values()
            ->first();

        return $selected !== null ? (int) $selected : null;
    }

    private function getAssignedRoles(string $usuario)
    {
        return DB::table('detallerol as dr')
            ->join('rol as r', 'dr.rol_idrol', '=', 'r.idrol')
            ->where('dr.usuario_usuario', $usuario)
            ->select('r.idrol', 'r.tipo')
            ->get();
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

    private function cleanupInternalRoleIfOrphan(int $roleId): void
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
