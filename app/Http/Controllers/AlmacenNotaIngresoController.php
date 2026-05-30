<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Export\ExportableList;
use App\Http\Controllers\Permission\HandlesResourceLock;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AlmacenNotaIngresoController extends Controller
{
    use ExportableList;
    use HandlesResourceLock;

    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';

    private const LOCK_RESOURCE = 'almacen.nota_ingreso';

    public function index(Request $request): View
    {
        $baseQuery = $this->baseQuery($request);
        $statsQuery = clone $baseQuery;

        $items = $baseQuery
            ->orderBy('e.imei')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $items->through(function ($row) {
            $row->fecha_ingreso_label = $this->formatDateTime($row->fechaIngreso ?? null);
            $row->estado_label = ((string) ($row->estado ?? '0')) === '1' ? 'Activo' : 'Inactivo';
            return $row;
        });

        $total = (clone $statsQuery)->count();
        $activos = (clone $statsQuery)->where('e.estado', 1)->count();

        return view('almacen.nota-ingreso.index', [
            'title' => 'Nota de ingreso',
            'singularTitle' => 'Nota de ingreso',
            'items' => $items,
            'createRoute' => route('modules.almacen.nota-ingreso.create'),
            'editRoute' => 'modules.almacen.nota-ingreso.edit',
            'showRoute' => 'modules.almacen.nota-ingreso.edit',
            'destroyRoute' => 'modules.almacen.nota-ingreso.destroy',
            'bulkDestroyRoute' => route('modules.almacen.nota-ingreso.bulk-destroy'),
            'identifierKey' => 'imei',
            'lockResource' => self::LOCK_RESOURCE,
            'showActionsColumn' => true,
            'columns' => [
                ['key' => 'imei', 'label' => 'IMEI', 'type' => 'text'],
                ['key' => 'almacen_detalle', 'label' => 'Dispositivo', 'type' => 'text', 'wrap' => true],
                ['key' => 'fecha_ingreso_label', 'label' => 'Fecha ingreso', 'type' => 'text'],
                ['key' => 'estado', 'label' => 'Estado', 'type' => 'status'],
                ['key' => 'idAuxiliar', 'label' => 'ID Auxiliar', 'type' => 'text'],
            ],
            'stats' => [
                ['label' => 'Total de nota de ingreso', 'value' => $activos],
            ],
            'filters' => [
                [
                    'name' => 'imei',
                    'label' => 'IMEI',
                    'type' => 'text',
                    'placeholder' => 'Buscar IMEI',
                ],
                [
                    'name' => 'dispositivo_iddispositivo',
                    'label' => 'Dispositivo',
                    'options' => $this->almacenOptions(),
                    'placeholder' => 'Todos los dispositivos',
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
            'exportRoutes' => [
                'pdf' => route('modules.almacen.nota-ingreso.export', ['format' => 'pdf']),
                'xlsx' => route('modules.almacen.nota-ingreso.export', ['format' => 'xlsx']),
            ],
        ]);
    }

    public function export(Request $request, string $format)
    {
        $format = strtolower($format);

        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $rows = $this->baseQuery($request)
            ->orderBy('e.imei')
            ->get()
            ->map(function ($row) {
                $row->fecha_ingreso_label = $this->formatDateTime($row->fechaIngreso ?? null);
                $row->estado_label = ((string) ($row->estado ?? '0')) === '1' ? 'Activo' : 'Inactivo';
                return $row;
            });

        $columns = [
            ['key' => 'imei', 'label' => 'IMEI'],
            ['key' => 'almacen_detalle', 'label' => 'Dispositivo'],
            ['key' => 'fecha_ingreso_label', 'label' => 'Fecha ingreso'],
            ['key' => 'estado', 'label' => 'Estado'],
            ['key' => 'idAuxiliar', 'label' => 'ID Auxiliar'],
        ];

        $filename = 'nota_ingreso_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Nota de ingreso', $filename);
    }

    public function create(): View
    {
        return view('almacen.nota-ingreso.form', [
            'title' => 'Nueva nota de ingreso',
            'moduleTitle' => 'Nota de ingreso',
            'mode' => 'create',
            'formAction' => route('modules.almacen.nota-ingreso.store'),
            'backRoute' => route('modules.almacen.nota-ingreso.index'),
            'record' => null,
            'fields' => $this->buildFields(),
            'readOnly' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateElementoAlmacen($request, true);
        $payload = $this->preparePayload($validated, true);

        DB::transaction(function () use ($payload): void {
            DB::table('elementoalmacen')->insert($payload);
            $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $payload['imei'], 'created');
        });

        return redirect()
            ->route('modules.almacen.nota-ingreso.index')
            ->with('success', 'Nota de ingreso creada correctamente.');
    }

    public function edit(string $id): View|RedirectResponse
    {
        $record = DB::table('elementoalmacen as e')
            ->leftJoin('almacen as a', 'a.idalmacen', '=', 'e.dispositivo_iddispositivo')
            ->select([
                'e.imei',
                'e.dispositivo_iddispositivo',
                'e.fechaIngreso',
                'e.estado',
                'e.idAuxiliar',
                DB::raw('COALESCE(a.detalle, "Sin dispositivo") as almacen_detalle'),
            ])
            ->where('e.imei', $id)
            ->first();

        if (!$record) {
            return redirect()
                ->route('modules.almacen.nota-ingreso.index')
                ->with('error', 'No se encontro la nota de ingreso solicitada.');
        }

        $record->fechaIngreso = $this->formatDateTimeForFormValue($record->fechaIngreso ?? null);

        return view('almacen.nota-ingreso.form', [
            'title' => 'Editar nota de ingreso',
            'moduleTitle' => 'Nota de ingreso',
            'mode' => 'edit',
            'formAction' => route('modules.almacen.nota-ingreso.update', $record->imei),
            'backRoute' => route('modules.almacen.nota-ingreso.index'),
            'record' => $record,
            'fields' => $this->buildFields($record),
            'readOnly' => true,
        ] + $this->prepareLockViewData(self::LOCK_RESOURCE, $record->imei));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $exists = DB::table('elementoalmacen')->where('imei', $id)->exists();

        if (!$exists) {
            return redirect()
                ->route('modules.almacen.nota-ingreso.index')
                ->with('error', 'No se encontro la nota de ingreso solicitada.');
        }

        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, $id, 'nota de ingreso', 'modules.almacen.nota-ingreso.index')) {
            return $redirect;
        }

        $validated = $this->validateElementoAlmacen($request, false);
        $payload = $this->preparePayload($validated, false);

        DB::transaction(function () use ($payload, $request, $id): void {
            DB::table('elementoalmacen')->where('imei', $id)->update($payload);
            $this->publishResourceEvent(self::LOCK_RESOURCE, $id, 'updated');
            $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, $id);
        });

        return redirect()
            ->route('modules.almacen.nota-ingreso.index')
            ->with('success', 'Nota de ingreso actualizada correctamente.');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, $id, 'nota de ingreso', 'modules.almacen.nota-ingreso.index')) {
            return $redirect;
        }

        try {
            DB::table('elementoalmacen')->where('imei', $id)->delete();
            $this->publishResourceEvent(self::LOCK_RESOURCE, $id, 'deleted');
            $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, $id);

            return redirect()
                ->route('modules.almacen.nota-ingreso.index')
                ->with('success', 'Nota de ingreso eliminada correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.almacen.nota-ingreso.index')
                ->with('error', 'No se puede eliminar la nota de ingreso porque tiene relaciones asociadas.');
        }
    }

    private function baseQuery(Request $request)
    {
        $query = DB::table('elementoalmacen as e')
            ->leftJoin('almacen as a', 'a.idalmacen', '=', 'e.dispositivo_iddispositivo')
            ->select([
                'e.imei',
                'e.dispositivo_iddispositivo',
                'e.fechaIngreso',
                'e.estado',
                'e.idAuxiliar',
                DB::raw('COALESCE(a.detalle, "Sin dispositivo") as almacen_detalle'),
            ]);

        $query->where('e.estado', 1);

        if ($search = trim((string) $request->input('q', ''))) {
            $term = '%' . $search . '%';
            $query->where(function ($builder) use ($term) {
                $builder
                    ->where('e.imei', 'like', $term)
                    ->orWhere('e.dispositivo_iddispositivo', 'like', $term)
                    ->orWhere('a.detalle', 'like', $term)
                    ->orWhere('e.fechaIngreso', 'like', $term)
                    ->orWhere('e.estado', 'like', $term)
                    ->orWhere('e.idAuxiliar', 'like', $term);
            });
        }

        if ($imei = trim((string) $request->input('imei', ''))) {
            $query->where('e.imei', 'like', '%' . $imei . '%');
        }

        if ($dispositivo = trim((string) $request->input('dispositivo_iddispositivo', ''))) {
            $query->where('e.dispositivo_iddispositivo', (int) $dispositivo);
        }

        if ($estado = trim((string) $request->input('estado', ''))) {
            $query->where('e.estado', (int) $estado);
        }

        return $query;
    }

    private function buildFields(?object $record = null): array
    {
        return [
            [
                'name' => 'imei',
                'type' => 'text',
                'label' => 'IMEI',
                'required' => true,
                'maxlength' => 30,
                'pattern' => '^[0-9]+$',
                'inputmode' => 'numeric',
                'helpText' => 'Solo números, hasta 30 caracteres.',
                'disabled' => $record !== null,
            ],
            [
                'name' => 'dispositivo_iddispositivo',
                'type' => 'select',
                'label' => 'Dispositivo (Almacén)',
                'required' => true,
                'tomSelect' => true,
                'optionsData' => $this->almacenOptions(),
                'optionKey' => 'idalmacen',
                'optionLabel' => 'detalle',
                'placeholder' => 'Selecciona un dispositivo de almacén',
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
                'value' => '1',
            ],
            [
                'name' => 'idAuxiliar',
                'type' => 'text',
                'label' => 'ID Auxiliar',
                'required' => false,
                'maxlength' => 30,
                'helpText' => 'Identificador auxiliar opcional.',
            ],
        ];
    }

    private function validateElementoAlmacen(Request $request, bool $isCreate = true): array
    {
        $rules = [
            'dispositivo_iddispositivo' => ['required', 'integer', 'exists:almacen,idalmacen'],
            'fechaIngreso' => ['nullable', 'date'],
            'estado' => ['nullable', 'integer', 'in:0,1'],
            'idAuxiliar' => ['nullable', 'string', 'max:30', 'regex:' . self::SAFE_TEXT_REGEX],
        ];

        if ($isCreate) {
            $rules['imei'] = ['required', 'string', 'max:30', 'regex:' . self::SAFE_TEXT_REGEX, 'unique:elementoalmacen,imei'];
        } else {
            $rules['imei'] = ['nullable', 'string', 'max:30', 'regex:' . self::SAFE_TEXT_REGEX];
        }

        return $request->validate($rules);
    }

    private function preparePayload(array $validated, bool $isCreate): array
    {
        $payload = [
            'dispositivo_iddispositivo' => (int) $validated['dispositivo_iddispositivo'],
            'estado' => (int) ($validated['estado'] ?? 1),
            'idAuxiliar' => $this->nullableString($validated['idAuxiliar'] ?? null),
        ];

        if ($isCreate) {
            $payload['imei'] = (string) $validated['imei'];
            $payload['fechaIngreso'] = now()->format('Y-m-d H:i:s');
            $payload['estado'] = 1;
        }

        return $payload;
    }

    private function almacenOptions(): Collection
    {
        return DB::table('almacen')
            ->select(['idalmacen', 'detalle'])
            ->orderBy('detalle')
            ->get()
            ->map(fn ($row): array => [
                'value' => (string) $row->idalmacen,
                'label' => trim((string) ($row->detalle ?? 'Sin detalle')),
                'idalmacen' => (int) $row->idalmacen,
                'detalle' => trim((string) ($row->detalle ?? 'Sin detalle')),
            ]);
    }

    private function formatDateTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        $monthNames = ['ene.', 'feb.', 'mar.', 'abr.', 'may.', 'jun.', 'jul.', 'ago.', 'sep.', 'oct.', 'nov.', 'dic.'];
        $date = Carbon::parse((string) $value);

        return sprintf(
            '%s %s %s, %s',
            $date->format('d'),
            $monthNames[((int) $date->format('n')) - 1],
            $date->format('Y'),
            $date->format('H:i')
        );
    }

    private function formatDateTimeForFormValue(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return Carbon::parse((string) $value)->format('Y-m-d\TH:i');
    }

    private function nullableString(mixed $value): ?string
    {
        $stringValue = trim((string) ($value ?? ''));
        return $stringValue === '' ? null : $stringValue;
    }
}
