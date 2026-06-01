<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Export\ExportableList;
use App\Http\Controllers\Permission\HandlesResourceLock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AlmacenNotaSalidaController extends Controller
{
    use ExportableList;
    use HandlesResourceLock;

    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';

    private const LOCK_RESOURCE = 'almacen.nota_salida';
    private const LAST_REPORT_SESSION_KEY = 'almacen_nota_salida_last_report';
    private const LAST_REPORT_VISIBLE_FLASH_KEY = 'almacen_nota_salida_show_last_report';

    public function index(Request $request): View
    {
        $baseQuery = $this->baseQuery($request, 0);

        $items = $baseQuery
            ->orderBy('e.imei')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $items->through(function ($row) {
            $row->fecha_ingreso_label = $this->formatDateTime($row->fechaIngreso ?? null);
            $row->estado_label = ((string) ($row->estado ?? '0')) === '1' ? 'Activo' : 'Inactivo';
            return $row;
        });

        return view('almacen.nota-salida.index', [
            'title' => 'Nota de salida',
            'singularTitle' => 'Nota de salida',
            'items' => $items,
            'createRoute' => route('modules.almacen.nota-salida.create'),
            'createButtonLabel' => 'Nota de salida',
            'editRoute' => null,
            'showRoute' => null,
            'destroyRoute' => null,
            'bulkDestroyRoute' => null,
            'identifierKey' => 'imei',
            'lockResource' => self::LOCK_RESOURCE,
            'showActionsColumn' => false,
            'columns' => [
                ['key' => 'imei', 'label' => 'IMEI', 'type' => 'text'],
                ['key' => 'almacen_label', 'label' => 'Dispositivo', 'type' => 'text', 'wrap' => true],
                ['key' => 'fecha_ingreso_label', 'label' => 'Fecha salida', 'type' => 'text'],
                ['key' => 'estado', 'label' => 'Estado', 'type' => 'status'],
                ['key' => 'idAuxiliar', 'label' => 'ID Auxiliar', 'type' => 'text'],
            ],
            'stats' => [
                ['label' => 'Total de bajas registradas', 'value' => (clone $baseQuery)->count()],
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
            ],
            'exportRoutes' => [
                'pdf' => route('modules.almacen.nota-salida.export', ['format' => 'pdf']),
                'xlsx' => route('modules.almacen.nota-salida.export', ['format' => 'xlsx']),
            ],
        ]);
    }

    public function export(Request $request, string $format)
    {
        $format = strtolower($format);

        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $rows = $this->baseQuery($request, 0)
            ->orderBy('e.imei')
            ->get()
            ->map(function ($row) {
                $row->fecha_ingreso_label = $this->formatDateTime($row->fechaIngreso ?? null);
                $row->estado_label = ((string) ($row->estado ?? '0')) === '1' ? 'Activo' : 'Inactivo';
                return $row;
            });

        $columns = [
            ['key' => 'imei', 'label' => 'IMEI'],
            ['key' => 'almacen_label', 'label' => 'Dispositivo'],
            ['key' => 'fecha_ingreso_label', 'label' => 'Fecha salida'],
            ['key' => 'estado', 'label' => 'Estado'],
            ['key' => 'idAuxiliar', 'label' => 'ID Auxiliar'],
        ];

        $filename = 'nota_salida_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Nota de salida', $filename);
    }

    public function create(): View
    {
        $request = request();
        $baseQuery = $this->baseQuery($request, 1);

        $items = $baseQuery
            ->orderBy('e.imei')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $items->through(function ($row) {
            $row->fecha_ingreso_label = $this->formatDateTime($row->fechaIngreso ?? null);
            $row->estado_label = ((string) ($row->estado ?? '1')) === '1' ? 'Activo' : 'Inactivo';
            return $row;
        });

        $showReport = session()->pull(self::LAST_REPORT_VISIBLE_FLASH_KEY, false);
        $report = $showReport ? session(self::LAST_REPORT_SESSION_KEY, []) : [];
        $report = is_array($report) ? $report : [];

        if (! $showReport) {
            session()->forget(self::LAST_REPORT_SESSION_KEY);
        }

        return view('almacen.nota-salida.operar', [
            'title' => 'Dar de baja elementos',
            'moduleTitle' => 'Dar de baja elementos',
            'backRoute' => route('modules.almacen.nota-salida.index'),
            'formAction' => route('modules.almacen.nota-salida.store'),
            'reportExportRoute' => route('modules.almacen.nota-salida.report-export', ['format' => 'xlsx']),
            'reportExportPdfRoute' => route('modules.almacen.nota-salida.report-export', ['format' => 'pdf']),
            'items' => $items,
            'report' => $report,
            'columns' => [
                ['key' => 'imei', 'label' => 'IMEI', 'type' => 'text'],
                ['key' => 'almacen_label', 'label' => 'Dispositivo', 'type' => 'text', 'wrap' => true],
                ['key' => 'fecha_ingreso_label', 'label' => 'Fecha Ingreso', 'type' => 'text'],
                ['key' => 'estado', 'label' => 'Estado', 'type' => 'status'],
                ['key' => 'idAuxiliar', 'label' => 'ID Auxiliar', 'type' => 'text'],
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
            ],
        ]);
    }

    public function exportExecutionReport(Request $request, string $format)
    {
        $format = strtolower($format);

        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $report = session(self::LAST_REPORT_SESSION_KEY, []);
        if (!is_array($report) || empty($report['rows']) || !is_array($report['rows'])) {
            return redirect()
                ->route('modules.almacen.nota-salida.create')
                ->with('error', 'No hay un informe final disponible para descargar.');
        }

        $rows = collect($report['rows'])->map(function ($row) {
            return (object) [
                'imei' => (string) ($row['imei'] ?? ''),
                'almacen_label' => (string) ($row['almacen_label'] ?? ''),
                'fecha' => (string) ($row['fecha'] ?? ''),
                'resultado' => (string) ($row['resultado'] ?? ''),
                'mensaje' => (string) ($row['mensaje'] ?? ''),
            ];
        });

        $columns = [
            ['key' => 'imei', 'label' => 'IMEI'],
            ['key' => 'almacen_label', 'label' => 'Dispositivo'],
            ['key' => 'fecha', 'label' => 'Fecha Salida'],
            ['key' => 'resultado', 'label' => 'Resultado'],
            ['key' => 'mensaje', 'label' => 'Mensaje'],
        ];

        $filename = 'nota_salida_ejecucion_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Informe final de nota de salida', $filename);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'selectedImeis' => ['required', 'array', 'min:1'],
            'selectedImeis.*' => ['required', 'string', 'max:30', 'regex:' . self::SAFE_TEXT_REGEX],
        ], [
            'selectedImeis.required' => 'Selecciona al menos un elemento para dar de baja.',
            'selectedImeis.array' => 'La selección no es válida.',
            'selectedImeis.min' => 'Selecciona al menos un elemento para dar de baja.',
        ]);

        $selectedImeis = collect($validated['selectedImeis'])
            ->map(fn ($imei) => trim((string) $imei))
            ->filter()
            ->unique()
            ->values();

        $selectedRows = DB::table('elementoalmacen as e')
            ->leftJoin('almacen as a', 'a.idalmacen', '=', 'e.dispositivo_iddispositivo')
            ->leftJoin('empresapropietaria as ep', 'a.empresaPropietaria_RUC', '=', 'ep.RUC')
            ->whereIn('e.imei', $selectedImeis->all())
            ->select([
                'e.imei',
                'e.dispositivo_iddispositivo',
                'e.estado',
                'e.fechaIngreso',
                'e.idAuxiliar',
                DB::raw('COALESCE(a.detalle, "Sin dispositivo") as almacen_detalle'),
                DB::raw('TRIM(CONCAT(COALESCE(NULLIF(TRIM(ep.razonSocial), ""), "Sin empresa"), " - ", COALESCE(NULLIF(TRIM(a.detalle), ""), "Sin dispositivo"))) as almacen_label'),
            ])
            ->get()
            ->keyBy('imei');

        if ($selectedRows->isEmpty()) {
            return redirect()
                ->route('modules.almacen.nota-salida.create')
                ->with('error', 'No se encontraron elementos válidos para dar de baja.');
        }

        $reportRows = [];
        $summary = [
            'seleccionados' => $selectedImeis->count(),
            'bajados' => 0,
            'omitidos' => 0,
            'errores' => 0,
        ];

        DB::transaction(function () use ($selectedImeis, $selectedRows, &$reportRows, &$summary): void {
            foreach ($selectedImeis as $imei) {
                $record = $selectedRows->get($imei);

                if (!$record) {
                    $summary['omitidos']++;
                    $reportRows[] = [
                        'imei' => $imei,
                        'almacen_label' => 'Sin coincidencia',
                        'fecha' => '-',
                        'resultado' => 'Omitido',
                        'mensaje' => 'El elemento no existe o ya no está disponible.',
                    ];
                    continue;
                }

                $estadoAnterior = (string) ($record->estado ?? '0');
                if ($estadoAnterior !== '1') {
                    $summary['omitidos']++;
                    $reportRows[] = [
                        'imei' => (string) $record->imei,
                        'almacen_label' => (string) ($record->almacen_label ?? 'Sin dispositivo'),
                        'fecha' => '-',
                        'resultado' => 'Omitido',
                        'mensaje' => 'El elemento ya estaba inactivo.',
                    ];
                    continue;
                }

                $fechaSalida = now()->format('Y-m-d H:i:s');
                $affected = DB::table('elementoalmacen')
                    ->where('imei', $imei)
                    ->where('estado', 1)
                    ->update([
                        'estado' => 0,
                        'fechaIngreso' => $fechaSalida,
                    ]);

                if ($affected < 1) {
                    $summary['omitidos']++;
                    $reportRows[] = [
                        'imei' => (string) $record->imei,
                        'almacen_label' => (string) ($record->almacen_label ?? 'Sin dispositivo'),
                        'fecha' => '-',
                        'resultado' => 'Omitido',
                        'mensaje' => 'El estado cambió antes de ejecutar la baja.',
                    ];
                    continue;
                }

                $summary['bajados']++;
                $reportRows[] = [
                    'imei' => (string) $record->imei,
                    'almacen_label' => (string) ($record->almacen_label ?? 'Sin dispositivo'),
                    'fecha' => $this->formatDateTime($fechaSalida),
                    'resultado' => 'Dado de baja',
                    'mensaje' => 'Cambio de estado ejecutado correctamente.',
                ];

                $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $record->imei, 'updated');
            }
        });

        session([
            self::LAST_REPORT_SESSION_KEY => [
                'generatedAt' => now()->format('Y-m-d H:i:s'),
                'summary' => $summary,
                'rows' => $reportRows,
            ],
        ]);

        $message = 'Se procesó la baja de ' . $summary['bajados'] . ' elemento(s).';

        if ($summary['omitidos'] > 0) {
            $message .= ' Se omitieron ' . $summary['omitidos'] . '.';
        }

        if ($summary['errores'] > 0) {
            $message .= ' Hubo ' . $summary['errores'] . ' error(es).';
        }

        return redirect()
            ->route('modules.almacen.nota-salida.create')
            ->with('success', $message)
            ->with(self::LAST_REPORT_VISIBLE_FLASH_KEY, true);
    }

    public function edit(string $id): View|RedirectResponse
    {
        return redirect()
            ->route('modules.almacen.nota-salida.index')
            ->with('error', 'La nota de salida ahora se gestiona desde Dar de baja elementos.');
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        return redirect()
            ->route('modules.almacen.nota-salida.index')
            ->with('error', 'La nota de salida ahora se gestiona desde Dar de baja elementos.');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        return redirect()
            ->route('modules.almacen.nota-salida.index')
            ->with('error', 'La nota de salida ahora se gestiona desde Dar de baja elementos.');
    }

    private function baseQuery(Request $request, int $estado = 0)
    {
        $query = DB::table('elementoalmacen as e')
            ->leftJoin('almacen as a', 'a.idalmacen', '=', 'e.dispositivo_iddispositivo')
            ->leftJoin('empresapropietaria as ep', 'a.empresaPropietaria_RUC', '=', 'ep.RUC')
            ->select([
                'e.imei',
                'e.dispositivo_iddispositivo',
                'e.fechaIngreso',
                'e.estado',
                'e.idAuxiliar',
                DB::raw('COALESCE(a.detalle, "Sin dispositivo") as almacen_detalle'),
                DB::raw('TRIM(CONCAT(COALESCE(NULLIF(TRIM(ep.razonSocial), ""), "Sin empresa"), " - ", COALESCE(NULLIF(TRIM(a.detalle), ""), "Sin dispositivo"))) as almacen_label'),
            ])
            ->where('e.estado', $estado);

        if ($search = trim((string) $request->input('q', ''))) {
            $term = '%' . $search . '%';
            $query->where(function ($builder) use ($term) {
                $builder
                    ->where('e.imei', 'like', $term)
                    ->orWhere('e.dispositivo_iddispositivo', 'like', $term)
                    ->orWhere('a.detalle', 'like', $term)
                    ->orWhere('ep.razonSocial', 'like', $term)
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

        return $query;
    }

    private function almacenOptions(): Collection
    {
        return DB::table('almacen as a')
            ->leftJoin('empresapropietaria as ep', 'a.empresaPropietaria_RUC', '=', 'ep.RUC')
            ->select([
                'a.idalmacen',
                'a.detalle',
                'ep.razonSocial',
            ])
            ->orderBy('ep.razonSocial')
            ->orderBy('a.detalle')
            ->get() 
            ->map(fn ($row): array => [
                'value' => (string) $row->idalmacen,
                'label' => trim(
                    (string) (
                        trim((string) ($row->razonSocial ?? '')) !== ''
                            ? trim((string) $row->razonSocial)
                            : 'Sin empresa'
                    ) . ' - ' . trim((string) ($row->detalle ?? 'Sin detalle'))
                ),
                'idalmacen' => (int) $row->idalmacen,
                'detalle' => trim((string) ($row->detalle ?? 'Sin detalle')),
                'razonSocial' => trim((string) ($row->razonSocial ?? '')),
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

    private function nullableString(mixed $value): ?string
    {
        $stringValue = trim((string) ($value ?? ''));
        return $stringValue === '' ? null : $stringValue;
    }
}
