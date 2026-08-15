<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Export\ExportableList;
use App\Http\Controllers\Permission\HandlesResourceLock;
use App\Support\ResourceLock;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class ServicioClienteController extends Controller
{
    use ExportableList;
    use HandlesResourceLock;

    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';

    private const LOCK_RESOURCE = 'servicio_cliente';

    public function index(Request $request): View
    {
        $query = $this->baseQuery();

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $query->where(function ($builder) use ($term) {
                $builder
                    ->where('sc.idservicioCliente', 'like', $term)
                    ->orWhere('sc.cliente_idcliente', 'like', $term)
                    ->orWhere('sc.vehiculo_placa', 'like', $term)
                    ->orWhere('sc.estado', 'like', $term)
                    ->orWhere('sc.docReferencia', 'like', $term)
                    ->orWhere('c.nombreComercial', 'like', $term)
                    ->orWhere('c.razonSocial', 'like', $term)
                    ->orWhere('v.marca', 'like', $term)
                    ->orWhere('v.modelo', 'like', $term)
                    ->orWhere('a.detalle', 'like', $term);
            });
        }

        // Filtros: cliente (texto), almacén (texto, excluye planes/servicios en opciones), vehículo (texto), estado (select), rango fechas
        $clienteText = trim((string) $request->input('cliente_idcliente', ''));
        if ($clienteText !== '') {
            $term = '%' . $clienteText . '%';
            $query->where(function ($b) use ($term) {
                $b->where('sc.cliente_idcliente', 'like', $term)
                  ->orWhere('c.nombreComercial', 'like', $term)
                  ->orWhere('c.razonSocial', 'like', $term);
            });
        }

        $almacenText = trim((string) $request->input('almacen_idalmacen', ''));
        if ($almacenText !== '') {
            $term = '%' . $almacenText . '%';
            $query->where('a.detalle', 'like', $term);
        }

        $vehiculoText = trim((string) $request->input('vehiculo', ''));
        if ($vehiculoText !== '') {
            $term = '%' . $vehiculoText . '%';
            $query->where(function ($b) use ($term) {
                $b->where('sc.vehiculo_placa', 'like', $term)
                  ->orWhere('v.marca', 'like', $term)
                  ->orWhere('v.modelo', 'like', $term);
            });
        }

        $estadoFilter = trim((string) $request->input('estado', ''));
        if ($estadoFilter !== '') {
            $query->where('sc.estado', $estadoFilter);
        }

        $fechaFrom = $request->input('fechaInicio_from');
        if ($fechaFrom) {
            $query->whereDate('sc.fechaInicio', '>=', $fechaFrom);
        }

        $fechaTo = $request->input('fechaInicio_to');
        if ($fechaTo) {
            $query->whereDate('sc.fechaInicio', '<=', $fechaTo);
        }

        $items = $query->orderByDesc('sc.idservicioCliente')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        // Formatear las fechas para el listado: mostrar solo fecha "25 abr, 2026"
        $items->setCollection(
            $items->getCollection()->map(function ($row) {
                try {
                    $row->fechaInicio = $row->fechaInicio
                        ? Carbon::parse($row->fechaInicio)->locale('es')->isoFormat('D MMM, YYYY')
                        : '-';
                } catch (\Exception $e) {
                    $row->fechaInicio = $row->fechaInicio ?? '-';
                }

                try {
                    $row->fecheVencimiento = $row->fecheVencimiento
                        ? Carbon::parse($row->fecheVencimiento)->locale('es')->isoFormat('D MMM, YYYY')
                        : '-';
                } catch (\Exception $e) {
                    $row->fecheVencimiento = $row->fecheVencimiento ?? '-';
                }

                // Normalizar estado para la columna tipo 'status': 1 => activo, 0 => inactivo
                $estadoRaw = strtolower(trim((string)($row->estado ?? '')));
                $row->estado = $estadoRaw === 'activo' || $estadoRaw === '1' || $estadoRaw === 'true' ? 1 : 0;

                // Formatear monto con símbolo de moneda
                if (!empty($row->moneda_simbolo)) {
                    $montoDisplay = $row->monto !== null && $row->monto !== '' ? $row->monto : '-';
                    $row->monto = $this->normalizeCurrencySymbol($row->moneda_simbolo) . ' ' . $montoDisplay;
                } else {
                    $row->monto = $row->monto !== null && $row->monto !== '' ? $row->monto : '-';
                }

                return $row;
            })
        );

        return view('serviciocliente.servicio-cliente', [
            'title' => 'Módulo Servicio Cliente',
            'singularTitle' => 'Servicio Cliente',
            'items' => $items,
            'columns' => [
                ['key' => 'idservicioCliente', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'cliente_nombre', 'label' => 'Cliente', 'type' => 'text'],
                ['key' => 'vehiculo_placa', 'label' => 'Vehículo', 'type' => 'text'],
                ['key' => 'almacen_detalle', 'label' => 'Almacén', 'type' => 'text'],
                ['key' => 'fechaInicio', 'label' => 'Fecha Inicio', 'type' => 'text'],
                ['key' => 'fechaVencimiento', 'label' => 'Fecha Vencimiento', 'type' => 'text'],
                ['key' => 'monto', 'label' => 'Monto', 'type' => 'text'],
                ['key' => 'estado', 'label' => 'Estado', 'type' => 'status'],
                ['key' => 'docReferencia', 'label' => 'Documento', 'type' => 'text'],
            ],
            'stats' => [
                ['label' => 'Total de servicios', 'value' => (clone $query)->count()],
            ],
            'filters' => [
                [
                    'name' => 'cliente_idcliente',
                    'type' => 'text',
                    'label' => 'Cliente',
                    'placeholder' => 'Escribe cliente',
                ],
                [
                    'name' => 'almacen_idalmacen',
                    'type' => 'text',
                    'label' => 'Almacén',
                    'placeholder' => 'Escribe almacén',
                ],
                [
                    'name' => 'vehiculo',
                    'type' => 'text',
                    'label' => 'Vehículo',
                    'placeholder' => 'Escribe placa, marca o modelo',
                ],
                [
                    'name' => 'estado',
                    'type' => 'select',
                    'label' => 'Estado',
                    'options' => [
                        ['value' => 'activo', 'label' => 'Activo'],
                        ['value' => 'inactivo', 'label' => 'Inactivo'],
                    ],
                    'placeholder' => 'Todos',
                ],
                [
                    'name' => 'fechaInicio_from',
                    'type' => 'date',
                    'label' => 'Fecha inicio desde',
                    'placeholder' => 'YYYY-MM-DD',
                ],
                [
                    'name' => 'fechaInicio_to',
                    'type' => 'date',
                    'label' => 'Fecha inicio hasta',
                    'placeholder' => 'YYYY-MM-DD',
                ],
            ],
            'createRoute' => route('modules.servicio-cliente.create'),
            'editRoute' => 'modules.servicio-cliente.edit',
            'showRoute' => 'modules.servicio-cliente.edit',
            'destroyRoute' => 'modules.servicio-cliente.destroy',
            'lockResource' => self::LOCK_RESOURCE,
            'exportRoutes' => [
                'pdf' => route('modules.servicio-cliente.export', ['format' => 'pdf']),
                'xlsx' => route('modules.servicio-cliente.export', ['format' => 'xlsx']),
            ],
            'identifierKey' => 'idservicioCliente',
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
            ['key' => 'idservicioCliente', 'label' => 'ID'],
            ['key' => 'cliente_nombre', 'label' => 'Cliente'],
            ['key' => 'vehiculo_placa', 'label' => 'Vehículo'],
            ['key' => 'almacen_detalle', 'label' => 'Almacén'],
            ['key' => 'fechaInicio', 'label' => 'Inicio'],
            ['key' => 'fecheVencimiento', 'label' => 'Vencimiento'],
            ['key' => 'monto', 'label' => 'Monto'],
            ['key' => 'estado', 'label' => 'Estado'],
            ['key' => 'docReferencia', 'label' => 'Documento'],
        ];

        $filename = 'servicio_cliente_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $this->baseQuery()->whereIn('sc.idservicioCliente', array_values($selectedIds))->orderBy('sc.idservicioCliente')->get();

            // Formatear fechas para exportación
            $rows = $rows->map(function ($r) {
                try {
                    $r->fechaInicio = $r->fechaInicio ? Carbon::parse($r->fechaInicio)->locale('es')->isoFormat('D MMM, YYYY') : '';
                } catch (\Exception $e) {
                    $r->fechaInicio = $r->fechaInicio ?? '';
                }

                try {
                    $r->fecheVencimiento = $r->fecheVencimiento ? Carbon::parse($r->fecheVencimiento)->locale('es')->isoFormat('D MMM, YYYY') : '';
                } catch (\Exception $e) {
                    $r->fecheVencimiento = $r->fecheVencimiento ?? '';
                }

                // Formatear monto con símbolo de moneda
                if (!empty($r->moneda_simbolo)) {
                    $montoDisplay = $r->monto !== null && $r->monto !== '' ? $r->monto : '-';
                    $r->monto = $this->normalizeCurrencySymbol($r->moneda_simbolo) . ' ' . $montoDisplay;
                } else {
                    $r->monto = $r->monto !== null && $r->monto !== '' ? $r->monto : '-';
                }

                return $r;
            });

            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }

            return $this->exportPdfResponse($rows, $columns, 'Listado de Servicio Cliente', $filename);
        }

        $rows = $this->baseQuery()->orderByDesc('sc.idservicioCliente')->get();

        // Formatear fechas para exportación en el conjunto completo
        $rows = $rows->map(function ($r) {
            try {
                $r->fechaInicio = $r->fechaInicio ? Carbon::parse($r->fechaInicio)->locale('es')->isoFormat('D MMM, YYYY') : '';
            } catch (\Exception $e) {
                $r->fechaInicio = $r->fechaInicio ?? '';
            }

            try {
                $r->fecheVencimiento = $r->fecheVencimiento ? Carbon::parse($r->fecheVencimiento)->locale('es')->isoFormat('D MMM, YYYY') : '';
            } catch (\Exception $e) {
                $r->fecheVencimiento = $r->fecheVencimiento ?? '';
            }

            // Formatear monto con símbolo de moneda
            if (!empty($r->moneda_simbolo)) {
                $montoDisplay = $r->monto !== null && $r->monto !== '' ? $r->monto : '-';
                $r->monto = $this->normalizeCurrencySymbol($r->moneda_simbolo) . ' ' . $montoDisplay;
            } else {
                $r->monto = $r->monto !== null && $r->monto !== '' ? $r->monto : '-';
            }

            return $r;
        });

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Servicio Cliente', $filename);
    }

    public function create(): View
    {
        return view('serviciocliente.servicio-cliente-form', [
            'title' => 'Nuevo Servicio Cliente',
            'moduleTitle' => 'Módulo Servicio Cliente',
            'mode' => 'create',
            'formAction' => route('modules.servicio-cliente.store'),
            'backRoute' => route('modules.servicio-cliente'),
            'record' => null,
            'readOnly' => false,
            'fields' => [
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
                    'name' => 'vehiculo_placa',
                    'type' => 'select',
                    'label' => 'Vehículo',
                    'required' => true,
                    'tomSelect' => true,
                    'optionsData' => $this->vehiculoOptions(),
                    'optionKey' => 'placa',
                    'optionLabel' => 'vehiculo_label',
                    'placeholder' => 'Selecciona vehículo',
                ],
                [
                    'name' => 'almacen_idalmacen',
                    'type' => 'select',
                    'label' => 'Almacén',
                    'required' => true,
                    'tomSelect' => true,
                    'optionsData' => $this->almacenOptions(),
                    'optionKey' => 'idalmacen',
                    'optionLabel' => 'detalle',
                    'placeholder' => 'Selecciona almacén',
                ],
                [
                    'name' => 'fechaInicio',
                    'type' => 'date',
                    'label' => 'Fecha inicio',
                    'required' => true,
                    'placeholder' => 'YYYY-MM-DD',
                    'value' => now()->format('Y-m-d'),
                ],
                [
                    'name' => 'fecheVencimiento',
                    'type' => 'date',
                    'label' => 'Fecha vencimiento',
                    'required' => false,
                    'placeholder' => 'YYYY-MM-DD',
                ],
                [
                    'name' => 'monto',
                    'type' => 'number',
                    'label' => 'Monto',
                    'required' => false,
                    'min' => 0,
                    'step' => '0.01',
                    'helpText' => 'Ingresa un monto.',
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
                    'placeholder' => 'Selecciona moneda',
                ],
                [
                    'name' => 'estado',
                    'type' => 'select',
                    'label' => 'Estado',
                    'required' => true,
                    'optionsData' => $this->estadoOptions(),
                    'optionKey' => 'value',
                    'optionLabel' => 'label',
                    'placeholder' => 'Selecciona estado',
                ],
                [
                    'name' => 'docReferencia',
                    'type' => 'text',
                    'label' => 'Documento referencia',
                    'maxlength' => 15,
                    'helpText' => 'Ingresa el número del documento de referencia.',
                ],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $rules = [
            'cliente_idcliente' => ['required', 'exists:cliente,idcliente'],
            'vehiculo_placa' => ['required', 'exists:vehiculo,placa'],
            'almacen_idalmacen' => ['required', 'exists:almacen,idalmacen'],
            'fechaInicio' => ['required', 'date_format:Y-m-d'],
            'fecheVencimiento' => ['nullable', 'date_format:Y-m-d'],
            'monto' => ['nullable', 'numeric', 'min:0'],
            'moneda_idmoneda' => ['nullable', 'exists:moneda,idmoneda'],
            'estado' => ['required', 'in:activo,inactivo'],
            'docReferencia' => ['nullable', 'string', 'max:15', 'regex:' . self::SAFE_TEXT_REGEX],
        ];

        $messages = [
            'required' => 'El campo :attribute es obligatorio.',
            'exists' => 'El valor seleccionado para :attribute no es válido.',
            'date_format' => 'El campo :attribute debe tener el formato YYYY-MM-DD.',
            'numeric' => 'El campo :attribute debe ser numérico.',
            'min' => 'El campo :attribute debe ser como mínimo :min.',
            'max' => 'El campo :attribute no debe exceder :max caracteres.',
            'regex' => 'El campo :attribute contiene caracteres inválidos.',
        ];

        $attributes = [
            'cliente_idcliente' => 'cliente',
            'vehiculo_placa' => 'vehículo',
            'almacen_idalmacen' => 'almacén',
            'fechaInicio' => 'fecha inicio',
            'fecheVencimiento' => 'fecha vencimiento',
            'monto' => 'monto',
            'moneda_idmoneda' => 'moneda',
            'estado' => 'estado',
            'docReferencia' => 'documento referencia',
        ];

        $validated = $request->validate($rules, $messages, $attributes);

        $validated['vehiculo_placa'] = Str::upper(trim($validated['vehiculo_placa']));

        $id = DB::table('serviciocliente')->insertGetId($validated);
        $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $id, 'created');

        return redirect()->route('modules.servicio-cliente')->with('success', 'Servicio cliente creado correctamente.');
    }

    public function edit(int $id): View|RedirectResponse
    {
        $record = $this->baseQuery()->where('sc.idservicioCliente', $id)->first();
        if (!$record) {
            return redirect()->route('modules.servicio-cliente')->with('error', 'No se encontró el servicio solicitado.');
        }

        return view('serviciocliente.servicio-cliente-form', [
            'title' => 'Editar Servicio Cliente',
            'moduleTitle' => 'Módulo Servicio Cliente',
            'mode' => 'edit',
            'formAction' => route('modules.servicio-cliente.update', $id),
            'backRoute' => route('modules.servicio-cliente'),
            'record' => $record,
            'readOnly' => true,
            'fields' => [
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
                    'name' => 'vehiculo_placa',
                    'type' => 'select',
                    'label' => 'Vehículo',
                    'required' => true,
                    'tomSelect' => true,
                    'optionsData' => $this->vehiculoOptions(),
                    'optionKey' => 'placa',
                    'optionLabel' => 'vehiculo_label',
                    'placeholder' => 'Selecciona vehículo',
                ],
                [
                    'name' => 'almacen_idalmacen',
                    'type' => 'select',
                    'label' => 'Almacén',
                    'required' => true,
                    'tomSelect' => true,
                    'optionsData' => $this->almacenOptions(),
                    'optionKey' => 'idalmacen',
                    'optionLabel' => 'detalle',
                    'placeholder' => 'Selecciona almacén',
                ],
                [
                    'name' => 'fechaInicio',
                    'type' => 'date',
                    'label' => 'Fecha inicio',
                    'required' => true,
                    'placeholder' => 'YYYY-MM-DD',
                    'value' => now()->format('Y-m-d'),
                ],
                [
                    'name' => 'fecheVencimiento',
                    'type' => 'date',
                    'label' => 'Fecha vencimiento',
                    'required' => false,
                    'placeholder' => 'YYYY-MM-DD',
                ],
                [
                    'name' => 'monto',
                    'type' => 'number',
                    'label' => 'Monto',
                    'required' => false,
                    'min' => 0,
                    'step' => '0.01',
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
                    'placeholder' => 'Selecciona moneda',
                ],
                [
                    'name' => 'estado',
                    'type' => 'select',
                    'label' => 'Estado',
                    'optionsData' => $this->estadoOptions(),
                    'optionKey' => 'value',
                    'optionLabel' => 'label',
                    'placeholder' => 'Selecciona estado',
                ],
                [
                    'name' => 'docReferencia',
                    'type' => 'text',
                    'label' => 'Documento referencia',
                    'maxlength' => 15,
                ],
            ],
        ] + $this->prepareLockViewData(self::LOCK_RESOURCE, (string) $id));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $recordExists = DB::table('serviciocliente')->where('idservicioCliente', $id)->exists();
        if (!$recordExists) {
            return redirect()->route('modules.servicio-cliente')->with('error', 'No se encontró el servicio solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, (string) $id, 'servicio cliente', 'modules.servicio-cliente')) {
            return $redirect;
        }

        $rules = [
            'cliente_idcliente' => ['required', 'exists:cliente,idcliente'],
            'vehiculo_placa' => ['required', 'exists:vehiculo,placa'],
            'almacen_idalmacen' => ['required', 'exists:almacen,idalmacen'],
            'fechaInicio' => ['nullable', 'date_format:Y-m-d'],
            'fecheVencimiento' => ['nullable', 'date_format:Y-m-d'],
            'monto' => ['nullable', 'numeric', 'min:0'],
            'moneda_idmoneda' => ['nullable', 'exists:moneda,idmoneda'],
            'estado' => ['required', 'in:activo,inactivo'],
            'docReferencia' => ['nullable', 'string', 'max:15', 'regex:' . self::SAFE_TEXT_REGEX],
        ];

        $messages = [
            'required' => 'El campo :attribute es obligatorio.',
            'exists' => 'El valor seleccionado para :attribute no es válido.',
            'date_format' => 'El campo :attribute debe tener el formato YYYY-MM-DD.',
            'numeric' => 'El campo :attribute debe ser numérico.',
            'min' => 'El campo :attribute debe ser como mínimo :min.',
            'max' => 'El campo :attribute no debe exceder :max caracteres.',
            'regex' => 'El campo :attribute contiene caracteres inválidos.',
        ];

        $attributes = [
            'cliente_idcliente' => 'cliente',
            'vehiculo_placa' => 'vehículo',
            'almacen_idalmacen' => 'almacén',
            'fechaInicio' => 'fecha inicio',
            'fecheVencimiento' => 'fecha vencimiento',
            'monto' => 'monto',
            'moneda_idmoneda' => 'moneda',
            'estado' => 'estado',
            'docReferencia' => 'documento referencia',
        ];

        $validated = $request->validate($rules, $messages, $attributes);

        $validated['vehiculo_placa'] = Str::upper(trim($validated['vehiculo_placa']));

        DB::table('serviciocliente')->where('idservicioCliente', $id)->update($validated);
        $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $id, 'updated');
        $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, (string) $id);

        return redirect()->route('modules.servicio-cliente')->with('success', 'Servicio cliente actualizado correctamente.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, (string) $id, 'servicio cliente', 'modules.servicio-cliente')) {
            return $redirect;
        }

        try {
            DB::table('serviciocliente')->where('idservicioCliente', $id)->delete();
            $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, (string) $id);

            return redirect()->route('modules.servicio-cliente')->with('success', 'Servicio cliente eliminado correctamente.');
        } catch (QueryException) {
            return redirect()->route('modules.servicio-cliente')->with('error', 'No se puede eliminar el servicio porque tiene relaciones asociadas.');
        }
    }

    public function lockStatus(int $id): JsonResponse
    {
        $status = ResourceLock::status(self::LOCK_RESOURCE, (string) $id);

        return response()->json([
            'locked' => $status !== null,
            'lock' => $status,
        ]);
    }

    public function acquireLock(Request $request, int $id): JsonResponse
    {
        $usuario = $request->session()->get('erp_auth.usuario', 'anonimo');
        $result = ResourceLock::acquire(self::LOCK_RESOURCE, (string) $id, $usuario);

        if ($result['success']) {
            $this->publishLockEvent(self::LOCK_RESOURCE, (string) $id, $usuario, 'locked', $result['lock']['expires_at']);

            return response()->json([
                'success' => true,
                'lock' => $result['lock'],
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'El servicio cliente ya se encuentra bloqueado por otro usuario.',
            'lock' => $result['lock'],
        ], 409);
    }

    public function releaseLock(Request $request, int $id): JsonResponse
    {
        $usuario = $request->session()->get('erp_auth.usuario', 'anonimo');
        $result = ResourceLock::release(self::LOCK_RESOURCE, (string) $id, $usuario);

        if ($result['success']) {
            $this->publishLockEvent(self::LOCK_RESOURCE, (string) $id, $usuario, 'released', null);

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
        return DB::table('serviciocliente as sc')
            ->leftJoin('cliente as c', 'c.idcliente', '=', 'sc.cliente_idcliente')
            ->leftJoin('vehiculo as v', 'v.placa', '=', 'sc.vehiculo_placa')
            ->leftJoin('almacen as a', 'a.idalmacen', '=', 'sc.almacen_idalmacen')
            ->leftJoin('moneda as m', 'm.idmoneda', '=', 'sc.moneda_idmoneda')
            ->select([
                'sc.idservicioCliente',
                'sc.cliente_idcliente',
                'sc.vehiculo_placa',
                'sc.almacen_idalmacen',
                'sc.moneda_idmoneda',
                'sc.fechaInicio',
                'sc.fecheVencimiento',
                'sc.monto',
                'sc.estado',
                'sc.docReferencia',
                DB::raw('COALESCE(c.nombreComercial, c.razonSocial, c.idcliente) as cliente_nombre'),
                DB::raw('COALESCE(v.marca, "") as vehiculo_marca'),
                DB::raw('COALESCE(v.modelo, "") as vehiculo_modelo'),
                DB::raw('COALESCE(a.detalle, "") as almacen_detalle'),
                DB::raw('COALESCE(m.simbolo, "") as moneda_simbolo'),
                DB::raw('COALESCE(m.detalle, "") as moneda_detalle'),
            ]);
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

        return 'S/';
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

    private function vehiculoOptions()
    {
        return DB::table('vehiculo as v')
            ->select([
                'v.placa',
                DB::raw('CONCAT(v.placa, " - ", COALESCE(v.marca, ""), " ", COALESCE(v.modelo, "")) as vehiculo_label'),
            ])
            ->orderBy('v.placa')
            ->get();
    }

    private function almacenOptions()
    {
        return DB::table('almacen')
            ->select(['idalmacen', 'detalle'])
            ->where('detalle', 'not like', '%equipo%')
            ->where('detalle', 'not like', '%equipos%')
            ->orderBy('detalle')
            ->get();
    }

    private function estadoOptions()
    {
        return collect([
            (object) ['value' => 'activo', 'label' => 'Activo'],
            (object) ['value' => 'inactivo', 'label' => 'Inactivo'],
        ]);
    }

    private function monedaOptions()
    {
        return DB::table('moneda')
            ->select([
                'idmoneda',
                DB::raw('CONCAT(detalle) as moneda_label'),
            ])
            ->orderBy('detalle')
            ->get();
    }
}