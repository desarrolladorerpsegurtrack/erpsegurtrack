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
            'filters' => [],
            'createRoute' => route('modules.configuracion.estados.create'),
            'editRoute' => 'modules.configuracion.estados.edit',
            'showRoute' => 'modules.configuracion.estados.edit',
            'destroyRoute' => 'modules.configuracion.estados.destroy',
            'bulkDestroyRoute' => route('modules.configuracion.estados.bulk-destroy'),
            'identifierKey' => 'idestadoCliente',
            'lockResource' => 'configuracion.estados',
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
            'detalle' => ['required', 'string', 'min:2', 'max:20', 'regex:' . self::SAFE_TEXT_REGEX, Rule::unique('estadocliente', 'detalle')],
        ], [
            'detalle.unique' => 'Ya existe un estado de cliente con ese detalle.',
        ]);

        $newId = DB::table('estadocliente')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.estados', (string) $newId, 'created');

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
                ->with('error', 'No se encontro el estado solicitado.');
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
        ] + $this->prepareLockViewData('configuracion.estados', (string) $id));
    }

    public function estadosUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('estadocliente')->where('idestadoCliente', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.estados.index')
                ->with('error', 'No se encontro el estado solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.estados', (string) $id, 'estado de cliente', 'modules.configuracion.estados.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'detalle' => ['required', 'string', 'min:2', 'max:20', 'regex:' . self::SAFE_TEXT_REGEX, Rule::unique('estadocliente', 'detalle')->ignore($id, 'idestadoCliente')],
        ], [
            'detalle.unique' => 'Ya existe un estado de cliente con ese detalle.',
        ]);

        DB::table('estadocliente')->where('idestadoCliente', $id)->update($validated);
        $this->publishResourceEvent('configuracion.estados', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.estados', (string) $id);

        return redirect()
            ->route('modules.configuracion.estados.index')
            ->with('success', 'Estado de cliente actualizado correctamente.');
    }

    public function estadosDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.estados', (string) $id, 'estado de cliente', 'modules.configuracion.estados.index')) {
            return $redirect;
        }

        try {
            DB::table('estadocliente')->where('idestadoCliente', $id)->delete();
            $this->publishResourceEvent('configuracion.estados', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.estados', (string) $id);
            return redirect()
                ->route('modules.configuracion.estados.index')
                ->with('success', 'Estado de cliente eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.estados.index')
                ->with('error', 'No se puede eliminar el estado porque tiene clientes relacionados.');
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

        foreach ($selectedIds as $id) {
            if ($redirect = $this->assertLockAvailable($request, 'configuracion.estados', (string) $id, 'estado de cliente', 'modules.configuracion.estados.index')) {
                return $redirect;
            }
        }

        try {
            DB::transaction(function () use ($selectedIds, $request) {
                DB::table('estadocliente')->whereIn('idestadoCliente', $selectedIds)->delete();

                foreach ($selectedIds as $id) {
                    $this->publishResourceEvent('configuracion.estados', (string) $id, 'deleted');
                    $this->releaseLockIfOwned($request, 'configuracion.estados', (string) $id);
                }
            });

            $count = count($selectedIds);
            return redirect()
                ->route('modules.configuracion.estados.index')
                ->with('success', "Se eliminaron {$count} registro(s) correctamente.");
        } catch (QueryException $e) {
            return redirect()
                ->route('modules.configuracion.estados.index')
                ->with('error', 'No se puede eliminar los registros seleccionados porque tienen clientes relacionados.');
        }
    }

    public function estadosExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $baseQuery = DB::table('estadocliente');

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idestadoCliente', 'like', $term)
                    ->orWhere('detalle', 'like', $term);
            });
        }

        $rows = $baseQuery
            ->orderBy('idestadoCliente')
            ->get();

        $columns = [
            ['key' => 'idestadoCliente', 'label' => 'ID'],
            ['key' => 'detalle', 'label' => 'Detalle'],
        ];

        $filename = 'estado_cliente_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Estados de Cliente', $filename);
    }

    public function tiposContactoIndex(Request $request): View
    {
        $baseQuery = DB::table('tipocontacto');

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
                ['label' => 'Total de tipos', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => [],
            'createRoute' => route('modules.configuracion.tipos-contacto.create'),
            'editRoute' => 'modules.configuracion.tipos-contacto.edit',
            'showRoute' => 'modules.configuracion.tipos-contacto.edit',
            'destroyRoute' => 'modules.configuracion.tipos-contacto.destroy',
            'lockResource' => 'configuracion.tipo-contacto',
            'identifierKey' => 'idtipoContacto',
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
            'detalle' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX, Rule::unique('tipocontacto', 'detalle')],
        ], [
            'detalle.unique' => 'Ya existe un tipo de contacto con ese detalle.',
        ]);

        $newId = DB::table('tipocontacto')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.tipo-contacto', (string) $newId, 'created');

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
        ] + $this->prepareLockViewData('configuracion.tipo-contacto', (string) $id));
    }

    public function tiposContactoUpdate(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tipo-contacto', (string) $id, 'tipo de contacto', 'modules.configuracion.tipos-contacto.index')) {
            return $redirect;
        }

        $exists = DB::table('tipocontacto')->where('idtipoContacto', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.tipos-contacto.index')
                ->with('error', 'No se encontro el tipo de contacto solicitado.');
        }

        $validated = $request->validate([
            'detalle' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX, Rule::unique('tipocontacto', 'detalle')->ignore($id, 'idtipoContacto')],
        ], [
            'detalle.unique' => 'Ya existe un tipo de contacto con ese detalle.',
        ]);

        DB::table('tipocontacto')->where('idtipoContacto', $id)->update($validated);
        $this->publishResourceEvent('configuracion.tipo-contacto', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.tipo-contacto', (string) $id);

        return redirect()
            ->route('modules.configuracion.tipos-contacto.index')
            ->with('success', 'Tipo de contacto actualizado correctamente.');
    }

    public function tiposContactoDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tipo-contacto', (string) $id, 'tipo de contacto', 'modules.configuracion.tipos-contacto.index')) {
            return $redirect;
        }

        try {
            DB::table('tipocontacto')->where('idtipoContacto', $id)->delete();
            $this->publishResourceEvent('configuracion.tipo-contacto', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.tipo-contacto', (string) $id);

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

        $baseQuery = DB::table('tipocontacto');

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtipoContacto', 'like', $term)
                    ->orWhere('detalle', 'like', $term);
            });
        }

        $rows = $baseQuery
            ->orderBy('idtipoContacto')
            ->get();

        $columns = [
            ['key' => 'idtipoContacto', 'label' => 'ID'],
            ['key' => 'detalle', 'label' => 'Detalle'],
        ];

        $filename = 'tipo_contacto_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Contacto', $filename);
    }

    public function monedasIndex(Request $request): View
    {
        $baseQuery = DB::table('moneda');

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
            'filters' => [],
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
                    'helpText' => 'Símbolo de la moneda.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function monedasStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'detalle' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX, Rule::unique('moneda', 'detalle')],
            'simbolo' => ['required', 'string', 'min:1', 'max:3', 'regex:' . self::SAFE_TEXT_REGEX, Rule::unique('moneda', 'simbolo')],
        ], [
            'detalle.unique' => 'Ya existe una moneda con ese nombre.',
            'simbolo.unique' => 'Ya existe una moneda con ese símbolo.',
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
                    'helpText' => 'Símbolo de la moneda.',
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
            'detalle' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX, Rule::unique('moneda', 'detalle')->ignore($id, 'idmoneda')],
            'simbolo' => ['required', 'string', 'min:1', 'max:3', 'regex:' . self::SAFE_TEXT_REGEX, Rule::unique('moneda', 'simbolo')->ignore($id, 'idmoneda')],
        ], [
            'detalle.unique' => 'Ya existe una moneda con ese nombre.',
            'simbolo.unique' => 'Ya existe una moneda con ese símbolo.',
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

        $baseQuery = DB::table('moneda');

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

        $rows = $baseQuery
            ->orderBy('idmoneda')
            ->get();

        $columns = [
            ['key' => 'idmoneda', 'label' => 'ID'],
            ['key' => 'detalle', 'label' => 'Detalle'],
            ['key' => 'simbolo', 'label' => 'Símbolo'],
        ];

        $filename = 'moneda_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Monedas', $filename);
    }

    public function tributosIndex(Request $request): View
    {
        $baseQuery = DB::table('tributo');

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtributo', 'like', $term)
                    ->orWhere('nombreTributo', 'like', $term)
                    ->orWhere('tipo', 'like', $term);
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
            'filters' => [],
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
                    'required' => true,
                    'maxlength' => 45,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'valor',
                    'type' => 'number',
                    'label' => 'Valor',
                    'required' => true,
                    'min' => 0,
                    'helpText' => 'Valor numérico del tributo.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function tributosStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nombreTributo' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX, Rule::unique('tributo', 'nombreTributo')],
            'tipo' => ['required', 'string', 'min:2', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
            'valor' => ['required', 'integer', 'min:0'],
        ], [
            'nombreTributo.unique' => 'Ya existe un tributo con ese nombre.',
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
                    'required' => true,
                    'maxlength' => 45,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'valor',
                    'type' => 'number',
                    'label' => 'Valor',
                    'required' => true,
                    'min' => 0,
                    'helpText' => 'Valor numérico del tributo.',
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
            'nombreTributo' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX, Rule::unique('tributo', 'nombreTributo')->ignore($id, 'idtributo')],
            'tipo' => ['required', 'string', 'min:2', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
            'valor' => ['required', 'integer', 'min:0'],
        ], [
            'nombreTributo.unique' => 'Ya existe un tributo con ese nombre.',
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

        $baseQuery = DB::table('tributo');

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtributo', 'like', $term)
                    ->orWhere('nombreTributo', 'like', $term)
                    ->orWhere('tipo', 'like', $term);
            });
        }

        $rows = $baseQuery
            ->orderBy('idtributo')
            ->get();

        $columns = [
            ['key' => 'idtributo', 'label' => 'ID'],
            ['key' => 'nombreTributo', 'label' => 'Nombre'],
            ['key' => 'tipo', 'label' => 'Tipo'],
            ['key' => 'valor', 'label' => 'Valor'],
        ];

        $filename = 'tributo_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Tributos', $filename);
    }

    public function unidadMedidasIndex(Request $request): View
    {
        $baseQuery = DB::table('unidadmedida');

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
            'filters' => [],
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
                    'helpText' => 'Ej: KG, L, M.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function unidadMedidasStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'detalle' => ['required', 'string', 'min:2', 'max:30', 'regex:' . self::SAFE_TEXT_REGEX, Rule::unique('unidadmedida', 'detalle')],
            'nomenclatura' => ['required', 'string', 'min:1', 'max:3', 'regex:' . self::SAFE_TEXT_REGEX, Rule::unique('unidadmedida', 'nomenclatura')],
        ], [
            'detalle.unique' => 'Ya existe una unidad de medida con ese detalle.',
            'nomenclatura.unique' => 'La nomenclatura ya está en uso.',
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
                    'helpText' => 'Ej: KG, L, M.',
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
            'detalle' => ['required', 'string', 'min:2', 'max:30', 'regex:' . self::SAFE_TEXT_REGEX, Rule::unique('unidadmedida', 'detalle')->ignore($id, 'idunidadMedida')],
            'nomenclatura' => ['required', 'string', 'min:1', 'max:3', 'regex:' . self::SAFE_TEXT_REGEX, Rule::unique('unidadmedida', 'nomenclatura')->ignore($id, 'idunidadMedida')],
        ], [
            'detalle.unique' => 'Ya existe una unidad de medida con ese detalle.',
            'nomenclatura.unique' => 'La nomenclatura ya está en uso.',
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

        $baseQuery = DB::table('unidadmedida');

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

        $rows = $baseQuery
            ->orderBy('idunidadMedida')
            ->get();

        $columns = [
            ['key' => 'idunidadMedida', 'label' => 'ID'],
            ['key' => 'detalle', 'label' => 'Detalle'],
            ['key' => 'nomenclatura', 'label' => 'Nomenclatura'],
        ];

        $filename = 'unidad_medida_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Unidades de Medida', $filename);
    }

    public function marcasIndex(Request $request): View
    {
        $baseQuery = DB::table('marca');

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
            'filters' => [],
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
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'procedencia',
                    'type' => 'text',
                    'label' => 'Procedencia',
                    'required' => false,
                    'maxlength' => 45,
                    'minlength' => 2,
                    'helpText' => 'Origen o procedencia de la marca.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function marcasStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nombreMarca' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX, Rule::unique('marca', 'nombreMarca')],
            'procedencia' => ['nullable', 'string', 'min:2', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
        ], [
            'nombreMarca.unique' => 'Ya existe una marca con ese nombre.',
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
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'procedencia',
                    'type' => 'text',
                    'label' => 'Procedencia',
                    'required' => false,
                    'maxlength' => 45,
                    'minlength' => 2,
                    'helpText' => 'Origen o procedencia de la marca.',
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
            'nombreMarca' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX, Rule::unique('marca', 'nombreMarca')->ignore($id, 'idmarca')],
            'procedencia' => ['nullable', 'string', 'min:2', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
        ], [
            'nombreMarca.unique' => 'Ya existe una marca con ese nombre.',
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

        $baseQuery = DB::table('marca');

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

        $rows = $baseQuery
            ->orderBy('idmarca')
            ->get();

        $columns = [
            ['key' => 'idmarca', 'label' => 'ID'],
            ['key' => 'nombreMarca', 'label' => 'Nombre'],
            ['key' => 'procedencia', 'label' => 'Procedencia'],
        ];

        $filename = 'marca_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Marcas', $filename);
    }

    public function tecnologiasIndex(Request $request): View
    {
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

        $items = $baseQuery
            ->orderBy('idtecnologia')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.tecnologia.tecnologia', [
            'title' => 'Configuracion: Tecnologia',
            'singularTitle' => 'Tecnologia',
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
                ['label' => 'Total de tecnologias', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => [],
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
            'title' => 'Nueva Tecnologia',
            'moduleTitle' => 'Configuracion: Tecnologia',
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
                    'helpText' => 'Código o nombre corto de la tecnología.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function tecnologiasStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nombreTecnologia' => ['required', 'string', 'min:1', 'max:2', 'regex:' . self::SAFE_TEXT_REGEX, Rule::unique('tecnologia', 'nombreTecnologia')],
        ], [
            'nombreTecnologia.unique' => 'Ya existe una tecnología con ese nombre.',
        ]);

        $newId = DB::table('tecnologia')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.tecnologia', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.tecnologias.index')
            ->with('success', 'Tecnologia creada correctamente.');
    }

    public function tecnologiasEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('tecnologia')->where('idtecnologia', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.tecnologias.index')
                ->with('error', 'No se encontro la tecnologia solicitada.');
        }

        return view('configuracion.tecnologia.tecnologia-form', [
            'title' => 'Editar Tecnologia',
            'moduleTitle' => 'Configuracion: Tecnologia',
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
                    'helpText' => 'Código o nombre corto de la tecnología.',
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
                ->with('error', 'No se encontro la tecnologia solicitada.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tecnologia', (string) $id, 'tecnologia', 'modules.configuracion.tecnologias.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'nombreTecnologia' => ['required', 'string', 'min:1', 'max:2', 'regex:' . self::SAFE_TEXT_REGEX, Rule::unique('tecnologia', 'nombreTecnologia')->ignore($id, 'idtecnologia')],
        ], [
            'nombreTecnologia.unique' => 'Ya existe una tecnología con ese nombre.',
        ]);

        DB::table('tecnologia')->where('idtecnologia', $id)->update($validated);
        $this->publishResourceEvent('configuracion.tecnologia', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.tecnologia', (string) $id);

        return redirect()
            ->route('modules.configuracion.tecnologias.index')
            ->with('success', 'Tecnologia actualizada correctamente.');
    }

    public function tecnologiasDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.tecnologia', (string) $id, 'tecnologia', 'modules.configuracion.tecnologias.index')) {
            return $redirect;
        }

        try {
            DB::table('tecnologia')->where('idtecnologia', $id)->delete();
            $this->publishResourceEvent('configuracion.tecnologia', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.tecnologia', (string) $id);
            return redirect()
                ->route('modules.configuracion.tecnologias.index')
                ->with('success', 'Tecnologia eliminada correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.tecnologias.index')
                ->with('error', 'No se puede eliminar la tecnologia porque tiene registros relacionados.');
        }
    }

    public function tecnologiasExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

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

        $rows = $baseQuery
            ->orderBy('idtecnologia')
            ->get();

        $columns = [
            ['key' => 'idtecnologia', 'label' => 'ID'],
            ['key' => 'nombreTecnologia', 'label' => 'Nombre'],
        ];

        $filename = 'tecnologia_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Tecnologias', $filename);
    }

    public function tiposGastoIndex(Request $request): View
    {
        $baseQuery = DB::table('tipogasto');

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
            'filters' => [],
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

        $baseQuery = DB::table('tipogasto');

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtipoGasto', 'like', $term)
                    ->orWhere('nombre', 'like', $term);
            });
        }

        $rows = $baseQuery
            ->orderBy('idtipoGasto')
            ->get();

        $columns = [
            ['key' => 'idtipoGasto', 'label' => 'ID'],
            ['key' => 'nombre', 'label' => 'Nombre'],
        ];

        $filename = 'tipo_gasto_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Gasto', $filename);
    }

    public function tiposCobroIndex(Request $request): View
    {
        $baseQuery = DB::table('tipocobro');

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
            'filters' => [],
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

        $baseQuery = DB::table('tipocobro');

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

        $rows = $baseQuery
            ->orderBy('idtipoCobros')
            ->get();

        $columns = [
            ['key' => 'idtipoCobros', 'label' => 'ID'],
            ['key' => 'nombre', 'label' => 'Nombre'],
            ['key' => 'recurrencia', 'label' => 'Recurrencia'],
            ['key' => 'tiempo', 'label' => 'Tiempo'],
        ];

        $filename = 'tipo_cobro_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Cobro', $filename);
    }

    public function tiposPlataformaIndex(Request $request): View
    {
        $baseQuery = DB::table('tipoplataforma');

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
            'filters' => [],
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

        $baseQuery = DB::table('tipoplataforma');

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtipoPlataforma', 'like', $term)
                    ->orWhere('descripcion', 'like', $term);
            });
        }

        $rows = $baseQuery
            ->orderBy('idtipoPlataforma')
            ->get();

        $columns = [
            ['key' => 'idtipoPlataforma', 'label' => 'ID'],
            ['key' => 'descripcion', 'label' => 'Descripcion'],
        ];

        $filename = 'tipo_plataforma_export_' . now()->format('Ymd_His') . '.' . $format;

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
            'filters' => [],
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

        $baseQuery = DB::table('plataforma')
            ->leftJoin('tipoplataforma', 'plataforma.tipoPlataforma_idtipoPlataforma', '=', 'tipoplataforma.idtipoPlataforma')
            ->select('plataforma.*', 'tipoplataforma.descripcion as tipoPlataforma');

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

        $rows = $baseQuery
            ->orderBy('plataforma.idplataforma')
            ->get();

        $columns = [
            ['key' => 'idplataforma', 'label' => 'ID'],
            ['key' => 'nombrePlataforma', 'label' => 'Nombre'],
            ['key' => 'tipoPlataforma', 'label' => 'Tipo de plataforma'],
        ];

        $filename = 'plataforma_export_' . now()->format('Ymd_His') . '.' . $format;

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
            'filters' => [],
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
                    'name' => 'renovacion',
                    'type' => 'number',
                    'label' => 'Renovación',
                    'required' => false,
                    'min' => 0,
                    'helpText' => 'Período de renovación en días.',
                ],
                [
                    'name' => 'plataforma_idplataforma',
                    'type' => 'select',
                    'label' => 'Plataforma',
                    'required' => true,
                    'optionsData' => $plataformas,
                    'optionKey' => 'idplataforma',
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
                    'name' => 'renovacion',
                    'type' => 'number',
                    'label' => 'Renovación',
                    'required' => false,
                    'min' => 0,
                    'helpText' => 'Período de renovación en días.',
                ],
                [
                    'name' => 'plataforma_idplataforma',
                    'type' => 'select',
                    'label' => 'Plataforma',
                    'required' => true,
                    'optionsData' => $plataformas,
                    'optionKey' => 'idplataforma',
                    'optionLabel' => 'nombrePlataforma',
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

        $baseQuery = DB::table('tipoelemento')
            ->leftJoin('plataforma', 'tipoelemento.plataforma_idplataforma', '=', 'plataforma.idplataforma')
            ->select('tipoelemento.*', 'plataforma.nombrePlataforma as plataforma');

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

        $rows = $baseQuery
            ->orderBy('tipoelemento.idtipoElemento')
            ->get();

        $columns = [
            ['key' => 'idtipoElemento', 'label' => 'ID'],
            ['key' => 'nombre', 'label' => 'Nombre'],
            ['key' => 'detalle', 'label' => 'Detalle'],
            ['key' => 'plataforma', 'label' => 'Plataforma'],
        ];

        $filename = 'tipo_elemento_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Elemento', $filename);
    }

    public function tiposDocumentoIndex(Request $request): View
    {
        $baseQuery = DB::table('tipodocumento');

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
            'filters' => [],
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

        DB::table('tipodocumento')->where('idtipoDocumento', $id)->update($validated);
        $this->publishResourceEvent('configuracion.tipo_documento', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.tipo_documento', (string) $id);

        return redirect()
            ->route('modules.configuracion.tipos-documento.index')
            ->with('success', 'Tipo de documento actualizado correctamente.');
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

        $baseQuery = DB::table('tipodocumento');

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

        $rows = $baseQuery
            ->orderBy('idtipoDocumento')
            ->get();

        $columns = [
            ['key' => 'idtipoDocumento', 'label' => 'ID'],
            ['key' => 'detalle', 'label' => 'Detalle'],
            ['key' => 'serie', 'label' => 'Serie'],
            ['key' => 'correlativo', 'label' => 'Correlativo'],
            ['key' => 'area', 'label' => 'Area'],
        ];

        $filename = 'tipo_documento_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Documento', $filename);
    }

    public function formasPagoIndex(Request $request): View
    {
        $baseQuery = DB::table('formapago');

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
            'filters' => [],
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

        $baseQuery = DB::table('formapago');

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

        $rows = $baseQuery
            ->orderBy('idformaPago')
            ->get();

        $columns = [
            ['key' => 'idformaPago', 'label' => 'ID'],
            ['key' => 'detalle', 'label' => 'Detalle'],
            ['key' => 'tiempo', 'label' => 'Tiempo'],
        ];

        $filename = 'forma_pago_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Formas de Pago', $filename);
    }

    public function entidadesBancariasIndex(Request $request): View
    {
        $baseQuery = DB::table('entidadbancaria');

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
            'filters' => [],
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

        $baseQuery = DB::table('entidadbancaria');

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

        $rows = $baseQuery
            ->orderBy('identidadBancaria')
            ->get();

        $columns = [
            ['key' => 'identidadBancaria', 'label' => 'ID'],
            ['key' => 'razonSocial', 'label' => 'Razon social'],
            ['key' => 'ruc', 'label' => 'RUC'],
            ['key' => 'descripcion', 'label' => 'Descripcion'],
        ];

        $filename = 'entidad_bancaria_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Entidades Bancarias', $filename);
    }

    public function operadoresIndex(Request $request): View
    {
        $baseQuery = DB::table('operador');

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
            'filters' => [],
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

        $baseQuery = DB::table('operador');

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idoperador', 'like', $term)
                    ->orWhere('nombre', 'like', $term);
            });
        }

        $rows = $baseQuery
            ->orderBy('idoperador')
            ->get();

        $columns = [
            ['key' => 'idoperador', 'label' => 'ID'],
            ['key' => 'nombre', 'label' => 'Nombre'],
        ];

        $filename = 'operador_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Operadores', $filename);
    }

    public function tiposVehiculoIndex(Request $request): View
    {
        $baseQuery = DB::table('tipovehiculo');

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
            'filters' => [],
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

        $baseQuery = DB::table('tipovehiculo');

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idtipoVehiculo', 'like', $term)
                    ->orWhere('nombre', 'like', $term);
            });
        }

        $rows = $baseQuery
            ->orderBy('idtipoVehiculo')
            ->get();

        $columns = [
            ['key' => 'idtipoVehiculo', 'label' => 'ID'],
            ['key' => 'nombre', 'label' => 'Nombre'],
        ];

        $filename = 'tipo_vehiculo_export_' . now()->format('Ymd_His') . '.' . $format;


        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Vehículo', $filename);
    }

    public function tiposOperacionIndex(Request $request): View
    {
        $baseQuery = DB::table('tipooperacion');

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
            'filters' => [],
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

        $baseQuery = DB::table('tipooperacion');

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

        $rows = $baseQuery
            ->orderBy('idtipoOperacion')
            ->get();

        $columns = [
            ['key' => 'idtipoOperacion', 'label' => 'ID'],
            ['key' => 'nomenclatura', 'label' => 'Nomenclatura'],
            ['key' => 'detalle', 'label' => 'Detalle'],
        ];

        $filename = 'tipo_operacion_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Operación', $filename);
    }

    public function listaprecioIndex(Request $request): View
    {
        $baseQuery = DB::table('listaprecio');

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
            'filters' => [],
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

        $baseQuery = DB::table('listaprecio');

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idListaPrecio', 'like', $term)
                    ->orWhere('nombreLista', 'like', $term);
            });
        }

        $rows = $baseQuery
            ->orderBy('idListaPrecio')
            ->get();

        $columns = [
            ['key' => 'idListaPrecio', 'label' => 'ID'],
            ['key' => 'nombreLista', 'label' => 'Nombre lista'],
        ];

        $filename = 'listaprecio_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Listas de Precio', $filename);
    }

    public function tipopedidoIndex(Request $request): View
    {
        $baseQuery = DB::table('tipopedido');

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
            'filters' => [],
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

        $baseQuery = DB::table('tipopedido');

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

        $rows = $baseQuery
            ->orderBy('idtipoPedido')
            ->get();

        $columns = [
            ['key' => 'idtipoPedido', 'label' => 'ID'],
            ['key' => 'nomenclatura', 'label' => 'Nomenclatura'],
            ['key' => 'detalle', 'label' => 'Detalle'],
        ];

        $filename = 'tipo_pedido_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Tipos de Pedido', $filename);
    }

    public function proveedorIndex(Request $request): View
    {
        $baseQuery = DB::table('proveedor');

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
            'filters' => [],
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
            'idproveedor' => ['required', 'string', 'min:1', 'max:15', 'regex:' . self::SAFE_TEXT_REGEX],
            'razonSocial' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'tipoProveedor' => ['required', 'string', 'min:2', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        DB::table('proveedor')->insert($validated);
        $this->publishResourceEvent('configuracion.proveedor', $validated['idproveedor'] ?? '', 'created');

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
                    'disabled' => true,
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
            'razonSocial' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'tipoProveedor' => ['required', 'string', 'min:2', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        DB::table('proveedor')->where('idproveedor', $id)->update($validated);
        $this->publishResourceEvent('configuracion.proveedor', $id, 'updated');

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
            DB::table('proveedor')->where('idproveveedor', $id)->delete();
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

    public function proveedorExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $baseQuery = DB::table('proveedor');

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

        $rows = $baseQuery
            ->orderBy('idproveedor')
            ->get();

        $columns = [
            ['key' => 'idproveedor', 'label' => 'ID'],
            ['key' => 'razonSocial', 'label' => 'Razón social'],
            ['key' => 'tipoProveedor', 'label' => 'Tipo proveedor'],
        ];

        $filename = 'proveedor_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Proveedores', $filename);
    }

    public function vigenciaofertaIndex(Request $request): View
    {
        $baseQuery = DB::table('vigenciaoferta');

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
            'filters' => [],
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

        $baseQuery = DB::table('vigenciaoferta');

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

        $rows = $baseQuery
            ->orderBy('idvigenciaOferta')
            ->get();

        $columns = [
            ['key' => 'idvigenciaOferta', 'label' => 'ID'],
            ['key' => 'detalle', 'label' => 'Detalle'],
            ['key' => 'dias', 'label' => 'Días'],
        ];

        $filename = 'vigencia_oferta_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Vigencias de Oferta', $filename);
    }

    public function certificadosUnatIndex(Request $request): View
    {
        $baseQuery = DB::table('certificadosunat');

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
            'filters' => [],
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
        ]);

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

        $baseQuery = DB::table('certificadosunat');

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

        $rows = $baseQuery
            ->orderBy('idcertificadoSUNAT')
            ->get()
            ->map(function ($row) {
                $row->fechaEmision = $row->fechaEmision ? date('Y-m-d', strtotime($row->fechaEmision)) : null;
                $row->fechaVencimiento = $row->fechaVencimiento ? date('Y-m-d', strtotime($row->fechaVencimiento)) : null;
                $row->fechaCargaSistema = $row->fechaCargaSistema ? date('Y-m-d', strtotime($row->fechaCargaSistema)) : null;
                return $row;
            });

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
            'filters' => [],
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

        $baseQuery = DB::table('ubigeo');

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

        $rows = $baseQuery
            ->orderBy('idubigeo')
            ->get();

        $columns = [
            ['key' => 'idubigeo', 'label' => 'ID'],
            ['key' => 'departamento', 'label' => 'Departamento'],
            ['key' => 'provincia', 'label' => 'Provincia'],
            ['key' => 'distrito', 'label' => 'Distrito'],
            ['key' => 'pais', 'label' => 'Pais'],
        ];

        $filename = 'ubigeo_export_' . now()->format('Ymd_His') . '.' . $format;


        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Ubigeos', $filename);
    }

    public function cargosIndex(Request $request): View|RedirectResponse
    {
        $baseQuery = DB::table('cargopersonal');

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
            'filters' => [],
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

        $baseQuery = DB::table('cargopersonal');

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('idcargoPersonal', 'like', $term)
                    ->orWhere('descripcion', 'like', $term);
            });
        }

        $rows = $baseQuery
            ->orderBy('descripcion')
            ->orderBy('idcargoPersonal')
            ->get();

        $columns = [
            ['key' => 'idcargoPersonal', 'label' => 'ID'],
            ['key' => 'descripcion', 'label' => 'Descripcion'],
        ];

        $filename = 'cargo_export_' . now()->format('Ymd_His') . '.' . $format;

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

        return view('configuracion.vista.vista', [
            'title' => 'Configuracion: Vista',
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
                'pdf' => route('modules.configuracion.vistas.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.vistas.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de vistas', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => [],
            'createRoute' => route('modules.configuracion.vistas.create'),
            'editRoute' => 'modules.configuracion.vistas.edit',
            'showRoute' => 'modules.configuracion.vistas.edit',
            'destroyRoute' => 'modules.configuracion.vistas.destroy',
            'bulkDestroyRoute' => route('modules.configuracion.vistas.bulk-destroy'),
            'identifierKey' => 'idvista',
            'lockResource' => 'configuracion.vista',
        ]);
    }

    public function vistasCreate(): View
    {
        return view('configuracion.vista.vista-form', [
            'title' => 'Nueva Vista',
            'moduleTitle' => 'Configuracion: Vista',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.vistas.store'),
            'backRoute' => route('modules.configuracion.vistas.index'),
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
                [
                    'name' => 'fechacreacion',
                    'type' => 'datetime-local',
                    'label' => 'Fecha creación',
                    'required' => false,
                    'value' => now()->format('Y-m-d\TH:i'),
                    'helpText' => 'Se usa como fecha y hora de creación.',
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
        $this->publishResourceEvent('configuracion.vista', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.vistas.index')
            ->with('success', 'Vista creada correctamente.');
    }

    public function vistasEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('vista')->where('idvista', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.vistas.index')
                ->with('error', 'No se encontro la vista solicitada.');
        }

        return view('configuracion.vista.vista-form', [
            'title' => 'Editar Vista',
            'moduleTitle' => 'Configuracion: Vista',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.vistas.update', $id),
            'backRoute' => route('modules.configuracion.vistas.index'),
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
                [
                    'name' => 'fechacreacion',
                    'type' => 'datetime-local',
                    'label' => 'Fecha creación',
                    'required' => false,
                    'value' => self::formatDateTimeForForm((string) ($record->fechacreacion ?? '')),
                    'helpText' => 'Se usa como fecha y hora de creación.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.vista', (string) $id));
    }

    public function vistasUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('vista')->where('idvista', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.vistas.index')
                ->with('error', 'No se encontro la vista solicitada.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.vista', (string) $id, 'vista', 'modules.configuracion.vistas.index')) {
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
        $this->publishResourceEvent('configuracion.vista', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.vista', (string) $id);

        return redirect()
            ->route('modules.configuracion.vistas.index')
            ->with('success', 'Vista actualizada correctamente.');
    }

    public function vistasDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.vista', (string) $id, 'vista', 'modules.configuracion.vistas.index')) {
            return $redirect;
        }

        try {
            DB::table('vista')->where('idvista', $id)->delete();
            $this->publishResourceEvent('configuracion.vista', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.vista', (string) $id);

            return redirect()
                ->route('modules.configuracion.vistas.index')
                ->with('success', 'Vista eliminada correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.vistas.index')
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
                ->route('modules.configuracion.vistas.index')
                ->with('error', 'No se seleccionaron registros para eliminar.');
        }

        foreach ($selectedIds as $selectedId) {
            if ($redirect = $this->assertLockAvailable($request, 'configuracion.vista', (string) $selectedId, 'vista', 'modules.configuracion.vistas.index')) {
                return $redirect;
            }
        }

        try {
            DB::transaction(function () use ($selectedIds, $request) {
                DB::table('vista')->whereIn('idvista', $selectedIds)->delete();

                foreach ($selectedIds as $selectedId) {
                    $this->publishResourceEvent('configuracion.vista', (string) $selectedId, 'deleted');
                    $this->releaseLockIfOwned($request, 'configuracion.vista', (string) $selectedId);
                }
            });

            return redirect()
                ->route('modules.configuracion.vistas.index')
                ->with('success', 'Vistas eliminadas correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.vistas.index')
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

        $items->through(function ($row) {
            if (isset($row->fechacreacion)) {
                $row->fechacreacion = self::formatDateTimeForList((string) $row->fechacreacion);
            }

            return $row;
        });

        return view('configuracion.flujo.flujo', [
            'title' => 'Configuracion: Flujo',
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
                'pdf' => route('modules.configuracion.flujos.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.flujos.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de flujos', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => [],
            'createRoute' => route('modules.configuracion.flujos.create'),
            'editRoute' => 'modules.configuracion.flujos.edit',
            'showRoute' => 'modules.configuracion.flujos.edit',
            'destroyRoute' => 'modules.configuracion.flujos.destroy',
            'bulkDestroyRoute' => route('modules.configuracion.flujos.bulk-destroy'),
            'identifierKey' => 'idflujo',
            'lockResource' => 'configuracion.flujo',
        ]);
    }

    public function flujosCreate(): View
    {
        $tiposOperacion = DB::table('tipooperacion')
            ->orderBy('detalle')
            ->select('idtipoOperacion', DB::raw("CONCAT(nomenclatura, ' - ', detalle) as label"))
            ->get();

        return view('configuracion.flujo.flujo-form', [
            'title' => 'Nuevo Flujo',
            'moduleTitle' => 'Configuracion: Flujo',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.flujos.store'),
            'backRoute' => route('modules.configuracion.flujos.index'),
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
                [
                    'name' => 'fechacreacion',
                    'type' => 'datetime-local',
                    'label' => 'Fecha creación',
                    'required' => false,
                    'value' => now()->format('Y-m-d\TH:i'),
                    'helpText' => 'Se usa como fecha y hora de creación.',
                ],
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
        ]);

        $validated['fechacreacion'] = self::normalizeDateTimeInput($validated['fechacreacion'] ?? null) ?? now()->format('Y-m-d H:i:s');

        $newId = DB::table('flujo')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.flujo', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.flujos.index')
            ->with('success', 'Flujo creado correctamente.');
    }

    public function flujosEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('flujo')->where('idflujo', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.flujos.index')
                ->with('error', 'No se encontro el flujo solicitado.');
        }

        $tiposOperacion = DB::table('tipooperacion')
            ->orderBy('detalle')
            ->select('idtipoOperacion', DB::raw("CONCAT(nomenclatura, ' - ', detalle) as label"))
            ->get();

        return view('configuracion.flujo.flujo-form', [
            'title' => 'Editar Flujo',
            'moduleTitle' => 'Configuracion: Flujo',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.flujos.update', $id),
            'backRoute' => route('modules.configuracion.flujos.index'),
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
                [
                    'name' => 'fechacreacion',
                    'type' => 'datetime-local',
                    'label' => 'Fecha creación',
                    'required' => false,
                    'value' => self::formatDateTimeForForm((string) ($record->fechacreacion ?? '')),
                    'helpText' => 'Se usa como fecha y hora de creación.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.flujo', (string) $id));
    }

    public function flujosUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('flujo')->where('idflujo', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.flujos.index')
                ->with('error', 'No se encontro el flujo solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.flujo', (string) $id, 'flujo', 'modules.configuracion.flujos.index')) {
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
        $this->publishResourceEvent('configuracion.flujo', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.flujo', (string) $id);

        return redirect()
            ->route('modules.configuracion.flujos.index')
            ->with('success', 'Flujo actualizado correctamente.');
    }

    public function flujosDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.flujo', (string) $id, 'flujo', 'modules.configuracion.flujos.index')) {
            return $redirect;
        }

        try {
            DB::table('flujo')->where('idflujo', $id)->delete();
            $this->publishResourceEvent('configuracion.flujo', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.flujo', (string) $id);

            return redirect()
                ->route('modules.configuracion.flujos.index')
                ->with('success', 'Flujo eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.flujos.index')
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
                ->route('modules.configuracion.flujos.index')
                ->with('error', 'No se seleccionaron registros para eliminar.');
        }

        foreach ($selectedIds as $selectedId) {
            if ($redirect = $this->assertLockAvailable($request, 'configuracion.flujo', (string) $selectedId, 'flujo', 'modules.configuracion.flujos.index')) {
                return $redirect;
            }
        }

        try {
            DB::transaction(function () use ($selectedIds, $request) {
                DB::table('flujo')->whereIn('idflujo', $selectedIds)->delete();

                foreach ($selectedIds as $selectedId) {
                    $this->publishResourceEvent('configuracion.flujo', (string) $selectedId, 'deleted');
                    $this->releaseLockIfOwned($request, 'configuracion.flujo', (string) $selectedId);
                }
            });

            return redirect()
                ->route('modules.configuracion.flujos.index')
                ->with('success', 'Flujos eliminados correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.flujos.index')
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

        $items = $baseQuery
            ->orderBy('flujoregla.idflujoregla')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('configuracion.flujoregla.flujoregla', [
            'title' => 'Configuracion: Flujo Regla',
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
                'pdf' => route('modules.configuracion.flujo-reglas.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.flujo-reglas.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de reglas de flujo', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => [],
            'createRoute' => route('modules.configuracion.flujo-reglas.create'),
            'editRoute' => 'modules.configuracion.flujo-reglas.edit',
            'showRoute' => 'modules.configuracion.flujo-reglas.edit',
            'destroyRoute' => 'modules.configuracion.flujo-reglas.destroy',
            'bulkDestroyRoute' => route('modules.configuracion.flujo-reglas.bulk-destroy'),
            'identifierKey' => 'idflujoregla',
            'lockResource' => 'configuracion.flujoregla',
        ]);
    }

    public function flujoReglasCreate(): View
    {
        $flujos = DB::table('flujo')
            ->orderBy('nombre')
            ->select('idflujo', DB::raw("CONCAT(idflujo, ' - ', nombre) as label"))
            ->get();
        $vistas = DB::table('vista')
            ->orderBy('nombre')
            ->select('idvista', DB::raw("CONCAT(idvista, ' - ', nombre) as label"))
            ->get();

        return view('configuracion.flujoregla.flujoregla-form', [
            'title' => 'Nueva Flujo Regla',
            'moduleTitle' => 'Configuracion: Flujo Regla',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.flujo-reglas.store'),
            'backRoute' => route('modules.configuracion.flujo-reglas.index'),
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
        $this->publishResourceEvent('configuracion.flujoregla', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.flujo-reglas.index')
            ->with('success', 'Flujo regla creada correctamente.');
    }

    public function flujoReglasEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('flujoregla')->where('idflujoregla', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.flujo-reglas.index')
                ->with('error', 'No se encontro la regla de flujo solicitada.');
        }

        $flujos = DB::table('flujo')
            ->orderBy('nombre')
            ->select('idflujo', DB::raw("CONCAT(idflujo, ' - ', nombre) as label"))
            ->get();
        $vistas = DB::table('vista')
            ->orderBy('nombre')
            ->select('idvista', DB::raw("CONCAT(idvista, ' - ', nombre) as label"))
            ->get();

        return view('configuracion.flujoregla.flujoregla-form', [
            'title' => 'Editar Flujo Regla',
            'moduleTitle' => 'Configuracion: Flujo Regla',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.flujo-reglas.update', $id),
            'backRoute' => route('modules.configuracion.flujo-reglas.index'),
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
        ] + $this->prepareLockViewData('configuracion.flujoregla', (string) $id));
    }

    public function flujoReglasUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('flujoregla')->where('idflujoregla', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.flujo-reglas.index')
                ->with('error', 'No se encontro la regla de flujo solicitada.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.flujoregla', (string) $id, 'flujo regla', 'modules.configuracion.flujo-reglas.index')) {
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
        $this->publishResourceEvent('configuracion.flujoregla', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.flujoregla', (string) $id);

        return redirect()
            ->route('modules.configuracion.flujo-reglas.index')
            ->with('success', 'Flujo regla actualizada correctamente.');
    }

    public function flujoReglasDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.flujoregla', (string) $id, 'flujo regla', 'modules.configuracion.flujo-reglas.index')) {
            return $redirect;
        }

        try {
            DB::table('flujoregla')->where('idflujoregla', $id)->delete();
            $this->publishResourceEvent('configuracion.flujoregla', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.flujoregla', (string) $id);

            return redirect()
                ->route('modules.configuracion.flujo-reglas.index')
                ->with('success', 'Flujo regla eliminada correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.flujo-reglas.index')
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
                ->route('modules.configuracion.flujo-reglas.index')
                ->with('error', 'No se seleccionaron registros para eliminar.');
        }

        foreach ($selectedIds as $selectedId) {
            if ($redirect = $this->assertLockAvailable($request, 'configuracion.flujoregla', (string) $selectedId, 'flujo regla', 'modules.configuracion.flujo-reglas.index')) {
                return $redirect;
            }
        }

        try {
            DB::transaction(function () use ($selectedIds, $request) {
                DB::table('flujoregla')->whereIn('idflujoregla', $selectedIds)->delete();

                foreach ($selectedIds as $selectedId) {
                    $this->publishResourceEvent('configuracion.flujoregla', (string) $selectedId, 'deleted');
                    $this->releaseLockIfOwned($request, 'configuracion.flujoregla', (string) $selectedId);
                }
            });

            return redirect()
                ->route('modules.configuracion.flujo-reglas.index')
                ->with('success', 'Reglas de flujo eliminadas correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.flujo-reglas.index')
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

        return view('configuracion.historialflujo.historialflujo', [
            'title' => 'Configuracion: Historial Flujo',
            'singularTitle' => 'Historial Flujo',
            'items' => $items,
            'columns' => [
                ['key' => 'idhistorialflujo', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'usuario', 'label' => 'Usuario', 'type' => 'text'],
                ['key' => 'ticket', 'label' => 'Ticket', 'type' => 'text'],
                ['key' => 'flujoregla', 'label' => 'Regla', 'type' => 'text'],
                ['key' => 'vista', 'label' => 'Vista', 'type' => 'text'],
                ['key' => 'fechaejecucion', 'label' => 'Fecha ejecución', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.configuracion.historial-flujos.export', ['format' => 'pdf']),
                'xlsx' => route('modules.configuracion.historial-flujos.export', ['format' => 'xlsx']),
            ],
            'stats' => [
                ['label' => 'Total de registros de historial', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => [],
            'createRoute' => route('modules.configuracion.historial-flujos.create'),
            'editRoute' => 'modules.configuracion.historial-flujos.edit',
            'showRoute' => 'modules.configuracion.historial-flujos.edit',
            'destroyRoute' => 'modules.configuracion.historial-flujos.destroy',
            'bulkDestroyRoute' => route('modules.configuracion.historial-flujos.bulk-destroy'),
            'identifierKey' => 'idhistorialflujo',
            'lockResource' => 'configuracion.historialflujo',
        ]);
    }

    public function historialFlujosCreate(): View
    {
        $usuarios = DB::table('usuario')
            ->orderBy('usuario')
            ->select('usuario', DB::raw('usuario as label'))
            ->get();
        $tickets = DB::table('ticket')
            ->orderByDesc('idticket')
            ->select('idticket', DB::raw("CONCAT(idticket, ' - ', COALESCE(detalle, pedidoReferencia, 'Sin detalle')) as label"))
            ->get();
        $flujosRegla = DB::table('flujoregla')
            ->orderBy('idflujoregla')
            ->select('idflujoregla', DB::raw("CONCAT('Orden ', COALESCE(orden, '-'), ' - ', COALESCE(condicion, 'Sin condición')) as label"))
            ->get();
        $vistas = DB::table('vista')
            ->orderBy('nombre')
            ->select('idvista', DB::raw("CONCAT(idvista, ' - ', nombre) as label"))
            ->get();

        return view('configuracion.historialflujo.historialflujo-form', [
            'title' => 'Nuevo Historial Flujo',
            'moduleTitle' => 'Configuracion: Historial Flujo',
            'mode' => 'create',
            'formAction' => route('modules.configuracion.historial-flujos.store'),
            'backRoute' => route('modules.configuracion.historial-flujos.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'usuario_usuario',
                    'type' => 'select',
                    'label' => 'Usuario',
                    'required' => true,
                    'optionsData' => $usuarios,
                    'optionKey' => 'usuario',
                    'optionLabel' => 'label',
                    'helpText' => 'Selecciona el usuario que ejecutó el flujo.',
                ],
                [
                    'name' => 'ticket_idticket',
                    'type' => 'select',
                    'label' => 'Ticket',
                    'required' => true,
                    'optionsData' => $tickets,
                    'optionKey' => 'idticket',
                    'optionLabel' => 'label',
                    'helpText' => 'Selecciona el ticket relacionado.',
                ],
                [
                    'name' => 'flujoregla_idflujoregla',
                    'type' => 'select',
                    'label' => 'Regla de flujo',
                    'required' => true,
                    'optionsData' => $flujosRegla,
                    'optionKey' => 'idflujoregla',
                    'optionLabel' => 'label',
                    'helpText' => 'Selecciona la regla aplicada.',
                ],
                [
                    'name' => 'vista_idvista',
                    'type' => 'select',
                    'label' => 'Vista',
                    'required' => true,
                    'optionsData' => $vistas,
                    'optionKey' => 'idvista',
                    'optionLabel' => 'label',
                    'helpText' => 'Selecciona la vista relacionada.',
                ],
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => false,
                    'maxlength' => 45,
                    'minlength' => 0,
                    'helpText' => 'Detalle opcional de la ejecución.',
                ],
                [
                    'name' => 'resultado',
                    'type' => 'text',
                    'label' => 'Resultado',
                    'required' => true,
                    'maxlength' => 45,
                    'minlength' => 2,
                    'helpText' => 'Resultado de la ejecución.',
                ],
                [
                    'name' => 'fechaejecucion',
                    'type' => 'datetime-local',
                    'label' => 'Fecha ejecución',
                    'required' => false,
                    'value' => now()->format('Y-m-d\TH:i'),
                    'helpText' => 'Fecha y hora de ejecución.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function historialFlujosStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'usuario_usuario' => ['required', 'string', 'max:50', 'exists:usuario,usuario'],
            'ticket_idticket' => ['required', 'integer', 'exists:ticket,idticket'],
            'flujoregla_idflujoregla' => ['required', 'integer', 'exists:flujoregla,idflujoregla'],
            'vista_idvista' => ['required', 'integer', 'exists:vista,idvista'],
            'detalle' => ['nullable', 'string', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
            'resultado' => ['required', 'string', 'min:2', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
            'fechaejecucion' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ]);

        $validated['fechaejecucion'] = self::normalizeDateTimeInput($validated['fechaejecucion'] ?? null) ?? now()->format('Y-m-d H:i:s');

        $newId = DB::table('historialflujo')->insertGetId($validated);
        $this->publishResourceEvent('configuracion.historialflujo', (string) $newId, 'created');

        return redirect()
            ->route('modules.configuracion.historial-flujos.index')
            ->with('success', 'Historial de flujo creado correctamente.');
    }

    public function historialFlujosEdit(int $id): View|RedirectResponse
    {
        $record = DB::table('historialflujo')->where('idhistorialflujo', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.configuracion.historial-flujos.index')
                ->with('error', 'No se encontro el historial solicitado.');
        }

        $usuarios = DB::table('usuario')
            ->orderBy('usuario')
            ->select('usuario', DB::raw('usuario as label'))
            ->get();
        $tickets = DB::table('ticket')
            ->orderByDesc('idticket')
            ->select('idticket', DB::raw("CONCAT(idticket, ' - ', COALESCE(detalle, pedidoReferencia, 'Sin detalle')) as label"))
            ->get();
        $flujosRegla = DB::table('flujoregla')
            ->orderBy('idflujoregla')
            ->select('idflujoregla', DB::raw("CONCAT('Orden ', COALESCE(orden, '-'), ' - ', COALESCE(condicion, 'Sin condición')) as label"))
            ->get();
        $vistas = DB::table('vista')
            ->orderBy('nombre')
            ->select('idvista', DB::raw("CONCAT(idvista, ' - ', nombre) as label"))
            ->get();

        return view('configuracion.historialflujo.historialflujo-form', [
            'title' => 'Editar Historial Flujo',
            'moduleTitle' => 'Configuracion: Historial Flujo',
            'mode' => 'edit',
            'formAction' => route('modules.configuracion.historial-flujos.update', $id),
            'backRoute' => route('modules.configuracion.historial-flujos.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'usuario_usuario',
                    'type' => 'select',
                    'label' => 'Usuario',
                    'required' => true,
                    'optionsData' => $usuarios,
                    'optionKey' => 'usuario',
                    'optionLabel' => 'label',
                    'helpText' => 'Selecciona el usuario que ejecutó el flujo.',
                ],
                [
                    'name' => 'ticket_idticket',
                    'type' => 'select',
                    'label' => 'Ticket',
                    'required' => true,
                    'optionsData' => $tickets,
                    'optionKey' => 'idticket',
                    'optionLabel' => 'label',
                    'helpText' => 'Selecciona el ticket relacionado.',
                ],
                [
                    'name' => 'flujoregla_idflujoregla',
                    'type' => 'select',
                    'label' => 'Regla de flujo',
                    'required' => true,
                    'optionsData' => $flujosRegla,
                    'optionKey' => 'idflujoregla',
                    'optionLabel' => 'label',
                    'helpText' => 'Selecciona la regla aplicada.',
                ],
                [
                    'name' => 'vista_idvista',
                    'type' => 'select',
                    'label' => 'Vista',
                    'required' => true,
                    'optionsData' => $vistas,
                    'optionKey' => 'idvista',
                    'optionLabel' => 'label',
                    'helpText' => 'Selecciona la vista relacionada.',
                ],
                [
                    'name' => 'detalle',
                    'type' => 'text',
                    'label' => 'Detalle',
                    'required' => false,
                    'maxlength' => 45,
                    'minlength' => 0,
                    'helpText' => 'Detalle opcional de la ejecución.',
                ],
                [
                    'name' => 'resultado',
                    'type' => 'text',
                    'label' => 'Resultado',
                    'required' => true,
                    'maxlength' => 45,
                    'minlength' => 2,
                    'helpText' => 'Resultado de la ejecución.',
                ],
                [
                    'name' => 'fechaejecucion',
                    'type' => 'datetime-local',
                    'label' => 'Fecha ejecución',
                    'required' => false,
                    'value' => self::formatDateTimeForForm((string) ($record->fechaejecucion ?? '')),
                    'helpText' => 'Fecha y hora de ejecución.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('configuracion.historialflujo', (string) $id));
    }

    public function historialFlujosUpdate(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('historialflujo')->where('idhistorialflujo', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.configuracion.historial-flujos.index')
                ->with('error', 'No se encontro el historial solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'configuracion.historialflujo', (string) $id, 'historial de flujo', 'modules.configuracion.historial-flujos.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'usuario_usuario' => ['required', 'string', 'max:50', 'exists:usuario,usuario'],
            'ticket_idticket' => ['required', 'integer', 'exists:ticket,idticket'],
            'flujoregla_idflujoregla' => ['required', 'integer', 'exists:flujoregla,idflujoregla'],
            'vista_idvista' => ['required', 'integer', 'exists:vista,idvista'],
            'detalle' => ['nullable', 'string', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
            'resultado' => ['required', 'string', 'min:2', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
            'fechaejecucion' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ]);

        $validated['fechaejecucion'] = self::normalizeDateTimeInput($validated['fechaejecucion'] ?? null) ?? DB::table('historialflujo')->where('idhistorialflujo', $id)->value('fechaejecucion');

        DB::table('historialflujo')->where('idhistorialflujo', $id)->update($validated);
        $this->publishResourceEvent('configuracion.historialflujo', (string) $id, 'updated');

        $this->releaseLockIfOwned($request, 'configuracion.historialflujo', (string) $id);

        return redirect()
            ->route('modules.configuracion.historial-flujos.index')
            ->with('success', 'Historial de flujo actualizado correctamente.');
    }

    public function historialFlujosDestroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'configuracion.historialflujo', (string) $id, 'historial de flujo', 'modules.configuracion.historial-flujos.index')) {
            return $redirect;
        }

        try {
            DB::table('historialflujo')->where('idhistorialflujo', $id)->delete();
            $this->publishResourceEvent('configuracion.historialflujo', (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, 'configuracion.historialflujo', (string) $id);

            return redirect()
                ->route('modules.configuracion.historial-flujos.index')
                ->with('success', 'Historial de flujo eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.historial-flujos.index')
                ->with('error', 'No se puede eliminar el historial porque tiene registros relacionados.');
        }
    }

    public function historialFlujosBulkDestroy(Request $request): RedirectResponse
    {
        $selectedIds = $request->input('selectedIds', []);
        if (!is_array($selectedIds)) {
            $selectedIds = [];
        }

        $selectedIds = array_filter(array_map('intval', $selectedIds), fn ($id) => $id > 0);
        if (empty($selectedIds)) {
            return redirect()
                ->route('modules.configuracion.historial-flujos.index')
                ->with('error', 'No se seleccionaron registros para eliminar.');
        }

        foreach ($selectedIds as $selectedId) {
            if ($redirect = $this->assertLockAvailable($request, 'configuracion.historialflujo', (string) $selectedId, 'historial de flujo', 'modules.configuracion.historial-flujos.index')) {
                return $redirect;
            }
        }

        try {
            DB::transaction(function () use ($selectedIds, $request) {
                DB::table('historialflujo')->whereIn('idhistorialflujo', $selectedIds)->delete();

                foreach ($selectedIds as $selectedId) {
                    $this->publishResourceEvent('configuracion.historialflujo', (string) $selectedId, 'deleted');
                    $this->releaseLockIfOwned($request, 'configuracion.historialflujo', (string) $selectedId);
                }
            });

            return redirect()
                ->route('modules.configuracion.historial-flujos.index')
                ->with('success', 'Historiales de flujo eliminados correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.configuracion.historial-flujos.index')
                ->with('error', 'No se pueden eliminar los historiales porque tienen registros relacionados.');
        }
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
            ->get()
            ->map(function ($row) {
                if (isset($row->fechaejecucion)) {
                    $row->fechaejecucion = self::formatDateTimeForList((string) $row->fechaejecucion);
                }

                return $row;
            });

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

    private static function cargoLockResource(): string
    {
        return self::CARGO_LOCK_RESOURCE;
    }

    private static function cargoLockId(int $id): string
    {
        return (string) $id;
    }
}

