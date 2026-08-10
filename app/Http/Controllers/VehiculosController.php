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

        $items = $query->orderBy('v.placa', 'desc')
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

        $grouped = $this->loadVehiculoDeviceGroups($placas);
        if (empty($grouped)) {
            return $items;
        }

        $newCollection = $items->getCollection()->map(function ($row) use ($grouped) {
            return $this->attachRelationGroupsToVehiculoRow($row, $grouped);
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
                ->orderByDesc('n.fechaAsignacion')
                ->orderByDesc('n.iddetNumerosDispositivo')
                ->get()
                ->groupBy('dispositivoCliente_iddispositivoCliente')
                ->map(function ($group) {
                    $first = $group->first();
                    return $first ? ($first->numeroTelefonico_numeroTelefonico ?? '-') : '-';
                })->all();
        }

        return $dispositivosRows->map(function ($d) use ($numbersMap) {
            $arr = (array) $d;
            $arr['numero'] = $numbersMap[$arr['iddispositivoCliente']] ?? '-';
            return $arr;
        })->groupBy('vehiculo_placa')->map(function ($group) {
            return $group->map(function ($d) {
                return (array) $d;
            })->all();
        })->all();
    }

    private function attachRelationGroupsToVehiculoRow($row, array $grouped)
    {
        $placa = data_get($row, 'placa');
        $devices = $grouped[$placa] ?? [];

        $relationGroups = [
            [
                'key' => 'dispositivo_cliente',
                'label' => 'Dispositivos cliente',
                'columns' => [
                    ['key' => 'iddispositivoCliente', 'label' => 'ID Dispositivo', 'type' => 'text'],
                    ['key' => 'numero', 'label' => 'Número', 'type' => 'text'],
                    ['key' => 'marcaDispositivo', 'label' => 'Marca', 'type' => 'text'],
                    ['key' => 'modeloDispositivo', 'label' => 'Modelo', 'type' => 'text'],
                    ['key' => 'fechaInstalacion', 'label' => 'Fecha de instalación', 'type' => 'date'],
                    ['key' => 'fechaBaja', 'label' => 'Fecha de baja', 'type' => 'date'],
                    ['key' => 'estado', 'label' => 'Estado', 'type' => 'status'],
                ],
                'records' => $devices,
            ],
        ];

        $rowArr = (array) $row;
        $rowArr['numero'] = '-';
        if (!empty($devices) && is_array($devices) && isset($devices[0]['numero'])) {
            $rowArr['numero'] = $devices[0]['numero'] ?? '-';
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
                    'type' => 'text',
                    'placeholder' => 'Filtrar por tracto',
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
            $rows = $this->baseQuery()->whereIn('v.placa', array_values($selectedIds))->orderBy('v.placa')->get();

            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }

            return $this->exportPdfResponse($rows, $columns, 'Listado de Vehículos', $filename);
        }

        $rows = $this->baseQuery()->orderBy('v.placa')->get();

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
                    'consultTargetFields' => ['anio', 'color', 'marca', 'modelo'],
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
                    'required' => true,
                    'maxlength' => 20,
                    'minlength' => 2,
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
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Selecciona o escribe un modelo.',
                    'placeholder' => 'Selecciona o escribe un modelo',
                    'datalistOptions' => VehiculoData::getModels(),
                ],
                [
                    'name' => 'tracto',
                    'type' => 'text',
                    'label' => 'Tracto',
                    'required' => true,
                    'maxlength' => 20,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
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
            'color' => ['required', 'string', 'min:2', 'max:20', 'regex:' . self::SAFE_TEXT_REGEX],
            'marca' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'modelo' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'tracto' => ['required', 'string', 'min:2', 'max:20', 'regex:' . self::SAFE_TEXT_REGEX],
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

    public function edit(string $placa): View|RedirectResponse
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
            'backRoute' => route('modules.vehiculos'),
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
                    'consultTargetFields' => ['anio', 'color', 'marca', 'modelo'],
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
                    'required' => true,
                    'maxlength' => 20,
                    'minlength' => 2,
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
                    'required' => true,
                    'maxlength' => 50,
                    'minlength' => 2,
                    'helpText' => 'Selecciona o escribe un modelo.',
                    'placeholder' => 'Selecciona o escribe un modelo',
                    'datalistOptions' => VehiculoData::getModels(),
                ],
                [
                    'name' => 'tracto',
                    'type' => 'text',
                    'label' => 'Tracto',
                    'required' => true,
                    'maxlength' => 20,
                    'minlength' => 2,
                    'helpText' => 'Mínimo 2 caracteres.',
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
            'color' => ['required', 'string', 'min:2', 'max:20', 'regex:' . self::SAFE_TEXT_REGEX],
            'marca' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'modelo' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'tracto' => ['required', 'string', 'min:2', 'max:20', 'regex:' . self::SAFE_TEXT_REGEX],
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

        return response()->json([
            'status' => 'error',
            'message' => 'La consulta de placa aún no está implementada. Cuando la API esté lista, esta ruta devolverá año, color, marca y modelo.',
            'data' => null,
        ], 501);
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
