<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Export\ExportableList;
use App\Http\Controllers\Permission\HandlesResourceLock;
use App\Support\ResourceLock;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DispositivoClienteController extends Controller
{
    use ExportableList;
    use HandlesResourceLock;

    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';
    private const LOCK_RESOURCE = 'dispositivo_cliente';

    public function index(Request $request): View
    {
        $latestService = DB::table('serviciocliente')
            ->select('vehiculo_placa', DB::raw('MAX(idservicioCliente) as idservicioCliente'))
            ->groupBy('vehiculo_placa');

        $query = DB::table('dispositivocliente as d')
            ->leftJoin('vehiculo as v', 'v.placa', '=', 'd.vehiculo_placa')
            ->leftJoin('cliente as c', 'c.idcliente', '=', 'v.cliente_idcliente')
            ->leftJoinSub($latestService, 'latest_sc', function ($join) {
                $join->on('latest_sc.vehiculo_placa', '=', 'd.vehiculo_placa');
            })
            ->leftJoin('serviciocliente as sc', 'sc.idservicioCliente', '=', 'latest_sc.idservicioCliente')
            ->leftJoin('almacen as sa', 'sa.idalmacen', '=', 'sc.almacen_idalmacen')
            ->select([
                'd.iddispositivoCliente',
                'd.vehiculo_placa',
                'v.cliente_idcliente',
                DB::raw('COALESCE(c.nombreComercial, c.razonSocial, c.idcliente) as nombre_cliente'),
                'd.marcaDispositivo',
                'd.modeloDispositivo',
                DB::raw("CONCAT(COALESCE(sa.detalle, ''), CASE COALESCE(sa.periodo, 0) WHEN 30 THEN ' - Mensual' WHEN 90 THEN ' - 3 Meses' WHEN 180 THEN ' - 6 Meses' WHEN 365 THEN ' - 12 Meses' WHEN 730 THEN ' - 24 Meses' WHEN 1095 THEN ' - 36 Meses' WHEN 1460 THEN ' - 48 Meses' ELSE '' END) as servicio"),
                'd.fechaInstalacion',
                'd.fechaBaja',
                'd.estado',
            ]);

        if ($search = trim((string) $request->query('q', ''))) {
            $term = '%' . $search . '%';
            $query->where(function ($query) use ($term) {   
                $query
                    ->where('d.iddispositivoCliente', 'like', $term)
                    ->orWhere('d.vehiculo_placa', 'like', $term)
                    ->orWhereExists(function ($numberQuery) use ($term) {
                        $numberQuery->select(DB::raw(1))
                            ->from('detnumerosdispositivo as n')
                            ->whereColumn('n.dispositivoCliente_iddispositivoCliente', 'd.iddispositivoCliente')
                            ->where('n.numeroTelefonico_numeroTelefonico', 'like', $term);
                    })
                    ->orWhere('v.placa', 'like', $term)
                    ->orWhere('c.nombreComercial', 'like', $term)
                    ->orWhere('d.marcaDispositivo', 'like', $term)
                    ->orWhere('d.modeloDispositivo', 'like', $term);
            });
        }

        

        if ($id = trim((string) $request->query('iddispositivoCliente', ''))) {
            $query->where('d.iddispositivoCliente', 'like', "%{$id}%");
        }

        if ($numero = trim((string) $request->query('numero', ''))) {
            $query->whereExists(function ($numberQuery) use ($numero) {
                $numberQuery->select(DB::raw(1))
                    ->from('detnumerosdispositivo as n')
                    ->whereColumn('n.dispositivoCliente_iddispositivoCliente', 'd.iddispositivoCliente')
                    ->where('n.numeroTelefonico_numeroTelefonico', 'like', "%{$numero}%");
            });
        }

        if ($placa = trim((string) $request->query('vehiculo_placa', ''))) {
            $query->where('d.vehiculo_placa', 'like', "%{$placa}%");
        }

        if ($cliente = trim((string) $request->query('nombreComercial', ''))) {
            $query->where('c.nombreComercial', 'like', "%{$cliente}%");
        }

        $estado = trim((string) $request->query('estado', ''));
        if ($estado !== '') {
            $query->where('d.estado', $estado);
        }


        $items = $query
            ->orderByRaw("CASE WHEN d.estado = '1' THEN 0 ELSE 1 END")
            ->orderByDesc('d.fechaInstalacion')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();
        // Añadir los grupos de relación (números de dispositivo) en cada fila para permitir
        $items->setCollection(
            $items->getCollection()->map(function ($row) {
                $deviceId = data_get($row, 'iddispositivoCliente');
                $relationGroups = [];

                if (!empty($deviceId)) {
                    $numbers = DB::table('historial_dispositivocliente as h')
                        ->select([
                            'h.idhistorial_dispositivocliente',
                            'h.dispositivoCliente_iddispositivoCliente',
                            'h.vehiculo as vehiculo_placa',
                            'h.cliente as nombre_cliente',
                            'h.numerotelefono as numeroTelefonico_numeroTelefonico',
                            'h.fechainicio',
                            'h.fechafin',
                        ])
                        ->where('h.dispositivoCliente_iddispositivoCliente', $deviceId)
                        ->orderByDesc('h.fechafin')
                        ->orderByDesc('h.idhistorial_dispositivocliente')
                        ->get();

                    if ($numbers->isNotEmpty()) {
                        $relationGroups[] = [
                            'key' => 'historial_dispositivocliente',
                            'label' => 'Historial de Dispositivo',
                            'columns' => [
                                ['key' => 'dispositivoCliente_iddispositivoCliente', 'label' => 'ID Dispositivo'],
                                ['key' => 'vehiculo_placa', 'label' => 'Vehículo'],
                                ['key' => 'numeroTelefonico_numeroTelefonico', 'label' => 'Número'],
                                ['key' => 'nombre_cliente', 'label' => 'Cliente'],
                                ['key' => 'fechainicio', 'label' => 'Fecha Inicio'],
                                ['key' => 'fechafin', 'label' => 'Fecha Fin'],
                            ],
                            'records' => $numbers->map(function ($r) {
                                $row = (array) $r;
                                $raw = $row['fechainicio'] ?? null;
                                $formatted = '-';
                                if (!empty($raw) && $raw !== '0000-00-00') {
                                    try {
                                        $dt = Carbon::parse($raw);
                                        $months = ['ene.', 'feb.', 'mar.', 'abr.', 'may.', 'jun.', 'jul.', 'ago.', 'sep.', 'oct.', 'nov.', 'dic.'];
                                        $formatted = sprintf('%s %s %s', $dt->format('d'), $months[$dt->month - 1], $dt->format('Y'));  
                                    } catch (\Throwable $e) {
                                        $formatted = (string) $raw;
                                    }
                                }
                                $row['fechainicio'] = $formatted;
                                $rawEnd = $row['fechafin'] ?? null;
                                if (!empty($rawEnd) && $rawEnd !== '0000-00-00') {
                                    try {
                                        $dt = Carbon::parse($rawEnd);
                                        $row['fechafin'] = sprintf('%s %s %s', $dt->format('d'), $months[$dt->month - 1], $dt->format('Y'));
                                    } catch (\Throwable $e) {
                                        $row['fechafin'] = (string) $rawEnd;
                                    }
                                } else {
                                    $row['fechafin'] = '-';
                                }
                                return $row;
                            })->all(),
                        ];
                    }
                }

                // El número de la fila principal sigue siendo el número actual, no una baja histórica.
                $activeNumber = DB::table('detnumerosdispositivo')
                    ->where('dispositivoCliente_iddispositivoCliente', $deviceId)
                    ->orderByDesc('fechaAsignacion')
                    ->orderByDesc('iddetNumerosDispositivo')
                    ->value('numeroTelefonico_numeroTelefonico') ?? '-';

                $row->numero = $activeNumber;
                $row->relation_groups = $relationGroups;
                return $row;
            })
        );

        return view('dispositivocliente.dispositivo-cliente', [
            'title' => 'Módulo Dispositivo cliente',
            'singularTitle' => 'Dispositivo cliente',
            'resultsLabel' => 'Dispositivos',
            'items' => $items,
            'stats' => [
                ['label' => 'Total de Dispositivos', 'value' => (clone $query)->count()],
                ['label' => 'Dispositivos Activos', 'value' => (clone $query)->where('d.estado', '1')->count()],
                ['label' => 'Dispositivos Inactivos', 'value' => (clone $query)->where('d.estado', '0')->count()],
            ],
            'columns' => [
                ['key' => 'iddispositivoCliente', 'label' => 'ID Dispositivo', 'type' => 'text'],
                ['key' => 'vehiculo_placa', 'label' => 'Vehículo', 'type' => 'text'],
                ['key' => 'numero', 'label' => 'Número', 'type' => 'text'],
                ['key' => 'nombre_cliente', 'label' => 'Cliente', 'type' => 'text'],
                ['key' => 'servicio', 'label' => 'Servicio', 'type' => 'text'],
                ['key' => 'modeloDispositivo', 'label' => 'Modelo', 'type' => 'text'],
                ['key' => 'fechaInstalacion', 'label' => 'Fecha Inicio', 'type' => 'date'],
                ['key' => 'fechaBaja', 'label' => 'Fecha Fin', 'type' => 'date'],
                ['key' => 'estado', 'label' => 'Estado', 'type' => 'status'],
            ],
            'filters' => [
                [
                    'name' => 'iddispositivoCliente',
                    'label' => 'ID Dispositivo',
                    'type' => 'text',
                    'placeholder' => 'Buscar dispositivo',
                ],
                [
                    'name' => 'vehiculo_placa',
                    'label' => 'Vehículo',
                    'type' => 'text',
                    'placeholder' => 'Buscar placa',
                ],
                [
                    'name' => 'numero',
                    'label' => 'Número',
                    'type' => 'text',
                    'placeholder' => 'Buscar número',
                ],
                [
                    'name' => 'nombreComercial',
                    'label' => 'Cliente',
                    'type' => 'text',
                    'placeholder' => 'Buscar cliente',
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
            ],
            'editRoute' => 'modules.dispositivo-cliente.edit',
            'showRoute' => 'modules.dispositivo-cliente.edit',
            'destroyRoute' => 'modules.dispositivo-cliente.destroy',
            'lockResource' => self::LOCK_RESOURCE,
            'exportRoutes' => [
                'pdf' => route('modules.dispositivo-cliente.export', ['format' => 'pdf']),
                'xlsx' => route('modules.dispositivo-cliente.export', ['format' => 'xlsx']),
            ],
            'relationPanelView' => 'cliente.relation-panel',
            'identifierKey' => 'iddispositivoCliente',
        ]);
    }

    public function export(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $selectedIds = (array) $request->input('selectedIds', []);

        $rows = collect($this->getExportRows($request));

        if (!empty($selectedIds)) {
            $rows = $rows->whereIn('iddispositivoCliente', $selectedIds);
        }

        $columns = $this->getExportColumns();
        $filename = 'dispositivo_cliente_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows->all(), $columns, $filename);
        }

        return $this->exportPdfResponse($rows->all(), $columns, 'Listado de Dispositivo cliente', $filename);
    }

    public function create(): View
    {
        return view('dispositivocliente.dispositivo-cliente-form', [
            'title' => 'Nuevo Dispositivo cliente',
            'moduleTitle' => 'Módulo Dispositivo cliente',
            'mode' => 'create',
            'readOnly' => false,
            'formAction' => route('modules.dispositivo-cliente.store'),
            'backRoute' => route('modules.dispositivo-cliente'),
            'record' => null,
            'fields' => [
                [
                    'name' => 'iddispositivoCliente',
                    'type' => 'text',
                    'label' => 'ID Dispositivo',
                    'required' => true,
                    'maxlength' => 20,
                    'helpText' => 'Identificador único del dispositivo.',
                ],
                [
                    'name' => 'vehiculo_placa',
                    'type' => 'select',
                    'label' => 'Vehículo',
                    'required' => true,
                    'tomSelect' => true,
                    'optionsData' => $this->vehiculoOptions(),
                    'optionKey' => 'placa',
                    'optionLabel' => 'label',
                    'placeholder' => 'Selecciona vehículo',
                ],
                [
                    'name' => 'marcaDispositivo',
                    'type' => 'text',
                    'label' => 'Marca',
                    'required' => true,
                    'maxlength' => 50,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'modeloDispositivo',
                    'type' => 'text',
                    'label' => 'Modelo',
                    'required' => true,
                    'maxlength' => 50,
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'fechaInstalacion',
                    'type' => 'date',
                    'label' => 'Fecha de instalación',
                    'required' => true,
                    'value' => now()->format('Y-m-d'),
                ],
                [
                    'name' => 'fechaBaja',
                    'type' => 'date',
                    'label' => 'Fecha de baja',
                    'required' => false,
                ],
                [
                    'name' => 'estado',
                    'type' => 'select',
                    'label' => 'Estado',
                    'required' => true,
                    'value' => '1',
                    'options' => [
                        ['value' => '1', 'label' => 'Activo'],
                        ['value' => '0', 'label' => 'Inactivo'],
                    ],
                    'placeholder' => 'Selecciona estado',
                ],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'iddispositivoCliente' => ['required', 'string', 'max:20', 'regex:' . self::SAFE_TEXT_REGEX, 'unique:dispositivocliente,iddispositivoCliente'],
            'vehiculo_placa' => ['required', 'exists:vehiculo,placa'],
            'marcaDispositivo' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'modeloDispositivo' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'fechaInstalacion' => ['required', 'date'],
            'fechaBaja' => ['nullable', 'date'],
            'estado' => ['required', 'string', 'size:1', 'regex:/^[A-Za-z0-9]$/'],
        ]);

        $validated['fechaInstalacion'] = $this->normalizeDateInput($validated['fechaInstalacion'] ?? null);
        $validated['fechaBaja'] = $this->normalizeDateInput($validated['fechaBaja'] ?? null);

        DB::table('dispositivocliente')->insert($validated);
        $this->publishResourceEvent(self::LOCK_RESOURCE, $validated['iddispositivoCliente'], 'created');

        return redirect()->route('modules.dispositivo-cliente')->with('success', 'Dispositivo cliente creado correctamente.');
    }

    public function edit(Request $request, string $id): View|RedirectResponse
    {
        $record = $this->findRecord($id);
        if (!$record) {
            return redirect()->route('modules.dispositivo-cliente')->with('error', 'No se encontró el dispositivo solicitado.');
        }

        $historialDispositivoCliente = DB::table('historial_dispositivocliente as h')
            ->select([
                'h.idhistorial_dispositivocliente',
                'h.dispositivoCliente_iddispositivoCliente',
                'h.detNumerosDispositivo_iddetNumerosDispositivo',
                'h.vehiculo',
                'h.cliente',
                'h.numerotelefono',
                'h.fechainicio',
                'h.fechafin',
            ])
            ->where('h.dispositivoCliente_iddispositivoCliente', $id)
            ->orderByDesc('h.fechafin')
            ->orderByDesc('h.idhistorial_dispositivocliente')
            ->get();

        return view('dispositivocliente.dispositivo-cliente-form', [
            'title' => 'Editar Dispositivo cliente',
            'moduleTitle' => 'Módulo Dispositivo cliente',
            'mode' => 'edit',
            'readOnly' => true,
            'formAction' => route('modules.dispositivo-cliente.update', $record->iddispositivoCliente),
            'backRoute' => $request->query('return_route') === 'modules.clientes'
                ? route('modules.clientes')
                : route('modules.dispositivo-cliente'),
            'return_route' => $request->query('return_route'),
            'record' => $record,
            'fields' => [
                [
                    'name' => 'iddispositivoCliente',
                    'type' => 'text',
                    'label' => 'ID Dispositivo',
                    'required' => true,
                    'maxlength' => 20,
                    'disabled' => true,
                ],
                [
                    'name' => 'vehiculo_placa',
                    'type' => 'select',
                    'label' => 'Vehículo',
                    'required' => true,
                    'tomSelect' => true,
                    'optionsData' => $this->vehiculoOptions(),
                    'optionKey' => 'placa',
                    'optionLabel' => 'label',
                    'placeholder' => 'Selecciona vehículo',
                ],
                [
                    'name' => 'marcaDispositivo',
                    'type' => 'text',
                    'label' => 'Marca',
                    'required' => true,
                    'maxlength' => 50,
                ],
                [
                    'name' => 'modeloDispositivo',
                    'type' => 'text',
                    'label' => 'Modelo',
                    'required' => true,
                    'maxlength' => 50,
                ],
                [
                    'name' => 'fechaInstalacion',
                    'type' => 'date',
                    'label' => 'Fecha de instalación',
                    'required' => true,
                ],
                [
                    'name' => 'fechaBaja',
                    'type' => 'date',
                    'label' => 'Fecha de baja',
                    'required' => false,
                ],
                [
                    'name' => 'estado',
                    'type' => 'select',
                    'label' => 'Estado',
                    'required' => true,
                    'options' => [
                        ['value' => '1', 'label' => 'Activo'],
                        ['value' => '0', 'label' => 'Inactivo'],
                    ],
                    'placeholder' => 'Selecciona estado',
                ],
            ],
            'historialDispositivoCliente' => $historialDispositivoCliente,
            
        ] + $this->prepareLockViewData(self::LOCK_RESOURCE, $record->iddispositivoCliente));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        
        $recordExists = DB::table('dispositivocliente')->where('iddispositivoCliente', $id)->exists();
        if (!$recordExists) {
            return redirect()->route('modules.dispositivo-cliente')->with('error', 'No se encontró el dispositivo solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, $id, 'dispositivo cliente', 'modules.dispositivo-cliente')) {
            return $redirect;
        }

        $validated = $request->validate([
            'vehiculo_placa' => ['required', 'exists:vehiculo,placa'],
            'marcaDispositivo' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'modeloDispositivo' => ['required', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'fechaInstalacion' => ['required', 'date'],
            'fechaBaja' => ['nullable', 'date'],
            'estado' => ['required', 'string', 'size:1', 'regex:/^[A-Za-z0-9]$/'],
        ]);

        $validated['fechaInstalacion'] = $this->normalizeDateInput($validated['fechaInstalacion'] ?? null);
        $validated['fechaBaja'] = $this->normalizeDateInput($validated['fechaBaja'] ?? null);

        DB::transaction(function () use ($id, $validated) {
            $current = DB::table('dispositivocliente')
                ->where('iddispositivoCliente', $id)
                ->first();

            if ($current && (string) $current->estado === '1' && (string) $validated['estado'] === '0') {
                $vehicle = DB::table('vehiculo')
                    ->where('placa', $validated['vehiculo_placa'])
                    ->first(['placa', 'cliente_idcliente']);
                $clientName = $vehicle ? DB::table('cliente')
                    ->where('idcliente', $vehicle->cliente_idcliente)
                    ->selectRaw('COALESCE(nombreComercial, razonSocial, idcliente) as nombre_cliente')
                    ->value('nombre_cliente') : null;
                $latestNumber = DB::table('detnumerosdispositivo')
                    ->where('dispositivoCliente_iddispositivoCliente', $id)
                    ->orderByDesc('fechaAsignacion')
                    ->orderByDesc('iddetNumerosDispositivo')
                    ->first(['iddetNumerosDispositivo', 'numeroTelefonico_numeroTelefonico']);

                if ($latestNumber) {
                    DB::table('historial_dispositivocliente')->insert([
                        'dispositivoCliente_iddispositivoCliente' => $id,
                        'detNumerosDispositivo_iddetNumerosDispositivo' => $latestNumber->iddetNumerosDispositivo,
                        'cliente' => $clientName,
                        'vehiculo' => $vehicle?->placa,
                        'numerotelefono' => $latestNumber->numeroTelefonico_numeroTelefonico,
                        'fechainicio' => $validated['fechaInstalacion'],
                        'fechafin' => $validated['fechaBaja'],
                    ]);
                }
            }

            DB::table('dispositivocliente')->where('iddispositivoCliente', $id)->update($validated);
        });
        $this->publishResourceEvent(self::LOCK_RESOURCE, $id, 'updated');
        $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, $id);

        return redirect()->route('modules.dispositivo-cliente')->with('success', 'Dispositivo cliente actualizado correctamente.');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, $id, 'dispositivo cliente', 'modules.dispositivo-cliente')) {
            return $redirect;
        }

        $enUso = DB::table('detnumerosdispositivo')
            ->where('dispositivoCliente_iddispositivoCliente', $id)
            ->exists();

        if ($enUso) {
            return redirect()->route('modules.dispositivo-cliente')->with('error', 'No se puede eliminar el dispositivo porque tiene números asociados.');
        }

        try {
            DB::table('dispositivocliente')->where('iddispositivoCliente', $id)->delete();
            $this->publishResourceEvent(self::LOCK_RESOURCE, $id, 'deleted');
            $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, $id);

            return redirect()->route('modules.dispositivo-cliente')->with('success', 'Dispositivo cliente eliminado correctamente.');
        } catch (QueryException) {
            return redirect()->route('modules.dispositivo-cliente')->with('error', 'No se pudo eliminar el dispositivo.');
        }
    }

    public function lockStatus(string $id): JsonResponse
    {
        $status = ResourceLock::status(self::LOCK_RESOURCE, $id);

        return response()->json([
            'locked' => $status !== null,
            'lock' => $status,
        ]);
    }

    public function acquireLock(Request $request, string $id): JsonResponse
    {
        $usuario = $request->session()->get('erp_auth.usuario', 'anonimo');
        $result = ResourceLock::acquire(self::LOCK_RESOURCE, $id, $usuario);

        if ($result['success']) {
            $this->publishLockEvent(self::LOCK_RESOURCE, $id, $usuario, 'locked', $result['lock']['expires_at']);

            return response()->json([
                'success' => true,
                'lock' => $result['lock'],
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'El dispositivo ya se encuentra bloqueado por otro usuario.',
            'lock' => $result['lock'],
        ], 409);
    }

    public function releaseLock(Request $request, string $id): JsonResponse
    {
        $usuario = $request->session()->get('erp_auth.usuario', 'anonimo');
        $result = ResourceLock::release(self::LOCK_RESOURCE, $id, $usuario);

        if ($result['success']) {
            $this->publishLockEvent(self::LOCK_RESOURCE, $id, $usuario, 'released', null);

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

    private function getExportRows(Request $request): array
    {
        $query = DB::table('dispositivocliente as d')
            ->leftJoin('vehiculo as v', 'v.placa', '=', 'd.vehiculo_placa')
            ->leftJoin('cliente as c', 'c.idcliente', '=', 'v.cliente_idcliente')
            ->leftJoinSub(
                DB::table('serviciocliente')
                    ->select('vehiculo_placa', DB::raw('MAX(idservicioCliente) as idservicioCliente'))
                    ->groupBy('vehiculo_placa'),
                'latest_sc',
                function ($join) {
                    $join->on('latest_sc.vehiculo_placa', '=', 'd.vehiculo_placa');
                }
            )
            ->leftJoin('serviciocliente as sc', 'sc.idservicioCliente', '=', 'latest_sc.idservicioCliente')
            ->leftJoin('almacen as sa', 'sa.idalmacen', '=', 'sc.almacen_idalmacen')
            ->select([
                'd.iddispositivoCliente',
                'd.vehiculo_placa',
                DB::raw('COALESCE(c.nombreComercial, c.razonSocial, c.idcliente) as nombre_cliente'),
                // Obtener número activo (última asignación) mediante subconsulta
                DB::raw('(select n.numeroTelefonico_numeroTelefonico from detnumerosdispositivo n where n.dispositivoCliente_iddispositivoCliente = d.iddispositivoCliente order by n.fechaAsignacion desc, n.iddetNumerosDispositivo desc limit 1) as numero'),
                'd.marcaDispositivo',
                'd.modeloDispositivo',
                DB::raw("CONCAT(COALESCE(sa.detalle, ''), CASE COALESCE(sa.periodo, 0) WHEN 30 THEN ' - Mensual' WHEN 90 THEN ' - 3 Meses' WHEN 180 THEN ' - 6 Meses' WHEN 365 THEN ' - 12 Meses' WHEN 730 THEN ' - 24 Meses' WHEN 1095 THEN ' - 36 Meses' WHEN 1460 THEN ' - 48 Meses' ELSE '' END) as servicio"),
                'd.fechaInstalacion',
                'd.fechaBaja',
                'd.estado',
            ]);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $query->where(function ($builder) use ($term) {
                $builder
                    ->where('d.iddispositivoCliente', 'like', $term)
                    ->orWhere('d.vehiculo_placa', 'like', $term)
                    ->orWhere('v.placa', 'like', $term)
                    ->orWhere('c.nombreComercial', 'like', $term)
                    ->orWhere('d.marcaDispositivo', 'like', $term)
                    ->orWhere('d.modeloDispositivo', 'like', $term)
                    ->orWhereExists(function ($numberQuery) use ($term) {
                        $numberQuery->select(DB::raw(1))
                            ->from('detnumerosdispositivo as n')
                            ->whereColumn('n.dispositivoCliente_iddispositivoCliente', 'd.iddispositivoCliente')
                            ->where('n.numeroTelefonico_numeroTelefonico', 'like', $term);
                    });
            });
        }

        $id = trim((string) $request->input('iddispositivoCliente', ''));
        if ($id !== '') {
            $query->where('d.iddispositivoCliente', 'like', "%{$id}%");
        }

        $numero = trim((string) $request->input('numero', ''));
        if ($numero !== '') {
            $query->whereExists(function ($numberQuery) use ($numero) {
                $numberQuery->select(DB::raw(1))
                    ->from('detnumerosdispositivo as n')
                    ->whereColumn('n.dispositivoCliente_iddispositivoCliente', 'd.iddispositivoCliente')
                    ->where('n.numeroTelefonico_numeroTelefonico', 'like', "%{$numero}%");
            });
        }

        $placa = trim((string) $request->input('vehiculo_placa', ''));
        if ($placa !== '') {
            $query->where('d.vehiculo_placa', 'like', "%{$placa}%");
        }

        $cliente = trim((string) $request->input('nombreComercial', ''));
        if ($cliente !== '') {
            $query->where('c.nombreComercial', 'like', "%{$cliente}%");
        }

        $estado = trim((string) $request->input('estado', ''));
        if ($estado !== '') {
            $query->where('d.estado', $estado);
        }

        return $query->orderBy('d.iddispositivoCliente')->get()
            ->map(function ($item) {
                return [
                    'iddispositivoCliente' => $item->iddispositivoCliente,
                    'vehiculo_placa' => $item->vehiculo_placa,
                    'nombre_cliente' => $item->nombre_cliente,
                    'numero' => $item->numero ?? '-',
                    'marcaDispositivo' => $item->marcaDispositivo,
                    'modeloDispositivo' => $item->modeloDispositivo,
                    'servicio' => $item->servicio ?: '-',
                    'fechaInstalacion' => $item->fechaInstalacion,
                    'fechaBaja' => $item->fechaBaja,
                    'estado' => ((string) $item->estado === '1') ? 'Activo' : 'Inactivo',
                ];
            })
            ->all();
    }

    private function getExportColumns(): array
    {
        return [
            ['key' => 'iddispositivoCliente', 'label' => 'ID Dispositivo'],
            ['key' => 'vehiculo_placa', 'label' => 'Vehículo'],
            ['key' => 'numero', 'label' => 'Número'],
            ['key' => 'marcaDispositivo', 'label' => 'Marca'],
            ['key' => 'modeloDispositivo', 'label' => 'Modelo'],
            ['key' => 'servicio', 'label' => 'Servicio'],
            ['key' => 'fechaInstalacion', 'label' => 'Fecha instalación'],
            ['key' => 'fechaBaja', 'label' => 'Fecha baja'],
            ['key' => 'estado', 'label' => 'Estado'],
        ];
    }

    private function normalizeDateInput(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $trimmed = trim($value);

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
                return Carbon::createFromFormat('Y-m-d', $trimmed )
                    ->setTime((int) now()->format('H'), (int) now()->format('i'), (int) now()->format('s'))
                    ->format('Y-m-d H:i:s');
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $trimmed)) {
                return Carbon::createFromFormat('Y-m-d H:i', $trimmed )
                    ->setTime((int) now()->format('H'), (int) now()->format('i'), (int) now()->format('s'))
                    ->format('Y-m-d H:i:s');
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $trimmed)) {
                return Carbon::createFromFormat('Y-m-d H:i:s', $trimmed )
                    ->format('Y-m-d H:i:s');
            }

            return Carbon::parse($trimmed)->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    private function vehiculoOptions(): array
    {
        return DB::table('vehiculo as v')
            ->leftJoin('cliente as c', 'c.idcliente', '=', 'v.cliente_idcliente')
            ->select([
                'v.placa',
                DB::raw('CONCAT(v.placa, " - ", COALESCE(c.nombreComercial, c.razonSocial, c.idcliente)) as label'),
            ])
            ->orderBy('v.placa')
            ->get()
            ->all();
    }

    private function findRecord(string $id): ?object
    {
        return DB::table('dispositivocliente')
            ->where('iddispositivoCliente', $id)
            ->first();
    }
}
