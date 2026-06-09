<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Export\ExportableList;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ElementoAlmacenController extends Controller
{
    use ExportableList;

    public function elementoAlmacenIndex(Request $request): View
    {
        $baseQuery = DB::table('elementoalmacen as e')
            ->leftJoin('almacen as a', 'a.idalmacen', '=', 'e.dispositivo_iddispositivo')
            ->leftJoin('empresapropietaria as ep', 'a.empresaPropietaria_RUC', '=', 'ep.RUC')
            ->select([
                'e.imei',
                'e.dispositivo_iddispositivo',
                'e.fechaIngreso',
                'e.estado',
                'e.idAuxiliar',
                DB::raw('COALESCE(a.detalle, "") as almacen_detalle'),
                DB::raw('TRIM(CONCAT(COALESCE(NULLIF(TRIM(ep.razonSocial), ""), "Sin empresa"), " - ", COALESCE(NULLIF(TRIM(a.detalle), ""), "Sin dispositivo"))) as almacen_label'),
                DB::raw('CASE WHEN e.estado = 1 THEN "Activo" ELSE "Inactivo" END as estado_label'),
            ]);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('e.imei', 'like', $term)
                    ->orWhere('e.dispositivo_iddispositivo', 'like', $term)
                    ->orWhere('a.detalle', 'like', $term)
                    ->orWhere('ep.razonSocial', 'like', $term)
                    ->orWhere('e.fechaIngreso', 'like', $term)
                    ->orWhere('e.estado', 'like', $term)
                    ->orWhere('e.idAuxiliar', 'like', $term);
            });
        }

        $imei = trim((string) $request->input('imei', ''));
        if ($imei !== '') {
            $baseQuery->where('e.imei', 'like', '%' . $imei . '%');
        }

        $dispositivo = trim((string) $request->input('dispositivo_iddispositivo', ''));
        if ($dispositivo !== '') {
            $baseQuery->where('e.dispositivo_iddispositivo', $dispositivo);
        }

        $fechaIngreso = trim((string) $request->input('fechaIngreso', ''));
        if ($fechaIngreso !== '') {
            $baseQuery->whereDate('e.fechaIngreso', $fechaIngreso);
        }

        $estado = trim((string) $request->input('estado', ''));
        if ($estado !== '') {
            $baseQuery->where('e.estado', (int) $estado);
        }

        $idAuxiliar = trim((string) $request->input('idAuxiliar', ''));
        if ($idAuxiliar !== '') {
            $baseQuery->where('e.idAuxiliar', 'like', '%' . $idAuxiliar . '%');
        }

        $items = $baseQuery
            ->orderBy('e.imei')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $queryParams = $request->except('page');

        return view('almacen.elemento-almacen.index', [
            'title' => 'Almacén: Elemento Almacén',
            'singularTitle' => 'Elemento Almacén',
            'items' => $items,
            'columns' => [
                ['key' => 'imei', 'label' => 'IMEI', 'type' => 'text'],
                ['key' => 'almacen_label', 'label' => 'Dispositivo', 'type' => 'text', 'wrap' => true],
                ['key' => 'fechaIngreso', 'label' => 'Fecha ingreso', 'type' => 'date'],
                ['key' => 'estado', 'label' => 'Estado', 'type' => 'status'],
                ['key' => 'idAuxiliar', 'label' => 'ID Auxiliar', 'type' => 'text'],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.almacen.elemento-almacen.export', array_merge(['format' => 'pdf'], $queryParams)),
                'xlsx' => route('modules.almacen.elemento-almacen.export', array_merge(['format' => 'xlsx'], $queryParams)),
            ],
            'stats' => [
                ['label' => 'Total de elementos de almacén', 'value' => (clone $baseQuery)->count()],
            ],
            'filters' => [
                [
                    'name' => 'imei', 
                    'label' => 'IMEI', 
                    'type' => 'text'
                ],
                [
                    'name' => 'dispositivo_iddispositivo',
                    'label' => 'Dispositivo',
                    'type' => 'select',
                    'options' => $this->almacenOptions(),
                    'placeholder' => 'Todos los dispositivos',
                ],
                [
                    'name' => 'fechaIngreso',
                    'label' => 'Fecha ingreso',
                    'type' => 'date',
                    'placeholder' => 'Fecha',
                ],
                [
                    'name' => 'estado',
                    'label' => 'Estado',
                    'type' => 'select',
                    'options' => [
                        ['value' => '1', 'label' => 'Activo'],
                        ['value' => '0', 'label' => 'Inactivo'],
                    ],
                    'placeholder' => 'Todos los estados',
                ],
                [
                    'name' => 'idAuxiliar',
                    'label' => 'ID Auxiliar',
                    'type' => 'text',
                    'placeholder' => 'ID Auxiliar',
                ],
            ],
            'queryParams' => $queryParams,
            'createRoute' => route('modules.almacen.elemento-almacen.create'),
            'editRoute' => 'modules.almacen.elemento-almacen.edit',
            'showRoute' => 'modules.almacen.elemento-almacen.edit',
            'destroyRoute' => 'modules.almacen.elemento-almacen.destroy',
            'bulkDestroyRoute' => route('modules.almacen.elemento-almacen.bulk-destroy'),
            'identifierKey' => 'imei',
            'lockResource' => 'almacen.elemento_almacen',
        ]);
    }

    public function elementoAlmacenCreate(): View
    {
        return view('almacen.elemento-almacen.form', [
            'title' => 'Nuevo Elemento Almacén',
            'moduleTitle' => 'Almacén: Elemento Almacén',
            'mode' => 'create',
            'formAction' => route('modules.almacen.elemento-almacen.store'),
            'backRoute' => route('modules.almacen.elemento-almacen.index'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'imei',
                    'type' => 'text',
                    'label' => 'IMEI',
                    'required' => true,
                    'maxlength' => 30,
                    'minlength' => 1,
                    'pattern' => '^[0-9]+$',
                    'inputmode' => 'numeric',
                    'helpText' => 'Solo números, hasta 30 caracteres.',
                ],
                [
                    'name' => 'dispositivo_iddispositivo',
                    'type' => 'select',
                    'label' => 'Dispositivo (Almacén)',
                    'required' => true,
                    'tomSelect' => true,
                    'placeholder' => 'Selecciona un dispositivo de Almacén',
                    'optionsData' => $this->almacenOptions(),
                    'optionKey' => 'idalmacen',
                    'optionLabel' => 'label',
                ],
                [
                    'name' => 'estado',
                    'type' => 'select',
                    'label' => 'Estado',
                    'required' => true,
                    'placeholder' => 'Selecciona un estado',
                    'options' => [
                        '1' => 'Activo',
                        '0' => 'Inactivo',                  
                    ],
                ],
                [
                    'name' => 'idAuxiliar',
                    'type' => 'text',
                    'label' => 'ID Auxiliar',
                    'required' => false,
                    'maxlength' => 30,
                    'helpText' => 'Identificador auxiliar opcional.',
                ],
            ],
            'readOnly' => false,
        ]);
    }

    public function elementoAlmacenStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'imei' => ['required', 'string', 'max:30', 'regex:/^[0-9]+$/'],
            'dispositivo_iddispositivo' => ['required', 'integer', 'exists:almacen,idalmacen'],
            'fechaIngreso' => ['nullable', 'date'],
            'estado' => ['nullable', 'integer', 'in:0,1'],
            'idAuxiliar' => ['nullable', 'string', 'max:30'],
        ]);

        $payload = $validated;
        $payload['estado'] = (int) ($payload['estado'] ?? 0);
        $payload['fechaIngreso'] = now()->format('Y-m-d H:i:s');

        DB::table('elementoalmacen')->insert($payload);
        $this->publishResourceEvent('almacen.elemento_almacen', (string) $payload['imei'], 'created');

        return redirect()
            ->route('modules.almacen.elemento-almacen.index')
            ->with('success', 'Elemento de almacén creado correctamente.');
    }

    public function elementoAlmacenEdit(string $id): View|RedirectResponse
    {
        $record = DB::table('elementoalmacen')->where('imei', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.almacen.elemento-almacen.index')
                ->with('error', 'No se encontro el elemento de almacén solicitado.');
        }

        return view('almacen.elemento-almacen.form', [
            'title' => 'Editar Elemento Almacén',
            'moduleTitle' => 'Almacén: Elemento Almacén',
            'mode' => 'edit',
            'formAction' => route('modules.almacen.elemento-almacen.update', $id),
            'backRoute' => route('modules.almacen.elemento-almacen.index'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'imei',
                    'type' => 'text',
                    'label' => 'IMEI',
                    'required' => true,
                    'maxlength' => 30,
                    'minlength' => 1,
                    'pattern' => '^[0-9]+$',
                    'inputmode' => 'numeric',
                    'helpText' => 'Solo números, hasta 30 caracteres.',
                ],
                [
                    'name' => 'dispositivo_iddispositivo',
                    'type' => 'select',
                    'label' => 'Dispositivo (Almacén)',
                    'required' => true,
                    'tomSelect' => true,
                    'placeholder' => 'Selecciona un dispositivo de almacén',
                    'optionsData' => $this->almacenOptions(),
                    'optionKey' => 'idalmacen',
                    'optionLabel' => 'label',
                ],
                [
                    'name' => 'estado',
                    'type' => 'select',
                    'label' => 'Estado',
                    'required' => false,
                    'placeholder' => 'Selecciona un estado',
                    'options' => [
                        '0' => 'Inactivo',
                        '1' => 'Activo',
                    ],
                ],
                [
                    'name' => 'idAuxiliar',
                    'type' => 'text',
                    'label' => 'ID Auxiliar',
                    'required' => false,
                    'maxlength' => 30,
                    'helpText' => 'Identificador auxiliar opcional.',
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData('almacen.elemento_almacen', $id));
    }

    public function elementoAlmacenUpdate(Request $request, string $id): RedirectResponse
    {
        $exists = DB::table('elementoalmacen')->where('imei', $id)->exists();
        if (!$exists) {
            return redirect()
                ->route('modules.almacen.elemento-almacen.index')
                ->with('error', 'No se encontro el elemento de almacén solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, 'almacen.elemento_almacen', $id, 'elemento de almacén', 'modules.almacen.elemento-almacen.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'imei' => ['required', 'string', 'max:30', 'regex:/^[0-9]+$/'],
            'dispositivo_iddispositivo' => ['required', 'integer', 'exists:almacen,idalmacen'],
            'fechaIngreso' => ['nullable', 'date'],
            'estado' => ['nullable', 'integer', 'in:0,1'],
            'idAuxiliar' => ['nullable', 'string', 'max:30'],
        ]);

        $payload = $validated;
        $payload['estado'] = (int) ($payload['estado'] ?? 0);
        unset($payload['fechaIngreso']);

        DB::table('elementoalmacen')->where('imei', $id)->update($payload);
        $this->publishResourceEvent('almacen.elemento_almacen', $id, 'updated');

        $this->releaseLockIfOwned($request, 'almacen.elemento_almacen', $id);

        return redirect()
            ->route('modules.almacen.elemento-almacen.index')
            ->with('success', 'Elemento de almacén actualizado correctamente.');
    }

    public function elementoAlmacenDestroy(Request $request, string $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'almacen.elemento_almacen', $id, 'elemento de almacén', 'modules.almacen.elemento-almacen.index')) {
            return $redirect;
        }

        try {
            DB::table('elementoalmacen')->where('imei', $id)->delete();
            $this->publishResourceEvent('almacen.elemento_almacen', $id, 'deleted');
            $this->releaseLockIfOwned($request, 'almacen.elemento_almacen', $id);
            return redirect()
                ->route('modules.almacen.elemento-almacen.index')
                ->with('success', 'Elemento de almacén eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.almacen.elemento-almacen.index')
                ->with('error', 'No se puede eliminar el elemento de almacén porque tiene registros relacionados.');
        }
    }

    public function elementoAlmacenExport(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $selectedIds = $request->input('selectedIds', []);

        $baseQuery = DB::table('elementoalmacen as e')
            ->leftJoin('almacen as a', 'a.idalmacen', '=', 'e.dispositivo_iddispositivo')
            ->leftJoin('empresapropietaria as ep', 'a.empresaPropietaria_RUC', '=', 'ep.RUC')
            ->select([
                'e.imei',
                'e.dispositivo_iddispositivo',
                'e.fechaIngreso',
                'e.estado',
                'e.idAuxiliar',
                DB::raw('COALESCE(a.detalle, "") as almacen_detalle'),
                DB::raw('CASE WHEN e.estado = 1 THEN "Activo" ELSE "Inactivo" END as estado_label'),
                DB::raw('TRIM(CONCAT(COALESCE(NULLIF(TRIM(ep.razonSocial), ""), "Sin empresa"), " - ", COALESCE(NULLIF(TRIM(a.detalle), ""), "Sin dispositivo"))) as almacen_label'),
            ]);

        if (!empty($selectedIds)) {
            $baseQuery->whereIn('e.imei', $selectedIds);
        } else {
            $search = trim((string) $request->input('q', ''));
            if ($search !== '') {
                $term = '%' . $search . '%';
                $baseQuery->where(function ($query) use ($term) {
                    $query
                        ->where('e.imei', 'like', $term)
                        ->orWhere('e.dispositivo_iddispositivo', 'like', $term)
                        ->orWhere('a.detalle', 'like', $term)
                        ->orWhere('e.fechaIngreso', 'like', $term)
                        ->orWhere('ep.razonSocial', 'like', $term)
                        ->orWhere('e.estado', 'like', $term)
                        ->orWhere('e.idAuxiliar', 'like', $term);
                });
            }
        }

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('e.imei', 'like', $term)
                    ->orWhere('e.dispositivo_iddispositivo', 'like', $term)
                    ->orWhere('a.detalle', 'like', $term)
                    ->orWhere('e.fechaIngreso', 'like', $term)
                    ->orWhere('ep.razonSocial', 'like', $term)
                    ->orWhere('e.estado', 'like', $term)
                    ->orWhere('e.idAuxiliar', 'like', $term);
            });
        }

        $rows = $baseQuery
            ->orderBy('e.imei')
            ->get();

        $columns = [
            ['key' => 'imei', 'label' => 'IMEI'],
            ['key' => 'almacen_label', 'label' => 'Almacén'],
            ['key' => 'fechaIngreso', 'label' => 'Fecha ingreso'],
            ['key' => 'estado_label', 'label' => 'Estado'],
            ['key' => 'idAuxiliar', 'label' => 'ID Auxiliar'],
        ];

        $filename = 'elemento_almacen_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Elementos de Almacén', $filename);
    }

    private function almacenOptions()
    {
        return DB::table('almacen as a')
            ->leftJoin('empresapropietaria as ep', 'a.empresaPropietaria_RUC', '=', 'ep.RUC')
            ->leftJoin('tipoelemento as te', 'a.tipoElemento_idtipoElemento', '=', 'te.idtipoElemento')
            ->whereRaw("LOWER(TRIM(COALESCE(te.nombre, ''))) NOT LIKE '%plan%'")
            ->whereRaw("LOWER(TRIM(COALESCE(te.nombre, ''))) NOT LIKE '%servicio%'")
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
}
