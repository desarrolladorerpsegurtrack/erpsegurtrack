<?php

namespace App\Support;

use Illuminate\Support\Collection;

class RolePermissionMatrix
{
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
        'vehiculos' => [
            'label' => 'Vehículos',
            'submodules' => [
                'vehiculos' => 'Vehículos',
            ],
        ],
        'dispositivo_cliente' => 'Dispositivo cliente',
        'servicio_cliente' => 'Servicio cliente',
        'tickets' => 'Tickets',
        'configuracion' => [
            'label' => 'Configuracion',
            'submodules' => [
                'configuracion.estado' => 'Estado cliente',
                'configuracion.tipo_contacto' => 'Tipo de contacto',
                'configuracion.ubigeo' => 'Ubigeo',
                'configuracion.entidad_bancaria' => 'Entidad bancaria',
                'configuracion.proveedor' => 'Proveedor',
                'configuracion.tipo_cobro' => 'Tipo de cobro',
                'configuracion.tipo_gasto' => 'Tipo de gasto',
                'configuracion.certificadosunat' => 'Certificados SUNAT',
                'configuracion.forma_pago' => 'Forma de pago',
                'configuracion.moneda' => 'Moneda',
                'configuracion.tributo' => 'Tributo',
                'configuracion.tipo_documento' => 'Tipo de documento',
                'configuracion.vigencia_oferta' => 'Vigencia de oferta',
                'configuracion.cargo' => 'Cargo Personal',
                'configuracion.plataforma' => 'Plataforma',
                'configuracion.tipo_elemento' => 'Tipo de elemento',
                'configuracion.tipo_plataforma' => 'Tipo de plataforma',
                'configuracion.tipo_operacion' => 'Tipo de operación',
                'configuracion.marca' => 'Marca',
                'configuracion.unidad_medida' => 'Unidad de medida',
                'configuracion.tipo_pedido' => 'Tipo de pedido',
                'configuracion.tecnologia' => 'Tecnologia',
                'configuracion.lista_precio' => 'Lista de precio',
                'configuracion.operador' => 'Operador',
                'configuracion.tipo_vehiculo' => 'Tipo de vehículo',
                'configuracion.auditoria' => 'Auditoria',
                'configuracion.vista' => 'Vista',
                'configuracion.flujo' => 'Flujo',
                'configuracion.flujoregla' => 'Flujo Regla',
                'configuracion.historialflujo' => 'Historial Flujo',
            ],
        ],
        
    ];

    private const PERMISSION_ACTIONS = [
        'ver' => 'Ver',
        'crear' => 'Crear',
        'editar' => 'Editar',
        'eliminar' => 'Eliminar',
    ];

    private const MODULES_WITHOUT_EDIT = [
        'lineas_chips.detallesimcard',
        'lineas_chips.numero_dispositivo',
    ];

    private const MODULES_VER_ONLY = [
        'lineas_chips.cargar_numeros',
        'lineas_chips.bajar_numeros',
    ];

    public static function modules(): array
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

    public static function actions(): array
    {
        return self::PERMISSION_ACTIONS;
    }

    public static function defaultMatrix(): array
    {
        $matrix = [];
        $leafModules = self::resolvePermissionLeafLabels(self::modules());

        foreach (array_keys($leafModules) as $moduleKey) {
            foreach (array_keys(self::PERMISSION_ACTIONS) as $actionKey) {
                if (in_array($moduleKey, self::MODULES_VER_ONLY, true) && $actionKey !== 'ver') {
                    continue;
                }

                if (in_array($moduleKey, self::MODULES_WITHOUT_EDIT, true) && $actionKey === 'editar') {
                    continue;
                }

                $matrix[$moduleKey][$actionKey] = false;
            }
        }

        return $matrix;
    }

    /**
     * @param iterable<int, mixed> $storedPermissions
     */
    public static function matrixFromStoredPermissions(iterable $storedPermissions): array
    {
        $matrix = self::defaultMatrix();
        $moduleChildren = self::resolvePermissionModuleChildren(self::modules());

        foreach ($storedPermissions as $permission) {
            $module = mb_strtolower(trim((string) (is_array($permission) ? ($permission['modulo'] ?? '') : ($permission->modulo ?? ''))));
            $action = mb_strtolower(trim((string) (is_array($permission) ? ($permission['accion'] ?? '') : ($permission->accion ?? ''))));

            if ($module === '' || $action === '') {
                continue;
            }

            if (in_array($module, self::MODULES_VER_ONLY, true) && $action !== 'ver') {
                continue;
            }

            if (in_array($module, self::MODULES_WITHOUT_EDIT, true) && $action === 'editar') {
                continue;
            }

            if (isset($matrix[$module][$action])) {
                $matrix[$module][$action] = true;
                continue;
            }

            if (isset($moduleChildren[$module])) {
                foreach ($moduleChildren[$module] as $childKey) {
                    if (isset($matrix[$childKey][$action])) {
                        $matrix[$childKey][$action] = true;
                    }
                }
            }
        }

        return $matrix;
    }

    public static function extractSelectedPermissions(array $permissionsInput): array
    {
        $selected = [];
        $leafModules = self::resolvePermissionLeafLabels(self::modules());

        foreach (array_keys($leafModules) as $moduleKey) {
            $modulePermissions = $permissionsInput[$moduleKey] ?? [];
            if (!is_array($modulePermissions)) {
                continue;
            }

            foreach (array_keys(self::PERMISSION_ACTIONS) as $actionKey) {
                if (in_array($moduleKey, self::MODULES_VER_ONLY, true) && $actionKey !== 'ver') {
                    continue;
                }

                if (in_array($moduleKey, self::MODULES_WITHOUT_EDIT, true) && $actionKey === 'editar') {
                    continue;
                }

                if (!empty($modulePermissions[$actionKey])) {
                    $selected[] = [
                        'modulo' => $moduleKey,
                        'accion' => $actionKey,
                    ];
                }
            }
        }

        return $selected;
    }

    public static function buildInforolRows(int $roleId, array $permissionPairs): array
    {
        $rows = [];
        $moduleLabels = self::resolvePermissionLeafLabels(self::modules());

        foreach ($permissionPairs as $permission) {
            $module = $permission['modulo'];
            $action = $permission['accion'];
            $rows[] = [
                'rol_idrol' => $roleId,
                'modulo' => $module,
                'accion' => $action,
                'nombre' => ucfirst($action) . ' ' . ($moduleLabels[$module] ?? $module),
            ];
        }

        return $rows;
    }

    public static function validateDependencies(array $permissionPairs): ?string
    {
        $permissionCollection = collect($permissionPairs);

        $hasCredenciales = $permissionCollection->contains(function ($permission) {
            return ($permission['modulo'] ?? '') === 'clientes.credenciales';
        });

        $errors = [];

        if ($hasCredenciales) {
            $clienteActions = $permissionCollection
                ->filter(fn ($permission) => ($permission['modulo'] ?? '') === 'clientes.cliente')
                ->pluck('accion')
                ->map(fn ($action) => mb_strtolower(trim((string) $action)))
                ->unique();

            $hasClienteVer = $clienteActions->contains('ver');
            $hasClienteCreateOrEdit = $clienteActions->contains('crear') || $clienteActions->contains('editar');

            if (!$hasClienteVer || !$hasClienteCreateOrEdit) {
                $errors[] = 'Para asignar permisos de Credenciales debes dar primero permisos de Cliente: Ver y Crear o Editar.';
            }
        }

        $hasCargaOrBaja = $permissionCollection->contains(function ($permission) {
            return in_array($permission['modulo'] ?? '', ['lineas_chips.cargar_numeros', 'lineas_chips.bajar_numeros'], true);
        });

        if ($hasCargaOrBaja) {
            $detalleSimCardActions = $permissionCollection
                ->filter(fn ($permission) => ($permission['modulo'] ?? '') === 'lineas_chips.detallesimcard')
                ->pluck('accion')
                ->map(fn ($action) => mb_strtolower(trim((string) $action)))
                ->unique();

            if (!$detalleSimCardActions->contains('ver')) {
                $errors[] = 'Para asignar permisos de Cargar números o Bajar números debes dar primero permisos de Asignación SimCard: Ver.';
            }
        }

        return empty($errors) ? null : implode(' ', $errors);
    }

    private static function resolvePermissionLeafLabels(array $modules): array
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

    private static function resolvePermissionModuleChildren(array $modules): array
    {
        $children = [];

        foreach ($modules as $moduleKey => $moduleConfig) {
            $submodules = $moduleConfig['submodules'] ?? [];
            $children[$moduleKey] = $submodules !== [] ? array_keys($submodules) : [$moduleKey];
        }

        return $children;
    }
}
