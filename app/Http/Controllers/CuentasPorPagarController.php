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

class CuentasPorPagarController extends Controller
{
    use ExportableList;
    use HandlesResourceLock;

    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';
    private const LOCK_RESOURCE = 'cuentasporpagar';

    public function index(Request $request): View
    {
        $query = DB::table('cuentasporpagar as c')
            ->leftJoin('proveedor as p', 'p.idproveedor', '=', 'c.proveedor_idproveedor')
            ->leftJoin('tipocobro as tc', 'tc.idtipoCobros', '=', 'c.tipoCobro_idtipoCobros')
            ->select([
                'c.idcuentasPorPagar',
                'c.proveedor_idproveedor',
                DB::raw('COALESCE(p.razonSocial, p.idproveedor) as proveedor_nombre'),
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
                'c.deudaOriginal',
                'c.deudaActual',
                'c.canCuota',
                'c.fechaRegistro',
                'c.fechaCancelacion',
                'c.estado',
            ]);

        if ($search = trim((string) $request->query('q', ''))) {
            $term = '%' . $search . '%';
            $query->where(function ($query) use ($term) {
                $query
                    ->where('c.idcuentasPorPagar', 'like', $term)
                    ->orWhere('c.proveedor_idproveedor', 'like', $term)
                    ->orWhere('p.razonSocial', 'like', $term)
                    ->orWhere('tc.nombre', 'like', $term)
                    ->orWhere('c.docReferencia', 'like', $term)
                    ->orWhere('c.descripcion', 'like', $term);
            });
        }

        $filters = [
            'proveedor_idproveedor' => $request->query('proveedor_idproveedor', ''),
            'tipoCobro_idtipoCobros' => $request->query('tipoCobro_idtipoCobros', ''),
            'estado' => $request->query('estado', ''),
        ];

        $statsQuery = clone $query;
        $total = (clone $statsQuery)->count();
        $activos = (clone $statsQuery)->where('c.estado', '1')->count();
        $inactivos = max($total - $activos, 0);

        if ($filters['proveedor_idproveedor'] !== '') {
            $query->where('c.proveedor_idproveedor', 'like', '%' . trim($filters['proveedor_idproveedor']) . '%');
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
            $row->deudaOriginal_display = $this->formatCurrencyValue(data_get($row, 'deudaOriginal'));
            $row->deudaActual_display = $this->formatCurrencyValue(data_get($row, 'deudaActual'));
            $row->fechaRegistro_display = $this->formatDisplayDate(data_get($row, 'fechaRegistro'));
            $row->fechaCancelacion_display = $this->formatDisplayDate(data_get($row, 'fechaCancelacion'));

            return $row;
        });

        return view('cuentasporpagar.cuentasporpagar', [
            'title' => 'Módulo Cuentas por Pagar',
            'singularTitle' => 'Cuenta por Pagar',
            'items' => $items,
            'columns' => [
                ['key' => 'proveedor_nombre', 'label' => 'Proveedor', 'type' => 'text'],
                ['key' => 'tipo_cobro', 'label' => 'Tipo de cobro', 'type' => 'text'],
                ['key' => 'deudaOriginal_display', 'label' => 'Deuda original', 'type' => 'text'],
                ['key' => 'deudaActual_display', 'label' => 'Deuda actual', 'type' => 'text'],
                ['key' => 'canCuota', 'label' => 'Cuotas', 'type' => 'text'],
                ['key' => 'fechaRegistro_display', 'label' => 'Fecha registro', 'type' => 'text'],
                ['key' => 'fechaCancelacion_display', 'label' => 'Fecha cancelación', 'type' => 'text'],
                ['key' => 'estado', 'label' => 'Estado', 'type' => 'status'],
            ],
            'stats' => [
                ['label' => 'Total Cuentas por pagar', 'value' => $total],
                ['label' => 'Cuentas por pagar Activo', 'value' => $activos],
                ['label' => 'Cuentas por pagar Inactivo', 'value' => $inactivos],
            ],
            'filters' => [
                ['name' => 'proveedor_idproveedor', 'label' => 'Proveedor', 'type' => 'text', 'placeholder' => 'Buscar por proveedor'],
                ['name' => 'tipoCobro_idtipoCobros', 'label' => 'Tipo de cobro', 'type' => 'select', 'options' => $this->tipoCobroOptions(), 'placeholder' => 'Todos los tipos de cobro'],
                ['name' => 'estado', 'label' => 'Estado', 'type' => 'select', 'options' => [ ['value' => '1', 'label' => 'Activo'], ['value' => '0', 'label' => 'Inactivo'] ], 'placeholder' => 'Todos los estados'],
                
            ],
            'createRoute' => route('modules.cuentasporpagar.create'),
            'editRoute' => 'modules.cuentasporpagar.edit',
            'showRoute' => 'modules.cuentasporpagar.edit',
            'destroyRoute' => 'modules.cuentasporpagar.destroy',
            'lockResource' => self::LOCK_RESOURCE,
            'exportRoutes' => [
                'pdf' => route('modules.cuentasporpagar.export', ['format' => 'pdf']),
                'xlsx' => route('modules.cuentasporpagar.export', ['format' => 'xlsx']),
            ],
            'identifierKey' => 'idcuentasPorPagar',
        ]);
    }

    public function export(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $selectedIds = (array) $request->input('selectedIds', []);
        $query = $this->applyExportFilters($request, $this->baseQuery());

        if (!empty($selectedIds)) {
            $query->whereIn('c.idcuentasPorPagar', $selectedIds);
        }

        $rows = $query->orderByDesc('c.fechaRegistro')->get();
        $columns = [
            ['key' => 'idcuentasPorPagar', 'label' => 'ID'],
            ['key' => 'proveedor_idproveedor', 'label' => 'Proveedor'],
            ['key' => 'proveedor_nombre', 'label' => 'Nombre proveedor'],
            ['key' => 'tipo_cobro', 'label' => 'Tipo de cobro'],
            ['key' => 'docReferencia', 'label' => 'Documento referencia'],
            ['key' => 'descripcion', 'label' => 'Descripción'],
            ['key' => 'deudaOriginal', 'label' => 'Deuda original'],
            ['key' => 'deudaActual', 'label' => 'Deuda actual'],
            ['key' => 'canCuota', 'label' => 'Cuotas'],
            ['key' => 'fechaRegistro', 'label' => 'Fecha registro'],
            ['key' => 'fechaCancelacion', 'label' => 'Fecha cancelación'],
            ['key' => 'estado', 'label' => 'Estado'],
        ];

        $filename = 'cuentasporpagar_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Cuentas por Pagar', $filename);
    }

    public function create(): View
    {
        return view('cuentasporpagar.cuentasporpagar-form', [
            'title' => 'Nueva Cuenta por Pagar',
            'moduleTitle' => 'Módulo Cuentas por Pagar',
            'mode' => 'create',
            'formAction' => route('modules.cuentasporpagar.store'),
            'backRoute' => route('modules.cuentasporpagar'),
            'record' => null,
            'fields' => [
                ['name' => 'proveedor_idproveedor', 'type' => 'select', 'label' => 'Proveedor', 'required' => true, 'tomSelect' => true, 'optionsData' => $this->proveedorOptions(), 'optionKey' => 'idproveedor', 'optionLabel' => 'proveedor_label', 'placeholder' => 'Selecciona proveedor'],
                ['name' => 'tipoCobro_idtipoCobros', 'type' => 'select', 'label' => 'Tipo de cobro', 'required' => true, 'tomSelect' => true, 'optionsData' => $this->tipoCobroOptions(), 'optionKey' => 'idtipoCobros', 'optionLabel' => 'label', 'placeholder' => 'Selecciona tipo de cobro'],
                ['name' => 'canCuota', 'type' => 'number', 'label' => 'Cuotas', 'required' => false, 'inputmode' => 'numeric', 'maxlength' => 5],
                ['name' => 'descripcion', 'type' => 'text', 'label' => 'Descripción', 'required' => false, 'maxlength' => 50, 'helpText' => 'Breve descripción.'],
                $this->decimalFieldDefinition('deudaOriginal', 'Deuda original', null, true),
                $this->decimalFieldDefinition('deudaActual', 'Deuda actual', null, true),
                ['name' => 'fechaRegistro', 'type' => 'date', 'label' => 'Fecha registro', 'required' => true, 'value' => now()->format('Y-m-d')],
                ['name' => 'fechaCancelacion', 'type' => 'date', 'label' => 'Fecha cancelación', 'required' => false],
                ['name' => 'docReferencia', 'type' => 'text', 'label' => 'Documento referencia', 'required' => false, 'maxlength' => 15, 'helpText' => 'Documento de referencia.'],
                ['name' => 'estado', 'type' => 'select', 'label' => 'Estado', 'required' => true, 'value' => '1', 'options' => [ ['value' => '1', 'label' => 'Activo'], ['value' => '0', 'label' => 'Inactivo'] ], 'placeholder' => 'Selecciona estado'],
            ],
            'readOnly' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'proveedor_idproveedor' => ['required', 'string', 'exists:proveedor,idproveedor'],
            'tipoCobro_idtipoCobros' => ['required', 'integer', 'exists:tipocobro,idtipoCobros'],
            'docReferencia' => ['nullable', 'string', 'max:15', 'regex:' . self::SAFE_TEXT_REGEX],
            'descripcion' => ['nullable', 'string', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'deudaOriginal' => ['required', 'numeric', 'min:0'],
            'deudaActual' => ['required', 'numeric', 'min:0'],
            'canCuota' => ['nullable', 'numeric', 'min:1'],
            'fechaRegistro' => ['required', 'date'],
            'fechaCancelacion' => ['nullable', 'date', 'after_or_equal:fechaRegistro'],
            'estado' => ['required', 'in:0,1'],
        ]);
        $validated = $this->normalizeDecimalFields($validated);

        $id = DB::table('cuentasporpagar')->insertGetId($validated);
        $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $id, 'created');

        return redirect()->route('modules.cuentasporpagar')->with('success', 'Cuenta por pagar creada correctamente.');
    }

    public function edit(string $id): View
    {
        $record = DB::table('cuentasporpagar as c')
            ->leftJoin('proveedor as p', 'p.idproveedor', '=', 'c.proveedor_idproveedor')
            ->leftJoin('tipocobro as tc', 'tc.idtipoCobros', '=', 'c.tipoCobro_idtipoCobros')
            ->select([
                'c.*',
                DB::raw('COALESCE(p.razonSocial, p.idproveedor) as proveedor_nombre'),
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
            ->where('c.idcuentasPorPagar', $id)
            ->first();

        if (!$record) {
            abort(404);
        }

        return view('cuentasporpagar.cuentasporpagar-form', [
            'title' => 'Editar Cuenta por Pagar',
            'moduleTitle' => 'Módulo Cuentas por Pagar',
            'mode' => 'edit',
            'formAction' => route('modules.cuentasporpagar.update', ['id' => $id]),
            'backRoute' => route('modules.cuentasporpagar'),
            'record' => $record,
            'fields' => [
                ['name' => 'proveedor_idproveedor', 'type' => 'select', 'label' => 'Proveedor', 'required' => true, 'tomSelect' => true, 'optionsData' => $this->proveedorOptions(), 'optionKey' => 'idproveedor', 'optionLabel' => 'proveedor_label', 'placeholder' => 'Selecciona proveedor'],
                ['name' => 'tipoCobro_idtipoCobros', 'type' => 'select', 'label' => 'Tipo de cobro', 'required' => true, 'tomSelect' => true, 'optionsData' => $this->tipoCobroOptions(), 'optionKey' => 'idtipoCobros', 'optionLabel' => 'label', 'placeholder' => 'Selecciona tipo de cobro'],
                ['name' => 'canCuota', 'type' => 'number', 'label' => 'Cuotas', 'required' => false, 'inputmode' => 'numeric', 'maxlength' => 5],
                ['name' => 'descripcion', 'type' => 'text', 'label' => 'Descripción', 'required' => false, 'maxlength' => 50, 'helpText' => 'Breve descripción.'],
                $this->decimalFieldDefinition('deudaOriginal', 'Deuda original', data_get($record, 'deudaOriginal'), true),
                $this->decimalFieldDefinition('deudaActual', 'Deuda actual', data_get($record, 'deudaActual'), true),
                ['name' => 'fechaRegistro', 'type' => 'date', 'label' => 'Fecha registro', 'required' => true],
                ['name' => 'fechaCancelacion', 'type' => 'date', 'label' => 'Fecha cancelación', 'required' => false],
                ['name' => 'docReferencia', 'type' => 'text', 'label' => 'Documento referencia', 'required' => false, 'maxlength' => 15, 'helpText' => 'Número de documento o referencia.'],
                ['name' => 'estado', 'type' => 'select', 'label' => 'Estado', 'required' => true, 'options' => [ ['value' => '1', 'label' => 'Activo'], ['value' => '0', 'label' => 'Inactivo'] ], 'placeholder' => 'Selecciona estado'],
            ],
            'readOnly' => true,
        ] + $this->prepareLockViewData(self::LOCK_RESOURCE, $id));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, $id, 'cuenta por pagar', 'modules.cuentasporpagar')) {
            return $redirect;
        }

        $validated = $request->validate([
            'proveedor_idproveedor' => ['required', 'string', 'exists:proveedor,idproveedor'],
            'tipoCobro_idtipoCobros' => ['required', 'integer', 'exists:tipocobro,idtipoCobros'],
            'docReferencia' => ['nullable', 'string', 'max:15', 'regex:' . self::SAFE_TEXT_REGEX],
            'descripcion' => ['nullable', 'string', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'deudaOriginal' => ['required', 'numeric', 'min:0'],
            'deudaActual' => ['required', 'numeric', 'min:0'],
            'canCuota' => ['nullable', 'numeric', 'min:1'],
            'fechaRegistro' => ['required', 'date'],
            'fechaCancelacion' => ['nullable', 'date', 'after_or_equal:fechaRegistro'],
            'estado' => ['required', 'in:0,1'],
        ]);
        $validated = $this->normalizeDecimalFields($validated);

        DB::table('cuentasporpagar')->where('idcuentasPorPagar', $id)->update($validated);
        $this->publishResourceEvent(self::LOCK_RESOURCE, $id, 'updated');
        $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, $id);

        return redirect()->route('modules.cuentasporpagar')->with('success', 'Cuenta por pagar actualizada correctamente.');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, $id, 'cuenta por pagar', 'modules.cuentasporpagar')) {
            return $redirect;
        }

        try {
            DB::table('cuentasporpagar')->where('idcuentasPorPagar', $id)->delete();
            $this->publishResourceEvent(self::LOCK_RESOURCE, $id, 'deleted');
            $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, $id);

            return redirect()->route('modules.cuentasporpagar')->with('success', 'Cuenta por pagar eliminada correctamente.');
        } catch (QueryException) {
            return redirect()->route('modules.cuentasporpagar')->with('error', 'No se puede eliminar la cuenta por pagar porque tiene dependencias asociadas.');
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
            'message' => 'La cuenta por pagar ya se encuentra bloqueada por otro usuario.',
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
        foreach (['deudaOriginal', 'deudaActual'] as $field) {
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
        return DB::table('cuentasporpagar as c')
            ->leftJoin('proveedor as p', 'p.idproveedor', '=', 'c.proveedor_idproveedor')
            ->leftJoin('tipocobro as tc', 'tc.idtipoCobros', '=', 'c.tipoCobro_idtipoCobros')
            ->select([
                'c.idcuentasPorPagar',
                'c.proveedor_idproveedor',
                DB::raw('COALESCE(p.razonSocial, p.idproveedor) as proveedor_nombre'),
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
                'c.deudaOriginal',
                'c.deudaActual',
                'c.canCuota',
                'c.fechaRegistro',
                'c.fechaCancelacion',
                'c.estado',
            ]);
    }

    private function applyExportFilters(Request $request, $query)
    {
        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $query->where(function ($builder) use ($term) {
                $builder
                    ->where('c.idcuentasPorPagar', 'like', $term)
                    ->orWhere('c.proveedor_idproveedor', 'like', $term)
                    ->orWhere('p.razonSocial', 'like', $term)
                    ->orWhere('tc.nombre', 'like', $term)
                    ->orWhere('c.docReferencia', 'like', $term)
                    ->orWhere('c.descripcion', 'like', $term);
            });
        }

        $proveedor = trim((string) $request->input('proveedor_idproveedor', ''));
        if ($proveedor !== '') {
            $query->where('c.proveedor_idproveedor', 'like', '%' . $proveedor . '%');
        }

        $tipoCobro = trim((string) $request->input('tipoCobro_idtipoCobros', ''));
        if ($tipoCobro !== '') {
            $query->where('c.tipoCobro_idtipoCobros', $tipoCobro);
        }

        $estado = trim((string) $request->input('estado', ''));
        if ($estado !== '') {
            $query->where('c.estado', $estado);
        }

        return $query;
    }

    private function proveedorOptions()
    {
        return DB::table('proveedor')
            ->select(['idproveedor', DB::raw('COALESCE(razonSocial, idproveedor) as proveedor_label')])
            ->orderBy('proveedor_label')
            ->get();
    }

    private function tipoCobroOptions()
    {
        return DB::table('tipocobro')
            ->select(['idtipoCobros', 'nombre', 'tiempo', 'recurrencia'])
            ->orderBy('nombre')
            ->get()
            ->map(function ($row): array {
                return [
                    'idtipoCobros' => (string) $row->idtipoCobros,
                    'nombre' => trim((string) ($row->nombre ?? '')),
                    'tiempo' => (string) ($row->tiempo ?? '0'),
                    'recurrencia' => strtoupper(trim((string) ($row->recurrencia ?? ''))),
                    'value' => (string) $row->idtipoCobros,
                    'label' => trim((string) ($row->nombre ?? '')),
                ];
            });
    }
}
