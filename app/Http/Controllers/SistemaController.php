<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Export\ExportableList;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SistemaController extends Controller
{
    use ExportableList;

    public function index(): RedirectResponse
    {
        return redirect()->route('modules.sistema.vistas.index');
    }

    public function vistasIndex(Request $request): View
    {
        $baseQuery = DB::table('vista');

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idvista', 'like', $term)
                    ->orWhere('nombre', 'like', $term)
                    ->orWhere('detalle', 'like', $term)
                    ->orWhere('estado', 'like', $term)
                    ->orWhere('fechacreacion', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('idvista')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $items->through(function ($row) {
            if (isset($row->fechacreacion)) {
                $row->fechacreacion = self::formatDateTimeForList((string) $row->fechacreacion);
            }

            return $row;
        });

        return view('sistema.vista', [
            'title' => 'Sistema: Vista',
            'singularTitle' => 'Vista',
            'items' => $items,
            'columns' => [
                ['key' => 'idvista', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text'],
                ['key' => 'detalle', 'label' => 'Detalle', 'type' => 'text'],
                ['key' => 'estado', 'label' => 'Estado', 'type' => 'status'],
                ['key' => 'fechacreacion', 'label' => 'Fecha creación', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.sistema.vistas.export', ['format' => 'pdf']),
                'xlsx' => route('modules.sistema.vistas.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de vistas', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => [],
            'createRoute' => route('modules.sistema.vistas.create'),
            'editRoute' => 'modules.sistema.vistas.edit',
            'showRoute' => 'modules.sistema.vistas.edit',
            'destroyRoute' => 'modules.sistema.vistas.destroy',
            'bulkDestroyRoute' => route('modules.sistema.vistas.bulk-destroy'),
            'identifierKey' => 'idvista',
            'lockResource' => 'sistema.vista',
        ]);
    }

    public function vistasCreate(): View
    {
        return view('sistema.vista-form', [
            'title' => 'Nueva Vista',
            'moduleTitle' => 'Sistema: Vista',
            'mode' => 'create',
            'formAction' => route('modules.sistema.vistas.store'),
            'backRoute' => route('modules.sistema.vistas.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'nombre',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 45,
                    'minlength' => 2,
                    'helpText' => 'Nombre de la vista.',
                ],
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => true,
                    'maxlength' => 45,
                    'minlength' => 2,
                    'helpText' => 'Detalle descriptivo de la vista.',
                ],
                [
                    'name' => 'estado',
                    'type' => 'select',
                    'label' => 'Estado',
                    'required' => true,
                    'value' => old('estado', 'Activo'),
                    'options' => [
                        'Activo' => 'Activo',
                        'Inactivo' => 'Inactivo',
                    ],
                    'helpText' => 'Selecciona el estado de la vista.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function vistasStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
            'detalle' => ['required', 'string', 'min:2', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
            'estado' => ['required', 'in:Activo,Inactivo'],
            'fechacreacion' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ]);

        $validated['fechacreacion'] = self::normalizeDateTimeInput($validated['fechacreacion'] ?? null) ?? now()->format('Y-m-d H:i:s');

        $newId = DB::table('vista')->insertGetId($validated);
        $this->publishResourceEvent('sistema.vista', (string) $newId, 'created');

        return redirect()
            ->route('modules.sistema.vistas.index')
            ->with('success', 'Vista creada correctamente.');
    }

    public function vistasEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('vista')->where('idvista', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.sistema.vistas.index')
                ->with('error', 'No se encontro la vista solicitada.');
        }

        return view('sistema.vista-form', [
            'title' => 'Editar Vista',
            'moduleTitle' => 'Sistema: Vista',
            'mode' => 'edit',
            'formAction' => route('modules.sistema.vistas.update', $id),
            'backRoute' => route('modules.sistema.vistas.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'nombre',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 45,
                    'minlength' => 2,
                    'helpText' => 'Nombre de la vista.',
                ],
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => true,
                    'maxlength' => 45,
                    'minlength' => 2,
                    'helpText' => 'Detalle descriptivo de la vista.',
                ],
                [
                    'name' => 'estado',
                    'type' => 'select',
                    'label' => 'Estado',
                    'required' => true,
                    'value' => $record->estado ?? 'Activo',
                    'options' => [
                        'Activo' => 'Activo',
                        'Inactivo' => 'Inactivo',
                    ],
                    'helpText' => 'Selecciona el estado de la vista.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('sistema.vista', (string) $id));
    }

    public function vistasUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('vista')->where('idvista', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.sistema.vistas.index')
                ->with('error', 'No se encontro la vista solicitada.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'sistema.vista', (string) $id, 'vista', 'modules.sistema.vistas.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
            'detalle' => ['required', 'string', 'min:2', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
            'estado' => ['required', 'in:Activo,Inactivo'],
            'fechacreacion' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ]);

        $validated['fechacreacion'] = self::normalizeDateTimeInput($validated['fechacreacion'] ?? null) ?? DB::table('vista')->where('idvista', $id)->value('fechacreacion');

        DB::table('vista')->where('idvista', $id)->update($validated);
        $this->publishResourceEvent('sistema.vista', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'sistema.vista', (string) $id);

        return redirect()
            ->route('modules.sistema.vistas.index')
            ->with('success', 'Vista actualizada correctamente.');
    }

    public function vistasDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'sistema.vista', (string) $id, 'vista', 'modules.sistema.vistas.index')) {
            return $redirect;
        }

        try {
            DB::table('vista')->where('idvista', $id)->delete();
            $this->publishResourceEvent('sistema.vista', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'sistema.vista', (string) $id);

            return redirect()
                ->route('modules.sistema.vistas.index')
                ->with('success', 'Vista eliminada correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.sistema.vistas.index')
                ->with('error', 'No se puede eliminar la vista porque tiene registros relacionados.');
        }
    }

    public function vistasBulkDestroy(Request $request): RedirectResponse
    {
        $selectedIds = $request->input('selectedIds', []);
        if (!is_array($selectedIds)) {
            $selectedIds = [];
        }

        $selectedIds = array_filter(array_map('intval', $selectedIds), fn ($id) => $id > 0);
        if (empty($selectedIds)) {
            return redirect()
                ->route('modules.sistema.vistas.index')
                ->with('error', 'No se seleccionaron registros para eliminar.');
        }

        foreach ($selectedIds as $selectedId) {
            if ($redirect = $this->assertLockAvailable($request, 'sistema.vista', (string) $selectedId, 'vista', 'modules.sistema.vistas.index')) {
                return $redirect;
            }
        }

        try {
            DB::transaction(function () use ($selectedIds, $request) {
                DB::table('vista')->whereIn('idvista', $selectedIds)->delete();

                foreach ($selectedIds as $selectedId) {
                    $this->publishResourceEvent('sistema.vista', (string) $selectedId, 'deleted');
                    $this->releaseLockIfOwned($request, 'sistema.vista', (string) $selectedId);
                }
            });

            return redirect()
                ->route('modules.sistema.vistas.index')
                ->with('success', 'Vistas eliminadas correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.sistema.vistas.index')
                ->with('error', 'No se pueden eliminar las vistas porque tienen registros relacionados.');
        }
    }

    public function vistasExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $baseQuery = DB::table('vista');

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idvista', 'like', $term)
                    ->orWhere('nombre', 'like', $term)
                    ->orWhere('detalle', 'like', $term)
                    ->orWhere('estado', 'like', $term)
                    ->orWhere('fechacreacion', 'like', $term);
            });
        }

        $rows = $baseQuery
            ->orderBy('idvista')
            ->get()
            ->map(function ($row) {
                if (isset($row->fechacreacion)) {
                    $row->fechacreacion = self::formatDateTimeForList((string) $row->fechacreacion);
                }

                return $row;
            });

        $columns = [
            ['key' => 'idvista', 'label' => 'ID'],
            ['key' => 'nombre', 'label' => 'Nombre'],
            ['key' => 'detalle', 'label' => 'Detalle'],
            ['key' => 'estado', 'label' => 'Estado'],
            ['key' => 'fechacreacion', 'label' => 'Fecha creación'],
        ];

        $filename = 'vista_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Vistas', $filename);
    }

    public function flujosIndex(Request $request): View
    {
        $baseQuery = DB::table('flujo')
            ->leftJoin('tipooperacion', 'flujo.tipoOperacion_idtipoOperacion', '=', 'tipooperacion.idtipoOperacion')
            ->select('flujo.*', DB::raw("COALESCE(CONCAT(tipooperacion.nomenclatura, ' - ', tipooperacion.detalle), 'Sin tipo de operación') as tipoOperacion"));

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('flujo.idflujo', 'like', $term)
                    ->orWhere('flujo.nombre', 'like', $term)
                    ->orWhere('flujo.descripcion', 'like', $term)
                    ->orWhere('flujo.fechacreacion', 'like', $term)
                    ->orWhere('tipooperacion.nomenclatura', 'like', $term)
                    ->orWhere('tipooperacion.detalle', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('flujo.idflujo')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $flowIds = $items->getCollection()
            ->pluck('idflujo')
            ->filter()
            ->map(fn ($value) => (int) $value)
            ->values();

        $rulesByFlow = collect();
        if ($flowIds->isNotEmpty()) {
            $rulesByFlow = DB::table('flujoregla')
                ->leftJoin('vista', 'flujoregla.vista_idvista', '=', 'vista.idvista')
                ->whereIn('flujoregla.flujo_idflujo', $flowIds->all())
                ->orderBy('flujoregla.flujo_idflujo')
                ->orderBy('flujoregla.orden')
                ->select(
                    'flujoregla.flujo_idflujo',
                    'flujoregla.orden',
                    'flujoregla.estado',
                    'flujoregla.condicion',
                    DB::raw("COALESCE(CONCAT(vista.nombre), 'Sin vista') as vista_label")
                )
                ->get()
                ->groupBy('flujo_idflujo');
        }

        $items->through(function ($row) {
            if (isset($row->fechacreacion)) {
                $row->fechacreacion = self::formatDateTimeForList((string) $row->fechacreacion);
            }

            return $row;
        });

        $items->setCollection(
            $items->getCollection()->map(function ($row) use ($rulesByFlow) {
                $rules = collect($rulesByFlow->get($row->idflujo, []));
                $row->history = $rules->map(function ($rule) {
                    return (object) [
                        'vista_label' => $rule->vista_label ?? 'Sin vista',
                        'orden' => $rule->orden ?? '-',
                        'estado' => $rule->estado ?? '1',
                        'condicion' => $rule->condicion ?? null,
                    ];
                });

            return $row;
            })
        );

        return view('sistema.flujo', [
            'title' => 'Sistema: Flujo',
            'singularTitle' => 'Flujo',
            'items' => $items,
            'columns' => [
                ['key' => 'idflujo', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'tipoOperacion', 'label' => 'Tipo de operación', 'type' => 'text'],
                ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text'],
                ['key' => 'descripcion', 'label' => 'Descripción', 'type' => 'text'],
                ['key' => 'fechacreacion', 'label' => 'Fecha creación', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.sistema.flujos.export', ['format' => 'pdf']),
                'xlsx' => route('modules.sistema.flujos.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de flujos', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => [],
            'historyColumns' => [
                ['key' => 'vista_label', 'label' => 'Vista', 'type' => 'text'],
                ['key' => 'orden', 'label' => 'Orden', 'type' => 'text'],
                ['key' => 'estado', 'label' => 'Estado', 'type' => 'status'],
                ['key' => 'condicion', 'label' => 'Condición', 'type' => 'text'],
            ],
            'historyTitle' => 'Regla de flujo',
            'createRoute' => route('modules.sistema.flujos.create'),
            'editRoute' => 'modules.sistema.flujos.edit',
            'showRoute' => 'modules.sistema.flujos.edit',
            'destroyRoute' => 'modules.sistema.flujos.destroy',
            'bulkDestroyRoute' => route('modules.sistema.flujos.bulk-destroy'),
            'identifierKey' => 'idflujo',
            'lockResource' => 'sistema.flujo',
        ]);
    }

    public function flujosCreate(): View
    {
        $tiposOperacion = DB::table('tipooperacion')
            ->orderBy('detalle')
            ->select('idtipoOperacion', DB::raw("CONCAT(nomenclatura, ' - ', detalle) as label"))
            ->get();

        $vistas = DB::table('vista')
            ->orderBy('nombre')
            ->select('idvista', 'nombre', 'detalle', 'estado', DB::raw("CONCAT(idvista, ' - ', nombre) as label"))
            ->get();

        return view('sistema.flujo-form', [
            'title' => 'Nuevo Flujo',
            'moduleTitle' => 'Sistema: Flujo',
            'mode' => 'create',
            'formAction' => route('modules.sistema.flujos.store'),
            'backRoute' => route('modules.sistema.flujos.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'tipoOperacion_idtipoOperacion',
                    'type' => 'select',
                    'label' => 'Tipo de operación',
                    'required' => true,
                    'optionsData' => $tiposOperacion,
                    'optionKey' => 'idtipoOperacion',
                    'optionLabel' => 'label',
                    'tomSelect' => true,
                    'helpText' => 'Selecciona el tipo de operación.',
                ],
                [
                    'name' => 'nombre',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Nombre del flujo.',
                ],
                [
                    'name' => 'descripcion',
                    'type' => 'text',
                    'label' => 'Descripción',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Descripción del flujo.',
                ],
            ],
            'extraSections' => [
                $this->buildFlujoReglasSection('create', $vistas, old('reglas', [[
                    'vista_idvista' => '',
                    'orden' => 1,
                    'estado' => '1',
                    'condicion' => '',
                ]]))
            ],
            'readOnly' => false,
        ]);
    }

    public function flujosStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tipoOperacion_idtipoOperacion' => ['required', 'integer', 'exists:tipooperacion,idtipoOperacion'],
            'nombre' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'descripcion' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'fechacreacion' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'reglas' => ['required', 'array', 'min:1'],
            'reglas.*.vista_idvista' => ['required', 'integer', 'exists:vista,idvista'],
            'reglas.*.orden' => ['required', 'integer', 'min:1'],
            'reglas.*.estado' => ['required', 'in:1'],
            'reglas.*.condicion' => ['nullable', 'string', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        $validated['fechacreacion'] = self::normalizeDateTimeInput($validated['fechacreacion'] ?? null) ?? now()->format('Y-m-d H:i:s');

        $reglas = $validated['reglas'];
        unset($validated['reglas']);

        $newId = DB::transaction(function () use ($validated, $reglas) {
            $flowId = DB::table('flujo')->insertGetId($validated);

            foreach ($reglas as $rule) {
                $orden = (int) ($rule['orden'] ?? 0);
                $ruleData = [
                    'flujo_idflujo' => $flowId,
                    'vista_idvista' => (int) $rule['vista_idvista'],
                    'orden' => $orden,
                    'estado' => (string) ($rule['estado'] ?? '1'),
                    'condicion' => 'ver vista ' . $orden,
                ];

                $newRuleId = DB::table('flujoregla')->insertGetId($ruleData);
                $this->publishResourceEvent('sistema.flujoregla', (string) $newRuleId, 'created');
            }

            $this->publishResourceEvent('sistema.flujo', (string) $flowId, 'created');

            return $flowId;
        });

        return redirect()
            ->route('modules.sistema.flujos.index')
            ->with('success', 'Flujo y reglas creados correctamente.');
    }

    public function flujosEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('flujo')->where('idflujo', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.sistema.flujos.index')
                ->with('error', 'No se encontro el flujo solicitado.');
        }

        $tiposOperacion = DB::table('tipooperacion')
            ->orderBy('detalle')
            ->select('idtipoOperacion', DB::raw("CONCAT(nomenclatura, ' - ', detalle) as label"))
            ->get();

        return view('sistema.flujo-form', [
            'title' => 'Editar Flujo',
            'moduleTitle' => 'Sistema: Flujo',
            'mode' => 'edit',
            'formAction' => route('modules.sistema.flujos.update', $id),
            'backRoute' => route('modules.sistema.flujos.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'tipoOperacion_idtipoOperacion',
                    'type' => 'select',
                    'label' => 'Tipo de operación',
                    'required' => true,
                    'optionsData' => $tiposOperacion,
                    'optionKey' => 'idtipoOperacion',
                    'optionLabel' => 'label',
                    'tomSelect' => true,
                    
                    'helpText' => 'Selecciona el tipo de operación.',
                ],
                [
                    'name' => 'nombre',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Nombre del flujo.',
                ],
                [
                    'name' => 'descripcion',
                    'type' => 'text',
                    'label' => 'Descripción',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Descripción del flujo.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('sistema.flujo', (string) $id));
    }

    private function buildFlujoReglasSection(string $sectionMode, iterable $vistaOptions, iterable $rules = [], ?int $flowId = null): array
    {
        return [
            'view' => 'sistema.partials.flujo-reglas-section',
            'data' => [
                'sectionMode' => $sectionMode,
                'vistaOptions' => $vistaOptions,
                'rules' => $rules,
                'flowId' => $flowId,
            ],
        ];
    }

    public function flujosUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('flujo')->where('idflujo', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.sistema.flujos.index')
                ->with('error', 'No se encontro el flujo solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'sistema.flujo', (string) $id, 'flujo', 'modules.sistema.flujos.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'tipoOperacion_idtipoOperacion' => ['required', 'integer', 'exists:tipooperacion,idtipoOperacion'],
            'nombre' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'descripcion' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'fechacreacion' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ]);

        $validated['fechacreacion'] = self::normalizeDateTimeInput($validated['fechacreacion'] ?? null) ?? DB::table('flujo')->where('idflujo', $id)->value('fechacreacion');

        DB::table('flujo')->where('idflujo', $id)->update($validated);
        $this->publishResourceEvent('sistema.flujo', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'sistema.flujo', (string) $id);

        return redirect()
            ->route('modules.sistema.flujos.index')
            ->with('success', 'Flujo actualizado correctamente.');
    }

    public function flujosDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'sistema.flujo', (string) $id, 'flujo', 'modules.sistema.flujos.index')) {
            return $redirect;
        }

        try {
            DB::table('flujo')->where('idflujo', $id)->delete();
            $this->publishResourceEvent('sistema.flujo', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'sistema.flujo', (string) $id);

            return redirect()
                ->route('modules.sistema.flujos.index')
                ->with('success', 'Flujo eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.sistema.flujos.index')
                ->with('error', 'No se puede eliminar el flujo porque tiene registros relacionados.');
        }
    }

    public function flujosBulkDestroy(Request $request): RedirectResponse
    {
        $selectedIds = $request->input('selectedIds', []);
        if (!is_array($selectedIds)) {
            $selectedIds = [];
        }

        $selectedIds = array_filter(array_map('intval', $selectedIds), fn ($id) => $id > 0);
        if (empty($selectedIds)) {
            return redirect()
                ->route('modules.sistema.flujos.index')
                ->with('error', 'No se seleccionaron registros para eliminar.');
        }

        foreach ($selectedIds as $selectedId) {
            if ($redirect = $this->assertLockAvailable($request, 'sistema.flujo', (string) $selectedId, 'flujo', 'modules.sistema.flujos.index')) {
                return $redirect;
            }
        }

        try {
            DB::transaction(function () use ($selectedIds, $request) {
                DB::table('flujo')->whereIn('idflujo', $selectedIds)->delete();

                foreach ($selectedIds as $selectedId) {
                    $this->publishResourceEvent('sistema.flujo', (string) $selectedId, 'deleted');
                    $this->releaseLockIfOwned($request, 'sistema.flujo', (string) $selectedId);
                }
            });

            return redirect()
                ->route('modules.sistema.flujos.index')
                ->with('success', 'Flujos eliminados correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.sistema.flujos.index')
                ->with('error', 'No se pueden eliminar los flujos porque tienen registros relacionados.');
        }
    }

    public function flujosExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $baseQuery = DB::table('flujo')
            ->leftJoin('tipooperacion', 'flujo.tipoOperacion_idtipoOperacion', '=', 'tipooperacion.idtipoOperacion')
            ->select('flujo.*', DB::raw("COALESCE(CONCAT(tipooperacion.nomenclatura, ' - ', tipooperacion.detalle), 'Sin tipo de operación') as tipoOperacion"));

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('flujo.idflujo', 'like', $term)
                    ->orWhere('flujo.nombre', 'like', $term)
                    ->orWhere('flujo.descripcion', 'like', $term)
                    ->orWhere('flujo.fechacreacion', 'like', $term)
                    ->orWhere('tipooperacion.nomenclatura', 'like', $term)
                    ->orWhere('tipooperacion.detalle', 'like', $term);
            });
        }

        $rows = $baseQuery
            ->orderBy('flujo.idflujo')
            ->get()
            ->map(function ($row) {
                if (isset($row->fechacreacion)) {
                    $row->fechacreacion = self::formatDateTimeForList((string) $row->fechacreacion);
                }

                return $row;
            });

        $columns = [
            ['key' => 'idflujo', 'label' => 'ID'],
            ['key' => 'tipoOperacion', 'label' => 'Tipo de operación'],
            ['key' => 'nombre', 'label' => 'Nombre'],
            ['key' => 'descripcion', 'label' => 'Descripción'],
            ['key' => 'fechacreacion', 'label' => 'Fecha creación'],
        ];

        $filename = 'flujo_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Flujos', $filename);
    }

    public function flujoReglasIndex(Request $request): View
    {
        $baseQuery = DB::table('flujoregla')
            ->leftJoin('flujo', 'flujoregla.flujo_idflujo', '=', 'flujo.idflujo')
            ->leftJoin('vista', 'flujoregla.vista_idvista', '=', 'vista.idvista')
            ->select(
                'flujoregla.*',
                DB::raw("COALESCE(CONCAT(flujo.nombre), 'Sin flujo') as flujo"),
                DB::raw("COALESCE(CONCAT(vista.nombre), 'Sin vista') as vista")
            );

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('flujoregla.idflujoregla', 'like', $term)
                    ->orWhere('flujoregla.orden', 'like', $term)
                    ->orWhere('flujoregla.estado', 'like', $term)
                    ->orWhere('flujoregla.condicion', 'like', $term)
                    ->orWhere('flujo.nombre', 'like', $term)
                    ->orWhere('vista.nombre', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('flujoregla.idflujoregla')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('sistema.flujoregla', [
            'title' => 'Sistema: Flujo Regla',
            'singularTitle' => 'Flujo Regla',
            'items' => $items,
            'columns' => [
                ['key' => 'idflujoregla', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'flujo', 'label' => 'Flujo', 'type' => 'text'],
                ['key' => 'vista', 'label' => 'Vista', 'type' => 'text'],
                ['key' => 'orden', 'label' => 'Orden', 'type' => 'text'],
                ['key' => 'estado', 'label' => 'Estado', 'type' => 'status'],
                ['key' => 'condicion', 'label' => 'Condición', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.sistema.flujo-reglas.export', ['format' => 'pdf']),
                'xlsx' => route('modules.sistema.flujo-reglas.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de reglas de flujo', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => [],
            'createRoute' => route('modules.sistema.flujo-reglas.create'),
            'editRoute' => 'modules.sistema.flujo-reglas.edit',
            'showRoute' => 'modules.sistema.flujo-reglas.edit',
            'destroyRoute' => 'modules.sistema.flujo-reglas.destroy',
            'bulkDestroyRoute' => route('modules.sistema.flujo-reglas.bulk-destroy'),
            'identifierKey' => 'idflujoregla',
            'lockResource' => 'sistema.flujoregla',
        ]);
    }

    public function flujoReglasCreate(): View
    {
        $flujos = DB::table('flujo')
            ->orderBy('nombre')
            ->select('idflujo', DB::raw("CONCAT(nombre) as label"))
            ->get();
        $vistas = DB::table('vista')
            ->orderBy('nombre')
            ->select('idvista', DB::raw("CONCAT(nombre) as label"))
            ->get();

        return view('sistema.flujoregla-form', [
            'title' => 'Nueva Flujo Regla',
            'moduleTitle' => 'Sistema: Flujo Regla',
            'mode' => 'create',
            'formAction' => route('modules.sistema.flujo-reglas.store'),
            'backRoute' => route('modules.sistema.flujo-reglas.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'flujo_idflujo',
                    'type' => 'select',
                    'label' => 'Flujo',
                    'required' => true,
                    'optionsData' => $flujos,
                    'optionKey' => 'idflujo',
                    'optionLabel' => 'label',
                    'tomSelect' => true,
                    'helpText' => 'Selecciona el flujo relacionado.',
                ],
                [
                    'name' => 'vista_idvista',
                    'type' => 'select',
                    'label' => 'Vista',
                    'required' => true,
                    'optionsData' => $vistas,
                    'optionKey' => 'idvista',
                    'optionLabel' => 'label',
                    'tomSelect' => true,
                    'helpText' => 'Selecciona la vista relacionada.',
                ],
                [
                    'name' => 'orden',
                    'type' => 'number',
                    'label' => 'Orden',
                    'required' => true,
                    'min' => 1,
                    'helpText' => 'Orden de ejecución de la regla.',
                ],
                [
                    'name' => 'estado',
                    'type' => 'select',
                    'label' => 'Estado',
                    'required' => true,
                    'value' => old('estado', '1'),
                    'options' => [
                        '1' => 'Activo',
                        '0' => 'Inactivo',
                    ],
                    'helpText' => 'Selecciona el estado de la regla.',
                ],
                [
                    'name' => 'condicion',
                    'type' => 'text',
                    'label' => 'Condición',
                    'required' => false,
                    'maxlength' => 45,
                    'minlength' => 0,
                    'helpText' => 'Condición opcional de la regla.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function flujoReglasStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'flujo_idflujo' => ['required', 'integer', 'exists:flujo,idflujo'],
            'vista_idvista' => ['required', 'integer', 'exists:vista,idvista'],
            'orden' => ['required', 'integer', 'min:1'],
            'estado' => ['required', 'in:0,1'],
            'condicion' => ['nullable', 'string', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        $newId = DB::table('flujoregla')->insertGetId($validated);
        $this->publishResourceEvent('sistema.flujoregla', (string) $newId, 'created');

        return redirect()
            ->route('modules.sistema.flujo-reglas.index')
            ->with('success', 'Flujo regla creada correctamente.');
    }

    public function flujoReglasEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('flujoregla')->where('idflujoregla', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.sistema.flujo-reglas.index')
                ->with('error', 'No se encontro la regla de flujo solicitada.');
        }

        $flujos = DB::table('flujo')
            ->orderBy('nombre')
            ->select('idflujo', DB::raw("CONCAT(nombre) as label"))
            ->get();
        $vistas = DB::table('vista')
            ->orderBy('nombre')
            ->select('idvista', DB::raw("CONCAT(nombre) as label"))
            ->get();

        return view('sistema.flujoregla-form', [
            'title' => 'Editar Flujo Regla',
            'moduleTitle' => 'Sistema: Flujo Regla',
            'mode' => 'edit',
            'formAction' => route('modules.sistema.flujo-reglas.update', $id),
            'backRoute' => route('modules.sistema.flujo-reglas.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'flujo_idflujo',
                    'type' => 'select',
                    'label' => 'Flujo',
                    'required' => true,
                    'optionsData' => $flujos,
                    'optionKey' => 'idflujo',
                    'optionLabel' => 'label',
                    'tomSelect' => true,
                    'helpText' => 'Selecciona el flujo relacionado.',
                ],
                [
                    'name' => 'vista_idvista',
                    'type' => 'select',
                    'label' => 'Vista',
                    'required' => true,
                    'optionsData' => $vistas,
                    'optionKey' => 'idvista',
                    'optionLabel' => 'label',
                    'tomSelect' => true,
                    'helpText' => 'Selecciona la vista relacionada.',
                ],
                [
                    'name' => 'orden',
                    'type' => 'number',
                    'label' => 'Orden',
                    'required' => true,
                    'min' => 1,
                    'helpText' => 'Orden de ejecución de la regla.',
                ],
                [
                    'name' => 'estado',
                    'type' => 'select',
                    'label' => 'Estado',
                    'required' => true,
                    'value' => $record->estado ?? '1',
                    'options' => [
                        '1' => 'Activo',
                        '0' => 'Inactivo',
                    ],
                    'helpText' => 'Selecciona el estado de la regla.',
                ],
                [
                    'name' => 'condicion',
                    'type' => 'text',
                    'label' => 'Condición',
                    'required' => false,
                    'maxlength' => 45,
                    'minlength' => 0,
                    'helpText' => 'Condición opcional de la regla.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('sistema.flujoregla', (string) $id));
    }

    public function flujoReglasUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('flujoregla')->where('idflujoregla', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.sistema.flujo-reglas.index')
                ->with('error', 'No se encontro la regla de flujo solicitada.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'sistema.flujoregla', (string) $id, 'flujo regla', 'modules.sistema.flujo-reglas.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'flujo_idflujo' => ['required', 'integer', 'exists:flujo,idflujo'],
            'vista_idvista' => ['required', 'integer', 'exists:vista,idvista'],
            'orden' => ['required', 'integer', 'min:1'],
            'estado' => ['required', 'in:0,1'],
            'condicion' => ['nullable', 'string', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        DB::table('flujoregla')->where('idflujoregla', $id)->update($validated);
        $this->publishResourceEvent('sistema.flujoregla', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'sistema.flujoregla', (string) $id);

        return redirect()
            ->route('modules.sistema.flujo-reglas.index')
            ->with('success', 'Flujo regla actualizada correctamente.');
    }

    public function flujoReglasDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'sistema.flujoregla', (string) $id, 'flujo regla', 'modules.sistema.flujo-reglas.index')) {
            return $redirect;
        }

        try {
            DB::table('flujoregla')->where('idflujoregla', $id)->delete();
            $this->publishResourceEvent('sistema.flujoregla', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'sistema.flujoregla', (string) $id);

            return redirect()
                ->route('modules.sistema.flujo-reglas.index')
                ->with('success', 'Flujo regla eliminada correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.sistema.flujo-reglas.index')
                ->with('error', 'No se puede eliminar la regla porque tiene registros relacionados.');
        }
    }

    public function flujoReglasBulkDestroy(Request $request): RedirectResponse
    {
        $selectedIds = $request->input('selectedIds', []);
        if (!is_array($selectedIds)) {
            $selectedIds = [];
        }

        $selectedIds = array_filter(array_map('intval', $selectedIds), fn ($id) => $id > 0);
        if (empty($selectedIds)) {
            return redirect()
                ->route('modules.sistema.flujo-reglas.index')
                ->with('error', 'No se seleccionaron registros para eliminar.');
        }

        foreach ($selectedIds as $selectedId) {
            if ($redirect = $this->assertLockAvailable($request, 'sistema.flujoregla', (string) $selectedId, 'flujo regla', 'modules.sistema.flujo-reglas.index')) {
                return $redirect;
            }
        }

        try {
            DB::transaction(function () use ($selectedIds, $request) {
                DB::table('flujoregla')->whereIn('idflujoregla', $selectedIds)->delete();

                foreach ($selectedIds as $selectedId) {
                    $this->publishResourceEvent('sistema.flujoregla', (string) $selectedId, 'deleted');
                    $this->releaseLockIfOwned($request, 'sistema.flujoregla', (string) $selectedId);
                }
            });

            return redirect()
                ->route('modules.sistema.flujo-reglas.index')
                ->with('success', 'Reglas de flujo eliminadas correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.sistema.flujo-reglas.index')
                ->with('error', 'No se pueden eliminar las reglas porque tienen registros relacionados.');
        }
    }

    public function flujoReglasExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $baseQuery = DB::table('flujoregla')
            ->leftJoin('flujo', 'flujoregla.flujo_idflujo', '=', 'flujo.idflujo')
            ->leftJoin('vista', 'flujoregla.vista_idvista', '=', 'vista.idvista')
            ->select(
                'flujoregla.*',
                DB::raw("COALESCE(CONCAT(flujo.idflujo, ' - ', flujo.nombre), 'Sin flujo') as flujo"),
                DB::raw("COALESCE(CONCAT(vista.idvista, ' - ', vista.nombre), 'Sin vista') as vista")
            );

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('flujoregla.idflujoregla', 'like', $term)
                    ->orWhere('flujoregla.orden', 'like', $term)
                    ->orWhere('flujoregla.estado', 'like', $term)
                    ->orWhere('flujoregla.condicion', 'like', $term)
                    ->orWhere('flujo.nombre', 'like', $term)
                    ->orWhere('vista.nombre', 'like', $term);
            });
        }

        $rows = $baseQuery
            ->orderBy('flujoregla.idflujoregla')
            ->get();

        $columns = [
            ['key' => 'idflujoregla', 'label' => 'ID'],
            ['key' => 'flujo', 'label' => 'Flujo'],
            ['key' => 'vista', 'label' => 'Vista'],
            ['key' => 'orden', 'label' => 'Orden'],
            ['key' => 'estado', 'label' => 'Estado'],
            ['key' => 'condicion', 'label' => 'Condición'],
        ];

        $filename = 'flujo_regla_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Flujo Reglas', $filename);
    }

    public function historialFlujosIndex(Request $request): View
    {
        $baseQuery = DB::table('historialflujo')
            ->leftJoin('usuario', 'historialflujo.usuario_usuario', '=', 'usuario.usuario')
            ->leftJoin('ticket', 'historialflujo.ticket_idticket', '=', 'ticket.idticket')
            ->leftJoin('flujoregla', 'historialflujo.flujoregla_idflujoregla', '=', 'flujoregla.idflujoregla')
            ->leftJoin('vista', 'historialflujo.vista_idvista', '=', 'vista.idvista')
            ->select(
                'historialflujo.*',
                'usuario.usuario as usuario',
                DB::raw("COALESCE(CONCAT(ticket.idticket, ' - ', COALESCE(ticket.detalle, ticket.pedidoReferencia, '')), 'Sin ticket') as ticket"),
                DB::raw("COALESCE(CONCAT('Orden ', COALESCE(flujoregla.orden, '-'), ' - ', COALESCE(flujoregla.condicion, 'Sin condición')), 'Sin regla') as flujoregla"),
                DB::raw("COALESCE(CONCAT(vista.idvista, ' - ', vista.nombre), 'Sin vista') as vista")
            );

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('historialflujo.idhistorialflujo', 'like', $term)
                    ->orWhere('historialflujo.usuario_usuario', 'like', $term)
                    ->orWhere('historialflujo.ticket_idticket', 'like', $term)
                    ->orWhere('historialflujo.flujoregla_idflujoregla', 'like', $term)
                    ->orWhere('historialflujo.vista_idvista', 'like', $term)
                    ->orWhere('historialflujo.detalle', 'like', $term)
                    ->orWhere('historialflujo.resultado', 'like', $term)
                    ->orWhere('historialflujo.fechaejecucion', 'like', $term)
                    ->orWhere('usuario.usuario', 'like', $term)
                    ->orWhere('ticket.detalle', 'like', $term)
                    ->orWhere('vista.nombre', 'like', $term)
                    ->orWhere('flujoregla.condicion', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('historialflujo.idhistorialflujo')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $items->through(function ($row) {
            if (isset($row->fechaejecucion)) {
                $row->fechaejecucion = self::formatDateTimeForList((string) $row->fechaejecucion);
            }

            return $row;
        });

        return view('sistema.historialflujo', [
            'title' => 'Sistema: Historial Flujo',
            'singularTitle' => 'Historial Flujo',
            'items' => $items,
            'columns' => [
                ['key' => 'idhistorialflujo', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'usuario', 'label' => 'Usuario', 'type' => 'text'],
                ['key' => 'ticket', 'label' => 'Ticket', 'type' => 'text'],
                ['key' => 'flujoregla', 'label' => 'Regla', 'type' => 'text'],
                ['key' => 'resultado', 'label' => 'Resultado', 'type' => 'text'],
                ['key' => 'fechaejecucion', 'label' => 'Fecha ejecución', 'type' => 'text'],
            ],
            'showActionsColumn' => false,
            'exportRoutes' => [
                'pdf' => route('modules.sistema.historial-flujos.export', ['format' => 'pdf']),
                'xlsx' => route('modules.sistema.historial-flujos.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de registros de historial', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => [],
            'identifierKey' => 'idhistorialflujo',
            'lockResource' => 'sistema.historialflujo',
        ]);
    }

   
    public function historialFlujosExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $baseQuery = DB::table('historialflujo')
            ->leftJoin('usuario', 'historialflujo.usuario_usuario', '=', 'usuario.usuario')
            ->leftJoin('ticket', 'historialflujo.ticket_idticket', '=', 'ticket.idticket')
            ->leftJoin('flujoregla', 'historialflujo.flujoregla_idflujoregla', '=', 'flujoregla.idflujoregla')
            ->leftJoin('vista', 'historialflujo.vista_idvista', '=', 'vista.idvista')
            ->select(
                'historialflujo.*',
                'usuario.usuario as usuario',
                DB::raw("COALESCE(CONCAT(ticket.idticket, ' - ', COALESCE(ticket.detalle, ticket.pedidoReferencia, '')), 'Sin ticket') as ticket"),
                DB::raw("COALESCE(CONCAT('Orden ', COALESCE(flujoregla.orden, '-'), ' - ', COALESCE(flujoregla.condicion, 'Sin condición')), 'Sin regla') as flujoregla"),
                DB::raw("COALESCE(CONCAT(vista.idvista, ' - ', vista.nombre), 'Sin vista') as vista")
            );

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('historialflujo.idhistorialflujo', 'like', $term)
                    ->orWhere('historialflujo.usuario_usuario', 'like', $term)
                    ->orWhere('historialflujo.ticket_idticket', 'like', $term)
                    ->orWhere('historialflujo.flujoregla_idflujoregla', 'like', $term)
                    ->orWhere('historialflujo.vista_idvista', 'like', $term)
                    ->orWhere('historialflujo.detalle', 'like', $term)
                    ->orWhere('historialflujo.resultado', 'like', $term)
                    ->orWhere('historialflujo.fechaejecucion', 'like', $term)
                    ->orWhere('usuario.usuario', 'like', $term)
                    ->orWhere('ticket.detalle', 'like', $term)
                    ->orWhere('vista.nombre', 'like', $term)
                    ->orWhere('flujoregla.condicion', 'like', $term);
            });
        }

        $rows = $baseQuery
            ->orderBy('historialflujo.idhistorialflujo')
            ->get();

        $columns = [
            ['key' => 'idhistorialflujo', 'label' => 'ID'],
            ['key' => 'usuario', 'label' => 'Usuario'],
            ['key' => 'ticket', 'label' => 'Ticket'],
            ['key' => 'flujoregla', 'label' => 'Regla'],
            ['key' => 'vista', 'label' => 'Vista'],
            ['key' => 'detalle', 'label' => 'Detalle'],
            ['key' => 'resultado', 'label' => 'Resultado'],
            ['key' => 'fechaejecucion', 'label' => 'Fecha ejecución'],
        ];

        $filename = 'historial_flujo_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Historiales de Flujo', $filename);
    }

    private static function formatDateTimeForList(string $value): string
    {
        try {
            return Carbon::parse($value)->locale('es')->translatedFormat('d M Y, H:i');
        } catch (\Throwable) {
            return $value;
        }
    }

    private static function formatDateTimeForForm(string $value): string
    {
        if (trim($value) === '') {
            return '';
        }

        try {
            if (str_contains($value, 'T')) {
                return Carbon::createFromFormat('Y-m-d\TH:i', $value)->format('Y-m-d\TH:i');
            }

            return Carbon::parse($value)->format('Y-m-d\TH:i');
        } catch (\Throwable) {
            return '';
        }
    }

    private static function normalizeDateTimeInput(?string $value): ?string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        try {
            if (strlen($trimmed) === 16) {
                return Carbon::createFromFormat('Y-m-d\TH:i', $trimmed)->format('Y-m-d H:i:s');
            }

            return Carbon::parse($trimmed)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
