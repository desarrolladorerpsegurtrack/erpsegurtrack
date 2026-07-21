<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Export\ExportableList;
use App\Support\ResourceLock;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Illuminate\Support\Carbon;
use App\Services\CorrelativoService;

class ConfiguracionController extends Controller
{
    use ExportableList;

    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';
    private const CARGO_LOCK_RESOURCE = 'personal.cargo';

    public function index(): RedirectResponse
    {
        return redirect()->route('modules.configuracion.estados.index');
    }

    public function estadosIndex(Request $request): View
    {
        $baseQuery = DB::table('estadocliente');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idestadoCliente', 'like', $term)
                    ->orWhere('detalle', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('idestadoCliente')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.estadocliente.estadocliente', [
            'title' => 'Configuracion: Estado Cliente',
            'singularTitle' => 'Estado Cliente',
            'items' => $items,
            'columns' => [
                ['key' => 'idestadoCliente', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'detalle', 'label' => 'Detalle', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.estados.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.estados.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de estados', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.estados.create'),
            'editRoute' => 'modules.configuracion.estados.edit',
            'showRoute' => 'modules.configuracion.estados.edit',
            'destroyRoute' => 'modules.configuracion.estados.destroy',
            'bulkDestroyRoute' => route('modules.configuracion.estados.bulk-destroy'),
            'identifierKey' => 'idestadoCliente',
        ]);
    }

    public function estadosCreate(): View
    {
        return view('configuracion.estadocliente.estadocliente-form', [
            'title' => 'Nuevo Estado Cliente',
            'moduleTitle' => 'Configuracion: Estado Cliente',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.estados.store'),
            'backRoute' => route('modules.configuracion.estados.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => true,
                    'maxlength' => 20,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function estadosStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'detalle' => ['required', 'string', 'min:2', 'max:20', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        $newId = DB::table('estadocliente')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.estado_cliente', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.estados.index')
            ->with('success', 'Estado de cliente creado correctamente.');
    }

    public function estadosEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('estadocliente')->where('idestadoCliente', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.estados.index')
                ->with('error', 'No se encontro el estado de cliente solicitado.');
        }

        return view('configuracion.estadocliente.estadocliente-form', [
            'title' => 'Editar Estado Cliente',
            'moduleTitle' => 'Configuracion: Estado Cliente',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.estados.update', $id),
            'backRoute' => route('modules.configuracion.estados.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => true,
                    'maxlength' => 20,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.estado_cliente', (string) $id));
    }

    public function estadosUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('estadocliente')->where('idestadoCliente', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.estados.index')
                ->with('error', 'No se encontro el estado de cliente solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.estado_cliente', (string) $id, 'estado de cliente', 'modules.configuracion.estados.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'detalle' => ['required', 'string', 'min:2', 'max:20', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        DB::table('estadocliente')->where('idestadoCliente', $id)->update($validated);
        $this->publishResourceEvent('configuracion.estado_cliente', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.estado_cliente', (string) $id);

        return redirect()
            ->route('modules.configuracion.estados.index')
            ->with('success', 'Estado de cliente actualizado correctamente.');
    }

    public function estadosDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.estado_cliente', (string) $id, 'estado de cliente', 'modules.configuracion.estados.index')) {
            return $redirect;
        }

        try {
            DB::table('estadocliente')->where('idestadoCliente', $id)->delete();
            $this->publishResourceEvent('configuracion.estado_cliente', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.estado_cliente', (string) $id);

            return redirect()
                ->route('modules.configuracion.estados.index')
                ->with('success', 'Estado de cliente eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.estados.index')
                ->with('error', 'No se puede eliminar el estado de cliente porque tiene registros relacionados.');
        }
    }

    public function estadosBulkDestroy(Request $request): RedirectResponse
    {
        $selectedIds = $request->input('selectedIds', []);
        if (!is_array($selectedIds)) {
            $selectedIds = [];
        }

        $selectedIds = array_filter(array_map('intval', $selectedIds), fn ($id) => $id > 0);
        if (empty($selectedIds)) {
            return redirect()
                ->route('modules.configuracion.estados.index')
                ->with('error', 'No se seleccionaron registros para eliminar.');
        }

        foreach ($selectedIds as $selectedId) {
            if ($redirect = $this->assertLockAvailable($request, 'configuracion.estado_cliente', (string) $selectedId, 'estado de cliente', 'modules.configuracion.estados.index')) {
                return $redirect;
            }
        }

        try {
            DB::transaction(function () use ($selectedIds, $request) {
                DB::table('estadocliente')->whereIn('idestadoCliente', $selectedIds)->delete();

                foreach ($selectedIds as $selectedId) {
                    $this->publishResourceEvent('configuracion.estado_cliente', (string) $selectedId, 'deleted');
                    $this->releaseLockIfOwned($request, 'configuracion.estado_cliente', (string) $selectedId);
                }
            });

            $count = count($selectedIds);
            return redirect()
                ->route('modules.configuracion.estados.index')
                ->with('success', "Se eliminaron {$count} registro(s) correctamente.");
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.estados.index')
                ->with('error', 'No se pueden eliminar los estados de cliente porque tienen registros relacionados.');
        }
    }

    public function estadosExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('estadocliente');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idestadoCliente', 'like', $term)
                    ->orWhere('detalle', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idestadoCliente', 'label' => 'ID'],
            ['key' => 'detalle', 'label' => 'Detalle'],
        ];

        $filename = 'estado_cliente_export_' . now()->format('Ymd_His') . '.' . $format;

         if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('idestadoCliente', array_values($selectedIds))->orderBy('idestadoCliente')->get();

            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }

            return $this->exportPdfResponse($rows, $columns, 'Listado de Estados de Cliente', $filename);
        }

        $rows = $baseQuery->orderBy('idestadoCliente')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Estados de Cliente', $filename);
    }

    public function tecnologiasExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('tecnologia');

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtecnologia', 'like', $term)
                    ->orWhere('nombreTecnologia', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idtecnologia', 'label' => 'ID'],
            ['key' => 'nombreTecnologia', 'label' => 'Nombre'],
        ];

        $filename = 'tecnologia_export_' . now()->format('Ymd_His') . '.' . $format;

         if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('idtecnologia', array_values($selectedIds))->orderBy('idtecnologia')->get();

            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }

            return $this->exportPdfResponse($rows, $columns, 'Listado de Tecnologias', $filename);
        }

        $rows = $baseQuery->orderBy('idtecnologia')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Tecnologias', $filename);
    }

    public function tiposGastoIndex(Request $request): View
    {
        $baseQuery = DB::table('tipogasto');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtipoGasto', 'like', $term)
                    ->orWhere('nombre', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('idtipoGasto')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.tipogasto.tipogasto', [
            'title' => 'Configuracion: Tipo de Gasto',
            'singularTitle' => 'Tipo de Gasto',
            'items' => $items,
            'columns' => [
                ['key' => 'idtipoGasto', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.tipos-gasto.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.tipos-gasto.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de tipos de gasto', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.tipos-gasto.create'),
            'editRoute' => 'modules.configuracion.tipos-gasto.edit',
            'showRoute' => 'modules.configuracion.tipos-gasto.edit',
            'destroyRoute' => 'modules.configuracion.tipos-gasto.destroy',
            'identifierKey' => 'idtipoGasto',
            'lockResource' => 'configuracion.tipo_gasto',
        ]);
    }

    public function tiposGastoCreate(): View
    {
        return view('configuracion.tipogasto.tipogasto-form', [
            'title' => 'Nuevo Tipo de Gasto',
            'moduleTitle' => 'Configuracion: Tipo de Gasto',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.tipos-gasto.store'),
            'backRoute' => route('modules.configuracion.tipos-gasto.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'nombre',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 45,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function tiposGastoStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        $newId = DB::table('tipogasto')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.tipo_gasto', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.tipos-gasto.index')
            ->with('success', 'Tipo de gasto creado correctamente.');
    }

    public function tiposGastoEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('tipogasto')->where('idtipoGasto', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.tipos-gasto.index')
                ->with('error', 'No se encontro el tipo de gasto solicitado.');
        }

        return view('configuracion.tipogasto.tipogasto-form', [
            'title' => 'Editar Tipo de Gasto',
            'moduleTitle' => 'Configuracion: Tipo de Gasto',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.tipos-gasto.update', $id),
            'backRoute' => route('modules.configuracion.tipos-gasto.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'nombre',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 45,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.tipo_gasto', (string) $id));
    }

    public function tiposGastoUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('tipogasto')->where('idtipoGasto', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.tipos-gasto.index')
                ->with('error', 'No se encontro el tipo de gasto solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tipo_gasto', (string) $id, 'tipo de gasto', 'modules.configuracion.tipos-gasto.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        DB::table('tipogasto')->where('idtipoGasto', $id)->update($validated);
        $this->publishResourceEvent('configuracion.tipo_gasto', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.tipo_gasto', (string) $id);

        return redirect()
            ->route('modules.configuracion.tipos-gasto.index')
            ->with('success', 'Tipo de gasto actualizado correctamente.');
    }

    public function tiposGastoDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tipo_gasto', (string) $id, 'tipo de gasto', 'modules.configuracion.tipos-gasto.index')) {
            return $redirect;
        }

        try {
            DB::table('tipogasto')->where('idtipoGasto', $id)->delete();
            $this->publishResourceEvent('configuracion.tipo_gasto', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.tipo_gasto', (string) $id);
            return redirect()
                ->route('modules.configuracion.tipos-gasto.index')
                ->with('success', 'Tipo de gasto eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.tipos-gasto.index')
                ->with('error', 'No se puede eliminar el tipo de gasto porque tiene registros relacionados.');
        }
    }

    public function tiposGastoExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('tipogasto');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtipoGasto', 'like', $term)
                    ->orWhere('nombre', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idtipoGasto', 'label' => 'ID'],
            ['key' => 'nombre', 'label' => 'Nombre'],
        ];

        $filename = 'tipo_gasto_export_' . now()->format('Ymd_His') . '.' . $format;

         if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('idtipoGasto', array_values($selectedIds))->orderBy('idtipoGasto')->get();

            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }

            return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Gasto', $filename);
        }

        $rows = $baseQuery->orderBy('idtipoGasto')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Gasto', $filename);
    }

    public function tiposContactoIndex(Request $request): View
    {
        $baseQuery = DB::table('tipocontacto');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtipoContacto', 'like', $term)
                    ->orWhere('detalle', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('idtipoContacto')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.tipocontacto.tipocontacto', [
            'title' => 'Configuracion: Tipo de Contacto',
            'singularTitle' => 'Tipo de Contacto',
            'items' => $items,
            'columns' => [
                ['key' => 'idtipoContacto', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'detalle', 'label' => 'Detalle', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.tipos-contacto.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.tipos-contacto.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de tipos de contacto', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.tipos-contacto.create'),
            'editRoute' => 'modules.configuracion.tipos-contacto.edit',
            'showRoute' => 'modules.configuracion.tipos-contacto.edit',
            'destroyRoute' => 'modules.configuracion.tipos-contacto.destroy',
            'identifierKey' => 'idtipoContacto',
            'lockResource' => 'configuracion.tipo_contacto',
        ]);
    }

    public function tiposContactoCreate(): View
    {
        return view('configuracion.tipocontacto.tipocontacto-form', [
            'title' => 'Nuevo Tipo de Contacto',
            'moduleTitle' => 'Configuracion: Tipo de Contacto',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.tipos-contacto.store'),
            'backRoute' => route('modules.configuracion.tipos-contacto.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function tiposContactoStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'detalle' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        $newId = DB::table('tipocontacto')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.tipo_contacto', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.tipos-contacto.index')
            ->with('success', 'Tipo de contacto creado correctamente.');
    }

    public function tiposContactoEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('tipocontacto')->where('idtipoContacto', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.tipos-contacto.index')
                ->with('error', 'No se encontro el tipo de contacto solicitado.');
        }

        return view('configuracion.tipocontacto.tipocontacto-form', [
            'title' => 'Editar Tipo de Contacto',
            'moduleTitle' => 'Configuracion: Tipo de Contacto',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.tipos-contacto.update', $id),
            'backRoute' => route('modules.configuracion.tipos-contacto.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.tipo_contacto', (string) $id));
    }

    public function tiposContactoUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('tipocontacto')->where('idtipoContacto', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.tipos-contacto.index')
                ->with('error', 'No se encontro el tipo de contacto solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tipo_contacto', (string) $id, 'tipo de contacto', 'modules.configuracion.tipos-contacto.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'detalle' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        DB::table('tipocontacto')->where('idtipoContacto', $id)->update($validated);
        $this->publishResourceEvent('configuracion.tipo_contacto', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.tipo_contacto', (string) $id);

        return redirect()
            ->route('modules.configuracion.tipos-contacto.index')
            ->with('success', 'Tipo de contacto actualizado correctamente.');
    }

    public function tiposContactoDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tipo_contacto', (string) $id, 'tipo de contacto', 'modules.configuracion.tipos-contacto.index')) {
            return $redirect;
        }

        try {
            DB::table('tipocontacto')->where('idtipoContacto', $id)->delete();
            $this->publishResourceEvent('configuracion.tipo_contacto', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.tipo_contacto', (string) $id);

            return redirect()
                ->route('modules.configuracion.tipos-contacto.index')
                ->with('success', 'Tipo de contacto eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.tipos-contacto.index')
                ->with('error', 'No se puede eliminar el tipo de contacto porque tiene registros relacionados.');
        }
    }

    public function tiposContactoExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('tipocontacto');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtipoContacto', 'like', $term)
                    ->orWhere('detalle', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idtipoContacto', 'label' => 'ID'],
            ['key' => 'detalle', 'label' => 'Detalle'],
        ];

        $filename = 'tipo_contacto_export_' . now()->format('Ymd_His') . '.' . $format;

         if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('idtipoContacto', array_values($selectedIds))->orderBy('idtipoContacto')->get();

            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }

            return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Contacto', $filename);
        }

        $rows = $baseQuery->orderBy('idtipoContacto')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Contacto', $filename);
    }

    public function tiposCobroIndex(Request $request): View
    {
        $baseQuery = DB::table('tipocobro');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtipoCobros', 'like', $term)
                    ->orWhere('nombre', 'like', $term)
                    ->orWhere('recurrencia', 'like', $term)
                    ->orWhere('tiempo', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('idtipoCobros')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.tipocobro.tipocobro', [
            'title' => 'Configuracion: Tipo de Cobro',
            'singularTitle' => 'Tipo de Cobro',
            'items' => $items,
            'columns' => [
                ['key' => 'idtipoCobros', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text'],
                ['key' => 'recurrencia', 'label' => 'Recurrencia', 'type' => 'text'],
                ['key' => 'tiempo', 'label' => 'Tiempo', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.tipos-cobro.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.tipos-cobro.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de tipos de cobro', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.tipos-cobro.create'),
            'editRoute' => 'modules.configuracion.tipos-cobro.edit',
            'showRoute' => 'modules.configuracion.tipos-cobro.edit',
            'destroyRoute' => 'modules.configuracion.tipos-cobro.destroy',
            'identifierKey' => 'idtipoCobros',
            'lockResource' => 'configuracion.tipo_cobro',
        ]);
    }

    public function tiposCobroCreate(): View
    {
        return view('configuracion.tipocobro.tipocobro-form', [
            'title' => 'Nuevo Tipo de Cobro',
            'moduleTitle' => 'Configuracion: Tipo de Cobro',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.tipos-cobro.store'),
            'backRoute' => route('modules.configuracion.tipos-cobro.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'nombre',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'recurrencia',
                    'type' => 'text',
                    'label' => 'Recurrencia',
                    'required' => false,
                    'maxlength' => 1,
                    'minlength' => 1,
                    'helpText' => 'Ej: D (diario), M (mensual).',
                ],
                [
                    'name' => 'tiempo',
                    'type' => 'number',
                    'label' => 'Tiempo',
                    'required' => false,
                    'min' => 0,
                    'helpText' => 'Tiempo asociado al cobro.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function tiposCobroStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'recurrencia' => ['nullable', 'string', 'min:1', 'max:1', 'regex:' . self::SAFE_TEXT_REGEX],
            'tiempo' => ['nullable', 'integer', 'min:0'],
        ]);

        $newId = DB::table('tipocobro')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.tipo_cobro', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.tipos-cobro.index')
            ->with('success', 'Tipo de cobro creado correctamente.');
    }

    public function tiposCobroEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('tipocobro')->where('idtipoCobros', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.tipos-cobro.index')
                ->with('error', 'No se encontro el tipo de cobro solicitado.');
        }

        return view('configuracion.tipocobro.tipocobro-form', [
            'title' => 'Editar Tipo de Cobro',
            'moduleTitle' => 'Configuracion: Tipo de Cobro',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.tipos-cobro.update', $id),
            'backRoute' => route('modules.configuracion.tipos-cobro.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'nombre',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'recurrencia',
                    'type' => 'text',
                    'label' => 'Recurrencia',
                    'required' => false,
                    'maxlength' => 1,
                    'minlength' => 1,
                    'helpText' => 'Ej: D (diario), M (mensual).',
                ],
                [
                    'name' => 'tiempo',
                    'type' => 'number',
                    'label' => 'Tiempo',
                    'required' => false,
                    'min' => 0,
                    'helpText' => 'Tiempo asociado al cobro.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.tipo_cobro', (string) $id));
    }

    public function tiposCobroUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('tipocobro')->where('idtipoCobros', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.tipos-cobro.index')
                ->with('error', 'No se encontro el tipo de cobro solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tipo_cobro', (string) $id, 'tipo de cobro', 'modules.configuracion.tipos-cobro.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'recurrencia' => ['nullable', 'string', 'min:1', 'max:1', 'regex:' . self::SAFE_TEXT_REGEX],
            'tiempo' => ['nullable', 'integer', 'min:0'],
        ]);

        DB::table('tipocobro')->where('idtipoCobros', $id)->update($validated);
        $this->publishResourceEvent('configuracion.tipo_cobro', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.tipo_cobro', (string) $id);

        return redirect()
            ->route('modules.configuracion.tipos-cobro.index')
            ->with('success', 'Tipo de cobro actualizado correctamente.');
    }

    public function tiposCobroDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tipo_cobro', (string) $id, 'tipo de cobro', 'modules.configuracion.tipos-cobro.index')) {
            return $redirect;
        }

        try {
            DB::table('tipocobro')->where('idtipoCobros', $id)->delete();
            $this->publishResourceEvent('configuracion.tipo_cobro', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.tipo_cobro', (string) $id);
            return redirect()
                ->route('modules.configuracion.tipos-cobro.index')
                ->with('success', 'Tipo de cobro eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.tipos-cobro.index')
                ->with('error', 'No se puede eliminar el tipo de cobro porque tiene registros relacionados.');
        }
    }

    public function tiposCobroExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('tipocobro');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtipoCobros', 'like', $term)
                    ->orWhere('nombre', 'like', $term)
                    ->orWhere('recurrencia', 'like', $term)
                    ->orWhere('tiempo', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idtipoCobros', 'label' => 'ID'],
            ['key' => 'nombre', 'label' => 'Nombre'],
            ['key' => 'recurrencia', 'label' => 'Recurrencia'],
            ['key' => 'tiempo', 'label' => 'Tiempo'],
        ];

        $filename = 'tipo_cobro_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('idtipoCobros', array_values($selectedIds))->orderBy('idtipoCobros')->get();
    
            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }
    
            return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Cobro', $filename);
        }    

        $rows = $baseQuery->orderBy('idtipoCobros')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Cobro', $filename);
    }

    public function unidadMedidasIndex(Request $request): View
    {
        $baseQuery = DB::table('unidadmedida');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idunidadMedida', 'like', $term)
                    ->orWhere('detalle', 'like', $term)
                    ->orWhere('nomenclatura', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('idunidadMedida')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.unidadmedida.unidadmedida', [
            'title' => 'Configuracion: Unidad de medida',
            'singularTitle' => 'Unidad de medida',
            'items' => $items,
            'columns' => [
                ['key' => 'idunidadMedida', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'detalle', 'label' => 'Detalle', 'type' => 'text'],
                ['key' => 'nomenclatura', 'label' => 'Nomenclatura', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.unidad-medida.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.unidad-medida.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de unidades de medida', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.unidad-medida.create'),
            'editRoute' => 'modules.configuracion.unidad-medida.edit',
            'showRoute' => 'modules.configuracion.unidad-medida.edit',
            'destroyRoute' => 'modules.configuracion.unidad-medida.destroy',
            'identifierKey' => 'idunidadMedida',
            'lockResource' => 'configuracion.unidad_medida',
        ]);
    }

    public function unidadMedidasCreate(): View
    {
        return view('configuracion.unidadmedida.unidadmedida-form', [
            'title' => 'Nueva Unidad de medida',
            'moduleTitle' => 'Configuracion: Unidad de medida',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.unidad-medida.store'),
            'backRoute' => route('modules.configuracion.unidad-medida.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => true,
                    'maxlength' => 30,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'nomenclatura',
                    'type' => 'text',
                    'label' => 'Nomenclatura',
                    'required' => true,
                    'maxlength' => 3,
                    'minlength' => 1,
                    'helpText' => 'Mínimo 1 carácter.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function unidadMedidasStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'detalle' => ['required', 'string', 'min:2', 'max:30', 'regex:' . self::SAFE_TEXT_REGEX],
            'nomenclatura' => ['required', 'string', 'min:1', 'max:3', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        $newId = DB::table('unidadmedida')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.unidad_medida', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.unidad-medida.index')
            ->with('success', 'Unidad de medida creada correctamente.');
    }

    public function unidadMedidasEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('unidadmedida')->where('idunidadMedida', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.unidad-medida.index')
                ->with('error', 'No se encontro la unidad de medida solicitada.');
        }

        return view('configuracion.unidadmedida.unidadmedida-form', [
            'title' => 'Editar Unidad de medida',
            'moduleTitle' => 'Configuracion: Unidad de medida',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.unidad-medida.update', $id),
            'backRoute' => route('modules.configuracion.unidad-medida.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => true,
                    'maxlength' => 30,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'nomenclatura',
                    'type' => 'text',
                    'label' => 'Nomenclatura',
                    'required' => true,
                    'maxlength' => 3,
                    'minlength' => 1,
                    'helpText' => 'Mínimo 1 carácter.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.unidad_medida', (string) $id));
    }

    public function unidadMedidasUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('unidadmedida')->where('idunidadMedida', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.unidad-medida.index')
                ->with('error', 'No se encontro la unidad de medida solicitada.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.unidad_medida', (string) $id, 'unidad de medida', 'modules.configuracion.unidad-medida.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'detalle' => ['required', 'string', 'min:2', 'max:30', 'regex:' . self::SAFE_TEXT_REGEX],
            'nomenclatura' => ['required', 'string', 'min:1', 'max:3', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        DB::table('unidadmedida')->where('idunidadMedida', $id)->update($validated);
        $this->publishResourceEvent('configuracion.unidad_medida', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.unidad_medida', (string) $id);

        return redirect()
            ->route('modules.configuracion.unidad-medida.index')
            ->with('success', 'Unidad de medida actualizada correctamente.');
    }

    public function unidadMedidasDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.unidad_medida', (string) $id, 'unidad de medida', 'modules.configuracion.unidad-medida.index')) {
            return $redirect;
        }

        try {
            DB::table('unidadmedida')->where('idunidadMedida', $id)->delete();
            $this->publishResourceEvent('configuracion.unidad_medida', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.unidad_medida', (string) $id);
            return redirect()
                ->route('modules.configuracion.unidad-medida.index')
                ->with('success', 'Unidad de medida eliminada correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.unidad-medida.index')
                ->with('error', 'No se puede eliminar la unidad de medida porque tiene registros relacionados.');
        }
    }

    public function unidadMedidasExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('unidadmedida');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idunidadMedida', 'like', $term)
                    ->orWhere('detalle', 'like', $term)
                    ->orWhere('nomenclatura', 'like', $term);
            });
        }

        

        $columns = [
            ['key' => 'idunidadMedida', 'label' => 'ID'],
            ['key' => 'detalle', 'label' => 'Detalle'],
            ['key' => 'nomenclatura', 'label' => 'Nomenclatura'],
        ];

        $filename = 'unidad_medida_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('idunidadMedida', array_values($selectedIds))->orderBy('idunidadMedida')->get();

            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }

            return $this->exportPdfResponse($rows, $columns, 'Listado de Unidades de Medida', $filename);
        }

        $rows = $baseQuery->orderBy('idunidadMedida')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Unidades de Medida', $filename);
    }

    public function monedasIndex(Request $request): View
    {
        $baseQuery = DB::table('moneda');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idmoneda', 'like', $term)
                    ->orWhere('detalle', 'like', $term)
                    ->orWhere('simbolo', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('idmoneda')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.moneda.moneda', [
            'title' => 'Configuracion: Moneda',
            'singularTitle' => 'Moneda',
            'items' => $items,
            'columns' => [
                ['key' => 'idmoneda', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'detalle', 'label' => 'Detalle', 'type' => 'text'],
                ['key' => 'simbolo', 'label' => 'Símbolo', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.monedas.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.monedas.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de monedas', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.monedas.create'),
            'editRoute' => 'modules.configuracion.monedas.edit',
            'showRoute' => 'modules.configuracion.monedas.edit',
            'destroyRoute' => 'modules.configuracion.monedas.destroy',
            'identifierKey' => 'idmoneda',
            'lockResource' => 'configuracion.moneda',
        ]);
    }

    public function monedasCreate(): View
    {
        return view('configuracion.moneda.moneda-form', [
            'title' => 'Nueva Moneda',
            'moduleTitle' => 'Configuracion: Moneda',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.monedas.store'),
            'backRoute' => route('modules.configuracion.monedas.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'simbolo',
                    'type' => 'text',
                    'label' => 'Símbolo',
                    'required' => true,
                    'maxlength' => 3,
                    'minlength' => 1,
                    'helpText' => 'Mínimo 1 carácter.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function monedasStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'detalle' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'simbolo' => ['required', 'string', 'min:1', 'max:3', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        $newId = DB::table('moneda')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.moneda', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.monedas.index')
            ->with('success', 'Moneda creada correctamente.');
    }

    public function monedasEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('moneda')->where('idmoneda', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.monedas.index')
                ->with('error', 'No se encontro la moneda solicitada.');
        }

        return view('configuracion.moneda.moneda-form', [
            'title' => 'Editar Moneda',
            'moduleTitle' => 'Configuracion: Moneda',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.monedas.update', $id),
            'backRoute' => route('modules.configuracion.monedas.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'simbolo',
                    'type' => 'text',
                    'label' => 'Símbolo',
                    'required' => true,
                    'maxlength' => 3,
                    'minlength' => 1,
                    'helpText' => 'Mínimo 1 carácter.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.moneda', (string) $id));
    }

    public function monedasUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('moneda')->where('idmoneda', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.monedas.index')
                ->with('error', 'No se encontro la moneda solicitada.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.moneda', (string) $id, 'moneda', 'modules.configuracion.monedas.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'detalle' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'simbolo' => ['required', 'string', 'min:1', 'max:3', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        DB::table('moneda')->where('idmoneda', $id)->update($validated);
        $this->publishResourceEvent('configuracion.moneda', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.moneda', (string) $id);

        return redirect()
            ->route('modules.configuracion.monedas.index')
            ->with('success', 'Moneda actualizada correctamente.');
    }

    public function monedasDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.moneda', (string) $id, 'moneda', 'modules.configuracion.monedas.index')) {
            return $redirect;
        }

        try {
            DB::table('moneda')->where('idmoneda', $id)->delete();
            $this->publishResourceEvent('configuracion.moneda', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.moneda', (string) $id);
            return redirect()
                ->route('modules.configuracion.monedas.index')
                ->with('success', 'Moneda eliminada correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.monedas.index')
                ->with('error', 'No se puede eliminar la moneda porque tiene registros relacionados.');
        }
    }

    public function monedasExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('moneda');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idmoneda', 'like', $term)
                    ->orWhere('detalle', 'like', $term)
                    ->orWhere('simbolo', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idmoneda', 'label' => 'ID'],
            ['key' => 'detalle', 'label' => 'Detalle'],
            ['key' => 'simbolo', 'label' => 'Símbolo'],
        ];

        $filename = 'moneda_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('idmoneda', array_values($selectedIds))->orderBy('idmoneda')->get();
    
            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }
    
            return $this->exportPdfResponse($rows, $columns, 'Listado de Monedas', $filename);
        }

        $rows = $baseQuery->orderBy('idmoneda')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Monedas', $filename);
    }

    public function marcasIndex(Request $request): View
    {
        $baseQuery = DB::table('marca');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idmarca', 'like', $term)
                    ->orWhere('nombreMarca', 'like', $term)
                    ->orWhere('procedencia', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('idmarca')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.marca.marca', [
            'title' => 'Configuracion: Marca',
            'singularTitle' => 'Marca',
            'items' => $items,
            'columns' => [
                ['key' => 'idmarca', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'nombreMarca', 'label' => 'Nombre', 'type' => 'text'],
                ['key' => 'procedencia', 'label' => 'Procedencia', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.marcas.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.marcas.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de marcas', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.marcas.create'),
            'editRoute' => 'modules.configuracion.marcas.edit',
            'showRoute' => 'modules.configuracion.marcas.edit',
            'destroyRoute' => 'modules.configuracion.marcas.destroy',
            'identifierKey' => 'idmarca',
            'lockResource' => 'configuracion.marca',
        ]);
    }

    public function marcasCreate(): View
    {
        return view('configuracion.marca.marca-form', [
            'title' => 'Nueva Marca',
            'moduleTitle' => 'Configuracion: Marca',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.marcas.store'),
            'backRoute' => route('modules.configuracion.marcas.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'nombreMarca',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres. Entrega el nombre de la marca.',
                ],
                [
                    'name' => 'procedencia',
                    'type' => 'text',
                    'label' => 'Procedencia',
                    'required' => false,
                    'maxlength' => 45,
                    'minlength' => 1,
                    'helpText' => 'Opcional.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function marcasStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nombreMarca' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'procedencia' => ['nullable', 'string', 'min:1', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        $newId = DB::table('marca')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.marca', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.marcas.index')
            ->with('success', 'Marca creada correctamente.');
    }

    public function marcasEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('marca')->where('idmarca', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.marcas.index')
                ->with('error', 'No se encontro la marca solicitada.');
        }

        return view('configuracion.marca.marca-form', [
            'title' => 'Editar Marca',
            'moduleTitle' => 'Configuracion: Marca',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.marcas.update', $id),
            'backRoute' => route('modules.configuracion.marcas.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'nombreMarca',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres. Entrega el nombre de la marca.',
                ],
                [
                    'name' => 'procedencia',
                    'type' => 'text',
                    'label' => 'Procedencia',
                    'required' => false,
                    'maxlength' => 45,
                    'minlength' => 1,
                    'helpText' => 'Opcional.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.marca', (string) $id));
    }

    public function marcasUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('marca')->where('idmarca', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.marcas.index')
                ->with('error', 'No se encontro la marca solicitada.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.marca', (string) $id, 'marca', 'modules.configuracion.marcas.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'nombreMarca' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'procedencia' => ['nullable', 'string', 'min:1', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        DB::table('marca')->where('idmarca', $id)->update($validated);
        $this->publishResourceEvent('configuracion.marca', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.marca', (string) $id);

        return redirect()
            ->route('modules.configuracion.marcas.index')
            ->with('success', 'Marca actualizada correctamente.');
    }

    public function marcasDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.marca', (string) $id, 'marca', 'modules.configuracion.marcas.index')) {
            return $redirect;
        }

        try {
            DB::table('marca')->where('idmarca', $id)->delete();
            $this->publishResourceEvent('configuracion.marca', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.marca', (string) $id);
            return redirect()
                ->route('modules.configuracion.marcas.index')
                ->with('success', 'Marca eliminada correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.marcas.index')
                ->with('error', 'No se puede eliminar la marca porque tiene registros relacionados.');
        }
    }

    public function marcasExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('marca');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idmarca', 'like', $term)
                    ->orWhere('nombreMarca', 'like', $term)
                    ->orWhere('procedencia', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idmarca', 'label' => 'ID'],
            ['key' => 'nombreMarca', 'label' => 'Nombre'],
            ['key' => 'procedencia', 'label' => 'Procedencia'],
        ];

        $filename = 'marca_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('idmarca', array_values($selectedIds))->orderBy('idmarca')->get();
    
            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }
    
            return $this->exportPdfResponse($rows, $columns, 'Listado de Marcas', $filename);
        }

        $rows = $baseQuery->orderBy('idmarca')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Marcas', $filename);
    }

    public function empresapropietariaIndex(Request $request): View
    {
        $baseQuery = DB::table('empresapropietaria as ep')
            ->leftJoin('ubigeo as u', 'ep.ubigeo_idubigeo', '=', 'u.idubigeo')
            ->select('ep.*', DB::raw("CONCAT_WS(' - ', u.departamento, u.provincia, u.distrito) as ubigeo_label"));
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('ep.RUC', 'like', $term)
                    ->orWhere('ep.razonSocial', 'like', $term)
                    ->orWhere('ep.rubro', 'like', $term)
                    ->orWhere('ep.direccionFiscal', 'like', $term)
                    ->orWhere('u.departamento', 'like', $term)
                    ->orWhere('u.provincia', 'like', $term)
                    ->orWhere('u.distrito', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('ep.razonSocial')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.empresapropietaria.empresapropietaria', [
            'title' => 'Configuracion: Empresa Propietaria',
            'singularTitle' => 'Empresa Propietaria',
            'items' => $items,
            'columns' => [
                ['key' => 'RUC', 'label' => 'RUC', 'type' => 'text'],
                ['key' => 'razonSocial', 'label' => 'Razón social', 'type' => 'text'],
                ['key' => 'rubro', 'label' => 'Rubro', 'type' => 'text'],
                ['key' => 'direccionFiscal', 'label' => 'Dirección fiscal', 'type' => 'text'],
                ['key' => 'ubigeo_label', 'label' => 'Ubigeo', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.empresapropietaria.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.empresapropietaria.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de empresas propietarias', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.empresapropietaria.create'),
            'editRoute' => 'modules.configuracion.empresapropietaria.edit',
            'showRoute' => 'modules.configuracion.empresapropietaria.edit',
            'destroyRoute' => 'modules.configuracion.empresapropietaria.destroy',
            'bulkDestroyRoute' => route('modules.configuracion.empresapropietaria.bulk-destroy'),
            'identifierKey' => 'RUC',
            'lockResource' => 'configuracion.empresapropietaria',
        ]);
    }

    public function empresapropietariaCreate(): View
    {
        $ubigeos = DB::table('ubigeo')
            ->select('idubigeo', DB::raw("CONCAT_WS(' - ', departamento, provincia, distrito) as ubigeo_label"))
            ->orderBy('departamento')
            ->orderBy('provincia')
            ->orderBy('distrito')
            ->get();

        return view('configuracion.empresapropietaria.empresapropietaria-form', [
            'title' => 'Nueva Empresa Propietaria',
            'moduleTitle' => 'Configuracion: Empresa Propietaria',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.empresapropietaria.store'),
            'backRoute' => route('modules.configuracion.empresapropietaria.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'RUC',
                    'type' => 'text',
                    'label' => 'RUC',
                    'required' => true,
                    'maxlength' => 11,
                    'minlength' => 11,
                    'helpText' => 'Solo números, exactamente 11 dígitos',
                ],
                [
                    'name' => 'razonSocial',
                    'type' => 'text',
                    'label' => 'Razón social',
                    'required' => true,
                    'maxlength' => 200,
                    'minlength' => 2,
                    'helpText' => 'Nombre legal de la empresa. Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'rubro',
                    'type' => 'text',
                    'label' => 'Rubro',
                    'required' => false,
                    'maxlength' => 50,
                    'minlength' => 1,
                    'helpText' => 'Opcional.',
                ],
                [
                    'name' => 'direccionFiscal',
                    'type' => 'text',
                    'label' => 'Dirección fiscal',
                    'required' => false,
                    'maxlength' => 300,
                    'minlength' => 1,
                    'helpText' => 'Opcional.',
                ],
                [
                    'name' => 'ubigeo_idubigeo',
                    'type' => 'select',
                    'label' => 'Ubigeo',
                    'required' => true,
                    'optionsData' => $ubigeos,
                    'optionKey' => 'idubigeo',
                    'optionLabel' => 'ubigeo_label',
                    'tomSelect' => true,
                    'helpText' => 'Selecciona la ubicación fiscal.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function empresapropietariaStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'RUC' => ['required', 'integer'],
            'razonSocial' => ['required', 'string', 'min:2', 'max:200', 'regex:' . self::SAFE_TEXT_REGEX],
            'rubro' => ['nullable', 'string', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'direccionFiscal' => ['nullable', 'string', 'max:300', 'regex:' . self::SAFE_TEXT_REGEX],
            'ubigeo_idubigeo' => ['required', 'integer', 'exists:ubigeo,idubigeo'],
        ], [
            'RUC.integer' => 'El RUC debe ser un número entero.',
            'rubro.max' => 'El rubro no debe tener más de 50 caracteres.',
        ]
        );

        $validated['RUC'] = (int) $validated['RUC'];
        $validated['ubigeo_idubigeo'] = (int) $validated['ubigeo_idubigeo'];

        DB::table('empresapropietaria')->insert($validated);
        $this->publishResourceEvent('configuracion.empresapropietaria', (string) $validated['RUC'], 'created');

        return redirect()
            ->route('modules.configuracion.empresapropietaria.index')
            ->with('success', 'Empresa propietaria creada correctamente.');
    }

    public function empresapropietariaEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('empresapropietaria')->where('RUC', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.empresapropietaria.index')
                ->with('error', 'No se encontro la empresa propietaria solicitada.');
        }

        $ubigeos = DB::table('ubigeo')
            ->select('idubigeo', DB::raw("CONCAT_WS(' - ', departamento, provincia, distrito) as ubigeo_label"))
            ->orderBy('departamento')
            ->orderBy('provincia')
            ->orderBy('distrito')
            ->get();

        return view('configuracion.empresapropietaria.empresapropietaria-form', [
            'title' => 'Editar Empresa Propietaria',
            'moduleTitle' => 'Configuracion: Empresa Propietaria',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.empresapropietaria.update', $id),
            'backRoute' => route('modules.configuracion.empresapropietaria.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'RUC',
                    'type' => 'text',
                    'label' => 'RUC',
                    'required' => true,
                    'maxlength' => 11,
                    'minlength' => 11,
                    'helpText' => 'Solo números, exactamente 11 dígitos',
                ],
                [
                    'name' => 'razonSocial',
                    'type' => 'text',
                    'label' => 'Razón social',
                    'required' => true,
                    'maxlength' => 200,
                    'minlength' => 2,
                    'helpText' => 'Nombre legal de la empresa.',
                ],
                [
                    'name' => 'rubro',
                    'type' => 'text',
                    'label' => 'Rubro',
                    'required' => false,
                    'maxlength' => 50,
                    'minlength' => 1,
                    'helpText' => 'Opcional.',
                ],
                [
                    'name' => 'direccionFiscal',
                    'type' => 'text',
                    'label' => 'Dirección fiscal',
                    'required' => false,
                    'maxlength' => 300,
                    'minlength' => 1,
                    'helpText' => 'Opcional.',
                ],
                [
                    'name' => 'ubigeo_idubigeo',
                    'type' => 'select',
                    'label' => 'Ubigeo',
                    'required' => true,
                    'optionsData' => $ubigeos,
                    'optionKey' => 'idubigeo',
                    'optionLabel' => 'ubigeo_label',
                    'tomSelect' => true,
                    'helpText' => 'Selecciona la ubicación fiscal.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.empresapropietaria', (string) $id));
    }

    public function empresapropietariaUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('empresapropietaria')->where('RUC', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.empresapropietaria.index')
                ->with('error', 'No se encontro la empresa propietaria solicitada.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.empresapropietaria', (string) $id, 'empresa propietaria', 'modules.configuracion.empresapropietaria.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'RUC' => ['required', 'integer'],
            'razonSocial' => ['required', 'string', 'min:2', 'max:200', 'regex:' . self::SAFE_TEXT_REGEX],
            'rubro' => ['nullable', 'string', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'direccionFiscal' => ['nullable', 'string', 'max:300', 'regex:' . self::SAFE_TEXT_REGEX],
            'ubigeo_idubigeo' => ['required', 'integer', 'exists:ubigeo,idubigeo'],
        ], [
            'rubro.max' => 'El rubro no debe tener más de 50 caracteres.',
        ]);

        $validated['RUC'] = (int) $validated['RUC'];
        $validated['ubigeo_idubigeo'] = (int) $validated['ubigeo_idubigeo'];

        DB::table('empresapropietaria')->where('RUC', $id)->update($validated);
        $this->publishResourceEvent('configuracion.empresapropietaria', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.empresapropietaria', (string) $id);

        return redirect()
            ->route('modules.configuracion.empresapropietaria.index')
            ->with('success', 'Empresa propietaria actualizada correctamente.');
    }

    public function empresapropietariaDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.empresapropietaria', (string) $id, 'empresa propietaria', 'modules.configuracion.empresapropietaria.index')) {
            return $redirect;
        }

        try {
            DB::table('empresapropietaria')->where('RUC', $id)->delete();
            $this->publishResourceEvent('configuracion.empresapropietaria', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.empresapropietaria', (string) $id);
            return redirect()
                ->route('modules.configuracion.empresapropietaria.index')
                ->with('success', 'Empresa propietaria eliminada correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.empresapropietaria.index')
                ->with('error', 'No se puede eliminar la empresa propietaria porque tiene registros relacionados.');
        }
    }

    public function empresapropietariaExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('empresapropietaria as ep')
            ->leftJoin('ubigeo as u', 'ep.ubigeo_idubigeo', '=', 'u.idubigeo')
            ->select('ep.*', DB::raw("CONCAT_WS(' - ', u.departamento, u.provincia, u.distrito) as ubigeo_label"));
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('ep.RUC', 'like', $term)
                    ->orWhere('ep.razonSocial', 'like', $term)
                    ->orWhere('ep.rubro', 'like', $term)
                    ->orWhere('ep.direccionFiscal', 'like', $term)
                    ->orWhere('u.departamento', 'like', $term)
                    ->orWhere('u.provincia', 'like', $term)
                    ->orWhere('u.distrito', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'RUC', 'label' => 'RUC'],
            ['key' => 'razonSocial', 'label' => 'Razón social'],
            ['key' => 'rubro', 'label' => 'Rubro'],
            ['key' => 'direccionFiscal', 'label' => 'Dirección fiscal'],
            ['key' => 'ubigeo_label', 'label' => 'Ubigeo'],
        ];

        $filename = 'empresa_propietaria_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('ep.RUC', array_values($selectedIds))->orderBy('ep.razonSocial')->get();
    
            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }
    
            return $this->exportPdfResponse($rows, $columns, 'Listado de Empresas Propietarias', $filename);
        }

        $rows = $baseQuery->orderBy('ep.razonSocial')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Empresas Propietarias', $filename);
    }

    public function modeloIndex(Request $request): View
    {
        $baseQuery = DB::table('modelo as m')
            ->leftJoin('marca as ma', 'm.marca_idmarca', '=', 'ma.idmarca')
            ->select('m.*', 'ma.nombreMarca as marca_label');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('m.idmodelo', 'like', $term)
                    ->orWhere('m.nombreModelo', 'like', $term)
                    ->orWhere('ma.nombreMarca', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('m.idmodelo')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.modelo.modelo', [
            'title' => 'Configuracion: Modelo',
            'singularTitle' => 'Modelo',
            'items' => $items,
            'columns' => [
                ['key' => 'idmodelo', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'nombreModelo', 'label' => 'Nombre', 'type' => 'text'],
                ['key' => 'marca_label', 'label' => 'Marca', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.modelo.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.modelo.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de modelos', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.modelo.create'),
            'editRoute' => 'modules.configuracion.modelo.edit',
            'showRoute' => 'modules.configuracion.modelo.edit',
            'destroyRoute' => 'modules.configuracion.modelo.destroy',
            'identifierKey' => 'idmodelo',
            'lockResource' => 'configuracion.modelo',
        ]);
    }

    public function modeloCreate(): View
    {
        $marcas = DB::table('marca')->orderBy('nombreMarca')->get();

        return view('configuracion.modelo.modelo-form', [
            'title' => 'Nuevo Modelo',
            'moduleTitle' => 'Configuracion: Modelo',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.modelo.store'),
            'backRoute' => route('modules.configuracion.modelo.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'nombreModelo',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Nombre del modelo.',
                ],
                [
                    'name' => 'marca_idmarca',
                    'type' => 'select',
                    'label' => 'Marca',
                    'required' => true,
                    'optionsData' => $marcas,
                    'optionKey' => 'idmarca',
                    'optionLabel' => 'nombreMarca',
                    'tomSelect' => true,
                    'helpText' => 'Selecciona la marca asociada.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function modeloStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nombreModelo' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'marca_idmarca' => ['required', 'integer', 'exists:marca,idmarca'],
        ]);

        $validated['marca_idmarca'] = (int) $validated['marca_idmarca'];

        $newId = DB::table('modelo')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.modelo', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.modelo.index')
            ->with('success', 'Modelo creado correctamente.');
    }

    public function modeloEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('modelo')->where('idmodelo', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.modelo.index')
                ->with('error', 'No se encontro el modelo solicitado.');
        }

        $marcas = DB::table('marca')->orderBy('nombreMarca')->get();

        return view('configuracion.modelo.modelo-form', [
            'title' => 'Editar Modelo',
            'moduleTitle' => 'Configuracion: Modelo',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.modelo.update', $id),
            'backRoute' => route('modules.configuracion.modelo.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'nombreModelo',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Nombre del modelo.',
                ],
                [
                    'name' => 'marca_idmarca',
                    'type' => 'select',
                    'label' => 'Marca',
                    'required' => true,
                    'optionsData' => $marcas,
                    'optionKey' => 'idmarca',
                    'optionLabel' => 'nombreMarca',
                    'tomSelect' => true,
                    'helpText' => 'Selecciona la marca asociada.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.modelo', (string) $id));
    }

    public function modeloUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('modelo')->where('idmodelo', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.modelo.index')
                ->with('error', 'No se encontro el modelo solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.modelo', (string) $id, 'modelo', 'modules.configuracion.modelo.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'nombreModelo' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'marca_idmarca' => ['required', 'integer', 'exists:marca,idmarca'],
        ]);

        $validated['marca_idmarca'] = (int) $validated['marca_idmarca'];

        DB::table('modelo')->where('idmodelo', $id)->update($validated);
        $this->publishResourceEvent('configuracion.modelo', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.modelo', (string) $id);

        return redirect()
            ->route('modules.configuracion.modelo.index')
            ->with('success', 'Modelo actualizado correctamente.');
    }

    public function modeloDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.modelo', (string) $id, 'modelo', 'modules.configuracion.modelo.index')) {
            return $redirect;
        }

        try {
            DB::table('modelo')->where('idmodelo', $id)->delete();
            $this->publishResourceEvent('configuracion.modelo', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.modelo', (string) $id);
            return redirect()
                ->route('modules.configuracion.modelo.index')
                ->with('success', 'Modelo eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.modelo.index')
                ->with('error', 'No se puede eliminar el modelo porque tiene registros relacionados.');
        }
    }

    public function modeloExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('modelo as m')
            ->leftJoin('marca as ma', 'm.marca_idmarca', '=', 'ma.idmarca')
            ->select('m.*', 'ma.nombreMarca as marca_label');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('m.idmodelo', 'like', $term)
                    ->orWhere('m.nombreModelo', 'like', $term)
                    ->orWhere('ma.nombreMarca', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idmodelo', 'label' => 'ID'],
            ['key' => 'nombreModelo', 'label' => 'Nombre'],
            ['key' => 'marca_label', 'label' => 'Marca'],
        ];

        $filename = 'modelo_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('m.idmodelo', array_values($selectedIds))->orderBy('m.idmodelo')->get();
    
            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }
    
            return $this->exportPdfResponse($rows, $columns, 'Listado de Modelos', $filename);
        }

        $rows = $baseQuery->orderBy('m.idmodelo')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Modelos', $filename);
    }

    public function tributosIndex(Request $request): View
    {
        $baseQuery = DB::table('tributo');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtributo', 'like', $term)
                    ->orWhere('nombreTributo', 'like', $term)
                    ->orWhere('tipo', 'like', $term)
                    ->orWhere('valor', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('idtributo')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.tributo.tributo', [
            'title' => 'Configuracion: Tributo',
            'singularTitle' => 'Tributo',
            'items' => $items,
            'columns' => [
                ['key' => 'idtributo', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'nombreTributo', 'label' => 'Nombre', 'type' => 'text'],
                ['key' => 'tipo', 'label' => 'Tipo', 'type' => 'text'],
                ['key' => 'valor', 'label' => 'Valor', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.tributos.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.tributos.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de tributos', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.tributos.create'),
            'editRoute' => 'modules.configuracion.tributos.edit',
            'showRoute' => 'modules.configuracion.tributos.edit',
            'destroyRoute' => 'modules.configuracion.tributos.destroy',
            'identifierKey' => 'idtributo',
            'lockResource' => 'configuracion.tributo',
        ]);
    }

    public function tributosCreate(): View
    {
        return view('configuracion.tributo.tributo-form', [
            'title' => 'Nuevo Tributo',
            'moduleTitle' => 'Configuracion: Tributo',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.tributos.store'),
            'backRoute' => route('modules.configuracion.tributos.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'nombreTributo',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'tipo',
                    'type' => 'text',
                    'label' => 'Tipo',
                    'required' => false,
                    'maxlength' => 45,
                    'minlength' => 1,
                    'helpText' => 'Opcional.',
                ],
                [
                    'name' => 'valor',
                    'type' => 'number',
                    'label' => 'Valor',
                    'required' => false,
                    'min' => 0,
                    'helpText' => 'Opcional.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function tributosStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nombreTributo' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'tipo' => ['nullable', 'string', 'min:1', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
            'valor' => ['nullable', 'integer', 'min:0'],
        ]);

        $newId = DB::table('tributo')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.tributo', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.tributos.index')
            ->with('success', 'Tributo creado correctamente.');
    }

    public function tributosEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('tributo')->where('idtributo', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.tributos.index')
                ->with('error', 'No se encontro el tributo solicitado.');
        }

        return view('configuracion.tributo.tributo-form', [
            'title' => 'Editar Tributo',
            'moduleTitle' => 'Configuracion: Tributo',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.tributos.update', $id),
            'backRoute' => route('modules.configuracion.tributos.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'nombreTributo',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'tipo',
                    'type' => 'text',
                    'label' => 'Tipo',
                    'required' => false,
                    'maxlength' => 45,
                    'minlength' => 1,
                    'helpText' => 'Opcional.',
                ],
                [
                    'name' => 'valor',
                    'type' => 'number',
                    'label' => 'Valor',
                    'required' => false,
                    'min' => 0,
                    'helpText' => 'Opcional.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.tributo', (string) $id));
    }

    public function tributosUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('tributo')->where('idtributo', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.tributos.index')
                ->with('error', 'No se encontro el tributo solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tributo', (string) $id, 'tributo', 'modules.configuracion.tributos.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'nombreTributo' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'tipo' => ['nullable', 'string', 'min:1', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
            'valor' => ['nullable', 'integer', 'min:0'],
        ]);

        DB::table('tributo')->where('idtributo', $id)->update($validated);
        $this->publishResourceEvent('configuracion.tributo', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.tributo', (string) $id);

        return redirect()
            ->route('modules.configuracion.tributos.index')
            ->with('success', 'Tributo actualizado correctamente.');
    }

    public function tributosDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tributo', (string) $id, 'tributo', 'modules.configuracion.tributos.index')) {
            return $redirect;
        }

        try {
            DB::table('tributo')->where('idtributo', $id)->delete();
            $this->publishResourceEvent('configuracion.tributo', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.tributo', (string) $id);
            return redirect()
                ->route('modules.configuracion.tributos.index')
                ->with('success', 'Tributo eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.tributos.index')
                ->with('error', 'No se puede eliminar el tributo porque tiene registros relacionados.');
        }
    }

    public function tributosExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('tributo');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtributo', 'like', $term)
                    ->orWhere('nombreTributo', 'like', $term)
                    ->orWhere('tipo', 'like', $term)
                    ->orWhere('valor', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idtributo', 'label' => 'ID'],
            ['key' => 'nombreTributo', 'label' => 'Nombre'],
            ['key' => 'tipo', 'label' => 'Tipo'],
            ['key' => 'valor', 'label' => 'Valor'],
        ];

        $filename = 'tributo_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('idtributo', array_values($selectedIds))->orderBy('idtributo')->get();
    
            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }
    
            return $this->exportPdfResponse($rows, $columns, 'Listado de Tributos', $filename);
        }

        $rows = $baseQuery->orderBy('idtributo')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Tributos', $filename);
    }

    public function paquetesIndex(Request $request): View
    {
        $baseQuery = DB::table('paquetes');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idpaquetes', 'like', $term)
                    ->orWhere('nombre', 'like', $term)
                    ->orWhere('descripcion', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('idpaquetes')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        // Adjuntar detallepaquete (almacén + precio) a cada fila como relation_groups
        try {
            $pageIds = collect($items->items())->pluck('idpaquetes')->filter()->values()->all();
                if (!empty($pageIds)) {
                $detalles = DB::table('detallepaquete as dp')
                    ->leftJoin('almacen as a', 'dp.almacen_idalmacen', '=', 'a.idalmacen')
                    ->leftJoin('empresapropietaria as ep', 'a.empresaPropietaria_RUC', '=', 'ep.RUC')
                    ->leftJoin('modelo as m', 'a.modelo_idmodelo', '=', 'm.idmodelo')
                    ->leftJoin('marca as ma', 'm.marca_idmarca', '=', 'ma.idmarca')
                    ->leftJoin('tipoelemento as te', 'a.tipoElemento_idtipoElemento', '=', 'te.idtipoElemento')
                    ->leftJoin('plataforma as p', 'te.plataforma_idplataforma', '=', 'p.idplataforma')
                    ->whereIn('dp.paquetes_idpaquetes', $pageIds)
                    ->select([
                        'dp.paquetes_idpaquetes',
                        'dp.precio',
                        'a.idalmacen',
                        'a.detalle as almacen_detalle',
                        'ep.razonSocial',
                        'm.nombreModelo',
                        'ma.nombreMarca',
                        'te.nombre as tipoelemento_nombre',
                        'te.detalle as tipoelemento_detalle',
                        'p.nombrePlataforma',
                    ])
                    ->orderBy('dp.iddetallepaquete')
                    ->get()
                    ->groupBy('paquetes_idpaquetes');

                $collection = $items->getCollection()->map(function ($row) use ($detalles) {
                    $pk = data_get($row, 'idpaquetes');
                    $groupRecords = [];
                    if (isset($detalles[$pk])) {
                        $groupRecords = collect($detalles[$pk])->map(function ($d) {
                            $empresa = trim((string) ($d->razonSocial ?? '')) ?: 'Sin empresa';
                            $modelo = trim((string) ($d->nombreModelo ?? '')) ?: 'Sin modelo';
                            $marca = trim((string) ($d->nombreMarca ?? '')) ?: 'Sin marca';
                            $tipo = trim((string) ($d->tipoelemento_nombre ?? '')) ?: 'Sin tipo';
                            $detalle = trim((string) ($d->tipoelemento_detalle ?? '')) ?: 'Sin detalle';
                            $plataforma = trim((string) ($d->nombrePlataforma ?? '')) ?: 'Sin plataforma';

                            $isPlanServicio = preg_match('/\b(?:PLAN|SERVICIO)\b/i', $tipo) === 1 || preg_match('/\b(?:PLAN|SERVICIO)\b/i', $detalle) === 1;
                            $label = $isPlanServicio
                                ? sprintf('%s - %s - %s - %s', $empresa, $tipo, $detalle, $plataforma)
                                : sprintf('%s - %s - %s - %s - %s - %s', $empresa, $modelo, $marca, $tipo, $detalle, $plataforma);

                            return [
                                'almacen_label' => $label,
                                'precio' => is_null($d->precio) ? '-' : 'S/' . (string) $d->precio,
                            ];
                        })->values()->all();
                    }

                    // Para que la UI sea igual a la de Flujos, usamos el campo `history`
                    // y definimos `historyColumns` en la vista. El layout renderiza
                    // el panel expandible exactamente como en flujosIndex.
                    if (!empty($groupRecords)) {
                        $row->history = collect($groupRecords)->map(function ($r) {
                            return (object) [
                                'almacen_label' => $r['almacen_label'] ?? '-',
                                'precio' => $r['precio'] ?? '-',
                            ];
                        })->values();
                    }

                    return $row;
                });

                $items->setCollection($collection);
            }
        } catch (\Throwable $e) {
            // No fallar la lista si ocurre un problema; sólo no mostrar relaciones
        }

        return view('configuracion.paquetes.paquetes', [
            'title' => 'Configuracion: Paquetes',
            'singularTitle' => 'Paquete',
            'items' => $items,
            'columns' => [
                ['key' => 'idpaquetes', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text'],
                ['key' => 'descripcion', 'label' => 'Descripción', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.paquetes.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.paquetes.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de paquetes', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'historyColumns' => [
                ['key' => 'almacen_label', 'label' => 'Almacén', 'type' => 'text'],
                ['key' => 'precio', 'label' => 'Precio', 'type' => 'text'],
            ],
            'historyTitle' => 'Detalle del paquete',
            'createRoute' => route('modules.configuracion.paquetes.create'),
            'editRoute' => 'modules.configuracion.paquetes.edit',
            'showRoute' => 'modules.configuracion.paquetes.edit',
            'destroyRoute' => 'modules.configuracion.paquetes.destroy',
            'identifierKey' => 'idpaquetes',
            'lockResource' => 'configuracion.paquetes',
        ]);
    }

    public function paquetesCreate(): View
    {
        $almacenOptions = DB::table('almacen as a')
            ->leftJoin('empresapropietaria as ep', 'a.empresaPropietaria_RUC', '=', 'ep.RUC')
            ->leftJoin('modelo as m', 'a.modelo_idmodelo', '=', 'm.idmodelo')
            ->leftJoin('marca as ma', 'm.marca_idmarca', '=', 'ma.idmarca')
            ->leftJoin('tipoelemento as te', 'a.tipoElemento_idtipoElemento', '=', 'te.idtipoElemento')
            ->leftJoin('plataforma as p', 'te.plataforma_idplataforma', '=', 'p.idplataforma')
            ->select([
                'a.idalmacen',
                'a.detalle',
                'ep.razonSocial',
                'm.nombreModelo',
                'ma.nombreMarca',
                'te.nombre as tipoelemento_nombre',
                'te.detalle as tipoelemento_detalle',
                'p.nombrePlataforma',
            ])
            ->orderBy('ep.razonSocial')
            ->orderBy('ma.nombreMarca')
            ->orderBy('m.nombreModelo')
            ->orderBy('a.detalle')
            ->get()
            ->map(function ($r) {
                $empresa = trim((string) ($r->razonSocial ?? '')) ?: 'Sin empresa';
                $modelo = trim((string) ($r->nombreModelo ?? '')) ?: 'Sin modelo';
                $marca = trim((string) ($r->nombreMarca ?? '')) ?: 'Sin marca';
                $tipo = trim((string) ($r->tipoelemento_nombre ?? '')) ?: 'Sin tipo';
                $detalle = trim((string) ($r->tipoelemento_detalle ?? '')) ?: 'Sin detalle';
                $plataforma = trim((string) ($r->nombrePlataforma ?? '')) ?: 'Sin plataforma';

                $isPlanServicio = preg_match('/\b(?:PLAN|SERVICIO)\b/i', $tipo) === 1 || preg_match('/\b(?:PLAN|SERVICIO)\b/i', $detalle) === 1;
                $label = $isPlanServicio
                    ? sprintf('%s - %s - %s - %s', $empresa, $tipo, $detalle, $plataforma)
                    : sprintf('%s - %s - %s - %s - %s - %s', $empresa, $modelo, $marca, $tipo, $detalle, $plataforma);

                return [
                    'value' => $r->idalmacen,
                    'label' => $label,
                ];
            })->values()->all();

        return view('configuracion.paquetes.paquetes-form', [
            'title' => 'Nuevo Paquete',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.paquetes.store'),
            'backRoute' => route('modules.configuracion.paquetes.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'nombre',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 200,
                    'minlength' => 2,
                    'helpText' => 'Solo letras. Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'descripcion',
                    'type' => 'text',
                    'label' => 'Descripción',
                    'required' => false,
                    'maxlength' => 300,
                    'helpText' => 'Solo letras. Mínimo 2 caracteres.',
                ],
            ],
            'extraSections' => [
                [
                    'view' => 'configuracion.paquetes._detalle_paquete_rows',
                    'data' => [
                        'almacenOptions' => $almacenOptions,
                        'detallePaquetePayload' => '[]',
                        'readOnly' => false,
                    ],
                ]
            ],
            'readOnly' => false,
            'almacenOptions' => $almacenOptions,
            'detallePaquetePayload' => '[]',
        ]);
    }

    public function paquetesStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:200', 'regex:' . self::SAFE_TEXT_REGEX],
            'descripcion' => ['nullable', 'string', 'max:300', 'regex:' . self::SAFE_TEXT_REGEX],
            'detalle_paquete_payload' => ['nullable'],
        ]);

        $newId = null;
        DB::transaction(function () use ($validated, $request, &$newId) {
            $payload = collect($validated)->only(['nombre', 'descripcion'])->all();
            $newId = DB::table('paquetes')->insertGetId($payload);
            $this->syncDetallePaquetePayload($request, (int) $newId);
            $this->publishResourceEvent('configuracion.paquetes', (string) $newId, 'created');
        });

        return redirect()
            ->route('modules.configuracion.paquetes.index')
            ->with('success', 'Paquete creado correctamente.');
    }

    public function paquetesEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('paquetes')->where('idpaquetes', $id)->first();
        if (!$record) {
            return redirect()->route('modules.configuracion.paquetes.index')->with('error', 'Registro no encontrado.');
        }
        $almacenOptions = DB::table('almacen as a')
            ->leftJoin('empresapropietaria as ep', 'a.empresaPropietaria_RUC', '=', 'ep.RUC')
            ->leftJoin('modelo as m', 'a.modelo_idmodelo', '=', 'm.idmodelo')
            ->leftJoin('marca as ma', 'm.marca_idmarca', '=', 'ma.idmarca')
            ->leftJoin('tipoelemento as te', 'a.tipoElemento_idtipoElemento', '=', 'te.idtipoElemento')
            ->leftJoin('plataforma as p', 'te.plataforma_idplataforma', '=', 'p.idplataforma')
            ->select([
                'a.idalmacen',
                'ep.razonSocial',
                'm.nombreModelo',
                'ma.nombreMarca',
                'te.nombre as tipoelemento_nombre',
                'te.detalle as tipoelemento_detalle',
                'p.nombrePlataforma',
            ])
            ->orderBy('ep.razonSocial')
            ->orderBy('ma.nombreMarca')
            ->orderBy('m.nombreModelo')
            ->get()
            ->map(function ($r) {
                $empresa = trim((string) ($r->razonSocial ?? '')) ?: 'Sin empresa';
                $modelo = trim((string) ($r->nombreModelo ?? '')) ?: 'Sin modelo';
                $marca = trim((string) ($r->nombreMarca ?? '')) ?: 'Sin marca';
                $tipo = trim((string) ($r->tipoelemento_nombre ?? '')) ?: 'Sin tipo';
                $detalle = trim((string) ($r->tipoelemento_detalle ?? '')) ?: 'Sin detalle';
                $plataforma = trim((string) ($r->nombrePlataforma ?? '')) ?: 'Sin plataforma';

                $isPlanServicio = preg_match('/\b(?:PLAN|SERVICIO)\b/i', $tipo) === 1 || preg_match('/\b(?:PLAN|SERVICIO)\b/i', $detalle) === 1;
                $label = $isPlanServicio
                    ? sprintf('%s - %s - %s - %s', $empresa, $tipo, $detalle, $plataforma)
                    : sprintf('%s - %s - %s - %s - %s - %s', $empresa, $modelo, $marca, $tipo, $detalle, $plataforma);

                return [
                    'value' => $r->idalmacen,
                    'label' => $label,
                ];
            })->values()->all();

        $detalleItems = $this->detailPaqueteItems($id)->map(function ($it) use ($almacenOptions) {
            $label = collect($almacenOptions)->first(fn($o) => (string)$o['value'] === (string)$it->almacen_idalmacen)['label'] ?? ('Almacén ' . $it->almacen_idalmacen);
            return [
                'tempId' => (string) ($it->iddetallepaquete ?? ('tmp-' . uniqid())),
                'almacen_idalmacen' => (int) $it->almacen_idalmacen,
                'almacen_label' => $label,
                'precio' => (string) $it->precio,
            ];
        })->values()->all();

        return view('configuracion.paquetes.paquetes-form', [
            'title' => 'Editar Paquete',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.paquetes.update', $id),
            'backRoute' => route('modules.configuracion.paquetes.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'nombre',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 200,
                    'minlength' => 2,
                    'helpText' => 'Solo letras. Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'descripcion',
                    'type' => 'text',
                    'label' => 'Descripción',
                    'required' => false,
                    'maxlength' => 300,
                    'helpText' => 'Solo letras. Mínimo 2 caracteres.',
                ],
            ],
            'extraSections' => [
                [
                    'view' => 'configuracion.paquetes._detalle_paquete_rows',
                    'data' => [
                        'almacenOptions' => $almacenOptions,
                        'detallePaquetePayload' => json_encode($detalleItems, JSON_UNESCAPED_UNICODE),
                        'readOnly' => true,
                    ],
                ]
            ],
            'readOnly' => true,
            'almacenOptions' => $almacenOptions,
            'detallePaquetePayload' => json_encode($detalleItems, JSON_UNESCAPED_UNICODE),
        ] + $this->prepareLockViewData('configuracion.paquetes', (string) $id));
    }

    public function paquetesUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('paquetes')->where('idpaquetes', $id)->exists();
        if (!$exists) {
            return redirect()->route('modules.configuracion.paquetes.index')->with('error', 'Registro no encontrado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.paquetes', (string) $id, 'paquete', 'modules.configuracion.paquetes.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:200', 'regex:' . self::SAFE_TEXT_REGEX],
            'descripcion' => ['nullable', 'string', 'max:300', 'regex:' . self::SAFE_TEXT_REGEX],
            'detalle_paquete_payload' => ['nullable'],
        ]);

        DB::transaction(function () use ($validated, $request, $id) {
            $payload = collect($validated)->only(['nombre', 'descripcion'])->all();
            DB::table('paquetes')->where('idpaquetes', $id)->update($payload);
            $this->syncDetallePaquetePayload($request, $id);
            $this->publishResourceEvent('configuracion.paquetes', (string) $id, 'updated');
            $this->releaseLockIfOwned($request, 'configuracion.paquetes', (string) $id);
        });

        return redirect()
            ->route('modules.configuracion.paquetes.index')
            ->with('success', 'Paquete actualizado correctamente.');
    }

    public function paquetesDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.paquetes', (string) $id, 'paquete', 'modules.configuracion.paquetes.index')) {
            return $redirect;
        }

        try {
            DB::table('paquetes')->where('idpaquetes', $id)->delete();
            $this->publishResourceEvent('configuracion.paquetes', (string) $id, 'deleted');

            return redirect()->route('modules.configuracion.paquetes.index')->with('success', 'Paquete eliminado correctamente.');
        } catch (QueryException) {
            return redirect()->route('modules.configuracion.paquetes.index')->with('error', 'No se puede eliminar el paquete porque tiene registros relacionados.');
        }
    }

    public function paquetesExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('paquetes');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idpaquetes', 'like', $term)
                    ->orWhere('nombre', 'like', $term)
                    ->orWhere('descripcion', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idpaquetes', 'label' => 'ID'],
            ['key' => 'nombre', 'label' => 'Nombre'],
            ['key' => 'descripcion', 'label' => 'Descripción'],
        ];

        $filename = 'paquetes_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('idpaquetes', array_values($selectedIds))->orderBy('idpaquetes')->get();

            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }

            return $this->exportPdfResponse($rows, $columns, 'Listado de Paquetes', $filename);
        }

        $rows = $baseQuery->orderBy('idpaquetes')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Paquetes', $filename);
    }

    public function tecnologiasIndex(Request $request): View
    {
        $baseQuery = DB::table('tecnologia');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtecnologia', 'like', $term)
                    ->orWhere('nombreTecnologia', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('idtecnologia')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.tecnologia.tecnologia', [
            'title' => 'Configuracion: Tecnología',
            'singularTitle' => 'Tecnología',
            'items' => $items,
            'columns' => [
                ['key' => 'idtecnologia', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'nombreTecnologia', 'label' => 'Nombre', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.tecnologias.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.tecnologias.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de tecnologías', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.tecnologias.create'),
            'editRoute' => 'modules.configuracion.tecnologias.edit',
            'showRoute' => 'modules.configuracion.tecnologias.edit',
            'destroyRoute' => 'modules.configuracion.tecnologias.destroy',
            'identifierKey' => 'idtecnologia',
            'lockResource' => 'configuracion.tecnologia',
        ]);
    }

    public function tecnologiasCreate(): View
    {
        return view('configuracion.tecnologia.tecnologia-form', [
            'title' => 'Nueva Tecnología',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.tecnologias.store'),
            'backRoute' => route('modules.configuracion.tecnologias.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'nombreTecnologia',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 2,
                    'minlength' => 1,
                    'helpText' => 'Mínimo 1 carácter.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function tecnologiasStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nombreTecnologia' => ['required', 'string', 'min:1', 'max:2', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        $newId = DB::table('tecnologia')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.tecnologia', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.tecnologias.index')
            ->with('success', 'Tecnología creada correctamente.');
    }

    public function tecnologiasEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('tecnologia')->where('idtecnologia', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.tecnologias.index')
                ->with('error', 'No se encontro la tecnología solicitada.');
        }

        return view('configuracion.tecnologia.tecnologia-form', [
            'title' => 'Editar Tecnología',
            'moduleTitle' => 'Configuracion: Tecnología',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.tecnologias.update', $id),
            'backRoute' => route('modules.configuracion.tecnologias.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'nombreTecnologia',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 2,
                    'minlength' => 1,
                    'helpText' => 'Mínimo 1 carácter.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.tecnologia', (string) $id));
    }

    public function tecnologiasUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('tecnologia')->where('idtecnologia', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.tecnologias.index')
                ->with('error', 'No se encontro la tecnología solicitada.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tecnologia', (string) $id, 'tecnología', 'modules.configuracion.tecnologias.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'nombreTecnologia' => ['required', 'string', 'min:1', 'max:2', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        DB::table('tecnologia')->where('idtecnologia', $id)->update($validated);
        $this->publishResourceEvent('configuracion.tecnologia', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.tecnologia', (string) $id);

        return redirect()
            ->route('modules.configuracion.tecnologias.index')
            ->with('success', 'Tecnología actualizada correctamente.');
    }

    public function tecnologiasDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tecnologia', (string) $id, 'tecnología', 'modules.configuracion.tecnologias.index')) {
            return $redirect;
        }

        try {
            DB::table('tecnologia')->where('idtecnologia', $id)->delete();
            $this->publishResourceEvent('configuracion.tecnologia', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.tecnologia', (string) $id);
            return redirect()
                ->route('modules.configuracion.tecnologias.index')
                ->with('success', 'Tecnología eliminada correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.tecnologias.index')
                ->with('error', 'No se puede eliminar la tecnología porque tiene registros relacionados.');
        }
    }

    public function tiposPlataformaIndex(Request $request): View
    {
        $baseQuery = DB::table('tipoplataforma');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtipoPlataforma', 'like', $term)
                    ->orWhere('descripcion', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('idtipoPlataforma')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.tipoplataforma.tipoplataforma', [
            'title' => 'Configuracion: Tipo de plataforma',
            'singularTitle' => 'Tipo de plataforma',
            'items' => $items,
            'columns' => [
                ['key' => 'idtipoPlataforma', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'descripcion', 'label' => 'Descripcion', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.tipos-plataforma.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.tipos-plataforma.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de tipos de plataforma', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.tipos-plataforma.create'),
            'editRoute' => 'modules.configuracion.tipos-plataforma.edit',
            'showRoute' => 'modules.configuracion.tipos-plataforma.edit',
            'destroyRoute' => 'modules.configuracion.tipos-plataforma.destroy',
            'identifierKey' => 'idtipoPlataforma',
            'lockResource' => 'configuracion.tipo_plataforma',
        ]);
    }

    public function tiposPlataformaCreate(): View
    {
        return view('configuracion.tipoplataforma.tipoplataforma-form', [
            'title' => 'Nuevo Tipo de plataforma',
            'moduleTitle' => 'Configuracion: Tipo de plataforma',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.tipos-plataforma.store'),
            'backRoute' => route('modules.configuracion.tipos-plataforma.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'descripcion',
                    'type' => 'text',
                    'label' => 'Descripcion',
                    'required' => true,
                    'maxlength' => 45,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function tiposPlataformaStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'descripcion' => ['required', 'string', 'min:2', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        $newId = DB::table('tipoplataforma')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.tipo_plataforma', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.tipos-plataforma.index')
            ->with('success', 'Tipo de plataforma creado correctamente.');
    }

    public function tiposPlataformaEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('tipoplataforma')->where('idtipoPlataforma', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.tipos-plataforma.index')
                ->with('error', 'No se encontro el tipo de plataforma solicitado.');
        }

        return view('configuracion.tipoplataforma.tipoplataforma-form', [
            'title' => 'Editar Tipo de plataforma',
            'moduleTitle' => 'Configuracion: Tipo de plataforma',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.tipos-plataforma.update', $id),
            'backRoute' => route('modules.configuracion.tipos-plataforma.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'descripcion',
                    'type' => 'text',
                    'label' => 'Descripcion',
                    'required' => true,
                    'maxlength' => 45,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.tipo_plataforma', (string) $id));
    }

    public function tiposPlataformaUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('tipoplataforma')->where('idtipoPlataforma', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.tipos-plataforma.index')
                ->with('error', 'No se encontro el tipo de plataforma solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tipo_plataforma', (string) $id, 'tipo de plataforma', 'modules.configuracion.tipos-plataforma.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'descripcion' => ['required', 'string', 'min:2', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        DB::table('tipoplataforma')->where('idtipoPlataforma', $id)->update($validated);
        $this->publishResourceEvent('configuracion.tipo_plataforma', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.tipo_plataforma', (string) $id);

        return redirect()
            ->route('modules.configuracion.tipos-plataforma.index')
            ->with('success', 'Tipo de plataforma actualizado correctamente.');
    }

    public function tiposPlataformaDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tipo_plataforma', (string) $id, 'tipo de plataforma', 'modules.configuracion.tipos-plataforma.index')) {
            return $redirect;
        }

        try {
            DB::table('tipoplataforma')->where('idtipoPlataforma', $id)->delete();
            $this->publishResourceEvent('configuracion.tipo_plataforma', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.tipo_plataforma', (string) $id);
            return redirect()
                ->route('modules.configuracion.tipos-plataforma.index')
                ->with('success', 'Tipo de plataforma eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.tipos-plataforma.index')
                ->with('error', 'No se puede eliminar el tipo de plataforma porque tiene registros relacionados.');
        }
    }

    public function tiposPlataformaExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('tipoplataforma');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtipoPlataforma', 'like', $term)
                    ->orWhere('descripcion', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idtipoPlataforma', 'label' => 'ID'],
            ['key' => 'descripcion', 'label' => 'Descripcion'],
        ];

        $filename = 'tipo_plataforma_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('idtipoPlataforma', array_values($selectedIds))->orderBy('idtipoPlataforma')->get();
    
            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }
    
            return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Plataforma', $filename);
        }

        $rows = $baseQuery->orderBy('idtipoPlataforma')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Plataforma', $filename);
    }

    public function plataformaIndex(Request $request): View
    {
        $baseQuery = DB::table('plataforma')
            ->leftJoin('tipoplataforma', 'plataforma.tipoPlataforma_idtipoPlataforma', '=', 'tipoplataforma.idtipoPlataforma')
            ->select('plataforma.*', 'tipoplataforma.descripcion as tipoPlataforma');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('plataforma.idplataforma', 'like', $term)
                    ->orWhere('plataforma.nombrePlataforma', 'like', $term)
                    ->orWhere('tipoplataforma.descripcion', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('plataforma.idplataforma')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.plataforma.plataforma', [
            'title' => 'Configuracion: Plataforma',
            'singularTitle' => 'Plataforma',
            'items' => $items,
            'columns' => [
                ['key' => 'idplataforma', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'nombrePlataforma', 'label' => 'Nombre', 'type' => 'text'],
                ['key' => 'tipoPlataforma', 'label' => 'Tipo de plataforma', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.plataforma.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.plataforma.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de plataformas', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.plataforma.create'),
            'editRoute' => 'modules.configuracion.plataforma.edit',
            'showRoute' => 'modules.configuracion.plataforma.edit',
            'destroyRoute' => 'modules.configuracion.plataforma.destroy',
            'identifierKey' => 'idplataforma',
            'lockResource' => 'configuracion.plataforma',
        ]);
    }

    public function plataformaCreate(): View
    {
        $tiposPlataforma = DB::table('tipoplataforma')->orderBy('descripcion')->get();

        return view('configuracion.plataforma.plataforma-form', [
            'title' => 'Nueva Plataforma',
            'moduleTitle' => 'Configuracion: Plataforma',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.plataforma.store'),
            'backRoute' => route('modules.configuracion.plataforma.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'nombrePlataforma',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 20,
                    'minlength' => 2,
                    'helpText' => 'Nombre de la plataforma.',
                ],
                [
                    'name' => 'tipoPlataforma_idtipoPlataforma',
                    'type' => 'select',
                    'label' => 'Tipo de plataforma',
                    'required' => true,
                    'optionsData' => $tiposPlataforma,
                    'optionKey' => 'idtipoPlataforma',
                    'optionLabel' => 'descripcion',
                    'tomSelect' => true,
                    'helpText' => 'Selecciona el tipo de plataforma.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function plataformaStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nombrePlataforma' => ['required', 'string', 'min:2', 'max:20', 'regex:' . self::SAFE_TEXT_REGEX],
            'tipoPlataforma_idtipoPlataforma' => ['required', 'integer', 'exists:tipoplataforma,idtipoPlataforma'],
        ]);

        $newId = DB::table('plataforma')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.plataforma', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.plataforma.index')
            ->with('success', 'Plataforma creada correctamente.');
    }

    public function plataformaEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('plataforma')->where('idplataforma', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.plataforma.index')
                ->with('error', 'No se encontro la plataforma solicitada.');
        }

        $tiposPlataforma = DB::table('tipoplataforma')->orderBy('descripcion')->get();

        return view('configuracion.plataforma.plataforma-form', [
            'title' => 'Editar Plataforma',
            'moduleTitle' => 'Configuracion: Plataforma',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.plataforma.update', $id),
            'backRoute' => route('modules.configuracion.plataforma.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'nombrePlataforma',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 20,
                    'minlength' => 2,
                    'helpText' => 'Nombre de la plataforma.',
                ],
                [
                    'name' => 'tipoPlataforma_idtipoPlataforma',
                    'type' => 'select',
                    'label' => 'Tipo de plataforma',
                    'required' => true,
                    'optionsData' => $tiposPlataforma,
                    'optionKey' => 'idtipoPlataforma',
                    'optionLabel' => 'descripcion',
                    'tomSelect' => true,
                    'helpText' => 'Selecciona el tipo de plataforma.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.plataforma', (string) $id));
    }

    public function plataformaUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('plataforma')->where('idplataforma', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.plataforma.index')
                ->with('error', 'No se encontro la plataforma solicitada.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.plataforma', (string) $id, 'plataforma', 'modules.configuracion.plataforma.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'nombrePlataforma' => ['required', 'string', 'min:2', 'max:20', 'regex:' . self::SAFE_TEXT_REGEX],
            'tipoPlataforma_idtipoPlataforma' => ['required', 'integer', 'exists:tipoplataforma,idtipoPlataforma'],
        ]);

        DB::table('plataforma')->where('idplataforma', $id)->update($validated);
        $this->publishResourceEvent('configuracion.plataforma', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.plataforma', (string) $id);

        return redirect()
            ->route('modules.configuracion.plataforma.index')
            ->with('success', 'Plataforma actualizada correctamente.');
    }

    public function plataformaDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.plataforma', (string) $id, 'plataforma', 'modules.configuracion.plataforma.index')) {
            return $redirect;
        }

        try {
            DB::table('plataforma')->where('idplataforma', $id)->delete();
            $this->publishResourceEvent('configuracion.plataforma', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.plataforma', (string) $id);
            return redirect()
                ->route('modules.configuracion.plataforma.index')
                ->with('success', 'Plataforma eliminada correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.plataforma.index')
                ->with('error', 'No se puede eliminar la plataforma porque tiene registros relacionados.');
        }
    }

    public function plataformaExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('plataforma')
            ->leftJoin('tipoplataforma', 'plataforma.tipoPlataforma_idtipoPlataforma', '=', 'tipoplataforma.idtipoPlataforma')
            ->select('plataforma.*', 'tipoplataforma.descripcion as tipoPlataforma');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('plataforma.idplataforma', 'like', $term)
                    ->orWhere('plataforma.nombrePlataforma', 'like', $term)
                    ->orWhere('tipoplataforma.descripcion', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idplataforma', 'label' => 'ID'],
            ['key' => 'nombrePlataforma', 'label' => 'Nombre'],
            ['key' => 'tipoPlataforma', 'label' => 'Tipo de plataforma'],
        ];

        $filename = 'plataforma_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('plataforma.idplataforma', array_values($selectedIds))->orderBy('plataforma.idplataforma')->get();
    
            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }
    
            return $this->exportPdfResponse($rows, $columns, 'Listado de Plataformas', $filename);
        }

        $rows = $baseQuery->orderBy('plataforma.idplataforma')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Plataformas', $filename);
    }

    public function tipoElementoIndex(Request $request): View
    {
        $baseQuery = DB::table('tipoelemento')
            ->leftJoin('plataforma', 'tipoelemento.plataforma_idplataforma', '=', 'plataforma.idplataforma')
            ->select('tipoelemento.*', 'plataforma.nombrePlataforma as plataforma');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('tipoelemento.idtipoElemento', 'like', $term)
                    ->orWhere('tipoelemento.nombre', 'like', $term)
                    ->orWhere('tipoelemento.detalle', 'like', $term)
                    ->orWhere('plataforma.nombrePlataforma', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('tipoelemento.idtipoElemento')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.tipoelemento.tipoelemento', [
            'title' => 'Configuracion: Tipo de elemento',
            'singularTitle' => 'Tipo de elemento',
            'items' => $items,
            'columns' => [
                ['key' => 'idtipoElemento', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text'],
                ['key' => 'detalle', 'label' => 'Detalle', 'type' => 'text'],
                ['key' => 'plataforma', 'label' => 'Plataforma', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.tipos-elemento.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.tipos-elemento.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de tipos de elemento', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.tipos-elemento.create'),
            'editRoute' => 'modules.configuracion.tipos-elemento.edit',
            'showRoute' => 'modules.configuracion.tipos-elemento.edit',
            'destroyRoute' => 'modules.configuracion.tipos-elemento.destroy',
            'identifierKey' => 'idtipoElemento',
            'lockResource' => 'configuracion.tipo_elemento',
        ]);
    }

    public function tipoElementoCreate(): View
    {
        $plataformas = DB::table('plataforma')->orderBy('nombrePlataforma')->get();

        return view('configuracion.tipoelemento.tipoelemento-form', [
            'title' => 'Nuevo Tipo de elemento',
            'moduleTitle' => 'Configuracion: Tipo de elemento',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.tipos-elemento.store'),
            'backRoute' => route('modules.configuracion.tipos-elemento.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'nombre',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Nombre del tipo de elemento.',
                ],
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => true,
                    'maxlength' => 45,
                    'minlength' => 2,
                    'helpText' => 'Detalle del tipo de elemento.',
                ],
                [
                    'name' => 'plataforma_idplataforma',
                    'type' => 'select',
                    'label' => 'Plataforma',
                    'required' => true,
                    'optionsData' => $plataformas,
                    'optionKey' => 'idplataforma',
                    'tomSelect' => true,
                    'optionLabel' => 'nombrePlataforma',
                    'helpText' => 'Selecciona la plataforma asociada.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function tipoElementoStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'detalle' => ['required', 'string', 'min:2', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
            'renovacion' => ['nullable', 'integer', 'min:0'],
            'plataforma_idplataforma' => ['required', 'integer', 'exists:plataforma,idplataforma'],
        ]);

        $newId = DB::table('tipoelemento')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.tipo_elemento', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.tipos-elemento.index')
            ->with('success', 'Tipo de elemento creado correctamente.');
    }

    public function tipoElementoEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('tipoelemento')->where('idtipoElemento', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.tipos-elemento.index')
                ->with('error', 'No se encontro el tipo de elemento solicitado.');
        }

        $plataformas = DB::table('plataforma')->orderBy('nombrePlataforma')->get();

        return view('configuracion.tipoelemento.tipoelemento-form', [
            'title' => 'Editar Tipo de elemento',
            'moduleTitle' => 'Configuracion: Tipo de elemento',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.tipos-elemento.update', $id),
            'backRoute' => route('modules.configuracion.tipos-elemento.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'nombre',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Nombre del tipo de elemento.',
                ],
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => true,
                    'maxlength' => 45,
                    'minlength' => 2,
                    'helpText' => 'Detalle del tipo de elemento.',
                ],
                [
                    'name' => 'plataforma_idplataforma',
                    'type' => 'select',
                    'label' => 'Plataforma',
                    'required' => true,
                    'optionsData' => $plataformas,
                    'optionKey' => 'idplataforma',
                    'optionLabel' => 'nombrePlataforma',
                    'tomSelect' => true,
                    'helpText' => 'Selecciona la plataforma asociada.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.tipo_elemento', (string) $id));
    }

    public function tipoElementoUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('tipoelemento')->where('idtipoElemento', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.tipos-elemento.index')
                ->with('error', 'No se encontro el tipo de elemento solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tipo_elemento', (string) $id, 'tipo de elemento', 'modules.configuracion.tipos-elemento.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'detalle' => ['required', 'string', 'min:2', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
            'renovacion' => ['nullable', 'integer', 'min:0'],
            'plataforma_idplataforma' => ['required', 'integer', 'exists:plataforma,idplataforma'],
        ]);

        DB::table('tipoelemento')->where('idtipoElemento', $id)->update($validated);
        $this->publishResourceEvent('configuracion.tipo_elemento', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.tipo_elemento', (string) $id);

        return redirect()
            ->route('modules.configuracion.tipos-elemento.index')
            ->with('success', 'Tipo de elemento actualizado correctamente.');
    }

    public function tipoElementoDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tipo_elemento', (string) $id, 'tipo de elemento', 'modules.configuracion.tipos-elemento.index')) {
            return $redirect;
        }

        try {
            DB::table('tipoelemento')->where('idtipoElemento', $id)->delete();
            $this->publishResourceEvent('configuracion.tipo_elemento', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.tipo_elemento', (string) $id);
            return redirect()
                ->route('modules.configuracion.tipos-elemento.index')
                ->with('success', 'Tipo de elemento eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.tipos-elemento.index')
                ->with('error', 'No se puede eliminar el tipo de elemento porque tiene registros relacionados.');
        }
    }

    public function tipoElementoExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('tipoelemento')
            ->leftJoin('plataforma', 'tipoelemento.plataforma_idplataforma', '=', 'plataforma.idplataforma')
            ->select('tipoelemento.*', 'plataforma.nombrePlataforma as plataforma');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('tipoelemento.idtipoElemento', 'like', $term)
                    ->orWhere('tipoelemento.nombre', 'like', $term)
                    ->orWhere('tipoelemento.detalle', 'like', $term)
                    ->orWhere('plataforma.nombrePlataforma', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idtipoElemento', 'label' => 'ID'],
            ['key' => 'nombre', 'label' => 'Nombre'],
            ['key' => 'detalle', 'label' => 'Detalle'],
            ['key' => 'plataforma', 'label' => 'Plataforma'],
        ];

        $filename = 'tipo_elemento_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('tipoelemento.idtipoElemento', array_values($selectedIds))->orderBy('tipoelemento.idtipoElemento')->get();
    
            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }
    
            return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Elemento', $filename);
        }

        $rows = $baseQuery->orderBy('tipoelemento.idtipoElemento')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Elemento', $filename);
    }

    public function tiposDocumentoIndex(Request $request): View
    {
        $baseQuery = DB::table('tipodocumento');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtipoDocumento', 'like', $term)
                    ->orWhere('detalle', 'like', $term)
                    ->orWhere('serie', 'like', $term)
                    ->orWhere('correlativo', 'like', $term)
                    ->orWhere('area', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('idtipoDocumento')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.tipodocumento.tipodocumento', [
            'title' => 'Configuracion: Tipo de documento',
            'singularTitle' => 'Tipo de documento',
            'items' => $items,
            'columns' => [
                ['key' => 'idtipoDocumento', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'detalle', 'label' => 'Detalle', 'type' => 'text'],
                ['key' => 'serie', 'label' => 'Serie', 'type' => 'text'],
                ['key' => 'correlativo', 'label' => 'Correlativo', 'type' => 'text'],
                ['key' => 'area', 'label' => 'Area', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.tipos-documento.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.tipos-documento.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de tipos de documento', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.tipos-documento.create'),
            'editRoute' => 'modules.configuracion.tipos-documento.edit',
            'showRoute' => 'modules.configuracion.tipos-documento.edit',
            'destroyRoute' => 'modules.configuracion.tipos-documento.destroy',
            'identifierKey' => 'idtipoDocumento',
            'lockResource' => 'configuracion.tipo_documento',
        ]);
    }

    public function tiposDocumentoCreate(): View
    {
        return view('configuracion.tipodocumento.tipodocumento-form', [
            'title' => 'Nuevo Tipo de documento',
            'moduleTitle' => 'Configuracion: Tipo de documento',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.tipos-documento.store'),
            'backRoute' => route('modules.configuracion.tipos-documento.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'serie',
                    'type' => 'text',
                    'label' => 'Serie',
                    'required' => false,
                    'maxlength' => 45,
                    'minlength' => 1,
                ],
                [
                    'name' => 'correlativo',
                    'type' => 'number',
                    'label' => 'Correlativo',
                    'required' => false,
                    'min' => 0,
                ],
                [
                    'name' => 'area',
                    'type' => 'text',
                    'label' => 'Area',
                    'required' => false,
                    'maxlength' => 1,
                    'minlength' => 1,
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function tiposDocumentoStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'detalle' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'serie' => ['nullable', 'string', 'min:1', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
            'correlativo' => ['nullable', 'integer', 'min:0'],
            'area' => ['nullable', 'string', 'min:1', 'max:1', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        $newId = DB::table('tipodocumento')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.tipo_documento', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.tipos-documento.index')
            ->with('success', 'Tipo de documento creado correctamente.');
    }

    public function tiposDocumentoEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('tipodocumento')->where('idtipoDocumento', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.tipos-documento.index')
                ->with('error', 'No se encontro el tipo de documento solicitado.');
        }

        return view('configuracion.tipodocumento.tipodocumento-form', [
            'title' => 'Editar Tipo de documento',
            'moduleTitle' => 'Configuracion: Tipo de documento',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.tipos-documento.update', $id),
            'backRoute' => route('modules.configuracion.tipos-documento.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'serie',
                    'type' => 'text',
                    'label' => 'Serie',
                    'required' => false,
                    'maxlength' => 45,
                    'minlength' => 1,
                ],
                [
                    'name' => 'correlativo',
                    'type' => 'number',
                    'label' => 'Correlativo',
                    'required' => false,
                    'min' => 0,
                ],
                [
                    'name' => 'area',
                    'type' => 'text',
                    'label' => 'Area',
                    'required' => false,
                    'maxlength' => 1,
                    'minlength' => 1,
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.tipo_documento', (string) $id));
    }

    public function tiposDocumentoUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('tipodocumento')->where('idtipoDocumento', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.tipos-documento.index')
                ->with('error', 'No se encontro el tipo de documento solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tipo_documento', (string) $id, 'tipo de documento', 'modules.configuracion.tipos-documento.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'detalle' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'serie' => ['nullable', 'string', 'min:1', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
            'correlativo' => ['nullable', 'integer', 'min:0'],
            'area' => ['nullable', 'string', 'min:1', 'max:1', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        // Resolve correlativo according to business rules
        $requestedCorrelativo = array_key_exists('correlativo', $validated) ? ($validated['correlativo'] === null ? null : (int) $validated['correlativo']) : null;
        $correlativoTarget = $this->resolveCorrelativoTarget($id);
        $result = CorrelativoService::resolveCorrelativo($id, $requestedCorrelativo, $correlativoTarget['table'], $correlativoTarget['idColumn']);
        // Ensure the final correlativo is persisted
        $validated['correlativo'] = $result['final'];

        DB::table('tipodocumento')->where('idtipoDocumento', $id)->update($validated);
        $this->publishResourceEvent('configuracion.tipo_documento', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.tipo_documento', (string) $id);

        $redirect = redirect()->route('modules.configuracion.tipos-documento.index');
        if (!$result['accepted'] && $requestedCorrelativo !== null) {
            $reason = $result['reason'] ?? 'rejected';
            $message = 'Tipo de documento actualizado. Correlativo no modificado: ' . $reason . '.';
            return $redirect->with('success', 'Tipo de documento actualizado correctamente.')->with('warning', $message);
        }

        return $redirect->with('success', 'Tipo de documento actualizado correctamente.');
    }

    private function resolveCorrelativoTarget(int $tipoDocumentoId): array
    {
        $tipo = DB::table('tipodocumento')->where('idtipoDocumento', $tipoDocumentoId)->first();
        if (!$tipo) {
            return ['table' => 'compras', 'idColumn' => 'idcompras'];
        }

        $detalle = trim((string) ($tipo->detalle ?? ''));
        if (str_contains(mb_strtolower($detalle, 'UTF-8'), 'cotiz')) {
            return ['table' => 'cotizacion', 'idColumn' => 'nroCotizacion'];
        }

        return ['table' => 'compras', 'idColumn' => 'idcompras'];
    }

    public function tiposDocumentoDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tipo_documento', (string) $id, 'tipo de documento', 'modules.configuracion.tipos-documento.index')) {
            return $redirect;
        }

        try {
            DB::table('tipodocumento')->where('idtipoDocumento', $id)->delete();
            $this->publishResourceEvent('configuracion.tipo_documento', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.tipo_documento', (string) $id);
            return redirect()
                ->route('modules.configuracion.tipos-documento.index')
                ->with('success', 'Tipo de documento eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.tipos-documento.index')
                ->with('error', 'No se puede eliminar el tipo de documento porque tiene registros relacionados.');
        }
    }

    public function tiposDocumentoExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('tipodocumento');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtipoDocumento', 'like', $term)
                    ->orWhere('detalle', 'like', $term)
                    ->orWhere('serie', 'like', $term)
                    ->orWhere('correlativo', 'like', $term)
                    ->orWhere('area', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idtipoDocumento', 'label' => 'ID'],
            ['key' => 'detalle', 'label' => 'Detalle'],
            ['key' => 'serie', 'label' => 'Serie'],
            ['key' => 'correlativo', 'label' => 'Correlativo'],
            ['key' => 'area', 'label' => 'Area'],
        ];

        $filename = 'tipo_documento_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('idtipoDocumento', array_values($selectedIds))->orderBy('idtipoDocumento')->get();
    
            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }
    
            return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Documento', $filename);
        }

        $rows = $baseQuery->orderBy('idtipoDocumento')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Documento', $filename);
    }

    public function formasPagoIndex(Request $request): View
    {
        $baseQuery = DB::table('formapago');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idformaPago', 'like', $term)
                    ->orWhere('detalle', 'like', $term)
                    ->orWhere('tiempo', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('idformaPago')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.formapago.formapago', [
            'title' => 'Configuracion: Forma de pago',
            'singularTitle' => 'Forma de pago',
            'items' => $items,
            'columns' => [
                ['key' => 'idformaPago', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'detalle', 'label' => 'Detalle', 'type' => 'text'],
                ['key' => 'tiempo', 'label' => 'Tiempo', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.formas-pago.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.formas-pago.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de formas de pago', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.formas-pago.create'),
            'editRoute' => 'modules.configuracion.formas-pago.edit',
            'showRoute' => 'modules.configuracion.formas-pago.edit',
            'destroyRoute' => 'modules.configuracion.formas-pago.destroy',
            'identifierKey' => 'idformaPago',
            'lockResource' => 'configuracion.forma_pago',
        ]);
    }

    public function formasPagoCreate(): View
    {
        return view('configuracion.formapago.formapago-form', [
            'title' => 'Nueva Forma de pago',
            'moduleTitle' => 'Configuracion: Forma de pago',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.formas-pago.store'),
            'backRoute' => route('modules.configuracion.formas-pago.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'tiempo',
                    'type' => 'number',
                    'label' => 'Tiempo',
                    'required' => false,
                    'min' => 0,
                    'helpText' => 'Días o tiempo en unidades según el tipo de pago.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function formasPagoStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'detalle' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'tiempo' => ['nullable', 'integer', 'min:0'],
        ]);

        $newId = DB::table('formapago')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.forma_pago', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.formas-pago.index')
            ->with('success', 'Forma de pago creada correctamente.');
    }

    public function formasPagoEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('formapago')->where('idformaPago', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.formas-pago.index')
                ->with('error', 'No se encontro la forma de pago solicitada.');
        }

        return view('configuracion.formapago.formapago-form', [
            'title' => 'Editar Forma de pago',
            'moduleTitle' => 'Configuracion: Forma de pago',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.formas-pago.update', $id),
            'backRoute' => route('modules.configuracion.formas-pago.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'tiempo',
                    'type' => 'number',
                    'label' => 'Tiempo',
                    'required' => false,
                    'min' => 0,
                    'helpText' => 'Días o tiempo en unidades según el tipo de pago.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.forma_pago', (string) $id));
    }

    public function formasPagoUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('formapago')->where('idformaPago', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.formas-pago.index')
                ->with('error', 'No se encontro la forma de pago solicitada.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.forma_pago', (string) $id, 'forma de pago', 'modules.configuracion.formas-pago.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'detalle' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'tiempo' => ['nullable', 'integer', 'min:0'],
        ]);

        DB::table('formapago')->where('idformaPago', $id)->update($validated);
        $this->publishResourceEvent('configuracion.forma_pago', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.forma_pago', (string) $id);

        return redirect()
            ->route('modules.configuracion.formas-pago.index')
            ->with('success', 'Forma de pago actualizada correctamente.');
    }

    public function formasPagoDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.forma_pago', (string) $id, 'forma de pago', 'modules.configuracion.formas-pago.index')) {
            return $redirect;
        }

        try {
            DB::table('formapago')->where('idformaPago', $id)->delete();
            $this->publishResourceEvent('configuracion.forma_pago', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.forma_pago', (string) $id);
            return redirect()
                ->route('modules.configuracion.formas-pago.index')
                ->with('success', 'Forma de pago eliminada correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.formas-pago.index')
                ->with('error', 'No se puede eliminar la forma de pago porque tiene registros relacionados.');
        }
    }

    public function formasPagoExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('formapago');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idformaPago', 'like', $term)
                    ->orWhere('detalle', 'like', $term)
                    ->orWhere('tiempo', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idformaPago', 'label' => 'ID'],
            ['key' => 'detalle', 'label' => 'Detalle'],
            ['key' => 'tiempo', 'label' => 'Tiempo'],
        ];

        $filename = 'forma_pago_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('idformaPago', array_values($selectedIds))->orderBy('idformaPago')->get();
    
            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }
    
            return $this->exportPdfResponse($rows, $columns, 'Listado de Formas de Pago', $filename);
        }

        $rows = $baseQuery->orderBy('idformaPago')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Formas de Pago', $filename);
    }

    public function entidadesBancariasIndex(Request $request): View
    {
        $baseQuery = DB::table('entidadbancaria');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('identidadBancaria', 'like', $term)
                    ->orWhere('razonSocial', 'like', $term)
                    ->orWhere('ruc', 'like', $term)
                    ->orWhere('descripcion', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('identidadBancaria')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.entidadbancaria.entidadbancaria', [
            'title' => 'Configuracion: Entidad bancaria',
            'singularTitle' => 'Entidad bancaria',
            'items' => $items,
            'columns' => [
                ['key' => 'identidadBancaria', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'razonSocial', 'label' => 'Razon social', 'type' => 'text'],
                ['key' => 'ruc', 'label' => 'RUC', 'type' => 'text'],
                ['key' => 'descripcion', 'label' => 'Descripcion', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.entidades-bancarias.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.entidades-bancarias.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de entidades bancarias', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.entidades-bancarias.create'),
            'editRoute' => 'modules.configuracion.entidades-bancarias.edit',
            'showRoute' => 'modules.configuracion.entidades-bancarias.edit',
            'destroyRoute' => 'modules.configuracion.entidades-bancarias.destroy',
            'identifierKey' => 'identidadBancaria',
            'lockResource' => 'configuracion.entidad_bancaria',
        ]);
    }

    public function entidadesBancariasCreate(): View
    {
        return view('configuracion.entidadbancaria.entidadbancaria-form', [
            'title' => 'Nueva Entidad bancaria',
            'moduleTitle' => 'Configuracion: Entidad bancaria',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.entidades-bancarias.store'),
            'backRoute' => route('modules.configuracion.entidades-bancarias.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'razonSocial',
                    'type' => 'text',
                    'label' => 'Razon social',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Nombre o razon social de la entidad.',
                ],
                [
                    'name' => 'ruc',
                    'type' => 'text',
                    'label' => 'RUC',
                    'required' => true,
                    'maxlength' => 11,
                    'minlength' => 11,
                    'helpText' => 'Solo números, exactamente 11 dígitos.',
                ],
                [
                    'name' => 'descripcion',
                    'type' => 'text',
                    'label' => 'Descripcion',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function entidadesBancariasStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'razonSocial' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'ruc' => ['required', 'string', 'min:11', 'max:11', 'regex:' . self::SAFE_TEXT_REGEX],
            'descripcion' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        $newId = DB::table('entidadbancaria')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.entidad_bancaria', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.entidades-bancarias.index')
            ->with('success', 'Entidad bancaria creada correctamente.');
    }

    public function entidadesBancariasEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('entidadbancaria')->where('identidadBancaria', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.entidades-bancarias.index')
                ->with('error', 'No se encontro la entidad bancaria solicitada.');
        }

        return view('configuracion.entidadbancaria.entidadbancaria-form', [
            'title' => 'Editar Entidad bancaria',
            'moduleTitle' => 'Configuracion: Entidad bancaria',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.entidades-bancarias.update', $id),
            'backRoute' => route('modules.configuracion.entidades-bancarias.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'razonSocial',
                    'type' => 'text',
                    'label' => 'Razon social',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Nombre o razon social de la entidad.',
                ],
                [
                    'name' => 'ruc',
                    'type' => 'text',
                    'label' => 'RUC',
                    'required' => true,
                    'maxlength' => 11,
                    'minlength' => 11,
                    'helpText' => 'Solo números, exactamente 11 dígitos.',
                ],
                [
                    'name' => 'descripcion',
                    'type' => 'text',
                    'label' => 'Descripcion',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.entidad_bancaria', (string) $id));
    }

    public function entidadesBancariasUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('entidadbancaria')->where('identidadBancaria', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.entidades-bancarias.index')
                ->with('error', 'No se encontro la entidad bancaria solicitada.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.entidad_bancaria', (string) $id, 'entidad bancaria', 'modules.configuracion.entidades-bancarias.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'razonSocial' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'ruc' => ['required', 'string', 'min:11', 'max:11', 'regex:' . self::SAFE_TEXT_REGEX],
            'descripcion' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        DB::table('entidadbancaria')->where('identidadBancaria', $id)->update($validated);
        $this->publishResourceEvent('configuracion.entidad_bancaria', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.entidad_bancaria', (string) $id);

        return redirect()
            ->route('modules.configuracion.entidades-bancarias.index')
            ->with('success', 'Entidad bancaria actualizada correctamente.');
    }

    public function entidadesBancariasDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.entidad_bancaria', (string) $id, 'entidad bancaria', 'modules.configuracion.entidades-bancarias.index')) {
            return $redirect;
        }

        try {
            DB::table('entidadbancaria')->where('identidadBancaria', $id)->delete();
            $this->publishResourceEvent('configuracion.entidad_bancaria', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.entidad_bancaria', (string) $id);
            return redirect()
                ->route('modules.configuracion.entidades-bancarias.index')
                ->with('success', 'Entidad bancaria eliminada correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.entidades-bancarias.index')
                ->with('error', 'No se puede eliminar la entidad bancaria porque tiene registros relacionados.');
        }
    }

    public function entidadesBancariasExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('entidadbancaria');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('identidadBancaria', 'like', $term)
                    ->orWhere('razonSocial', 'like', $term)
                    ->orWhere('ruc', 'like', $term)
                    ->orWhere('descripcion', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'identidadBancaria', 'label' => 'ID'],
            ['key' => 'razonSocial', 'label' => 'Razon social'],
            ['key' => 'ruc', 'label' => 'RUC'],
            ['key' => 'descripcion', 'label' => 'Descripcion'],
        ];

        $filename = 'entidad_bancaria_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('identidadBancaria', array_values($selectedIds))->orderBy('identidadBancaria')->get();
    
            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }
    
            return $this->exportPdfResponse($rows, $columns, 'Listado de Entidades Bancarias', $filename);
        }

        $rows = $baseQuery->orderBy('identidadBancaria')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Entidades Bancarias', $filename);
    }

    public function operadoresIndex(Request $request): View
    {
        $baseQuery = DB::table('operador');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idoperador', 'like', $term)
                    ->orWhere('nombre', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('idoperador')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.operador.operador', [
            'title' => 'Configuracion: Operador',
            'singularTitle' => 'Operador',
            'items' => $items,
            'columns' => [
                ['key' => 'idoperador', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.operadores.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.operadores.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de operadores', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.operadores.create'),
            'editRoute' => 'modules.configuracion.operadores.edit',
            'showRoute' => 'modules.configuracion.operadores.edit',
            'destroyRoute' => 'modules.configuracion.operadores.destroy',
            'identifierKey' => 'idoperador',
            'lockResource' => 'configuracion.operador',
        ]);
    }

    public function operadoresCreate(): View
    {
        return view('configuracion.operador.operador-form', [
            'title' => 'Nuevo Operador',
            'moduleTitle' => 'Configuracion: Operador',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.operadores.store'),
            'backRoute' => route('modules.configuracion.operadores.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'nombre',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 30,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function operadoresStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:30', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        $newId = DB::table('operador')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.operador', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.operadores.index')
            ->with('success', 'Operador creado correctamente.');
    }

    public function operadoresEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('operador')->where('idoperador', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.operadores.index')
                ->with('error', 'No se encontro el operador solicitado.');
        }

        return view('configuracion.operador.operador-form', [
            'title' => 'Editar Operador',
            'moduleTitle' => 'Configuracion: Operador',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.operadores.update', $id),
            'backRoute' => route('modules.configuracion.operadores.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'nombre',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 30,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.operador', (string) $id));
    }

    public function operadoresUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('operador')->where('idoperador', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.operadores.index')
                ->with('error', 'No se encontro el operador solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.operador', (string) $id, 'operador', 'modules.configuracion.operadores.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:30', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        DB::table('operador')->where('idoperador', $id)->update($validated);
        $this->publishResourceEvent('configuracion.operador', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.operador', (string) $id);

        return redirect()
            ->route('modules.configuracion.operadores.index')
            ->with('success', 'Operador actualizado correctamente.');
    }

    public function operadoresDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.operador', (string) $id, 'operador', 'modules.configuracion.operadores.index')) {
            return $redirect;
        }

        try {
            DB::table('operador')->where('idoperador', $id)->delete();
            $this->publishResourceEvent('configuracion.operador', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.operador', (string) $id);
            return redirect()
                ->route('modules.configuracion.operadores.index')
                ->with('success', 'Operador eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.operadores.index')
                ->with('error', 'No se puede eliminar el operador porque tiene registros relacionados.');
        }
    }

    public function operadoresExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('operador');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idoperador', 'like', $term)
                    ->orWhere('nombre', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idoperador', 'label' => 'ID'],
            ['key' => 'nombre', 'label' => 'Nombre'],
        ];

        $filename = 'operador_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('idoperador', array_values($selectedIds))->orderBy('idoperador')->get();
    
            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }
    
            return $this->exportPdfResponse($rows, $columns, 'Listado de Operadores', $filename);
        }

        $rows = $baseQuery->orderBy('idoperador')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Operadores', $filename);
    }

    public function tiposVehiculoIndex(Request $request): View
    {
        $baseQuery = DB::table('tipovehiculo');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtipoVehiculo', 'like', $term)
                    ->orWhere('nombre', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('idtipoVehiculo')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.tipovehiculo.tipovehiculo', [
            'title' => 'Configuracion: Tipo de vehículo',
            'singularTitle' => 'Tipo de vehículo',
            'items' => $items,
            'columns' => [
                ['key' => 'idtipoVehiculo', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.tipos-vehiculo.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.tipos-vehiculo.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de tipos de vehículo', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.tipos-vehiculo.create'),
            'editRoute' => 'modules.configuracion.tipos-vehiculo.edit',
            'showRoute' => 'modules.configuracion.tipos-vehiculo.edit',
            'destroyRoute' => 'modules.configuracion.tipos-vehiculo.destroy',
            'identifierKey' => 'idtipoVehiculo',
            'lockResource' => 'configuracion.tipo_vehiculo',
        ]);
    }

    public function tiposVehiculoCreate(): View
    {
        return view('configuracion.tipovehiculo.tipovehiculo-form', [
            'title' => 'Nuevo Tipo de vehículo',
            'moduleTitle' => 'Configuracion: Tipo de vehículo',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.tipos-vehiculo.store'),
            'backRoute' => route('modules.configuracion.tipos-vehiculo.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'nombre',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function tiposVehiculoStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        $newId = DB::table('tipovehiculo')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.tipo_vehiculo', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.tipos-vehiculo.index')
            ->with('success', 'Tipo de vehículo creado correctamente.');
    }

    public function tiposVehiculoEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('tipovehiculo')->where('idtipoVehiculo', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.tipos-vehiculo.index')
                ->with('error', 'No se encontro el tipo de vehículo solicitado.');
        }

        return view('configuracion.tipovehiculo.tipovehiculo-form', [
            'title' => 'Editar Tipo de vehículo',
            'moduleTitle' => 'Configuracion: Tipo de vehículo',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.tipos-vehiculo.update', $id),
            'backRoute' => route('modules.configuracion.tipos-vehiculo.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'nombre',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.tipo_vehiculo', (string) $id));
    }

    public function tiposVehiculoUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('tipovehiculo')->where('idtipoVehiculo', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.tipos-vehiculo.index')
                ->with('error', 'No se encontro el tipo de vehículo solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tipo_vehiculo', (string) $id, 'tipo de vehículo', 'modules.configuracion.tipos-vehiculo.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        DB::table('tipovehiculo')->where('idtipoVehiculo', $id)->update($validated);
        $this->publishResourceEvent('configuracion.tipo_vehiculo', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.tipo_vehiculo', (string) $id);

        return redirect()
            ->route('modules.configuracion.tipos-vehiculo.index')
            ->with('success', 'Tipo de vehículo actualizado correctamente.');
    }

    public function tiposVehiculoDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tipo_vehiculo', (string) $id, 'tipo de vehículo', 'modules.configuracion.tipos-vehiculo.index')) {
            return $redirect;
        }

        try {
            DB::table('tipovehiculo')->where('idtipoVehiculo', $id)->delete();
            $this->publishResourceEvent('configuracion.tipo_vehiculo', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.tipo_vehiculo', (string) $id);
            return redirect()
                ->route('modules.configuracion.tipos-vehiculo.index')
                ->with('success', 'Tipo de vehículo eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.tipos-vehiculo.index')
                ->with('error', 'No se puede eliminar el tipo de vehículo porque tiene registros relacionados.');
        }
    }

    public function tiposVehiculoExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('tipovehiculo');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtipoVehiculo', 'like', $term)
                    ->orWhere('nombre', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idtipoVehiculo', 'label' => 'ID'],
            ['key' => 'nombre', 'label' => 'Nombre'],
        ];

        $filename = 'tipo_vehiculo_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('idtipoVehiculo', array_values($selectedIds))->orderBy('idtipoVehiculo')->get();
    
            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }
    
            return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Vehículo', $filename);
        }

        $rows = $baseQuery->orderBy('idtipoVehiculo')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Vehículo', $filename);
    }

    public function tiposOperacionIndex(Request $request): View
    {
        $baseQuery = DB::table('tipooperacion');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtipoOperacion', 'like', $term)
                    ->orWhere('nomenclatura', 'like', $term)
                    ->orWhere('detalle', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('idtipoOperacion')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.tipooperacion.tipooperacion', [
            'title' => 'Configuracion: Tipo de operación',
            'singularTitle' => 'Tipo de operación',
            'items' => $items,
            'columns' => [
                ['key' => 'idtipoOperacion', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'nomenclatura', 'label' => 'Nomenclatura', 'type' => 'text'],
                ['key' => 'detalle', 'label' => 'Detalle', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.tipos-operacion.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.tipos-operacion.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de tipos de operación', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.tipos-operacion.create'),
            'editRoute' => 'modules.configuracion.tipos-operacion.edit',
            'showRoute' => 'modules.configuracion.tipos-operacion.edit',
            'destroyRoute' => 'modules.configuracion.tipos-operacion.destroy',
            'identifierKey' => 'idtipoOperacion',
            'lockResource' => 'configuracion.tipo_operacion',
        ]);
    }

    public function tiposOperacionCreate(): View
    {
        return view('configuracion.tipooperacion.tipooperacion-form', [
            'title' => 'Nuevo Tipo de operación',
            'moduleTitle' => 'Configuracion: Tipo de operación',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.tipos-operacion.store'),
            'backRoute' => route('modules.configuracion.tipos-operacion.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'nomenclatura',
                    'type' => 'text',
                    'label' => 'Nomenclatura',
                    'required' => true,
                    'maxlength' => 2,
                    'minlength' => 1,
                    'helpText' => 'Mínimo 1 carácter.',
                ],
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function tiposOperacionStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nomenclatura' => ['required', 'string', 'min:1', 'max:2', 'regex:' . self::SAFE_TEXT_REGEX],
            'detalle' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        $newId = DB::table('tipooperacion')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.tipo_operacion', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.tipos-operacion.index')
            ->with('success', 'Tipo de operación creado correctamente.');
    }

    public function tiposOperacionEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('tipooperacion')->where('idtipoOperacion', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.tipos-operacion.index')
                ->with('error', 'No se encontro el tipo de operación solicitado.');
        }

        return view('configuracion.tipooperacion.tipooperacion-form', [
            'title' => 'Editar Tipo de operación',
            'moduleTitle' => 'Configuracion: Tipo de operación',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.tipos-operacion.update', $id),
            'backRoute' => route('modules.configuracion.tipos-operacion.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'nomenclatura',
                    'type' => 'text',
                    'label' => 'Nomenclatura',
                    'required' => true,
                    'maxlength' => 2,
                    'minlength' => 1,
                    'helpText' => 'Mínimo 1 carácter.',
                ],
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.tipo_operacion', (string) $id));
    }

    public function tiposOperacionUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('tipooperacion')->where('idtipoOperacion', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.tipos-operacion.index')
                ->with('error', 'No se encontro el tipo de operación solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tipo_operacion', (string) $id, 'tipo de operación', 'modules.configuracion.tipos-operacion.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'nomenclatura' => ['required', 'string', 'min:1', 'max:2', 'regex:' . self::SAFE_TEXT_REGEX],
            'detalle' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        DB::table('tipooperacion')->where('idtipoOperacion', $id)->update($validated);
        $this->publishResourceEvent('configuracion.tipo_operacion', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.tipo_operacion', (string) $id);

        return redirect()
            ->route('modules.configuracion.tipos-operacion.index')
            ->with('success', 'Tipo de operación actualizado correctamente.');
    }

    public function tiposOperacionDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tipo_operacion', (string) $id, 'tipo de operación', 'modules.configuracion.tipos-operacion.index')) {
            return $redirect;
        }

        try {
            DB::table('tipooperacion')->where('idtipoOperacion', $id)->delete();
            $this->publishResourceEvent('configuracion.tipo_operacion', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.tipo_operacion', (string) $id);
            return redirect()
                ->route('modules.configuracion.tipos-operacion.index')
                ->with('success', 'Tipo de operación eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.tipos-operacion.index')
                ->with('error', 'No se puede eliminar el tipo de operación porque tiene registros relacionados.');
        }
    }

    public function tiposOperacionExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('tipooperacion');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtipoOperacion', 'like', $term)
                    ->orWhere('nomenclatura', 'like', $term)
                    ->orWhere('detalle', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idtipoOperacion', 'label' => 'ID'],
            ['key' => 'nomenclatura', 'label' => 'Nomenclatura'],
            ['key' => 'detalle', 'label' => 'Detalle'],
        ];

        $filename = 'tipo_operacion_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('idtipoOperacion', array_values($selectedIds))->orderBy('idtipoOperacion')->get();
    
            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }
    
            return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Operación', $filename);
        }

        $rows = $baseQuery->orderBy('idtipoOperacion')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Operación', $filename);
    }

    public function listaprecioIndex(Request $request): View
    {
        $baseQuery = DB::table('listaprecio');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idListaPrecio', 'like', $term)
                    ->orWhere('nombreLista', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('idListaPrecio')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.listaprecio.listaprecio', [
            'title' => 'Configuracion: Lista de precio',
            'singularTitle' => 'Lista de precio',
            'items' => $items,
            'columns' => [
                ['key' => 'idListaPrecio', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'nombreLista', 'label' => 'Nombre lista', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.listas-precio.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.listas-precio.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de listas de precio', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.listas-precio.create'),
            'editRoute' => 'modules.configuracion.listas-precio.edit',
            'showRoute' => 'modules.configuracion.listas-precio.edit',
            'destroyRoute' => 'modules.configuracion.listas-precio.destroy',
            'identifierKey' => 'idListaPrecio',
            'lockResource' => 'configuracion.lista_precio',
        ]);
    }

    public function listaprecioCreate(): View
    {
        return view('configuracion.listaprecio.listaprecio-form', [
            'title' => 'Nueva Lista de precio',
            'moduleTitle' => 'Configuracion: Lista de precio',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.listas-precio.store'),
            'backRoute' => route('modules.configuracion.listas-precio.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'nombreLista',
                    'type' => 'text',
                    'label' => 'Nombre lista',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Nombre de la lista de precio.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function listaprecioStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nombreLista' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        $newId = DB::table('listaprecio')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.lista_precio', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.listas-precio.index')
            ->with('success', 'Lista de precio creada correctamente.');
    }

    public function listaprecioEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('listaprecio')->where('idListaPrecio', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.listas-precio.index')
                ->with('error', 'No se encontro la lista de precio solicitada.');
        }

        return view('configuracion.listaprecio.listaprecio-form', [
            'title' => 'Editar Lista de precio',
            'moduleTitle' => 'Configuracion: Lista de precio',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.listas-precio.update', $id),
            'backRoute' => route('modules.configuracion.listas-precio.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'nombreLista',
                    'type' => 'text',
                    'label' => 'Nombre lista',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Nombre de la lista de precio.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.lista_precio', (string) $id));
    }

    public function listaprecioUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('listaprecio')->where('idListaPrecio', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.listas-precio.index')
                ->with('error', 'No se encontro la lista de precio solicitada.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.lista_precio', (string) $id, 'lista de precio', 'modules.configuracion.listas-precio.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'nombreLista' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        DB::table('listaprecio')->where('idListaPrecio', $id)->update($validated);
        $this->publishResourceEvent('configuracion.lista_precio', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.lista_precio', (string) $id);

        return redirect()
            ->route('modules.configuracion.listas-precio.index')
            ->with('success', 'Lista de precio actualizada correctamente.');
    }

    public function listaprecioDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.lista_precio', (string) $id, 'lista de precio', 'modules.configuracion.listas-precio.index')) {
            return $redirect;
        }

        try {
            DB::table('listaprecio')->where('idListaPrecio', $id)->delete();
            $this->publishResourceEvent('configuracion.lista_precio', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.lista_precio', (string) $id);
            return redirect()
                ->route('modules.configuracion.listas-precio.index')
                ->with('success', 'Lista de precio eliminada correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.listas-precio.index')
                ->with('error', 'No se puede eliminar la lista de precio porque tiene registros relacionados.');
        }
    }

    public function listaprecioExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('listaprecio');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idListaPrecio', 'like', $term)
                    ->orWhere('nombreLista', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idListaPrecio', 'label' => 'ID'],
            ['key' => 'nombreLista', 'label' => 'Nombre lista'],
        ];

        $filename = 'listaprecio_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('idListaPrecio', array_values($selectedIds))->orderBy('idListaPrecio')->get();
    
            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }
    
            return $this->exportPdfResponse($rows, $columns, 'Listado de Listas de Precio', $filename);
        }

        $rows = $baseQuery->orderBy('idListaPrecio')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Listas de Precio', $filename);
    }

    public function detalleListaPrecioIndex(Request $request): View
    {
        $baseQuery = DB::table('detallelistaprecio as d')
            ->leftJoin('almacen as a', 'a.idalmacen', '=', 'd.almacen_idalmacen')
            ->leftJoin('empresapropietaria as ep', 'a.empresaPropietaria_RUC', '=', 'ep.RUC')
            ->leftJoin('listaprecio as lp', 'lp.idListaPrecio', '=', 'd.ListaPrecio_idListaPrecio')
            ->select([
                'd.iddetalleListaPrecio',
                'd.almacen_idalmacen',
                'd.ListaPrecio_idListaPrecio',
                'd.precio',
                DB::raw('COALESCE(a.detalle, "") as almacen_detalle'),
                DB::raw('TRIM(CONCAT(COALESCE(NULLIF(TRIM(ep.razonSocial), ""), "Sin empresa"), " - ", COALESCE(NULLIF(TRIM(a.detalle), ""), "Sin dispositivo"))) as almacen_label'),
                DB::raw('COALESCE(lp.nombreLista, "") as listaprecio_nombre'),
            ]);
            $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('d.iddetalleListaPrecio', 'like', $term)
                    ->orWhere('d.almacen_idalmacen', 'like', $term)
                    ->orWhere('d.ListaPrecio_idListaPrecio', 'like', $term)
                    ->orWhere('ep.razonSocial', 'like', $term)
                    ->orWhere('a.detalle', 'like', $term)
                    ->orWhere('lp.nombreLista', 'like', $term)
                    ->orWhere('d.precio', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('d.iddetalleListaPrecio')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.detallelistaprecio.detallelistaprecio', [
            'title' => 'Configuracion: Detalle Lista de Precio',
            'singularTitle' => 'Detalle Lista de Precio',
            'items' => $items,
            'columns' => [
                ['key' => 'iddetalleListaPrecio', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'almacen_label', 'label' => 'Almacén', 'type' => 'text', 'wrap' => true],
                ['key' => 'listaprecio_nombre', 'label' => 'Lista de precio', 'type' => 'text'],
                ['key' => 'precio', 'label' => 'Precio', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.detalle-lista-precio.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.detalle-lista-precio.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de detalles de lista de precio', 'value' => (clone $baseQuery)->count('d.iddetalleListaPrecio')],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.detalle-lista-precio.create'),
            'editRoute' => 'modules.configuracion.detalle-lista-precio.edit',
            'showRoute' => 'modules.configuracion.detalle-lista-precio.edit',
            'destroyRoute' => 'modules.configuracion.detalle-lista-precio.destroy',
            'bulkDestroyRoute' => route('modules.configuracion.detalle-lista-precio.bulk-destroy'),
            'identifierKey' => 'iddetalleListaPrecio',
            'lockResource' => 'configuracion.detalle_lista_precio',
        ]);
    }

    public function detalleListaPrecioCreate(): View
    {
        return view('configuracion.detallelistaprecio.detallelistaprecio-form', [
            'title' => 'Nuevo Detalle Lista de Precio',
            'moduleTitle' => 'Configuracion: Detalle Lista de Precio',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.detalle-lista-precio.store'),
            'backRoute' => route('modules.configuracion.detalle-lista-precio.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'almacen_idalmacen',
                    'type' => 'select',
                    'label' => 'Almacén',
                    'required' => true,
                    'tomSelect' => true,
                    'placeholder' => 'Selecciona un almacén',
                    'optionsData' => $this->almacenOptions(),
                    'optionKey' => 'idalmacen',
                    'optionLabel' => 'label',
                ],
                [
                    'name' => 'ListaPrecio_idListaPrecio',
                    'type' => 'select',
                    'label' => 'Lista de precio',
                    'required' => true,
                    'tomSelect' => true,
                    'placeholder' => 'Selecciona una lista de precio',
                    'optionsData' => $this->listaprecioOptions(),
                    'optionKey' => 'idListaPrecio',
                    'optionLabel' => 'nombreLista',
                ],
                [
                    'name' => 'precio',
                    'type' => 'number',
                    'label' => 'Precio',
                    'required' => true,
                    'step' => '0.01',
                    'min' => 0,
                    'helpText' => 'Precio unitario para el almacén y la lista seleccionados.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function detalleListaPrecioStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'almacen_idalmacen' => ['required', 'integer', 'exists:almacen,idalmacen'],
            'ListaPrecio_idListaPrecio' => ['required', 'integer', 'exists:listaprecio,idListaPrecio'],
            'precio' => ['required', 'numeric', 'min:0'],
        ]);

        $newId = DB::table('detallelistaprecio')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.detalle_lista_precio', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.detalle-lista-precio.index')
            ->with('success', 'Detalle de lista de precio creado correctamente.');
    }

    public function detalleListaPrecioEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('detallelistaprecio')->where('iddetalleListaPrecio', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.detalle-lista-precio.index')
                ->with('error', 'No se encontro el detalle de lista de precio solicitado.');
        }

        return view('configuracion.detallelistaprecio.detallelistaprecio-form', [
            'title' => 'Editar Detalle Lista de Precio',
            'moduleTitle' => 'Configuracion: Detalle Lista de Precio',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.detalle-lista-precio.update', $id),
            'backRoute' => route('modules.configuracion.detalle-lista-precio.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'almacen_idalmacen',
                    'type' => 'select',
                    'label' => 'Almacén',
                    'required' => true,
                    'tomSelect' => true,
                    'placeholder' => 'Selecciona un almacén',
                    'optionsData' => $this->almacenOptions(),
                    'optionKey' => 'idalmacen',
                    'optionLabel' => 'label',
                ],
                [
                    'name' => 'ListaPrecio_idListaPrecio',
                    'type' => 'select',
                    'label' => 'Lista de precio',
                    'required' => true,
                    'tomSelect' => true,
                    'placeholder' => 'Selecciona una lista de precio',
                    'optionsData' => $this->listaprecioOptions(),
                    'optionKey' => 'idListaPrecio',
                    'optionLabel' => 'nombreLista',
                ],
                [
                    'name' => 'precio',
                    'type' => 'number',
                    'label' => 'Precio',
                    'required' => true,
                    'step' => '0.01',
                    'min' => 0,
                    'helpText' => 'Precio unitario para el almacén y la lista seleccionados.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.detalle_lista_precio', (string) $id));
    }

    public function detalleListaPrecioUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('detallelistaprecio')->where('iddetalleListaPrecio', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.detalle-lista-precio.index')
                ->with('error', 'No se encontro el detalle de lista de precio solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.detalle_lista_precio', (string) $id, 'detalle de lista de precio', 'modules.configuracion.detalle-lista-precio.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'almacen_idalmacen' => ['required', 'integer', 'exists:almacen,idalmacen'],
            'ListaPrecio_idListaPrecio' => ['required', 'integer', 'exists:listaprecio,idListaPrecio'],
            'precio' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::table('detallelistaprecio')->where('iddetalleListaPrecio', $id)->update($validated);
        $this->publishResourceEvent('configuracion.detalle_lista_precio', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.detalle_lista_precio', (string) $id);

        return redirect()
            ->route('modules.configuracion.detalle-lista-precio.index')
            ->with('success', 'Detalle de lista de precio actualizado correctamente.');
    }

    public function detalleListaPrecioDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.detalle_lista_precio', (string) $id, 'detalle de lista de precio', 'modules.configuracion.detalle-lista-precio.index')) {
            return $redirect;
        }

        try {
            DB::table('detallelistaprecio')->where('iddetalleListaPrecio', $id)->delete();
            $this->publishResourceEvent('configuracion.detalle_lista_precio', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.detalle_lista_precio', (string) $id);
            return redirect()
                ->route('modules.configuracion.detalle-lista-precio.index')
                ->with('success', 'Detalle de lista de precio eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.detalle-lista-precio.index')
                ->with('error', 'No se puede eliminar el detalle de lista de precio porque tiene registros relacionados.');
        }
    }

    public function detalleListaPrecioExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('detallelistaprecio as d')
            ->leftJoin('almacen as a', 'a.idalmacen', '=', 'd.almacen_idalmacen')
            ->leftJoin('listaprecio as lp', 'lp.idListaPrecio', '=', 'd.ListaPrecio_idListaPrecio')
            ->leftJoin('empresapropietaria as ep', 'a.empresaPropietaria_RUC', '=', 'ep.RUC')
            ->select([
                'd.iddetalleListaPrecio',
                'd.almacen_idalmacen',
                'd.ListaPrecio_idListaPrecio',
                'd.precio',
                DB::raw('COALESCE(a.detalle, "") as almacen_detalle'),
                DB::raw('COALESCE(lp.nombreLista, "") as listaprecio_nombre'),
                DB::raw('TRIM(CONCAT(COALESCE(NULLIF(TRIM(ep.razonSocial), ""), "Sin empresa"), " - ", COALESCE(NULLIF(TRIM(a.detalle), ""), "Sin dispositivo"))) as almacen_label'),
            ]);
            $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('d.iddetalleListaPrecio', 'like', $term)
                    ->orWhere('d.almacen_idalmacen', 'like', $term)
                    ->orWhere('d.ListaPrecio_idListaPrecio', 'like', $term)
                    ->orWhere('ep.razonSocial', 'like', $term)
                    ->orWhere('a.detalle', 'like', $term)
                    ->orWhere('lp.nombreLista', 'like', $term)
                    ->orWhere('d.precio', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'iddetalleListaPrecio', 'label' => 'ID'],
            ['key' => 'almacen_label', 'label' => 'Almacén'],
            ['key' => 'listaprecio_nombre', 'label' => 'Lista de precio'],
            ['key' => 'precio', 'label' => 'Precio'],
        ];

        $filename = 'detalle_lista_precio_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('d.iddetalleListaPrecio', array_values($selectedIds))->orderBy('d.iddetalleListaPrecio')->get();
    
            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }
    
            return $this->exportPdfResponse($rows, $columns, 'Listado de Detalles de Lista de Precio', $filename);
        }

        $rows = $baseQuery->orderBy('d.iddetalleListaPrecio')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Detalles de Lista de Precio', $filename);
    }

    private function almacenOptions()
    {
        return DB::table('almacen as a')
            ->leftJoin('empresapropietaria as ep', 'a.empresaPropietaria_RUC', '=', 'ep.RUC')
            ->select([
                'a.idalmacen',
                'a.detalle',
                'ep.razonSocial',
            ])
            ->orderBy('ep.razonSocial')
            ->orderBy('a.detalle')
            ->get() 
            ->map(fn ($row): array => [
                'value' => (string) $row->idalmacen,
                'label' => trim(
                    (string) (
                        trim((string) ($row->razonSocial ?? '')) !== ''
                            ? trim((string) $row->razonSocial)
                            : 'Sin empresa'
                    ) . ' - ' . trim((string) ($row->detalle ?? 'Sin detalle'))
                ),
                'idalmacen' => (int) $row->idalmacen,
                'detalle' => trim((string) ($row->detalle ?? 'Sin detalle')),
                'razonSocial' => trim((string) ($row->razonSocial ?? '')),
            ]);
    }

    private function listaprecioOptions()
    {
        return DB::table('listaprecio')
            ->select(['idListaPrecio', 'nombreLista'])
            ->orderBy('nombreLista')
            ->get();
    }

    private function detailPaqueteItems(int $paqueteId)
    {
        return DB::table('detallepaquete')
            ->select(['iddetallepaquete', 'paquetes_idpaquetes', 'almacen_idalmacen', 'precio'])
            ->where('paquetes_idpaquetes', $paqueteId)
            ->orderBy('iddetallepaquete')
            ->get();
    }

    private function syncDetallePaquetePayload(Request $request, int $paqueteId): void
    {
        // Aceptamos tanto `detalle[]` (array desde formulario) como `detalle_paquete_payload` (JSON)
        $rawDetalleArray = $request->input('detalle');
        if (is_array($rawDetalleArray)) {
            $payload = $rawDetalleArray;
        } else {
            $rawPayload = $request->input('detalle_paquete_payload', '[]');
            $payload = is_string($rawPayload) ? json_decode($rawPayload, true) : $rawPayload;
        }

        if (!is_array($payload) || empty($payload)) {
            DB::table('detallepaquete')->where('paquetes_idpaquetes', $paqueteId)->delete();
            return;
        }

        $normalized = collect($payload)
            ->filter(fn ($item) => is_array($item) || is_object($item))
            ->map(function ($item): array {
                $arr = is_array($item) ? $item : (array) $item;
                return [
                    // Usar exclusivamente el id de almacén enviado por el formulario.
                    // NO tomar el valor de ListaPrecio_idListaPrecio como id de almacén.
                    'almacen_idalmacen' => (int) data_get($arr, 'almacen_idalmacen', 0),
                    'precio' => data_get($arr, 'precio', null),
                ];
            })
            ->values()
            ->all();

        
        $messages = [
            'items.*.almacen_idalmacen.distinct' => 'El campo :attribute tiene un valor duplicado.',
            'items.*.almacen_idalmacen.required' => 'El campo :attribute es obligatorio.',
            'items.*.almacen_idalmacen.integer' => 'El campo :attribute debe ser un número entero.',
            'items.*.almacen_idalmacen.exists' => 'El :attribute seleccionado no existe.',
            'items.*.precio.required' => 'El campo precio es obligatorio.',
            'items.*.precio.numeric' => 'El campo precio debe ser un número válido.',
            'items.*.precio.min' => 'El campo precio debe ser como mínimo :min.',
        ];

        $customAttributes = [
            'items.*.almacen_idalmacen' => 'almacén',
            'items.*.precio' => 'precio',
        ];

        \Illuminate\Support\Facades\Validator::make(['items' => $normalized], [
            'items' => ['array'],
            'items.*.almacen_idalmacen' => ['required', 'integer', 'distinct', 'exists:almacen,idalmacen'],
            'items.*.precio' => ['required', 'numeric', 'min:0'],
        ], $messages, $customAttributes)->validate();

        DB::transaction(function () use ($paqueteId, $normalized) {
            DB::table('detallepaquete')->where('paquetes_idpaquetes', $paqueteId)->delete();

            foreach ($normalized as $item) {
                $newId = DB::table('detallepaquete')->insertGetId([
                    'paquetes_idpaquetes' => $paqueteId,
                    'almacen_idalmacen' => (int) $item['almacen_idalmacen'],
                    'precio' => $item['precio'],
                ]);
                $this->publishResourceEvent('configuracion.detallepaquete', (string) $newId, 'created');
            }
        });
    }

    private function formatDateTimeForFormValue($value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d\TH:i');
        } catch (\Throwable) {
            return null;
        }
    }

    public function tipopedidoIndex(Request $request): View
    {
        $baseQuery = DB::table('tipopedido');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtipoPedido', 'like', $term)
                    ->orWhere('nomenclatura', 'like', $term)
                    ->orWhere('detalle', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('idtipoPedido')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.tipopedido.tipopedido', [
            'title' => 'Configuracion: Tipo de pedido',
            'singularTitle' => 'Tipo de pedido',
            'items' => $items,
            'columns' => [
                ['key' => 'idtipoPedido', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'nomenclatura', 'label' => 'Nomenclatura', 'type' => 'text'],
                ['key' => 'detalle', 'label' => 'Detalle', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.tipos-pedido.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.tipos-pedido.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de tipos de pedido', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.tipos-pedido.create'),
            'editRoute' => 'modules.configuracion.tipos-pedido.edit',
            'showRoute' => 'modules.configuracion.tipos-pedido.edit',
            'destroyRoute' => 'modules.configuracion.tipos-pedido.destroy',
            'identifierKey' => 'idtipoPedido',
            'lockResource' => 'configuracion.tipo_pedido',
        ]);
    }

    public function tipopedidoCreate(): View
    {
        return view('configuracion.tipopedido.tipopedido-form', [
            'title' => 'Nuevo Tipo de pedido',
            'moduleTitle' => 'Configuracion: Tipo de pedido',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.tipos-pedido.store'),
            'backRoute' => route('modules.configuracion.tipos-pedido.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'nomenclatura',
                    'type' => 'text',
                    'label' => 'Nomenclatura',
                    'required' => true,
                    'maxlength' => 2,
                    'minlength' => 1,
                    'helpText' => 'Mínimo 1 carácter.',
                ],
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function tipopedidoStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nomenclatura' => ['required', 'string', 'min:1', 'max:2', 'regex:' . self::SAFE_TEXT_REGEX],
            'detalle' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        $newId = DB::table('tipopedido')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.tipo_pedido', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.tipos-pedido.index')
            ->with('success', 'Tipo de pedido creado correctamente.');
    }

    public function tipopedidoEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('tipopedido')->where('idtipoPedido', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.tipos-pedido.index')
                ->with('error', 'No se encontro el tipo de pedido solicitado.');
        }

        return view('configuracion.tipopedido.tipopedido-form', [
            'title' => 'Editar Tipo de pedido',
            'moduleTitle' => 'Configuracion: Tipo de pedido',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.tipos-pedido.update', $id),
            'backRoute' => route('modules.configuracion.tipos-pedido.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'nomenclatura',
                    'type' => 'text',
                    'label' => 'Nomenclatura',
                    'required' => true,
                    'maxlength' => 2,
                    'minlength' => 1,
                    'helpText' => 'Mínimo 1 carácter.',
                ],
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.tipo_pedido', (string) $id));
    }

    public function tipopedidoUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('tipopedido')->where('idtipoPedido', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.tipos-pedido.index')
                ->with('error', 'No se encontro el tipo de pedido solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tipo_pedido', (string) $id, 'tipo de pedido', 'modules.configuracion.tipos-pedido.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'nomenclatura' => ['required', 'string', 'min:1', 'max:2', 'regex:' . self::SAFE_TEXT_REGEX],
            'detalle' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        DB::table('tipopedido')->where('idtipoPedido', $id)->update($validated);
        $this->publishResourceEvent('configuracion.tipo_pedido', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.tipo_pedido', (string) $id);

        return redirect()
            ->route('modules.configuracion.tipos-pedido.index')
            ->with('success', 'Tipo de pedido actualizado correctamente.');
    }

    public function tipopedidoDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tipo_pedido', (string) $id, 'tipo de pedido', 'modules.configuracion.tipos-pedido.index')) {
            return $redirect;
        }

        try {
            DB::table('tipopedido')->where('idtipoPedido', $id)->delete();
            $this->publishResourceEvent('configuracion.tipo_pedido', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.tipo_pedido', (string) $id);
            return redirect()
                ->route('modules.configuracion.tipos-pedido.index')
                ->with('success', 'Tipo de pedido eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.tipos-pedido.index')
                ->with('error', 'No se puede eliminar el tipo de pedido porque tiene registros relacionados.');
        }
    }

    public function tipopedidoExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('tipopedido');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtipoPedido', 'like', $term)
                    ->orWhere('nomenclatura', 'like', $term)
                    ->orWhere('detalle', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idtipoPedido', 'label' => 'ID'],
            ['key' => 'nomenclatura', 'label' => 'Nomenclatura'],
            ['key' => 'detalle', 'label' => 'Detalle'],
        ];

        $filename = 'tipo_pedido_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('idtipoPedido', array_values($selectedIds))->orderBy('idtipoPedido')->get();
    
            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }
    
            return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Pedido', $filename);
        }

        $rows = $baseQuery->orderBy('idtipoPedido')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Pedido', $filename);
    }

    public function proveedorIndex(Request $request): View
    {
        $baseQuery = DB::table('proveedor');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idproveedor', 'like', $term)
                    ->orWhere('razonSocial', 'like', $term)
                    ->orWhere('tipoProveedor', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('idproveedor')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.proveedor.proveedor', [
            'title' => 'Configuracion: Proveedor',
            'singularTitle' => 'Proveedor',
            'items' => $items,
            'columns' => [
                ['key' => 'idproveedor', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'razonSocial', 'label' => 'Razón social', 'type' => 'text'],
                ['key' => 'tipoProveedor', 'label' => 'Tipo proveedor', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.proveedores.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.proveedores.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de proveedores', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.proveedores.create'),
            'editRoute' => 'modules.configuracion.proveedores.edit',
            'showRoute' => 'modules.configuracion.proveedores.edit',
            'destroyRoute' => 'modules.configuracion.proveedores.destroy',
            'identifierKey' => 'idproveedor',
            'lockResource' => 'configuracion.proveedor',
        ]);
    }

    public function proveedorCreate(): View
    {
        return view('configuracion.proveedor.proveedor-form', [
            'title' => 'Nuevo Proveedor',
            'moduleTitle' => 'Configuracion: Proveedor',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.proveedores.store'),
            'backRoute' => route('modules.configuracion.proveedores.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'idproveedor',
                    'type' => 'text',
                    'label' => 'ID Proveedor',
                    'required' => true,
                    'maxlength' => 15,
                    'minlength' => 1,
                    'helpText' => 'Identificador único del proveedor.',
                ],
                [
                    'name' => 'razonSocial',
                    'type' => 'text',
                    'label' => 'Razón social',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Nombre o razón social del proveedor.',
                ],
                [
                    'name' => 'tipoProveedor',
                    'type' => 'text',
                    'label' => 'Tipo proveedor',
                    'required' => true,
                    'maxlength' => 45,
                    'minlength' => 2,
                    'helpText' => 'Tipo de proveedor.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function proveedorStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'idproveedor' => ['required', 'string', 'min:1', 'max:15', 'regex:' . self::SAFE_TEXT_REGEX, Rule::unique('proveedor', 'idproveedor')],
            'razonSocial' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'tipoProveedor' => ['required', 'string', 'min:2', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
        ], [
            'idproveedor.unique' => 'El ID del proveedor ya existe.',
        ]);

        try {
            DB::table('proveedor')->insert($validated);
            $this->publishResourceEvent('configuracion.proveedor', $validated['idproveedor'] ?? '', 'created');
        } catch (QueryException $exception) {
            if ($this->isDuplicateKeyException($exception)) {
                return redirect()
                    ->back()
                    ->withInput()
                    ->with('error', 'El ID del proveedor ya existe.');
            }

            throw $exception;
        }

        return redirect()
            ->route('modules.configuracion.proveedores.index')
            ->with('success', 'Proveedor creado correctamente.');
    }

    public function proveedorEdit(string $id): View|RedirectResponse
    {
        $record = DB::table('proveedor')->where('idproveedor', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.proveedores.index')
                ->with('error', 'No se encontro el proveedor solicitado.');
        }

        return view('configuracion.proveedor.proveedor-form', [
            'title' => 'Editar Proveedor',
            'moduleTitle' => 'Configuracion: Proveedor',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.proveedores.update', $id),
            'backRoute' => route('modules.configuracion.proveedores.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'idproveedor',
                    'type' => 'text',
                    'label' => 'ID Proveedor',
                    'required' => true,
                    'maxlength' => 15,
                    'minlength' => 1,
                    'helpText' => 'Identificador único del proveedor.',
                    'editable' => true,
                ],
                [
                    'name' => 'razonSocial',
                    'type' => 'text',
                    'label' => 'Razón social',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Nombre o razón social del proveedor.',
                ],
                [
                    'name' => 'tipoProveedor',
                    'type' => 'text',
                    'label' => 'Tipo proveedor',
                    'required' => true,
                    'maxlength' => 45,
                    'minlength' => 2,
                    'helpText' => 'Tipo de proveedor.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.proveedor', $id));
    }

    public function proveedorUpdate(Request $request, string $id): RedirectResponse
    {
        $exists = DB::table('proveedor')->where('idproveedor', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.proveedores.index')
                ->with('error', 'No se encontro el proveedor solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.proveedor', $id, 'proveedor', 'modules.configuracion.proveedores.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'idproveedor' => ['required', 'string', 'min:1', 'max:15', 'regex:' . self::SAFE_TEXT_REGEX, Rule::unique('proveedor', 'idproveedor')->ignore($id, 'idproveedor')],
            'razonSocial' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'tipoProveedor' => ['required', 'string', 'min:2', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
        ], [
            'idproveedor.unique' => 'El ID del proveedor ya existe.',
        ]);

        try {
            DB::table('proveedor')->where('idproveedor', $id)->update($validated);
            $this->publishResourceEvent('configuracion.proveedor', $validated['idproveedor'] ?? $id, 'updated');
        } catch (QueryException $exception) {
            if ($this->isDuplicateKeyException($exception)) {
                return redirect()
                    ->back()
                    ->withInput()
                    ->with('error', 'El ID del proveedor ya existe.');
            }

            throw $exception;
        }

        $this->releaseLockIfOwned($request, 'configuracion.proveedor', $id);

        return redirect()
            ->route('modules.configuracion.proveedores.index')
            ->with('success', 'Proveedor actualizado correctamente.');
    }

    public function proveedorDestroy(Request $request, string $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.proveedor', $id, 'proveedor', 'modules.configuracion.proveedores.index')) {
            return $redirect;
        }

        try {
            DB::table('proveedor')->where('idproveedor', $id)->delete();
            $this->publishResourceEvent('configuracion.proveedor', $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.proveedor', $id);
            return redirect()
                ->route('modules.configuracion.proveedores.index')
                ->with('success', 'Proveedor eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.proveedores.index')
                ->with('error', 'No se puede eliminar el proveedor porque tiene registros relacionados.');
        }
    }

    private function isDuplicateKeyException(QueryException $exception): bool
    {
        $message = $exception->getMessage();
        return (string) $exception->getCode() === '23000'
            && (str_contains($message, 'Duplicate entry') || str_contains($message, '1062'));
    }

    public function proveedorExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('proveedor');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idproveedor', 'like', $term)
                    ->orWhere('razonSocial', 'like', $term)
                    ->orWhere('tipoProveedor', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idproveedor', 'label' => 'ID'],
            ['key' => 'razonSocial', 'label' => 'Razón social'],
            ['key' => 'tipoProveedor', 'label' => 'Tipo proveedor'],
        ];

        $filename = 'proveedor_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('idproveedor', array_values($selectedIds))->orderBy('idproveedor')->get();
    
            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }
    
            return $this->exportPdfResponse($rows, $columns, 'Listado de Proveedores', $filename);
        }

        $rows = $baseQuery->orderBy('idproveedor')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Proveedores', $filename);
    }

    public function vigenciaofertaIndex(Request $request): View
    {
        $baseQuery = DB::table('vigenciaoferta');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idvigenciaOferta', 'like', $term)
                    ->orWhere('detalle', 'like', $term)
                    ->orWhere('dias', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('idvigenciaOferta')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.vigenciaoferta.vigenciaoferta', [
            'title' => 'Configuracion: Vigencia de oferta',
            'singularTitle' => 'Vigencia de oferta',
            'items' => $items,
            'columns' => [
                ['key' => 'idvigenciaOferta', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'detalle', 'label' => 'Detalle', 'type' => 'text'],
                ['key' => 'dias', 'label' => 'Días', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.vigencias-oferta.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.vigencias-oferta.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de vigencias de oferta', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.vigencias-oferta.create'),
            'editRoute' => 'modules.configuracion.vigencias-oferta.edit',
            'showRoute' => 'modules.configuracion.vigencias-oferta.edit',
            'destroyRoute' => 'modules.configuracion.vigencias-oferta.destroy',
            'identifierKey' => 'idvigenciaOferta',
            'lockResource' => 'configuracion.vigencia_oferta',
        ]);
    }

    public function vigenciaofertaCreate(): View
    {
        return view('configuracion.vigenciaoferta.vigenciaoferta-form', [
            'title' => 'Nueva Vigencia de oferta',
            'moduleTitle' => 'Configuracion: Vigencia de oferta',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.vigencias-oferta.store'),
            'backRoute' => route('modules.configuracion.vigencias-oferta.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Detalle de la vigencia de oferta.',
                ],
                [
                    'name' => 'dias',
                    'type' => 'number',
                    'label' => 'Días',
                    'required' => false,
                    'min' => 0,
                    'helpText' => 'Cantidad de días de vigencia.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function vigenciaofertaStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'detalle' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'dias' => ['nullable', 'integer', 'min:0'],
        ]);

        $newId = DB::table('vigenciaoferta')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.vigencia_oferta', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.vigencias-oferta.index')
            ->with('success', 'Vigencia de oferta creada correctamente.');
    }

    public function vigenciaofertaEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('vigenciaoferta')->where('idvigenciaOferta', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.vigencias-oferta.index')
                ->with('error', 'No se encontro la vigencia de oferta solicitada.');
        }

        return view('configuracion.vigenciaoferta.vigenciaoferta-form', [
            'title' => 'Editar Vigencia de oferta',
            'moduleTitle' => 'Configuracion: Vigencia de oferta',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.vigencias-oferta.update', $id),
            'backRoute' => route('modules.configuracion.vigencias-oferta.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Detalle de la vigencia de oferta.',
                ],
                [
                    'name' => 'dias',
                    'type' => 'number',
                    'label' => 'Días',
                    'required' => false,
                    'min' => 0,
                    'helpText' => 'Cantidad de días de vigencia.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.vigencia_oferta', (string) $id));
    }

    public function vigenciaofertaUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('vigenciaoferta')->where('idvigenciaOferta', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.vigencias-oferta.index')
                ->with('error', 'No se encontro la vigencia de oferta solicitada.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.vigencia_oferta', (string) $id, 'vigencia de oferta', 'modules.configuracion.vigencias-oferta.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'detalle' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'dias' => ['nullable', 'integer', 'min:0'],
        ]);

        DB::table('vigenciaoferta')->where('idvigenciaOferta', $id)->update($validated);
        $this->publishResourceEvent('configuracion.vigencia_oferta', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.vigencia_oferta', (string) $id);

        return redirect()
            ->route('modules.configuracion.vigencias-oferta.index')
            ->with('success', 'Vigencia de oferta actualizada correctamente.');
    }

    public function vigenciaofertaDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.vigencia_oferta', (string) $id, 'vigencia de oferta', 'modules.configuracion.vigencias-oferta.index')) {
            return $redirect;
        }

        try {
            DB::table('vigenciaoferta')->where('idvigenciaOferta', $id)->delete();
            $this->publishResourceEvent('configuracion.vigencia_oferta', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.vigencia_oferta', (string) $id);
            return redirect()
                ->route('modules.configuracion.vigencias-oferta.index')
                ->with('success', 'Vigencia de oferta eliminada correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.vigencias-oferta.index')
                ->with('error', 'No se puede eliminar la vigencia de oferta porque tiene registros relacionados.');
        }
    }

    public function vigenciaofertaExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('vigenciaoferta');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idvigenciaOferta', 'like', $term)
                    ->orWhere('detalle', 'like', $term)
                    ->orWhere('dias', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idvigenciaOferta', 'label' => 'ID'],
            ['key' => 'detalle', 'label' => 'Detalle'],
            ['key' => 'dias', 'label' => 'Días'],
        ];

        $filename = 'vigencia_oferta_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('idvigenciaOferta', array_values($selectedIds))->orderBy('idvigenciaOferta')->get();
    
            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }
    
            return $this->exportPdfResponse($rows, $columns, 'Listado de Vigencias de Oferta', $filename);
        }

        $rows = $baseQuery->orderBy('idvigenciaOferta')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Vigencias de Oferta', $filename);
    }

    public function certificadosUnatIndex(Request $request): View
    {
        $baseQuery = DB::table('certificadosunat');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idcertificadoSUNAT', 'like', $term)
                    ->orWhere('firmaDigital', 'like', $term)
                    ->orWhere('archivoCertificadoPublico', 'like', $term)
                    ->orWhere('archivoCertificadoPrivado', 'like', $term)
                    ->orWhere('proveedor', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('idcertificadoSUNAT')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $items->setCollection($items->getCollection()->map(function ($row){
            if ($row->fechaEmision) {
                $row->fechaEmision = Carbon::parse($row->fechaEmision)->locale('es')->translatedFormat('d M Y'); 
            }
            if ($row->fechaVencimiento) {
                $row->fechaVencimiento = Carbon::parse($row->fechaVencimiento)->locale('es')->translatedFormat('d M Y'); 
            }
            if ($row->fechaCargaSistema) {
                $row->fechaCargaSistema = Carbon::parse($row->fechaCargaSistema)->locale('es')->translatedFormat('d M Y'); 
            }

            return $row;
        }));

        return view('configuracion.certificadosunat.certificadosunat', [
            'title' => 'Configuracion: Certificados SUNAT',
            'singularTitle' => 'Certificado SUNAT',
            'items' => $items,
            'columns' => [
                ['key' => 'idcertificadoSUNAT', 'label' => 'Certificado SUNAT', 'type' => 'text'],
                ['key' => 'proveedor', 'label' => 'Proveedor', 'type' => 'text'],
                ['key' => 'fechaEmision', 'label' => 'Fecha emisión', 'type' => 'text'],
                ['key' => 'fechaVencimiento', 'label' => 'Fecha vencimiento', 'type' => 'text'],
                ['key' => 'fechaCargaSistema', 'label' => 'Fecha carga', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.certificados-sunat.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.certificados-sunat.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de certificados SUNAT', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.certificados-sunat.create'),
            'editRoute' => 'modules.configuracion.certificados-sunat.edit',
            'showRoute' => 'modules.configuracion.certificados-sunat.edit',
            'destroyRoute' => 'modules.configuracion.certificados-sunat.destroy',
            'identifierKey' => 'idcertificadoSUNAT',
            'lockResource' => 'configuracion.certificadosunat',
        ]);
    }

    public function certificadosUnatCreate(): View
    {
        return view('configuracion.certificadosunat.certificadosunat-form', [
            'title' => 'Nuevo Certificado SUNAT',
            'moduleTitle' => 'Configuracion: Certificados SUNAT',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.certificados-sunat.store'),
            'backRoute' => route('modules.configuracion.certificados-sunat.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'idcertificadoSUNAT',
                    'type' => 'text',
                    'label' => 'Certificado SUNAT',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Identificador único del certificado SUNAT.',
                ],
                [
                    'name' => 'proveedor',
                    'type' => 'text',
                    'label' => 'Proveedor',
                    'required' => false,
                    'maxlength' => 100,
                    'helpText' => 'Proveedor del certificado SUNAT.',
                ],
                [
                    'name' => 'fechaEmision',
                    'type' => 'date',
                    'label' => 'Fecha emisión',
                    'required' => false,
                    'value' => now()->format('Y-m-d'),
                    'helpText' => 'Autocompletada con la fecha actual. Puedes editarla.',
                ],
                [
                    'name' => 'fechaVencimiento',
                    'type' => 'date',
                    'label' => 'Fecha vencimiento',
                    'required' => false,
                    'helpText' => 'Selecciona la fecha de vencimiento del certificado.',
                ],
                [
                    'name' => 'fechaCargaSistema',
                    'type' => 'date',
                    'label' => 'Fecha carga sistema',
                    'required' => false,
                    'value' => now()->format('Y-m-d'),
                    'helpText' => 'Autocompletada con la fecha actual. Puedes editarla.',
                ],
                [
                    'name' => 'firmaDigital',
                    'type' => 'file',
                    'label' => 'Firma digital',
                    'required' => false,
                    'accept' => 'image/jpeg,image/png',
                    'fileKind' => 'image',
                    'helpText' => 'Sube una imagen JPG o PNG para la firma digital.',
                ],
                [
                    'name' => 'archivoCertificadoPublico',
                    'type' => 'file',
                    'label' => 'Archivo certificado público',
                    'required' => false,
                    'accept' => 'application/pdf',
                    'fileKind' => 'pdf',
                    'helpText' => 'Sube el certificado público en formato PDF.',
                ],
                [
                    'name' => 'archivoCertificadoPrivado',
                    'type' => 'file',
                    'label' => 'Archivo certificado privado',
                    'required' => false,
                    'accept' => 'application/pdf',
                    'fileKind' => 'pdf',
                    'helpText' => 'Sube el certificado privado en formato PDF.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function certificadosUnatStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'idcertificadoSUNAT' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'firmaDigital' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'archivoCertificadoPublico' => ['nullable', 'file', 'mimes:pdf', 'max:5120'],
            'archivoCertificadoPrivado' => ['nullable', 'file', 'mimes:pdf', 'max:5120'],
            'proveedor' => ['nullable', 'string', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'fechaEmision' => ['nullable', 'date'],
            'fechaVencimiento' => ['nullable', 'date', 'after_or_equal:fechaEmision'],
            'fechaCargaSistema' => ['nullable', 'date'],
        ], [
            'idcertificadoSUNAT.required' => 'El campo Certificado SUNAT es obligatorio.',
            'firmaDigital.image' => 'El archivo de Firma digital debe ser una imagen.',
            'firmaDigital' => 'La Firma digital no debe ser mayor a 2MB.',
            'archivoCertificadoPublico.file' => 'El Archivo certificado público debe ser un archivo válido.',
            'archivoCertificadoPublico' => 'El Archivo certificado público no debe ser mayor a 5MB.',
            'archivoCertificadoPrivado.file' => 'El Archivo certificado privado debe ser un archivo válido.',
            'archivoCertificadoPrivado' => 'El Archivo certificado privado no debe ser mayor a 5MB.',
        ]
        );

        if ($request->hasFile('firmaDigital')) {
            $file = $request->file('firmaDigital');
            $filename = 'firma_digital_' . Str::lower(Str::random(12)) . '.' . $file->extension();
            $path = $file->storePubliclyAs('certificadosunat/firmas', $filename, 'public');
            $validated['firmaDigital'] = $path;
        }

        if ($request->hasFile('archivoCertificadoPublico')) {
            $file = $request->file('archivoCertificadoPublico');
            $filename = 'cert_publico_' . Str::lower(Str::random(12)) . '.' . $file->extension();
            $path = $file->storePubliclyAs('certificadosunat/publicos', $filename, 'public');
            $validated['archivoCertificadoPublico'] = $path;
        }

        if ($request->hasFile('archivoCertificadoPrivado')) {
            $file = $request->file('archivoCertificadoPrivado');
            $filename = 'cert_privado_' . Str::lower(Str::random(12)) . '.' . $file->extension();
            $path = $file->storePubliclyAs('certificadosunat/privados', $filename, 'public');
            $validated['archivoCertificadoPrivado'] = $path;
        }

        $validated['fechaEmision'] = $validated['fechaEmision'] ?? now()->toDateString();
        $validated['fechaCargaSistema'] = $validated['fechaCargaSistema'] ?? now()->toDateString();

        DB::table('certificadosunat')->insert($validated);
        $this->publishResourceEvent('configuracion.certificadosunat', $validated['idcertificadoSUNAT'], 'created');

        return redirect()
            ->route('modules.configuracion.certificados-sunat.index')
            ->with('success', 'Certificado SUNAT creado correctamente.');
    }

    public function certificadosUnatEdit(string $id): View|RedirectResponse
    {
        $record = DB::table('certificadosunat')->where('idcertificadoSUNAT', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.certificados-sunat.index')
                ->with('error', 'No se encontro el certificado SUNAT solicitado.');
        }

        return view('configuracion.certificadosunat.certificadosunat-form', [
            'title' => 'Editar Certificado SUNAT',
            'moduleTitle' => 'Configuracion: Certificados SUNAT',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.certificados-sunat.update', $id),
            'backRoute' => route('modules.configuracion.certificados-sunat.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'idcertificadoSUNAT',
                    'type' => 'text',
                    'label' => 'Certificado SUNAT',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Identificador único del certificado SUNAT.',
                ],
                [
                    'name' => 'proveedor',
                    'type' => 'text',
                    'label' => 'Proveedor',
                    'required' => false,
                    'maxlength' => 100,
                    'helpText' => 'Proveedor del certificado SUNAT.',
                ],
                [
                    'name' => 'fechaEmision',
                    'type' => 'date',
                    'label' => 'Fecha emisión',
                    'required' => false,
                    'helpText' => 'Autocompletada con la fecha actual. Puedes editarla.',
                ],
                [
                    'name' => 'fechaVencimiento',
                    'type' => 'date',
                    'label' => 'Fecha vencimiento',
                    'required' => false,
                    'helpText' => 'Selecciona la fecha de vencimiento del certificado.',
                ],
                [
                    'name' => 'fechaCargaSistema',
                    'type' => 'date',
                    'label' => 'Fecha carga sistema',
                    'required' => false,
                    'helpText' => 'Autocompletada con la fecha actual. Puedes editarla.',
                ],
                [
                    'name' => 'firmaDigital',
                    'type' => 'file',
                    'label' => 'Firma digital',
                    'required' => false,
                    'accept' => 'image/jpeg,image/png',
                    'fileKind' => 'image',
                    'helpText' => 'Sube una imagen JPG o PNG para la firma digital.',
                ],
                [
                    'name' => 'archivoCertificadoPublico',
                    'type' => 'file',
                    'label' => 'Archivo certificado público',
                    'required' => false,
                    'accept' => 'application/pdf',
                    'fileKind' => 'pdf',
                    'helpText' => 'Sube el certificado público en formato PDF.',
                ],
                [
                    'name' => 'archivoCertificadoPrivado',
                    'type' => 'file',
                    'label' => 'Archivo certificado privado',
                    'required' => false,
                    'accept' => 'application/pdf',
                    'fileKind' => 'pdf',
                    'helpText' => 'Sube el certificado privado en formato PDF.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.certificadosunat', (string) $id));
    }

    public function certificadosUnatUpdate(Request $request, string $id): RedirectResponse
    {
        $exists = DB::table('certificadosunat')->where('idcertificadoSUNAT', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.certificados-sunat.index')
                ->with('error', 'No se encontro el certificado SUNAT solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.certificadosunat', (string) $id, 'certificado SUNAT', 'modules.configuracion.certificados-sunat.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'firmaDigital' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'archivoCertificadoPublico' => ['nullable', 'file', 'mimes:pdf', 'max:5120'],
            'archivoCertificadoPrivado' => ['nullable', 'file', 'mimes:pdf', 'max:5120'],
            'proveedor' => ['nullable', 'string', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'fechaEmision' => ['nullable', 'date'],
            'fechaVencimiento' => ['nullable', 'date', 'after_or_equal:fechaEmision'],
            'fechaCargaSistema' => ['nullable', 'date'],
        ]);

        $previous = DB::table('certificadosunat')->where('idcertificadoSUNAT', $id)->first();

        if ($request->hasFile('firmaDigital')) {
            if (!empty($previous->firmaDigital)) {
                Storage::disk('public')->delete($previous->firmaDigital);
            }
            $file = $request->file('firmaDigital');
            $filename = 'firma_digital_' . Str::lower(Str::random(12)) . '.' . $file->extension();
            $path = $file->storePubliclyAs('certificadosunat/firmas', $filename, 'public');
            $validated['firmaDigital'] = $path;
        }

        if ($request->hasFile('archivoCertificadoPublico')) {
            if (!empty($previous->archivoCertificadoPublico)) {
                Storage::disk('public')->delete($previous->archivoCertificadoPublico);
            }
            $file = $request->file('archivoCertificadoPublico');
            $filename = 'cert_publico_' . Str::lower(Str::random(12)) . '.' . $file->extension();
            $path = $file->storePubliclyAs('certificadosunat/publicos', $filename, 'public');
            $validated['archivoCertificadoPublico'] = $path;
        }

        if ($request->hasFile('archivoCertificadoPrivado')) {
            if (!empty($previous->archivoCertificadoPrivado)) {
                Storage::disk('public')->delete($previous->archivoCertificadoPrivado);
            }
            $file = $request->file('archivoCertificadoPrivado');
            $filename = 'cert_privado_' . Str::lower(Str::random(12)) . '.' . $file->extension();
            $path = $file->storePubliclyAs('certificadosunat/privados', $filename, 'public');
            $validated['archivoCertificadoPrivado'] = $path;
        }

        DB::table('certificadosunat')->where('idcertificadoSUNAT', $id)->update($validated);
        $this->publishResourceEvent('configuracion.certificadosunat', $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.certificadosunat', (string) $id);

        return redirect()
            ->route('modules.configuracion.certificados-sunat.index')
            ->with('success', 'Certificado SUNAT actualizado correctamente.');
    }

    public function certificadosUnatDestroy(Request $request, string $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.certificadosunat', (string) $id, 'certificado SUNAT', 'modules.configuracion.certificados-sunat.index')) {
            return $redirect;
        }

        try {
            DB::table('certificadosunat')->where('idcertificadoSUNAT', $id)->delete();
            $this->publishResourceEvent('configuracion.certificadosunat', $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.certificadosunat', (string) $id);
            return redirect()
                ->route('modules.configuracion.certificados-sunat.index')
                ->with('success', 'Certificado SUNAT eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.certificados-sunat.index')
                ->with('error', 'No se puede eliminar el certificado SUNAT porque tiene registros relacionados.');
        }
    }

    public function certificadosUnatExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('certificadosunat');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idcertificadoSUNAT', 'like', $term)
                    ->orWhere('firmaDigital', 'like', $term)
                    ->orWhere('archivoCertificadoPublico', 'like', $term)
                    ->orWhere('archivoCertificadoPrivado', 'like', $term)
                    ->orWhere('proveedor', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idcertificadoSUNAT', 'label' => 'Certificado SUNAT'],
            ['key' => 'firmaDigital', 'label' => 'Firma digital'],
            ['key' => 'archivoCertificadoPublico', 'label' => 'Certificado público'],
            ['key' => 'archivoCertificadoPrivado', 'label' => 'Certificado privado'],
            ['key' => 'proveedor', 'label' => 'Proveedor'],
            ['key' => 'fechaEmision', 'label' => 'Fecha emisión'],
            ['key' => 'fechaVencimiento', 'label' => 'Fecha vencimiento'],
            ['key' => 'fechaCargaSistema', 'label' => 'Fecha carga'],
        ];

        $filename = 'certificadosunat_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('idcertificadoSUNAT', array_values($selectedIds))->orderBy('idcertificadoSUNAT')->get();
        
            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }
        
            return $this->exportPdfResponse($rows, $columns, 'Listado de Certificados SUNAT', $filename);
        }

        $rows = $baseQuery
            ->orderBy('idcertificadoSUNAT')
            ->get()
            ->map(function ($row) {
                $row->fechaEmision = $row->fechaEmision ? date('Y-m-d', strtotime($row->fechaEmision)) : null;
                $row->fechaVencimiento = $row->fechaVencimiento ? date('Y-m-d', strtotime($row->fechaVencimiento)) : null;
                $row->fechaCargaSistema = $row->fechaCargaSistema ? date('Y-m-d', strtotime($row->fechaCargaSistema)) : null;
                return $row;
            });

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Certificados SUNAT', $filename);
    }

    private static function normalizeNumeroTelefonicoEstado(string|null $estado): string
    {
        $normalized = trim((string) $estado);
        if ($normalized === '') {
            return '0';
        }

        $lowerValue = mb_strtolower($normalized);

        if (in_array($lowerValue, ['1', 'activo', 'a'], true)) {
            return '1';
        }

        if (in_array($lowerValue, ['0', 'inactivo', 'i'], true)) {
            return '0';
        }

        return '0';
    }

    private static function normalizeNumeroTelefonicoEstadoLabel(string|null $estado): string
    {
        return self::normalizeNumeroTelefonicoEstado($estado) === '1' ? 'Activo' : 'Inactivo';
    }

    public function ubigeosIndex(Request $request): View
    {
        $baseQuery = DB::table('ubigeo');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idubigeo', 'like', $term)
                    ->orWhere('departamento', 'like', $term)
                    ->orWhere('provincia', 'like', $term)
                    ->orWhere('distrito', 'like', $term)
                    ->orWhere('pais', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('idubigeo')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.ubigeo.ubigeo', [
            'title' => 'Configuracion: Ubigeo',
            'singularTitle' => 'Ubigeo',
            'items' => $items,
            'columns' => [
                ['key' => 'idubigeo', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'departamento', 'label' => 'Departamento', 'type' => 'text'],
                ['key' => 'provincia', 'label' => 'Provincia', 'type' => 'text'],
                ['key' => 'distrito', 'label' => 'Distrito', 'type' => 'text'],
                ['key' => 'pais', 'label' => 'Pais', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.ubigeos.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.ubigeos.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de ubigeos', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.ubigeos.create'),
            'editRoute' => 'modules.configuracion.ubigeos.edit',
            'showRoute' => 'modules.configuracion.ubigeos.edit',
            'lockResource' => 'configuracion.ubigeo',
            'destroyRoute' => 'modules.configuracion.ubigeos.destroy',
            'identifierKey' => 'idubigeo',
        ]);
    }

    public function ubigeosCreate(): View
    {
        return view('configuracion.ubigeo.ubigeo-form', [
            'title' => 'Nuevo Ubigeo',
            'moduleTitle' => 'Configuracion: Ubigeo',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.ubigeos.store'),
            'backRoute' => route('modules.configuracion.ubigeos.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'departamento',
                    'type' => 'text',
                    'label' => 'Departamento',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'provincia',
                    'type' => 'text',
                    'label' => 'Provincia',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'distrito',
                    'type' => 'text',
                    'label' => 'Distrito',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'pais',
                    'type' => 'text',
                    'label' => 'Pais',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function ubigeosStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'departamento' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'provincia' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'distrito' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'pais' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        $newId = DB::table('ubigeo')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.ubigeo', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.ubigeos.index')
            ->with('success', 'Ubigeo creado correctamente.');
    }

    public function ubigeosEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('ubigeo')->where('idubigeo', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.ubigeos.index')
                ->with('error', 'No se encontro el ubigeo solicitado.');
        }

        return view('configuracion.ubigeo.ubigeo-form', [
            'title' => 'Editar Ubigeo',
            'moduleTitle' => 'Configuracion: Ubigeo',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.ubigeos.update', $id),
            'backRoute' => route('modules.configuracion.ubigeos.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'departamento',
                    'type' => 'text',
                    'label' => 'Departamento',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'provincia',
                    'type' => 'text',
                    'label' => 'Provincia',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'distrito',
                    'type' => 'text',
                    'label' => 'Distrito',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'pais',
                    'type' => 'text',
                    'label' => 'Pais',
                    'required' => true,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.ubigeo', (string) $id));
    }

    public function ubigeosUpdate(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.ubigeo', (string) $id, 'ubigeo', 'modules.configuracion.ubigeos.index')) {
            return $redirect;
        }

        $exists = DB::table('ubigeo')->where('idubigeo', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.ubigeos.index')
                ->with('error', 'No se encontro el ubigeo solicitado.');
        }

        $validated = $request->validate([
            'departamento' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'provincia' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'distrito' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'pais' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        DB::table('ubigeo')->where('idubigeo', $id)->update($validated);
        $this->publishResourceEvent('configuracion.ubigeo', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.ubigeo', (string) $id);

        return redirect()
            ->route('modules.configuracion.ubigeos.index')
            ->with('success', 'Ubigeo actualizado correctamente.');
    }

    public function ubigeosDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.ubigeo', (string) $id, 'ubigeo', 'modules.configuracion.ubigeos.index')) {
            return $redirect;
        }

        try {
            DB::table('ubigeo')->where('idubigeo', $id)->delete();
            $this->publishResourceEvent('configuracion.ubigeo', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.ubigeo', (string) $id);
            return redirect()
                ->route('modules.configuracion.ubigeos.index')
                ->with('success', 'Ubigeo eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.ubigeos.index')
                ->with('error', 'No se puede eliminar el ubigeo porque tiene registros relacionados.');
        }
    }

    public function ubigeosExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('ubigeo');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idubigeo', 'like', $term)
                    ->orWhere('departamento', 'like', $term)
                    ->orWhere('provincia', 'like', $term)
                    ->orWhere('distrito', 'like', $term)
                    ->orWhere('pais', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idubigeo', 'label' => 'ID'],
            ['key' => 'departamento', 'label' => 'Departamento'],
            ['key' => 'provincia', 'label' => 'Provincia'],
            ['key' => 'distrito', 'label' => 'Distrito'],
            ['key' => 'pais', 'label' => 'Pais'],
        ];

        $filename = 'ubigeo_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('idubigeo', array_values($selectedIds))->orderBy('idubigeo')->get();
        
            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }
        
            return $this->exportPdfResponse($rows, $columns, 'Listado de Ubigeos', $filename);
        }

        $rows = $baseQuery->orderBy('idubigeo')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Ubigeos', $filename);
    }

    public function cargosIndex(Request $request): View|RedirectResponse
    {
        $baseQuery = DB::table('cargopersonal');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idcargoPersonal', 'like', $term)
                    ->orWhere('descripcion', 'like', $term);
            });
        }

        $items = $baseQuery
            ->orderBy('descripcion')
            ->orderBy('idcargoPersonal')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $currentUser = $request->session()->get('erp_auth.usuario', 'anonimo');
        $items->getCollection()->transform(function ($item) use ($currentUser) {
            $lockInfo = ResourceLock::status(self::cargoLockResource(), self::cargoLockId((string) $item->idcargoPersonal));
            $item->lockBlocked = $lockInfo !== null && (($lockInfo['usuario'] ?? '') !== $currentUser);
            $item->lockOwner = $lockInfo['usuario'] ?? null;
            return $item;
        });

        return view('configuracion.cargopersonal.cargopersonal', [
            'title' => 'Configuracion: Cargo Personal',
            'singularTitle' => 'Cargo Personal',
            'items' => $items,
            'columns' => [
                ['key' => 'idcargoPersonal', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'descripcion', 'label' => 'Descripcion', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.cargos.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.cargos.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de cargos', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => $this->configuracionListFilters(__FUNCTION__),
            'createRoute' => route('modules.configuracion.cargos.create'),
            'editRoute' => 'modules.configuracion.cargos.edit',
            'lockResource' => self::cargoLockResource(),
            'showRoute' => 'modules.configuracion.cargos.edit',
            'destroyRoute' => 'modules.configuracion.cargos.destroy',
            'identifierKey' => 'idcargoPersonal',
        ]);
    }

    public function cargosCreate(Request $request): View|RedirectResponse
    {
        return view('configuracion.cargopersonal.cargopersonal-form', [
            'title' => 'Nuevo Cargo Personal',
            'moduleTitle' => 'Configuracion: Cargo Personal',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.cargos.store'),
            'backRoute' => route('modules.configuracion.cargos.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'descripcion',
                    'type' => 'text',
                    'label' => 'Descripcion',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function cargosStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'descripcion' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        $newId = DB::table('cargopersonal')->insertGetId($validated);
        $this->publishResourceEvent(self::cargoLockResource(), (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.cargos.index')
            ->with('success', 'Cargo creado correctamente.');
    }

    public function cargosEdit(Request $request, int $id): View|RedirectResponse
    {
        $record = DB::table('cargopersonal')->where('idcargoPersonal', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.cargos.index')
                ->with('error', 'No se encontro el cargo solicitado.');
        }

        return view('configuracion.cargopersonal.cargopersonal-form', [
            'title' => 'Editar Cargo Personal',
            'moduleTitle' => 'Configuracion: Cargo Personal',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.cargos.update', $id),
            'backRoute' => route('modules.configuracion.cargos.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'descripcion',
                    'type' => 'text',
                    'label' => 'Descripcion',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData(self::cargoLockResource(), self::cargoLockId($id)));
    }

    public function cargosUpdate(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, self::cargoLockResource(), self::cargoLockId($id), 'cargo', 'modules.configuracion.cargos.index')) {
            return $redirect;
        }

        $exists = DB::table('cargopersonal')->where('idcargoPersonal', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.cargos.index')
                ->with('error', 'No se encontro el cargo solicitado.');
        }

        $validated = $request->validate([
            'descripcion' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        DB::table('cargopersonal')
            ->where('idcargoPersonal', $id)
            ->update($validated);
        $this->publishResourceEvent(self::cargoLockResource(), (string) $id, 'updated');

        $this->releaseLockIfOwned($request, self::cargoLockResource(), self::cargoLockId($id));

        return redirect()
            ->route('modules.configuracion.cargos.index')
            ->with('success', 'Cargo actualizado correctamente.');
    }

    public function cargosDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, self::cargoLockResource(), self::cargoLockId($id), 'cargo', 'modules.configuracion.cargos.index')) {
            return $redirect;
        }

        $hasRelatedPersonal = DB::table('personal')
            ->where('cargoPersonal_idcargoPersonal', $id)
            ->exists();

        if ($hasRelatedPersonal) {
            return redirect()
                ->route('modules.configuracion.cargos.index')
                ->with('error', 'No se puede eliminar este cargo porque está relacionado con uno o más registros de personal.');
        }

        try {
            DB::table('cargopersonal')->where('idcargoPersonal', $id)->delete();
            $this->publishResourceEvent(self::cargoLockResource(), (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, self::cargoLockResource(), self::cargoLockId($id));

            return redirect()
                ->route('modules.configuracion.cargos.index')
                ->with('success', 'Cargo eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.cargos.index')
                ->with('error', 'No se puede eliminar este cargo porque tiene registros relacionados.');
        }
    }

    public function cargosExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('cargopersonal');
        $this->applyConfiguracionListFilters($baseQuery, $request, __FUNCTION__);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idcargoPersonal', 'like', $term)
                    ->orWhere('descripcion', 'like', $term);
            });
        }

        $columns = [
            ['key' => 'idcargoPersonal', 'label' => 'ID'],
            ['key' => 'descripcion', 'label' => 'Descripcion'],
        ];

        $filename = 'cargo_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $baseQuery->whereIn('idcargoPersonal', array_values($selectedIds))->orderBy('descripcion')->orderBy('idcargoPersonal')->get();
        
            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }
        
            return $this->exportPdfResponse($rows, $columns, 'Listado de Cargos', $filename);
        }

        $rows = $baseQuery->orderBy('descripcion')->orderBy('idcargoPersonal')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Cargos', $filename);
    }

    public function auditoriaIndex(Request $request): View
    {
        $baseQuery = DB::table('auditoria');

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('usuario', 'like', $term)
                    ->orWhere('modulo', 'like', $term)
                    ->orWhere('accion', 'like', $term)
                    ->orWhere('ruta', 'like', $term)
                    ->orWhere('nombre_ruta', 'like', $term)
                    ->orWhere('mensaje', 'like', $term);
            });
        }

        // Filtro por fecha: desde y hasta (solo fecha, sin hora)
        if ($dateFrom = $request->input('date_from')) {
            $baseQuery->where('created_at', '>=', $dateFrom . ' 00:00:00');
        }
        if ($dateTo = $request->input('date_to')) {
            $baseQuery->where('created_at', '<=', $dateTo . ' 23:59:59');
        }

        $items = $baseQuery
            ->orderByDesc('created_at')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        // Construir query string actual para exportRoutes
        $query = $request->except('page');
        $queryString = count($query) ? ('?' . http_build_query($query)) : '';

        return view('configuracion.auditoria', [
            'title' => 'Configuración: Auditoría',
            'singularTitle' => 'Evento de Auditoría',
            'items' => $items,
            'columns' => [
                ['key' => 'usuario', 'label' => 'Usuario', 'type' => 'text'],
                ['key' => 'modulo', 'label' => 'Módulo', 'type' => 'text'],
                ['key' => 'accion', 'label' => 'Acción', 'type' => 'truncated_modal', 'maxLength' => 25],
                ['key' => 'ruta', 'label' => 'Ruta', 'type' => 'text'],
                ['key' => 'created_at', 'label' => 'Fecha', 'type' => 'text'],
            ],
            'showActionsColumn' => false,
            'filters' => [
                ['name' => 'date_from', 'label' => 'Desde', 'type' => 'date'],
                ['name' => 'date_to', 'label' => 'Hasta', 'type' => 'date'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.auditoria.export', ['format' => 'pdf']) . $queryString,
                'xlsx' => route('modules.configuracion.auditoria.export', ['format' => 'xlsx']) . $queryString,
            ],
            'exportMode' => 'buttons',
            'stats' => [],
            'createRoute' => null,
            'editRoute' => null,
            'showRoute' => null,
            'destroyRoute' => null,
            'identifierKey' => 'id',
        ]);
    }

    public function auditoriaExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            return redirect()
                ->route('modules.configuracion.auditoria.index')
                ->with('error', 'Formato de exportación inválido.');
        }

        $baseQuery = DB::table('auditoria');

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('usuario', 'like', $term)
                    ->orWhere('modulo', 'like', $term)
                    ->orWhere('accion', 'like', $term)
                    ->orWhere('ruta', 'like', $term)
                    ->orWhere('nombre_ruta', 'like', $term)
                    ->orWhere('mensaje', 'like', $term);
            });
        }

        // Filtro por fecha: desde y hasta (mismo que en auditoriaIndex)
        if ($dateFrom = $request->input('date_from')) {
            $baseQuery->where('created_at', '>=', $dateFrom . ' 00:00:00');
        }
        if ($dateTo = $request->input('date_to')) {
            $baseQuery->where('created_at', '<=', $dateTo . ' 23:59:59');
        }

        $rows = $baseQuery
            ->orderByDesc('created_at')
            ->get();

        $columns = [
            ['key' => 'usuario', 'label' => 'Usuario'],
            ['key' => 'modulo', 'label' => 'Módulo'],
            ['key' => 'accion', 'label' => 'Acción', 'width' => 45, 'wrap' => true],
            ['key' => 'ruta', 'label' => 'Ruta'],
            ['key' => 'ip_address', 'label' => 'IP'],
            ['key' => 'created_at', 'label' => 'Fecha'],
        ];

        $filename = 'auditoria_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Auditoría', $filename);
    }

    private static function formatDateTimeForList(string $value): string
    {
        try {
            return Carbon::parse($value)->locale('es')->translatedFormat('d M Y, H:i');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function configuracionListFilters(string $method): array
    {
        return match ($method) {
            'estadosIndex', 'estadosExport' => [
                ['name' => 'idestadoCliente', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'detalle', 'label' => 'Detalle', 'type' => 'text'],
            ],
            'tiposGastoIndex', 'tiposGastoExport' => [
                ['name' => 'idtipoGasto', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'nombre', 'label' => 'Nombre', 'type' => 'text'],
            ],
            'tiposContactoIndex', 'tiposContactoExport' => [
                ['name' => 'idtipoContacto', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'detalle', 'label' => 'Detalle', 'type' => 'text'],
            ],
            'tiposCobroIndex', 'tiposCobroExport' => [
                ['name' => 'idtipoCobros', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'nombre', 'label' => 'Nombre', 'type' => 'text'],
                ['name' => 'recurrencia', 'label' => 'Recurrencia', 'type' => 'text'],
                ['name' => 'tiempo', 'label' => 'Tiempo', 'type' => 'text'],
            ],
            'unidadMedidasIndex', 'unidadMedidasExport' => [
                ['name' => 'idunidadMedida', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'detalle', 'label' => 'Detalle', 'type' => 'text'],
                ['name' => 'nomenclatura', 'label' => 'Nomenclatura', 'type' => 'text'],
            ],
            'monedasIndex', 'monedasExport' => [
                ['name' => 'idmoneda', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'detalle', 'label' => 'Detalle', 'type' => 'text'],
                ['name' => 'simbolo', 'label' => 'Símbolo', 'type' => 'text'],
            ],
            'marcasIndex', 'marcasExport' => [
                ['name' => 'idmarca', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'nombreMarca', 'label' => 'Nombre', 'type' => 'text'],
                ['name' => 'procedencia', 'label' => 'Procedencia', 'type' => 'text'],
            ],
            'empresapropietariaIndex', 'empresapropietariaExport' => [
                ['name' => 'RUC', 'label' => 'RUC', 'type' => 'text'],
                ['name' => 'razonSocial', 'label' => 'Razón social', 'type' => 'text'],
                ['name' => 'rubro', 'label' => 'Rubro', 'type' => 'text'],
                ['name' => 'direccionFiscal', 'label' => 'Dirección fiscal', 'type' => 'text'],
                ['name' => 'ubigeo_label', 'label' => 'Ubigeo', 'type' => 'text'],
            ],
            'modeloIndex', 'modeloExport' => [
                ['name' => 'idmodelo', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'nombreModelo', 'label' => 'Nombre', 'type' => 'text'],
                ['name' => 'marca_label', 'label' => 'Marca', 'type' => 'text'],
            ],
            'tributosIndex', 'tributosExport' => [
                ['name' => 'idtributo', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'nombreTributo', 'label' => 'Nombre', 'type' => 'text'],
                ['name' => 'tipo', 'label' => 'Tipo', 'type' => 'text'],
                ['name' => 'valor', 'label' => 'Valor', 'type' => 'text'],
            ],
            'tecnologiasIndex', 'tecnologiasExport' => [
                ['name' => 'idtecnologia', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'nombreTecnologia', 'label' => 'Nombre', 'type' => 'text'],
            ],
            'tiposPlataformaIndex', 'tiposPlataformaExport' => [
                ['name' => 'idtipoPlataforma', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'descripcion', 'label' => 'Descripcion', 'type' => 'text'],
            ],
            'plataformaIndex', 'plataformaExport' => [
                ['name' => 'idplataforma', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'nombrePlataforma', 'label' => 'Nombre', 'type' => 'text'],
                ['name' => 'tipoPlataforma', 'label' => 'Tipo de plataforma', 'type' => 'text'],
            ],
            'tipoElementoIndex', 'tipoElementoExport' => [
                ['name' => 'idtipoElemento', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'nombre', 'label' => 'Nombre', 'type' => 'text'],
                ['name' => 'detalle', 'label' => 'Detalle', 'type' => 'text'],
                ['name' => 'plataforma', 'label' => 'Plataforma', 'type' => 'text'],
            ],
            'tiposDocumentoIndex', 'tiposDocumentoExport' => [
                ['name' => 'idtipoDocumento', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'detalle', 'label' => 'Detalle', 'type' => 'text'],
                ['name' => 'serie', 'label' => 'Serie', 'type' => 'text'],
                ['name' => 'correlativo', 'label' => 'Correlativo', 'type' => 'text'],
                ['name' => 'area', 'label' => 'Area', 'type' => 'text'],
            ],
            'formasPagoIndex', 'formasPagoExport' => [
                ['name' => 'idformaPago', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'detalle', 'label' => 'Detalle', 'type' => 'text'],
                ['name' => 'tiempo', 'label' => 'Tiempo', 'type' => 'text'],
            ],
            'entidadesBancariasIndex', 'entidadesBancariasExport' => [
                ['name' => 'identidadBancaria', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'razonSocial', 'label' => 'Razón social', 'type' => 'text'],
                ['name' => 'ruc', 'label' => 'RUC', 'type' => 'text'],
                ['name' => 'descripcion', 'label' => 'Descripcion', 'type' => 'text'],
            ],
            'operadoresIndex', 'operadoresExport' => [
                ['name' => 'idoperador', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'nombre', 'label' => 'Nombre', 'type' => 'text'],
            ],
            'tiposVehiculoIndex', 'tiposVehiculoExport' => [
                ['name' => 'idtipoVehiculo', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'nombre', 'label' => 'Nombre', 'type' => 'text'],
            ],
            'tiposOperacionIndex', 'tiposOperacionExport' => [
                ['name' => 'idtipoOperacion', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'nomenclatura', 'label' => 'Nomenclatura', 'type' => 'text'],
                ['name' => 'detalle', 'label' => 'Detalle', 'type' => 'text'],
            ],
            'listaprecioIndex', 'listaprecioExport' => [
                ['name' => 'idListaPrecio', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'nombreLista', 'label' => 'Nombre lista', 'type' => 'text'],
            ],
            'detalleListaPrecioIndex', 'detalleListaPrecioExport' => [
                ['name' => 'iddetalleListaPrecio', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'almacen_detalle', 'label' => 'Almacén', 'type' => 'text'],
                ['name' => 'listaprecio_nombre', 'label' => 'Lista de precio', 'type' => 'text'],
                ['name' => 'precio', 'label' => 'Precio', 'type' => 'text'],
            ],
            'tipopedidoIndex', 'tipopedidoExport' => [
                ['name' => 'idtipoPedido', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'nomenclatura', 'label' => 'Nomenclatura', 'type' => 'text'],
                ['name' => 'detalle', 'label' => 'Detalle', 'type' => 'text'],
            ],
            'proveedorIndex', 'proveedorExport' => [
                ['name' => 'idproveedor', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'razonSocial', 'label' => 'Razón social', 'type' => 'text'],
                ['name' => 'tipoProveedor', 'label' => 'Tipo proveedor', 'type' => 'text'],
            ],
            'vigenciaofertaIndex', 'vigenciaofertaExport' => [
                ['name' => 'idvigenciaOferta', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'detalle', 'label' => 'Detalle', 'type' => 'text'],
                ['name' => 'dias', 'label' => 'Días', 'type' => 'text'],
            ],
            'certificadosUnatIndex', 'certificadosUnatExport' => [
                ['name' => 'idcertificadoSUNAT', 'label' => 'Certificado SUNAT', 'type' => 'text'],
                ['name' => 'proveedor', 'label' => 'Proveedor', 'type' => 'text'],
                ['name' => 'fechaEmision_from', 'label' => 'Fecha emisión', 'type' => 'date'],
                ['name' => 'fechaVencimiento_to', 'label' => 'Fecha vencimiento', 'type' => 'date'],
                ['name' => 'fechaCargaSistema', 'label' => 'Fecha carga', 'type' => 'date'],
            ],
            'ubigeosIndex', 'ubigeosExport' => [
                ['name' => 'idubigeo', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'departamento', 'label' => 'Departamento', 'type' => 'text'],
                ['name' => 'provincia', 'label' => 'Provincia', 'type' => 'text'],
                ['name' => 'distrito', 'label' => 'Distrito', 'type' => 'text'],
                ['name' => 'pais', 'label' => 'Pais', 'type' => 'text'],
            ],
            'cargosIndex', 'cargosExport' => [
                ['name' => 'idcargoPersonal', 'label' => 'ID', 'type' => 'text'],
                ['name' => 'descripcion', 'label' => 'Descripcion', 'type' => 'text'],
            ],
            default => [],
        };
    }

    private function applyConfiguracionListFilters($query, Request $request, string $method): void
    {
        foreach ($this->configuracionListFilters($method) as $filter) {
            $name = (string) ($filter['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $type = isset($filter['type']) ? strtolower((string) $filter['type']) : 'text';
            $value = trim((string) $request->input($name, ''));
            if ($value === '') {
                continue;
            }

            if ($type === 'date') {
                // Expect names like columna_from and columna_to
                $base = preg_replace('/_(from|to)$/', '', $name);
                try {
                    if (str_ends_with($name, '_from')) {
                        $dt = Carbon::parse($value)->startOfDay()->format('Y-m-d H:i:s');
                        $query->havingRaw('`' . $base . '` >= ?', [$dt]);
                        continue;
                    }

                    if (str_ends_with($name, '_to')) {
                        $dt = Carbon::parse($value)->endOfDay()->format('Y-m-d H:i:s');
                        $query->havingRaw('`' . $base . '` <= ?', [$dt]);
                        continue;
                    }
                } catch (\Throwable $e) {
                    // invalid date input -> skip this filter
                    continue;
                }
            }

            // default: text-like matching
            $query->havingRaw('`' . $name . '` like ?', ['%' . $value . '%']);
        }
    }

    private static function cargoLockResource(): string
    {
        return self::CARGO_LOCK_RESOURCE;
    }

    private static function cargoLockId(int $id): string
    {
        return (string) $id;
    }

    public function getAlmacen($id)
    {
        $row = DB::table('almacen')->where('idalmacen', $id)->select('idalmacen as id', 'precio')->first();
        if (!$row) {
            return response()->json(null, 404);
        }
        return response()->json($row);
    }

    public function getAlmacenPrecios($id)
    {
        $precios = DB::table('detallelistaprecio as d')
            ->leftJoin('listaprecio as lp', 'lp.idListaPrecio', '=', 'd.ListaPrecio_idListaPrecio')
            ->where('d.almacen_idalmacen', $id)
            ->select('d.precio', DB::raw('COALESCE(lp.nombreLista, "Sin lista") as lista'))
            ->get();

        return response()->json($precios);
    }
}
