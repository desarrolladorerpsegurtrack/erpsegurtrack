<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Export\ExportableList;
use App\Services\RolesService;
use App\Support\RolePermissionMatrix;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Illuminate\Support\Carbon;

class RolesController extends Controller
{
    use ExportableList;

    private RolesService $rolesService;

    public function __construct(RolesService $rolesService)
    {
        $this->rolesService = $rolesService;
    }

    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';
    private const PERMISSION_MODULES = [
        'personal' => 'Personal',
        'roles' => 'Roles',
        'usuarios' => 'Usuarios',
        'clientes' => [
            'label' => 'Clientes',
            'submodules' => [
                'clientes.cliente' => 'Cliente',
                'clientes.credenciales' => 'Credenciales',
                'clientes.grupo_cliente' => 'Grupo cliente',
                'servicio_cliente' => 'Servicio cliente',
                'vehiculos' => 'Vehículos',
                'dispositivo_cliente' => 'Dispositivo cliente',
            ],
        ],
        'almacen' => [
            'label' => 'Almacen',
            'submodules' => [
                'almacen' => 'Almacen',
                'almacen.planes_servicios' => 'Planes y servicios',
                'almacen.nota_ingreso' => 'Nota de ingreso',
                'almacen.nota_salida' => 'Nota de salida',
            ],
        ],
        'configuracion' => [
            'label' => 'Configuracion',
            'submodules' => [
                'configuracion.estado' => 'Estado cliente',
                'configuracion.tipo_contacto' => 'Tipo de contacto',
                'configuracion.ubigeo' => 'Ubigeo',
                'configuracion.cargo' => 'Cargo',
                'configuracion.auditoria' => 'Auditoria',
                'configuracion.moneda' => 'Moneda',
                'configuracion.tributo' => 'Tributo',
                'configuracion.unidad_medida' => 'Unidad de medida',
                'configuracion.marca' => 'Marca',
                'configuracion.tecnologia' => 'Tecnologia',
                'configuracion.tipo_gasto' => 'Tipo de gasto',
                'configuracion.tipo_cobro' => 'Tipo de cobro',
            ],
        ],
        'sistema' => [
            'label' => 'Sistema',
            'submodules' => [
                'sistema.vista' => 'Vista',
                'sistema.flujo' => 'Flujo',
                'sistema.flujoregla' => 'Flujo Regla',
                'sistema.historialflujo' => 'Historial Flujo',
            ],
        ],
        'lineas_chips' => [
            'label' => 'Lineas y Chips',
            'submodules' => [
                'lineas_chips.numero_telefonico' => 'Número telefónico',
                'lineas_chips.numero_dispositivo' => 'Número de dispositivo',
                'lineas_chips.simcard' => 'Plastico (SimCard)',
                'lineas_chips.detallesimcard' => 'Asignacion SimCard',
                'lineas_chips.cargar_numeros' => 'Cargar números',
                'lineas_chips.bajar_numeros' => 'Dar de Baja números',
            ],
        ],
    ];
    public function index(Request $request): View
    {
        $roles = $this->rolesService->getRoleList($request, $this->resolvePerPage($request));
        $stats = $this->rolesService->getRoleStats($request);

        $roles->through(function ($row) {
        if (isset($row->fechaCreacion)) {
            $row->fechaCreacion = Carbon::parse($row->fechaCreacion)
                ->locale('es')
                ->translatedFormat('d M Y, H:i'); 
        }
        return $row;
    });

        return view('role.roles', [
            'title' => 'Módulo Roles',
            'singularTitle' => 'Rol',
            'items' => $roles,
            'exportRoutes' => [
                'pdf' => route('modules.roles.export', ['format' => 'pdf']),
                'xlsx' => route('modules.roles.export', ['format' => 'xlsx']),
            ],
            'columns' => [
                ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text'],
                ['key' => 'fechaCreacion', 'label' => 'Fecha Creación', 'type' => 'text'],
                ['key' => 'estado', 'label' => 'Estado', 'type' => 'status'],
            ],
            'stats' => [
                ['label' => 'Total de Roles', 'value' => $stats['total']],
                ['label' => 'Roles activos', 'value' => $stats['active']],
                ['label' => 'Roles inactivos', 'value' => $stats['inactive']],
            ],
            'filters' => [
                [
                    'name' => 'nombre',
                    'label' => 'Nombre',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por nombre de rol',
                ],
                [
                    'name' => 'estado',
                    'label' => 'Estado de roles',
                    'options' => [
                        ['value' => '1', 'label' => 'Activo'],
                        ['value' => '0', 'label' => 'Inactivo'],
                    ],
                    'placeholder' => 'Todos los estados',
                ],
            ],
            'createRoute' => route('modules.roles.create'),
            'editRoute' => 'modules.roles.edit',
            'showRoute' => 'modules.roles.edit',
            'destroyRoute' => 'modules.roles.destroy',
            'identifierKey' => 'idrol',
            'lockResource' => 'roles',
        ]);
    }

    public function create(): View
    {
        $vistas = $this->rolesService->getVistasCatalog();
        $selectedVistaIds = $this->rolesService->extractSelectedVistaIds((array) old('vista_permissions', []));

        return view('role.roles-form', [
            'title' => 'Nuevo Rol',
            'moduleTitle' => 'Módulo Roles',
            'mode' => 'create',
            'formAction' => route('modules.roles.store'),
            'backRoute' => route('modules.roles'),
            'record' => null,
            'fields' => $this->buildRoleFields($this->defaultPermissionMatrix(), $vistas, $selectedVistaIds),
            'readOnly' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:15', 'regex:' . self::SAFE_TEXT_REGEX, Rule::unique('rol', 'nombre')->where(fn ($query) => $query->where('tipo', 1))],
            'estado' => ['required', Rule::in(['0', '1'])],
            'permissions' => ['nullable', 'array'],
            'vista_permissions' => ['nullable', 'array'],
            'vista_permissions.*' => ['integer', Rule::exists('vista', 'idvista')],
        ], [
            'nombre.unique' => 'Ya existe un rol con ese nombre.',
            'estado.required' => 'El estado es requerido.',
            'vista_permissions.*.exists' => 'Una de las vistas seleccionadas no existe.',
        ]);

        $permissionPairs = $this->rolesService->extractSelectedPermissions((array) ($request->input('permissions') ?? []));
        $vistaIds = $this->rolesService->extractSelectedVistaIds((array) ($request->input('vista_permissions') ?? []));

        if ($permissionPairs === [] && $vistaIds === []) {
            return back()
                ->withErrors(['permissions' => 'Debes seleccionar al menos un permiso o una vista permitida.'])
                ->withInput();
        }

        $dependencyError = $this->rolesService->validateRolePermissionDependencies($permissionPairs);
        if ($dependencyError !== null) {
            return back()
                ->withErrors(['permissions' => $dependencyError])
                ->withInput();
        }

        $roleId = $this->rolesService->createRole($validated, $permissionPairs, $vistaIds);

        $this->publishResourceEvent('roles', (string) $roleId, 'created');

        return redirect()
            ->route('modules.roles')
            ->with('success', 'Rol creado correctamente.');
    }

    public function edit(int $id): View|RedirectResponse
    {
        $role = $this->rolesService->getRoleById($id);

        if (!$role) {
            return redirect()
                ->route('modules.roles')
                ->with('error', 'No se encontro el rol solicitado.');
        }

        $storedPermissions = $this->rolesService->getStoredPermissionsByRoleId($id);
        $storedVistaIds = $this->rolesService->getStoredVistaIdsByRoleId($id);
        $selectedVistaIds = $this->rolesService->extractSelectedVistaIds((array) old('vista_permissions', $storedVistaIds));

        return view('role.roles-form', [
            'title' => 'Editar Rol',
            'moduleTitle' => 'Módulo Roles',
            'mode' => 'edit',
            'formAction' => route('modules.roles.update', $id),
            'backRoute' => route('modules.roles'),
            'record' => $role,
            'fields' => $this->rolesService->buildRoleFields(
                $this->rolesService->matrixFromStoredPermissions($storedPermissions),
                $this->rolesService->getVistasCatalog(),
                $selectedVistaIds
            ),
            'readOnly' => true,
        ] + $this->prepareLockViewData('roles', (string) $id));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('rol')->where('idrol', $id)->where('tipo', 1)->exists();

        if (!$exists) {
            return redirect()
                ->route('modules.roles')
                ->with('error', 'No se encontro el rol solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'roles', (string) $id, 'rol', 'modules.roles')) {
            return $redirect;
        }

        $validated = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:15', 'regex:' . self::SAFE_TEXT_REGEX, Rule::unique('rol', 'nombre')->ignore($id, 'idrol')->where(fn ($query) => $query->where('tipo', 1))],
            'estado' => ['required', Rule::in(['0', '1'])],
            'permissions' => ['nullable', 'array'],
            'vista_permissions' => ['nullable', 'array'],
            'vista_permissions.*' => ['integer', Rule::exists('vista', 'idvista')],
        ], [
            'nombre.unique' => 'Ya existe un rol con ese nombre.',
            'estado.required' => 'El estado es requerido.',
            'vista_permissions.*.exists' => 'Una de las vistas seleccionadas no existe.',
        ]);

        $permissionPairs = $this->rolesService->extractSelectedPermissions((array) ($request->input('permissions') ?? []));
        $vistaIds = $this->rolesService->extractSelectedVistaIds((array) ($request->input('vista_permissions') ?? []));

        if ($permissionPairs === [] && $vistaIds === []) {
            return back()
                ->withErrors(['permissions' => 'Debes seleccionar al menos un permiso o una vista permitida.'])
                ->withInput();
        }

        $dependencyError = $this->rolesService->validateRolePermissionDependencies($permissionPairs);
        if ($dependencyError !== null) {
            return back()
                ->withErrors(['permissions' => $dependencyError])
                ->withInput();
        }

        $this->rolesService->updateRole($id, $validated, $permissionPairs, $vistaIds);

        $this->publishResourceEvent('roles', (string) $id, 'updated');
        $this->publishUsersAffectedByRole($id, ['source' => 'role.update']);
        $this->releaseLockIfOwned($request, 'roles', (string) $id);

        return redirect()
            ->route('modules.roles')
            ->with('success', 'Rol actualizado correctamente.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('rol')->where('idrol', $id)->where('tipo', 1)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.roles')
                ->with('error', 'No se encontro el rol solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'roles', (string) $id, 'rol', 'modules.roles')) {
            return $redirect;
        }

        try {
            $affectedUsers = $this->rolesService->deleteRole($id);

            $this->publishResourceEvent('roles', (string) $id, 'deleted');
            foreach ($affectedUsers as $usuario) {
                $this->publishUserPermissionsChanged((string) $usuario, ['source' => 'role.delete']);
            }
            $this->releaseLockIfOwned($request, 'roles', (string) $id);

            return redirect()
                ->route('modules.roles')
                ->with('success', 'Rol eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.roles')
                ->with('error', 'No se puede eliminar el rol porque tiene registros relacionados.');
        }
    }

    public function export(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $roles = $this->rolesService->getRoleExportRows($request);
        $columns = $this->rolesService->getExportColumns();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($roles, $columns, 'roles_export_' . now()->format('Ymd_His') . '.xlsx');
        }


        return $this->exportPdfResponse($roles, $columns, 'Listado de Roles', 'roles_export_' . now()->format('Ymd_His') . '.pdf');
    }


    private function buildRoleFields(array $permissionsMatrix, 
        Collection $vistasCatalog, array $selectedVistaIds): array
    {
        return $this->rolesService->buildRoleFields($permissionsMatrix, $vistasCatalog, $selectedVistaIds);
    }

    private function defaultPermissionMatrix(): array
    {
        return RolePermissionMatrix::defaultMatrix();
    }

    private function matrixFromStoredPermissions(Collection $storedPermissions): array
    {
        return RolePermissionMatrix::matrixFromStoredPermissions($storedPermissions);
    }

    private function extractSelectedPermissions(array $permissionsInput): array
    {
        return RolePermissionMatrix::extractSelectedPermissions($permissionsInput);
    }

    private function buildInforolRows(int $roleId, array $permissionPairs): array
    {
        return RolePermissionMatrix::buildInforolRows($roleId, $permissionPairs);
    }

    private function validateRolePermissionDependencies(array $permissionPairs): ?string
    {
        return RolePermissionMatrix::validateDependencies($permissionPairs);
    }

    private function resolvePermissionModules(): array
    {
        $modules = [];

        foreach (self::PERMISSION_MODULES as $moduleKey => $moduleConfig) {
            if (is_array($moduleConfig)) {
                $modules[$moduleKey] = [
                    'label' => $moduleConfig['label'] ?? $moduleKey,
                    'submodules' => $moduleConfig['submodules'] ?? [],
                ];
                continue;
            }

            $modules[$moduleKey] = [
                'label' => $moduleConfig,
                'submodules' => [],
            ];
        }

        return $modules;
    }

    private function resolvePermissionLeafLabels(array $modules): array
    {
        $leafModules = [];

        foreach ($modules as $moduleKey => $moduleConfig) {
            $submodules = $moduleConfig['submodules'] ?? [];
            if ($submodules !== []) {
                foreach ($submodules as $subKey => $subLabel) {
                    $leafModules[$subKey] = $subLabel;
                }
                continue;
            }

            $leafModules[$moduleKey] = $moduleConfig['label'] ?? $moduleKey;
        }

        return $leafModules;
    }

    private function resolvePermissionModuleChildren(array $modules): array
    {
        $children = [];

        foreach ($modules as $moduleKey => $moduleConfig) {
            $submodules = $moduleConfig['submodules'] ?? [];
            $children[$moduleKey] = $submodules !== [] ? array_keys($submodules) : [$moduleKey];
        }

        return $children;
    }
}
