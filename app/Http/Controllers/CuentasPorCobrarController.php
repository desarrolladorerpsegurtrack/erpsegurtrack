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
use Illuminate\View\View;

class CuentasPorCobrarController extends Controller
{
    use ExportableList;
    use HandlesResourceLock;

    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';
    private const LOCK_RESOURCE = 'cuentasporcobrar';

    public function index(Request $request): View
    {
        $query = DB::table('cuentasporcobrar as c')
            ->leftJoin('cliente as cl', 'cl.idcliente', '=', 'c.cliente_idcliente')
            ->leftJoin('tipocobro as tc', 'tc.idtipoCobros', '=', 'c.tipoCobro_idtipoCobros')
            ->select([
                'c.idcuentasPorCobrar',
                'c.cliente_idcliente',
                DB::raw('COALESCE(cl.razonSocial, cl.nombreComercial, cl.idcliente) as cliente_nombre'),
                DB::raw('CONCAT(
                    COALESCE(tc.nombre, ""),
                    " - ",
                    COALESCE(CAST(tc.tiempo AS CHAR), "0"),
                    " ",
                    CASE
                        WHEN UPPER(TRIM(COALESCE(tc.recurrencia, ""))) = "D" THEN "Días"
                        WHEN UPPER(TRIM(COALESCE(tc.recurrencia, ""))) = "M" THEN "Meses"
                        ELSE COALESCE(tc.recurrencia, "")
                    END
                ) as tipo_cobro'),
                'c.docReferencia',
                'c.descripcion',
                'c.montoOriginal',
                'c.montoActual',
                'c.canCuotas',
                'c.fechaRegistro',
                'c.fechaCancelacion',
                'c.estado',
            ]);

        if ($search = trim((string) $request->query('q', ''))) {
            $term = '%' . $search . '%';
            $query->where(function ($query) use ($term) {
                $query
                    ->where('c.idcuentasPorCobrar', 'like', $term)
                    ->orWhere('c.cliente_idcliente', 'like', $term)
                    ->orWhere('cl.nombreComercial', 'like', $term)
                    ->orWhere('cl.razonSocial', 'like', $term)
                    ->orWhere('tc.nombre', 'like', $term)
                    ->orWhere('c.docReferencia', 'like', $term)
                    ->orWhere('c.descripcion', 'like', $term);
            });
        }

        $filters = [
            'cliente_idcliente' => $request->query('cliente_idcliente', ''),
            'tipoCobro_idtipoCobros' => $request->query('tipoCobro_idtipoCobros', ''),
            'estado' => $request->query('estado', ''),
        ];

        $statsQuery = clone $query;
        $totalcuentasporcobrar= (clone $statsQuery)->count();
        $activosCuentasPorCobrar = (clone $statsQuery)->where('c.estado', '1')->count();
        $inactivosCuentasPorCobrar = max($totalcuentasporcobrar - $activosCuentasPorCobrar, 0);

        if ($filters['cliente_idcliente'] !== '') {
            $query->where('c.cliente_idcliente', 'like', '%' . trim($filters['cliente_idcliente']) . '%');
        }

        if ($filters['tipoCobro_idtipoCobros'] !== '') {
            $query->where('c.tipoCobro_idtipoCobros', $filters['tipoCobro_idtipoCobros']);
        }

        if ($filters['estado'] !== '') {
            $query->where('c.estado', $filters['estado']);
        }

        $items = $query
            ->orderByRaw("CASE WHEN c.estado = '1' THEN 0 ELSE 1 END")
            ->orderByDesc('c.fechaRegistro')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $items->getCollection()->transform(function ($row): mixed {
            $row->montoOriginal_display = $this->formatCurrencyValue(data_get($row, 'montoOriginal'));
            $row->montoActual_display = $this->formatCurrencyValue(data_get($row, 'montoActual'));
            $row->fechaRegistro_display = $this->formatDisplayDate(data_get($row, 'fechaRegistro'));
            $row->fechaCancelacion_display = $this->formatDisplayDate(data_get($row, 'fechaCancelacion'));

            return $row;
        });

        return view('cuentasporcobrar.cuentasporcobrar', [
            'title' => 'Módulo Cuentas por Cobrar',
            'singularTitle' => 'Cuenta por Cobrar',
            'items' => $items,
            'columns' => [
                ['key' => 'idcuentasPorCobrar', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'cliente_nombre', 'label' => 'Cliente', 'type' => 'text'],
                ['key' => 'tipo_cobro', 'label' => 'Tipo de cobro', 'type' => 'text'],
                ['key' => 'montoOriginal_display', 'label' => 'Monto original', 'type' => 'text'],
                ['key' => 'montoActual_display', 'label' => 'Monto actual', 'type' => 'text'],
                ['key' => 'canCuotas', 'label' => 'Cuotas', 'type' => 'text'],
                ['key' => 'fechaRegistro_display', 'label' => 'Fecha registro', 'type' => 'text'],
                ['key' => 'fechaCancelacion_display', 'label' => 'Fecha cancelación', 'type' => 'text'],
                ['key' => 'estado', 'label' => 'Estado', 'type' => 'status'],
            ],
            'stats' => [
                ['label' => 'Total Cuentas por cobrar', 'value' => $totalcuentasporcobrar],
                ['label' => 'Cuentas por cobrar Activo', 'value' => $activosCuentasPorCobrar],
                ['label' => 'Cuentas por cobrar Inactivo', 'value' => $inactivosCuentasPorCobrar],
            ],
            'filters' => [
                [
                    'name' => 'q',
                    'label' => 'Buscar',
                    'type' => 'text',
                    'placeholder' => 'Buscar por cliente, documento o descripción',
                ],
                [
                    'name' => 'cliente_idcliente',
                    'label' => 'Cliente',
                    'type' => 'text',
                    'placeholder' => 'Buscar por cliente',
                ],
                [
                    'name' => 'tipoCobro_idtipoCobros',
                    'label' => 'Tipo de cobro',
                    'type' => 'select',
                    'options' => $this->tipoCobroOptions(),
                    'placeholder' => 'Todos los tipos de cobro',
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
            'createRoute' => route('modules.cuentasporcobrar.create'),
            'editRoute' => 'modules.cuentasporcobrar.edit',
            'showRoute' => 'modules.cuentasporcobrar.edit',
            'destroyRoute' => 'modules.cuentasporcobrar.destroy',
            'lockResource' => self::LOCK_RESOURCE,
            'exportRoutes' => [
                'pdf' => route('modules.cuentasporcobrar.export', ['format' => 'pdf']),
                'xlsx' => route('modules.cuentasporcobrar.export', ['format' => 'xlsx']),
            ],
            'identifierKey' => 'idcuentasPorCobrar',
        ]);
    }

    public function export(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $selectedIds = (array) $request->input('selectedIds', []);
        $query = $this->baseQuery();

        if (!empty($selectedIds)) {
            $query->whereIn('c.idcuentasPorCobrar', $selectedIds);
        }

        $rows = $query->orderByDesc('c.fechaRegistro')->get();
        $columns = [
            ['key' => 'idcuentasPorCobrar', 'label' => 'ID'],
            ['key' => 'cliente_idcliente', 'label' => 'Cliente'],
            ['key' => 'cliente_nombre', 'label' => 'Nombre cliente'],
            ['key' => 'tipo_cobro', 'label' => 'Tipo de cobro'],
            ['key' => 'docReferencia', 'label' => 'Documento referencia'],
            ['key' => 'descripcion', 'label' => 'Descripción'],
            ['key' => 'montoOriginal', 'label' => 'Monto original'],
            ['key' => 'montoActual', 'label' => 'Monto actual'],
            ['key' => 'canCuotas', 'label' => 'Cuotas'],
            ['key' => 'fechaRegistro', 'label' => 'Fecha registro'],
            ['key' => 'fechaCancelacion', 'label' => 'Fecha cancelación'],
            ['key' => 'estado', 'label' => 'Estado'],
        ];

        $filename = 'cuentasporcobrar_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Cuentas por Cobrar', $filename);
    }

    public function create(): View
    {
        return view('cuentasporcobrar.cuentasporcobrar-form', [
            'title' => 'Nueva Cuenta por Cobrar',
            'moduleTitle' => 'Módulo Cuentas por Cobrar',
            'mode' => 'create',
            'formAction' => route('modules.cuentasporcobrar.store'),
            'backRoute' => route('modules.cuentasporcobrar'),
            'record' => null,
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
                    'name' => 'tipoCobro_idtipoCobros',
                    'type' => 'select',
                    'label' => 'Tipo de cobro',
                    'required' => true,
                    'tomSelect' => true,
                    'optionsData' => $this->tipoCobroOptions(),
                    'optionKey' => 'idtipoCobros',
                    'optionLabel' => 'label',
                    'placeholder' => 'Selecciona tipo de cobro',
                ],
                [
                    'name' => 'canCuotas',
                    'type' => 'number',
                    'label' => 'Cuotas',
                    'required' => false,
                    'inputmode' => 'numeric',
                    'maxlength' => 5,
                ],
                [
                    'name' => 'descripcion',
                    'type' => 'text',
                    'label' => 'Descripción',
                    'required' => false,
                    'maxlength' => 50,
                    'helpText' => 'Breve descripción.',
                ],
                $this->decimalFieldDefinition('montoOriginal', 'Monto original', null, true),
                $this->decimalFieldDefinition('montoActual', 'Monto actual', null, true),
                [
                    'name' => 'fechaRegistro',
                    'type' => 'date',
                    'label' => 'Fecha registro',
                    'required' => true,
                    'value' => now()->format('Y-m-d'),
                ],
                [
                    'name' => 'fechaCancelacion',
                    'type' => 'date',
                    'label' => 'Fecha cancelación',
                    'required' => false,
                ],
                [
                    'name' => 'docReferencia',
                    'type' => 'text',
                    'label' => 'Documento referencia',
                    'required' => false,
                    'maxlength' => 15,
                    'helpText' => 'Documento de referencia.',
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
            'readOnly' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'cliente_idcliente' => ['required', 'string', 'exists:cliente,idcliente'],
            'tipoCobro_idtipoCobros' => ['required', 'integer', 'exists:tipocobro,idtipoCobros'],
            'docReferencia' => ['nullable', 'string', 'max:15', 'regex:' . self::SAFE_TEXT_REGEX],
            'descripcion' => ['nullable', 'string', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'montoOriginal' => ['required', 'numeric', 'min:0'],
            'montoActual' => ['required', 'numeric', 'min:0'],
            'canCuotas' => ['nullable', 'numeric', 'min:1'],
            'fechaRegistro' => ['required', 'date'],
            'fechaCancelacion' => ['nullable', 'date', 'after_or_equal:fechaRegistro'],
            'estado' => ['required', 'in:0,1'],
        ]);
        $validated = $this->normalizeDecimalFields($validated);

        $id = DB::table('cuentasporcobrar')->insertGetId($validated);
        $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $id, 'created');

        return redirect()->route('modules.cuentasporcobrar')->with('success', 'Cuenta por cobrar creada correctamente.');
    }

    public function edit(string $id): View
    {
        $record = DB::table('cuentasporcobrar as c')
            ->leftJoin('cliente as cl', 'cl.idcliente', '=', 'c.cliente_idcliente')
            ->leftJoin('tipocobro as tc', 'tc.idtipoCobros', '=', 'c.tipoCobro_idtipoCobros')
            ->select([
                'c.*',
                DB::raw('COALESCE(cl.razonSocial, cl.nombreComercial, cl.idcliente) as cliente_nombre'),
                DB::raw('CONCAT(
                    COALESCE(tc.nombre, ""),
                    " - ",
                    COALESCE(CAST(tc.tiempo AS CHAR), "0"),
                    " ",
                    CASE
                        WHEN UPPER(TRIM(COALESCE(tc.recurrencia, ""))) = "D" THEN "Días"
                        WHEN UPPER(TRIM(COALESCE(tc.recurrencia, ""))) = "M" THEN "Meses"
                        ELSE COALESCE(tc.recurrencia, "")
                    END
                ) as tipo_cobro'),
            ])
            ->where('c.idcuentasPorCobrar', $id)
            ->first();

        if (!$record) {
            abort(404);
        }

        return view('cuentasporcobrar.cuentasporcobrar-form', [
            'title' => 'Editar Cuenta por Cobrar',
            'moduleTitle' => 'Módulo Cuentas por Cobrar',
            'mode' => 'edit',
            'formAction' => route('modules.cuentasporcobrar.update', ['id' => $id]),
            'backRoute' => route('modules.cuentasporcobrar'),
            'record' => $record,
            'fields' => [
                ['name' => 'cliente_idcliente', 'type' => 'select', 'label' => 'Cliente', 'required' => true, 'tomSelect' => true, 'optionsData' => $this->clienteOptions(), 'optionKey' => 'idcliente', 'optionLabel' => 'cliente_label', 'placeholder' => 'Selecciona cliente'],
                ['name' => 'tipoCobro_idtipoCobros', 'type' => 'select', 'label' => 'Tipo de cobro', 'required' => true, 'tomSelect' => true, 'optionsData' => $this->tipoCobroOptions(), 'optionKey' => 'idtipoCobros', 'optionLabel' => 'label', 'placeholder' => 'Selecciona tipo de cobro'],
                ['name' => 'docReferencia', 'type' => 'text', 'label' => 'Documento referencia', 'required' => false, 'maxlength' => 15, 'helpText' => 'Número de documento o referencia.'],
                ['name' => 'descripcion', 'type' => 'text', 'label' => 'Descripción', 'required' => false, 'maxlength' => 50, 'helpText' => 'Breve descripción.'],
                $this->decimalFieldDefinition('montoOriginal', 'Monto original', data_get($record, 'montoOriginal'), true),
                $this->decimalFieldDefinition('montoActual', 'Monto actual', data_get($record, 'montoActual'), true),
                ['name' => 'canCuotas', 'type' => 'number', 'label' => 'Cuotas', 'required' => false, 'inputmode' => 'numeric', 'maxlength' => 5],
                ['name' => 'fechaRegistro', 'type' => 'date', 'label' => 'Fecha registro', 'required' => true],
                ['name' => 'fechaCancelacion', 'type' => 'date', 'label' => 'Fecha cancelación', 'required' => false],
                [   
                    'name' => 'estado', 
                    'type' => 'select', 
                    'label' => 'Estado', 
                    'required' => true, 
                    'options' => [
                        ['value' => '1', 'label' => 'Activo'], 
                        ['value' => '0', 'label' => 'Inactivo']
                    ], 
                    'placeholder' => 'Selecciona estado'
                ],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData(self::LOCK_RESOURCE, $id));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, $id, 'cuenta por cobrar', 'modules.cuentasporcobrar')) {
            return $redirect;
        }

        $validated = $request->validate([
            'cliente_idcliente' => ['required', 'string', 'exists:cliente,idcliente'],
            'tipoCobro_idtipoCobros' => ['required', 'integer', 'exists:tipocobro,idtipoCobros'],
            'docReferencia' => ['nullable', 'string', 'max:15', 'regex:' . self::SAFE_TEXT_REGEX],
            'descripcion' => ['nullable', 'string', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'montoOriginal' => ['required', 'numeric', 'min:0'],
            'montoActual' => ['required', 'numeric', 'min:0'],
            'canCuotas' => ['nullable', 'numeric', 'min:1'],
            'fechaRegistro' => ['required', 'date'],
            'fechaCancelacion' => ['nullable', 'date', 'after_or_equal:fechaRegistro'],
            'estado' => ['required', 'in:0,1'],
        ]);
        $validated = $this->normalizeDecimalFields($validated);

        DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $id)->update($validated);
        $this->publishResourceEvent(self::LOCK_RESOURCE, $id, 'updated');
        $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, $id);

        return redirect()->route('modules.cuentasporcobrar')->with('success', 'Cuenta por cobrar actualizada correctamente.');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, $id, 'cuenta por cobrar', 'modules.cuentasporcobrar')) {
            return $redirect;
        }

        try {
            DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $id)->delete();
            $this->publishResourceEvent(self::LOCK_RESOURCE, $id, 'deleted');
            $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, $id);

            return redirect()->route('modules.cuentasporcobrar')->with('success', 'Cuenta por cobrar eliminada correctamente.');
        } catch (QueryException) {
            return redirect()->route('modules.cuentasporcobrar')->with('error', 'No se puede eliminar la cuenta por cobrar porque tiene dependencias asociadas.');
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
            'message' => 'La cuenta por cobrar ya se encuentra bloqueada por otro usuario.',
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

    private function decimalFieldDefinition(string $name, string $label, mixed $value = null, bool $required = true): array
    {
        return [
            'name' => $name,
            'type' => 'number',
            'label' => $label,
            'required' => $required,
            'inputmode' => 'decimal',
            'step' => '0.01',
            'min' => '0',
            'placeholder' => '0.00',
            'maxlength' => 20,
            'value' => $this->formatDecimalValue($value),
        ];
    }

    private function formatDecimalValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function normalizeDecimalFields(array $data): array
    {
        foreach (['montoOriginal', 'montoActual'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $this->formatDecimalValue($data[$field] ?? null);
            }
        }

        return $data;
    }

    private function formatCurrencyValue(mixed $value): string
    {
        return 'S/ ' . number_format((float) $value, 2, '.', ',');
    }

    private function formatDisplayDate(mixed $value): string
    {
        if (empty($value) || in_array((string) $value, ['0000-00-00', '0000-00-00 00:00:00'], true)) {
            return '-';
        }

        try {
            $carbonDate = \Illuminate\Support\Carbon::parse($value);
            $monthNames = ['ene.', 'feb.', 'mar.', 'abr.', 'may.', 'jun.', 'jul.', 'ago.', 'sep.', 'oct.', 'nov.', 'dic.'];

            return sprintf('%s %s %s', $carbonDate->format('d'), $monthNames[(int) $carbonDate->format('m') - 1], $carbonDate->format('Y'));
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function baseQuery()
    {
        return DB::table('cuentasporcobrar as c')
            ->leftJoin('cliente as cl', 'cl.idcliente', '=', 'c.cliente_idcliente')
            ->leftJoin('tipocobro as tc', 'tc.idtipoCobros', '=', 'c.tipoCobro_idtipoCobros')
            ->select([
                'c.idcuentasPorCobrar',
                'c.cliente_idcliente',
                DB::raw('COALESCE(cl.razonSocial, cl.nombreComercial, cl.idcliente) as cliente_nombre'),
                DB::raw('CONCAT(
                    COALESCE(tc.nombre, ""),
                    " - ",
                    COALESCE(CAST(tc.tiempo AS CHAR), "0"),
                    " ",
                    CASE
                        WHEN UPPER(TRIM(COALESCE(tc.recurrencia, ""))) = "D" THEN "Días"
                        WHEN UPPER(TRIM(COALESCE(tc.recurrencia, ""))) = "M" THEN "Meses"
                        ELSE COALESCE(tc.recurrencia, "")
                    END
                ) as tipo_cobro'),
                'c.docReferencia',
                'c.descripcion',
                'c.montoOriginal',
                'c.montoActual',
                'c.canCuotas',
                'c.fechaRegistro',
                'c.fechaCancelacion',
                'c.estado',
            ]);
    }

    private function clienteOptions()
    {
        return DB::table('cliente')
            ->select([
                'idcliente',
                DB::raw('COALESCE(razonSocial, nombreComercial, idcliente) as cliente_label'),
            ])
            ->orderBy('cliente_label')
            ->get();
    }

    private function tipoCobroOptions()
    {
        return DB::table('tipocobro')
            ->select(['idtipoCobros', 'nombre', 'tiempo', 'recurrencia'])
            ->orderBy('nombre')
            ->get()
            ->map(function ($row): array {
                $recurrencia = strtoupper(trim((string) ($row->recurrencia ?? '')));
                $recurrenciaLabel = match ($recurrencia) {
                    'D' => 'Días',
                    'M' => 'Meses',
                    default => $recurrencia,
                };

                return [
                    'idtipoCobros' => (string) $row->idtipoCobros,
                    'nombre' => trim((string) ($row->nombre ?? '')),
                    'tiempo' => (string) ($row->tiempo ?? '0'),
                    'recurrencia' => $recurrencia,
                    'value' => (string) $row->idtipoCobros,
                    'label' => trim(sprintf('%s - %s %s', $row->nombre ?? '', $row->tiempo ?? '0', $recurrenciaLabel)),
                ];
            });
    }
}
