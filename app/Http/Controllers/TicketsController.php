<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use App\Support\ResourceLock;
use App\Http\Controllers\Permission\HandlesResourceLock;

class TicketsController extends Controller
{
    use HandlesResourceLock;
    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';

    private const ESTADOS = [
        'Nuevo',
        'Asignado',
        'En Progreso',
        'En Espera',
        'Resuelto',
        'Cerrado',
        'Cancelado',
    ];

    public function index(Request $request): View
    {
        $baseQuery = DB::table('ticket as t')
            ->leftJoin('tipooperacion as tp', 't.tipoOperacion_idtipoOperacion', '=', 'tp.idtipoOperacion')
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
                'tp.detalle as tipo_detalle'
            );

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

        $statsBase = clone $baseQuery;

        $estado = trim((string) $request->input('estado', ''));
        if ($estado !== '') {
            $baseQuery->whereRaw('LOWER(t.estado) = ?', [mb_strtolower($estado)]);
        }

        $items = $baseQuery
            ->orderByDesc('t.fechaHoraRegistro')
            ->orderByDesc('t.idticket')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $items->through(function ($row) {
            $tipoOperacion = trim((string) ($row->tipo_nomenclatura ?? '') . ' ' . (string) ($row->tipo_detalle ?? ''));
            $row->tipo_operacion = $tipoOperacion !== '' ? $tipoOperacion : '-';
            return $row;
        });

        $tipoOperaciones = DB::table('tipooperacion')
            ->orderBy('detalle')
            ->get();

        $activosEstados = ['Nuevo', 'Asignado', 'En Progreso'];
        $activosEstadosLower = array_map('mb_strtolower', $activosEstados);

        $stats = [
            'activos' => (clone $statsBase)
                ->whereIn(DB::raw('LOWER(t.estado)'), $activosEstadosLower)
                ->count('t.idticket'),
            'en_espera' => (clone $statsBase)
                ->whereRaw('LOWER(t.estado) = ?', ['en espera'])
                ->count('t.idticket'),
            'resueltos' => (clone $statsBase)
                ->whereRaw('LOWER(t.estado) = ?', ['resuelto'])
                ->count('t.idticket'),
            'cancelados' => (clone $statsBase)
                ->whereRaw('LOWER(t.estado) = ?', ['cancelado'])
                ->count('t.idticket'),
        ];

        return view('ticket.tickets', [
            'title' => 'Tickets',
            'singularTitle' => 'Ticket',
            'items' => $items,
            'createRoute' => route('modules.tickets.create'),
            'editRoute' => 'modules.tickets.edit',
            'showRoute' => 'modules.tickets.edit',
            'destroyRoute' => 'modules.tickets.destroy',
            'showActionsColumn' => true,
            'columns' => [
                ['key' => 'idticket', 'label' => 'Ticket', 'type' => 'text'],
                ['key' => 'usuarioEmisor', 'label' => 'Emisor', 'type' => 'text'],
                ['key' => 'tipo_operacion', 'label' => 'Tipo de operacion', 'type' => 'text', 'wrap' => true],
                ['key' => 'usuarioReceptor', 'label' => 'Receptor', 'type' => 'text'],
                ['key' => 'estado', 'label' => 'Estado', 'type' => 'estado'],
                ['key' => 'fechaHoraRegistro', 'label' => 'Registro', 'type' => 'date'],
                ['key' => 'fechaHoraCierre', 'label' => 'Cierre', 'type' => 'date'],
            ],
            'stats' => [
                ['label' => 'Activos', 'value' => $stats['activos']],
                ['label' => 'En espera', 'value' => $stats['en_espera']],
                ['label' => 'Resueltos', 'value' => $stats['resueltos']],
                ['label' => 'Cancelados', 'value' => $stats['cancelados']],
            ],
            'filters' => [
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
            ],
            'identifierKey' => 'idticket',
        ]);
    }

    public function create(): View
    {
        $tipoOperaciones = $this->getTipoOperaciones();

        return view('ticket.tickets-form', [
            'title' => 'Nuevo Ticket',
            'moduleTitle' => 'Módulo Tickets',
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

        if ($request->hasFile('ImagenEvidencia')) {
            $validated['ImagenEvidencia'] = $this->storeEvidenceFile($request);
        }

        $validated['estado'] = $validated['estado'] ?: 'Nuevo';

        DB::table('ticket')->insert($validated);

        return redirect()
            ->route('modules.tickets')
            ->with('success', 'Ticket creado correctamente.');
    }

    public function edit(string $ticket): View|RedirectResponse
    {
        $record = DB::table('ticket')->where('idticket', $ticket)->first();

        if (!$record) {
            return redirect()
                ->route('modules.tickets')
                ->with('error', 'No se encontró el ticket solicitado.');
        }

        $tipoOperaciones = $this->getTipoOperaciones();

        return view('ticket.tickets-form', [
            'title' => 'Editar Ticket',
            'moduleTitle' => 'Módulo Tickets',
            'mode' => 'edit',
            'formAction' => route('modules.tickets.update', $ticket),
            'backRoute' => route('modules.tickets'),
            'record' => $this->hydrateTicketDates($record),
            'readOnly' => true,
            'fields' => $this->buildTicketFields($tipoOperaciones, $record),
        ] + $this->prepareLockViewData('tickets', (string) $ticket));
    }

    public function update(Request $request, string $ticket): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'tickets', $ticket, 'ticket', 'modules.tickets')) {
            return $redirect;
        }

        $exists = DB::table('ticket')->where('idticket', $ticket)->exists();

        if (!$exists) {
            return redirect()
                ->route('modules.tickets')
                ->with('error', 'No se encontró el ticket solicitado.');
        }

        $validated = $this->validateTicket($request, (int) $ticket);

        $validated['fechaHoraRegistro'] = $this->normalizeDateTimeInput($validated['fechaHoraRegistro'] ?? null) ?? now()->format('Y-m-d H:i:s');
        $validated['fechaHoraCierre'] = $this->normalizeDateTimeInput($validated['fechaHoraCierre'] ?? null);

        $previous = DB::table('ticket')->where('idticket', $ticket)->first();

        if ($request->hasFile('ImagenEvidencia')) {
            $this->deleteEvidenceFile((string) ($previous->ImagenEvidencia ?? ''));
            $validated['ImagenEvidencia'] = $this->storeEvidenceFile($request);
        } else {
            $validated['ImagenEvidencia'] = $previous->ImagenEvidencia ?? null;
        }

        DB::table('ticket')->where('idticket', $ticket)->update($validated);

        $this->releaseLockIfOwned($request, 'tickets', $ticket);

        return redirect()
            ->route('modules.tickets')
            ->with('success', 'Ticket actualizado correctamente.');
    }

    public function destroy(Request $request, string $ticket): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, 'tickets', $ticket, 'ticket', 'modules.tickets')) {
            return $redirect;
        }

        $record = DB::table('ticket')->where('idticket', $ticket)->first();

        if (!$record) {
            return redirect()
                ->route('modules.tickets')
                ->with('error', 'No se encontró el ticket solicitado.');
        }

        $hasOperations = DB::table('operacion')->where('ticket_idticket', $ticket)->exists();
        if ($hasOperations) {
            return redirect()
                ->route('modules.tickets')
                ->with('error', 'No se puede eliminar el ticket porque tiene operaciones relacionadas.');
        }

        try {
            DB::table('ticket')->where('idticket', $ticket)->delete();
            $this->deleteEvidenceFile((string) ($record->ImagenEvidencia ?? ''));
            $this->releaseLockIfOwned($request, 'tickets', $ticket);

            return redirect()
                ->route('modules.tickets')
                ->with('success', 'Ticket eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.tickets')
                ->with('error', 'No se puede eliminar el ticket porque tiene registros relacionados.');
        }
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
        ];

        // En creación, requerir usuarios e imagen
        if ($ticketId === null) {
            $rules['usuarioEmisor'] = ['required', 'string', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX];
            $rules['usuarioReceptor'] = ['required', 'string', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX];
            $rules['ImagenEvidencia'] = ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'];
        } else {
            // En edición, usuarios obligatorios pero imagen opcional
            $rules['usuarioEmisor'] = ['required', 'string', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX];
            $rules['usuarioReceptor'] = ['required', 'string', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX];
            $rules['ImagenEvidencia'] = ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'];
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
                'minlength' => 5,
                'maxlength' => 50,
                'helpText' => 'Mínimo 5 caracteres.',
            ],
            [
                'name' => 'usuarioReceptor',
                'type' => 'text',
                'label' => 'Usuario receptor',
                'required' => true,
                'minlength' => 5,
                'maxlength' => 50,
                'helpText' => 'Mínimo 5 caracteres.',
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
                'value' => $record->estado ?? 'Nuevo',
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

    private function hydrateTicketDates(object $record): object
    {
        $record->fechaHoraRegistro = $this->formatDateTimeForForm((string) ($record->fechaHoraRegistro ?? ''));
        $record->fechaHoraCierre = $this->formatDateTimeForForm((string) ($record->fechaHoraCierre ?? ''));

        return $record;
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

    private function deleteEvidenceFile(?string $path): void
    {
        $path = trim((string) $path);
        if ($path === '') {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    public function lockStatus(string $ticket): JsonResponse
    {
        $status = ResourceLock::status('tickets', $ticket);

        return response()->json([
            'locked' => $status !== null,
            'lock' => $status,
        ]);
    }

    public function acquireLock(Request $request, string $ticket): JsonResponse
    {
        $usuario = $request->session()->get('erp_auth.usuario', 'anonimo');
        $result = ResourceLock::acquire('tickets', $ticket, $usuario);

        if ($result['success']) {
            $this->publishLockEvent('tickets', $ticket, $usuario, 'locked', $result['lock']['expires_at']);

            return response()->json([
                'success' => true,
                'lock' => $result['lock'],
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'El ticket ya se encuentra bloqueado por otro usuario.',
            'lock' => $result['lock'],
        ], 409);
    }

    public function releaseLock(Request $request, string $ticket): JsonResponse
    {
        $usuario = $request->session()->get('erp_auth.usuario', 'anonimo');
        $result = ResourceLock::release('tickets', $ticket, $usuario);

        if ($result['success']) {
            $this->publishLockEvent('tickets', $ticket, $usuario, 'released', null);

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

}
