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
        $query = DB::table('dispositivocliente as d')
            ->leftJoin('vehiculo as v', 'v.placa', '=', 'd.vehiculo_placa')
            ->select([
                'd.iddispositivoCliente',
                'd.vehiculo_placa',
                'v.cliente_idcliente',
                'd.marcaDispositivo',
                'd.modeloDispositivo',
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
                    ->orWhere('v.placa', 'like', $term)
                    ->orWhere('d.marcaDispositivo', 'like', $term)
                    ->orWhere('d.modeloDispositivo', 'like', $term);
            });
        }

        if ($id = trim((string) $request->query('iddispositivoCliente', ''))) {
            $query->where('d.iddispositivoCliente', 'like', "%{$id}%");
        }

        if ($placa = trim((string) $request->query('vehiculo_placa', ''))) {
            $query->where('d.vehiculo_placa', 'like', "%{$placa}%");
        }

        if ($marca = trim((string) $request->query('marcaDispositivo', ''))) {
            $query->where('d.marcaDispositivo', 'like', "%{$marca}%");
        }

        if ($modelo = trim((string) $request->query('modeloDispositivo', ''))) {
            $query->where('d.modeloDispositivo', 'like', "%{$modelo}%");
        }

        if ($estado = trim((string) $request->query('estado', ''))) {
            $query->where('d.estado', $estado);
        }

        $items = $query
            ->orderBy('d.iddispositivoCliente')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        return view('dispositivocliente.dispositivo-cliente', [
            'title' => 'Módulo Dispositivo cliente',
            'singularTitle' => 'Dispositivo cliente',
            'items' => $items,
            'stats' => [
                ['label' => 'Total de Dispositivos', 'value' => (clone $query)->count()],
                ['label' => 'Dispositivos Activos', 'value' => (clone $query)->where('d.estado', '1')->count()],
                ['label' => 'Dispositivos Inactivos', 'value' => (clone $query)->where('d.estado', '0')->count()],
            ],
            'columns' => [
                ['key' => 'iddispositivoCliente', 'label' => 'ID Dispositivo', 'type' => 'text'],
                ['key' => 'vehiculo_placa', 'label' => 'Vehículo', 'type' => 'text'],
                ['key' => 'marcaDispositivo', 'label' => 'Marca', 'type' => 'text'],
                ['key' => 'modeloDispositivo', 'label' => 'Modelo', 'type' => 'text'],
                ['key' => 'fechaInstalacion', 'label' => 'Fecha de instalación', 'type' => 'date'],
                ['key' => 'fechaBaja', 'label' => 'Fecha de baja', 'type' => 'date'],
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
                    'name' => 'marcaDispositivo',
                    'label' => 'Marca',
                    'type' => 'text',
                    'placeholder' => 'Buscar marca',
                ],
                [
                    'name' => 'modeloDispositivo',
                    'label' => 'Modelo',
                    'type' => 'text',
                    'placeholder' => 'Buscar modelo',
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
            'createRoute' => route('modules.dispositivo-cliente.create'),
            'editRoute' => 'modules.dispositivo-cliente.edit',
            'showRoute' => 'modules.dispositivo-cliente.edit',
            'destroyRoute' => 'modules.dispositivo-cliente.destroy',
            'lockResource' => self::LOCK_RESOURCE,
            'exportRoutes' => [
                'pdf' => route('modules.dispositivo-cliente.export', ['format' => 'pdf']),
                'xlsx' => route('modules.dispositivo-cliente.export', ['format' => 'xlsx']),
            ],
            'identifierKey' => 'iddispositivoCliente',
        ]);
    }

    public function export(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $rows = $this->getExportRows($request);
        $columns = $this->getExportColumns();
        $filename = 'dispositivo_cliente_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Dispositivo cliente', $filename);
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

    public function edit(string $id): View|RedirectResponse
    {
        $record = $this->findRecord($id);
        if (!$record) {
            return redirect()->route('modules.dispositivo-cliente')->with('error', 'No se encontró el dispositivo solicitado.');
        }

        return view('dispositivocliente.dispositivo-cliente-form', [
            'title' => 'Editar Dispositivo cliente',
            'moduleTitle' => 'Módulo Dispositivo cliente',
            'mode' => 'edit',
            'readOnly' => true,
            'formAction' => route('modules.dispositivo-cliente.update', $record->iddispositivoCliente),
            'backRoute' => route('modules.dispositivo-cliente'),
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

        DB::table('dispositivocliente')->where('iddispositivoCliente', $id)->update($validated);
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
        return DB::table('dispositivocliente as d')
            ->leftJoin('vehiculo as v', 'v.placa', '=', 'd.vehiculo_placa')
            ->select([
                'd.iddispositivoCliente',
                'd.vehiculo_placa',
                'd.marcaDispositivo',
                'd.modeloDispositivo',
                'd.fechaInstalacion',
                'd.fechaBaja',
                'd.estado',
            ])
            ->orderBy('d.iddispositivoCliente')
            ->get()
            ->map(function ($item) {
                return [
                    'iddispositivoCliente' => $item->iddispositivoCliente,
                    'vehiculo_placa' => $item->vehiculo_placa,
                    'marcaDispositivo' => $item->marcaDispositivo,
                    'modeloDispositivo' => $item->modeloDispositivo,
                    'fechaInstalacion' => $item->fechaInstalacion,
                    'fechaBaja' => $item->fechaBaja,
                    'estado' => $item->estado,
                ];
            })
            ->all();
    }

    private function getExportColumns(): array
    {
        return [
            ['key' => 'iddispositivoCliente', 'label' => 'ID Dispositivo'],
            ['key' => 'vehiculo_placa', 'label' => 'Vehículo'],
            ['key' => 'marcaDispositivo', 'label' => 'Marca'],
            ['key' => 'modeloDispositivo', 'label' => 'Modelo'],
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
