<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Permission\HandlesResourceLock;
use App\Support\ResourceLock;
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

    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';

    private const ESTADOS = [
        'Activo',
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
        $userRoles = collect($authData['roles'] ?? [])
            ->map(fn ($role) => mb_strtolower(trim((string) $role)))
            ->filter()
            ->values();
        $isAdmin = $userRoles->contains('admin');
        $currentPermissions = collect($authData['permissions']['tickets'] ?? [])
            ->map(fn ($value) => \App\Support\ErpPermission::normalizeAction((string) $value))
            ->filter()
            ->unique()
            ->values();

        return [
            'authData' => $authData,
            'currentUser' => (string) ($authData['usuario'] ?? 'anonimo'),
            'isAdmin' => $isAdmin,
            'canSeeFlow' => $isAdmin || $currentPermissions->contains('ver_flujo'),
            'canAttendTickets' => $isAdmin || $currentPermissions->contains('ver'),
            'allowedVistaIds' => collect($authData['allowed_vistas'] ?? [])
                ->map(fn ($id) => is_numeric($id) ? (int) $id : null)
                ->filter(fn ($id) => $id !== null && $id > 0)
                ->unique()
                ->values()
                ->all(),
        ];
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

        if (!$context['isAdmin']) {
            if (!$context['canSeeFlow'] && $context['allowedVistaIds'] === []) {
                $baseQuery->whereRaw('1 = 0');
            } elseif (!$context['canSeeFlow']) {
                $baseQuery->whereIn('hf.vista_idvista', $context['allowedVistaIds']);
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
        $lockInfo = ResourceLock::status('ticket', (string) ($row->idticket ?? ''));
        $row->lock_usuario = $lockInfo['usuario'] ?? null;
        $row->lock_expires_at = $lockInfo['expires_at'] ?? null;
        $row->is_locked = $lockInfo !== null;
        $row->locked_by_other = $row->is_locked && $row->lock_usuario !== null && $row->lock_usuario !== $currentUser;

        $displayInfo = $this->resolveTicketListDisplayInfo($row);
        $row->usuarioReceptor = $displayInfo['receptor'];
        $row->estado_fase_texto = $displayInfo['fase_texto'];
        $row->estado_accion_texto = $displayInfo['accion_texto'];

        return $row;
    }

    private function buildTicketIndexStats($statsBase): array
    {
        return [
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
            ['label' => 'Activos', 'value' => $stats['activos']],
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
                        $label = trim($nomenclatura . ' ' . $detalle);

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
        $historialUser = $authUser !== ''
            ? $authUser
            : (string) ($validated['usuarioEmisor'] ?? 'anonimo');

        $this->createInitialHistorial($ticketId, (int) $validated['tipoOperacion_idtipoOperacion'], $historialUser);

        // Emitir evento realtime para que las listas se actualicen sin recargar.
        $this->publishResourceEvent('ticket', (string) $ticketId, 'created');

        return redirect()
            ->route('modules.tickets')
            ->with('success', 'Ticket creado correctamente.');
    }

    public function latestRow(Request $request)
    {
        $authData = $request->session()->get('erp_auth', []);
        $userRoles = collect($authData['roles'] ?? [])
            ->map(fn ($role) => mb_strtolower(trim((string) $role)))
            ->filter()
            ->values();
        $isAdmin = $userRoles->contains('admin');
        $currentPermissions = collect($authData['permissions']['tickets'] ?? [])
            ->map(fn ($value) => \App\Support\ErpPermission::normalizeAction((string) $value))
            ->filter()
            ->unique()
            ->values();
        $canSeeFlow = $isAdmin || $currentPermissions->contains('ver_flujo');
        $canAttendTickets = $isAdmin || $currentPermissions->contains('ver');
        $allowedVistaIds = collect($authData['allowed_vistas'] ?? [])
            ->map(fn ($id) => is_numeric($id) ? (int) $id : null)
            ->filter(fn ($id) => $id !== null && $id > 0)
            ->unique()
            ->values()
            ->all();

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
                $baseQuery->whereIn('hf.vista_idvista', $allowedVistaIds);
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
        $lockInfo = ResourceLock::status('ticket', (string) ($row->idticket ?? ''));
        $row->lock_usuario = $lockInfo['usuario'] ?? null;
        $row->lock_expires_at = $lockInfo['expires_at'] ?? null;
        $row->is_locked = $lockInfo !== null;
        $row->locked_by_other = $row->is_locked && $row->lock_usuario !== null && $row->lock_usuario !== (string) ($authData['usuario'] ?? '');

        $displayInfo = $this->resolveTicketListDisplayInfo($row);
        $row->usuarioReceptor = $displayInfo['receptor'];
        $row->estado_fase_texto = $displayInfo['fase_texto'];
        $row->estado_accion_texto = $displayInfo['accion_texto'];

        $columns = [
            ['key' => 'idticket', 'label' => 'Gestión', 'type' => 'text' ],
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
                'required' => true,
                'fileKind' => 'file',
                'accept' => 'image/jpeg,image/png,image/webp,application/pdf',
                'colSpan' => 1,
                'helpText' => 'campo imagen obligatorio',
            ],
            [
                'name' => 'estado',
                'type' => 'select',
                'label' => 'Estado',
                'required' => true,
                'colSpan' => 1,
                'options' => array_combine(self::ESTADOS, self::ESTADOS),
                'value' => $record->estado ?? 'Activo',
            ],
        ];
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
            'activo', 'nuevo', 'asignado' => 'Activo',
            'en proceso', 'en progreso' => 'En proceso',
            'resuelto', 'cancelado' => 'Resuelto',
            default => 'Activo',
        };
    }

    private function estadoAliases(string $estado): array
    {
        return match ($this->normalizeEstadoValue($estado)) {
            'Activo' => ['activo', 'nuevo', 'asignado'],
            'En proceso' => ['en proceso', 'en progreso'],
            'Resuelto' => ['resuelto', 'cancelado'],
            default => ['activo'],
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

    public function show(Request $request, int $ticketId): View|RedirectResponse
    {
        $authData = $request->session()->get('erp_auth', []);
        $currentUser = (string) ($authData['usuario'] ?? 'anonimo');
        $allowedVistaIds = collect($authData['allowed_vistas'] ?? [])
            ->map(fn ($id) => is_numeric($id) ? (int) $id : null)
            ->filter(fn ($id) => $id !== null && $id > 0)
            ->unique()
            ->values()
            ->all();

        $ticket = DB::table('ticket')
            ->where('idticket', $ticketId)
            ->first();

        if (!$ticket) {
            return redirect()
                ->route('modules.tickets')
                ->with('error', 'Ticket no encontrado.');
        }

        $flujo = $this->resolveFlujoForTicket((int) $ticket->tipoOperacion_idtipoOperacion);
        if (!$flujo) {
            return redirect()
                ->route('modules.tickets')
                ->with('error', 'No se encontró flujo para el tipo de operación del ticket.');
        }

        $reglas = $this->getFlujoReglas((int) $flujo->idflujo);
        if ($reglas->isEmpty()) {
            return redirect()
                ->route('modules.tickets')
                ->with('error', 'No hay reglas de flujo disponibles para este ticket.');
        }

        $historial = $this->getLatestHistorial($ticketId);
        if (!$historial) {
            $this->createInitialHistorial($ticketId, (int) $ticket->tipoOperacion_idtipoOperacion, $currentUser);
            $historial = $this->getLatestHistorial($ticketId);
        }

        if (!$historial) {
            return redirect()
                ->route('modules.tickets')
                ->with('error', 'No se pudo inicializar el historial del ticket.');
        }

        $currentRule = $this->resolveCurrentRule($historial, $reglas);
        if (!$currentRule) {
            return redirect()
                ->route('modules.tickets')
                ->with('error', 'No se encontró la regla actual del flujo.');
        }

        $vistaId = (int) $currentRule->vista_idvista;
        if ($vistaId <= 0 || !in_array($vistaId, $allowedVistaIds, true)) {
            abort(403, 'No tienes permiso para ver esta vista.');
        }

        $lockInfo = ResourceLock::status('ticket', (string) $ticketId);
        if ($lockInfo && ($lockInfo['usuario'] ?? '') !== $currentUser) {
            return redirect()
                ->route('modules.tickets')
                ->with('error', 'El ticket está siendo atendido por ' . $lockInfo['usuario'] . '.');
        }

        $lockResult = ResourceLock::acquire('ticket', (string) $ticketId, $currentUser);
        if (!$lockResult['success']) {
            $lockedBy = $lockResult['lock']['usuario'] ?? 'otro usuario';
            return redirect()
                ->route('modules.tickets')
                ->with('error', 'El ticket está siendo atendido por ' . $lockedBy . '.');
        }

        if (!empty($lockResult['lock'])) {
            $this->publishLockEvent('ticket', (string) $ticketId, $currentUser, 'locked', $lockResult['lock']['expires_at'] ?? null);
        }

        $needsAttendRow = true;
        if ($historial && ($historial->resultado ?? '') === self::HISTORIAL_RESULT_ATENDIENDO) {
            $sameUser = (string) ($historial->usuario_usuario ?? '') === $currentUser;
            $sameVista = (int) ($historial->vista_idvista ?? 0) === $vistaId;
            if ($sameUser && $sameVista) {
                $needsAttendRow = false;
            }
        }

        if ($needsAttendRow) {
            DB::table('historialflujo')->insert([
                'flujoregla_idflujoregla' => (int) $currentRule->idflujoregla,
                'ticket_idticket' => (int) $ticketId,
                'usuario_usuario' => $currentUser,
                'vista_idvista' => $vistaId,
                'detalle' => 'Orden ' . $currentRule->orden,
                'resultado' => self::HISTORIAL_RESULT_ATENDIENDO,
                'fechaejecucion' => now()->format('Y-m-d H:i:s'),
            ]);

            $this->publishResourceEvent('ticket', (string) $ticketId, 'updated', ['action' => 'attend']);
        }

        $nextRule = $this->resolveNextRule($currentRule, $reglas);
        $hasNext = $nextRule !== null;
        $canAdvance = $hasNext && in_array((int) $nextRule->vista_idvista, $allowedVistaIds, true);

        if (!$hasNext) {
            $actionLabel = 'Finalizar';
            $actionValue = 'finish';
        } elseif ($canAdvance) {
            $actionLabel = 'Siguiente';
            $actionValue = 'next';
        } else {
            $actionLabel = 'Guardar';
            $actionValue = 'save';
        }

        $vista = DB::table('vista')
            ->where('idvista', $vistaId)
            ->first();

        if (!$vista) {
            return redirect()
                ->route('modules.tickets')
                ->with('error', 'Vista no encontrada.');
        }

        $viewName = $this->resolveViewName($vistaId);
        if ($viewName === null) {
            abort(404, 'Plantilla de vista no encontrada.');
        }

        $tipoOperacion = DB::table('tipooperacion')
            ->where('idtipoOperacion', $ticket->tipoOperacion_idtipoOperacion)
            ->first();

        return view($viewName, [
            'ticket' => $ticket,
            'historial' => $historial,
            'vista' => $vista,
            'tipoOperacion' => $tipoOperacion,
            'user' => $authData,
            'actionLabel' => $actionLabel,
            'actionValue' => $actionValue,
            'nextVistaId' => $nextRule ? (int) $nextRule->vista_idvista : null,
            'cancelUrl' => route('modules.tickets.cancel', ['ticketId' => $ticketId]),
            'advanceUrl' => route('modules.tickets.advance', ['ticketId' => $ticketId]),
            'lockResource' => 'ticket',
            'lockId' => (string) $ticketId,
        ]);
    }

    public function cancel(Request $request, int $ticketId): RedirectResponse
    {
        $authData = $request->session()->get('erp_auth', []);
        $currentUser = (string) ($authData['usuario'] ?? 'anonimo');

        $ticket = DB::table('ticket')
            ->where('idticket', $ticketId)
            ->first();

        if ($ticket) {
            $flujo = $this->resolveFlujoForTicket((int) $ticket->tipoOperacion_idtipoOperacion);
            if ($flujo) {
                $reglas = $this->getFlujoReglas((int) $flujo->idflujo);
                $historial = $this->getLatestHistorial($ticketId);
                $currentRule = $this->resolveCurrentRule($historial, $reglas);

                if ($currentRule) {
                    DB::table('historialflujo')->insert([
                        'flujoregla_idflujoregla' => (int) $currentRule->idflujoregla,
                        'ticket_idticket' => (int) $ticketId,
                        'usuario_usuario' => $currentUser,
                        'vista_idvista' => (int) $currentRule->vista_idvista,
                        'detalle' => 'Cancelado por usuario',
                        'resultado' => self::HISTORIAL_RESULT_PENDIENTE,
                        'fechaejecucion' => now()->format('Y-m-d H:i:s'),
                    ]);
                }
            }
        }

        $lockInfo = ResourceLock::status('ticket', (string) $ticketId);

        if ($lockInfo && ($lockInfo['usuario'] ?? '') === $currentUser) {
            ResourceLock::release('ticket', (string) $ticketId, $currentUser);
            $this->publishLockEvent('ticket', (string) $ticketId, $currentUser, 'released', null);
        }

        $this->publishResourceEvent('ticket', (string) $ticketId, 'updated', ['action' => 'cancel']);

        return redirect()
            ->route('modules.tickets')
            ->with('success', 'Ticket liberado correctamente.');
    }

    public function advance(Request $request, int $ticketId): RedirectResponse
    {
        $authData = $request->session()->get('erp_auth', []);
        $currentUser = (string) ($authData['usuario'] ?? 'anonimo');
        $allowedVistaIds = collect($authData['allowed_vistas'] ?? [])
            ->map(fn ($id) => is_numeric($id) ? (int) $id : null)
            ->filter(fn ($id) => $id !== null && $id > 0)
            ->unique()
            ->values()
            ->all();

        $ticket = DB::table('ticket')
            ->where('idticket', $ticketId)
            ->first();

        if (!$ticket) {
            return redirect()
                ->route('modules.tickets')
                ->with('error', 'Ticket no encontrado.');
        }

        $lockInfo = ResourceLock::status('ticket', (string) $ticketId);
        if (!$lockInfo || ($lockInfo['usuario'] ?? '') !== $currentUser) {
            return redirect()
                ->route('modules.tickets')
                ->with('error', 'No puedes avanzar este ticket porque no lo estás atendiendo.');
        }

        $flujo = $this->resolveFlujoForTicket((int) $ticket->tipoOperacion_idtipoOperacion);
        if (!$flujo) {
            return redirect()
                ->route('modules.tickets')
                ->with('error', 'No se encontró flujo para el tipo de operación del ticket.');
        }

        $reglas = $this->getFlujoReglas((int) $flujo->idflujo);
        $historial = $this->getLatestHistorial($ticketId);
        $currentRule = $this->resolveCurrentRule($historial, $reglas);

        if (!$currentRule) {
            return redirect()
                ->route('modules.tickets')
                ->with('error', 'No se encontró la regla actual del flujo.');
        }

        $vistaId = (int) $currentRule->vista_idvista;
        if ($vistaId <= 0 || !in_array($vistaId, $allowedVistaIds, true)) {
            abort(403, 'No tienes permiso para modificar esta vista.');
        }

        $nextRule = $this->resolveNextRule($currentRule, $reglas);
        $hasNext = $nextRule !== null;
        $canAdvance = $hasNext && in_array((int) $nextRule->vista_idvista, $allowedVistaIds, true);

        $action = (string) $request->input('action', '');
        $expectedAction = !$hasNext
            ? 'finish'
            : ($canAdvance ? 'next' : 'save');

        if ($action !== $expectedAction) {
            return redirect()
                ->route('modules.tickets')
                ->with('error', 'Acción no válida para el estado actual del ticket.');
        }

        DB::table('historialflujo')->insert([
            'flujoregla_idflujoregla' => (int) $currentRule->idflujoregla,
            'ticket_idticket' => (int) $ticketId,
            'usuario_usuario' => $currentUser,
            'vista_idvista' => $vistaId,
            'detalle' => 'Orden ' . $currentRule->orden,
            'resultado' => $action === 'finish' ? self::HISTORIAL_RESULT_FINALIZADO : self::HISTORIAL_RESULT_COMPLETADO,
            'fechaejecucion' => now()->format('Y-m-d H:i:s'),
        ]);

        if ($action === 'finish') {
            DB::table('ticket')->where('idticket', $ticketId)->update([
                'estado' => 'Resuelto',
                'fechaHoraCierre' => now()->format('Y-m-d H:i:s'),
            ]);

            ResourceLock::release('ticket', (string) $ticketId, $currentUser);
            $this->publishLockEvent('ticket', (string) $ticketId, $currentUser, 'released', null);
            $this->publishResourceEvent('ticket', (string) $ticketId, 'updated', ['action' => 'finish']);

            return redirect()
                ->route('modules.tickets')
                ->with('success', 'Ticket finalizado correctamente.');
        }

        if ($nextRule) {
            DB::table('historialflujo')->insert([
                'flujoregla_idflujoregla' => (int) $nextRule->idflujoregla,
                'ticket_idticket' => (int) $ticketId,
                'usuario_usuario' => $currentUser,
                'vista_idvista' => (int) $nextRule->vista_idvista,
                'detalle' => 'Orden ' . $nextRule->orden,
                'resultado' => $action === 'next' ? self::HISTORIAL_RESULT_ATENDIENDO : self::HISTORIAL_RESULT_PENDIENTE,
                'fechaejecucion' => now()->format('Y-m-d H:i:s'),
            ]);
        }

        DB::table('ticket')->where('idticket', $ticketId)->update([
            'estado' => 'En proceso',
        ]);

        if ($action === 'next') {
            $lockResult = ResourceLock::acquire('ticket', (string) $ticketId, $currentUser);
            if (!empty($lockResult['lock'])) {
                $this->publishLockEvent('ticket', (string) $ticketId, $currentUser, 'locked', $lockResult['lock']['expires_at'] ?? null);
            }

            $this->publishResourceEvent('ticket', (string) $ticketId, 'updated', ['action' => 'next']);

            return redirect()
                ->route('modules.tickets.show', ['ticketId' => $ticketId]);
        }

        ResourceLock::release('ticket', (string) $ticketId, $currentUser);
        $this->publishLockEvent('ticket', (string) $ticketId, $currentUser, 'released', null);
        $this->publishResourceEvent('ticket', (string) $ticketId, 'updated', ['action' => 'save']);

        return redirect()
            ->route('modules.tickets')
            ->with('success', 'Ticket guardado correctamente.');
    }

    private function createInitialHistorial(int $ticketId, int $tipoOperacionId, ?string $usuario = null): void
    {
        $flujo = $this->resolveFlujoForTicket($tipoOperacionId);
        if (!$flujo) {
            return;
        }

        $reglas = $this->getFlujoReglas((int) $flujo->idflujo);
        $firstRule = $reglas->first();
        if (!$firstRule) {
            return;
        }

        $usuario = trim((string) $usuario);
        if ($usuario === '') {
            $usuario = 'anonimo';
        }

        DB::table('historialflujo')->insert([
            'flujoregla_idflujoregla' => (int) $firstRule->idflujoregla,
            'ticket_idticket' => (int) $ticketId,
            'usuario_usuario' => $usuario,
            'vista_idvista' => (int) $firstRule->vista_idvista,
            'detalle' => 'Orden ' . $firstRule->orden,
            'resultado' => self::HISTORIAL_RESULT_PENDIENTE,
            'fechaejecucion' => now()->format('Y-m-d H:i:s'),
        ]);
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
            ->where('flujo_idflujo', $flujoId)
            ->where('estado', '1')
            ->orderBy('orden')
            ->orderBy('idflujoregla')
            ->get();
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

    private function resolveNextRule(object $currentRule, Collection $reglas): ?object
    {
        $values = $reglas->values();
        $index = $values->search(fn ($rule) => (int) $rule->idflujoregla === (int) $currentRule->idflujoregla);
        if ($index === false) {
            return null;
        }

        return $values->get($index + 1);
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

    private function resolveTicketListDisplayInfo(object $row): array
    {
        $lockUser = trim((string) ($row->lock_usuario ?? ''));
        $historialUser = trim((string) ($row->historial_usuario ?? ''));
        $originalReceptor = trim((string) ($row->usuarioReceptor ?? ''));
        $receptor = $lockUser !== ''
            ? $lockUser
            : ($historialUser !== '' ? $historialUser : ($originalReceptor !== '' ? $originalReceptor : '-'));

        $accionTexto = null;
        if ($lockUser !== '') {
            $accionTexto = 'Atendiendo: ' . $lockUser;
        } elseif ($historialUser !== '') {
            $accionTexto = 'Último en atender: ' . $historialUser;
        }

        $faseTexto = null;
        $estadoActual = $this->normalizeEstadoValue((string) ($row->estado ?? ''));
        if (mb_strtolower($estadoActual) !== 'activo') {
            $tipoOperacionId = (int) ($row->tipo_operacion_id ?? 0);
            if ($tipoOperacionId > 0) {
                $flujo = $this->resolveFlujoForTicket($tipoOperacionId);
                if ($flujo) {
                    $reglas = $this->getFlujoReglas((int) $flujo->idflujo);
                    if ($reglas->isNotEmpty()) {
                        $historial = (object) [
                            'flujoregla_idflujoregla' => $row->historial_flujoregla_id ?? null,
                            'resultado' => $row->historial_resultado ?? null,
                            'usuario_usuario' => $row->historial_usuario ?? null,
                            'vista_idvista' => $row->vista_actual_id ?? null,
                        ];
                        $currentRule = $this->resolveCurrentRule($historial, $reglas);
                        if ($currentRule) {
                            $phaseIndex = $reglas->search(fn ($rule) => (int) $rule->idflujoregla === (int) $currentRule->idflujoregla);
                            if ($phaseIndex !== false) {
                                $faseTexto = 'Fase: ' . ($phaseIndex + 1) . ' de ' . $reglas->count();
                            }
                        }
                    }
                }
            }
        }

        return [
            'receptor' => $receptor,
            'fase_texto' => $faseTexto,
            'accion_texto' => $accionTexto,
        ];
    }
}
