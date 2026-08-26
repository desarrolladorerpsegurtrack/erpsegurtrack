<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Export\ExportableList;
use App\Http\Controllers\Permission\HandlesResourceLock;
use App\Support\ResourceLock;
use App\Support\VehiculoData;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class VehiculosController extends Controller
{
    use ExportableList;
    use HandlesResourceLock;

    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';

    private const LOCK_RESOURCE = 'vehiculos';

    public function index(Request $request): View
    {
        $query = $this->applyIndexFilters($request, $this->baseQuery());

        $items = $query->orderBy('c.nombreComercial')
            ->paginate($this->resolvePerPage($request, 25))
            ->withQueryString();

        $items = $this->attachVehiculoRelationGroups($items);

        return view('vehiculo.vehiculos', $this->buildIndexViewData($items, $query));
    }

    private function applyIndexFilters(Request $request, $query)
    {
        $placaFilter = trim((string) $request->input('placa', ''));
        if ($placaFilter !== '') {
            $query->where('v.placa', 'like', '%' . $placaFilter . '%');
        }

        $clienteFilter = trim((string) $request->input('cliente', ''));
        if ($clienteFilter !== '') {
            $query->where(function ($builder) use ($clienteFilter) {
                $term = '%' . $clienteFilter . '%';
                $builder
                    ->where('c.nombreComercial', 'like', $term)
                    ->orWhere('c.razonSocial', 'like', $term)
                    ->orWhere('v.cliente_idcliente', 'like', $term);
            });
        }

        $tipoFilter = trim((string) $request->input('tipo', ''));
        if ($tipoFilter !== '') {
            $query->where('tv.nombre', 'like', '%' . $tipoFilter . '%');
        }

        $anioFilter = trim((string) $request->input('anio', ''));
        if ($anioFilter !== '') {
            $query->where('v.anio', 'like', '%' . $anioFilter . '%');
        }

        $marcaFilter = trim((string) $request->input('marca', ''));
        if ($marcaFilter !== '') {
            $query->where('v.marca', 'like', '%' . $marcaFilter . '%');
        }

        $tractoFilter = trim((string) $request->input('tracto', ''));
        if ($tractoFilter !== '') {
            $query->where('v.tracto', 'like', '%' . $tractoFilter . '%');
        }

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $query->where(function ($builder) use ($term) {
                $builder
                    ->where('v.placa', 'like', $term)
                    ->orWhere('v.cliente_idcliente', 'like', $term)
                    ->orWhere('v.marca', 'like', $term)
                    ->orWhere('v.modelo', 'like', $term)
                    ->orWhere('v.color', 'like', $term)
                    ->orWhere('v.tracto', 'like', $term)
                    ->orWhere('c.nombreComercial', 'like', $term)
                    ->orWhere('c.razonSocial', 'like', $term)
                    ->orWhere('tv.nombre', 'like', $term);
            });
        }

        return $query;
    }

    private function attachVehiculoRelationGroups($items)
    {
        $placas = collect($items->items())->pluck('placa')->filter()->unique()->values()->all();
        if (empty($placas)) {
            return $items;
        }

        $deviceGroups = $this->loadVehiculoDeviceGroups($placas);
        $serviceGroups = $this->loadVehiculoServiceGroups($placas);
        if (empty($deviceGroups) && empty($serviceGroups)) {
            return $items;
        }

        $newCollection = $items->getCollection()->map(function ($row) use ($deviceGroups, $serviceGroups) {
            return $this->attachRelationGroupsToVehiculoRow($row, $deviceGroups, $serviceGroups);
        });

        $items->setCollection($newCollection);

        return $items;
    }

    private function loadVehiculoDeviceGroups(array $placas): array
    {
        $dispositivosRows = DB::table('dispositivocliente')
            ->whereIn('vehiculo_placa', $placas)
            ->select('iddispositivoCliente', 'marcaDispositivo', 'modeloDispositivo', 'fechaInstalacion', 'fechaBaja', 'estado', 'vehiculo_placa')
            ->get();

        $deviceIds = $dispositivosRows->pluck('iddispositivoCliente')->filter()->unique()->values()->all();
        $numbersMap = [];

        if (!empty($deviceIds)) {
            $numbersMap = DB::table('detnumerosdispositivo as n')
                ->whereIn('n.dispositivoCliente_iddispositivoCliente', $deviceIds)
                ->select(['n.dispositivoCliente_iddispositivoCliente', 'n.numeroTelefonico_numeroTelefonico'])
                ->addSelect([
                    'operador' => DB::table('detallesimcard as ds')
                        ->leftJoin('simcard as s', 's.idsimCard', '=', 'ds.simCard_idsimCard')
                        ->leftJoin('operador as o', 'o.idoperador', '=', 's.operador_idoperador')
                        ->select('o.nombre')
                        ->whereColumn('ds.numeroTelefonico_numeroTelefonico', 'n.numeroTelefonico_numeroTelefonico')
                        ->where('ds.estado', '0')
                        ->orderByDesc('ds.iddetalleSimCard')
                        ->limit(1),
                ])
                ->orderByDesc('n.fechaAsignacion')
                ->orderByDesc('n.iddetNumerosDispositivo')
                ->get()
                ->groupBy('dispositivoCliente_iddispositivoCliente')
                ->map(function ($group) {
                    $first = $group->first();
                    return [
                        'numero' => $first->numeroTelefonico_numeroTelefonico ?? '-',
                        'operador' => $first->operador ?? '-',
                    ];
                })->all();
        }

        return $dispositivosRows->map(function ($d) use ($numbersMap) {
            $arr = (array) $d;
            $numberData = $numbersMap[$arr['iddispositivoCliente']] ?? [];
            $arr['numero'] = $numberData['numero'] ?? '-';
            $arr['operador'] = $numberData['operador'] ?? '-';
            return $arr;
        })->groupBy('vehiculo_placa')->map(function ($group) {
            return $group->map(function ($d) {
                return (array) $d;
            })->all();
        })->all();
    }

    private function loadVehiculoServiceGroups(array $placas): array
    {
        return DB::table('serviciocliente as sc')
            ->leftJoin('almacen as a', 'a.idalmacen', '=', 'sc.almacen_idalmacen')
            ->leftJoin('tipoelemento as te', 'te.idtipoElemento', '=', 'a.tipoElemento_idtipoElemento')
            ->leftJoin('plataforma as p', 'p.idplataforma', '=', 'te.plataforma_idplataforma')
            ->leftJoin('moneda as m', 'm.idmoneda', '=', 'sc.moneda_idmoneda')
            ->select([
                'sc.idservicioCliente',
                'sc.vehiculo_placa',
                'sc.fechaInicio',
                'sc.fecheVencimiento',
                'sc.monto',
                'sc.estado',
                'sc.docReferencia',
                DB::raw('COALESCE(a.detalle, "") as almacen_detalle'),
                DB::raw('COALESCE(p.nombrePlataforma, "") as plataforma'),
                'a.periodo as almacen_periodo',
                DB::raw('COALESCE(m.simbolo, "") as moneda_simbolo'),
            ])
            ->whereIn('sc.vehiculo_placa', $placas)
            ->orderByDesc('sc.idservicioCliente')
            ->get()
            ->map(function ($service) {
                $periodo = $this->formatPeriodo($service->almacen_periodo ?? null);
                $detalle = trim((string) ($service->almacen_detalle ?? ''));

                $service->almacen_detalle = $periodo !== ''
                    ? trim($detalle . ' - ' . $periodo)
                    : $detalle;

                $monto = $service->monto;
                $service->monto = ($monto !== null && $monto !== '')
                    ? $this->normalizeCurrencySymbol($service->moneda_simbolo ?? null) . ' ' . number_format((float) $monto, 2, '.', '')
                    : '-';

                return (array) $service;
            })
            ->groupBy('vehiculo_placa')
            ->map(fn ($group) => $group->values()->all())
            ->all();
    }

    private function formatPeriodo(mixed $value): string
    {
        $periodo = trim((string) ($value ?? ''));
        if ($periodo === '' || strcasecmp($periodo, 'no') === 0) {
            return '';
        }

        if (!is_numeric($value)) {
            return $periodo;
        }

        return match ((int) $value) {
            30 => 'Mensual',
            90 => '3 Meses',
            180 => '6 Meses',
            365 => '12 Meses',
            730 => '24 Meses',
            1095 => '36 Meses',
            1460 => '48 Meses',
            default => $periodo,
        };
    }

    private function normalizeCurrencySymbol(?string $currency): string
    {
        $symbol = trim((string) ($currency ?? ''));
        if ($symbol === '') {
            return 'S/';
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

    private function attachRelationGroupsToVehiculoRow($row, array $deviceGroups, array $serviceGroups)
    {
        $placa = data_get($row, 'placa');
        $devices = $deviceGroups[$placa] ?? [];
        $services = $serviceGroups[$placa] ?? [];

        $relationGroups = [
            [
                'key' => 'dispositivo_cliente',
                'label' => 'Dispositivos cliente',
                'columns' => [
                    ['key' => 'iddispositivoCliente', 'label' => 'ID Dispositivo', 'type' => 'text'],
                    ['key' => 'numero', 'label' => 'Número', 'type' => 'text'],
                    ['key' => 'operador', 'label' => 'Operador', 'type' => 'text'],
                    ['key' => 'marcaDispositivo', 'label' => 'Marca', 'type' => 'text'],
                    ['key' => 'modeloDispositivo', 'label' => 'Modelo', 'type' => 'text'],
                    ['key' => 'fechaInstalacion', 'label' => 'Fecha de instalación', 'type' => 'date'],
                    ['key' => 'fechaBaja', 'label' => 'Fecha de baja', 'type' => 'date'],
                    ['key' => 'estado', 'label' => 'Estado', 'type' => 'status'],
                ],
                'records' => $devices,
            ],
            [
                'key' => 'servicio_cliente',
                'label' => 'Servicios Cliente',
                'columns' => [
                    ['key' => 'idservicioCliente', 'label' => 'ID', 'type' => 'text'],
                    ['key' => 'vehiculo_placa', 'label' => 'Vehículo', 'type' => 'text'],
                    ['key' => 'almacen_detalle', 'label' => 'Servicio', 'type' => 'text'],
                    ['key' => 'plataforma', 'label' => 'Plataforma', 'type' => 'text'],
                    ['key' => 'fechaInicio', 'label' => 'Fecha Inicio', 'type' => 'date'],
                    ['key' => 'fecheVencimiento', 'label' => 'Fecha Fin', 'type' => 'date'],
                    ['key' => 'monto', 'label' => 'Monto', 'type' => 'text'],
                    ['key' => 'estado', 'label' => 'Estado', 'type' => 'status'],
                    ['key' => 'docReferencia', 'label' => 'Documento', 'type' => 'text'],
                ],
                'records' => $services,
            ],
        ];

        $rowArr = (array) $row;
        $rowArr['numero'] = '-';
        $rowArr['operador'] = '-';
        if (!empty($devices) && is_array($devices) && isset($devices[0]['numero'])) {
            $rowArr['numero'] = $devices[0]['numero'] ?? '-';
            $rowArr['operador'] = $devices[0]['operador'] ?? '-';
        }
        $rowArr['relation_groups'] = $relationGroups;

        return (object) $rowArr;
    }

    private function buildIndexViewData($items, $query): array
    {
        return [
            'title' => 'Módulo Vehículos',
            'singularTitle' => 'Vehículo',
            'tableWrapperClass' => 'vehiculos-table',
            'statsWrapperClass' => 'vehiculos-stats',
            'perPageOptions' => [25, 50, 100],
            'defaultPerPage' => 25,
            'items' => $items,
            'columns' => [
                ['key' => 'placa', 'label' => 'Placa', 'type' => 'text'],
                ['key' => 'numero', 'label' => 'Número', 'type' => 'text'],
                ['key' => 'operador', 'label' => 'Operador', 'type' => 'text'],
                ['key' => 'cliente_nombre', 'label' => 'Cliente', 'type' => 'text'],
                ['key' => 'tipo_vehiculo', 'label' => 'Tipo', 'type' => 'text'],
                ['key' => 'anio', 'label' => 'Año', 'type' => 'text'],
                ['key' => 'marca', 'label' => 'Marca', 'type' => 'text'],
                ['key' => 'modelo', 'label' => 'Modelo', 'type' => 'text'],
                ['key' => 'tracto', 'label' => 'Tracto', 'type' => 'text'],
            ],
            'stats' => [
                ['label' => 'Total de vehículos', 'value' => (clone $query)->count()],
                ['label' => 'Total de clientes', 'value' => (clone $query)->distinct('v.cliente_idcliente')->count('v.cliente_idcliente')],
                ['label' => 'Total de tipos de vehículo', 'value' => (clone $query)->distinct('v.tipoUnidad_idtable1')->count('v.tipoUnidad_idtable1')],
            ],
            'filters' => [
                [
                    'name' => 'placa',
                    'label' => 'Placa',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por placa',
                ],
                [
                    'name' => 'cliente',
                    'label' => 'Cliente',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por cliente',
                ],
                [
                    'name' => 'tipo',
                    'label' => 'Tipo',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por tipo',
                ],
                [
                    'name' => 'anio',
                    'label' => 'Año',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por año',
                ],
                [
                    'name' => 'marca',
                    'label' => 'Marca',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por marca',
                ],
                [
                    'name' => 'tracto',
                    'label' => 'Tracto',
                    'type' => 'select',
                    'options' => [
                        ['value' => 'Si', 'label' => 'Sí'],
                        ['value' => 'No', 'label' => 'No'],
                    ],
                    'placeholder' => 'Todos',
                ],
            ],
            'createRoute' => route('modules.vehiculos.create'),
            'editRoute' => 'modules.vehiculos.edit',
            'showRoute' => 'modules.vehiculos.edit',
            'destroyRoute' => 'modules.vehiculos.destroy',
            'lockResource' => self::LOCK_RESOURCE,
            'relationPanelView' => 'cliente.relation-panel',
            'exportRoutes' => [
                'pdf' => route('modules.vehiculos.export', ['format' => 'pdf']),
                'xlsx' => route('modules.vehiculos.export', ['format' => 'xlsx']),
            ],
            'identifierKey' => 'placa',
        ];
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
            ['key' => 'placa', 'label' => 'Placa'],
            ['key' => 'cliente_nombre', 'label' => 'Cliente'],
            ['key' => 'tipo_vehiculo', 'label' => 'Tipo'],
            ['key' => 'anio', 'label' => 'Año'],
            ['key' => 'marca', 'label' => 'Marca'],
            ['key' => 'modelo', 'label' => 'Modelo'],
            ['key' => 'color', 'label' => 'Color'],
            ['key' => 'tracto', 'label' => 'Tracto'],
        ];

        $filename = 'vehiculos_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $this->applyIndexFilters($request, $this->baseQuery())
                ->whereIn('v.placa', array_values($selectedIds))
                ->orderBy('v.placa')
                ->get();

            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }

            return $this->exportPdfResponse($rows, $columns, 'Listado de Vehículos', $filename);
        }

        $rows = $this->applyIndexFilters($request, $this->baseQuery())
            ->orderBy('v.placa')
            ->get();

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Vehículos', $filename);
    }

    public function create(): View
    {
        $fields = [
                [
                    'name' => 'placa',
                    'type' => 'text',
                    'label' => 'Placa',
                    'required' => true,
                    'maxlength' => 20,
                    'helpText' => 'Identificador único del vehículo.',
                    'consultButton' => true,
                    'consultButtonLabel' => 'Consultar',
                    'consultButtonUrl' => route('api.consultar.placa'),
                    'consultTargetFields' => ['anio', 'marca', 'modelo', 'tipoUnidad_idtable1'],
                ],
                [
                    'name' => 'cliente_idcliente',
                    'type' => 'select',
                    'label' => 'Cliente',
                    'required' => true,
                    'tomSelect' => true,
                    'optionsData' => $this->clienteOptions(),
                    'optionKey' => 'idcliente',
                    'optionLabel' => 'cliente_label',
                    'placeholder' => 'Selecciona cliente',
                ],
                [
                    'name' => 'tipoUnidad_idtable1',
                    'type' => 'select',
                    'label' => 'Tipo de vehículo',
                    'required' => true,
                    'tomSelect' => true,
                    'optionsData' => $this->tipoVehiculoOptions(),
                    'optionKey' => 'idtipoVehiculo',
                    'optionLabel' => 'nombre',
                    'placeholder' => 'Selecciona tipo',
                ],
                [
                    'name' => 'anio',
                    'type' => 'text',
                    'label' => 'Año',
                    'required' => true,
                    'minlength' => 4,
                    'maxlength' => 4,
                    'inputmode' => 'numeric',
                    'pattern' => '^[0-9]{4}$',
                    'helpText' => 'Ingresa 4 números.',
                    'validationMessage' => 'El año debe tener 4 números válidos.',
                ],
                [
                    'name' => 'color',
                    'type' => 'text',
                    'label' => 'Color',
                    'required' => false,
                    'maxlength' => 20,
                    'helpText' => 'Selecciona o escribe un color.',
                    'placeholder' => 'Selecciona o escribe un color',
                    'datalistOptions' => VehiculoData::getColors(),
                ],
                [
                    'name' => 'marca',
                    'type' => 'text',
                    'label' => 'Marca',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Selecciona o escribe una marca.',
                    'placeholder' => 'Selecciona o escribe una marca',
                    'datalistOptions' => VehiculoData::getBrands(),
                ],
                [
                    'name' => 'modelo',
                    'type' => 'text',
                    'label' => 'Modelo',
                    'required' => false,
                    'maxlength' => 50,
                    'helpText' => 'Selecciona o escribe un modelo.',
                    'placeholder' => 'Selecciona o escribe un modelo',
                    'datalistOptions' => VehiculoData::getModels(),
                ],
                [
                    'name' => 'tracto',
                    'type' => 'switch',
                    'label' => 'Tracto',
                    'scalar' => true,
                    'required' => false,
                    'offValue' => 'No',
                    'onValue' => 'Si',
                    'switchLabels' => ['off' => 'No', 'on' => 'Si'],
                ],
            ];

        return view('vehiculo.vehiculos-form', [
            'title' => 'Nuevo Vehículo',
            'moduleTitle' => 'Módulo Vehículos',
            'mode' => 'create',
            'formAction' => route('modules.vehiculos.store'),
            'backRoute' => route('modules.vehiculos'),
            'record' => null,
            'readOnly' => false,
            'fields' => $fields,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'placa' => ['required', 'string', 'max:20', 'unique:vehiculo,placa', 'regex:' . self::SAFE_TEXT_REGEX],
            'cliente_idcliente' => ['required', 'exists:cliente,idcliente'],
            'tipoUnidad_idtable1' => ['required', 'exists:tipovehiculo,idtipoVehiculo'],
            'anio' => ['required', 'integer', 'digits:4', 'min:1900', 'max:' . ((int) date('Y') + 1)],
            'color' => ['nullable', 'string', 'max:20', 'regex:' . self::SAFE_TEXT_REGEX],
            'marca' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'modelo' => ['nullable', 'string', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'tracto' => ['nullable', 'in:Si,No'],
        ], [
            'placa.unique' => 'La placa ya existe en otro vehículo.',
            'placa.regex' => 'La placa contiene caracteres inválidos.',
        ]);

        $validated['placa'] = Str::upper(trim($validated['placa']));

        DB::table('vehiculo')->insert($validated);
        $this->publishResourceEvent(self::LOCK_RESOURCE, $validated['placa'], 'created');

        return redirect()
            ->route('modules.vehiculos')
            ->with('success', 'Vehículo creado correctamente.');
    }

    public function edit(Request $request, string $placa): View|RedirectResponse
    {
        $record = $this->baseQuery()->where('v.placa', $placa)->first();
        if (!$record) {
            return redirect()->route('modules.vehiculos')->with('error', 'No se encontró el vehículo solicitado.');
        }

        $dispositivos = DB::table('dispositivocliente')
            ->where('vehiculo_placa', $placa)
            ->select('iddispositivoCliente', 'marcaDispositivo', 'modeloDispositivo', 'fechaInstalacion', 'fechaBaja', 'estado')
            ->get();

        return view('vehiculo.vehiculos-form', [
            'title' => 'Editar Vehículo',
            'moduleTitle' => 'Módulo Vehículos',
            'mode' => 'edit',
            'formAction' => route('modules.vehiculos.update', $record->placa),
            'backRoute' => $request->query('return_route') === 'modules.clientes'
                ? route('modules.clientes')
                : route('modules.vehiculos'),
            'return_route' => $request->query('return_route'),
            'record' => $record,
            'readOnly' => true,
            'fields' => [
                [
                    'name' => 'placa',
                    'type' => 'text',
                    'label' => 'Placa',
                    'required' => true,
                    'maxlength' => 20,
                    'helpText' => 'Identificador único del vehículo.',
                    'disabled' => true,
                    'consultButton' => true,
                    'consultButtonLabel' => 'Consultar',
                    'consultButtonUrl' => route('api.consultar.placa'),
                    'consultTargetFields' => ['anio', 'marca', 'modelo', 'tipoUnidad_idtable1'],
                ],
                [
                    'name' => 'cliente_idcliente',
                    'type' => 'select',
                    'label' => 'Cliente',
                    'required' => true,
                    'tomSelect' => true,
                    'optionsData' => $this->clienteOptions(),
                    'optionKey' => 'idcliente',
                    'optionLabel' => 'cliente_label',
                    'placeholder' => 'Selecciona cliente',
                ],
                [
                    'name' => 'tipoUnidad_idtable1',
                    'type' => 'select',
                    'label' => 'Tipo de vehículo',
                    'required' => true,
                    'tomSelect' => true,
                    'optionsData' => $this->tipoVehiculoOptions(),
                    'optionKey' => 'idtipoVehiculo',
                    'optionLabel' => 'nombre',
                    'placeholder' => 'Selecciona tipo',
                ],
                [
                    'name' => 'anio',
                    'type' => 'text',
                    'label' => 'Año',
                    'required' => true,
                    'minlength' => 4,
                    'maxlength' => 4,
                    'inputmode' => 'numeric',
                    'pattern' => '^[0-9]{4}$',
                    'helpText' => 'Ingresa 4 números.',
                    'validationMessage' => 'El año debe tener 4 números válidos.',
                ],
                [
                    'name' => 'color',
                    'type' => 'text',
                    'label' => 'Color',
                    'required' => false,
                    'maxlength' => 20,
                    'helpText' => 'Selecciona o escribe un color.',
                    'placeholder' => 'Selecciona o escribe un color',
                    'datalistOptions' => VehiculoData::getColors(),
                ],
                [
                    'name' => 'marca',
                    'type' => 'text',
                    'label' => 'Marca',
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Selecciona o escribe una marca.',
                    'placeholder' => 'Selecciona o escribe una marca',
                    'datalistOptions' => VehiculoData::getBrands(),
                ],
                [
                    'name' => 'modelo',
                    'type' => 'text',
                    'label' => 'Modelo',
                    'required' => false,
                    'maxlength' => 50,
                    'helpText' => 'Selecciona o escribe un modelo.',
                    'placeholder' => 'Selecciona o escribe un modelo',
                    'datalistOptions' => VehiculoData::getModels(),
                ],
                [
                    'name' => 'tracto',
                    'type' => 'switch',
                    'label' => 'Tracto',
                    'scalar' => true,
                    'required' => false,
                    'offValue' => 'No',
                    'onValue' => 'Si',
                    'switchLabels' => ['off' => 'No', 'on' => 'Si'],
                ],
            ],
            'dispositivos' => $dispositivos,
        ] + $this->prepareLockViewData(self::LOCK_RESOURCE, $record->placa));
    }

    public function update(Request $request, string $placa): RedirectResponse
    {
        $recordExists = DB::table('vehiculo')->where('placa', $placa)->exists();
        if (!$recordExists) {
            return redirect()->route('modules.vehiculos')->with('error', 'No se encontró el vehículo solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, $placa, 'vehículo', 'modules.vehiculos')) {
            return $redirect;
        }

        $validated = $request->validate([
            'cliente_idcliente' => ['required', 'exists:cliente,idcliente'],
            'tipoUnidad_idtable1' => ['required', 'exists:tipovehiculo,idtipoVehiculo'],
            'anio' => ['required', 'integer', 'digits:4', 'min:1900', 'max:' . ((int) date('Y') + 1)],
            'color' => ['nullable', 'string', 'max:20', 'regex:' . self::SAFE_TEXT_REGEX],
            'marca' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'modelo' => ['nullable', 'string', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'tracto' => ['nullable', 'in:Si,No'],
        ]);

        DB::table('vehiculo')->where('placa', $placa)->update($validated);
        $this->publishResourceEvent(self::LOCK_RESOURCE, $placa, 'updated');
        $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, $placa);

        return redirect()->route('modules.vehiculos')->with('success', 'Vehículo actualizado correctamente.');
    }

    public function destroy(Request $request, string $placa): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, $placa, 'vehículo', 'modules.vehiculos')) {
            return $redirect;
        }

        try {
            DB::table('vehiculo')->where('placa', $placa)->delete();
            $this->publishResourceEvent(self::LOCK_RESOURCE, $placa, 'deleted');
            $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, $placa);

            return redirect()->route('modules.vehiculos')->with('success', 'Vehículo eliminado correctamente.');
        } catch (QueryException) {
            return redirect()->route('modules.vehiculos')->with('error', 'No se puede eliminar el vehículo porque tiene dispositivos asociados.');
        }
    }

    public function consultarPlaca(Request $request): JsonResponse
    {
        $placa = trim((string) $request->query('placa', ''));

        if ($placa === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'La placa es obligatoria para consultar.',
            ], 422);
        }

        $token = (string) env('SEGURTRACK_TOKEN', '');
        if ($token === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'El token de consulta de vehículos no está configurado en el servidor.',
            ], 500);
        }

        try {
            $response = Http::timeout(15)->get('https://tools.segurtrack.com/STKsearch/apiSUNARP_JTI.php', [
                'placa' => $placa,
                'token' => $token,
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo conectar con el servicio de consulta de vehículos.',
            ], 502);
        }

        if (!$response->successful()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo obtener información de la placa.',
            ], 502);
        }

        $payload = $response->json();
        $apiData = is_array($payload['data'] ?? null)
            ? $payload['data']
            : (is_array($payload) ? $payload : []);
        $clase = trim((string) ($apiData['clase'] ?? ''));
        $tipoId = $this->resolveTipoVehiculoId($clase);

        if (!is_array($apiData) || ($clase === '' && empty($apiData['marca']) && empty($apiData['modelo']) && empty($apiData['anioFabricacion']))) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se encontraron datos para la placa indicada.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'marca' => $apiData['marca'] ?? '',
                'modelo' => $apiData['modelo'] ?? '',
                'anio' => $apiData['anioFabricacion'] ?? '',
                'tipoUnidad_idtable1' => $tipoId,
            ],
            'message' => $tipoId === null && $clase !== ''
                ? 'Se obtuvieron los datos, pero la clase no coincide con un tipo de vehículo registrado.'
                : null,
        ]);
    }

    private function resolveTipoVehiculoId(string $clase): ?string
    {
        if ($clase === '') {
            return null;
        }

        $normalizedClase = Str::lower(Str::ascii(trim($clase)));
        $tipo = DB::table('tipovehiculo')
            ->select('idtipoVehiculo', 'nombre')
            ->get()
            ->first(function ($item) use ($normalizedClase) {
                return Str::lower(Str::ascii(trim((string) $item->nombre))) === $normalizedClase;
            });

        return $tipo ? (string) $tipo->idtipoVehiculo : null;
    }

    public function lockStatus(string $placa): JsonResponse
    {
        $status = ResourceLock::status(self::LOCK_RESOURCE, $placa);

        return response()->json([
            'locked' => $status !== null,
            'lock' => $status,
        ]);
    }

    public function acquireLock(Request $request, string $placa): JsonResponse
    {
        $usuario = $request->session()->get('erp_auth.usuario', 'anonimo');
        $result = ResourceLock::acquire(self::LOCK_RESOURCE, $placa, $usuario);

        if ($result['success']) {
            $this->publishLockEvent(self::LOCK_RESOURCE, $placa, $usuario, 'locked', $result['lock']['expires_at']);

            return response()->json([
                'success' => true,
                'lock' => $result['lock'],
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'El vehículo ya se encuentra bloqueado por otro usuario.',
            'lock' => $result['lock'],
        ], 409);
    }

    public function releaseLock(Request $request, string $placa): JsonResponse
    {
        $usuario = $request->session()->get('erp_auth.usuario', 'anonimo');
        $result = ResourceLock::release(self::LOCK_RESOURCE, $placa, $usuario);

        if ($result['success']) {
            $this->publishLockEvent(self::LOCK_RESOURCE, $placa, $usuario, 'released', null);

            return response()->json([
                'success' => true,
                'lock' => $result['lock'],
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'No se pudo liberar el bloqueo o el bloqueo no pertenece al usuario actual.',
            'lock' => $result['lock'],
        ], 403);
    }

    private function baseQuery()
    {
        return DB::table('vehiculo as v')
            ->leftJoin('cliente as c', 'c.idcliente', '=', 'v.cliente_idcliente')
            ->leftJoin('tipovehiculo as tv', 'tv.idtipoVehiculo', '=', 'v.tipoUnidad_idtable1')
            ->select([
                'v.placa',
                'v.cliente_idcliente',
                'v.tipoUnidad_idtable1',
                'v.anio',
                'v.color',
                'v.marca',
                'v.modelo',
                'v.tracto',
                DB::raw('COALESCE(c.nombreComercial, c.razonSocial, c.idcliente) as cliente_nombre'),
                DB::raw('COALESCE(tv.nombre, "") as tipo_vehiculo'),
            ]);
    }

    private function clienteOptions()
    {
        return DB::table('cliente')
            ->select([
                'idcliente',
                DB::raw('COALESCE(nombreComercial, razonSocial, idcliente) as cliente_label'),
            ])
            ->orderBy('cliente_label')
            ->get();
    }

    private function tipoVehiculoOptions()
    {
        return DB::table('tipovehiculo')
            ->orderBy('nombre')
            ->get();
    }
}
