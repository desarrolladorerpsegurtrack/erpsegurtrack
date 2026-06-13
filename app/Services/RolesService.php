<?php

namespace App\Services;

use App\Support\RolePermissionMatrix;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RolesService
{
    public function getVistasCatalog(): Collection
    {
        return DB::table('vista')
            ->select('idvista', 'nombre', 'detalle', 'estado')
            ->orderBy('nombre')
            ->get()
            ->map(function ($vista) {
                $vista->view_name = 'vistas.vista_' . $vista->idvista;
                return $vista;
            });
    }

    public function getTiposContactoCatalog(): Collection
    {
        return DB::table('tipocontacto')
            ->select('idtipoContacto', 'detalle')
            ->orderBy('detalle')
            ->get();
    }

    public function getRoleList(Request $request, int $perPage): LengthAwarePaginator
    {
        $query = $this->applyFilters($this->buildBaseQuery(), $this->extractFilters($request));

        return $query
            ->orderByDesc('r.idrol')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function getRoleStats(Request $request): array
    {
        $query = $this->applyFilters($this->buildBaseQuery(), $this->extractFilters($request));

        $totalRoles = (clone $query)->count('r.idrol');
        $activeRoles = DB::table('rol')->where('tipo', 1)->where('estado', '1')->count();
        $inactiveRoles = DB::table('rol')->where('tipo', 1)->where('estado', '0')->count();

        return [
            'total' => $totalRoles,
            'active' => $activeRoles,
            'inactive' => $inactiveRoles,
        ];
    }

    public function getRoleExportRows(Request $request): Collection
    {
        return $this->applyFilters($this->buildBaseQuery(), $this->extractFilters($request))
            ->orderByDesc('r.idrol')
            ->get();
    }

    public function getExportColumns(): array
    {
        return [
            ['key' => 'nombre', 'label' => 'Nombre'],
            ['key' => 'fechaCreacion', 'label' => 'Fecha Creación'],
            ['key' => 'estado', 'label' => 'Estado', 'value' => fn ($role) => $role->estado == '1'  ? 'Activo' : 'Inactivo'],
        ];
    }

    public function buildRoleFields(array $permissionsMatrix, Collection $vistasCatalog, array $selectedVistaIds = [], ?Collection $tiposContactoCatalog = null, array $selectedTipoContactoIds = []): array
    {
        return [
            [
                'name' => 'nombre',
                'type' => 'text',
                'label' => 'Nombre',
                'required' => true,
                'minlength' => 2,
                'maxlength' => 15,
            ],
            [
                'name' => 'estado',
                'type' => 'select',
                'label' => 'Estado',
                'required' => true,
                'options' => ['1' => 'Activo', '0' => 'Inactivo'],
            ],
            [
                'name' => 'permissions',
                'type' => 'permissions-matrix',
                'label' => 'Permisos del rol',
                'required' => false,
                'modules' => RolePermissionMatrix::modules(),
                'actions' => RolePermissionMatrix::actions(),
                'value' => $permissionsMatrix,
                'colSpan' => 2,
            ],
            [
                'name' => 'vista_permissions',
                'type' => 'vista-permissions',
                'label' => 'Acciones permitidas',
                'required' => false,
                'optionsData' => $vistasCatalog,
                'optionKey' => 'idvista',
                'optionLabel' => 'nombre',
                'value' => $selectedVistaIds,
                'colSpan' => 2,
            ],
            [
                'name' => 'contacto_tipos_permissions',
                'type' => 'contacto-tipos-permissions',
                'label' => 'Tipos de Contacto del Cliente permitidos',
                'required' => false,
                'optionsData' => $tiposContactoCatalog ?? collect(),
                'optionKey' => 'idtipoContacto',
                'optionLabel' => 'detalle',
                'value' => $selectedTipoContactoIds,
                'colSpan' => 2,
            ],
        ];
    }

    public function defaultPermissionMatrix(): array
    {
        return RolePermissionMatrix::defaultMatrix();
    }

    public function matrixFromStoredPermissions(Collection $storedPermissions): array
    {
        return RolePermissionMatrix::matrixFromStoredPermissions($storedPermissions);
    }

    public function extractSelectedPermissions(array $permissionsInput): array
    {
        return RolePermissionMatrix::extractSelectedPermissions($permissionsInput);
    }

    public function buildInforolRows(int $roleId, array $permissionPairs): array
    {
        return RolePermissionMatrix::buildInforolRows($roleId, $permissionPairs);
    }

    public function getStoredVistaIdsByRoleId(int $roleId): array
    {
        return DB::table('inforol')
            ->where('rol_idrol', $roleId)
            ->where('accion', 'ver')
            ->where('modulo', 'like', 'ticket.vista.%')
            ->pluck('modulo')
            ->map(function ($module) {
                $module = (string) $module;
                return (int) str_replace('ticket.vista.', '', $module);
            })
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    public function getStoredTipoContactoIdsByRoleId(int $roleId): array
    {
        $modulos = DB::table('inforol')
            ->where('rol_idrol', $roleId)
            ->where('accion', 'ver')
            ->where('modulo', 'like', 'cliente.tipo_contacto.%')
            ->pluck('modulo');

        if ($modulos->contains('cliente.tipo_contacto.*')) {
            return ['*'];
        }

        return $modulos
            ->map(function ($module) {
                $module = (string) $module;
                return (int) str_replace('cliente.tipo_contacto.', '', $module);
            })
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    public function extractSelectedVistaIds(array $vistaInput): array
    {
        return collect($vistaInput)
            ->map(fn ($id) => is_numeric($id) ? (int) $id : null)
            ->filter(fn ($id) => $id !== null && $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    public function extractSelectedTipoContactoIds(array $tipoInput): array
    {
        if (in_array('*', $tipoInput, true)) {
            return ['*'];
        }

        return collect($tipoInput)
            ->map(fn ($id) => is_numeric($id) ? (int) $id : null)
            ->filter(fn ($id) => $id !== null && $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    public function buildVistaInforolRows(int $roleId, array $vistaIds): array
    {
        if ($vistaIds === []) {
            return [];
        }

        $vistasById = $this->getVistasCatalog()->keyBy(fn ($vista) => (int) $vista->idvista);
        $rows = [];

        foreach ($vistaIds as $vistaId) {
            $vistaId = (int) $vistaId;
            if ($vistaId <= 0) {
                continue;
            }

            $vista = $vistasById->get($vistaId);
            $vistaName = $vista !== null ? (string) $vista->nombre : 'Vista ' . $vistaId;

            $rows[] = [
                'rol_idrol' => $roleId,
                'modulo' => 'ticket.vista.' . $vistaId,
                'accion' => 'ver',
                'nombre' => 'Ver vista ' . $vistaName,
            ];
        }

        return $rows;
    }

    public function buildTipoContactoInforolRows(int $roleId, array $tipoIds): array
    {
        if ($tipoIds === []) {
            return [];
        }

        $rows = [];

        if (in_array('*', $tipoIds, true)) {
            $rows[] = [
                'rol_idrol' => $roleId,
                'modulo' => 'cliente.tipo_contacto.*',
                'accion' => 'ver',
                'nombre' => 'Ver todos los tipos de contacto',
            ];
            return $rows;
        }

        $tiposById = $this->getTiposContactoCatalog()->keyBy(fn ($tipo) => (int) $tipo->idtipoContacto);

        foreach ($tipoIds as $tipoId) {
            $tipoId = (int) $tipoId;
            if ($tipoId <= 0) {
                continue;
            }

            $tipo = $tiposById->get($tipoId);
            $tipoName = $tipo !== null ? (string) $tipo->detalle : 'Tipo Contacto ' . $tipoId;

            $rows[] = [
                'rol_idrol' => $roleId,
                'modulo' => 'cliente.tipo_contacto.' . $tipoId,
                'accion' => 'ver',
                'nombre' => 'Ver contactos tipo ' . $tipoName,
            ];
        }

        return $rows;
    }

    public function validateRolePermissionDependencies(array $permissionPairs): ?string
    {
        return RolePermissionMatrix::validateDependencies($permissionPairs);
    }

    public function getRoleById(int $id)
    {
        return DB::table('rol')->where('idrol', $id)->where('tipo', 1)->first();
    }

    public function getStoredPermissionsByRoleId(int $id): Collection
    {
        return DB::table('inforol')->where('rol_idrol', $id)->select('modulo', 'accion')->get();
    }

    public function createRole(array $validated, array $permissionPairs, array $vistaIds, array $tipoContactoIds = []): int
    {
        return DB::transaction(function () use ($validated, $permissionPairs, $vistaIds, $tipoContactoIds) {
            $roleId = DB::table('rol')->insertGetId([
                'nombre' => $validated['nombre'],
                'estado' => $validated['estado'] ?? null,
                'tipo' => 1,
                'fechaCreacion' => now(),
            ]);

            $permissionRows = $this->buildInforolRows($roleId, $permissionPairs);
            $vistaRows = $this->buildVistaInforolRows($roleId, $vistaIds);
            $tipoContactoRows = $this->buildTipoContactoInforolRows($roleId, $tipoContactoIds);

            if ($permissionRows !== [] || $vistaRows !== [] || $tipoContactoRows !== []) {
                DB::table('inforol')->insert(array_merge($permissionRows, $vistaRows, $tipoContactoRows));
            }

            return $roleId;
        });
    }

    public function updateRole(int $id, array $validated, array $permissionPairs, array $vistaIds, array $tipoContactoIds = []): void
    {
        DB::transaction(function () use ($id, $validated, $permissionPairs, $vistaIds, $tipoContactoIds) {
            DB::table('rol')->where('idrol', $id)->update([
                'nombre' => $validated['nombre'],
                'estado' => $validated['estado'] ?? null,
            ]);

            DB::table('inforol')->where('rol_idrol', $id)->delete();

            $permissionRows = $this->buildInforolRows($id, $permissionPairs);
            $vistaRows = $this->buildVistaInforolRows($id, $vistaIds);
            $tipoContactoRows = $this->buildTipoContactoInforolRows($id, $tipoContactoIds);

            if ($permissionRows !== [] || $vistaRows !== [] || $tipoContactoRows !== []) {
                DB::table('inforol')->insert(array_merge($permissionRows, $vistaRows, $tipoContactoRows));
            }
        });
    }

    public function deleteRole(int $id): array
    {
        return DB::transaction(function () use ($id) {
            $affectedUsers = DB::table('detallerol')
                ->where('rol_idrol', $id)
                ->pluck('usuario_usuario')
                ->unique()
                ->values()
                ->all();

            DB::table('detallerol')->where('rol_idrol', $id)->delete();
            DB::table('inforol')->where('rol_idrol', $id)->delete();
            DB::table('rol')->where('idrol', $id)->delete();

            return $affectedUsers;
        });
    }

    private function buildBaseQuery(): Builder
    {
        return DB::table('rol as r')
            ->select('r.idrol', 'r.nombre', 'r.estado', 'r.fechaCreacion')
            ->where('r.tipo', 1);
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        if ($filters['search'] !== '') {
            $query->where(function (Builder $query) use ($filters) {
                $term = '%' . $filters['search'] . '%';
                $query
                    ->where('r.nombre', 'like', $term)
                    ->orWhere('r.estado', 'like', $term)
                    ->orWhereExists(function ($subquery) use ($term) {
                        $subquery
                            ->select(DB::raw(1))
                            ->from('inforol as i')
                            ->whereColumn('i.rol_idrol', 'r.idrol')
                            ->where(function ($inforolQuery) use ($term) {
                                $inforolQuery
                                    ->where('i.modulo', 'like', $term)
                                    ->orWhere('i.nombre', 'like', $term)
                                    ->orWhere('i.accion', 'like', $term);
                            });
                    });
            });
        }

        if ($filters['estado'] !== '') {
            $query->where('r.estado', $filters['estado']);
        }

        if ($filters['nombre'] !== '') {
            $query->where('r.nombre', 'like', '%' . $filters['nombre'] . '%');
        }

        return $query;
    }

    private function extractFilters(Request $request): array
    {
        return [
            'search' => trim((string) $request->input('q', '')),
            'estado' => trim((string) $request->input('estado', '')),
            'nombre' => trim((string) $request->input('nombre', '')),
        ];
    }
}
