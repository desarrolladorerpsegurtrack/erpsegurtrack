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
        $query = $this->baseQuery();

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

        $items = $query->orderBy('v.placa')->paginate($this->resolvePerPage($request))->withQueryString();

        return view('vehiculo.vehiculos', [
            'title' => 'Módulo Vehículos',
            'singularTitle' => 'Vehículo',
            'items' => $items,
            'columns' => [
                ['key' => 'placa', 'label' => 'Placa', 'type' => 'text'],
                ['key' => 'cliente_nombre', 'label' => 'Cliente', 'type' => 'text'],
                ['key' => 'tipo_vehiculo', 'label' => 'Tipo', 'type' => 'text'],
                ['key' => 'anio', 'label' => 'Año', 'type' => 'text'],
                ['key' => 'marca', 'label' => 'Marca', 'type' => 'text'],
            ],
            'stats' => [
                ['label' => 'Total de vehículos', 'value' => (clone $query)->count()],
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
            ],
            'createRoute' => route('modules.vehiculos.create'),
            'editRoute' => 'modules.vehiculos.edit',
            'showRoute' => 'modules.vehiculos.edit',
            'destroyRoute' => 'modules.vehiculos.destroy',
            'lockResource' => self::LOCK_RESOURCE,
            'exportRoutes' => [
                'pdf' => route('modules.vehiculos.export', ['format' => 'pdf']),
                'xlsx' => route('modules.vehiculos.export', ['format' => 'xlsx']),
            ],
            'identifierKey' => 'placa',
        ]);
    }

    public function export(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $rows = $this->baseQuery()
            ->orderBy('v.placa')
            ->get();

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
            ->select('iddispositivoCliente', 'marcaDispositivo', 'modeloDispositivo')
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
                    'disabled' => true,
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
