<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Export\ExportableList;
use App\Http\Controllers\Permission\HandlesResourceLock;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AlmacenController extends Controller
{
    use ExportableList;
    use HandlesResourceLock;

    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';

    private const LOCK_RESOURCE = 'almacen';

    public function index(Request $request): View
    {
        $baseQuery = $this->baseQuery($request);
        $statsQuery = clone $baseQuery;

        $items = $baseQuery
            ->orderByDesc('a.idalmacen')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $items->through(function ($row) {
            $row->cantidad_disponible_label = $this->formatDecimal($row->cantidadDisponible ?? null);
            $row->precio_label = $this->formatMoney($row->precio ?? null);
            $row->renovacion_label = is_null($row->renovacion) ? '-' : (string) $row->renovacion;
            $row->usa_red_movil_label = $this->formatYesNo($row->usaRedMovil ?? null);
            return $row;
        });

        $stats = [
            'total' => (clone $statsQuery)->count('a.idalmacen'),
            'empresaPropietaria_RUC' => (clone $statsQuery)->distinct('a.empresaPropietaria_RUC')->count('a.empresaPropietaria_RUC'),
            'con_red_movil' => (clone $statsQuery)->whereIn(DB::raw("LOWER(COALESCE(a.usaRedMovil, ''))"), ['s', '1', 'si', 'sí'])->count('a.idalmacen'),
            'sin_red_movil' => (clone $statsQuery)->whereIn(DB::raw("LOWER(COALESCE(a.usaRedMovil, ''))"), ['n', '0', 'no'])->count('a.idalmacen'),
        ];

        return view('almacen.almacen', [
            'title' => 'Módulo Almacén',
            'singularTitle' => 'Registro',
            'items' => $items,
            'createRoute' => route('modules.almacen.create'),
            'editRoute' => 'modules.almacen.edit',
            'destroyRoute' => 'modules.almacen.destroy',
            'bulkDestroyRoute' => route('modules.almacen.bulk-destroy'),
            'identifierKey' => 'idalmacen',
            'lockResource' => self::LOCK_RESOURCE,
            'showActionsColumn' => true,
            'columns' => [
                ['key' => 'idalmacen', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'empresa_label', 'label' => 'Empresa', 'type' => 'text', 'wrap' => true],
                ['key' => 'modelo_label', 'label' => 'Modelo', 'type' => 'text', 'wrap' => true],
                ['key' => 'marca_label', 'label' => 'Marca', 'type' => 'text', 'wrap' => true],
                ['key' => 'tipo_elemento_label', 'label' => 'Tipo elemento', 'type' => 'text', 'wrap' => true],
                ['key' => 'tecnologia_label', 'label' => 'Tecnología', 'type' => 'text', 'wrap' => true],
                ['key' => 'unidad_medida_label', 'label' => 'Unidad medida', 'type' => 'text', 'wrap' => true],
                ['key' => 'cantidad', 'label' => 'Cantidad', 'type' => 'text'],
                ['key' => 'precio_label', 'label' => 'Precio', 'type' => 'text'],
            ],
            'stats' => [
                ['label' => 'Total registros', 'value' => $stats['total']],
                ['label' => 'Empresas en almacen', 'value' => $stats['empresaPropietaria_RUC']],
            ],
            'filters' => [
                [
                    'name' => 'empresaPropietaria_RUC',
                    'label' => 'Empresa',
                    'options' => $this->empresaOptions(),
                    'placeholder' => 'Todas las empresas',
                ],
                [
                    'name' => 'modelo_idmodelo',
                    'label' => 'Modelo',
                    'options' => $this->modeloOptions(),
                    'placeholder' => 'Todos los modelos',
                ],
                [
                    'name' => 'marca_idmarca',
                    'label' => 'Marca',
                    'options' => $this->marcaOptions(),
                    'placeholder' => 'Todas las marcas',
                ],
                [
                    'name' => 'tipoElemento_idtipoElemento',
                    'label' => 'Tipo elemento',
                    'options' => $this->tipoElementoOptions(),
                    'placeholder' => 'Todos los tipos',
                ],
                [
                    'name' => 'tecnologia_idtecnologia',
                    'label' => 'Tecnología',
                    'options' => $this->tecnologiaOptions(),
                    'placeholder' => 'Todas las tecnologías',
                ],
                [
                    'name' => 'unidadMedida_idunidadMedida',
                    'label' => 'Unidad medida',
                    'options' => $this->unidadMedidaOptions(),
                    'placeholder' => 'Todas las unidades',
                ],
                [
                    'name' => 'cantidad',
                    'label' => 'Cantidad',
                    'type' => 'text',
                    'placeholder' => 'Ej: 1, >3, <=10',
                ],
                [
                    'name' => 'precio',
                    'label' => 'Precio',
                    'type' => 'text',
                    'placeholder' => 'Ej: 100',
                ],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.almacen.export', ['format' => 'pdf']),
                'xlsx' => route('modules.almacen.export', ['format' => 'xlsx']),
            ],
        ]);
    }

    public function export(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }
        // Soportar exportación por selección (selectedIds[] enviado por POST)
        $selectedIds = $request->input('selectedIds', []);

        $columns = [
            ['key' => 'idalmacen', 'label' => 'ID'],
            ['key' => 'empresa_label', 'label' => 'Empresa'],
            ['key' => 'modelo_label', 'label' => 'Modelo'],
            ['key' => 'marca_label', 'label' => 'Marca'],
            ['key' => 'tipo_elemento_label', 'label' => 'Tipo elemento'],
            ['key' => 'tecnologia_label', 'label' => 'Tecnología'],
            ['key' => 'unidad_medida_label', 'label' => 'Unidad medida'],
            ['key' => 'cantidad', 'label' => 'Cantidad'],
            ['key' => 'precio', 'label' => 'Precio'],
        ];

        $filename = 'almacen_export_' . now()->format('Ymd_His') . '.' . $format;

         if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $this->baseQuery($request)->whereIn('a.idalmacen', array_values($selectedIds))->orderBy('a.idalmacen')->get();

            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }

            return $this->exportPdfResponse($rows, $columns, 'Listado de Almacen', $filename);
        }

        $rows = $this->baseQuery($request)->orderByDesc('a.idalmacen')->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Almacén', $filename);
    }

    public function create(): View
    {
        $detailListaPrecioItems = collect();

        return view('almacen.almacen-form', [
            'title' => 'Nuevo Dispositivo',
            'moduleTitle' => 'Módulo Almacén',
            'mode' => 'create',
            'formAction' => route('modules.almacen.store'),
            'backRoute' => route('modules.almacen'),
            'record' => null,
            'fields' => $this->buildFields(null, $detailListaPrecioItems),
            'readOnly' => false,
            'detalleListaPrecioItems' => $detailListaPrecioItems,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateAlmacen($request);
        // manejar subida de imagen (si existe)
        if ($request->hasFile('imagen') && $request->file('imagen')->isValid()) {
            $file = $request->file('imagen');
            $filename = 'almacenimg_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('almacen', $filename, 'public');
            $validated['imagen'] = $path; // almacen/<filename>
        }
        $payload = $this->preparePayload($validated);

        $newId = null;
        DB::transaction(function () use ($payload, $request, &$newId): void {
            $newId = DB::table('almacen')->insertGetId($payload);
            $this->syncDetalleListaPrecioPayload($request, (int) $newId);
            $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $newId, 'created');
        });

        return redirect()
            ->route('modules.almacen')
            ->with('success', 'Registro de almacén creado correctamente.');
    }

    public function edit(int $id): View|RedirectResponse
    {
        $record = DB::table('almacen')->where('idalmacen', $id)->first();

        if (!$record) {
            return redirect()
                ->route('modules.almacen')
                ->with('error', 'No se encontro el registro solicitado.');
        }

        $detailListaPrecioItems = $this->detailListaPrecioItems($id);

        return view('almacen.almacen-form', [
            'title' => 'Editar Almacén',
            'moduleTitle' => 'Módulo Almacén',
            'mode' => 'edit',
            'formAction' => route('modules.almacen.update', $id),
            'backRoute' => route('modules.almacen'),
            'record' => $record,
            'fields' => $this->buildFields($id, $detailListaPrecioItems),
            'readOnly' => true,
            'detalleListaPrecioItems' => $detailListaPrecioItems,
        ] + $this->prepareLockViewData(self::LOCK_RESOURCE, (string) $id));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('almacen')->where('idalmacen', $id)->exists();

        if (!$exists) {
            return redirect()
                ->route('modules.almacen')
                ->with('error', 'No se encontro el registro solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, (string) $id, 'registro de almacén', 'modules.almacen')) {
            return $redirect;
        }

        $validated = $this->validateAlmacen($request);
        // manejar subida de imagen y reemplazo
        $existingImage = DB::table('almacen')->where('idalmacen', $id)->value('imagen');
        if ($request->hasFile('imagen') && $request->file('imagen')->isValid()) {
            $file = $request->file('imagen');
            $filename = 'almacenimg_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('almacen', $filename, 'public');
            $validated['imagen'] = $path; // almacen/<filename>
            // borrar anterior si existe
            if (!empty($existingImage) && Storage::disk('public')->exists($existingImage)) {
                Storage::disk('public')->delete($existingImage);
            }
        }
        $payload = $this->preparePayload($validated);

        DB::transaction(function () use ($payload, $request, $id): void {
            DB::table('almacen')->where('idalmacen', $id)->update($payload);
            $this->syncDetalleListaPrecioPayload($request, $id);
            $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $id, 'updated');
            $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, (string) $id);
        });

        return redirect()
            ->route('modules.almacen')
            ->with('success', 'Registro de almacén actualizado correctamente.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, (string) $id, 'registro de almacén', 'modules.almacen')) {
            return $redirect;
        }

        try {
            // eliminar archivo de imagen asociado si existe
            $existingImage = DB::table('almacen')->where('idalmacen', $id)->value('imagen');
            if (!empty($existingImage) && Storage::disk('public')->exists($existingImage)) {
                Storage::disk('public')->delete($existingImage);
            }
            DB::table('almacen')->where('idalmacen', $id)->delete();
            $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, (string) $id);

            return redirect()
                ->route('modules.almacen')
                ->with('success', 'Registro de almacén eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.almacen')
                ->with('error', 'No se puede eliminar el registro porque tiene relaciones asociadas.');
        }
    }

    private function baseQuery(Request $request)
    {
        $query = DB::table('almacen as a')
            ->leftJoin('empresapropietaria as ep', 'a.empresaPropietaria_RUC', '=', 'ep.RUC')
            ->leftJoin('modelo as m', 'a.modelo_idmodelo', '=', 'm.idmodelo')
            ->leftJoin('tipoelemento as te', 'a.tipoElemento_idtipoElemento', '=', 'te.idtipoElemento')
            ->leftJoin('tecnologia as tg', 'a.tecnologia_idtecnologia', '=', 'tg.idtecnologia')
            ->leftJoin('unidadmedida as um', 'a.unidadMedida_idunidadMedida', '=', 'um.idunidadMedida')
            ->leftJoin('plataforma as p', 'te.plataforma_idplataforma', '=', 'p.idplataforma')
            ->whereRaw("LOWER(TRIM(COALESCE(te.nombre, ''))) NOT LIKE '%plan%'")
            ->whereRaw("LOWER(TRIM(COALESCE(te.nombre, ''))) NOT LIKE '%servicio%'")
            ->select(
                'a.idalmacen',
                'a.imagen',
                'a.empresaPropietaria_RUC',
                'a.modelo_idmodelo',
                'a.tipoElemento_idtipoElemento',
                'a.tecnologia_idtecnologia',
                'a.unidadMedida_idunidadMedida',
                'a.usaRedMovil',
                'a.cantidadDisponible',
                'a.detalle',
                'a.precio',
                'a.renovacion',
                DB::raw("COALESCE(ep.razonSocial, 'Sin razón social') as empresa_label"),
                DB::raw("COALESCE(m.nombreModelo, 'Sin modelo') as modelo_label"),
                DB::raw("COALESCE(ma.nombreMarca, 'Sin marca') as marca_label"),
                DB::raw("CONCAT(COALESCE(te.nombre, 'Sin tipo'), IF(COALESCE(te.detalle, '') != '', CONCAT(' - ', te.detalle), ''), IF(COALESCE(p.nombrePlataforma, '') != '', CONCAT(' - ', p.nombrePlataforma), '')) as tipo_elemento_label"),
                DB::raw("COALESCE(tg.nombreTecnologia, 'Sin tecnología') as tecnologia_label"),
                DB::raw("COALESCE(um.nomenclatura, 'Sin unidad') as unidad_medida_label"),
                DB::raw('COALESCE(eac.cantidad, 0) as cantidad')
            );

        $query->leftJoin('marca as ma', 'm.marca_idmarca', '=', 'ma.idmarca');
        $query->leftJoinSub(
            DB::table('elementoalmacen as ea')
                ->where('ea.estado', 1)
                ->select('ea.dispositivo_iddispositivo', DB::raw('COUNT(*) as cantidad'))
                ->groupBy('ea.dispositivo_iddispositivo'),
            'eac',
            'eac.dispositivo_iddispositivo',
            '=',
            'a.idalmacen'
        );

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $query->where(function ($builder) use ($term) {
                $builder
                    ->where('a.idalmacen', 'like', $term)
                    ->orWhere('a.empresaPropietaria_RUC', 'like', $term)
                    ->orWhere('a.detalle', 'like', $term)
                    ->orWhere('ep.razonSocial', 'like', $term)
                    ->orWhere('m.nombreModelo', 'like', $term)
                    ->orWhere('ma.nombreMarca', 'like', $term)
                    ->orWhere('te.nombre', 'like', $term)
                    ->orWhere('te.detalle', 'like', $term)
                    ->orWhere('p.nombrePlataforma', 'like', $term)
                    ->orWhere('tg.nombreTecnologia', 'like', $term)
                    ->orWhere('um.detalle', 'like', $term)
                    ->orWhere('um.nomenclatura', 'like', $term)
                    ->orWhereRaw('CAST(a.cantidadDisponible AS CHAR) LIKE ?', [$term])
                    ->orWhereRaw('CAST(a.precio AS CHAR) LIKE ?', [$term]);
            });
        }

        $empresa = trim((string) $request->input('empresaPropietaria_RUC', ''));
        if ($empresa !== '') {
            $query->where('a.empresaPropietaria_RUC', (int) $empresa);
        }

        $modelo = trim((string) $request->input('modelo_idmodelo', ''));
        if ($modelo !== '') {
            $query->where('a.modelo_idmodelo', (int) $modelo);
        }

        $marca = trim((string) $request->input('marca_idmarca', ''));
        if ($marca !== '') {
            $query->where('m.marca_idmarca', (int) $marca);
        }

        $tipoElemento = trim((string) $request->input('tipoElemento_idtipoElemento', ''));
        if ($tipoElemento !== '') {
            $query->where('a.tipoElemento_idtipoElemento', (int) $tipoElemento);
        }

        $tecnologia = trim((string) $request->input('tecnologia_idtecnologia', ''));
        if ($tecnologia !== '') {
            $query->where('a.tecnologia_idtecnologia', (int) $tecnologia);
        }

        $unidadMedida = trim((string) $request->input('unidadMedida_idunidadMedida', ''));
        if ($unidadMedida !== '') {
            $query->where('a.unidadMedida_idunidadMedida', (int) $unidadMedida);
        }

        $cantidad = trim((string) $request->input('cantidad', ''));
        if ($cantidad !== '' && preg_match('/^(<=|>=|=|<|>)?\s*(\d+)$/', $cantidad, $matches)) {
            $operator = $matches[1] !== '' ? $matches[1] : '=';
            $amount = (int) $matches[2];
            $query->whereRaw("COALESCE(eac.cantidad, 0) {$operator} ?", [$amount]);
        }

        $precio = trim((string) $request->input('precio', ''));
        if ($precio !== '' && preg_match('/^(<=|>=|=|<|>)?\s*(\d+(?:\.\d+)?)$/', $precio, $matches)) {
            $operator = $matches[1] !== '' ? $matches[1] : '=';
            $amount = (float) $matches[2];
            $query->whereRaw("COALESCE(a.precio, 0) {$operator} ?", [$amount]);
        }

        $usaRedMovil = trim((string) $request->input('usaRedMovil', ''));
        if ($usaRedMovil !== '') {
            $query->where('a.usaRedMovil', $usaRedMovil);
        }

        return $query;
    }

    private function buildFields(?int $almacenId = null, ?Collection $detailListaPrecioItems = null): array
    {
        $detailListaPrecioItems = $detailListaPrecioItems ?? collect();

        return [
            [
                'name' => 'empresaPropietaria_RUC',
                'type' => 'select',
                'label' => 'Empresa propietaria',
                'required' => true,
                'tomSelect' => true,
                'optionsData' => $this->empresaOptions(),
                'optionKey' => 'value',
                'optionLabel' => 'label',
                'placeholder' => 'Selecciona empresa',
            ],
            [
                'name' => 'modelo_idmodelo',
                'type' => 'select',
                'label' => 'Modelo',
                'required' => true,
                'tomSelect' => true,
                'optionsData' => $this->modeloOptions(),
                'optionKey' => 'value',
                'optionLabel' => 'label',
                'placeholder' => 'Selecciona modelo',
            ],
            [
                'name' => 'tipoElemento_idtipoElemento',
                'type' => 'select',
                'label' => 'Tipo elemento',
                'required' => true,
                'tomSelect' => true,
                'optionsData' => $this->tipoElementoOptions(),
                'optionKey' => 'value',
                'optionLabel' => 'label',
                'placeholder' => 'Selecciona tipo',
            ],
            [
                'name' => 'tecnologia_idtecnologia',
                'type' => 'select',
                'label' => 'Tecnología',
                'required' => true,
                'tomSelect' => true,
                'optionsData' => $this->tecnologiaOptions(),
                'optionKey' => 'value',
                'optionLabel' => 'label',
                'placeholder' => 'Selecciona tecnología',
            ],
            [
                'name' => 'unidadMedida_idunidadMedida',
                'type' => 'select',
                'label' => 'Unidad de medida',
                'required' => true,
                'tomSelect' => true,
                'optionsData' => $this->unidadMedidaOptions(),
                'optionKey' => 'value',
                'optionLabel' => 'label',
                'placeholder' => 'Selecciona unidad',
            ],
            [
                'name' => 'usaRedMovil',
                'type' => 'select',
                'label' => 'Usa red móvil',
                'required' => true,
                'options' => [
                    ['value' => 'S', 'label' => 'Sí'],
                    ['value' => 'N', 'label' => 'No'],
                ],
                'placeholder' => 'Selecciona una opción',
            ],
            [
                'name' => 'precio',
                'type' => 'number',
                'label' => 'Precio',
                'required' => true,
                'step' => '0.01',
                'min' => '0',
                'helpText' => 'Precio del elemento en el almacén.',
                'quickCreateDetalleListaPrecio' => true,
                'quickCreateDetalleListaPrecioPayloadInput' => 'detalle_lista_precio_payload',
                'quickCreateDetalleListaPrecioOptions' => $this->listaprecioOptions(),
                'quickCreateDetalleListaPrecioAlmacenId' => $almacenId,
                'quickCreateDetalleListaPrecioAlmacenLabel' => $almacenId !== null
                    ? 'Almacén #' . $almacenId
                    : 'Se asignará automáticamente al guardar el almacén.',
                'quickCreateDetalleListaPrecioExisting' => $detailListaPrecioItems->map(function ($row): array {
                    return [
                        'id' => (string) data_get($row, 'iddetalleListaPrecio', ''),
                        'almacen_idalmacen' => (string) data_get($row, 'almacen_idalmacen', ''),
                        'ListaPrecio_idListaPrecio' => (string) data_get($row, 'ListaPrecio_idListaPrecio', ''),
                        'listaprecio_nombre' => (string) data_get($row, 'listaprecio_nombre', ''),
                        'precio' => (string) data_get($row, 'precio', ''),
                        'label' => trim((string) data_get($row, 'listaprecio_nombre', '') . ' - S/ ' . number_format((float) data_get($row, 'precio', 0), 2, ',', '.')),
                    ];
                })->all(),
            ],
            [
                'name' => 'detalle',
                'type' => 'textarea',
                'label' => 'Detalle',
                'required' => true,
                'maxlength' => 200,
                'placeholder' => 'Información adicional sobre el elemento en el almacén', 
            ],
            [
                'name' => 'imagen',
                'type' => 'file',
                'label' => 'Imagen',
                'required' => false,
                'placeholder' => '',
            ],
        ];
    }

    private function validateAlmacen(Request $request): array
    {
        return $request->validate([
            'empresaPropietaria_RUC' => ['required', 'integer', Rule::exists('empresapropietaria', 'RUC')],
            'modelo_idmodelo' => ['required', 'integer', Rule::exists('modelo', 'idmodelo')],
            'tipoElemento_idtipoElemento' => ['required', 'integer', Rule::exists('tipoelemento', 'idtipoElemento')],
            'tecnologia_idtecnologia' => ['required', 'integer', Rule::exists('tecnologia', 'idtecnologia')],
            'unidadMedida_idunidadMedida' => ['required', 'integer', Rule::exists('unidadmedida', 'idunidadMedida')],
            'usaRedMovil' => ['nullable', 'string', 'size:1', Rule::in(['S', 'N'])],
            'cantidadDisponible' => ['nullable', 'numeric', 'min:0'],
            'detalle' => ['nullable', 'string', 'max:200', 'regex:' . self::SAFE_TEXT_REGEX],
            'precio' => ['nullable', 'numeric', 'min:0'],
            'renovacion' => ['nullable', 'integer', 'min:0'],
            'imagen' => ['nullable', 'image', 'max:2048'],
        ], [
            'imagen' => 'La foto no debe ser menor a 2MB.',
        ]);
    }

    private function preparePayload(array $validated): array
    {
        return [
            'empresaPropietaria_RUC' => (int) $validated['empresaPropietaria_RUC'],
            'modelo_idmodelo' => (int) $validated['modelo_idmodelo'],
            'tipoElemento_idtipoElemento' => (int) $validated['tipoElemento_idtipoElemento'],
            'tecnologia_idtecnologia' => (int) $validated['tecnologia_idtecnologia'],
            'unidadMedida_idunidadMedida' => (int) $validated['unidadMedida_idunidadMedida'],
            'usaRedMovil' => $this->nullableString($validated['usaRedMovil'] ?? null),
            'cantidadDisponible' => null,
            'detalle' => $this->nullableString($validated['detalle'] ?? null),
            'precio' => $this->nullableNumber($validated['precio'] ?? null),
            'renovacion' => array_key_exists('renovacion', $validated) && $validated['renovacion'] !== null
                ? (int) $validated['renovacion']
                : 0,
            'imagen' => $this->nullableString($validated['imagen'] ?? null),
        ];
    }

    private function empresaOptions(): Collection
    {
        return DB::table('empresapropietaria as ep')
            ->orderBy('ep.razonSocial')
            ->orderBy('ep.RUC')
            ->get()
            ->map(function ($row): array {
                $razonSocial = trim((string) ($row->razonSocial ?? ''));
                return [
                    'value' => (string) $row->RUC,
                    'label' => trim((string) $row->RUC . ' - ' . ($razonSocial !== '' ? $razonSocial : 'Sin razón social')),
                ];
            });
    }

    private function modeloOptions(): Collection
    {
        return DB::table('modelo as m')
            ->orderBy('m.nombreModelo')
            ->orderBy('m.idmodelo')
            ->get()
            ->map(function ($row): array {
                $nombre = trim((string) ($row->nombreModelo ?? ''));
                return [
                    'value' => (string) $row->idmodelo,
                    'label' => trim((string) ($nombre !== '' ? $nombre : 'Sin nombre')),
                ];
            });
    }

    private function marcaOptions(): Collection
    {
        return DB::table('marca as ma')
            ->orderBy('ma.nombreMarca')
            ->orderBy('ma.idmarca')
            ->get()
            ->map(function ($row): array {
                $nombre = trim((string) ($row->nombreMarca ?? ''));
                return [
                    'value' => (string) $row->idmarca,
                    'label' => trim((string) ($nombre !== '' ? $nombre : 'Sin nombre')),
                ];
            });
    }

    private function tipoElementoOptions(): Collection
    {
        return DB::table('tipoelemento as te')
            ->join('plataforma as p', 'te.plataforma_idplataforma', '=', 'p.idplataforma')
            ->whereRaw("LOWER(TRIM(COALESCE(te.nombre, ''))) NOT LIKE '%plan%'")
            ->whereRaw("LOWER(TRIM(COALESCE(te.nombre, ''))) NOT LIKE '%servicio%'")
            ->select('te.idtipoElemento', 'te.nombre', 'te.detalle', 'p.nombrePlataforma')
            ->orderBy('te.nombre')
            ->orderBy('te.idtipoElemento')
            ->get()
            ->map(function ($row): array {
                $nombre = trim((string) ($row->nombre ?? ''));
                $detalle = trim((string) ($row->detalle ?? ''));
                $plataforma = trim((string) ($row->nombrePlataforma ?? ''));

                $labelBody = trim(
                    $nombre . 
                    ($detalle !== '' ? ' - ' . $detalle : '') . 
                    ($plataforma !== '' ? ' - ' . $plataforma . '' : '')
                );

                return [
                    'value' => (string) $row->idtipoElemento,
                    'label' => trim((string) ($labelBody !== '' ? $labelBody : 'Sin detalle')),
                ];
            });
    }

    private function tecnologiaOptions(): Collection
    {
        return DB::table('tecnologia as t')
            ->orderBy('t.nombreTecnologia')
            ->orderBy('t.idtecnologia')
            ->get()
            ->map(function ($row): array {
                $nombre = trim((string) ($row->nombreTecnologia ?? ''));
                return [
                    'value' => (string) $row->idtecnologia,
                    'label' => trim(($nombre !== '' ? $nombre : 'Sin nombre')),
                ];
            });
    }

    private function unidadMedidaOptions(): Collection
    {
        return DB::table('unidadmedida as um')
            ->orderBy('um.detalle')
            ->orderBy('um.idunidadMedida')
            ->get()
            ->map(function ($row): array {
                $detalle = trim((string) ($row->detalle ?? ''));
                $nomenclatura = trim((string) ($row->nomenclatura ?? ''));
                $labelBody = trim($nomenclatura . ' - ' . ($detalle !== '' ? '' . $detalle : ''));
                return [
                    'value' => (string) $row->idunidadMedida,
                    'label' => trim((string) ($labelBody !== '' ? $labelBody : 'Sin detalle')),
                ];
            });
    }

    private function listaprecioOptions(): Collection
    {
        return DB::table('listaprecio as lp')
            ->orderBy('lp.nombreLista')
            ->orderBy('lp.idListaPrecio')
            ->get()
            ->map(function ($row): array {
                $nombre = trim((string) ($row->nombreLista ?? ''));

                return [
                    'value' => (string) $row->idListaPrecio,
                    'label' => trim((string)($nombre !== '' ? $nombre : 'Sin nombre')),
                ];
            });
    }

    private function detailListaPrecioItems(int $almacenId): Collection
    {
        return DB::table('detallelistaprecio as d')
            ->leftJoin('listaprecio as lp', 'lp.idListaPrecio', '=', 'd.ListaPrecio_idListaPrecio')
            ->select([
                'd.iddetalleListaPrecio',
                'd.almacen_idalmacen',
                'd.ListaPrecio_idListaPrecio',
                'd.precio',
                DB::raw('COALESCE(lp.nombreLista, "") as listaprecio_nombre'),
            ])
            ->where('d.almacen_idalmacen', $almacenId)
            ->orderBy('d.iddetalleListaPrecio')
            ->get();
    }

    private function syncDetalleListaPrecioPayload(Request $request, int $almacenId): void
    {
        $rawPayload = $request->input('detalle_lista_precio_payload', '[]');
        $payload = is_string($rawPayload) ? json_decode($rawPayload, true) : $rawPayload;

        if (!is_array($payload) || $payload === []) {
            return;
        }

        $normalized = collect($payload)
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item): array {
                return [
                    'ListaPrecio_idListaPrecio' => (int) data_get($item, 'ListaPrecio_idListaPrecio', data_get($item, 'listaprecio_id', 0)),
                    'precio' => data_get($item, 'precio', null),
                ];
            })
            ->values()
            ->all();

        Validator::make(['items' => $normalized], [
            'items' => ['array'],
            'items.*.ListaPrecio_idListaPrecio' => ['required', 'integer', 'exists:listaprecio,idListaPrecio'],
            'items.*.precio' => ['required', 'numeric', 'min:0'],
        ])->validate();

        DB::transaction(function () use ($almacenId, $normalized): void {
            DB::table('detallelistaprecio')
                ->where('almacen_idalmacen', $almacenId)
                ->delete();

            foreach ($normalized as $item) {
                $newId = DB::table('detallelistaprecio')->insertGetId([
                    'almacen_idalmacen' => $almacenId,
                    'ListaPrecio_idListaPrecio' => (int) $item['ListaPrecio_idListaPrecio'],
                    'precio' => $item['precio'],
                ]);
                $this->publishResourceEvent('configuracion.detalle_lista_precio', (string) $newId, 'created');
            }
        });
    }

    private function nullableString(mixed $value): ?string
    {
        $stringValue = trim((string) ($value ?? ''));
        return $stringValue === '' ? null : $stringValue;
    }

    private function nullableNumber(mixed $value): ?string
    {
        $stringValue = trim((string) ($value ?? ''));
        return $stringValue === '' ? null : $stringValue;
    }

    private function formatDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format((float) $value, 2, ',', '.');
    }

    private function formatMoney(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return 'S/ ' . number_format((float) $value, 2, ',', '.');
    }

    private function formatYesNo(mixed $value): string
    {
        $normalized = mb_strtolower(trim((string) ($value ?? '')));

        return match ($normalized) {
            's', '1', 'si', 'sí', 'y', 'yes' => 'Sí',
            'n', '0', 'no' => 'No',
            default => '-',
        };
    }
}