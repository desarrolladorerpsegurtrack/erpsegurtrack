<?php

namespace App\Support;

use Illuminate\Support\Collection;

class RolePermissionMatrix
{
    private const PERMISSION_MODULES = [
        'inicio' => 'Inicio',
        'tickets' => 'Gestiones',
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
        'ventas' => [
            'label' => 'Ventas',
            'submodules' => [
                'ventas.planes_servicios' => 'Planes y servicios',
            ],
        ],
        'almacen' => [
            'label' => 'Almacen',
            'submodules' => [
                'almacen.almacen' => 'Almacén',
                'almacen.elemento_almacen' => 'Elemento de almacén',
                'almacen.nota_ingreso' => 'Nota de ingreso',
                'almacen.nota_salida' => 'Nota de salida',
            ],
        ],
        'configuracion' => [
            'label' => 'Configuracion',
            'submodules' => [
                // Cliente módulo de configuración
                'configuracion.estado' => 'Estado cliente',
                'configuracion.tipo_contacto' => 'Tipo de contacto',
                'configuracion.ubigeo' => 'Ubigeo',
                // Finanzas módulo de configuración
                'configuracion.entidad_bancaria' => 'Entidad bancaria',
                'configuracion.proveedor' => 'Proveedor',
                'configuracion.tipo_cobro' => 'Tipo de cobro',
                'configuracion.tipo_gasto' => 'Tipo de gasto',
                // Facturación módulo de configuración
                'configuracion.certificadosunat' => 'Certificados SUNAT',
                'configuracion.forma_pago' => 'Forma de pago',
                'configuracion.moneda' => 'Moneda',
                'configuracion.tributo' => 'Tributo',
                'configuracion.tipo_documento' => 'Tipo de documento',
                'configuracion.vigencia_oferta' => 'Vigencia de oferta',
                // Personal módulo de configuración
                'configuracion.cargo' => 'Cargo Personal',
                // Plataforma módulo de configuración
                'configuracion.plataforma' => 'Plataforma',
                'configuracion.tipo_plataforma' => 'Tipo de plataforma',
                // Almacén módulo de configuración
                'configuracion.detalle_lista_precio' => 'Detalle de lista de precio',
                'configuracion.empresapropietaria' => 'Empresa propietaria',
                'configuracion.lista_precio' => 'Lista de precio',
                'configuracion.marca' => 'Marca',
                'configuracion.modelo' => 'Modelo',
                'configuracion.tecnologia' => 'Tecnologia',
                'configuracion.tipo_elemento' => 'Tipo de elemento',
                'configuracion.tipo_pedido' => 'Tipo de pedido',
                'configuracion.unidad_medida' => 'Unidad de medida',
                // Gestión módulo de configuración
                'configuracion.tipo_operacion' => 'Tipo de operación',
                // Vehículos módulo de configuración
                'configuracion.operador' => 'Operador',
                'configuracion.tipo_vehiculo' => 'Tipo de vehículo',
                // Auditoria módulo de configuración
                'configuracion.auditoria' => 'Auditoria',
                
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
        
    ];

    private const PERMISSION_ACTIONS = [
        'ver' => 'Ver',
        'ver_flujo' => 'Ver flujo',
        'crear' => 'Crear',
        'editar' => 'Editar',
        'eliminar' => 'Eliminar',
        'exportar' => 'Exportar',
    ];

    private const MODULES_WITHOUT_EDIT = [
        'lineas_chips.detallesimcard',
        'lineas_chips.numero_dispositivo',
    ];

    private const MODULES_WITHOUT_EDIT_DELETE = [
        'tickets',
    ];

    private const MODULES_VER_ONLY = [
        'inicio',
        'lineas_chips.cargar_numeros',
        'lineas_chips.bajar_numeros',
        'configuracion.auditoria',
        'sistema.historialflujo',
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
                if (in_array($moduleKey, self::MODULES_VER_ONLY, true) && !in_array($actionKey, ['ver', 'exportar'], true)) {
                    continue;
                }

                if (in_array($moduleKey, self::MODULES_WITHOUT_EDIT_DELETE, true) && in_array($actionKey, ['editar', 'eliminar'], true)) {
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

            if (in_array($module, self::MODULES_VER_ONLY, true) && !in_array($action, ['ver', 'exportar'], true)) {
                continue;
            }

            if (in_array($module, self::MODULES_WITHOUT_EDIT_DELETE, true) && in_array($action, ['editar', 'eliminar'], true)) {
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
                if (in_array($moduleKey, self::MODULES_VER_ONLY, true) && !in_array($actionKey, ['ver', 'exportar'], true)) {
                    continue;
                }

                if (in_array($moduleKey, self::MODULES_WITHOUT_EDIT_DELETE, true) && in_array($actionKey, ['editar', 'eliminar'], true)) {
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
            $actionLabel = self::PERMISSION_ACTIONS[$action] ?? ucfirst(str_replace(['_', '-'], ' ', $action));
            $rows[] = [
                'rol_idrol' => $roleId,
                'modulo' => $module,
                'accion' => $action,
                'nombre' => $actionLabel . ' ' . ($moduleLabels[$module] ?? $module),
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
