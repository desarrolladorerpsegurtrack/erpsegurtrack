<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Permission\HandlesResourceLock;
use App\Services\TicketsService;
use App\Support\ResourceLock;
use App\Support\TicketFlowProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TicketsController extends Controller
{
    use HandlesResourceLock;

    public function __construct(private readonly TicketsService $ticketsService)
    {
    }

    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';

    private const ESTADOS = [
        'Pendiente',
        'En proceso',
        'Resuelto',
    ];

    private const HISTORIAL_RESULT_ATENDIENDO = 'atendiendo';
    private const HISTORIAL_RESULT_COMPLETADO = 'completado';
    private const HISTORIAL_RESULT_FINALIZADO = 'finalizado';
    private const HISTORIAL_RESULT_PENDIENTE = 'pendiente';

    public function index(Request $request): View
    {
        $context = $this->resolveTicketIndexContext($request);
        $baseQuery = $this->buildTicketIndexQuery($context);
        $statsBase = clone $baseQuery;
        $baseQuery = $this->applyTicketIndexFilters($request, $baseQuery);

        $items = $baseQuery
            ->orderByDesc('t.fechaHoraRegistro')
            ->orderByDesc('t.idticket')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $items->through(function ($row) use ($context) {
            return $this->decorateTicketIndexRow($row, $context['currentUser']);
        });

        $tipoOperaciones = DB::table('tipooperacion')
            ->orderBy('detalle')
            ->get();

        $stats = $this->buildTicketIndexStats($statsBase);

        return view('ticket.tickets', [
            'title' => 'Módulo Gestiones',
            'singularTitle' => 'Módulo Gestión',
            'items' => $items,
            'createRoute' => route('modules.tickets.create'),
            'listResource' => 'ticket',
            'showActionsColumn' => true,
            'columns' => $this->getTicketIndexColumns(),
            'stats' => $this->formatTicketIndexStats($stats),
            'filters' => $this->buildTicketIndexFilters($tipoOperaciones),
            'identifierKey' => 'idticket',
        ]);
    }

    /**
     * @return array{currentUser:string,isAdmin:bool,canSeeFlow:bool,canAttendTickets:bool,allowedVistaIds:array<int>,authData:array<string,mixed>}
     */
    private function resolveTicketIndexContext(Request $request): array
    {
        $authData = $request->session()->get('erp_auth', []);
        $ctx = $this->ticketsService->resolveAuthContext($authData);

        return array_merge($ctx, ['authData' => $authData]);
    }

    private function buildTicketIndexQuery(array $context)
    {
        $latestHistorySubquery = DB::table('historialflujo as hf')
            ->select('hf.ticket_idticket', DB::raw('MAX(hf.idhistorialflujo) as max_historial_id'))
            ->groupBy('hf.ticket_idticket');

        $baseQuery = DB::table('ticket as t')
            ->leftJoin('tipooperacion as tp', 't.tipoOperacion_idtipoOperacion', '=', 'tp.idtipoOperacion')
            ->leftJoinSub($latestHistorySubquery, 'latest_historial', function ($join) {
                $join->on('t.idticket', '=', 'latest_historial.ticket_idticket');
            })
            ->leftJoin('historialflujo as hf', 'latest_historial.max_historial_id', '=', 'hf.idhistorialflujo')
            ->leftJoin('vista as v', 'hf.vista_idvista', '=', 'v.idvista')
            ->select(
                't.idticket',
                't.pedidoReferencia',
                't.usuarioEmisor',
                't.usuarioReceptor',
                't.tipoOperacion_idtipoOperacion as tipo_operacion_id',
                't.fechaHoraRegistro',
                't.fechaHoraCierre',
                't.detalle',
                't.estado',
                'tp.nomenclatura as tipo_nomenclatura',
                't.tipoOperacion_idtipoOperacion as tipo_operacion_id',
                'tp.detalle as tipo_detalle',
                'hf.vista_idvista as vista_actual_id',
                'hf.resultado as historial_resultado',
                'hf.usuario_usuario as historial_usuario',
                'hf.flujoregla_idflujoregla as historial_flujoregla_id',
                'hf.fechaejecucion as historial_fecha',
                'v.nombre as vista_actual_nombre',
                'v.detalle as vista_actual_detalle'
            );

        $baseQuery->whereNotExists(function ($query): void {
            $query->selectRaw('1')
                ->from('cotizacion as annulled_quote')
                ->join('tipooperacion as quote_operation', 'quote_operation.idtipoOperacion', '=', 't.tipoOperacion_idtipoOperacion')
                ->where('annulled_quote.estado', \App\Services\CotizacionService::STATE_ANULADO)
                ->where(function ($referenceQuery): void {
                    $referenceQuery
                        ->whereColumn('annulled_quote.nroCotizacion', 't.pedidoReferencia')
                        ->orWhereColumn('annulled_quote.batch_id', 't.pedidoReferencia');
                })
                ->where(function ($operationQuery): void {
                    $operationQuery
                        ->where('quote_operation.nomenclatura', 'CT')
                        ->orWhere('quote_operation.detalle', 'like', '%Cotizaci%');
                })
                ->whereRaw("LOWER(COALESCE(t.estado, '')) = 'resuelto'");
        });

        if (!$context['isAdmin']) {
            if (!$context['canSeeFlow'] && $context['allowedVistaIds'] === []) {
                $baseQuery->whereRaw('1 = 0');
            } elseif (!$context['canSeeFlow']) {
                $baseQuery->whereExists(function ($query) use ($context): void {
                    $query->selectRaw('1')
                        ->from('flujoregla as permission_rule')
                        ->join('flujo as permission_flow', 'permission_flow.idflujo', '=', 'permission_rule.flujo_idflujo')
                        ->whereColumn('permission_flow.tipoOperacion_idtipoOperacion', 't.tipoOperacion_idtipoOperacion')
                        ->whereIn('permission_rule.vista_idvista', $context['allowedVistaIds'])
                        ->where('permission_rule.estado', '1');
                });
            }

            $baseQuery->whereNotIn(DB::raw('LOWER(t.estado)'), $this->estadoAliases('Resuelto'));
        }

        if (!$context['isAdmin'] && $context['canSeeFlow'] && !$context['canAttendTickets']) {
            $baseQuery->whereNotIn(DB::raw('LOWER(t.estado)'), $this->estadoAliases('Resuelto'));
        }

        return $baseQuery;
    }

    private function applyTicketIndexFilters(Request $request, $baseQuery)
    {
        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $baseQuery->where(function ($query) use ($term) {
                $query
                    ->where('t.idticket', 'like', $term)
                    ->orWhere('t.pedidoReferencia', 'like', $term)
                    ->orWhere('t.usuarioEmisor', 'like', $term)
                    ->orWhere('t.usuarioReceptor', 'like', $term)
                    ->orWhere('t.estado', 'like', $term)
                    ->orWhere('tp.nomenclatura', 'like', $term)
                    ->orWhere('tp.detalle', 'like', $term);
            });
        }

        $tipoOperacion = trim((string) $request->input('tipo_operacion', ''));
        if ($tipoOperacion !== '') {
            $baseQuery->where('t.tipoOperacion_idtipoOperacion', $tipoOperacion);
        }

        $emisor = trim((string) $request->input('emisor', ''));
        if ($emisor !== '') {
            $baseQuery->where('t.usuarioEmisor', 'like', '%' . $emisor . '%');
        }

        $receptor = trim((string) $request->input('receptor', ''));
        if ($receptor !== '') {
            $baseQuery->where('t.usuarioReceptor', 'like', '%' . $receptor . '%');
        }

        $desde = trim((string) $request->input('desde', ''));
        if ($desde !== '') {
            $baseQuery->whereDate('t.fechaHoraRegistro', '>=', $desde);
        }

        $hasta = trim((string) $request->input('hasta', ''));
        if ($hasta !== '') {
            $baseQuery->whereDate('t.fechaHoraRegistro', '<=', $hasta);
        }

        $estado = trim((string) $request->input('estado', ''));
        if ($estado !== '') {
            $baseQuery->whereIn(DB::raw('LOWER(t.estado)'), $this->estadoAliases($this->normalizeEstadoValue($estado)));
        }

        return $baseQuery;
    }

    private function decorateTicketIndexRow(object $row, string $currentUser): object
    {
        $tipoOperacion = trim((string) ($row->tipo_nomenclatura ?? '') . ' ' . (string) ($row->tipo_detalle ?? ''));
        $row->tipo_operacion = $tipoOperacion !== '' ? $tipoOperacion : '-';
        $row->estado = $this->normalizeEstadoValue((string) ($row->estado ?? ''));
        $row->vista_actual = trim((string) ($row->vista_actual_nombre ?? '')) ?: '-';
        $vistaId = (int) ($row->vista_actual_id ?? 0);
        $lockInfo = $vistaId > 0
            ? ResourceLock::status('ticket.vista', $this->viewLockId((int) $row->idticket, $vistaId))
            : null;
        $row->lock_usuario = $lockInfo['usuario'] ?? null;
        $row->lock_expires_at = $lockInfo['expires_at'] ?? null;
        $row->is_locked = $lockInfo !== null;
        $row->locked_by_other = false;

        $displayInfo = $this->ticketsService->resolveTicketListDisplayInfo($row);
        $row->usuarioReceptor = $displayInfo['receptor'];
        $row->estado_fase_texto = $displayInfo['fase_texto'];
        $row->estado_accion_texto = $displayInfo['accion_texto'];

        return $row;
    }

    private function buildTicketIndexStats($statsBase): array
    {
        return [
            'pendientes' => (clone $statsBase)
                ->whereIn(DB::raw('LOWER(t.estado)'), $this->estadoAliases('Pendiente'))
                ->count('t.idticket'),
            'activos' => (clone $statsBase)
                ->whereIn(DB::raw('LOWER(t.estado)'), $this->estadoAliases('Activo'))
                ->count('t.idticket'),
            'en_proceso' => (clone $statsBase)
                ->whereIn(DB::raw('LOWER(t.estado)'), $this->estadoAliases('En proceso'))
                ->count('t.idticket'),
            'resueltos' => (clone $statsBase)
                ->whereIn(DB::raw('LOWER(t.estado)'), $this->estadoAliases('Resuelto'))
                ->count('t.idticket'),
        ];
    }

    private function formatTicketIndexStats(array $stats): array
    {
        return [
            ['label' => 'Pendientes', 'value' => $stats['pendientes']], 
            ['label' => 'En proceso', 'value' => $stats['en_proceso']],
            ['label' => 'Resueltos', 'value' => $stats['resueltos']],
        ];
    }

    private function getTicketIndexColumns(): array
    {
        return [
            ['key' => 'estado', 'label' => 'Estado', 'type' => 'estado'],
            ['key' => 'idticket', 'label' => 'Gestión', 'type' => 'text'],
            ['key' => 'usuarioEmisor', 'label' => 'Emisor', 'type' => 'text'],
            ['key' => 'tipo_operacion', 'label' => 'Tipo de operación', 'type' => 'text', 'wrap' => true],
            ['key' => 'usuarioReceptor', 'label' => 'Receptor', 'type' => 'text'],
            ['key' => 'detalle', 'label' => 'Detalle', 'type' => 'text'],
            ['key' => 'fechaHoraRegistro', 'label' => 'Registro', 'type' => 'date'],
            ['key' => 'fechaHoraCierre', 'label' => 'Cierre', 'type' => 'date'],
        ];
    }

    private function buildTicketIndexFilters($tipoOperaciones): array
    {
        return [

            [
                'name' => 'estado',
                'label' => 'Estado',
                'options' => array_map(function (string $estado): array {
                    return ['value' => $estado, 'label' => $estado];
                }, self::ESTADOS),
                'placeholder' => 'Todos los estados',
            ],
            [
                'name' => 'tipo_operacion',
                'label' => 'Tipo de operacion',
                'options' => $tipoOperaciones
                    ->map(function ($tipo): array {
                        $nomenclatura = trim((string) ($tipo->nomenclatura ?? ''));
                        $detalle = trim((string) ($tipo->detalle ?? ''));
                        $label = trim($nomenclatura . ' - ' . $detalle);

                        return [
                            'value' => (string) $tipo->idtipoOperacion,
                            'label' => $label !== '' ? $label : 'Sin descripcion',
                        ];
                    })
                    ->values()
                    ->all(),
                'placeholder' => 'Todos los tipos',
            ],
            [
                'name' => 'emisor',
                'label' => 'Emisor',
                'type' => 'text',
                'placeholder' => 'Usuario emisor',
            ],
            [
                'name' => 'receptor',
                'label' => 'Receptor',
                'type' => 'text',
                'placeholder' => 'Usuario receptor',
            ],
            [
                'name' => 'desde',
                'label' => 'Desde',
                'type' => 'date',
            ],
            [
                'name' => 'hasta',
                'label' => 'Hasta',
                'type' => 'date',
            ],
        ];
    }
    public function create(): View
    {
        $tipoOperaciones = $this->getTipoOperaciones();

        return view('ticket.tickets-form', [
            'title' => 'Nuevo Gestion',
            'moduleTitle' => 'Módulo Gestiones',
            'mode' => 'create',
            'formAction' => route('modules.tickets.store'),
            'backRoute' => route('modules.tickets'),
            'record' => null,
            'readOnly' => false,
            'fields' => $this->buildTicketFields($tipoOperaciones),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateTicket($request);

        $validated['fechaHoraRegistro'] = $this->normalizeDateTimeInput($validated['fechaHoraRegistro'] ?? null) ?? now()->format('Y-m-d H:i:s');
        $validated['fechaHoraCierre'] = $this->normalizeDateTimeInput($validated['fechaHoraCierre'] ?? null);
        $validated['estado'] = $this->normalizeEstadoValue($validated['estado'] ?? null);

        if ($request->hasFile('ImagenEvidencia')) {
            $validated['ImagenEvidencia'] = $this->storeEvidenceFile($request);
        }

        $ticketId = DB::table('ticket')->insertGetId($validated);
        $ticketId = (int) ($validated['idticket'] ?? $ticketId);

        $authUser = (string) $request->session()->get('erp_auth.usuario', '');
        $resolvedUserLabel = $this->resolveUserDisplayName($authUser, $validated['usuarioEmisor'] ?? null);
        $validated['usuarioEmisor'] = $resolvedUserLabel;
        $validated['usuarioReceptor'] = $validated['usuarioReceptor'] ?? null;
        $historialUser = $authUser !== ''
            ? $authUser
            : (string) ($validated['usuarioEmisor'] ?? 'anonimo');

        $this->createInitialHistorial($ticketId, (int) $validated['tipoOperacion_idtipoOperacion'], $historialUser);

        // Emitir evento realtime para que las listas se actualicen sin recargar.
        $this->publishResourceEvent('ticket', (string) $ticketId, 'created');

        // Notificar a todos los usuarios con permiso para la primera vista del flujo
        $flujo = $this->resolveFlujoForTicket((int) $validated['tipoOperacion_idtipoOperacion']);
        if ($flujo) {
            $reglas = $this->getFlujoReglas((int) $flujo->idflujo);
            $firstRule = $reglas->first();
            if ($firstRule) {
                $this->publishResourceEvent('vista', (string) $firstRule->vista_idvista, 'ticket.assigned', [
                    'message' => 'Tienes una nueva gestión para atender.',
                    'ticketId' => (int) $ticketId,
                    'url' => route('modules.tickets'),
                ]);
            }
        }

        // Si el ticket tiene un usuario receptor asignado, enviar una notificación
        // dirigida específicamente a ese usuario para avisarle que debe atenderlo.
        $receptor = trim((string) ($validated['usuarioReceptor'] ?? ''));
        if ($receptor !== '') {
            $this->publishResourceEvent('notification', $receptor, 'created', [
                'message' => 'Tienes una nueva gestión para atender.',
                'ticketId' => (int) $ticketId,
                'url' => route('modules.tickets'),
            ]);
        }

        return redirect()
            ->route('modules.tickets')
            ->with('success', 'Ticket creado correctamente.');
    }

    public function latestRow(Request $request)
    {
        $authData = $request->session()->get('erp_auth', []);
        $ctx = $this->ticketsService->resolveAuthContext($authData);
        $isAdmin = $ctx['isAdmin'];
        $canSeeFlow = $ctx['canSeeFlow'];
        $canAttendTickets = $ctx['canAttendTickets'];
        $allowedVistaIds = $ctx['allowedVistaIds'];

        $latestHistorySubquery = DB::table('historialflujo as hf')
            ->select('hf.ticket_idticket', DB::raw('MAX(hf.idhistorialflujo) as max_historial_id'))
            ->groupBy('hf.ticket_idticket');

        $baseQuery = DB::table('ticket as t')
            ->leftJoin('tipooperacion as tp', 't.tipoOperacion_idtipoOperacion', '=', 'tp.idtipoOperacion')
            ->leftJoinSub($latestHistorySubquery, 'latest_historial', function ($join) {
                $join->on('t.idticket', '=', 'latest_historial.ticket_idticket');
            })
            ->leftJoin('historialflujo as hf', 'latest_historial.max_historial_id', '=', 'hf.idhistorialflujo')
            ->leftJoin('vista as v', 'hf.vista_idvista', '=', 'v.idvista')
            ->select(
                't.idticket',
                't.pedidoReferencia',
                't.usuarioEmisor',
                't.usuarioReceptor',
                't.fechaHoraRegistro',
                't.fechaHoraCierre',
                't.detalle',
                't.estado',
                'tp.nomenclatura as tipo_nomenclatura',
                'tp.detalle as tipo_detalle',
                'hf.vista_idvista as vista_actual_id',
                'hf.resultado as historial_resultado',
                'hf.usuario_usuario as historial_usuario',
                'hf.flujoregla_idflujoregla as historial_flujoregla_id',
                'hf.fechaejecucion as historial_fecha',
                'v.nombre as vista_actual_nombre',
                'v.detalle as vista_actual_detalle'
            );

        if (!$isAdmin) {
            if (!$canSeeFlow && $allowedVistaIds === []) {
                return response('', 204);
            } elseif (!$canSeeFlow) {
                $baseQuery->whereExists(function ($query) use ($allowedVistaIds): void {
                    $query->selectRaw('1')
                        ->from('flujoregla as permission_rule')
                        ->join('flujo as permission_flow', 'permission_flow.idflujo', '=', 'permission_rule.flujo_idflujo')
                        ->whereColumn('permission_flow.tipoOperacion_idtipoOperacion', 't.tipoOperacion_idtipoOperacion')
                        ->whereIn('permission_rule.vista_idvista', $allowedVistaIds)
                        ->where('permission_rule.estado', '1');
                });
            }

            $baseQuery->whereNotIn(DB::raw('LOWER(t.estado)'), $this->estadoAliases('Resuelto'));
        }

        $row = $baseQuery
            ->orderByDesc('t.fechaHoraRegistro')
            ->orderByDesc('t.idticket')
            ->first();

        if (!$row) {
            return response('', 204);
        }

        $tipoOperacion = trim((string) (($row->tipo_nomenclatura ?? '') . ' ' . ($row->tipo_detalle ?? '')));
        $row->tipo_operacion = $tipoOperacion !== '' ? $tipoOperacion : '-';
        $row->estado = $this->normalizeEstadoValue((string) ($row->estado ?? ''));
        $row->vista_actual = trim((string) ($row->vista_actual_nombre ?? '')) ?: '-';
        $vistaId = (int) ($row->vista_actual_id ?? 0);
        $lockInfo = $vistaId > 0
            ? ResourceLock::status('ticket.vista', $this->viewLockId((int) $row->idticket, $vistaId))
            : null;
        $row->lock_usuario = $lockInfo['usuario'] ?? null;
        $row->lock_expires_at = $lockInfo['expires_at'] ?? null;
        $row->is_locked = $lockInfo !== null;
        $row->locked_by_other = false;

        $displayInfo = $this->ticketsService->resolveTicketListDisplayInfo($row);
        $row->usuarioReceptor = $displayInfo['receptor'];
        $row->estado_fase_texto = $displayInfo['fase_texto'];
        $row->estado_accion_texto = $displayInfo['accion_texto'];

        $columns = [
            ['key' => 'idticket', 'label' => 'Gestión', 'type' => 'text'],
            ['key' => 'usuarioEmisor', 'label' => 'Emisor', 'type' => 'text'],
            ['key' => 'tipo_operacion', 'label' => 'Tipo de operacion', 'type' => 'text', 'wrap' => true],
            ['key' => 'usuarioReceptor', 'label' => 'Receptor', 'type' => 'text'],
            ['key' => 'estado', 'label' => 'Estado', 'type' => 'estado'],
            ['key' => 'detalle', 'label' => 'Detalle', 'type' => 'text'],
            ['key' => 'fechaHoraRegistro', 'label' => 'Registro', 'type' => 'date'],
            ['key' => 'fechaHoraCierre', 'label' => 'Cierre', 'type' => 'date'],
        ];

        $showActionsColumn = true;

        $html = view('ticket.partials.row', [
            'row' => $row,
            'columns' => $columns,
            'showActionsColumn' => $showActionsColumn,
            'canAttend' => $canAttendTickets,
        ])->render();

        return response($html, 200)->header('Content-Type', 'text/html');
    }

    private function validateTicket(Request $request, ?int $ticketId = null): array
    {
        $uniqueTicketRule = $ticketId !== null
            ? Rule::unique('ticket', 'idticket')->ignore($ticketId, 'idticket')
            : Rule::unique('ticket', 'idticket');

        $rules = [
            'idticket' => ['nullable', 'integer', 'min:1', $uniqueTicketRule],
            'tipoOperacion_idtipoOperacion' => ['required', 'integer', 'exists:tipooperacion,idtipoOperacion'],
            'pedidoReferencia' => ['nullable', 'string', 'max:45', 'regex:' . self::SAFE_TEXT_REGEX],
            'fechaHoraRegistro' => ['required', 'date_format:Y-m-d\TH:i'],
            'fechaHoraCierre' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'detalle' => ['nullable', 'string', 'max:500', 'regex:' . self::SAFE_TEXT_REGEX],
            'ImagenEvidencia' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'respuesta' => ['nullable', 'string', 'max:500', 'regex:' . self::SAFE_TEXT_REGEX],
            'estado' => ['nullable', 'string', Rule::in(self::ESTADOS)],
        ];

        $messages = [
            'ImagenEvidencia.required' => 'El campo imagen/evidencia es obligatorio.',
            'ImagenEvidencia.file' => 'El campo imagen/evidencia debe ser un archivo válido.',
            'ImagenEvidencia.mimes' => 'El campo imagen/evidencia debe ser un archivo de tipo: jpg, jpeg, png, webp.',
            'ImagenEvidencia' => 'El campo imagen/evidencia no debe superar los 5MB.',
            'ImagenEvidencia.max' => 'El campo imagen/evidencia no debe superar los 5MB.',
        ];

        $rules['usuarioEmisor'] = ['required', 'string', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX];
        $rules['usuarioReceptor'] = ['nullable', 'string', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX];

        if ($ticketId === null) {
            $rules['ImagenEvidencia'] = ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'];
        }

        return $request->validate($rules, $messages);
    }

    private function getTipoOperaciones()
    {
        return DB::table('tipooperacion')
            ->orderBy('detalle')
            ->get()
            ->map(function ($tipo) {
                $nomenclatura = trim((string) ($tipo->nomenclatura ?? ''));
                $detalle = trim((string) ($tipo->detalle ?? ''));
                $tipo->label = trim($nomenclatura . ' ' . $detalle) !== ''
                    ? trim($nomenclatura . ' ' . $detalle)
                    : 'Sin descripción';
                return $tipo;
            });
    }

    private function buildTicketFields($tipoOperaciones, ?object $record = null): array
    {
        return [
            [
                'name' => 'tipoOperacion_idtipoOperacion',
                'type' => 'select',
                'label' => 'Tipo de operación',
                'required' => true,
                'tomSelect' => true,
                'optionsData' => $tipoOperaciones,
                'optionKey' => 'idtipoOperacion',
                'optionLabel' => 'label',
                'placeholder' => 'Selecciona tipo de operación',
            ],
            [
                'name' => 'pedidoReferencia',
                'type' => 'text',
                'label' => 'Pedido de referencia',
                'required' => false,
                'maxlength' => 45,
                'helpText' => 'Opcional.',
            ],
            [
                'name' => 'usuarioEmisor',
                'type' => 'text',
                'label' => 'Usuario emisor',
                'required' => true,
                'value' => $record ? (string) ($record->usuarioEmisor ?? '') : (string) session('erp_auth.usuario', ''),
                'readonly' => true,
                'maxlength' => 50,
            ],
            [
                'name' => 'usuarioReceptor',
                'type' => 'text',
                'label' => 'Usuario receptor',
                'required' => false,
                'maxlength' => 50,
            ],
            [
                'name' => 'fechaHoraRegistro',
                'type' => 'datetime-local',
                'label' => 'Fecha y hora de registro',
                'required' => true,
                'value' => $record ? $this->formatDateTimeForForm((string) ($record->fechaHoraRegistro ?? '')) : now()->format('Y-m-d\TH:i'),
            ],
            [
                'name' => 'fechaHoraCierre',
                'type' => 'datetime-local',
                'label' => 'Fecha y hora de cierre',
                'required' => false,
                'value' => $record ? $this->formatDateTimeForForm((string) ($record->fechaHoraCierre ?? '')) : null,
            ],
            [
                'name' => 'detalle',
                'type' => 'textarea',
                'label' => 'Detalle',
                'required' => false,
                'colSpan' => 1,
                'rows' => 4,
            ],
            [
                'name' => 'respuesta',
                'type' => 'textarea',
                'label' => 'Respuesta',
                'required' => false,
                'colSpan' => 1,
                'rows' => 4,
            ],
            [
                'name' => 'ImagenEvidencia',
                'type' => 'file',
                'label' => 'Imagen / evidencia',
                'required' => false,
                'fileKind' => 'file',
                'accept' => 'image/jpeg,image/png,image/webp,application/pdf',
                'colSpan' => 1,
            ],
            [
                'name' => 'estado',
                'type' => 'select',
                'label' => 'Estado',
                'required' => true,
                'colSpan' => 1,
                'options' => array_combine(self::ESTADOS, self::ESTADOS),
                'value' => $record->estado ?? 'Pendiente',
            ],
        ];
    }

    private function resolveUserDisplayName(string $authUser, ?string $fallback = null): string
    {
        $authUser = trim($authUser);
        if ($authUser === '') {
            $authUser = trim((string) ($fallback ?? ''));
        }

        $userRow = DB::table('usuario')
            ->where('usuario', $authUser)
            ->first();

        if ($userRow && !empty($userRow->personal_dniPersonal)) {
            $personalRow = DB::table('personal')
                ->where('dniPersonal', $userRow->personal_dniPersonal)
                ->first();

            $displayName = trim((string) (($personalRow->nombre ?? '') . ' ' . ($personalRow->apellido ?? '')));
            if ($displayName !== '') {
                return substr($displayName, 0, 50);
            }
        }

        return substr($authUser !== '' ? $authUser : 'anonimo', 0, 50);
    }

    private function normalizeDateTimeInput(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d\TH:i', $value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            try {
                return Carbon::parse($value)->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                return null;
            }
        }
    }

    private function normalizeEstadoValue(?string $estado): string
    {
        $estado = mb_strtolower(trim((string) $estado));

        return match ($estado) {
            'pendiente', 'nuevo', 'asignado' => 'Pendiente',
            'activo' => 'Activo',
            'en proceso', 'en progreso' => 'En proceso',
            'resuelto', 'cancelado' => 'Resuelto',
            default => 'Pendiente',
        };
    }

    private function estadoAliases(string $estado): array
    {
        return match ($this->normalizeEstadoValue($estado)) {
            'Pendiente' => ['pendiente', 'nuevo', 'asignado'],
            'Activo' => ['activo'],
            'En proceso' => ['en proceso', 'en progreso'],
            'Resuelto' => ['resuelto', 'cancelado'],
            default => ['pendiente'],
        };
    }

    private function formatDateTimeForForm(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d\TH:i');
        } catch (\Throwable) {
            return null;
        }
    }

    private function storeEvidenceFile(Request $request): ?string
    {
        if (!$request->hasFile('ImagenEvidencia')) {
            return null;
        }

        $file = $request->file('ImagenEvidencia');
        if ($file === null) {
            return null;
        }

        $filename = 'evidencia_' . Str::lower(Str::random(12)) . '.' . $file->extension();

        return $file->storePubliclyAs('tickets', $filename, 'public');
    }

    public function show(int $ticketId): RedirectResponse
    {
        if (!DB::table('ticket')->where('idticket', $ticketId)->exists()) {
            return redirect()->route('modules.tickets')->with('error', 'Ticket no encontrado.');
        }

        $flashMessages = [];
        foreach (['success', 'error', 'warning', 'info'] as $key) {
            if (session()->has($key)) {
                $flashMessages[$key] = session($key);
            }
        }

        return redirect()->route('modules.tickets')->with($flashMessages);
    }

    public function availableViews(Request $request, int $ticketId): JsonResponse
    {
        $authData = $request->session()->get('erp_auth', []);
        $ctx = $this->ticketsService->resolveAuthContext($authData);
        if (!$ctx['isAdmin'] && !$ctx['canAttendTickets']) {
            abort(403, 'No tienes permiso para atender gestiones.');
        }

        $ticket = DB::table('ticket')->where('idticket', $ticketId)->first();
        if (!$ticket) {
            return response()->json(['message' => 'Ticket no encontrado.'], 404);
        }
        if (in_array(mb_strtolower(trim((string) $ticket->estado)), ['resuelto', 'cancelado'], true)) {
            return response()->json(['message' => 'Esta gestión ya está finalizada.'], 409);
        }

        $flujo = $this->resolveFlujoForTicket((int) $ticket->tipoOperacion_idtipoOperacion);
        if (!$flujo) {
            return response()->json(['message' => 'No se encontró flujo para el tipo de operación del ticket.'], 422);
        }

        $reglas = $this->getFlujoReglas((int) $flujo->idflujo);
        if ($reglas->isEmpty()) {
            return response()->json(['message' => 'No hay reglas de flujo disponibles para este ticket.'], 422);
        }

        $progress = $this->getRuleProgress($ticketId, $reglas);
        $allowedRules = $reglas->filter(function ($rule) use ($ctx) {
            return $ctx['isAdmin'] || in_array((int) $rule->vista_idvista, $ctx['allowedVistaIds'], true);
        });
        $vistas = $allowedRules->map(function ($rule) use ($ticketId, $ctx, $progress, $reglas) {
            $vistaId = (int) $rule->vista_idvista;
            $isComplete = TicketFlowProgress::isComplete($rule, $progress);
            $isEligible = TicketFlowProgress::isEligible($rule, $reglas, $progress);
            $lockId = $this->viewLockId($ticketId, $vistaId);
            $lock = ResourceLock::status('ticket.vista', $lockId);
            $lockedByOther = $lock && ($lock['usuario'] ?? '') !== $ctx['currentUser'];

            $reason = match (true) {
                $isComplete => 'Completada',
                !$isEligible => 'Completa primero las vistas anteriores',
                $lockedByOther => 'En atención por ' . ($lock['usuario'] ?? 'otro usuario'),
                default => null,
            };

            return (object) [
                'idvista' => $vistaId,
                'nombre' => $rule->vista_nombre ?? ('Vista ' . $vistaId),
                'orden' => $rule->orden,
                'completada' => $isComplete,
                'disponible' => $reason === null,
                'motivo' => $reason,
                'url' => route('modules.tickets.show.vista', ['ticketId' => $ticketId, 'vistaId' => $vistaId]),
            ];
        });

        return response()->json([
            'ticketId' => $ticketId,
            'vistas' => $vistas->values(),
        ]);
    }

    public function showVista(Request $request, int $ticketId, int $vistaId): View|RedirectResponse
    {
        $authData = $request->session()->get('erp_auth', []);
        $ctx = $this->ticketsService->resolveAuthContext($authData);
        if (!$ctx['isAdmin'] && !$ctx['canAttendTickets']) {
            abort(403, 'No tienes permiso para atender gestiones.');
        }

        $ticket = DB::table('ticket')->where('idticket', $ticketId)->first();
        if (!$ticket) {
            return redirect()->route('modules.tickets')->with('error', 'Ticket no encontrado.');
        }
        if (in_array(mb_strtolower(trim((string) $ticket->estado)), ['resuelto', 'cancelado'], true)) {
            return redirect()->route('modules.tickets')->with('info', 'Esta gestión ya está finalizada.');
        }

        $flujo = $this->resolveFlujoForTicket((int) $ticket->tipoOperacion_idtipoOperacion);
        $reglas = $flujo ? $this->getFlujoReglas((int) $flujo->idflujo) : collect();
        $currentRule = $reglas->firstWhere('vista_idvista', $vistaId);
        if (!$currentRule) {
            return redirect()->route('modules.tickets.show', ['ticketId' => $ticketId])
                ->with('error', 'La vista no pertenece al flujo de esta gestión.');
        }

        if (!$ctx['isAdmin'] && !in_array($vistaId, $ctx['allowedVistaIds'], true)) {
            abort(403, 'No tienes permiso para ver esta vista.');
        }

        if (!$this->getLatestHistorial($ticketId)) {
            $this->createInitialHistorial($ticketId, (int) $ticket->tipoOperacion_idtipoOperacion, $ctx['currentUser']);
        }
        $progress = $this->getRuleProgress($ticketId, $reglas);
        if (TicketFlowProgress::isComplete($currentRule, $progress)) {
            return redirect()->route('modules.tickets.show', ['ticketId' => $ticketId])
                ->with('info', 'Esta vista ya está completada.');
        }
        if (!TicketFlowProgress::isEligible($currentRule, $reglas, $progress)) {
            return redirect()->route('modules.tickets.show', ['ticketId' => $ticketId])
                ->with('error', 'Completa las vistas anteriores antes de atender esta vista única.');
        }

        $vista = DB::table('vista')->where('idvista', $vistaId)->first();
        if (!$vista) {
            return redirect()->route('modules.tickets.show', ['ticketId' => $ticketId])
                ->with('error', 'Vista no encontrada.');
        }
        $viewName = $this->resolveViewName($vistaId);
        if ($viewName === null) {
            abort(404, 'Plantilla de vista no encontrada.');
        }

        $tipoOperacion = DB::table('tipooperacion')
            ->where('idtipoOperacion', $ticket->tipoOperacion_idtipoOperacion)
            ->first();
        $extraData = $this->ticketsService->buildShowExtraData($ticket, $vistaId, $ticketId);

        $currentUser = $ctx['currentUser'];
        $lockResource = 'ticket.vista';
        $lockId = $this->viewLockId($ticketId, $vistaId);
        $lockResult = ResourceLock::acquire($lockResource, $lockId, $currentUser);
        if (!$lockResult['success']) {
            return redirect()->route('modules.tickets.show', ['ticketId' => $ticketId])
                ->with('error', 'Esta vista está siendo atendida por ' . ($lockResult['lock']['usuario'] ?? 'otro usuario') . '.');
        }
        if (!empty($lockResult['lock'])) {
            $this->publishLockEvent($lockResource, $lockId, $currentUser, 'locked', $lockResult['lock']['expires_at'] ?? null);
        }

        $latestForRule = DB::table('historialflujo')
            ->where('ticket_idticket', $ticketId)
            ->where('flujoregla_idflujoregla', (int) $currentRule->idflujoregla)
            ->orderByDesc('idhistorialflujo')
            ->first();
        if (($latestForRule->resultado ?? null) !== self::HISTORIAL_RESULT_ATENDIENDO
            || (string) ($latestForRule->usuario_usuario ?? '') !== $currentUser) {
            DB::table('historialflujo')->insert([
                'flujoregla_idflujoregla' => (int) $currentRule->idflujoregla,
                'ticket_idticket' => $ticketId,
                'usuario_usuario' => $currentUser,
                'vista_idvista' => $vistaId,
                'detalle' => 'Orden ' . $currentRule->orden,
                'resultado' => self::HISTORIAL_RESULT_ATENDIENDO,
                'fechaejecucion' => now()->format('Y-m-d H:i:s'),
            ]);
            $this->publishResourceEvent('ticket', (string) $ticketId, 'updated', ['action' => 'attend', 'vistaId' => $vistaId]);
        }

        $stepIndex = $reglas->search(fn ($rule) => (int) $rule->idflujoregla === (int) $currentRule->idflujoregla);
        $isFinalRule = $stepIndex !== false && $stepIndex === $reglas->count() - 1;
        $nextRule = $isFinalRule
            ? null
            : $this->resolveNextAvailableRule(
                $currentRule,
                $reglas,
                $progress,
                $ctx['allowedVistaIds'],
                $ctx['isAdmin'],
                $ticketId,
                $currentUser
            );
        $historial = $this->getLatestHistorial($ticketId);

        return view($viewName, array_merge([
            'ticket' => $ticket,
            'historial' => $historial,
            'vista' => $vista,
            'tipoOperacion' => $tipoOperacion,
            'user' => $authData,
            'actionLabel' => $isFinalRule
                ? 'Finalizar gestión'
                : ($nextRule ? 'Continuar' : 'Completar vista'),
            'actionValue' => $isFinalRule ? 'finish' : 'complete',
            'currentStep' => $stepIndex === false ? 1 : $stepIndex + 1,
            'totalSteps' => $reglas->count(),
            'nextVistaId' => $nextRule ? (int) $nextRule->vista_idvista : null,
            'cancelUrl' => route('modules.tickets.cancel', ['ticketId' => $ticketId]),
            'advanceUrl' => route('modules.tickets.advance', ['ticketId' => $ticketId]),
            'lockResource' => $lockResource,
            'lockId' => $lockId,
        ], $extraData));
    }

    public function cancel(Request $request, int $ticketId): RedirectResponse
    {
        $authData = $request->session()->get('erp_auth', []);
        $ctx = $this->ticketsService->resolveAuthContext($authData);
        $currentUser = $ctx['currentUser'];
        $vistaId = (int) $request->input('current_vista_id', 0);
        if (!$ctx['isAdmin'] && !$ctx['canAttendTickets']) {
            abort(403, 'No tienes permiso para atender gestiones.');
        }
        if ($vistaId <= 0) {
            return redirect()->route('modules.tickets')->with('error', 'No se indicó la vista que se está atendiendo.');
        }

        $lockResource = 'ticket.vista';
        $lockId = $this->viewLockId($ticketId, $vistaId);
        $lockInfo = ResourceLock::status($lockResource, $lockId);
        if ($lockInfo && ($lockInfo['usuario'] ?? '') !== $currentUser) {
            return redirect()->route('modules.tickets.show', ['ticketId' => $ticketId])
                ->with('error', 'Esta vista está siendo atendida por ' . ($lockInfo['usuario'] ?? 'otro usuario') . '.');
        }

        $ticket = DB::table('ticket')->where('idticket', $ticketId)->first();

        if ($ticket && $vistaId > 0) {
            $flujo = $this->resolveFlujoForTicket((int) $ticket->tipoOperacion_idtipoOperacion);
            $reglas = $flujo ? $this->getFlujoReglas((int) $flujo->idflujo) : collect();
            $currentRule = $reglas->firstWhere('vista_idvista', $vistaId);
            if ($currentRule && ($ctx['isAdmin'] || in_array($vistaId, $ctx['allowedVistaIds'], true))) {
                DB::table('historialflujo')->insert([
                    'flujoregla_idflujoregla' => (int) $currentRule->idflujoregla,
                    'ticket_idticket' => $ticketId,
                    'usuario_usuario' => $currentUser,
                    'vista_idvista' => $vistaId,
                    'detalle' => 'Cancelado por usuario',
                    'resultado' => self::HISTORIAL_RESULT_PENDIENTE,
                    'fechaejecucion' => now()->format('Y-m-d H:i:s'),
                ]);
            }
        }

        if ($lockInfo && ($lockInfo['usuario'] ?? '') === $currentUser) {
            ResourceLock::release($lockResource, $lockId, $currentUser);
            $this->publishLockEvent($lockResource, $lockId, $currentUser, 'released', null);
        }

        $this->publishResourceEvent('ticket', (string) $ticketId, 'updated', ['action' => 'cancel']);

        return redirect()
            ->route('modules.tickets.show', ['ticketId' => $ticketId])
            ->with('success', 'Ticket liberado correctamente.');
    }

    public function advance(Request $request, int $ticketId): RedirectResponse
    {
        $authData = $request->session()->get('erp_auth', []);
        $ctx = $this->ticketsService->resolveAuthContext($authData);
        $currentUser = $ctx['currentUser'];
        $vistaId = (int) $request->input('current_vista_id', 0);

        $ticket = DB::table('ticket')
            ->where('idticket', $ticketId)
            ->first();

        if (!$ticket) {
            return redirect()
                ->route('modules.tickets')
                ->with('error', 'Ticket no encontrado.');
        }

        if ($vistaId <= 0) {
            return redirect()->route('modules.tickets')->with('error', 'No se indicó la vista que se está atendiendo.');
        }

        $flujo = $this->resolveFlujoForTicket((int) $ticket->tipoOperacion_idtipoOperacion);
        if (!$flujo) {
            return redirect()
                ->route('modules.tickets')
                ->with('error', 'No se encontró flujo para el tipo de operación del ticket.');
        }

        $reglas = $this->getFlujoReglas((int) $flujo->idflujo);
        $currentRule = $reglas->firstWhere('vista_idvista', $vistaId);
        if (!$currentRule) {
            return redirect()
                ->route('modules.tickets.show', ['ticketId' => $ticketId])
                ->with('error', 'La vista indicada no pertenece al flujo de esta gestión.');
        }

        if (!$ctx['isAdmin'] && !$ctx['canAttendTickets']) {
            abort(403, 'No tienes permiso para atender gestiones.');
        }
        if (!$ctx['isAdmin'] && !in_array($vistaId, $ctx['allowedVistaIds'], true)) {
            abort(403, 'No tienes permiso para modificar esta vista.');
        }

        $progress = $this->getRuleProgress($ticketId, $reglas);
        if (TicketFlowProgress::isComplete($currentRule, $progress)) {
            return redirect()->route('modules.tickets.show', ['ticketId' => $ticketId])
                ->with('error', 'Esta vista ya está completada.');
        }
        if (!TicketFlowProgress::isEligible($currentRule, $reglas, $progress)) {
            return redirect()->route('modules.tickets.show', ['ticketId' => $ticketId])
                ->with('error', 'Completa las vistas anteriores antes de atender esta vista única.');
        }

        $lockResource = 'ticket.vista';
        $lockId = $this->viewLockId($ticketId, $vistaId);
        $lockResult = ResourceLock::acquire($lockResource, $lockId, $currentUser);
        if (!$lockResult['success']) {
            return redirect()->route('modules.tickets.show', ['ticketId' => $ticketId])
                ->with('error', 'Esta vista está siendo atendida por ' . ($lockResult['lock']['usuario'] ?? 'otro usuario') . '.');
        }

        $action = (string) $request->input('action', '');
        if (!in_array($action, ['complete', 'confirm', 'next', 'save', 'finish', 'back_to_1', 'back_to_2', 'back_to_previous', 'reject'], true)) {
            $this->releaseTicketViewLock($ticketId, $vistaId, $currentUser);
            return redirect()->route('modules.tickets.show', ['ticketId' => $ticketId])
                ->with('error', 'Acción no válida para esta vista.');
        }

        if (in_array($action, ['back_to_1', 'back_to_2', 'back_to_previous', 'reject'], true)) {
            $targetRule = match ($action) {
                'back_to_1' => $reglas->firstWhere('vista_idvista', 1),
                'back_to_2' => $reglas->firstWhere('vista_idvista', 2),
                default => $this->resolvePreviousRule($currentRule, $reglas),
            };
            if (!$targetRule) {
                $this->releaseTicketViewLock($ticketId, $vistaId, $currentUser);
                return redirect()->route('modules.tickets.show', ['ticketId' => $ticketId])
                    ->with('info', 'No existe una vista anterior válida para regresar.');
            }
            if (!$ctx['isAdmin'] && !in_array((int) $targetRule->vista_idvista, $ctx['allowedVistaIds'], true)) {
                $this->releaseTicketViewLock($ticketId, $vistaId, $currentUser);
                return redirect()->route('modules.tickets.show', ['ticketId' => $ticketId])
                    ->with('error', 'No tienes permiso para regresar a esa vista.');
            }

            $targetIndex = $reglas->search(fn ($rule) => (int) $rule->idflujoregla === (int) $targetRule->idflujoregla);
            foreach ($reglas->slice($targetIndex)->values() as $ruleToReset) {
                $ruleVistaId = (int) $ruleToReset->vista_idvista;
                $ruleLockId = $this->viewLockId($ticketId, $ruleVistaId);
                $activeLock = ResourceLock::status($lockResource, $ruleLockId);
                if ($activeLock && ($activeLock['usuario'] ?? '') !== $currentUser) {
                    $this->releaseTicketViewLock($ticketId, $vistaId, $currentUser);
                    return redirect()->route('modules.tickets.show', ['ticketId' => $ticketId])
                        ->with('error', 'No se puede regresar mientras una vista posterior está siendo atendida por otro usuario.');
                }
            }

            foreach ($reglas->slice($targetIndex)->values() as $ruleToReset) {
                $ruleVistaId = (int) $ruleToReset->vista_idvista;
                DB::table('historialflujo')->insert([
                    'flujoregla_idflujoregla' => (int) $ruleToReset->idflujoregla,
                    'ticket_idticket' => $ticketId,
                    'usuario_usuario' => $currentUser,
                    'vista_idvista' => $ruleVistaId,
                    'detalle' => 'Etapa pendiente por rechazo',
                    'resultado' => self::HISTORIAL_RESULT_PENDIENTE,
                    'fechaejecucion' => now()->format('Y-m-d H:i:s'),
                ]);
                if ($ruleVistaId !== $vistaId) {
                    $this->releaseTicketViewLock($ticketId, $ruleVistaId, $currentUser);
                }
            }

            $this->releaseTicketViewLock($ticketId, $vistaId, $currentUser);
            DB::table('ticket')->where('idticket', $ticketId)->update(['estado' => 'En proceso']);
            $this->publishResourceEvent('ticket', (string) $ticketId, 'updated', ['action' => 'back']);
            return redirect()->route('modules.tickets.show.vista', [
                'ticketId' => $ticketId,
                'vistaId' => (int) $targetRule->vista_idvista,
            ])
                ->with('info', 'Las vistas desde la etapa seleccionada quedaron pendientes para revisión.');
        }

        $progress = $this->getRuleProgress($ticketId, $reglas);
        $stepIndex = $reglas->search(fn ($rule) => (int) $rule->idflujoregla === (int) $currentRule->idflujoregla);
        $isFinalRule = $stepIndex !== false && $stepIndex === $reglas->count() - 1;
        if (($action === 'finish') !== $isFinalRule) {
            $this->releaseTicketViewLock($ticketId, $vistaId, $currentUser);
            return redirect()->route('modules.tickets.show', ['ticketId' => $ticketId])
                ->with('error', $isFinalRule ? 'La última vista debe finalizar la gestión.' : 'Solo la última vista puede finalizar la gestión.');
        }
        if ($isFinalRule && !TicketFlowProgress::arePreviousRulesComplete($currentRule, $reglas, $progress)) {
            $this->releaseTicketViewLock($ticketId, $vistaId, $currentUser);
            return redirect()->route('modules.tickets.show', ['ticketId' => $ticketId])
                ->with('error', 'No se puede finalizar hasta completar todas las vistas anteriores.');
        }

        if (in_array($vistaId, [1, 2, 3, 6, 7], true)) {
            $extraData = $this->ticketsService->buildShowExtraData($ticket, $vistaId, $ticketId);
            $storedTempData = $this->decodeTicketTempData($ticket);
            $submittedImeis = $vistaId === 2
                ? $request->input('imeis', [])
                : ($storedTempData['imeis'] ?? []);
            $submittedComponentImeis = $vistaId === 2
                ? $request->input('component_imeis', [])
                : ($storedTempData['component_imeis'] ?? []);
            $submittedPlacas = $vistaId === 1
                ? $request->input('placas', [])
                : ($storedTempData['placas'] ?? []);
            $submittedNumeros = $vistaId === 1
                ? $request->input('numeros', [])
                : ($storedTempData['numeros'] ?? []);
            $assignmentErrors = $this->validateVista3Assignments(
                $extraData,
                $submittedImeis,
                $submittedPlacas,
                $submittedNumeros,
                in_array($vistaId, [3, 6, 7], true),
                $vistaId === 3,
                $submittedComponentImeis
            );
            if ($assignmentErrors['messages'] !== []) {
                $this->releaseTicketViewLock($ticketId, $vistaId, $currentUser);
                return redirect()->back()
                    ->withInput()
                    ->with('error', implode(' ', $assignmentErrors['messages']))
                    ->with('invalidImeis', $assignmentErrors['invalidImeis']);
            }
        }

        $sessionKey = 'ticket_temp_' . $ticketId;
        DB::transaction(function () use ($request, $vistaId, $sessionKey, $ticketId, $currentUser, $currentRule, $ticket, $action) {
            $data = $this->ticketsService->persistTempSession($request, $vistaId, $sessionKey, $ticketId, $currentUser);
            DB::table('historialflujo')->insert([
                'flujoregla_idflujoregla' => (int) $currentRule->idflujoregla,
                'ticket_idticket' => $ticketId,
                'usuario_usuario' => $currentUser,
                'vista_idvista' => $vistaId,
                'detalle' => 'Orden ' . $currentRule->orden,
                'resultado' => $action === 'finish' ? self::HISTORIAL_RESULT_FINALIZADO : self::HISTORIAL_RESULT_COMPLETADO,
                'fechaejecucion' => now()->format('Y-m-d H:i:s'),
            ]);

            if ($action === 'finish') {
                $this->ticketsService->persistFinish(
                    $ticket,
                    $ticketId,
                    $data,
                    $currentUser,
                    fn ($resource, $id, $event, $payload) => $this->publishResourceEvent($resource, $id, $event, $payload)
                );
                DB::table('ticket')->where('idticket', $ticketId)->update([
                    'estado' => 'Resuelto',
                    'fechaHoraCierre' => now()->format('Y-m-d H:i:s'),
                ]);
            } else {
                DB::table('ticket')->where('idticket', $ticketId)->update(['estado' => 'En proceso']);
            }

        });

        $this->releaseTicketViewLock($ticketId, $vistaId, $currentUser);
        if ($action === 'finish') {
            $this->publishResourceEvent('almacen', '*', 'updated');
            $this->publishResourceEvent('ticket', (string) $ticketId, 'updated', ['action' => 'finish']);
            return redirect()->route('modules.tickets')
                ->with('success', 'Gestión finalizada y datos guardados correctamente.');
        }

        $this->publishResourceEvent('ticket', (string) $ticketId, 'updated', ['action' => 'complete', 'vistaId' => $vistaId]);
        $nextRule = $this->resolveNextAvailableRule(
            $currentRule,
            $reglas,
            $this->getRuleProgress($ticketId, $reglas),
            $ctx['allowedVistaIds'],
            $ctx['isAdmin'],
            $ticketId,
            $currentUser
        );
        if ($nextRule) {
            return redirect()->route('modules.tickets.show.vista', [
                'ticketId' => $ticketId,
                'vistaId' => (int) $nextRule->vista_idvista,
            ])->with('success', 'Vista completada correctamente.');
        }

        return redirect()->route('modules.tickets.show', ['ticketId' => $ticketId])
            ->with('success', 'Vista completada correctamente.');
    }

    private function createInitialHistorial(int $ticketId, int $tipoOperacionId, ?string $usuario = null): void
    {
        $this->ticketsService->ensureInitialHistorialForTicket($ticketId, $tipoOperacionId, $usuario);
    }

    private function resolveFlujoForTicket(int $tipoOperacionId): ?object
    {
        return DB::table('flujo')
            ->where('tipoOperacion_idtipoOperacion', $tipoOperacionId)
            ->orderBy('idflujo')
            ->first();
    }

    private function getFlujoReglas(int $flujoId): Collection
    {
        return DB::table('flujoregla')
            ->leftJoin('vista', 'flujoregla.vista_idvista', '=', 'vista.idvista')
            ->where('flujoregla.flujo_idflujo', $flujoId)
            ->where('flujoregla.estado', '1')
            ->orderBy('flujoregla.orden')
            ->orderBy('flujoregla.idflujoregla')
            ->select('flujoregla.*', 'vista.nombre as vista_nombre')
            ->get();
    }

    private function getRuleProgress(int $ticketId, Collection $reglas): array
    {
        $ruleIds = $reglas->pluck('idflujoregla')->map(fn ($id) => (int) $id)->all();
        if ($ruleIds === []) {
            return [];
        }

        return DB::table('historialflujo')
            ->where('ticket_idticket', $ticketId)
            ->whereIn('flujoregla_idflujoregla', $ruleIds)
            ->orderBy('idhistorialflujo')
            ->get()
            ->groupBy('flujoregla_idflujoregla')
            ->map(fn ($rows) => (string) $rows->last()->resultado)
            ->all();
    }

    private function viewLockId(int $ticketId, int $vistaId): string
    {
        return $ticketId . ':' . $vistaId;
    }

    private function releaseTicketViewLock(int $ticketId, int $vistaId, string $currentUser): void
    {
        $resource = 'ticket.vista';
        $lockId = $this->viewLockId($ticketId, $vistaId);
        $lock = ResourceLock::status($resource, $lockId);
        if ($lock && ($lock['usuario'] ?? '') === $currentUser) {
            ResourceLock::release($resource, $lockId, $currentUser);
            $this->publishLockEvent($resource, $lockId, $currentUser, 'released', null);
        }
    }

    private function decodeTicketTempData(object $ticket): array
    {
        $decoded = json_decode((string) ($ticket->temp_data ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function getLatestHistorial(int $ticketId): ?object
    {
        return DB::table('historialflujo')
            ->where('ticket_idticket', $ticketId)
            ->orderByDesc('idhistorialflujo')
            ->first();
    }

    private function resolveCurrentRule(?object $historial, Collection $reglas): ?object
    {
        if ($historial && !empty($historial->flujoregla_idflujoregla)) {
            $rule = $reglas->firstWhere('idflujoregla', (int) $historial->flujoregla_idflujoregla);
            if ($rule) {
                return $rule;
            }
        }

        return $reglas->first();
    }

    private function resolvePreviousRule(object $currentRule, Collection $reglas): ?object
    {
        $values = $reglas->values();
        $index = $values->search(fn($rule) => (int) $rule->idflujoregla === (int) $currentRule->idflujoregla);
        if ($index === false || $index <= 0) {
            return null;
        }

        return $values->get($index - 1);
    }

    private function resolveNextRule(object $currentRule, Collection $reglas): ?object
    {
        $values = $reglas->values();
        $index = $values->search(fn($rule) => (int) $rule->idflujoregla === (int) $currentRule->idflujoregla);
        if ($index === false) {
            return null;
        }

        return $values->get($index + 1);
    }

    private function resolveNextAvailableRule(
        object $currentRule,
        Collection $reglas,
        array $progress,
        array $allowedVistaIds,
        bool $isAdmin,
        int $ticketId,
        string $currentUser
    ): ?object {
        $nextRule = $this->resolveNextRule($currentRule, $reglas);
        if (!$nextRule) {
            return null;
        }

        $progress[(int) $currentRule->idflujoregla] = self::HISTORIAL_RESULT_COMPLETADO;
        $nextVistaId = (int) $nextRule->vista_idvista;
        if ((!$isAdmin && !in_array($nextVistaId, $allowedVistaIds, true))
            || TicketFlowProgress::isComplete($nextRule, $progress)
            || !TicketFlowProgress::isEligible($nextRule, $reglas, $progress)) {
            return null;
        }

        $lock = ResourceLock::status('ticket.vista', $this->viewLockId($ticketId, $nextVistaId));
        if ($lock && ($lock['usuario'] ?? '') !== $currentUser) {
            return null;
        }

        return $nextRule;
    }

    /**
     * @return array{
     *   messages: array<int, string>,
     *   invalidImeis: array<int, string>,
     *   missingImeis: array<int, string>,
     *   missingComponentImeis: array<int, string>,
     *   missingPlacas: array<int, string>,
     *   missingNumeros: array<int, string>
     * }
     */
    private function validateVista3Assignments(
        array $extraData,
        mixed $submittedImeis,
        mixed $submittedPlacas,
        mixed $submittedNumeros,
        bool $requireAllImeis = false,
        bool $requireVista1Assignments = false,
        mixed $submittedComponentImeis = []
    ): array {
        $submittedImeis = is_array($submittedImeis) ? $submittedImeis : [];
        $submittedPlacas = is_array($submittedPlacas) ? $submittedPlacas : [];
        $submittedNumeros = is_array($submittedNumeros) ? $submittedNumeros : [];
        $submittedComponentImeis = is_array($submittedComponentImeis) ? $submittedComponentImeis : [];
        $invalidImeis = [];
        $duplicateImeis = [];
        $missingImeis = [];
        $missingComponentImeis = [];
        $missingPlacas = [];
        $missingNumeros = [];
        $invalidPlacas = [];
        $duplicatePlacas = [];
        $invalidNumeros = [];
        $duplicateNumeros = [];
        $seenImeis = [];
        $seenPlacas = [];
        $seenNumeros = [];
        $normalize = static fn($value): string => mb_strtoupper(trim((string) $value));

        foreach ($submittedImeis as $imeiList) {
            foreach (is_array($imeiList) ? $imeiList : [$imeiList] as $imei) {
                $imei = trim((string) $imei);
                if ($imei === '') {
                    continue;
                }

                $normalizedImei = $normalize($imei);
                if (isset($seenImeis[$normalizedImei])) {
                    $duplicateImeis[] = $imei;
                }
                $seenImeis[$normalizedImei] = true;
            }
        }
        foreach ($submittedComponentImeis as $componentImeis) {
            foreach (is_array($componentImeis) ? $componentImeis : [$componentImeis] as $imei) {
                $imei = trim((string) $imei);
                if ($imei === '') {
                    continue;
                }

                $normalizedImei = $normalize($imei);
                if (isset($seenImeis[$normalizedImei])) {
                    $duplicateImeis[] = $imei;
                }
                $seenImeis[$normalizedImei] = true;
            }
        }

        $allowedExistingPlacas = collect($extraData['vehiculosExistentes'] ?? [])
            ->pluck('placa')
            ->map($normalize)
            ->filter()
            ->unique()
            ->all();
        $allowedPlacas = $allowedExistingPlacas;

        foreach (is_array($extraData['vehiculosTemporales'] ?? null) ? $extraData['vehiculosTemporales'] : [] as $vehiculoTemporal) {
            $placa = trim((string) ($vehiculoTemporal['placa'] ?? ''));
            $normalizedPlaca = $normalize($placa);
            if ($normalizedPlaca === '' || in_array($normalizedPlaca, $allowedExistingPlacas, true)) {
                continue;
            }

            $alreadyRegistered = DB::table('vehiculo')
                ->whereRaw('UPPER(TRIM(placa)) = ?', [$normalizedPlaca])
                ->exists();
            if (!$alreadyRegistered) {
                $allowedPlacas[] = $normalizedPlaca;
            }
        }

        $availableNumeros = collect($extraData['numerosTelefonicos'] ?? [])
            ->pluck('numeroTelefonico')
            ->map($normalize)
            ->filter()
            ->unique()
            ->all();

        foreach ($extraData['equipamiento'] ?? [] as $item) {
            $detailId = (string) $item->iddetalleCotizacion;
            $itemImeis = $submittedImeis[$detailId] ?? [];
            $itemPlacas = $submittedPlacas[$detailId] ?? [];
            $itemNumeros = $submittedNumeros[$detailId] ?? [];
            $availableImeis = is_array($item->availableImeis) ? $item->availableImeis : [];
            $validItemImeis = array_values(array_filter(
                is_array($itemImeis) ? $itemImeis : [],
                static fn ($imei) => trim((string) $imei) !== ''
            ));

            if ($requireAllImeis && count($validItemImeis) < (int) $item->cantidad) {
                $missingImeis[] = (string) $item->producto;
            }
            if ($requireVista1Assignments) {
                for ($index = 0; $index < (int) $item->cantidad; $index++) {
                    if (trim((string) ($itemPlacas[$index] ?? '')) === '') {
                        $missingPlacas[] = (string) $item->producto;
                    }
                    if (trim((string) ($itemNumeros[$index] ?? '')) === '') {
                        $missingNumeros[] = (string) $item->producto;
                    }
                }
            }

            foreach (is_array($itemImeis) ? $itemImeis : [] as $imei) {
                $imei = trim((string) $imei);
                if ($imei === '') {
                    continue;
                }

                if (!in_array($imei, $availableImeis, true)) {
                    $invalidImeis[] = $imei;
                }

            }

            foreach (is_array($itemPlacas) ? $itemPlacas : [] as $placa) {
                $placa = trim((string) $placa);
                if ($placa === '') {
                    continue;
                }

                $normalizedPlaca = $normalize($placa);
                if (!in_array($normalizedPlaca, $allowedPlacas, true)) {
                    $invalidPlacas[] = $placa;
                }

                if (isset($seenPlacas[$normalizedPlaca])) {
                    $duplicatePlacas[] = $placa;
                }
                $seenPlacas[$normalizedPlaca] = true;
            }

            foreach (is_array($itemNumeros) ? $itemNumeros : [] as $numero) {
                $numero = trim((string) $numero);
                if ($numero === '') {
                    continue;
                }

                $normalizedNumero = $normalize($numero);
                if (!in_array($normalizedNumero, $availableNumeros, true)) {
                    $invalidNumeros[] = $numero;
                }

                if (isset($seenNumeros[$normalizedNumero])) {
                    $duplicateNumeros[] = $numero;
                }
                $seenNumeros[$normalizedNumero] = true;
            }
        }

        foreach (array_merge(
            $extraData['componentAssignments']['sensors'] ?? [],
            $extraData['componentAssignments']['accessories'] ?? []
        ) as $component) {
            $detailId = (string) $component['detailId'];
            $unitIndex = (string) $component['unitIndex'];
            $componentValues = $submittedComponentImeis[$detailId] ?? [];
            $imei = trim((string) (is_array($componentValues) ? ($componentValues[$unitIndex] ?? '') : ''));
            if ($imei === '') {
                if ($requireAllImeis) {
                    $categoryLabel = $component['category'] === 'sensor' ? 'Sensor' : 'Accesorio';
                    $missingComponentImeis[] = $categoryLabel . ': ' . $component['producto'];
                }
                continue;
            }

            if (!in_array($imei, $component['availableImeis'] ?? [], true)) {
                $invalidImeis[] = $imei;
            }
        }

        $messages = [];
        if ($invalidImeis !== []) {
            $messages[] = 'Uno o más IMEIs no están disponibles para este producto o están en uso: ' . implode(', ', array_unique($invalidImeis));
        }
        if ($duplicateImeis !== []) {
            $messages[] = 'No puedes asignar el mismo IMEI a más de un equipo, sensor o accesorio: ' . implode(', ', array_unique($duplicateImeis));
        }
        if ($missingImeis !== []) {
            $messages[] = 'Asigna un IMEI válido a cada equipo desde la vista 2. Falta completar: ' . implode(', ', array_unique($missingImeis));
        }
        if ($missingComponentImeis !== []) {
            $messages[] = 'Asigna un IMEI válido a cada sensor y accesorio seleccionado desde la vista 2. Falta completar: ' . implode(', ', array_unique($missingComponentImeis));
        }
        if ($missingPlacas !== []) {
            $messages[] = 'Asigna una placa a cada equipo desde la vista 1. Falta completar: ' . implode(', ', array_unique($missingPlacas));
        }
        if ($missingNumeros !== []) {
            $messages[] = 'Asigna un número telefónico a cada equipo desde la vista 1. Falta completar: ' . implode(', ', array_unique($missingNumeros));
        }
        if ($invalidPlacas !== []) {
            $messages[] = 'Una o más placas no pertenecen al cliente o grupo de esta gestión, o ya no están disponibles: ' . implode(', ', array_unique($invalidPlacas));
        }
        if ($duplicatePlacas !== []) {
            $messages[] = 'No puedes asignar la misma placa a más de un equipo: ' . implode(', ', array_unique($duplicatePlacas));
        }
        if ($invalidNumeros !== []) {
            $messages[] = 'Uno o más números telefónicos ya no están disponibles; solo se permiten números en estado Disponible: ' . implode(', ', array_unique($invalidNumeros));
        }
        if ($duplicateNumeros !== []) {
            $messages[] = 'No puedes asignar el mismo número telefónico a más de un equipo: ' . implode(', ', array_unique($duplicateNumeros));
        }

        return [
            'messages' => $messages,
            'invalidImeis' => array_values(array_unique(array_merge($invalidImeis, $duplicateImeis))),
            'missingImeis' => array_values(array_unique($missingImeis)),
            'missingComponentImeis' => array_values(array_unique($missingComponentImeis)),
            'missingPlacas' => array_values(array_unique($missingPlacas)),
            'missingNumeros' => array_values(array_unique($missingNumeros)),
        ];
    }

    private function resolveViewName(int $vistaId): ?string
    {
        $primary = 'vistas.vista_' . $vistaId;
        if (view()->exists($primary)) {
            return $primary;
        }

        $fallback = 'vistas.vista' . $vistaId;
        if (view()->exists($fallback)) {
            return $fallback;
        }

        return null;
    }

    public function storeVehiculo(Request $request)
    {
        $request->validate([
            'ticket_id' => 'required|integer|exists:ticket,idticket',
            'placa' => 'required|string|max:20',
            'cliente_idcliente' => 'required|string|max:20|exists:cliente,idcliente',
            'tipoUnidad_idtable1' => 'required|exists:tipovehiculo,idtipoVehiculo',
            'anio' => 'nullable|integer|min:1900|max:2100',
            'marca' => 'nullable|string|max:100',
            'modelo' => 'nullable|string|max:100',
            'color' => 'nullable|string|max:100',
            'tracto' => 'nullable|in:Si,No',
        ]);

        $ticketId = (int) $request->input('ticket_id');
        $placa = strtoupper(trim($request->input('placa')));
        $clienteId = $request->input('cliente_idcliente');
        $tipoUnidad = $request->input('tipoUnidad_idtable1');
        $anio = $request->input('anio');
        $marca = $request->input('marca');
        $modelo = $request->input('modelo');
        $color = $request->input('color');
        $tracto = $request->input('tracto');

        $ticket = DB::table('ticket')->where('idticket', $ticketId)->first();
        if (!$ticket) {
            return response()->json(['success' => false, 'message' => 'Ticket no encontrado.'], 404);
        }

        $reference = (string) ($ticket->pedidoReferencia ?? '');
        $isBatchReference = DB::table('cotizacion')->where('batch_id', $reference)->exists();
        $ticketCotizaciones = DB::table('cotizacion')
            ->when(
                $isBatchReference,
                fn($query) => $query->where('batch_id', $reference),
                fn($query) => $query->where('nroCotizacion', $reference)
            )
            ->pluck('cliente_idcliente')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $groupIds = empty($ticketCotizaciones)
            ? []
            : DB::table('detallegrupocliente')
                ->whereIn('cliente_idcliente', $ticketCotizaciones)
                ->pluck('grupoCliente_idgrupoCliente')
                ->filter()
                ->unique()
                ->values()
                ->all();
        $groupClientIds = empty($groupIds)
            ? []
            : DB::table('detallegrupocliente')
                ->whereIn('grupoCliente_idgrupoCliente', $groupIds)
                ->pluck('cliente_idcliente')
                ->filter()
                ->unique()
                ->values()
                ->all();
        $allowedClientIds = array_map(
            'strval',
            array_unique(array_merge($ticketCotizaciones, $groupClientIds))
        );
        if (!in_array((string) $clienteId, $allowedClientIds, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Solo puedes registrar el vehículo para un cliente de esta cotización o de su grupo.',
            ], 422);
        }

        $existe = DB::table('vehiculo')
            ->whereRaw('UPPER(TRIM(placa)) = ?', [$placa])
            ->exists();

        if ($existe) {
            return response()->json(['success' => false, 'message' => 'Esta placa ya está registrada en el sistema.']);
        }

        $newVehiculo = [
            'placa' => $placa,
            'cliente_idcliente' => $clienteId,
            'tipoUnidad_idtable1' => $tipoUnidad,
            'anio' => $anio,
            'marca' => $marca,
            'modelo' => $modelo,
            'color' => $color,
            'tracto' => $tracto,
        ];

        $savedVehiculo = DB::transaction(function () use ($ticketId, $placa, $newVehiculo): ?array {
            $lockedTicket = DB::table('ticket')
                ->where('idticket', $ticketId)
                ->lockForUpdate()
                ->first(['temp_data']);
            if (!$lockedTicket) {
                throw new \RuntimeException('No se encontró el ticket para guardar el vehículo temporal.');
            }

            $decodedTempData = json_decode((string) ($lockedTicket->temp_data ?? ''), true);
            $tempData = is_array($decodedTempData) ? $decodedTempData : [];
            $tempData['vehiculos'] = $tempData['vehiculos'] ?? [];
            $alreadyTemp = collect($tempData['vehiculos'])->contains(function ($vehiculo) use ($placa) {
                return mb_strtoupper(trim((string) ($vehiculo['placa'] ?? ''))) === $placa;
            });
            if ($alreadyTemp) {
                return null;
            }

            $tempData['vehiculos'][] = $newVehiculo;
            DB::table('ticket')
                ->where('idticket', $ticketId)
                ->update(['temp_data' => json_encode($tempData, JSON_UNESCAPED_UNICODE)]);

            return $newVehiculo;
        });
        if ($savedVehiculo === null) {
            return response()->json(['success' => false, 'message' => 'Esta placa ya está registrada en la gestión.']);
        }

        return response()->json([
            'success' => true,
            'vehiculo' => $savedVehiculo,
        ]);
    }
}
