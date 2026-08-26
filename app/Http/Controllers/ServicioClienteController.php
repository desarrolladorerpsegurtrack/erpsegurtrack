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
use Illuminate\Validation\Rule;
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
                    ->orWhere('a.detalle', 'like', $term)
                    ->orWhere('p.nombrePlataforma', 'like', $term);
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
                $periodo = $this->formatPeriodo($row->almacen_periodo ?? null);
                if ($periodo !== '' && !str_ends_with($row->almacen_detalle, ' - ' . $periodo)) {
                    $row->almacen_detalle = trim($row->almacen_detalle . ' - ' . $periodo);
                }

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
                ['key' => 'cliente_nombre', 'label' => 'Cliente', 'type' => 'text'],
                ['key' => 'vehiculo_placa', 'label' => 'Vehículo', 'type' => 'text'],
                ['key' => 'almacen_detalle', 'label' => 'Servicio', 'type' => 'text'],
                ['key' => 'plataforma', 'label' => 'Plataforma', 'type' => 'text'],
                ['key' => 'fechaInicio', 'label' => 'Fecha Inicio', 'type' => 'text'],
                ['key' => 'fecheVencimiento', 'label' => 'Fecha Fin', 'type' => 'text'],
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
                    'label' => 'Servicio',
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
            ['key' => 'almacen_detalle', 'label' => 'Servicio'],
            ['key' => 'plataforma', 'label' => 'Plataforma'],
            ['key' => 'fechaInicio', 'label' => 'Fecha Inicio'],
            ['key' => 'fecheVencimiento', 'label' => 'Fecha Fin'],
            ['key' => 'monto', 'label' => 'Monto'],
            ['key' => 'estado', 'label' => 'Estado'],
            ['key' => 'docReferencia', 'label' => 'Documento'],
        ];

        $filename = 'servicio_cliente_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $this->applyIndexFilters($request, $this->baseQuery())
                ->whereIn('sc.idservicioCliente', array_values($selectedIds))
                ->orderBy('sc.idservicioCliente')
                ->get();

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

        $rows = $this->applyIndexFilters($request, $this->baseQuery())
            ->orderByDesc('sc.idservicioCliente')
            ->get();

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
            'dispositivoOptions' => [],
            'numeroTelefonicoOptions' => $this->numeroTelefonicoOptions(),
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
                    'optionsData' => [],
                    'optionKey' => 'placa',
                    'optionLabel' => 'vehiculo_label',
                    'placeholder' => 'Selecciona vehículo',
                ],
                [
                    'name' => 'dispositivoCliente_iddispositivoCliente',
                    'type' => 'select',
                    'label' => 'ID Dispositivo',
                    'required' => true,
                    'tomSelect' => true,
                    'options' => [],
                    'placeholder' => 'Selecciona ID dispositivo',
                ],
                [
                    'name' => 'numeroTelefonico_numeroTelefonico',
                    'type' => 'select',
                    'label' => 'Número telefónico',
                    'required' => true,
                    'tomSelect' => true,
                    'options' => $this->numeroTelefonicoOptions(),
                    'placeholder' => 'Selecciona número telefónico',
                ],
                [
                    'name' => 'almacen_idalmacen',
                    'type' => 'select',
                    'label' => 'Servicio',
                    'required' => true,
                    'tomSelect' => true,
                    'optionsData' => $this->almacenOptions(),
                    'optionKey' => 'idalmacen',
                    'optionLabel' => 'detalle',
                    'placeholder' => 'Selecciona servicio',
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
                    'value' => $this->defaultMonedaId(),
                ],
                [
                    'name' => 'estado',
                    'type' => 'select',
                    'label' => 'Estado',
                    'required' => true,
                    'value' => 'activo',
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
            'clienteOptionMeta' => $this->clienteOptionMeta(),
            'servicioOptionMeta' => $this->servicioOptionMeta(),
            'vehiculosUrl' => route('modules.servicio-cliente.vehiculos'),
            'dispositivosUrl' => route('modules.servicio-cliente.dispositivos'),
            'serviciosUrl' => route('modules.servicio-cliente.servicios'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $rules = [
            'cliente_idcliente' => [
                'required',
                'exists:cliente,idcliente',
                Rule::unique('serviciocliente')->where(fn ($query) => $query
                    ->where('vehiculo_placa', $request->input('vehiculo_placa'))
                    ->where('almacen_idalmacen', $request->input('almacen_idalmacen'))),
            ],
            'dispositivoCliente_iddispositivoCliente' => [
                'required',
                'string',
                'max:20',
                function ($attribute, $value, $fail) use ($request) {
                    $clienteId = $request->input('cliente_idcliente');
                    $stockDevice = DB::table('elementoalmacen')
                        ->where('imei', $value)
                        ->first();
                    if (!$stockDevice) {
                        $fail('El ID Dispositivo seleccionado no existe.');
                        return;
                    }
                    $estado = (int) $stockDevice->estado;
                    $inStock = in_array($estado, [1, 2, 4], true);
                    $isInactiveForClient = false;
                    if ($clienteId !== null && $clienteId !== '') {
                        $isInactiveForClient = DB::table('dispositivocliente as dc')
                            ->join('vehiculo as v', 'v.placa', '=', 'dc.vehiculo_placa')
                            ->where('dc.iddispositivoCliente', $value)
                            ->where('dc.estado', '0')
                            ->where('v.cliente_idcliente', $clienteId)
                            ->exists();
                    }
                    if (!$inStock && !$isInactiveForClient) {
                        $fail('El ID Dispositivo seleccionado no está disponible.');
                        return;
                    }
                    $existingDc = DB::table('dispositivocliente')
                        ->where('iddispositivoCliente', $value)
                        ->first();
                    if ($existingDc && (string) $existingDc->estado !== '0') {
                        $fail('El ID Dispositivo seleccionado ya está en uso por otro servicio activo.');
                        return;
                    }
                },
            ],
            'numeroTelefonico_numeroTelefonico' => [
                'required',
                'string',
                'exists:numerotelefonico,numeroTelefonico',
                Rule::exists('numerotelefonico', 'numeroTelefonico')->where(fn ($query) => $query->where('estado', '1')),
                Rule::exists('numerotelefonico', 'numeroTelefonico')->where(function ($query) {
                    $query->whereNotExists(function ($subquery) {
                        $subquery->select(DB::raw(1))
                            ->from('detnumerosdispositivo as dn')
                            ->whereColumn('dn.numeroTelefonico_numeroTelefonico', 'numerotelefonico.numeroTelefonico');
                    })->whereExists(function ($subquery) {
                        $subquery->select(DB::raw(1))
                            ->from('detallesimcard as ds')
                            ->whereColumn('ds.numeroTelefonico_numeroTelefonico', 'numerotelefonico.numeroTelefonico')
                            ->where('ds.estado', '0');
                    });
                }),
                Rule::unique('detnumerosdispositivo', 'numeroTelefonico_numeroTelefonico'),
            ],
            'vehiculo_placa' => [
                'required',
                Rule::exists('vehiculo', 'placa')->where(fn ($query) => $query->where('cliente_idcliente', $request->input('cliente_idcliente'))),
            ],
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
        $deviceId = trim((string) $validated['dispositivoCliente_iddispositivoCliente']);
        $phoneNumber = trim((string) $validated['numeroTelefonico_numeroTelefonico']);
        $fechaServicio = Carbon::createFromFormat('Y-m-d', $validated['fechaInicio'])->format('Y-m-d H:i:s');
        $usuario = (string) $request->session()->get('erp_auth.usuario', 'anonimo');
        $device = DB::table('elementoalmacen as ea')
            ->join('almacen as a', 'a.idalmacen', '=', 'ea.dispositivo_iddispositivo')
            ->leftJoin('modelo as mo', 'mo.idmodelo', '=', 'a.modelo_idmodelo')
            ->leftJoin('marca as ma', 'ma.idmarca', '=', 'mo.marca_idmarca')
            ->where('ea.imei', $deviceId)
            ->select(['ea.imei', 'ma.nombreMarca', 'mo.nombreModelo'])
            ->first();

        if (!$device) {
            return redirect()->back()->withInput()->with('error', 'El ID Dispositivo seleccionado ya no está disponible.');
        }

        try {
            $id = DB::transaction(function () use ($validated, $deviceId, $phoneNumber, $fechaServicio, $usuario, $device, $request) {
            $stockDevice = DB::table('elementoalmacen')
                ->where('imei', $deviceId)
                ->lockForUpdate()
                ->first();

            if (!$stockDevice) {
                throw new \RuntimeException('device_unavailable');
            }

            $currentState = (int) $stockDevice->estado;
            $inStock = in_array($currentState, [1, 2, 4], true);
            $isInactiveForClient = false;
            if ($validated['cliente_idcliente'] !== null && $validated['cliente_idcliente'] !== '') {
                $isInactiveForClient = DB::table('dispositivocliente as dc')
                    ->join('vehiculo as v', 'v.placa', '=', 'dc.vehiculo_placa')
                    ->where('dc.iddispositivoCliente', $deviceId)
                    ->where('dc.estado', '0')
                    ->where('v.cliente_idcliente', $validated['cliente_idcliente'])
                    ->exists();
            }

            if (!$inStock && !$isInactiveForClient) {
                throw new \RuntimeException('device_unavailable');
            }

            if (DB::table('detnumerosdispositivo')->where('numeroTelefonico_numeroTelefonico', $phoneNumber)->exists()
                || !DB::table('detallesimcard')->where('numeroTelefonico_numeroTelefonico', $phoneNumber)->where('estado', '0')->exists()) {
                throw new \RuntimeException('phone_unavailable');
            }

            if (DB::table('serviciocliente')
                ->where('cliente_idcliente', $validated['cliente_idcliente'])
                ->where('vehiculo_placa', $validated['vehiculo_placa'])
                ->where('almacen_idalmacen', $validated['almacen_idalmacen'])
                ->exists()) {
                throw new \RuntimeException('service_duplicate');
            }

            unset($validated['dispositivoCliente_iddispositivoCliente'], $validated['numeroTelefonico_numeroTelefonico']);
            $serviceId = DB::table('serviciocliente')->insertGetId($validated);

            DB::table('dispositivocliente')->updateOrInsert(
                ['iddispositivoCliente' => $deviceId],
                [
                    'vehiculo_placa' => $validated['vehiculo_placa'],
                    'marcaDispositivo' => $device->nombreMarca ?? null,
                    'modeloDispositivo' => $device->nombreModelo ?? null,
                    'fechaInstalacion' => $fechaServicio,
                    'fechaBaja' => $validated['estado'] === 'inactivo' ? $fechaServicio : null,
                    'estado' => $validated['estado'] === 'activo' ? '1' : '0',
                ]
            );

            DB::table('detnumerosdispositivo')->insert([
                'dispositivoCliente_iddispositivoCliente' => $deviceId,
                'numeroTelefonico_numeroTelefonico' => $phoneNumber,
                'fechaAsignacion' => $fechaServicio,
            ]);

            DB::table('historial_servicio')->insert([
                'usuario_usuario' => $usuario,
                'servicioCliente_idservicioCliente' => $serviceId,
                'fecha_accion' => $fechaServicio,
                'motivo' => 'Creacion',
                'descripcion' => 'Creacion de servicio',
                'doc_referencia' => $validated['docReferencia'] ?? null,
            ]);

            DB::table('detalle_serviciodispositivo')->insert([
                'servicioCliente_idservicioCliente' => $serviceId,
                'vehiculo_placa' => $validated['vehiculo_placa'],
                'dispositivoCliente_iddispositivoCliente' => $deviceId,
                'fecha' => $fechaServicio,
                'observacion' => $validated['estado'] === 'inactivo' ? ($request->input('comentario_baja') ?? null) : null,
                'estado' => $validated['estado'] === 'activo' ? 1 : 0,
            ]);

            $nextStateMap = [1 => 6, 2 => 3, 4 => 5];
            $nextState = $nextStateMap[$currentState] ?? $currentState;
            DB::table('elementoalmacen')->where('imei', $deviceId)->update(['estado' => $nextState]);

            // Handle deactivation logic if service is registered as inactivo
            if ($validated['estado'] === 'inactivo') {
                $activeSimPair = DB::table('detallesimcard')
                    ->where('numeroTelefonico_numeroTelefonico', $phoneNumber)
                    ->where('estado', '0')
                    ->first();

                if ($activeSimPair) {
                    DB::table('detallesimcard')
                        ->where('iddetalleSimCard', $activeSimPair->iddetalleSimCard)
                        ->update(['estado' => '1']);

                    DB::table('simcard')
                        ->where('idsimCard', $activeSimPair->simCard_idsimCard)
                        ->update(['estado' => '0']);
                }

                DB::table('numerotelefonico')
                    ->where('numeroTelefonico', $phoneNumber)
                    ->update(['estado' => '1']);

                $deviceState = (int) $nextState;
                if ($deviceState === 3 || $deviceState === 2) {
                    DB::table('elementoalmacen')
                        ->where('imei', $deviceId)
                        ->update(['estado' => 2]);
                } elseif (in_array($deviceState, [0, 5, 6], true)) {
                    DB::table('elementoalmacen')
                        ->where('imei', $deviceId)
                        ->update(['estado' => 0]);
                }

                DB::table('historial_servicio')->insert([
                    'usuario_usuario' => $usuario,
                    'servicioCliente_idservicioCliente' => $serviceId,
                    'fecha_accion' => $fechaServicio,
                    'motivo' => 'Dado de baja',
                    'descripcion' => $request->input('comentario_baja') ?? 'Servicio dado de baja',
                    'doc_referencia' => $validated['docReferencia'] ?? null,
                ]);
            }

            return $serviceId;
            });
        } catch (\RuntimeException $exception) {
            $message = match ($exception->getMessage()) {
                'device_unavailable' => 'El ID Dispositivo ya no está disponible para este tipo de cliente.',
                'phone_unavailable' => 'El número telefónico ya tiene una relación y no se puede reutilizar.',
                'service_duplicate' => 'Ese servicio ya está asignado al vehículo seleccionado.',
                default => 'No se pudo crear el servicio cliente. Intenta nuevamente.',
            };

            return redirect()->back()->withInput()->with('error', $message);
        }
        $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $id, 'created');

        return redirect()->route('modules.servicio-cliente')->with('success', 'Servicio cliente creado correctamente.');
    }

    public function edit(Request $request, int $id): View|RedirectResponse
    {
        $record = $this->baseQuery()->where('sc.idservicioCliente', $id)->first();
        if (!$record) {
            return redirect()->route('modules.servicio-cliente')->with('error', 'No se encontró el servicio solicitado.');
        }

        $fields = [
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
                'disabled' => true,
            ],
            [
                'name' => 'vehiculo_placa',
                'type' => 'select',
                'label' => 'Vehículo',
                'required' => true,
                'tomSelect' => true,
                'optionsData' => $this->vehiculoOptions((string) $record->cliente_idcliente),
                'optionKey' => 'placa',
                'optionLabel' => 'vehiculo_label',
                'placeholder' => 'Selecciona vehículo',
            ],
            [
                'name' => 'dispositivoCliente_iddispositivoCliente',
                'type' => 'select',
                'label' => 'ID Dispositivo',
                'required' => true,
                'tomSelect' => true,
                'options' => $this->dispositivoOptions((string) $record->cliente_idcliente, (string) $record->dispositivoCliente_iddispositivoCliente),
                'placeholder' => 'Selecciona ID dispositivo',
            ],
            [
                'name' => 'numeroTelefonico_numeroTelefonico',
                'type' => 'select',
                'label' => 'Número telefónico',
                'required' => true,
                'tomSelect' => true,
                'options' => $this->numeroTelefonicoOptions((string) $record->numeroTelefonico_numeroTelefonico),
                'placeholder' => 'Selecciona número telefónico',
            ],
            [
                'name' => 'almacen_idalmacen',
                'type' => 'select',
                'label' => 'Servicio',
                'required' => true,
                'tomSelect' => true,
                'optionsData' => $this->almacenOptions(),
                'optionKey' => 'idalmacen',
                'optionLabel' => 'detalle',
                'placeholder' => 'Selecciona servicio',
                'disabled' => true,
            ],
            [
                'name' => 'fechaInicio',
                'type' => 'date',
                'label' => 'Fecha inicio',
                'required' => true,
                'placeholder' => 'YYYY-MM-DD',
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
        ];

        if ($record->estado === 'inactivo') {
            $comentarioBaja = DB::table('historial_servicio')
                ->where('servicioCliente_idservicioCliente', $id)
                ->where('motivo', 'Dado de baja')
                ->orderByDesc('idhistorial_servicio')
                ->value('descripcion');

            $fields[] = [
                'name' => 'comentario_baja_display',
                'type' => 'text',
                'label' => 'Comentario de baja',
                'value' => $comentarioBaja ?? 'Servicio dado de baja',
                'readonly' => true,
            ];
        }

        $historialRows = DB::table('historial_servicio as hs')
            ->leftJoin('serviciocliente as sc', 'sc.idservicioCliente', '=', 'hs.servicioCliente_idservicioCliente')
            ->leftJoin('cliente as c', 'c.idcliente', '=', 'sc.cliente_idcliente')
            ->leftJoin('almacen as a', 'a.idalmacen', '=', 'sc.almacen_idalmacen')
            ->select([
                'hs.idhistorial_servicio',
                'hs.fecha_accion',
                'hs.motivo',
                'hs.descripcion',
                DB::raw("CONCAT(COALESCE(c.nombreComercial, c.razonSocial, c.idcliente)) as cliente"),
                'sc.vehiculo_placa as vehiculo',
                'a.detalle as servicio',
                'a.periodo as servicio_periodo',
                DB::raw('(select dsd.dispositivoCliente_iddispositivoCliente from detalle_serviciodispositivo as dsd where dsd.servicioCliente_idservicioCliente = sc.idservicioCliente order by dsd.iddetalle_serviciodispositivo desc limit 1) as dispositivo'),
                DB::raw('(select dnd.numeroTelefonico_numeroTelefonico from detnumerosdispositivo as dnd where dnd.dispositivoCliente_iddispositivoCliente = (select dsd.dispositivoCliente_iddispositivoCliente from detalle_serviciodispositivo as dsd where dsd.servicioCliente_idservicioCliente = sc.idservicioCliente order by dsd.iddetalle_serviciodispositivo desc limit 1) order by dnd.iddetNumerosDispositivo desc limit 1) as numero'),
            ])
            ->where('hs.servicioCliente_idservicioCliente', $id)
            ->orderByDesc('hs.idhistorial_servicio')
            ->get()
            ->map(function ($row) {
                $raw = $row->fecha_accion ?? null;
                $formatted = '-';
                if (!empty($raw) && $raw !== '0000-00-00 00:00:00') {
                    try {
                        $dt = \Carbon\Carbon::parse($raw);
                        $months = ['ene.', 'feb.', 'mar.', 'abr.', 'may.', 'jun.', 'jul.', 'ago.', 'sep.', 'oct.', 'nov.', 'dic.'];
                        $formatted = sprintf('%s %s %s', $dt->format('d'), $months[$dt->month - 1], $dt->format('Y'));  
                    } catch (\Throwable $e) {
                        $formatted = (string) $raw;
                    }
                }
                $row->fecha_accion = $formatted;
                $periodo = $this->formatPeriodo($row->servicio_periodo ?? null);
                if ($periodo !== '' && !str_ends_with((string) $row->servicio, ' - ' . $periodo)) {
                    $row->servicio = trim((string) $row->servicio . ' - ' . $periodo);
                }
                return $row;
            });

        $extraSections = [
            [
                'view' => 'serviciocliente.historial-servicio',
                'data' => [
                    'historialRows' => $historialRows
                ]
            ]
        ];

        return view('serviciocliente.servicio-cliente-form', [
            'title' => 'Editar Servicio Cliente',
            'moduleTitle' => 'Módulo Servicio Cliente',
            'mode' => 'edit',
            'formAction' => route('modules.servicio-cliente.update', $id),
            'backRoute' => $request->query('return_route') === 'modules.clientes'
                ? route('modules.clientes')
                : route('modules.servicio-cliente'),
            'return_route' => $request->query('return_route'),
            'record' => $record,
            'readOnly' => true,
            'fields' => $fields,
            'extraSections' => $extraSections,
            'clienteOptionMeta' => $this->clienteOptionMeta(),
            'servicioOptionMeta' => $this->servicioOptionMeta(),
            'vehiculosUrl' => route('modules.servicio-cliente.vehiculos'),
        ] + $this->prepareLockViewData(self::LOCK_RESOURCE, (string) $id));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $existingRecord = DB::table('serviciocliente')->where('idservicioCliente', $id)->first();
        if (!$existingRecord) {
            return redirect()->route('modules.servicio-cliente')->with('error', 'No se encontró el servicio solicitado.');
        }

        // Merge disabled inputs to prevent validation from failing
        if (!$request->has('cliente_idcliente')) {
            $request->merge(['cliente_idcliente' => $existingRecord->cliente_idcliente]);
        }
        if (!$request->has('almacen_idalmacen')) {
            $request->merge(['almacen_idalmacen' => $existingRecord->almacen_idalmacen]);
        }

        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, (string) $id, 'servicio cliente', 'modules.servicio-cliente')) {
            return $redirect;
        }

        $rules = [
            'cliente_idcliente' => [
                'required',
                'exists:cliente,idcliente',
                Rule::unique('serviciocliente')->ignore($id, 'idservicioCliente')->where(fn ($query) => $query
                    ->where('vehiculo_placa', $request->input('vehiculo_placa'))
                    ->where('almacen_idalmacen', $request->input('almacen_idalmacen'))),
            ],
            'dispositivoCliente_iddispositivoCliente' => [
                'required',
                'string',
                'max:20',
                function ($attribute, $value, $fail) use ($request, $id) {
                    $clienteId = $request->input('cliente_idcliente');
                    $currentDevice = DB::table('detalle_serviciodispositivo')
                        ->where('servicioCliente_idservicioCliente', $id)
                        ->orderBy('iddetalle_serviciodispositivo', 'desc')
                        ->value('dispositivoCliente_iddispositivoCliente');
                    if ($currentDevice !== null && $value === $currentDevice) {
                        return;
                    }
                    $stockDevice = DB::table('elementoalmacen')
                        ->where('imei', $value)
                        ->first();
                    if (!$stockDevice) {
                        $fail('El ID Dispositivo seleccionado no existe.');
                        return;
                    }
                    $estado = (int) $stockDevice->estado;
                    $inStock = in_array($estado, [1, 2, 4], true);
                    $isInactiveForClient = false;
                    if ($clienteId !== null && $clienteId !== '') {
                        $isInactiveForClient = DB::table('dispositivocliente as dc')
                            ->join('vehiculo as v', 'v.placa', '=', 'dc.vehiculo_placa')
                            ->where('dc.iddispositivoCliente', $value)
                            ->where('dc.estado', '0')
                            ->where('v.cliente_idcliente', $clienteId)
                            ->exists();
                    }
                    if (!$inStock && !$isInactiveForClient) {
                        $fail('El ID Dispositivo seleccionado no está disponible.');
                        return;
                    }
                    $existingDc = DB::table('dispositivocliente')
                        ->where('iddispositivoCliente', $value)
                        ->first();
                    if ($existingDc && (string) $existingDc->estado !== '0') {
                        $fail('El ID Dispositivo seleccionado ya está en uso por otro servicio activo.');
                        return;
                    }
                },
            ],
            'numeroTelefonico_numeroTelefonico' => [
                'required',
                'string',
                'exists:numerotelefonico,numeroTelefonico',
                Rule::exists('numerotelefonico', 'numeroTelefonico')->where(fn ($query) => $query->where('estado', '1')),
                Rule::exists('numerotelefonico', 'numeroTelefonico')->where(function ($query) use ($id) {
                    $currentDevice = DB::table('detalle_serviciodispositivo')
                        ->where('servicioCliente_idservicioCliente', $id)
                        ->orderBy('iddetalle_serviciodispositivo', 'desc')
                        ->value('dispositivoCliente_iddispositivoCliente');
                    $currentNumero = $currentDevice ? DB::table('detnumerosdispositivo')
                        ->where('dispositivoCliente_iddispositivoCliente', $currentDevice)
                        ->value('numeroTelefonico_numeroTelefonico') : null;

                    $query->where(function ($q) use ($currentNumero) {
                        $q->whereNotExists(function ($subquery) {
                            $subquery->select(DB::raw(1))
                                ->from('detnumerosdispositivo as dn')
                                ->whereColumn('dn.numeroTelefonico_numeroTelefonico', 'numerotelefonico.numeroTelefonico');
                        });
                        if ($currentNumero) {
                            $q->orWhere('numerotelefonico.numeroTelefonico', $currentNumero);
                        }
                    })->where(function ($q) use ($currentNumero) {
                        $q->whereExists(function ($subquery) {
                            $subquery->select(DB::raw(1))
                                ->from('detallesimcard as ds')
                                ->whereColumn('ds.numeroTelefonico_numeroTelefonico', 'numerotelefonico.numeroTelefonico')
                                ->where('ds.estado', '0');
                        });
                        if ($currentNumero) {
                            $q->orWhere('numerotelefonico.numeroTelefonico', $currentNumero);
                        }
                    });
                }),
                function ($attribute, $value, $fail) use ($id) {
                    $currentDevice = DB::table('detalle_serviciodispositivo')
                        ->where('servicioCliente_idservicioCliente', $id)
                        ->orderBy('iddetalle_serviciodispositivo', 'desc')
                        ->value('dispositivoCliente_iddispositivoCliente');
                    $currentNumero = $currentDevice ? DB::table('detnumerosdispositivo')
                        ->where('dispositivoCliente_iddispositivoCliente', $currentDevice)
                        ->value('numeroTelefonico_numeroTelefonico') : null;

                    if ($currentNumero !== null && $value === $currentNumero) {
                        return;
                    }

                    $exists = DB::table('detnumerosdispositivo')
                        ->where('numeroTelefonico_numeroTelefonico', $value)
                        ->exists();
                    if ($exists) {
                        $fail('El número telefónico ya está asignado a otro dispositivo.');
                    }
                }
            ],
            'vehiculo_placa' => [
                'required',
                Rule::exists('vehiculo', 'placa')->where(fn ($query) => $query->where('cliente_idcliente', $request->input('cliente_idcliente'))),
            ],
            'almacen_idalmacen' => ['required', 'exists:almacen,idalmacen'],
            'fechaInicio' => ['nullable', 'date_format:Y-m-d'],
            'fecheVencimiento' => ['nullable', 'date_format:Y-m-d'],
            'monto' => ['nullable', 'numeric', 'min:0'],
            'moneda_idmoneda' => ['nullable', 'exists:moneda,idmoneda'],
            'estado' => ['required', 'in:activo,inactivo'],
            'docReferencia' => ['nullable', 'string', 'max:15', 'regex:' . self::SAFE_TEXT_REGEX],
            'mantener_sim' => ['nullable', 'string', 'in:si,no'],
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
            'dispositivoCliente_iddispositivoCliente' => 'ID dispositivo',
            'numeroTelefonico_numeroTelefonico' => 'número telefónico',
            'mantener_sim' => 'mantener relación SIM',
        ];

        $validated = $request->validate($rules, $messages, $attributes);

        $validated['vehiculo_placa'] = Str::upper(trim($validated['vehiculo_placa']));
        $deviceId = trim((string) $request->input('dispositivoCliente_iddispositivoCliente'));
        $phoneNumber = trim((string) $request->input('numeroTelefonico_numeroTelefonico'));
        $fechaServicio = Carbon::now()->format('Y-m-d H:i:s');
        $usuario = (string) $request->session()->get('erp_auth.usuario', 'anonimo');

        try {
            DB::transaction(function () use ($validated, $id, $deviceId, $phoneNumber, $fechaServicio, $usuario, $request) {
                // Get old values
                $oldRecord = DB::table('serviciocliente as sc')
                    ->leftJoin('detalle_serviciodispositivo as dsd', 'dsd.servicioCliente_idservicioCliente', '=', 'sc.idservicioCliente')
                    ->leftJoin('detnumerosdispositivo as dnd', 'dnd.dispositivoCliente_iddispositivoCliente', '=', 'dsd.dispositivoCliente_iddispositivoCliente')
                    ->where('sc.idservicioCliente', $id)
                    ->select([
                        'sc.vehiculo_placa',
                        'dsd.dispositivoCliente_iddispositivoCliente as dispositivoCliente_iddispositivoCliente',
                        'dnd.numeroTelefonico_numeroTelefonico as numeroTelefonico_numeroTelefonico'
                    ])
                    ->first();

                $oldVehicle = $oldRecord->vehiculo_placa ?? null;
                $oldDevice = $oldRecord->dispositivoCliente_iddispositivoCliente ?? null;
                $oldNumero = $oldRecord->numeroTelefonico_numeroTelefonico ?? null;

                $deviceInfo = DB::table('elementoalmacen as ea')
                    ->join('almacen as a', 'a.idalmacen', '=', 'ea.dispositivo_iddispositivo')
                    ->leftJoin('modelo as mo', 'mo.idmodelo', '=', 'a.modelo_idmodelo')
                    ->leftJoin('marca as ma', 'ma.idmarca', '=', 'mo.marca_idmarca')
                    ->where('ea.imei', $deviceId)
                    ->select(['ea.imei', 'ma.nombreMarca', 'mo.nombreModelo', 'ea.estado'])
                    ->first();

                if ($deviceInfo) {
                    DB::table('dispositivocliente')->updateOrInsert(
                        ['iddispositivoCliente' => $deviceId],
                        [
                            'vehiculo_placa' => $validated['vehiculo_placa'],
                            'marcaDispositivo' => $deviceInfo->nombreMarca ?? null,
                            'modeloDispositivo' => $deviceInfo->nombreModelo ?? null,
                            'fechaInstalacion' => $fechaServicio,
                            'fechaBaja' => $validated['estado'] === 'inactivo' ? $fechaServicio : null,
                            'estado' => $validated['estado'] === 'activo' ? '1' : '0',
                        ]
                    );

                    DB::table('detnumerosdispositivo')->updateOrInsert(
                        ['dispositivoCliente_iddispositivoCliente' => $deviceId],
                        [
                            'numeroTelefonico_numeroTelefonico' => $phoneNumber,
                            'fechaAsignacion' => $fechaServicio,
                        ]
                    );

                    DB::table('detalle_serviciodispositivo')->updateOrInsert(
                        ['servicioCliente_idservicioCliente' => $id],
                        [
                            'vehiculo_placa' => $validated['vehiculo_placa'],
                            'dispositivoCliente_iddispositivoCliente' => $deviceId,
                            'fecha' => $fechaServicio,
                            'estado' => $validated['estado'] === 'activo' ? 1 : 0,
                            'observacion' => $validated['estado'] === 'inactivo' ? ($request->input('comentario_baja') ?? null) : null,
                        ]
                    );

                    if ($validated['estado'] === 'activo') {
                        $currentState = (int) $deviceInfo->estado;
                        if (in_array($currentState, [1, 2, 4], true)) {
                            $nextState = [1 => 6, 2 => 3, 4 => 5][$currentState];
                            DB::table('elementoalmacen')->where('imei', $deviceId)->update(['estado' => $nextState]);
                        }
                    }
                }

                // If device changed, release the old device
                if ($oldDevice !== null && $oldDevice !== $deviceId) {
                    DB::table('dispositivocliente')
                        ->where('iddispositivoCliente', $oldDevice)
                        ->update(['estado' => '0', 'fechaBaja' => $fechaServicio]);
                    
                    DB::table('elementoalmacen')
                        ->where('imei', $oldDevice)
                        ->update(['estado' => 0]);

                    DB::table('historial_servicio')->insert([
                        'usuario_usuario' => $usuario,
                        'servicioCliente_idservicioCliente' => $id,
                        'fecha_accion' => $fechaServicio,
                        'motivo' => 'Cambio de dispositivo',
                        'descripcion' => "Cambio de ID Dispositivo de {$oldDevice} a {$deviceId}",
                        'doc_referencia' => $validated['docReferencia'] ?? null,
                    ]);
                }

                // If vehicle changed (and device remained the same, update its placement details)
                if ($oldVehicle !== null && $oldVehicle !== $validated['vehiculo_placa']) {
                    DB::table('dispositivocliente')
                        ->where('iddispositivoCliente', $deviceId)
                        ->update(['vehiculo_placa' => $validated['vehiculo_placa']]);

                    DB::table('historial_servicio')->insert([
                        'usuario_usuario' => $usuario,
                        'servicioCliente_idservicioCliente' => $id,
                        'fecha_accion' => $fechaServicio,
                        'motivo' => 'Cambio de vehiculo',
                        'descripcion' => "Cambio de vehículo de {$oldVehicle} a {$validated['vehiculo_placa']}",
                        'doc_referencia' => $validated['docReferencia'] ?? null,
                    ]);
                }

                // If phone number changed
                if ($oldNumero !== null && $oldNumero !== $phoneNumber) {
                    $mantenerSim = $request->input('mantener_sim', 'si');

                    if ($mantenerSim === 'no') {
                        // Break relationship between old number and its SIM card
                        $activeSimPair = DB::table('detallesimcard')
                            ->where('numeroTelefonico_numeroTelefonico', $oldNumero)
                            ->where('estado', '0')
                            ->first();

                        if ($activeSimPair) {
                            DB::table('detallesimcard')
                                ->where('iddetalleSimCard', $activeSimPair->iddetalleSimCard)
                                ->update(['estado' => '1']); // broken/inactive relationship

                            // Keep SIM card active
                            DB::table('simcard')
                                ->where('idsimCard', $activeSimPair->simCard_idsimCard)
                                ->update(['estado' => '1']);
                        }
                    }

                    // Keep old phone number active (free for reuse)
                    DB::table('numerotelefonico')
                        ->where('numeroTelefonico', $oldNumero)
                        ->update(['estado' => '1']);

                    DB::table('historial_servicio')->insert([
                        'usuario_usuario' => $usuario,
                        'servicioCliente_idservicioCliente' => $id,
                        'fecha_accion' => $fechaServicio,
                        'motivo' => 'Cambio de numero',
                        'descripcion' => "Cambio de número telefónico de {$oldNumero} a {$phoneNumber} (Mantener relación SIM: " . ($mantenerSim === 'si' ? 'Sí' : 'No') . ")",
                        'doc_referencia' => $validated['docReferencia'] ?? null,
                    ]);
                }

                unset($validated['dispositivoCliente_iddispositivoCliente'], $validated['numeroTelefonico_numeroTelefonico'], $validated['mantener_sim']);
                DB::table('serviciocliente')->where('idservicioCliente', $id)->update($validated);

                if ($validated['estado'] === 'inactivo') {
                    $activeSimPair = DB::table('detallesimcard')
                        ->where('numeroTelefonico_numeroTelefonico', $phoneNumber)
                        ->where('estado', '0')
                        ->first();

                    if ($activeSimPair) {
                        DB::table('detallesimcard')
                            ->where('iddetalleSimCard', $activeSimPair->iddetalleSimCard)
                            ->update(['estado' => '1']);

                        DB::table('simcard')
                            ->where('idsimCard', $activeSimPair->simCard_idsimCard)
                            ->update(['estado' => '0']);
                    }

                    DB::table('numerotelefonico')
                        ->where('numeroTelefonico', $phoneNumber)
                        ->update(['estado' => '1']);

                    if ($deviceInfo) {
                        $latestStockDevice = DB::table('elementoalmacen')->where('imei', $deviceId)->first();
                        if ($latestStockDevice) {
                            $deviceState = (int) $latestStockDevice->estado;
                            if ($deviceState === 3 || $deviceState === 2) {
                                DB::table('elementoalmacen')
                                    ->where('imei', $deviceId)
                                    ->update(['estado' => 2]);
                            } elseif (in_array($deviceState, [0, 5, 6], true)) {
                                DB::table('elementoalmacen')
                                    ->where('imei', $deviceId)
                                    ->update(['estado' => 0]);
                            }
                        }
                    }

                    DB::table('historial_servicio')->insert([
                        'usuario_usuario' => $usuario,
                        'servicioCliente_idservicioCliente' => $id,
                        'fecha_accion' => $fechaServicio,
                        'motivo' => 'Dado de baja',
                        'descripcion' => $request->input('comentario_baja') ?? 'Servicio dado de baja',
                        'doc_referencia' => $validated['docReferencia'] ?? null,
                    ]);
                }
            });
        } catch (\Exception $exception) {
            return redirect()->back()->withInput()->with('error', 'No se pudo actualizar el servicio cliente. Intente de nuevo.');
        }

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

    public function vehiculos(Request $request): JsonResponse
    {
        $cliente = trim((string) $request->query('cliente_idcliente', ''));

        return response()->json($cliente === '' ? [] : $this->vehiculoOptions($cliente));
    }

    public function dispositivos(Request $request): JsonResponse
    {
        $cliente = trim((string) $request->query('cliente_idcliente', ''));

        return response()->json($cliente === '' ? [] : $this->dispositivoOptions($cliente));
    }

    public function servicios(Request $request): JsonResponse
    {
        $vehiculo = trim((string) $request->query('vehiculo_placa', ''));

        return response()->json($this->servicioOptionsForVehicle($vehiculo));
    }

    private function applyIndexFilters(Request $request, $query)
    {
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
                    ->orWhere('a.detalle', 'like', $term)
                    ->orWhere('p.nombrePlataforma', 'like', $term);
            });
        }

        $clienteText = trim((string) $request->input('cliente_idcliente', ''));
        if ($clienteText !== '') {
            $term = '%' . $clienteText . '%';
            $query->where(function ($builder) use ($term) {
                $builder
                    ->where('sc.cliente_idcliente', 'like', $term)
                    ->orWhere('c.nombreComercial', 'like', $term)
                    ->orWhere('c.razonSocial', 'like', $term);
            });
        }

        $almacenText = trim((string) $request->input('almacen_idalmacen', ''));
        if ($almacenText !== '') {
            $query->where('a.detalle', 'like', '%' . $almacenText . '%');
        }

        $vehiculoText = trim((string) $request->input('vehiculo', ''));
        if ($vehiculoText !== '') {
            $term = '%' . $vehiculoText . '%';
            $query->where(function ($builder) use ($term) {
                $builder
                    ->where('sc.vehiculo_placa', 'like', $term)
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

        return $query;
    }

    private function baseQuery()
    {
        return DB::table('serviciocliente as sc')
            ->leftJoin('cliente as c', 'c.idcliente', '=', 'sc.cliente_idcliente')
            ->leftJoin('vehiculo as v', 'v.placa', '=', 'sc.vehiculo_placa')
            ->leftJoin('almacen as a', 'a.idalmacen', '=', 'sc.almacen_idalmacen')
            ->leftJoin('tipoelemento as te', 'te.idtipoElemento', '=', 'a.tipoElemento_idtipoElemento')
            ->leftJoin('plataforma as p', 'p.idplataforma', '=', 'te.plataforma_idplataforma')
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
                DB::raw("CONCAT(COALESCE(c.nombreComercial, c.razonSocial, c.idcliente), CASE WHEN LOWER(REPLACE(TRIM(COALESCE(c.flag_integrador, '')), 'í', 'i')) IN ('si', '1', 'true', 'on', 'yes', 'y') THEN ' (Integrador)' ELSE '' END) as cliente_nombre"),
                DB::raw('COALESCE(v.marca, "") as vehiculo_marca'),
                DB::raw('COALESCE(v.modelo, "") as vehiculo_modelo'),
                DB::raw('COALESCE(a.detalle, "") as almacen_detalle'),
                DB::raw('COALESCE(p.nombrePlataforma, "") as plataforma'),
                'a.periodo as almacen_periodo',
                DB::raw('COALESCE(m.simbolo, "") as moneda_simbolo'),
                DB::raw('COALESCE(m.detalle, "") as moneda_detalle'),
                DB::raw('(select dsd.dispositivoCliente_iddispositivoCliente from detalle_serviciodispositivo as dsd where dsd.servicioCliente_idservicioCliente = sc.idservicioCliente order by dsd.iddetalle_serviciodispositivo desc limit 1) as dispositivoCliente_iddispositivoCliente'),
                DB::raw('(select dnd.numeroTelefonico_numeroTelefonico from detnumerosdispositivo as dnd where dnd.dispositivoCliente_iddispositivoCliente = (select dsd.dispositivoCliente_iddispositivoCliente from detalle_serviciodispositivo as dsd where dsd.servicioCliente_idservicioCliente = sc.idservicioCliente order by dsd.iddetalle_serviciodispositivo desc limit 1) order by dnd.iddetNumerosDispositivo desc limit 1) as numeroTelefonico_numeroTelefonico'),
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
                'flag_integrador',
                DB::raw('COALESCE(nombreComercial, razonSocial, idcliente) as cliente_label'),
            ])
            ->orderBy('cliente_label')
            ->get()
            ->map(function ($option) {
                $integratorValue = strtolower(str_replace('í', 'i', trim((string) $option->flag_integrador)));
                if (in_array($integratorValue, ['si', '1', 'true', 'on', 'yes', 'y'], true)) {
                    $option->cliente_label .= ' (Integrador)';
                }

                return $option;
            });
    }

    private function dispositivoOptions(?string $cliente = null, ?string $currentDevice = null): array
    {
        $query = DB::table('elementoalmacen as ea')
            ->join('almacen as a', 'a.idalmacen', '=', 'ea.dispositivo_iddispositivo')
            ->leftJoin('modelo as mo', 'mo.idmodelo', '=', 'a.modelo_idmodelo')
            ->leftJoin('marca as ma', 'ma.idmarca', '=', 'mo.marca_idmarca');

        if ($cliente !== null && $cliente !== '') {
            $query->where(function ($q) use ($cliente, $currentDevice) {
                $q->whereIn('ea.estado', [1, 2, 4]);
                if ($currentDevice !== null && $currentDevice !== '') {
                    $q->orWhere('ea.imei', $currentDevice);
                }
                $q->orWhere(function ($sub) use ($cliente) {
                    $sub->whereExists(function ($existsQuery) use ($cliente) {
                        $existsQuery->select(DB::raw(1))
                            ->from('dispositivocliente as dc')
                            ->join('vehiculo as v', 'v.placa', '=', 'dc.vehiculo_placa')
                            ->whereColumn('dc.iddispositivoCliente', 'ea.imei')
                            ->where('dc.estado', '0')
                            ->where('v.cliente_idcliente', $cliente);
                    });
                });
            });
        } else {
            $query->where(function ($q) use ($currentDevice) {
                $q->whereIn('ea.estado', [1, 2, 4]);
                if ($currentDevice !== null && $currentDevice !== '') {
                    $q->orWhere('ea.imei', $currentDevice);
                }
            });
        }

        return $query->select(['ea.imei', 'a.detalle'])
            ->orderBy('ea.imei')
            ->get()
            ->mapWithKeys(function ($item): array {
                $label = trim(implode(' - ', array_filter([
                    (string) $item->imei,
                    trim((string) ($item->detalle ?? '')),
                ])));

                return [(string) $item->imei => $label];
            })
            ->all();
    }

    private function servicioOptionsForVehicle(string $vehiculo): array
    {
        $usedServices = $vehiculo === '' ? [] : DB::table('serviciocliente')
            ->where('vehiculo_placa', $vehiculo)
            ->pluck('almacen_idalmacen')
            ->map(fn ($id) => (string) $id)
            ->all();

        return $this->almacenOptions()
            ->map(function ($option) use ($usedServices): array {
                $id = (string) $option->idalmacen;
                $label = trim((string) $option->detalle);

                return [
                    'value' => $id,
                    'text' => $label,
                    'disabled' => in_array($id, $usedServices, true),
                ];
            })
            ->values()
            ->all();
    }

    private function clienteEsIntegrador(string $cliente): bool
    {
        $value = DB::table('cliente')->where('idcliente', $cliente)->value('flag_integrador');
        $normalized = strtolower(str_replace('í', 'i', trim((string) $value)));

        return in_array($normalized, ['si', '1', 'true', 'on', 'yes', 'y'], true);
    }

    private function numeroTelefonicoOptions(?string $currentNumero = null): array
    {
        return DB::table('numerotelefonico as nt')
            ->where(function ($q) use ($currentNumero) {
                $q->where('nt.estado', '1');
                if ($currentNumero !== null && $currentNumero !== '') {
                    $q->orWhere('nt.numeroTelefonico', $currentNumero);
                }
            })
            ->where(function ($q) use ($currentNumero) {
                $q->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('detnumerosdispositivo as dn')
                        ->whereColumn('dn.numeroTelefonico_numeroTelefonico', 'nt.numeroTelefonico');
                });
                if ($currentNumero !== null && $currentNumero !== '') {
                    $q->orWhere('nt.numeroTelefonico', $currentNumero);
                }
            })
            ->where(function ($q) use ($currentNumero) {
                $q->whereExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('detallesimcard as ds')
                        ->whereColumn('ds.numeroTelefonico_numeroTelefonico', 'nt.numeroTelefonico')
                        ->where('ds.estado', '0');
                });
                if ($currentNumero !== null && $currentNumero !== '') {
                    $q->orWhere('nt.numeroTelefonico', $currentNumero);
                }
            })
            ->orderBy('nt.numeroTelefonico')
            ->pluck('nt.numeroTelefonico', 'nt.numeroTelefonico')
            ->all();
    }

    private function vehiculoOptions(?string $cliente = null)
    {
        $query = DB::table('vehiculo as v')
            ->select([
                'v.placa',
                DB::raw("CONCAT(v.placa, CASE WHEN COALESCE(v.marca, '') <> '-' OR COALESCE(v.modelo, '') <> '-' THEN CONCAT(' - ', COALESCE(v.marca, ''), '-', COALESCE(v.modelo, '')) ELSE '' END) as vehiculo_label"),
            ]);

        if ($cliente !== null && $cliente !== '') {
            $query->where('v.cliente_idcliente', $cliente);
        }

        return $query->orderBy('v.placa')->get();
    }

    private function almacenOptions()
    {
        return DB::table('almacen as a')
            ->leftJoin('tipoelemento as te', 'te.idtipoElemento', '=', 'a.tipoElemento_idtipoElemento')
            ->select(['a.idalmacen', 'a.detalle', 'a.periodo', 'te.nombre as tipo_elemento_nombre'])
            ->where(function ($query) {
                $query
                    ->whereRaw("LOWER(TRIM(COALESCE(te.nombre, ''))) LIKE '%plan%'")
                    ->orWhereRaw("LOWER(TRIM(COALESCE(te.nombre, ''))) LIKE '%servicio%'");
            })
            ->orderBy('a.detalle')
            ->orderBy('a.periodo')
            ->get()
            ->map(function ($option) {
                $detalle = trim((string) ($option->detalle ?? ''));
                $periodo = $this->formatPeriodo($option->periodo);

                $option->detalle = $periodo !== ''
                    ? trim($detalle . ' - ' . $periodo)
                    : $detalle;

                return $option;
            })
            ;
    }

    private function formatPeriodo(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '' || trim((string) $value) === 'No') {
            return '';
        }

        if (!is_numeric($value)) {
            return trim((string) $value);
        }

        return match ((int) $value) {
            30 => 'Mensual',
            90 => '3 Meses',
            180 => '6 Meses',
            365 => '12 Meses',
            730 => '24 Meses',
            1095 => '36 Meses',
            1460 => '48 Meses',
            default => trim((string) $value),
        };
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

    private function defaultMonedaId(): mixed
    {
        return DB::table('moneda')
            ->where(function ($query) {
                $query->whereRaw("LOWER(detalle) LIKE '%sol%'")
                    ->orWhereRaw("LOWER(simbolo) = 's/'");
            })
            ->value('idmoneda');
    }

    private function clienteOptionMeta()
    {
        return DB::table('cliente')
            ->select(['idcliente', 'flag_integrador'])
            ->get()
            ->mapWithKeys(fn ($item) => [(string) $item->idcliente => in_array(strtolower(str_replace('í', 'i', trim((string) $item->flag_integrador))), ['si', '1', 'true', 'on', 'yes', 'y'], true)])
            ->all();
    }

    private function servicioOptionMeta()
    {
        return DB::table('almacen as a')
            ->leftJoin('tipoelemento as te', 'te.idtipoElemento', '=', 'a.tipoElemento_idtipoElemento')
            ->where(function ($query) {
                $query->whereRaw("LOWER(TRIM(COALESCE(te.nombre, ''))) LIKE '%plan%'")
                    ->orWhereRaw("LOWER(TRIM(COALESCE(te.nombre, ''))) LIKE '%servicio%'");
            })
            ->select(['a.idalmacen', 'a.precio', 'a.periodo'])
            ->get()
            ->mapWithKeys(fn ($item) => [(string) $item->idalmacen => [
                'precio' => $item->precio,
                'periodo' => $item->periodo,
            ]])
            ->all();
    }
}