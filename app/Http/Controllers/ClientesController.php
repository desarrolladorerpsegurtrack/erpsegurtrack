<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Permission\HandlesResourceLock;
use App\Http\Controllers\Export\ExportableList;
use App\Services\ClienteService;
use App\Support\ErpPermission;
use App\Support\ResourceLock;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientesController extends Controller
{
    use ExportableList;
    use HandlesResourceLock;

    private ClienteService $clienteService;

    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';

    public function __construct(ClienteService $clienteService)
    {
        $this->clienteService = $clienteService;
    }

    public function index(Request $request): View
    {
        $clientes = $this->clienteService->getClientList($request, $this->resolvePerPage($request));
        $stats = $this->clienteService->getClientStatistics($request);
        $estados = $this->clienteService->getEstados();
        $grupos = $this->clienteService->getGrupos();

        return view('cliente.clientes', [
            'title' => 'Módulo Clientes',
            'singularTitle' => 'Cliente',
            'items' => $clientes,
            'stats' => $stats,
            'columns' => [
                ['key' => 'idcliente', 'label' => 'RUC/DNI', 'type' => 'text'],
                ['key' => 'nombreComercial', 'label' => 'Nombre Comercial', 'type' => 'text', 'wrap' => true],
                ['key' => 'razonSocial', 'label' => 'Razón Social', 'type' => 'text', 'wrap' => true],
                ['key' => 'grupo_asignado', 'label' => 'Grupo Asignado', 'type' => 'text'],
                ['key' => 'rubro', 'label' => 'Rubro', 'type' => 'text'],
                [
                    'key' => 'direccion_completa',
                    'label' => 'Dirección',
                    'type' => 'custom', // Para permitir HTML (salto de línea)
                    'wrap' => true,
                ],
                ['key' => 'estadoDetalle', 'label' => 'Estado', 'type' => 'status'],
            ],
            'filters' => [
                [
                    'name' => 'idcliente',
                    'label' => 'RUC/DNI',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por RUC o DNI',
                ],
                [
                    'name' => 'nombre',
                    'label' => 'Nombre Comercial',
                    'type' => 'text',
                    'placeholder' => 'Nombre comercial o razón social',
                ],
                [
                    'name' => 'grupo',
                    'label' => 'Grupo',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por grupo',
                ],
                [
                    'name' => 'rubro',
                    'label' => 'Rubro',
                    'type' => 'text',
                    'placeholder' => 'Filtrar por rubro',
                ],
                [
                    'name' => 'estado',
                    'label' => 'Estado',
                    'options' => $estados->map(fn($estado) => [
                        'value' => (string) $estado->idestadoCliente,
                        'label' => $estado->detalle,
                    ])->all(),
                    'placeholder' => 'Todos los estados',
                ],
                
            ],
            'createRoute' => route('modules.clientes.create'),
            'editRoute' => 'modules.clientes.edit',
            'relationPanelView' => 'cliente.relation-panel',
            'showRoute' => 'modules.clientes.edit',
            'destroyRoute' => 'modules.clientes.destroy',
            'lockResource' => 'clientes',
            'exportRoutes' => [
                'pdf' => route('modules.clientes.export', ['format' => 'pdf']),
                'xlsx' => route('modules.clientes.export', ['format' => 'xlsx']),
            ],
            'identifierKey' => 'idcliente',
        ]);
    }

    public function export(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        // Verificar si hay IDs seleccionados (exportación de selección múltiple)
        $selectedIds = $request->input('selectedIds', []);

        $filename = 'clientes_selection_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds)) {
            // Obtener grupos por cliente (cliente + servicios + vehiculos + dispositivos)
            $groups = $this->clienteService->getSelectedClientExportGroups(array_values((array) $selectedIds));

            if ($format === 'xlsx') {
                return $this->exportSelectedXlsxResponse($groups, $filename);
            }

            return $this->exportSelectedPdfResponse($groups, $filename);
        }

        // Exportar todos los filtrados (compatibilidad hacia atrás)
        $rows = $this->clienteService->getClientExportRows($request);
        $columns = $this->clienteService->getExportColumns();
        $filename = 'clientes_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Clientes', $filename);
    }

    public function create(Request $request): View
    {
        $estados = $this->clienteService->getEstados();
        $direcciones = $this->clienteService->getDirecciones();
        $grupos = $this->clienteService->getGrupos();
        $ubigeos = $this->clienteService->getUbigeos();
        $currentUser = request()->session()->get('erp_auth.usuario');
        $allowedContactTypes = $this->clienteService->getAllowedContactTypes($currentUser);
        $tiposContacto = $this->clienteService->getTiposContacto($allowedContactTypes);        
        $contactosPayload = old('contactos_payload', '[]');
        $credencialesPayload = old('credenciales_payload', '[]');
        $canSeeCredencialesField = $this->currentUserCanViewCredenciales(request());

        return view('cliente.clientes-form', [
            'title' => 'Nuevo Cliente',
            'moduleTitle' => 'Módulo Clientes',
            'mode' => 'create',
            'readOnly' => false,
            'formAction' => route('modules.clientes.store'),
            'backRoute' => route('modules.clientes'),
            'return_route' => $request->query('return_route'),
            'record' => null,
            'canSeeCredencialesField' => $canSeeCredencialesField,
            'fields' => [
                [
                    'name' => 'tipoCliente',
                    'type' => 'select',
                    'label' => 'Tipo Cliente',
                    'required' => true,
                    'options' => [
                        '0' => 'Persona Natural',
                        '1' => 'Empresa',
                    ],
                    'placeholder' => 'Selecciona tipo de cliente',
                    'helpText' => 'Elige Empresa o Persona Natural para validar RUC o DNI.',
                ],
                [
                    'name' => 'idcliente',
                    'type' => 'text',
                    'label' => 'Cliente',
                    'required' => true,
                    'maxlength' => 11,
                    'minlength' => 0,
                    'pattern' => '^[0-9]*$',
                    'inputmode' => 'numeric',
                    'helpText' => 'Selecciona tipo de cliente primero.',
                    'placeholder' => 'Cliente',
                    'disabled' => !((string) old('tipoCliente') !== ''),
                ],
                [
                    'name' => 'razonSocial',
                    'type' => 'text',
                    'label' => 'Razón Social',
                    'required' => false,
                    'maxlength' => 200,
                    'minlength' => 2,
                    'pattern' => '[A-Za-z0-9ÁÉÍÓÚáéíóúÑñ\s\.,\-&]{2,}',
                    'helpText' => 'Mínimo 2 caracteres.',
                    'colSpan' => 2,
                ],
                [
                    'name' => 'nombreComercial',
                    'type' => 'text',
                    'label' => 'Nombre Comercial',
                    'required' => false,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'pattern' => '[A-Za-z0-9ÁÉÍÓÚáéíóúÑñ\s\.,\-&]{2,}',
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'rubro',
                    'type' => 'text',
                    'label' => 'Rubro',
                    'required' => false,
                    'maxlength' => 50,
                    'helpText' => 'Selecciona o escribe un rubro.',
                    'datalist' => 'rubro-options',
                    'datalistOptions' => [
                        'AUTOMOTRIZ',
                        'CONSTRUCCIÓN',
                        'CONSULTORÍA',
                        'EDUCACIÓN',
                        'INMOBILIARIA',
                        'LOGÍSTICA',
                        'MANUFACTURA',
                        'MINERÍA',
                        'SALUD',
                        'TECNOLOGÍA',
                        'TRANSPORTE',
                    ],
                ],
                [
                    'name' => 'fechaIngreso',
                    'type' => 'date',
                    'label' => 'Fecha de Ingreso',
                    'required' => false,
                    'value' => now()->format('Y-m-d'),
                ],
                [
                    'name' => 'fechaBaja',
                    'type' => 'date',
                    'label' => 'Fecha de Baja',
                    'required' => false,
                ],
                [
                    'name' => 'estadoCliente_idestadoCliente',
                    'type' => 'select',
                    'label' => 'Estado del Cliente',
                    'required' => true,
                    'tomSelect' => true,
                    'optionsData' => $estados,
                    'optionKey' => 'idestadoCliente',
                    'optionLabel' => 'detalle',
                    'placeholder' => 'Selecciona estado',
                ],
                [
                    'name' => 'direccionCliente_iddireccionCliente',
                    'type' => 'select',
                    'label' => 'Dirección del Cliente',
                    'required' => true,
                    'tomSelect' => true,
                    'optionsData' => $direcciones,
                    'optionKey' => 'iddireccionCliente',
                    'optionLabel' => 'label_completo',
                    'placeholder' => 'Selecciona dirección',
                    'quickCreate' => true,
                    'quickCreateListUrl' => route('modules.clientes.direcciones.opciones'),
                    'quickCreateStoreUrl' => '',
                    'quickCreateUpdateUrlTemplate' => '',
                    'quickCreateDeleteUrlTemplate' => '',
                    'quickCreatePayloadInput' => 'direcciones_payload',
                    'quickCreateUbigeos' => $ubigeos->map(fn($ubigeo) => [
                        'id' => (int) $ubigeo->idubigeo,
                        'label' => trim(((string) $ubigeo->idubigeo) . '-' . ($ubigeo->ubigeo_text ?? '')),
                    ])->values()->all(),
                ],
                [
                    'name' => 'contactoSeleccionado',
                    'type' => 'select',
                    'label' => 'Contacto del Cliente',
                    'required' => false,
                    'tomSelect' => true,
                    'options' => [],
                    'placeholder' => 'Selecciona contacto',
                    'quickCreateContact' => true,
                    'quickContactMode' => 'create',
                    'quickContactTipos' => $tiposContacto->map(fn($tipo) => [
                        'id' => (int) $tipo->idtipoContacto,
                        'label' => (string) ($tipo->detalle ?? ''),
                    ])->values()->all(),
                    'quickContactPayloadInput' => 'contactos_payload',
                    'quickContactListUrl' => null,
                    'quickContactStoreUrl' => null,
                    'quickContactUpdateUrlTemplate' => null,
                    'quickContactDeleteUrlTemplate' => null,
                ],
                [
                    'name' => 'credencialSeleccionada',
                    'type' => 'select',
                    'label' => 'Credencial del Cliente',
                    'required' => false,
                    'tomSelect' => true,
                    'options' => [],
                    'placeholder' => 'Selecciona credencial',
                    'quickCreateCredential' => true,
                    'quickCredentialMode' => 'create',
                    'quickCredentialPayloadInput' => 'credenciales_payload',
                    'quickCredentialListUrl' => null,
                    'quickCredentialStoreUrl' => null,
                    'quickCredentialUpdateUrlTemplate' => null,
                    'quickCredentialDeleteUrlTemplate' => null,
                ],
                [
                    'name' => 'contactos_payload',
                    'type' => 'hidden',
                    'value' => is_string($contactosPayload) ? $contactosPayload : '[]',
                ],
                [
                    'name' => 'direcciones_payload',
                    'type' => 'hidden',
                    'value' => '[]',
                ],
                [
                    'name' => 'credenciales_payload',
                    'type' => 'hidden',
                    'value' => is_string($credencialesPayload) ? $credencialesPayload : '[]',
                ],
                [
                    'name' => 'grupoCliente_idgrupoCliente',
                    'type' => 'select',
                    'label' => 'Grupo del Cliente',
                    'required' => false,
                    'tomSelect' => true,
                    'optionsData' => $grupos,
                    'optionKey' => 'idgrupoCliente',
                    'optionLabel' => 'nombreGrupo',
                    'placeholder' => 'Selecciona grupo',
                ],
                [
                    'name' => 'detraccion',
                    'type' => 'switch',
                    'label' => 'Retencion',
                    'required' => false,
                    'options' => [
                        '1' => 'No',
                    ],
                    'switchLabels' => [
                        'off' => 'No',
                        'on' => 'Sí',
                    ],
                    'value' => [],
                ],
                [
                    'name' => 'flag_integrador',
                    'type' => 'switch',
                    'label' => 'Integrador',
                    'required' => false,
                    'options' => [
                        '1' => 'No',
                    ],
                    'switchLabels' => [
                        'off' => 'No',
                        'on' => 'Sí',
                    ],
                    'value' => [],
                ],
            ],
        ]);
    }

    private function currentUserCanViewCredenciales(Request $request): bool
    {
        $authData = $request->session()->get('erp_auth', []);
        $userRoles = collect($authData['roles'] ?? [])
            ->map(fn($role) => mb_strtolower(trim((string) $role)))
            ->filter();

        if ($userRoles->contains('admin')) {
            return true;
        }

        $actions = collect($authData['permissions']['clientes.credenciales'] ?? [])
            ->map(fn($action) => ErpPermission::normalizeAction((string) $action))
            ->filter()
            ->unique();

        return $actions->contains('ver');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tipoCliente' => ['required', 'in:0,1'],
            'idcliente' => [
                'required',
                Rule::when($request->input('tipoCliente') === '0', ['digits:8']),
                Rule::when($request->input('tipoCliente') === '1', ['digits:11']),
                'unique:cliente,idcliente',
            ],
            'razonSocial' => ['nullable', 'string', 'min:2', 'max:200', 'regex:' . self::SAFE_TEXT_REGEX],
            'nombreComercial' => ['nullable', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'fechaIngreso' => ['nullable', 'date'],
            'fechaBaja' => ['nullable', 'date', 'after_or_equal:fechaIngreso'],
            'rubro' => ['nullable', 'string', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'estadoCliente_idestadoCliente' => ['required', 'integer', 'exists:estadocliente,idestadoCliente'],
            'direccionCliente_iddireccionCliente' => ['required', 'string', 'max:60', 'regex:/^(tmp-\d+|\d+)$/'],
            'grupoCliente_idgrupoCliente' => ['nullable', 'integer', 'exists:grupocliente,idgrupoCliente'],
            'detraccion' => ['nullable'],
            'detraccion.*' => ['in:1'],
            'flag_integrador' => ['nullable'],
            'contactoSeleccionado' => ['nullable', 'string', 'max:60', 'regex:/^(tmp-\d+|\d+)$/'],
            'credencialSeleccionada' => ['nullable', 'string', 'max:60', 'regex:/^(tmp-\d+|\d+)$/'],
            'contactos_payload' => ['nullable', 'string'],
            'direcciones_payload' => ['nullable', 'string'],
            'credenciales_payload' => ['nullable', 'string'],
        ], [
            'idcliente.unique' => 'El cliente ya está registrado.',
            'idcliente.digits' => 'El cliente debe tener 8 dígitos si es persona natural o 11 si es empresa.',
            'rubro.max' => 'El rubro no debe tener más de 50 caracteres.',
            'direccionCliente_iddireccionCliente' => 'La dirección seleccionada no es válida.',
            'direc'
        ]);

        $grupoId = $validated['grupoCliente_idgrupoCliente'] ?? null;
        $selectedAddressId = $validated['direccionCliente_iddireccionCliente'] ?? null;
        $selectedContactId = $validated['contactoSeleccionado'] ?? null;
        $selectedCredencialId = $validated['credencialSeleccionada'] ?? null;
        $contactosPayload = (string) ($validated['contactos_payload'] ?? '[]');
        $direccionesPayload = (string) ($validated['direcciones_payload'] ?? '[]');
        $credencialesPayload = (string) ($validated['credenciales_payload'] ?? '[]');
        $detr = $validated['detraccion'] ?? null;
        $validated['detraccion'] = (
            (is_array($detr) && in_array('1', $detr, true))
            || $detr === '1' || $detr === 1 || $detr === 'on' || $detr === true
        ) ? '1' : '0';

        $fi = $validated['flag_integrador'] ?? null;
        $validated['flag_integrador'] = (
            (is_array($fi) && in_array('1', $fi, true))
            || $fi === '1' || $fi === 1 || $fi === 'on' || $fi === true
        ) ? '1' : '0';
        unset($validated['grupoCliente_idgrupoCliente'], $validated['contactoSeleccionado'], $validated['credencialSeleccionada'], $validated['contactos_payload'], $validated['direcciones_payload'], $validated['credenciales_payload'], $validated['direccionCliente_iddireccionCliente']);

        DB::transaction(function () use ($validated, $grupoId, $contactosPayload, $direccionesPayload, $credencialesPayload, &$selectedAddressId, $selectedContactId, $selectedCredencialId): void {
            DB::table('cliente')->insert($validated);

            if ($grupoId) {
                $maxId = DB::table('detalleGrupoCliente')->max('iddetalleGrupoCliente') ?? 0;
                DB::table('detalleGrupoCliente')->insert([
                    'iddetalleGrupoCliente' => $maxId + 1,
                    'grupoCliente_idgrupoCliente' => $grupoId,
                    'cliente_idcliente' => $validated['idcliente'],
                ]);
            }

            $this->clienteService->insertContactosTemporales($validated['idcliente'], $contactosPayload, $selectedContactId);
            $this->clienteService->insertDireccionesTemporales($validated['idcliente'], $direccionesPayload, $selectedAddressId);
            $this->clienteService->insertCredencialesTemporales($validated['idcliente'], $credencialesPayload, $selectedCredencialId);

            if ($selectedAddressId !== null) {
                $this->clienteService->syncClientAddress($validated['idcliente'], $selectedAddressId);
            }

            if ($selectedContactId !== null) {
                $this->clienteService->syncClientContact($validated['idcliente'], $selectedContactId);
            }
        });

        $returnRoute = $request->input('return_route');
        if ($returnRoute === 'modules.ventas.cotizaciones.create') {
            return redirect()
                ->route($returnRoute, ['cliente_idcliente' => $validated['idcliente']])
                ->with('success', 'Cliente creado correctamente.');
        }

        return redirect()
            ->route('modules.clientes')
            ->with('success', 'Cliente creado correctamente.');
    }

    public function edit(Request $request, string $cliente): View|RedirectResponse
    {
        $record = DB::table('cliente')->where('idcliente', $cliente)->first();

        if (!$record) {
            return redirect()
                ->route('modules.clientes')
                ->with('error', 'No se encontro el cliente solicitado.');
        }

        $currentUser = $request->session()->get('erp_auth.usuario', 'anonimo');
        $lockInfo = ResourceLock::status('clientes', $cliente);
        $lockOwner = $lockInfo['usuario'] ?? null;
        $lockBlocked = $lockInfo && $lockOwner !== $currentUser;

        return view('cliente.clientes-form', $this->buildClienteEditViewData(
            $request,
            $cliente,
            $record,
            $currentUser,
            $lockInfo,
            $lockOwner,
            $lockBlocked
        ));
    }

    private function buildClienteEditViewData(
        Request $request,
        string $cliente,
        object $record,
        string $currentUser,
        ?array $lockInfo,
        ?string $lockOwner,
        bool $lockBlocked
    ): array {
        $record->direccionCliente_iddireccionCliente = DB::table('direccioncliente')
            ->where('cliente_idcliente', $cliente)
            ->orderByDesc('default')
            ->orderByDesc('iddireccionCliente')
            ->value('iddireccionCliente');

        $estados = $this->clienteService->getEstados();
        $direcciones = $this->clienteService->getDirecciones($cliente);
        $grupos = $this->clienteService->getGrupos();
        $ubigeos = $this->clienteService->getUbigeos();
        $allowedContactTypes = $this->clienteService->getAllowedContactTypes($currentUser);
        $tiposContacto = $this->clienteService->getTiposContacto($allowedContactTypes);
        $contactosCliente = $this->clienteService->getContactosByCliente($cliente, $allowedContactTypes);
        $defaultContacto = DB::table('contacto')
            ->where('cliente_idcliente', $cliente)
            ->orderByDesc('default')
            ->orderByDesc('idcontacto')
            ->value('idcontacto');
        $contactoSeleccionado = old('contactoSeleccionado', $defaultContacto ?: $contactosCliente->first()?->idcontacto);

        $credencialesCliente = $this->clienteService->getCredencialesByCliente($cliente);
        $defaultCredencial = DB::table('credenciales')
            ->where('cliente_idcliente', $cliente)
            ->orderByDesc('idcredenciales')
            ->value('idcredenciales');
        $credencialSeleccionada = old('credencialSeleccionada', $defaultCredencial ?: $credencialesCliente->first()?->idcredenciales);

        $grupoAsignado = DB::table('detalleGrupoCliente')
            ->where('cliente_idcliente', $cliente)
            ->value('grupoCliente_idgrupoCliente');

        $tipoClienteActual = (string) old('tipoCliente', (string) ($record->tipoCliente ?? ''));
        $isTipoEmpresaActual = $tipoClienteActual === '1';
        $idclienteEditHelpText = $tipoClienteActual === ''
            ? null
            : ($isTipoEmpresaActual
                ? 'Obligatorio. Solo números, exactamente 11 dígitos.'
                : 'Obligatorio. Solo números, exactamente 8 dígitos.');

        $canSeeCredencialesField = $this->currentUserCanViewCredenciales($request);

        return [
            'title' => 'Editar Cliente',
            'moduleTitle' => 'Módulo Clientes',
            'mode' => 'edit',
            'readOnly' => true,
            'lockInfo' => $lockInfo,
            'lockBlocked' => $lockBlocked,
            'lockOwner' => $lockOwner,
            'currentUser' => $currentUser,
            'lockResource' => 'clientes',
            'lockId' => $cliente,
            'formAction' => route('modules.clientes.update', $cliente),
            'backRoute' => route('modules.clientes'),
            'record' => $record,
            'canSeeCredencialesField' => $canSeeCredencialesField,
            'grupoAsignado' => $grupoAsignado,
            'grupos' => $grupos,
            'fields' => [
                [
                    'name' => 'tipoCliente',
                    'type' => 'select',
                    'label' => 'Tipo Cliente',
                    'required' => true,
                    'options' => [
                        '0' => 'Persona Natural',
                        '1' => 'Empresa',
                    ],
                    'placeholder' => 'Selecciona tipo de cliente',
                    'helpText' => 'Elige Empresa o Persona Natural para validar RUC o DNI.',
                ],
                [
                    'name' => 'idcliente',
                    'type' => 'text',
                    'label' => 'Cliente',
                    'required' => true,
                    'maxlength' => 11,
                    'minlength' => $tipoClienteActual === '' ? 0 : ($isTipoEmpresaActual ? 11 : 8),
                    'pattern' => $tipoClienteActual === '' ? '^[0-9]*$' : ($isTipoEmpresaActual ? '[0-9]{11}' : '[0-9]{8}'),
                    'inputmode' => 'numeric',
                    'helpText' => $idclienteEditHelpText,
                    'placeholder' => 'Cliente',
                    'disabled' => !((string) old('tipoCliente', (string) ($record->tipoCliente ?? '')) !== ''),
                ],
                [
                    'name' => 'razonSocial',
                    'type' => 'text',
                    'label' => 'Razón Social',
                    'required' => false,
                    'maxlength' => 200,
                    'minlength' => 2,
                    'pattern' => '[A-Za-z0-9ÁÉÍÓÚáéíóúÑñ\s\.,\-&]{2,}',
                    'helpText' => 'Mínimo 2 caracteres.',
                    'colSpan' => 2,
                ],
                [
                    'name' => 'nombreComercial',
                    'type' => 'text',
                    'label' => 'Nombre Comercial',
                    'required' => false,
                    'maxlength' => 100,
                    'minlength' => 2,
                    'pattern' => '[A-Za-z0-9ÁÉÍÓÚáéíóúÑñ\s\.,\-&]{2,}',
                    'helpText' => 'Mínimo 2 caracteres.',
                ],
                [
                    'name' => 'rubro',
                    'type' => 'text',
                    'label' => 'Rubro',
                    'required' => false,
                    'maxlength' => 50,
                    'helpText' => 'Selecciona o escribe un rubro.',
                    'datalist' => 'rubro-options',
                    'datalistOptions' => [
                        'Automotriz',
                        'Construcción',
                        'Consultoría',
                        'Educación',
                        'Inmobiliaria',
                        'Logística',
                        'Manufactura',
                        'Minería',
                        'Salud',
                        'Tecnología',
                        'Transporte',
                    ],
                ],
                [
                    'name' => 'fechaIngreso',
                    'type' => 'date',
                    'label' => 'Fecha de Ingreso',
                    'required' => false,
                ],
                [
                    'name' => 'fechaBaja',
                    'type' => 'date',
                    'label' => 'Fecha de Baja',
                    'required' => false,
                ],
                [
                    'name' => 'estadoCliente_idestadoCliente',
                    'type' => 'select',
                    'label' => 'Estado del Cliente',
                    'required' => true,
                    'tomSelect' => true,
                    'optionsData' => $estados,
                    'optionKey' => 'idestadoCliente',
                    'optionLabel' => 'detalle',
                    'placeholder' => 'Selecciona estado',
                ],
                [
                    'name' => 'direccionCliente_iddireccionCliente',
                    'type' => 'select',
                    'label' => 'Dirección del Cliente',
                    'required' => true,
                    'tomSelect' => true,
                    'optionsData' => $direcciones,
                    'optionKey' => 'iddireccionCliente',
                    'optionLabel' => 'label_completo',
                    'placeholder' => 'Selecciona dirección',
                    'quickCreate' => true,
                    'quickCreateListUrl' => route('modules.clientes.direcciones.opciones') . '?cliente=' . urlencode($cliente),
                    'quickCreateStoreUrl' => route('modules.clientes.direcciones.crear-rapido', $cliente),
                    'quickCreateUpdateUrlTemplate' => route('modules.clientes.direcciones.actualizar-rapido', '__ID__'),
                    'quickCreateDeleteUrlTemplate' => route('modules.clientes.direcciones.eliminar-rapido', '__ID__'),
                    'quickCreateExportPdfUrl' => route('modules.clientes.direcciones.export', [$cliente, 'pdf']),
                    'quickCreateExportXlsxUrl' => route('modules.clientes.direcciones.export', [$cliente, 'xlsx']),
                    'quickCreateUbigeos' => $ubigeos->map(fn($ubigeo) => [
                        'id' => (int) $ubigeo->idubigeo,
                        'label' => trim(((string) $ubigeo->idubigeo) . '-' . ($ubigeo->ubigeo_text ?? '')),
                    ])->values()->all(),
                ],
                [
                    'name' => 'contactoSeleccionado',
                    'type' => 'select',
                    'label' => 'Contacto del Cliente',
                    'required' => false,
                    'tomSelect' => true,
                    'value' => $contactoSeleccionado,
                    'optionsData' => $contactosCliente,
                    'optionKey' => 'idcontacto',
                    'optionLabel' => 'label_completo',
                    'placeholder' => 'Selecciona contacto',
                    'quickCreateContact' => true,
                    'quickContactMode' => 'edit',
                    'quickContactTipos' => $tiposContacto->map(fn($tipo) => [
                        'id' => (int) $tipo->idtipoContacto,
                        'label' => (string) ($tipo->detalle ?? ''),
                    ])->values()->all(),
                    'quickContactPayloadInput' => 'contactos_payload',
                    'quickContactListUrl' => route('modules.clientes.contactos.opciones', $cliente),
                    'quickContactStoreUrl' => route('modules.clientes.contactos.crear-rapido', $cliente),
                    'quickContactUpdateUrlTemplate' => route('modules.clientes.contactos.actualizar-rapido', [$cliente, '__ID__']),
                    'quickContactDeleteUrlTemplate' => route('modules.clientes.contactos.eliminar-rapido', [$cliente, '__ID__']),
                    'quickContactExportPdfUrl' => route('modules.clientes.contactos.export', [$cliente, 'pdf']),
                    'quickContactExportXlsxUrl' => route('modules.clientes.contactos.export', [$cliente, 'xlsx']),
                ],
                [
                    'name' => 'credencialSeleccionada',
                    'type' => 'select',
                    'label' => 'Credencial del Cliente',
                    'required' => false,
                    'tomSelect' => true,
                    'value' => $credencialSeleccionada,
                    'optionsData' => $credencialesCliente,
                    'optionKey' => 'idcredenciales',
                    'optionLabel' => 'label_completo',
                    'placeholder' => 'Selecciona credencial',
                    'quickCreateCredential' => true,
                    'quickCredentialMode' => 'edit',
                    'quickCredentialPayloadInput' => 'credenciales_payload',
                    'quickCredentialListUrl' => route('modules.clientes.credenciales.opciones', $cliente),
                    'quickCredentialStoreUrl' => route('modules.clientes.credenciales.crear-rapido', $cliente),
                    'quickCredentialUpdateUrlTemplate' => route('modules.clientes.credenciales.actualizar-rapido', [$cliente, '__ID__']),
                    'quickCredentialDeleteUrlTemplate' => route('modules.clientes.credenciales.eliminar-rapido', [$cliente, '__ID__']),
                    'quickCredentialExportPdfUrl' => route('modules.clientes.credenciales.export', [$cliente, 'pdf']),
                    'quickCredentialExportXlsxUrl' => route('modules.clientes.credenciales.export', [$cliente, 'xlsx']),
                ],
                [
                    'name' => 'contactos_payload',
                    'type' => 'hidden',
                    'value' => '[]',
                ],
                [
                    'name' => 'direcciones_payload',
                    'type' => 'hidden',
                    'value' => '[]',
                ],
                [
                    'name' => 'credenciales_payload',
                    'type' => 'hidden',
                    'value' => '[]',
                ],
                [
                    'name' => 'grupoCliente_idgrupoCliente',
                    'type' => 'select',
                    'label' => 'Grupo del Cliente',
                    'required' => false,
                    'tomSelect' => true,
                    'optionsData' => $grupos,
                    'optionKey' => 'idgrupoCliente',
                    'optionLabel' => 'nombreGrupo',
                    'placeholder' => 'Selecciona grupo',
                    'value' => $record->grupoCliente_idgrupoCliente ?? $grupoAsignado,
                ],
                [
                    'name' => 'detraccion',
                    'type' => 'switch',
                    'label' => 'Retencion',
                    'required' => false,
                    'options' => [
                        '1' => 'No',
                    ],
                    'switchLabels' => [
                        'off' => 'No',
                        'on' => 'Sí',
                    ],
                    'value' => (string) ($record->detraccion ?? '') === '1' ? ['1'] : [],
                ],
                [
                    'name' => 'flag_integrador',
                    'type' => 'switch',
                    'label' => 'Integrador',
                    'required' => false,
                    'options' => [
                        '1' => 'No',
                    ],
                    'switchLabels' => [
                        'off' => 'No',
                        'on' => 'Sí',
                    ],
                    'value' => (string) ($record->flag_integrador ?? '') === '1' ? ['1'] : [],
                ],
            ],
            'extraSections' => [
                [
                    'view' => 'cliente.relation-panel',
                    'data' => [
                        'relationGroups' => $this->clienteService->buildRelationGroups($cliente),
                        'row' => (object) ['idcliente' => $cliente],
                    ],
                ],
            ],
        ];
        
    }

    public function lockStatus(string $cliente): JsonResponse
    {
        $status = ResourceLock::status('clientes', $cliente);

        return response()->json([
            'locked' => $status !== null,
            'lock' => $status,
        ]);
    }

    public function acquireLock(Request $request, string $cliente): JsonResponse
    {
        $usuario = $request->session()->get('erp_auth.usuario', 'anonimo');
        $result = ResourceLock::acquire('clientes', $cliente, $usuario);

        if ($result['success']) {
            $this->publishLockEvent('clientes', $cliente, $usuario, 'locked', $result['lock']['expires_at']);

            return response()->json([
                'success' => true,
                'lock' => $result['lock'],
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'El cliente ya se encuentra bloqueado por otro usuario.',
            'lock' => $result['lock'],
        ], 409);
    }

    public function releaseLock(Request $request, string $cliente): JsonResponse
    {
        $usuario = $request->session()->get('erp_auth.usuario', 'anonimo');
        $result = ResourceLock::release('clientes', $cliente, $usuario);

        if ($result['success']) {
            $this->publishLockEvent('clientes', $cliente, $usuario, 'released', null);

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


    public function update(Request $request, string $cliente): RedirectResponse
    {
        $currentRecord = DB::table('cliente')
            ->where('idcliente', $cliente)
            ->select('idcliente', 'tipoCliente')
            ->first();

        if (!$currentRecord) {
            return redirect()
                ->route('modules.clientes')
                ->with('error', 'No se encontro el cliente solicitado.');
        }

        // En edición, si tipo/id no llegan (campos deshabilitados), usamos los valores actuales.
        $resolvedTipo = trim((string) $request->input('tipoCliente', (string) ($currentRecord->tipoCliente ?? '')));
        $resolvedIdcliente = trim((string) $request->input('idcliente', (string) ($currentRecord->idcliente ?? $cliente)));
        $request->merge([
            'tipoCliente' => $resolvedTipo,
            'idcliente' => $resolvedIdcliente,
        ]);

        $currentTipo = (string) ($currentRecord->tipoCliente ?? '');
        $currentIdcliente = (string) ($currentRecord->idcliente ?? $cliente);
        $isTipoChanged = $resolvedTipo !== $currentTipo;
        $isIdclienteChanged = $resolvedIdcliente !== $currentIdcliente;

        $idclienteRules = [
            'required',
            Rule::unique('cliente', 'idcliente')->ignore($cliente, 'idcliente'),
        ];

        // Solo forzamos validación estricta DNI/RUC cuando se cambió tipo o id.
        if ($isTipoChanged || $isIdclienteChanged) {
            if ($resolvedTipo === '1') {
                $idclienteRules[] = 'digits:11';
            } elseif ($resolvedTipo === '0') {
                $idclienteRules[] = 'digits:8';
            }
        }

        $currentUser = $request->session()->get('erp_auth.usuario', 'anonimo');
        $lockInfo = ResourceLock::status('clientes', $cliente);
        if ($lockInfo && $lockInfo['usuario'] !== $currentUser) {
            return redirect()
                ->back()
                ->with('error', "El cliente está siendo editado por {$lockInfo['usuario']} y no puede actualizarse hasta que se libere.");
        }

        $validated = $request->validate([
            'tipoCliente' => ['required', 'in:0,1'],
            'idcliente' => $idclienteRules,
            'razonSocial' => ['nullable', 'string', 'min:2', 'max:200', 'regex:' . self::SAFE_TEXT_REGEX],
            'nombreComercial' => ['nullable', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'fechaIngreso' => ['nullable', 'date'],
            'fechaBaja' => ['nullable', 'date', 'after_or_equal:fechaIngreso'],
            'rubro' => ['nullable', 'string', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'estadoCliente_idestadoCliente' => ['required', 'integer', 'exists:estadocliente,idestadoCliente'],
            'direccionCliente_iddireccionCliente' => ['required', 'string', 'max:60', 'regex:/^(tmp-\d+|\d+)$/'],
            'grupoCliente_idgrupoCliente' => ['nullable', 'integer', 'exists:grupocliente,idgrupoCliente'],
            'detraccion' => ['nullable'],
            'detraccion.*' => ['in:1'],
            'flag_integrador' => ['nullable'],
            'contactoSeleccionado' => ['nullable', 'string', 'max:60', 'regex:/^(tmp-\d+|\d+)$/'],
            'credencialSeleccionada' => ['nullable', 'string', 'max:60', 'regex:/^(tmp-\d+|\d+)$/'],
            'contactos_payload' => ['nullable', 'string'],
            'direcciones_payload' => ['nullable', 'string'],
            'credenciales_payload' => ['nullable', 'string'],
        ], [
            'idcliente.unique' => 'El cliente ya está registrado.',
            'idcliente.digits' => 'El cliente debe tener 8 dígitos si es persona natural o 11 si es empresa.',
            'rubro.max' => 'El rubro no debe tener más de 50 caracteres.',
        ]);

        if (!$request->filled('contactoSeleccionado')) {
            $validated['contactoSeleccionado'] = null;
        }
        if (!$request->filled('credencialSeleccionada')) {
            $validated['credencialSeleccionada'] = null;
        }

        $grupoId = $validated['grupoCliente_idgrupoCliente'] ?? null;
        $selectedAddressId = $validated['direccionCliente_iddireccionCliente'] ?? null;
        $selectedContactId = $validated['contactoSeleccionado'] ?? null;
        $selectedCredencialId = $validated['credencialSeleccionada'] ?? null;
        $contactosPayload = (string) ($validated['contactos_payload'] ?? '[]');
        $direccionesPayload = (string) ($validated['direcciones_payload'] ?? '[]');
        $credencialesPayload = (string) ($validated['credenciales_payload'] ?? '[]');
        $detr = $validated['detraccion'] ?? null;
        $validated['detraccion'] = (
            (is_array($detr) && in_array('1', $detr, true))
            || $detr === '1' || $detr === 1 || $detr === 'on' || $detr === true
        ) ? '1' : '0';

        $fi = $validated['flag_integrador'] ?? null;
        $validated['flag_integrador'] = (
            (is_array($fi) && in_array('1', $fi, true))
            || $fi === '1' || $fi === 1 || $fi === 'on' || $fi === true
        ) ? '1' : '0';
        unset($validated['grupoCliente_idgrupoCliente'], $validated['contactos_payload'], $validated['direcciones_payload'], $validated['contactoSeleccionado'], $validated['credencialSeleccionada'], $validated['direccionCliente_iddireccionCliente'], $validated['credenciales_payload']);

        DB::transaction(function () use ($cliente, $validated, $grupoId, $contactosPayload, $direccionesPayload, $credencialesPayload, &$selectedAddressId, $selectedContactId, $selectedCredencialId): void {
            DB::table('cliente')->where('idcliente', $cliente)->update($validated);

            DB::table('detalleGrupoCliente')->where('cliente_idcliente', $cliente)->delete();
            if ($grupoId) {
                $maxId = DB::table('detalleGrupoCliente')->max('iddetalleGrupoCliente') ?? 0;
                DB::table('detalleGrupoCliente')->insert([
                    'iddetalleGrupoCliente' => $maxId + 1,
                    'grupoCliente_idgrupoCliente' => $grupoId,
                    'cliente_idcliente' => $cliente,
                ]);
            }

            $this->clienteService->insertContactosTemporales($cliente, $contactosPayload, $selectedContactId);
            $this->clienteService->insertDireccionesTemporales($cliente, $direccionesPayload, $selectedAddressId);
            $this->clienteService->insertCredencialesTemporales($cliente, $credencialesPayload, $selectedCredencialId);

            if ($selectedAddressId !== null) {
                $this->clienteService->syncClientAddress($cliente, $selectedAddressId);
            }

            if ($selectedContactId !== null) {
                $this->clienteService->syncClientContact($cliente, $selectedContactId);
            }
        });


        if ($lockInfo && $lockInfo['usuario'] === $currentUser) {
            ResourceLock::release('clientes', $cliente, $currentUser);
            $this->publishLockEvent('clientes', $cliente, $currentUser, 'released', null);
        }

        return redirect()
            ->route('modules.clientes')
            ->with('success', 'Cliente actualizado correctamente.');
    }

    public function destroy(Request $request, string $cliente): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'clientes', $cliente, 'cliente', 'modules.clientes')) {
            return $redirect;
        }

        try {
            // Eliminar relación con grupos
            DB::table('detalleGrupoCliente')->where('cliente_idcliente', $cliente)->delete();

            // Eliminar cliente
            DB::table('cliente')->where('idcliente', $cliente)->delete();

            $this->releaseLockIfOwned($request, 'clientes', $cliente);

            return redirect()
                ->route('modules.clientes')
                ->with('success', 'Cliente eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.clientes')
                ->with('error', 'No se puede eliminar el cliente porque tiene registros relacionados.');
        }
    }

    public function estadosOpcionesRapidas(): JsonResponse
    {
        $estados = $this->clienteService->getEstados()->map(fn($estado) => [
            'id' => (int) $estado->idestadoCliente,
            'label' => trim(((string) $estado->idestadoCliente) . ' - ' . ((string) ($estado->detalle ?? ''))),
            'detalle' => (string) ($estado->detalle ?? ''),
        ])->values();

        return response()->json([
            'ok' => true,
            'data' => $estados,
        ]);
    }

    public function estadosCrearRapido(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'detalle' => ['required', 'string', 'max:20', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        $newId = DB::transaction(function () use ($validated) {
            $nextId = ((int) DB::table('estadocliente')->max('idestadoCliente')) + 1;

            DB::table('estadocliente')->insert([
                'idestadoCliente' => $nextId,
                'detalle' => $validated['detalle'],
            ]);

            return $nextId;
        });

        return response()->json([
            'ok' => true,
            'message' => 'Estado creado correctamente.',
            'data' => [
                'id' => $newId,
                'label' => trim(((string) $newId) . ' - ' . $validated['detalle']),
                'detalle' => $validated['detalle'],
            ],
        ], 201);
    }

    public function estadosActualizarRapido(Request $request, int $estado): JsonResponse
    {
        $exists = DB::table('estadocliente')->where('idestadoCliente', $estado)->exists();
        if (!$exists) {
            return response()->json([
                'ok' => false,
                'message' => 'No se encontro el estado solicitado.',
            ], 404);
        }

        $validated = $request->validate([
            'detalle' => ['required', 'string', 'max:20', 'regex:' . self::SAFE_TEXT_REGEX],
        ]);

        DB::table('estadocliente')
            ->where('idestadoCliente', $estado)
            ->update([
                'detalle' => $validated['detalle'],
            ]);

        return response()->json([
            'ok' => true,
            'message' => 'Estado actualizado correctamente.',
            'data' => [
                'id' => $estado,
                'label' => trim(((string) $estado) . ' - ' . $validated['detalle']),
                'detalle' => $validated['detalle'],
            ],
        ]);
    }

    public function estadosEliminarRapido(int $estado): JsonResponse
    {
        $exists = DB::table('estadocliente')->where('idestadoCliente', $estado)->exists();
        if (!$exists) {
            return response()->json([
                'ok' => false,
                'message' => 'No se encontro el estado solicitado.',
            ], 404);
        }

        $enUso = DB::table('cliente')
            ->where('estadoCliente_idestadoCliente', $estado)
            ->exists();

        if ($enUso) {
            return response()->json([
                'ok' => false,
                'message' => 'No se puede eliminar porque el estado esta asignado a uno o mas clientes.',
            ], 422);
        }

        DB::table('estadocliente')->where('idestadoCliente', $estado)->delete();

        return response()->json([
            'ok' => true,
            'message' => 'Estado eliminado correctamente.',
        ]);
    }

    public function direccionesOpciones(Request $request): JsonResponse
    {
        $cliente = $request->query('cliente');
        $direcciones = $this->clienteService->getDirecciones($cliente)->map(fn($direccion) => [
            'id' => (int) $direccion->iddireccionCliente,
            'label' => (string) $direccion->label_completo,
            'direccion' => (string) ($direccion->direccion ?? ''),
            'tipo' => (string) ($direccion->tipo ?? ''),
            'ubigeo_idubigeo' => (int) ($direccion->ubigeo_idubigeo ?? 0),
            'linkUbicacion' => (string) ($direccion->linkUbicacion ?? ''),
        ])->values();

        return response()->json([
            'ok' => true,
            'data' => $direcciones,
        ]);
    }

    public function direccionesExport(Request $request, string $cliente, string $format)
    {
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $clienteRow = DB::table('cliente')->select('idcliente', 'nombreComercial')->where('idcliente', $cliente)->first();
        if (!$clienteRow) {
            abort(404);
        }

        $direcciones = $this->clienteService->getDirecciones($cliente);
        $columns = [
            ['key' => 'tipo', 'label' => 'Tipo'],
            ['key' => 'direccion', 'label' => 'Dirección'],
            ['key' => 'ubigeo_text', 'label' => 'Ubigeo'],
            ['key' => 'linkUbicacion', 'label' => 'Link ubicación'],
        ];
        $filename = 'direcciones_cliente_' . $cliente . '_' . now()->format('Ymd_His') . '.' . $format;
        $nombreComercial = trim((string) ($clienteRow->nombreComercial ?? ''));
        $title = 'Direcciones de cliente ' . $nombreComercial . ' ' . $cliente;


        return $format === 'xlsx'
            ? $this->exportXlsxResponse($direcciones, $columns, $filename)
            : $this->exportPdfResponseModal($direcciones, $columns, $title, $filename);
    }

    public function contactosExport(Request $request, string $cliente, string $format)
    {
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $clienteRow = DB::table('cliente')->select('idcliente', 'nombreComercial')->where('idcliente', $cliente)->first();
        if (!$clienteRow) {
            abort(404);
        }

        $contactos = $this->clienteService->getContactosByCliente($cliente);
        $columns = [
            ['key' => 'tipoDetalle', 'label' => 'Tipo contacto'],
            ['key' => 'nombreApellido', 'label' => 'Nombre y apellido'],
            ['key' => 'cargo', 'label' => 'Cargo'],
            ['key' => 'correo', 'label' => 'Correo'],
            ['key' => 'correo2', 'label' => 'Correo alterno'],
            ['key' => 'numero', 'label' => 'Número'],
            ['key' => 'numero2', 'label' => 'Número alterno'],
        ];
        $filename = 'contactos_cliente_' . $cliente . '_' . now()->format('Ymd_His') . '.' . $format;
        $nombreComercial = trim((string) ($clienteRow->nombreComercial ?? ''));
        $title = 'Contactos de cliente ' . $nombreComercial . ' ' . $cliente;

        return $format === 'xlsx'
            ? $this->exportXlsxResponse($contactos, $columns, $filename)
            : $this->exportPdfResponseModal($contactos, $columns, $title, $filename);
    }

    public function direccionesCrearRapido(Request $request, string $cliente): JsonResponse
    {
        $exists = DB::table('cliente')->where('idcliente', $cliente)->exists();
        if (!$exists) {
            return response()->json([
                'ok' => false,
                'message' => 'No se encontro el cliente solicitado.',
            ], 404);
        }

        if ($lockConflict = $this->assertClientLockAvailableJson($request, $cliente)) {
            return $lockConflict;
        }

        $validated = $request->validate([
            'tipo' => ['nullable', 'string', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
            'direccion' => ['required', 'string', 'min:5', 'max:200', 'regex:' . self::SAFE_TEXT_REGEX],
            'linkUbicacion' => ['nullable', 'url', 'max:300'],
            'ubigeo_idubigeo' => ['required', 'integer', 'exists:ubigeo,idubigeo'],
        ], [
            'linkUbicacion.url' => "El campo 'Link ubicación' debe ser una URL válida (p. ej. https://www.google.com/maps/...).",
        ]);

        $newId = DB::transaction(function () use ($validated, $cliente) {
            $nextId = ((int) DB::table('direccioncliente')->max('iddireccionCliente')) + 1;

            DB::table('direccioncliente')->insert([
                'iddireccionCliente' => $nextId,
                'tipo' => $validated['tipo'] ?? null,
                'direccion' => $validated['direccion'],
                'linkUbicacion' => $validated['linkUbicacion'] ?? null,
                'ubigeo_idubigeo' => $validated['ubigeo_idubigeo'],
                'cliente_idcliente' => $cliente,
                'default' => null,
            ]);

            return $nextId;
        });

        $direccion = $this->clienteService->getDirecciones($cliente)
            ->firstWhere('iddireccionCliente', $newId);

        return response()->json([
            'ok' => true,
            'message' => 'Direccion creada correctamente.',
            'data' => [
                'id' => (int) $direccion->iddireccionCliente,
                'label' => (string) $direccion->label_completo,
                'direccion' => (string) ($direccion->direccion ?? ''),
                'tipo' => (string) ($direccion->tipo ?? ''),
                'ubigeo_idubigeo' => (int) ($direccion->ubigeo_idubigeo ?? 0),
                'linkUbicacion' => (string) ($direccion->linkUbicacion ?? ''),
            ],
        ], 201);
    }

    public function direccionesActualizarRapido(Request $request, int $direccion): JsonResponse
    {
        $direccionRow = DB::table('direccioncliente')
            ->select('iddireccionCliente', 'cliente_idcliente')
            ->where('iddireccionCliente', $direccion)
            ->first();

        if (!$direccionRow) {
            return response()->json([
                'ok' => false,
                'message' => 'No se encontro la direccion solicitada.',
            ], 404);
        }

        $cliente = (string) ($direccionRow->cliente_idcliente ?? '');
        if ($cliente !== '' && ($lockConflict = $this->assertClientLockAvailableJson($request, $cliente))) {
            return $lockConflict;
        }

        $validated = $request->validate([
            'tipo' => ['nullable', 'string', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
            'direccion' => ['required', 'string', 'min:5', 'max:200', 'regex:' . self::SAFE_TEXT_REGEX],
            'linkUbicacion' => ['nullable', 'url', 'max:300'],
            'ubigeo_idubigeo' => ['required', 'integer', 'exists:ubigeo,idubigeo'],
        ], [
            'linkUbicacion.url' => "El campo 'Link ubicación' debe ser una URL válida (p. ej. https://www.google.com/maps/...).",
        ]);

        DB::table('direccioncliente')
            ->where('iddireccionCliente', $direccion)
            ->update([
                'tipo' => $validated['tipo'] ?? null,
                'direccion' => $validated['direccion'],
                'linkUbicacion' => $validated['linkUbicacion'] ?? null,
                'ubigeo_idubigeo' => $validated['ubigeo_idubigeo'],
            ]);

        $row = DB::table('direccioncliente as d')
            ->leftJoin('ubigeo as u', 'd.ubigeo_idubigeo', '=', 'u.idubigeo')
            ->select('d.iddireccionCliente', 'd.tipo', 'd.direccion', 'd.linkUbicacion', 'd.ubigeo_idubigeo', 'u.departamento', 'u.provincia', 'u.distrito')
            ->where('d.iddireccionCliente', $direccion)
            ->first();

        if ($row) {
            $departamento = $row->departamento ?? '';
            $provincia = $row->provincia ?? '';
            $distrito = $row->distrito ?? '';
            $ubigeo_text = trim("{$departamento} / {$provincia} / {$distrito}", ' /');
            $row->label_completo = !empty($ubigeo_text)
                ? "{$row->direccion} ({$ubigeo_text})"
                : "{$row->direccion}";
        }

        return response()->json([
            'ok' => true,
            'message' => 'Direccion actualizada correctamente.',
            'data' => [
                'id' => (int) $row->iddireccionCliente,
                'label' => (string) $row->label_completo,
                'direccion' => (string) ($row->direccion ?? ''),
                'tipo' => (string) ($row->tipo ?? ''),
                'ubigeo_idubigeo' => (int) ($row->ubigeo_idubigeo ?? 0),
                'linkUbicacion' => (string) ($row->linkUbicacion ?? ''),
            ],
        ]);
    }

    public function direccionesEliminarRapido(Request $request, int $direccion): JsonResponse
    {
        $direccionRow = DB::table('direccioncliente')
            ->select('iddireccionCliente', 'cliente_idcliente')
            ->where('iddireccionCliente', $direccion)
            ->first();

        if (!$direccionRow) {
            return response()->json([
                'ok' => false,
                'message' => 'No se encontro la direccion solicitada.',
            ], 404);
        }

        $cliente = (string) ($direccionRow->cliente_idcliente ?? '');
        if ($cliente !== '' && ($lockConflict = $this->assertClientLockAvailableJson($request, $cliente))) {
            return $lockConflict;
        }

        DB::table('direccioncliente')->where('iddireccionCliente', $direccion)->delete();

        return response()->json([
            'ok' => true,
            'message' => 'Direccion eliminada correctamente.',
        ]);
    }

    public function contactosOpciones(Request $request, string $cliente): JsonResponse
    {
        $exists = DB::table('cliente')->where('idcliente', $cliente)->exists();
        if (!$exists) {
            return response()->json([
                'ok' => false,
                'message' => 'No se encontro el cliente solicitado.',
                'data' => [],
            ], 404);
        }

        $currentUser = $request->session()->get('erp_auth.usuario');
        $allowedContactTypes = $this->clienteService->getAllowedContactTypes($currentUser);

        $contactos = $this->clienteService->getContactosByCliente($cliente, $allowedContactTypes)->map(fn($contacto) => [
            'id' => (int) $contacto->idcontacto,
            'label' => (string) $contacto->label_completo,
            'nombreApellido' => (string) ($contacto->nombreApellido ?? ''),
            'tipoContacto_idtipoContacto' => (int) $contacto->tipoContacto_idtipoContacto,
            'cargo' => (string) ($contacto->cargo ?? ''),
            'correo' => (string) ($contacto->correo ?? ''),
            'correo2' => (string) ($contacto->correo2 ?? ''),
            'numero' => (string) ($contacto->numero ?? ''),
            'numero2' => (string) ($contacto->numero2 ?? ''),
        ])->values();

        return response()->json([
            'ok' => true,
            'data' => $contactos,
        ]);
    }

    public function contactosCrearRapido(Request $request, string $cliente): JsonResponse
    {
        $exists = DB::table('cliente')->where('idcliente', $cliente)->exists();
        if (!$exists) {
            return response()->json([
                'ok' => false,
                'message' => 'No se encontro el cliente solicitado.',
            ], 404);
        }

        if ($lockConflict = $this->assertClientLockAvailableJson($request, $cliente)) {
            return $lockConflict;
        }

        $validated = $request->validate([
            'tipoContacto_idtipoContacto' => ['required', 'integer', 'exists:tipocontacto,idtipoContacto'],
            'nombreApellido' => ['required', 'string', 'min:5', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'cargo' => ['nullable', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'correo' => ['required', 'email', 'max:100'],
            'correo2' => ['nullable', 'email', 'max:100'],
            'numero' => ['required', 'digits:9'],
            'numero2' => ['nullable', 'digits:9'],
        ]);

        $currentUser = $request->session()->get('erp_auth.usuario');
        $allowedContactTypes = $this->clienteService->getAllowedContactTypes($currentUser);
        if (!in_array('*', $allowedContactTypes, true) && !in_array((int) $validated['tipoContacto_idtipoContacto'], $allowedContactTypes, true)) {
            return response()->json([
                'ok' => false,
                'message' => 'No tiene permiso para asignar este tipo de contacto.',
            ], 403);
        }

        $selectedCliente = $request->query('cliente', $cliente);
        $newId = DB::transaction(function () use ($validated, $selectedCliente) {
            $nextId = ((int) DB::table('contacto')->max('idcontacto')) + 1;

            DB::table('contacto')->insert([
                'idcontacto' => $nextId,
                'cliente_idcliente' => $selectedCliente,
                'tipoContacto_idtipoContacto' => $validated['tipoContacto_idtipoContacto'],
                'nombreApellido' => $validated['nombreApellido'],
                'cargo' => $validated['cargo'] ?? null,
                'correo' => $validated['correo'] ?? null,
                'correo2' => $validated['correo2'] ?? null,
                'numero' => $validated['numero'] ?? null,
                'numero2' => $validated['numero2'] ?? null,
            ]);

            return $nextId;
        });

        $contacto = $this->clienteService->getContactosByCliente($cliente)
            ->firstWhere('idcontacto', $newId);

        return response()->json([
            'ok' => true,
            'message' => 'Contacto creado correctamente.',
            'data' => [
                'id' => (int) $contacto->idcontacto,
                'label' => (string) $contacto->label_completo,
                'nombreApellido' => (string) ($contacto->nombreApellido ?? ''),
                'tipoContacto_idtipoContacto' => (int) $contacto->tipoContacto_idtipoContacto,
                'cargo' => (string) ($contacto->cargo ?? ''),
                'correo' => (string) ($contacto->correo ?? ''),
                'correo2' => (string) ($contacto->correo2 ?? ''),
                'numero' => (string) ($contacto->numero ?? ''),
                'numero2' => (string) ($contacto->numero2 ?? ''),
            ],
        ], 201);
    }

    public function contactosActualizarRapido(Request $request, string $cliente, int $contacto): JsonResponse
    {
        $clienteExists = DB::table('cliente')->where('idcliente', $cliente)->exists();
        if (!$clienteExists) {
            return response()->json([
                'ok' => false,
                'message' => 'No se encontro el cliente solicitado.',
            ], 404);
        }

        if ($lockConflict = $this->assertClientLockAvailableJson($request, $cliente)) {
            return $lockConflict;
        }

        $contactoRow = DB::table('contacto')
            ->where('idcontacto', $contacto)
            ->where('cliente_idcliente', $cliente)
            ->first();

        if (!$contactoRow) {
            return response()->json([
                'ok' => false,
                'message' => 'No se encontro el contacto solicitado para este cliente.',
            ], 404);
        }

        $validated = $request->validate([
            'tipoContacto_idtipoContacto' => ['required', 'integer', 'exists:tipocontacto,idtipoContacto'],
            'nombreApellido' => ['required', 'string', 'min:5', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'cargo' => ['nullable', 'string', 'min:2', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'correo' => ['required', 'email', 'max:100'],
            'correo2' => ['nullable', 'email', 'max:100'],
            'numero' => ['required', 'digits:9'],
            'numero2' => ['nullable', 'digits:9'],
        ]);

        $currentUser = $request->session()->get('erp_auth.usuario');
        $allowedContactTypes = $this->clienteService->getAllowedContactTypes($currentUser);
        if (!in_array('*', $allowedContactTypes, true) && !in_array((int) $validated['tipoContacto_idtipoContacto'], $allowedContactTypes, true)) {
            return response()->json([
                'ok' => false,
                'message' => 'No tiene permiso para asignar este tipo de contacto.',
            ], 403);
        }

        DB::table('contacto')
            ->where('idcontacto', $contacto)
            ->where('cliente_idcliente', $cliente)
            ->update([
                'tipoContacto_idtipoContacto' => $validated['tipoContacto_idtipoContacto'],
                'nombreApellido' => $validated['nombreApellido'],
                'cargo' => $validated['cargo'] ?? null,
                'correo' => $validated['correo'] ?? null,
                'correo2' => $validated['correo2'] ?? null,
                'numero' => $validated['numero'] ?? null,
                'numero2' => $validated['numero2'] ?? null,
            ]);

        $updated = $this->clienteService->getContactosByCliente($cliente)->firstWhere('idcontacto', $contacto);

        return response()->json([
            'ok' => true,
            'message' => 'Contacto actualizado correctamente.',
            'data' => [
                'id' => (int) $updated->idcontacto,
                'label' => (string) $updated->label_completo,
                'nombreApellido' => (string) ($updated->nombreApellido ?? ''),
                'tipoContacto_idtipoContacto' => (int) $updated->tipoContacto_idtipoContacto,
                'cargo' => (string) ($updated->cargo ?? ''),
                'correo' => (string) ($updated->correo ?? ''),
                'correo2' => (string) ($updated->correo2 ?? ''),
                'numero' => (string) ($updated->numero ?? ''),
                'numero2' => (string) ($updated->numero2 ?? ''),
            ],
        ]);
    }

    public function contactosEliminarRapido(Request $request, string $cliente, int $contacto): JsonResponse
    {
        $clienteExists = DB::table('cliente')->where('idcliente', $cliente)->exists();
        if (!$clienteExists) {
            return response()->json([
                'ok' => false,
                'message' => 'No se encontro el cliente solicitado.',
            ], 404);
        }

        if ($lockConflict = $this->assertClientLockAvailableJson($request, $cliente)) {
            return $lockConflict;
        }

        $deleted = DB::table('contacto')
            ->where('idcontacto', $contacto)
            ->where('cliente_idcliente', $cliente)
            ->delete();

        if ($deleted === 0) {
            return response()->json([
                'ok' => false,
                'message' => 'No se encontro el contacto solicitado para este cliente.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Contacto eliminado correctamente.',
        ]);
    }

    public function credencialesOpciones(string $cliente): JsonResponse
    {
        $exists = DB::table('cliente')->where('idcliente', $cliente)->exists();
        if (!$exists) {
            return response()->json([
                'ok' => false,
                'message' => 'No se encontro el cliente solicitado.',
                'data' => [],
            ], 404);
        }

        $credenciales = $this->clienteService->getCredencialesByCliente($cliente)->map(fn($credencial) => [
            'id' => (int) $credencial->idcredenciales,
            'label' => (string) $credencial->label_completo,
            'usuario' => (string) ($credencial->usuario ?? ''),
            'clave' => (string) ($credencial->clave ?? ''),
            'fechaCreacion' => (string) ($credencial->fechaCreacion ?? ''),
            'estadoRecepcion' => (string) ($credencial->estadoRecepcion ?? '0'),
        ])->values();

        return response()->json([
            'ok' => true,
            'data' => $credenciales,
        ]);
    }

    public function credencialesExport(Request $request, string $cliente, string $format)
    {
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $clienteRow = DB::table('cliente')->select('idcliente', 'nombreComercial')->where('idcliente', $cliente)->first();
        if (!$clienteRow) {
            abort(404);
        }

        $credenciales = $this->clienteService->getCredencialesByCliente($cliente);

        $credencialesFormateadas = $credenciales->map(function ($item) {
            return [
                'usuario' => $item->usuario ?? '',
                'clave' => $item->clave ?? '',
                'fechaCreacion' => $item->fechaCreacion ?? '',
                'estadoRecepcion' => ($item->estadoRecepcion == 1 ? 'Si' : 'No'),
            ];
        });

        $columns = [
            ['key' => 'usuario', 'label' => 'Usuario'],
            ['key' => 'clave', 'label' => 'Clave'],
            ['key' => 'fechaCreacion', 'label' => 'Fecha Creación'],
            ['key' => 'estadoRecepcion', 'label' => 'Estado de recepción'],
        ];
        $filename = 'credenciales_cliente_' . $cliente . '_' . now()->format('Ymd_His') . '.' . $format;
        $nombreComercial = trim((string) ($clienteRow->nombreComercial ?? ''));
        $title = 'Credenciales de cliente ' . $nombreComercial . ' ' . $cliente;

        return $format === 'xlsx'
            ? $this->exportXlsxResponse($credencialesFormateadas, $columns, $filename)
            : $this->exportPdfResponseModal($credencialesFormateadas, $columns, $title, $filename);
    }

    public function credencialesCrearRapido(Request $request, string $cliente): JsonResponse
    {
        $exists = DB::table('cliente')->where('idcliente', $cliente)->exists();
        if (!$exists) {
            return response()->json([
                'ok' => false,
                'message' => 'No se encontro el cliente solicitado.',
            ], 404);
        }

        if ($lockConflict = $this->assertClientLockAvailableJson($request, $cliente)) {
            return $lockConflict;
        }

        $validated = $request->validate([
            'usuario' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'clave' => ['required', 'string', 'min:8', 'max:100'],
            'fechaCreacion' => ['nullable', 'date'],
            'estadoRecepcion' => ['required', 'in:0,1'],
        ]);

        $usuarioUsuario = $request->session()->get('erp_auth.usuario');
        if (!$usuarioUsuario) {
            return response()->json([
                'ok' => false,
                'message' => 'No hay usuario autenticado para crear credenciales.',
            ], 403);
        }

        $newId = DB::transaction(function () use ($validated, $cliente, $usuarioUsuario) {
            $nextId = ((int) DB::table('credenciales')->max('idcredenciales')) + 1;

            DB::table('credenciales')->insert([
                'idcredenciales' => $nextId,
                'cliente_idcliente' => $cliente,
                'usuario_usuario' => $usuarioUsuario,
                'usuario' => $validated['usuario'],
                'clave' => $validated['clave'],
                'fechaCreacion' => $validated['fechaCreacion'] ?? now(),
                'estadoRecepcion' => $validated['estadoRecepcion'],
            ]);

            return $nextId;
        });

        $credencial = $this->clienteService->getCredencialesByCliente($cliente)->firstWhere('idcredenciales', $newId);

        return response()->json([
            'ok' => true,
            'message' => 'Credencial creada correctamente.',
            'data' => [
                'id' => (int) $credencial->idcredenciales,
                'label' => (string) $credencial->label_completo,
                'usuario' => (string) ($credencial->usuario ?? ''),
                'fechaCreacion' => (string) ($credencial->fechaCreacion ?? ''),
                'estadoRecepcion' => (string) ($credencial->estadoRecepcion ?? '0'),
            ],
        ], 201);
    }

    public function credencialesActualizarRapido(Request $request, string $cliente, int $credencial): JsonResponse
    {
        $clienteExists = DB::table('cliente')->where('idcliente', $cliente)->exists();
        if (!$clienteExists) {
            return response()->json([
                'ok' => false,
                'message' => 'No se encontro el cliente solicitado.',
            ], 404);
        }

        if ($lockConflict = $this->assertClientLockAvailableJson($request, $cliente)) {
            return $lockConflict;
        }

        $credencialRow = DB::table('credenciales')
            ->where('idcredenciales', $credencial)
            ->where('cliente_idcliente', $cliente)
            ->first();

        if (!$credencialRow) {
            return response()->json([
                'ok' => false,
                'message' => 'No se encontro la credencial solicitada para este cliente.',
            ], 404);
        }

        $validated = $request->validate([
            'usuario' => ['required', 'string', 'min:2', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'clave' => ['required', 'string', 'min:8', 'max:100'],
            'fechaCreacion' => ['nullable', 'date'],
            'estadoRecepcion' => ['required', 'in:0,1'],
        ]);

        DB::table('credenciales')
            ->where('idcredenciales', $credencial)
            ->where('cliente_idcliente', $cliente)
            ->update([
                'usuario' => $validated['usuario'],
                'clave' => $validated['clave'],
                'fechaCreacion' => $validated['fechaCreacion'] ?? now(),
                'estadoRecepcion' => $validated['estadoRecepcion'],
            ]);

        $updated = $this->clienteService->getCredencialesByCliente($cliente)->firstWhere('idcredenciales', $credencial);

        return response()->json([
            'ok' => true,
            'message' => 'Credencial actualizada correctamente.',
            'data' => [
                'id' => (int) $updated->idcredenciales,
                'label' => (string) $updated->label_completo,
                'usuario' => (string) ($updated->usuario ?? ''),
                'fechaCreacion' => (string) ($updated->fechaCreacion ?? ''),
                'estadoRecepcion' => (string) ($updated->estadoRecepcion ?? '0'),
            ],
        ]);
    }

    public function credencialesEliminarRapido(Request $request, string $cliente, int $credencial): JsonResponse
    {
        $clienteExists = DB::table('cliente')->where('idcliente', $cliente)->exists();
        if (!$clienteExists) {
            return response()->json([
                'ok' => false,
                'message' => 'No se encontro el cliente solicitado.',
            ], 404);
        }

        if ($lockConflict = $this->assertClientLockAvailableJson($request, $cliente)) {
            return $lockConflict;
        }

        $deleted = DB::table('credenciales')
            ->where('idcredenciales', $credencial)
            ->where('cliente_idcliente', $cliente)
            ->delete();

        if ($deleted === 0) {
            return response()->json([
                'ok' => false,
                'message' => 'No se encontro la credencial solicitada para este cliente.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Credencial eliminada correctamente.',
        ]);
    }

    private function assertClientLockAvailableJson(Request $request, string $cliente): ?JsonResponse
    {
        $currentUser = $request->session()->get('erp_auth.usuario', 'anonimo');
        $lockInfo = ResourceLock::status('clientes', $cliente);

        if ($lockInfo && ($lockInfo['usuario'] ?? '') !== $currentUser) {
            $owner = $lockInfo['usuario'] ?? 'otro usuario';

            return response()->json([
                'ok' => false,
                'message' => "El cliente está siendo editado por {$owner} y no puede modificarse hasta que se libere.",
            ], 409);
        }

        return null;
    }

}
