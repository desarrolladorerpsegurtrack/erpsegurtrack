<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Export\ExportableList;
use App\Http\Controllers\Permission\HandlesResourceLock;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Illuminate\Support\Facades\Validator;

class PlanesServiciosController extends Controller
{
    use ExportableList;
    use HandlesResourceLock;

    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';

    private const LOCK_RESOURCE = 'ventas.planes_servicios';

    public function index(Request $request): View
    {
        $baseQuery = $this->baseQuery($request);
        $statsQuery = clone $baseQuery;

        $items = $baseQuery
            ->orderBy('a.idalmacen')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $items->through(function ($row) {
            $row->precio_label = $this->formatMoney($row->precio ?? null, $row->moneda_simbolo ?? null);
            $row->renovacion_label = $this->formatYesNo($row->renovacion ?? null);
            $row->cantidad_disponible_label = $this->formatDecimal($row->cantidadDisponible ?? null);
            $row->periodo_label = $this->formatPeriodo($row->periodo ?? null);
            return $row;
        });

        $stats = [
            'total' => (clone $statsQuery)->count('a.idalmacen'),
            'empresaPropietaria_RUC' => (clone $statsQuery)->distinct('a.empresaPropietaria_RUC')->count('a.empresaPropietaria_RUC'),
            'renovacion' => (clone $statsQuery)->where('a.renovacion', 1)->count('a.idalmacen'),
        ];

        return view('ventas.planes-servicios.planesservicio', [
            'title' => 'Planes y servicios',
            'singularTitle' => 'Plan o servicio',
            'items' => $items,
            'createRoute' => route('modules.ventas.planes-servicios.create'),
            'editRoute' => 'modules.ventas.planes-servicios.edit',
            'showRoute' => 'modules.ventas.planes-servicios.edit',
            'destroyRoute' => 'modules.ventas.planes-servicios.destroy',
            'bulkDestroyRoute' => route('modules.ventas.planes-servicios.bulk-destroy'),
            'identifierKey' => 'idalmacen',
            'lockResource' => self::LOCK_RESOURCE,
            'showActionsColumn' => true,
            'columns' => [
                ['key' => 'empresa_label', 'label' => 'Empresa', 'type' => 'text', 'wrap' => true],
                ['key' => 'detalle', 'label' => 'Detalle', 'type' => 'text', 'wrap' => true],
                ['key' => 'tipo_elemento_label', 'label' => 'Tipo elemento', 'type' => 'text', 'wrap' => true],
                ['key' => 'precio_label', 'label' => 'Precio', 'type' => 'text'],
                ['key' => 'periodo_label', 'label' => 'Periodo', 'type' => 'text', 'wrap' => true],
                ['key' => 'renovacion_label', 'label' => 'Renovación', 'type' => 'text', 'wrap' => true],
            ],
            'stats' => [
                ['label' => 'Total registros', 'value' => $stats['total']],
                ['label' => 'Empresas', 'value' => $stats['empresaPropietaria_RUC']],
                ['label' => 'Total de renovaciones', 'value' => $stats['renovacion'] ?? 0],
            ],
            'filters' => [
                [
                    'name' => 'empresa_search',
                    'label' => 'Empresa',
                    'type' => 'text',
                    'placeholder' => 'Buscar por RUC o Razón Social...',
                ],
                [
                    'name' => 'tipo_elemento_search',
                    'label' => 'Tipo elemento',
                    'type' => 'text',
                    'placeholder' => 'Buscar por tipo...',
                ],
                [
                    'name' => 'precio_search',
                    'label' => 'Precio',
                    'type' => 'text',
                    'placeholder' => 'Ej: 50.00',
                ],
                [
                    'name' => 'detalle_search',
                    'label' => 'Detalle',
                    'type' => 'text',
                    'placeholder' => 'Buscar por detalle',
                ],
                [
                    'name' => 'periodo_search',
                    'label' => 'Periodo',
                    'type' => 'text',
                    'placeholder' => 'Ej: Mensual, 3 Meses, 6 Meses',
                ],
                [
                    'name' => 'renovacion',
                    'label' => 'Renovación',
                    'type' => 'select',
                    'options' => [
                        ['value' => '0', 'label' => 'No'],
                        ['value' => '1', 'label' => 'Sí'],
                    ],
                    'placeholder' => 'Todos',
                ],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.ventas.planes-servicios.export', ['format' => 'pdf']),
                'xlsx' => route('modules.ventas.planes-servicios.export', ['format' => 'xlsx']),
            ],
            'tableWrapperClass' => 'planes-servicios-table',
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

        $rows = $this->baseQuery($request)->orderByDesc('a.idalmacen')->get();

        $rows->transform(function ($row) {
            $row->renovacion = $this->formatYesNo($row->renovacion ?? null);
            return $row;
        });

        $columns = [
            ['key' => 'idalmacen', 'label' => 'ID'],
            ['key' => 'empresa_label', 'label' => 'Empresa'],
            ['key' => 'detalle', 'label' => 'Detalle'],
            ['key' => 'tipo_elemento_label', 'label' => 'Tipo elemento'],
            ['key' => 'precio', 'label' => 'Precio'],
            ['key' => 'renovacion', 'label' => 'Renovación'],
            ['key' => 'periodo', 'label' => 'Periodo'],
        ];

        $filename = 'planes_servicios_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $this->baseQuery($request)->whereIn('a.idalmacen', array_values($selectedIds))->orderBy('a.idalmacen')->get();

            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }

            return $this->exportPdfResponse($rows, $columns, 'Listado de Planes y Servicios', $filename);
        }

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Planes y servicios', $filename);
    }

    public function create(): View
    {
        return view('ventas.planes-servicios.planesservicio-form', [
            'title' => 'Nuevo plan o servicio',
            'moduleTitle' => 'Planes y servicios',
            'mode' => 'create',
            'formAction' => route('modules.ventas.planes-servicios.store'),
            'backRoute' => route('modules.ventas.planes-servicios.index'),
            'record' => null,
            'fields' => $this->buildFields(null),
            'readOnly' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePlanesServicios($request);
        $payload = $this->preparePayload($validated);

        $newId = null;
        DB::transaction(function () use ($payload, &$newId, $request): void {
            $newId = DB::table('almacen')->insertGetId($payload);
            $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $newId, 'created');
            $this->syncDetalleListaPrecioPayload($request, (int) $newId);
        });

        return redirect()
            ->route('modules.ventas.planes-servicios.index')
            ->with('success', 'Registro de plan o servicio creado correctamente.');
    }

    public function edit(int $id): View|RedirectResponse
    {
        $record = DB::table('almacen')->where('idalmacen', $id)->first();

        if (!$record) {
            return redirect()
                ->route('modules.ventas.planes-servicios.index')
                ->with('error', 'No se encontro el registro solicitado.');
        }

        return view('ventas.planes-servicios.planesservicio-form', [
            'title' => 'Editar plan o servicio',
            'moduleTitle' => 'Planes y servicios',
            'mode' => 'edit',
            'formAction' => route('modules.ventas.planes-servicios.update', $id),
            'backRoute' => route('modules.ventas.planes-servicios.index'),
            'record' => $record,
            'fields' => $this->buildFields($id),
            'readOnly' => true,
        ] + $this->prepareLockViewData(self::LOCK_RESOURCE, (string) $id));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $exists = DB::table('almacen')->where('idalmacen', $id)->exists();

        if (!$exists) {
            return redirect()
                ->route('modules.ventas.planes-servicios.index')
                ->with('error', 'No se encontro el registro solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, (string) $id, 'registro de plan o servicio', 'modules.ventas.planes-servicios.index')) {
            return $redirect;
        }

        $validated = $this->validatePlanesServicios($request);
        $payload = $this->preparePayload($validated);

        DB::transaction(function () use ($payload, $request, $id): void {
            DB::table('almacen')->where('idalmacen', $id)->update($payload);
            $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $id, 'updated');
            $this->syncDetalleListaPrecioPayload($request, $id);
            $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, (string) $id);
        });

        return redirect()
            ->route('modules.ventas.planes-servicios.index')
            ->with('success', 'Registro de plan o servicio actualizado correctamente.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, (string) $id, 'registro de plan o servicio', 'modules.ventas.planes-servicios.index')) {
            return $redirect;
        }

        try {
            DB::table('almacen')->where('idalmacen', $id)->delete();
            $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, (string) $id);

            return redirect()
                ->route('modules.ventas.planes-servicios.index')
                ->with('success', 'Registro eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.ventas.planes-servicios.index')
                ->with('error', 'No se puede eliminar el registro porque tiene relaciones asociadas.');
        }
    }

    private function baseQuery(Request $request)
    {
        $query = DB::table('almacen as a')
            ->leftJoin('empresapropietaria as ep', 'a.empresaPropietaria_RUC', '=', 'ep.RUC')
            ->leftJoin('modelo as m', 'a.modelo_idmodelo', '=', 'm.idmodelo')
            ->leftJoin('marca as ma', 'm.marca_idmarca', '=', 'ma.idmarca')
            ->leftJoin('tipoelemento as te', 'a.tipoElemento_idtipoElemento', '=', 'te.idtipoElemento')
            ->leftJoin('plataforma as p', 'te.plataforma_idplataforma', '=', 'p.idplataforma')
            ->leftJoin('tecnologia as tg', 'a.tecnologia_idtecnologia', '=', 'tg.idtecnologia')
            ->leftJoin('unidadmedida as um', 'a.unidadMedida_idunidadMedida', '=', 'um.idunidadMedida')
            ->leftJoin('moneda as mn', 'a.moneda_idmoneda', '=', 'mn.idmoneda')
            ->where(function ($builder) {
                $builder
                    ->whereRaw("LOWER(TRIM(COALESCE(te.nombre, ''))) LIKE '%plan%'")
                    ->orWhereRaw("LOWER(TRIM(COALESCE(te.nombre, ''))) LIKE '%servicio%'");
            })
            ->select(
                'a.idalmacen',
                'a.empresaPropietaria_RUC',
                'a.moneda_idmoneda',
                'a.modelo_idmodelo',
                'a.tipoElemento_idtipoElemento',
                'a.tecnologia_idtecnologia',
                'a.unidadMedida_idunidadMedida',
                'a.usaRedMovil',
                'a.cantidadDisponible',
                'a.detalle',
                'a.precio',
                'a.renovacion',
                'a.periodo',
                DB::raw("CONCAT(COALESCE(ep.razonSocial, 'Sin razón social')) as empresa_label"),
                DB::raw("COALESCE(m.nombreModelo, 'Sin modelo') as modelo_label"),
                DB::raw("COALESCE(ma.nombreMarca, 'Sin marca') as marca_label"),
                DB::raw("COALESCE(NULLIF(CONCAT_WS('-', te.nombre, te.detalle, p.nombrePlataforma), ''), 'Sin tipo') as tipo_elemento_label"),
                DB::raw("COALESCE(tg.nombreTecnologia, 'Sin tecnología') as tecnologia_label"),
                DB::raw("COALESCE(um.nomenclatura, 'Sin unidad') as unidad_medida_label"),
                DB::raw('COALESCE(mn.simbolo, "") as moneda_simbolo')
            );

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $query->where(function ($builder) use ($term) {
                $builder
                    ->where('a.idalmacen', 'like', $term)
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
                    ->orWhere('a.periodo', 'like', $term);
            });
        }

        $empresaSearch = trim((string) $request->input('empresa_search', ''));
        if ($empresaSearch !== '') {
            $term = '%' . $empresaSearch . '%';
            $query->where(function ($builder) use ($term) {
                $builder->where('a.empresaPropietaria_RUC', 'like', $term)
                        ->orWhere('ep.razonSocial', 'like', $term);
            });
        }

        $tipoSearch = trim((string) $request->input('tipo_elemento_search', ''));
        if ($tipoSearch !== '') {
            $term = '%' . $tipoSearch . '%';
            $query->where(function ($builder) use ($term) {
                $builder->where('te.nombre', 'like', $term)
                        ->orWhere('te.detalle', 'like', $term)
                        ->orWhere('p.nombrePlataforma', 'like', $term);
            });
        }

        $detalleSearch = trim((string) $request->input('detalle_search', ''));
        if ($detalleSearch !== '') {
            $query->where('a.detalle', 'like', '%' . $detalleSearch . '%');
        }

        $periodoSearch = trim((string) $request->input('periodo_search', ''));
        if ($periodoSearch !== '') {
            $query->where('a.periodo', 'like', '%' . $periodoSearch . '%');
        }

        $precioSearch = trim((string) $request->input('precio_search', ''));
        if ($precioSearch !== '') {
            $query->where('a.precio', 'like', '%' . $precioSearch . '%');
        }

        $renovacion = $request->input('renovacion');
        if ($renovacion !== null && $renovacion !== '') {
            $query->where('a.renovacion', (int) $renovacion);
        }

        return $query;
    }

    private function buildFields(?int $almacenId = null): array
    {
        $periodoValue = '';
        if ($almacenId !== null) {
            $record = DB::table('almacen')->where('idalmacen', $almacenId)->first();
            if ($record && $record->periodo !== null) {
                $periodoValue = $this->convertDaysToFormattedPeriodo($record->periodo);
            }
        }

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
                'name' => 'tipoElemento_idtipoElemento',
                'type' => 'select',
                'label' => 'Tipo elemento',
                'required' => true,
                'tomSelect' => true,
                'optionsData' => $this->tipoElementoOptions(),
                'optionKey' => 'value',
                'optionLabel' => 'label',
                'placeholder' => 'Selecciona un plan o servicio',
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
                    : 'Se asignará automáticamente al guardar el registro.',
                'quickCreateDetalleListaPrecioExisting' => $almacenId !== null ? $this->detailListaPrecioItems($almacenId)->map(function ($row): array {
                    return [
                        'id' => (string) data_get($row, 'iddetalleListaPrecio', ''),
                        'almacen_idalmacen' => (string) data_get($row, 'almacen_idalmacen', ''),
                        'ListaPrecio_idListaPrecio' => (string) data_get($row, 'ListaPrecio_idListaPrecio', ''),
                        'listaprecio_nombre' => (string) data_get($row, 'listaprecio_nombre', ''),
                        'precio' => (string) data_get($row, 'precio', ''),
                        'label' => trim((string) data_get($row, 'listaprecio_nombre', '') . ' - S/ ' . number_format((float) data_get($row, 'precio', 0), 2, ',', '.')),
                    ];
                })->all() : [],
            ],
            [
                'name' => 'moneda_idmoneda',
                'type' => 'select',
                'label' => 'Moneda',
                'required' => false,
                'tomSelect' => true,
                'optionsData' => $this->monedaOptions(),
                'optionKey' => 'idmoneda',
                'optionLabel' => 'moneda_label',
                'value' => $almacenId === null ? $this->defaultMonedaId() : null,
                'placeholder' => 'Selecciona moneda',
            ],
            [
                'name' => 'renovacion',
                'type' => 'select',
                'label' => 'Renovación',
                'required' => true,
                'options' => [
                    ['value' => '0', 'label' => 'No'],
                    ['value' => '1', 'label' => 'Sí'],
                ],
                'placeholder' => 'Selecciona estado',
            ],
            [
                'name' => 'periodo',
                'type' => 'text',
                'label' => 'Periodo',
                'required' => false,
                'maxlength' => 50,
                'placeholder' => 'Ej: Mensual, 3 Meses, 6 Meses',
                'value' => $periodoValue,
                'datalistOptions' => ['No','Mensual', '3 Meses', '6 Meses', '12 Meses', '24 Meses', '36 Meses', '48 Meses'],
            ],
            [
                'name' => 'detalle',
                'type' => 'textarea',
                'label' => 'Detalle',
                'required' => true,
                'maxlength' => 200,
                'placeholder' => 'Información sobre el plan o servicio',
            ],
        ];
    }

    private function validatePlanesServicios(Request $request): array
    {
        $allowedTipoElementoIds = $this->tipoElementoOptions()
            ->pluck('value')
            ->map(fn ($value) => (int) $value)
            ->all();

        $validated = $request->validate([
            'empresaPropietaria_RUC' => ['required', 'integer', Rule::exists('empresapropietaria', 'RUC')],
            'tipoElemento_idtipoElemento' => ['required', 'integer', Rule::in($allowedTipoElementoIds)],
            'detalle' => ['nullable', 'string', 'max:200', 'regex:' . self::SAFE_TEXT_REGEX],
            'periodo' => ['nullable', 'string', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'precio' => ['nullable', 'numeric', 'min:0'],
            'moneda_idmoneda' => ['nullable', 'integer', Rule::exists('moneda', 'idmoneda')],
            'renovacion' => ['nullable', 'integer', Rule::in([0, 1])],
        ]);

        return $validated;
    }

    private function preparePayload(array $validated): array
    {
        return [
            'empresaPropietaria_RUC' => (int) $validated['empresaPropietaria_RUC'],
            'modelo_idmodelo' => null,
            'tipoElemento_idtipoElemento' => (int) $validated['tipoElemento_idtipoElemento'],
            'tecnologia_idtecnologia' => null,
            'unidadMedida_idunidadMedida' => null,
            'moneda_idmoneda' => !empty($validated['moneda_idmoneda']) ? (int) $validated['moneda_idmoneda'] : null,
            'usaRedMovil' => null,
            'cantidadDisponible' => null,
            'detalle' => $this->nullableString($validated['detalle'] ?? null),
            'periodo' => $this->convertPeriodoToDays($validated['periodo'] ?? null),
            'precio' => $this->nullableNumber($validated['precio'] ?? null),
            'renovacion' => array_key_exists('renovacion', $validated) && $validated['renovacion'] !== null
                ? (int) $validated['renovacion']
                : 0,
        ];
    }

    private function defaultMonedaId(): int
    {
        $monedaId = DB::table('moneda')
            ->whereRaw('LOWER(TRIM(COALESCE(detalle, ""))) LIKE ?', ['%sol%'])
            ->value('idmoneda');

        return is_numeric($monedaId) ? (int) $monedaId : 2;
    }

    private function monedaOptions(): Collection
    {
        return DB::table('moneda')
            ->select(['idmoneda', DB::raw('CONCAT(detalle) as moneda_label')])
            ->where('idmoneda', '!=', 4)
            ->orderBy('detalle')
            ->get()
            ->map(function ($row): array {
                return [
                    'idmoneda' => (string) $row->idmoneda,
                    'moneda_label' => trim((string) $row->moneda_label),
                ];
            });
    }

    private function empresaOptions(): Collection
    {
        return DB::table('empresapropietaria as ep')
            ->orderBy('ep.razonSocial')
            ->orderBy('ep.RUC')
            ->get()
            ->map(fn ($row): array => [
                'value' => (string) $row->RUC,
                'label' => trim((string) $row->RUC . ' - ' . trim((string) ($row->razonSocial ?? 'Sin razón social'))),
            ]);
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

    private function tipoElementoOptions(): Collection
    {
        return DB::table('tipoelemento as te')
            ->leftJoin('plataforma as p', 'te.plataforma_idplataforma', '=', 'p.idplataforma')
            ->where(function ($query) {
                $query
                    ->whereRaw("LOWER(TRIM(COALESCE(te.nombre, ''))) LIKE '%plan%'")
                    ->orWhereRaw("LOWER(TRIM(COALESCE(te.nombre, ''))) LIKE '%servicio%'");
            })
            ->orderBy('te.nombre')
            ->orderBy('te.idtipoElemento')
            ->orderBy('te.detalle')
            ->orderBy('p.nombrePlataforma')
            ->get()
            ->map(function ($row): array {
                $nombre = trim((string) ($row->nombre ?? ''));
                $detalle = trim((string) ($row->detalle ?? ''));
                $plataforma = trim((string) ($row->nombrePlataforma ?? ''));

                $labelParts = array_filter([
                    $nombre,
                    $detalle,
                    $plataforma,
                ], fn ($part) => $part !== '');

                return [
                    'value' => (string) $row->idtipoElemento,
                    'label' => $labelParts !== [] ? implode('-', $labelParts) : (string) $row->idtipoElemento,
                ];
            });
    }

    private function formatYesNo(mixed $value): string
    {
        return match ((int) ($value ?? 0)) {
            1 => 'Sí',
            0 => 'No',
            default => '-',
        };
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

    private function formatMoney(mixed $value, ?string $currencySymbol = null): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        $formattedValue = number_format((float) $value, 2, ',', '.');
        $symbol = trim((string) ($currencySymbol ?? ''));

        if ($symbol === '') {
            return $formattedValue;
        }

        $normalizedSymbol = $this->normalizeCurrencySymbol($symbol);

        return $normalizedSymbol . ' ' . $formattedValue;
    }

    private function normalizeCurrencySymbol(?string $currency): string
    {
        $symbol = trim((string) ($currency ?? ''));
        if ($symbol === '') {
            return '';
        }

        $lower = mb_strtolower($symbol, 'UTF-8');

        if ($lower === 's/' || $lower === 's' || str_contains($lower, 'sol')) {
            return 'S/';
        }

        if (str_contains($lower, 'dolar') || str_contains($lower, 'dólar') || str_contains($lower, '$')) {
            return '$';
        }

        if (str_contains($lower, 'euro') || str_contains($lower, '€')) {
            return '€';
        }

        return $symbol;
    }

    private function formatPeriodo(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        $periodo = trim((string) $value);
        if (is_numeric($periodo)) {
            $formatted = $this->convertDaysToFormattedPeriodo($periodo);

            return $formatted !== '' ? $formatted : $periodo ;
        }

        return $periodo;
    }

    private function convertPeriodoToDays(mixed $value): ?string
    {
        $periodo = trim((string) ($value ?? ''));
        
        if ($periodo === '' || $periodo === 'No') {
            return null;
        }

        // Si ya es un número, retornar como está
        if (is_numeric($periodo)) {
            return (string) $periodo;
        }

        // Mapeo de texto a días
        $mapping = [
            'Mensual' => 30,
            '3 Meses' => 90,
            '6 Meses' => 180,
            '12 Meses' => 365,
            '24 Meses' => 730,
            '36 Meses' => 1095,
            '48 Meses' => 1460,
        ];

        return $mapping[$periodo] !== null ? (string) $mapping[$periodo] : $periodo;
    }

    private function convertDaysToFormattedPeriodo(mixed $value): string
    {
        $days = (int) ($value ?? 0);

        if ($days === 0) {
            return '';
        }

        // Mapeo inverso de días a texto para mostrar en formulario
        $mapping = [
            30 => 'Mensual',
            90 => '3 Meses',
            180 => '6 Meses',
            365 => '12 Meses',
            730 => '24 Meses',
            1095 => '36 Meses',
            1460 => '48 Meses',
        ];

        return $mapping[$days] ?? '';
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
}
