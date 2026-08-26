<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Export\ExportableList;
use App\Http\Controllers\Permission\HandlesResourceLock;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use App\Services\CorrelativoService;

class AlmacenNotaSalidaController extends Controller
{
    use HandlesResourceLock;
    use ExportableList;

    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';

    private const LOCK_RESOURCE = 'almacen.nota_salida';

    public function index(Request $request): View
    {
        $baseQuery = $this->baseQuery($request);

        $queryParams = $request->except('page');

        $items = $baseQuery
            ->orderByDesc('c.fechaRealizacion')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $items->through(function ($row) {
            $row->fecha_label = $this->formatDateTime($row->fechaRealizacion ?? null);
            $row->cantidadTotal = (int) ($row->cantidadTotal ?? 0);
            $row->download_link = '<a href="' . route('modules.almacen.nota-salida.pdf', ['id' => $row->idcompras]) . '" class="inline-flex items-center justify-center rounded-md transition duration-200 border border-slate-300 cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none bg-white px-2 py-1 text-sm text-slate-700 hover:bg-slate-50" title="Descargar PDF"><i data-lucide="download" class="mr-1 h-4 w-4 stroke-[1.3]"></i>Descargar</a>';
            // Cargar dispositivos e IMEIs asociados para mostrar en el panel expandible
            try {
                $deviceRows = DB::table('detallemovalmacen as dm')
                    ->join('elementoalmacen as e', 'dm.elementoAlmacen_imei', '=', 'e.imei')
                    ->join('almacen as a', 'e.dispositivo_iddispositivo', '=', 'a.idalmacen')
                    ->where('dm.compras_idcompras', $row->idcompras)
                    ->select(['a.idalmacen', 'a.detalle as dispositivo', 'e.imei'])
                    ->orderBy('a.detalle')
                    ->orderBy('e.imei')
                    ->get();

                if ($deviceRows->isNotEmpty()) {
                    $grouped = $deviceRows->groupBy('idalmacen')->map(function ($group, $idalmacen) {
                        $first = $group->first();
                        $imeis = $group->pluck('imei')->all();
                        return [
                            'idalmacen' => $idalmacen,
                            'dispositivo' => $first->dispositivo,
                            'cantidad' => count($imeis),
                            'imeis' => implode(', ', $imeis),
                        ];
                    })->values()->all();

                    $row->relation_groups = [
                        [
                            'key' => 'almacen',
                            'label' => 'Dispositivos',
                            'columns' => [
                                ['key' => 'dispositivo', 'label' => 'Dispositivo'],
                                ['key' => 'cantidad', 'label' => 'Cantidad'],
                                ['key' => 'imeis', 'label' => 'IMEIs'],
                            ],
                            'records' => $grouped,
                        ],
                    ];
                }
            } catch (\Throwable $e) {
                // No bloquear listado por errores al cargar relations; dejar vacío si falla
            }
            return $row;
        });

        return view('almacen.nota-salida.notasalida', [
            'title' => 'Nota de salida',
            'singularTitle' => 'Nota de salida',
            'items' => $items,
            'createRoute' => route('modules.almacen.nota-salida.create'),
            'editRoute' => 'modules.almacen.nota-salida.edit',
            'showRoute' => 'modules.almacen.nota-salida.edit',
            'destroyRoute' => 'modules.almacen.nota-salida.destroy',
            'bulkDestroyRoute' => route('modules.almacen.nota-salida.bulk-destroy'),
            'identifierKey' => 'idcompras',
            'lockResource' => self::LOCK_RESOURCE,
            'showActionsColumn' => true,
            'relationPanelView' => 'cliente.relation-panel',
            'columns' => [
                ['key' => 'idcompras', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'usuario_usuario', 'label' => 'Usuario', 'type' => 'text'],
                ['key' => 'tipoDocumento_nombre', 'label' => 'Tipo documento', 'type' => 'text'],
                ['key' => 'fecha_label', 'label' => 'Fecha', 'type' => 'text'],
                ['key' => 'cantidadTotal', 'label' => 'Cantidad', 'type' => 'text'],
                ['key' => 'motivo', 'label' => 'Motivo', 'type' => 'text'],
                ['key' => 'download_link', 'label' => 'Descargar', 'type' => 'custom'],
            ],
            'stats' => [
                ['label' => 'Total de notas', 'value' => (clone $baseQuery)->count()],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.almacen.nota-salida.export', array_merge(['format' => 'pdf'], $queryParams)),
                'xlsx' => route('modules.almacen.nota-salida.export', array_merge(['format' => 'xlsx'], $queryParams)),
            ],
            'filters' => [
                [
                    'name' => 'idcompras',
                    'label' => 'ID',
                    'type' => 'text',
                    'placeholder' => 'Buscar por ID',
                ],
                [
                    'name'=> 'usuario_usuario',
                    'label' => 'Usuario',
                    'type' => 'text',
                    'placeholder' => 'Usuario',
                ],
                [
                    'name' => 'tipoDocumento_idtipoDocumento',
                    'label' => 'Tipo documento',
                    'options' => $this->tipoDocumentoOptions(),
                    'placeholder' => 'Todos los tipos',
                ],
                [
                    'name'=> 'fechaRealizacion',
                    'label' => 'Fecha',
                    'type' => 'date',
                    'placeholder' => 'Fecha',
                ],
                [
                    'name'=> 'cantidadTotal',
                    'label' => 'Cantidad',
                    'type' => 'text',
                    'placeholder' => 'Cantidad total',
                ],
            ],
        ]);
    }

    public function downloadPdf(string $id)
    {
        $note = DB::table('compras as c')
            ->leftJoin('tipodocumento as td', 'c.tipoDocumento_idtipoDocumento', '=', 'td.idtipoDocumento')
            ->where('c.idcompras', $id)
            ->select([
                'c.idcompras',
                'c.usuario_usuario',
                'c.fechaRealizacion',
                'c.motivo',
                'c.docReferencia',
                'c.cantidadTotal',
                'td.detalle as tipoDocumento_nombre',
            ])
            ->first();

        if (!$note) {
            abort(404);
        }

        $items = DB::table('detallemovalmacen as dm')
            ->join('elementoalmacen as e', 'dm.elementoAlmacen_imei', '=', 'e.imei')
            ->join('almacen as a', 'e.dispositivo_iddispositivo', '=', 'a.idalmacen')
            ->where('dm.compras_idcompras', $id)
            ->select([
                'a.detalle as dispositivo',
                'e.imei',
            ])
            ->orderBy('a.detalle')
            ->orderBy('e.imei')
            ->get();

        $groupedItems = $items
            ->groupBy('dispositivo')
            ->map(function ($group, $dispositivo) {
                return [
                    'dispositivo' => $dispositivo,
                    'cantidad' => $group->count(),
                    'imeis' => $group->pluck('imei')->all(),
                ];
            })
            ->values();

        $pdf = Pdf::loadView('almacen.nota-salida.pdf', [
            'note' => $note,
            'items' => $groupedItems,
        ]);

        return $pdf->download('nota_salida_' . $id . '.pdf');
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
            ['key' => 'idcompras', 'label' => 'ID'],
            ['key' => 'usuario_usuario', 'label' => 'Usuario'],
            ['key' => 'tipoDocumento_nombre', 'label' => 'Tipo documento'],
            ['key' => 'fechaRealizacion', 'label' => 'Fecha'],
            ['key' => 'cantidadTotal', 'label' => 'Cantidad'],
            ['key' => 'motivo', 'label' => 'Motivo'],
            ['key' => 'docReferencia', 'label' => 'Documento referencia'],
        ];

        $filename = 'nota_salida_export_' . now()->format('Ymd_His') . '.' . $format;

         if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $this->baseQuery($request)->whereIn('c.idcompras', array_values($selectedIds))->orderBy('c.idcompras')->get();
            // Formatear fecha con hora para exportación (ej. "17 jun., 2026, 15:07")
            $rows = $rows->map(function ($r) {
                try {
                    $r->fechaRealizacion = $r->fechaRealizacion ? Carbon::parse($r->fechaRealizacion)->locale('es')->isoFormat('D MMM YYYY, HH:mm') : '';
                } catch (\Exception $e) {
                    $r->fechaRealizacion = $r->fechaRealizacion ?? '';
                }
                return $r;
            });

            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }

            return $this->exportPdfResponse($rows, $columns, 'Listado de Notas de Salida', $filename);
        }

        $rows = $this->baseQuery($request)->orderByDesc('c.fechaRealizacion')->get();

        // Formatear fecha con hora para exportación (ej. "17 jun., 2026, 15:07")
        $rows = $rows->map(function ($r) {
            try {
                $r->fechaRealizacion = $r->fechaRealizacion ? Carbon::parse($r->fechaRealizacion)->locale('es')->isoFormat('D MMM YYYY, HH:mm') : '';
            } catch (\Exception $e) {
                $r->fechaRealizacion = $r->fechaRealizacion ?? '';
            }
            return $r;
        });

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de notas de salida', $filename);
    }

    /**
     * Devuelve IMEIs aleatorios (preview) disponibles para un dispositivo dado.
     * Query params: device_id, qty
     */
    public function imeisPreview(Request $request)
    {
        $deviceId = (int) ($request->query('device_id') ?? 0);
        $qty = (int) ($request->query('qty') ?? 1);
        if ($deviceId <= 0 || $qty <= 0) {
            return response()->json(['imeis' => []]);
        }

        $imeis = DB::table('elementoalmacen')
            ->where('dispositivo_iddispositivo', $deviceId)
            ->whereIn('estado', [1, 2, 4])
            ->inRandomOrder()
            ->limit(min($qty, 200))
            ->pluck('imei')
            ->values();

        return response()->json(['imeis' => $imeis]);
    }

    public function create(): View
    {
        // Determinar el tipo de documento correspondiente a "Nota de salida"
        $tipoDefault = DB::table('tipodocumento')
            ->whereRaw("LOWER(TRIM(COALESCE(detalle, ''))) LIKE 'nota de salida%'")
            ->orderBy('idtipoDocumento')
            ->first();

        $fields = $this->buildFields();

        // Calcular preview de ID (correlativo siguiente) si hay un tipo de documento por defecto
        $previewId = null;
        if ($tipoDefault) {
            $next = ((int) ($tipoDefault->correlativo ?? 0)) + 1;
            $serie = trim((string) ($tipoDefault->serie ?? 'NS'));
            $previewId = $serie . sprintf('%05d', $next);
        }

        // Insertar campo readonly de previsualización de ID al inicio del formulario
        array_unshift($fields, [
            'name' => 'idcompras_preview',
            'type' => 'text',
            'label' => 'ID',
            'readonly' => true,
            'value' => $previewId,
        ]);

        if ($tipoDefault) {
            foreach ($fields as $idx => $f) {
                if (($f['name'] ?? '') === 'tipoDocumento_idtipoDocumento') {
                    $fields[$idx] = [
                        'name' => 'tipoDocumento_idtipoDocumento',
                        'type' => 'hidden',
                        'value' => (int) $tipoDefault->idtipoDocumento,
                    ];

                    array_splice($fields, $idx + 1, 0, [[
                        'name' => 'tipoDocumento_nombre',
                        'type' => 'text',
                        'label' => 'Tipo documento',
                        'required' => true,
                        'readonly' => true,
                        'value' => trim((string) ($tipoDefault->detalle ?? 'Nota de salida')),
                    ]]);

                    break;
                }
            }
        }

        return view('almacen.nota-salida.notasalida-form', [
            'title' => 'Nueva nota de salida',
            'moduleTitle' => 'Nota de salida',
            'mode' => 'create',
            'formAction' => route('modules.almacen.nota-salida.store'),
            'backRoute' => route('modules.almacen.nota-salida.index'),
            'record' => null,
            'fields' => $fields,
            'readOnly' => false,
            'almacenOptions' => $this->almacenOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Soportar dos modos de envío:
        // - nuevo: 'devices' => array de filas {dispositivo_iddispositivo, cantidad, manual, imeis}
        // - legado: dispositivo_iddispositivo + selectedImeis (texto/array)

        $baseRules = [
            'tipoDocumento_idtipoDocumento' => ['required', 'integer', 'exists:tipodocumento,idtipoDocumento'],
            'fechaRealizacion' => ['nullable', 'date'],
            'motivo' => ['nullable', 'string', 'max:200'],
            'docReferencia' => ['nullable', 'string', 'max:50'],
        ];

        $hasDevices = $request->has('devices');

        if ($hasDevices) {
            $rules = array_merge($baseRules, [
                'devices' => ['required', 'array', 'min:1'],
                'devices.*.dispositivo_iddispositivo' => ['required', 'integer', 'exists:almacen,idalmacen'],
                'devices.*.cantidad' => ['required', 'integer', 'min:1'],
                'devices.*.manual' => ['nullable', Rule::in(['0', '1', 0, 1, true, false])],
                'devices.*.imeis' => ['nullable'],
            ]);

            $validated = $request->validate($rules, [
                'devices.*.manual.in' => 'El valor seleccionado para Manual IMEIs es inválido.',
                'devices.*.dispositivo_iddispositivo.required' => 'Debes seleccionar un dispositivo.',
                'devices.*.cantidad.min' => 'La cantidad debe ser al menos 1.',
            ]);

            $devices = $validated['devices'];

            // Verificación anticipada de stock disponible por fila (antes de la transacción)
            foreach ($devices as $index => $row) {
                $deviceId = (int) $row['dispositivo_iddispositivo'];
                $cantidad = (int) $row['cantidad'];
                $stockDisponible = DB::table('elementoalmacen')
                    ->where('dispositivo_iddispositivo', $deviceId)
                    ->whereIn('estado', [1, 2, 4])
                    ->count();

                if ($stockDisponible < $cantidad) {
                    $deviceLabel = DB::table('almacen')->where('idalmacen', $deviceId)->value('detalle') ?? "ID {$deviceId}";
                    return redirect()->back()->withInput()->with(
                        'error',
                        "Fila #" . ($index + 1) . " — '{$deviceLabel}' solo tiene {$stockDisponible} unidad(es) disponible(s) en stock y solicitaste {$cantidad}."
                    );
                }
            }

            // Preparar IMEIs por dispositivo (no se generan IMEIs en salida)
            $imeisPerDevice = [];
            $totalCount = 0;

            foreach ($devices as $index => $row) {
                $deviceId = (int) $row['dispositivo_iddispositivo'];
                $cantidad = (int) $row['cantidad'];
                $manual = isset($row['manual']) && ((string) $row['manual'] === '1' || $row['manual'] === 1 || $row['manual'] === true);

                if ($manual) {
                    $raw = $row['imeis'] ?? '';
                    if (is_array($raw)) {
                        $collectedImeis = collect($raw);
                    } else {
                        $lines = preg_split('/[\r\n,;]+/', (string) $raw);
                        $collectedImeis = collect($lines);
                    }

                    $collectedImeis = $collectedImeis->map(fn ($v) => trim((string) $v))
                        ->filter(fn ($v) => $v !== '')
                        ->unique()
                        ->values();

                    if ($collectedImeis->count() !== $cantidad) {
                        return redirect()->back()->withInput()->with('error', "La fila #" . ($index + 1) . " requiere exactamente {$cantidad} IMEIs cuando se habilita la entrada manual.");
                    }

                    // Verificar que los IMEIs existen y están disponibles
                    $existing = DB::table('elementoalmacen')
                        ->whereIn('imei', $collectedImeis->all())
                        ->where('dispositivo_iddispositivo', $deviceId)
                        ->whereIn('estado', [1, 2, 4])
                        ->pluck('imei')
                        ->values();

                    if ($existing->count() !== $collectedImeis->count()) {
                        return redirect()->back()->withInput()->with('error', "La fila #" . ($index + 1) . " contiene IMEIs no disponibles o inválidos.");
                    }

                    $imeisPerDevice[] = [
                        'dispositivo_iddispositivo' => $deviceId,
                        'imeis' => $collectedImeis->all(),
                    ];
                } else {
                    // Para no-manual: reservamos la cantidad, la selección concreta de IMEIs
                    // se hará dentro de la transacción usando lockForUpdate para evitar race conditions
                    $imeisPerDevice[] = [
                        'dispositivo_iddispositivo' => $deviceId,
                        'imeis' => null,
                        'cantidad' => $cantidad,
                        'manual' => false,
                    ];
                }

                $totalCount += $cantidad;
            }

            // Crear compra y marcar IMEIs como salida (estado = 0) dentro de transacción
            $newId = null;
            $currentUser = session('erp_auth.usuario') ?? (auth()->check() ? (string) (auth()->user()->usuario ?? auth()->user()->name ?? 'system') : 'system');

            try {
                DB::transaction(function () use ($validated, $imeisPerDevice, &$newId, $currentUser, $totalCount): void {
                    $tipoId = (int) ($validated['tipoDocumento_idtipoDocumento'] ?? 0);
                    $alloc = CorrelativoService::allocateNext($tipoId);
                    $next = (int) $alloc['next'];
                    $newId = $alloc['formatted'];
                    DB::table('compras')->insert([
                        'idcompras' => $newId,
                        'usuario_usuario' => $currentUser,
                        'tipoDocumento_idtipoDocumento' => (int) ($validated['tipoDocumento_idtipoDocumento'] ?? 0),
                        'compras_idcompras' => 0,
                        'fechaRealizacion' => $this->normalizeDateTimeInput($validated['fechaRealizacion'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                        'motivo' => $validated['motivo'] ?? null,
                        'docReferencia' => $validated['docReferencia'] ?? null,
                        'cantidadTotal' => (int) $totalCount,
                    ]);

                    $fecha = now()->format('Y-m-d H:i:s');
                    foreach ($imeisPerDevice as $group) {
                        // Si ya tenemos IMEIs (manual), los usamos directamente
                        if (!empty($group['imeis']) && is_array($group['imeis'])) {
                            $imeisToProcess = $group['imeis'];
                        } else {
                            // Para filas no-manuales, seleccionar IMEIs disponibles y bloquear filas
                            $need = (int) ($group['cantidad'] ?? 0);
                            $deviceId = (int) $group['dispositivo_iddispositivo'];
                            $reserved = DB::table('elementoalmacen')
                                ->where('dispositivo_iddispositivo', $deviceId)
                                ->whereIn('estado', [1, 2, 4])
                                ->lockForUpdate()
                                ->limit($need)
                                ->pluck('imei')
                                ->values();

                            if ($reserved->count() < $need) {
                                throw new \RuntimeException('stock_insufficient');
                            }

                            $imeisToProcess = $reserved->all();
                        }

                        foreach ($imeisToProcess as $imei) {
                            $element = DB::table('elementoalmacen')
                                ->where('imei', $imei)
                                ->whereIn('estado', [1, 2, 4])
                                ->lockForUpdate()
                                ->first();

                            if (!$element) {
                                throw new \RuntimeException('stock_insufficient');
                            }

                            // Conservar el tipo de salida según el estado original.
                            $saleState = [1 => 6, 2 => 3, 4 => 5][(int) $element->estado];
                            DB::table('elementoalmacen')
                                ->where('imei', $imei)
                                ->update([
                                    'fechaIngreso' => $fecha,
                                    'estado' => $saleState,
                                ]);

                            // Registrar movimiento de salida
                            DB::table('detallemovalmacen')->insert([
                                'compras_idcompras' => $newId,
                                'elementoAlmacen_imei' => $imei,
                                'tipoMovimiento' => 'S',
                            ]);
                        }
                    }
                });
            } catch (\RuntimeException $e) {
                if ($e->getMessage() === 'stock_insufficient') {
                    return redirect()->back()->withInput()->with('error', 'No hay stock suficiente al momento de procesar la nota. Intenta nuevamente.');
                }
                throw $e;
            }

            return redirect()->route('modules.almacen.nota-salida.index')
                ->with('success', 'Nota de salida creada correctamente.')
                ->with('download_pdf_url', route('modules.almacen.nota-salida.pdf', ['id' => $newId]));
        }

        // Modo legado: single dispositivo + selectedImeis
        $validated = $request->validate(array_merge($baseRules, [
            'dispositivo_iddispositivo' => ['required', 'integer', 'exists:almacen,idalmacen'],
            'selectedImeis' => ['required'],
        ]));

        // Normalizar selectedImeis: aceptar array o texto (uno por línea o separados por comas)
        $rawSelected = $validated['selectedImeis'];
        if (is_array($rawSelected)) {
            $selectedImeis = collect($rawSelected);
        } else {
            $lines = preg_split('/[\r\n,;]+/', (string) $rawSelected);
            $selectedImeis = collect($lines);
        }

        $selectedImeis = $selectedImeis->map(fn($v) => trim((string) $v))
            ->filter(fn($v) => $v !== '')
            ->unique()
            ->values();

        if ($selectedImeis->isEmpty()) {
            return redirect()->back()->withInput()->with('error', 'Debes indicar al menos un IMEI válido.');
        }

        // Verificar disponibilidad de IMEIs solicitados
        $existing = DB::table('elementoalmacen')
            ->whereIn('imei', $selectedImeis->all())
            ->where('dispositivo_iddispositivo', (int) $validated['dispositivo_iddispositivo'])
            ->whereIn('estado', [1, 2, 4])
            ->pluck('imei')
            ->values();

        if ($existing->count() !== $selectedImeis->count()) {
            return redirect()->back()->withInput()->with('error', 'Algunos IMEIs solicitados no están disponibles o son inválidos.');
        }

        $newId = null;
        $currentUser = session('erp_auth.usuario') ?? (auth()->check() ? (string) (auth()->user()->usuario ?? auth()->user()->name ?? 'system') : 'system');

        try {
            DB::transaction(function () use ($validated, $selectedImeis, &$newId, $currentUser): void {
                $tipoId = (int) ($validated['tipoDocumento_idtipoDocumento'] ?? 0);
                $alloc = CorrelativoService::allocateNext($tipoId);
                $next = (int) $alloc['next'];
                $newId = $alloc['formatted'];
                DB::table('compras')->insert([
                    'idcompras' => $newId,
                    'usuario_usuario' => $currentUser,
                    'tipoDocumento_idtipoDocumento' => (int) $validated['tipoDocumento_idtipoDocumento'],
                    'compras_idcompras' => 0,
                    'fechaRealizacion' => $this->normalizeDateTimeInput($validated['fechaRealizacion'] ?? null) ?? now()->format('Y-m-d H:i:s'),
                    'motivo' => $validated['motivo'] ?? null,
                    'docReferencia' => $validated['docReferencia'] ?? null,
                    'cantidadTotal' => $selectedImeis->count(),
                ]);

                // Bloquear y verificar disponibilidad
                $reserved = DB::table('elementoalmacen')
                    ->whereIn('imei', $selectedImeis->all())
                    ->where('dispositivo_iddispositivo', (int) $validated['dispositivo_iddispositivo'])
                    ->whereIn('estado', [1, 2, 4])
                    ->lockForUpdate()
                    ->pluck('imei')
                    ->values();

                if ($reserved->count() !== $selectedImeis->count()) {
                    throw new \RuntimeException('stock_insufficient');
                }

                $fecha = now()->format('Y-m-d H:i:s');
                foreach ($reserved as $imei) {
                    $element = DB::table('elementoalmacen')
                        ->where('imei', $imei)
                        ->where('dispositivo_iddispositivo', (int) $validated['dispositivo_iddispositivo'])
                        ->whereIn('estado', [1, 2, 4])
                        ->lockForUpdate()
                        ->first();

                    if (!$element) {
                        throw new \RuntimeException('stock_insufficient');
                    }

                    $saleState = [1 => 6, 2 => 3, 4 => 5][(int) $element->estado];
                    DB::table('elementoalmacen')
                        ->where('imei', $imei)
                        ->where('dispositivo_iddispositivo', (int) $validated['dispositivo_iddispositivo'])
                        ->update([
                            'fechaIngreso' => $fecha,
                            'estado' => $saleState,
                        ]);

                    DB::table('detallemovalmacen')->insert([
                        'compras_idcompras' => $newId,
                        'elementoAlmacen_imei' => $imei,
                        'tipoMovimiento' => 'S',
                    ]);
                }
                
            });

            return redirect()
                ->route('modules.almacen.nota-salida.index')
                ->with('success', 'Nota de salida creada correctamente.')
                ->with('download_pdf_url', route('modules.almacen.nota-salida.pdf', ['id' => $newId]));
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'stock_insufficient') {
                return redirect()->back()->withInput()->with('error', 'Algunos IMEIs ya no están disponibles. Intenta nuevamente.');
            }
            throw $e;
        }
    }

    public function edit(string $id): View|RedirectResponse
    {
        $record = DB::table('compras as c')
            ->leftJoin('tipodocumento as td', 'td.idtipoDocumento', '=', 'c.tipoDocumento_idtipoDocumento')
            ->select([
                'c.idcompras',
                'c.tipoDocumento_idtipoDocumento',
                'c.fechaRealizacion',
                'c.motivo',
                'c.docReferencia',
                'c.cantidadTotal',
                DB::raw('COALESCE(td.detalle, "") as tipoDocumento_nombre'),
            ])
            ->where('c.idcompras', $id)
            ->first();

        if (!$record) {
            return redirect()
                ->route('modules.almacen.nota-salida.index')
                ->with('error', 'No se encontro la nota de salida solicitada.');
        }

        return view('almacen.nota-salida.notasalida-form', [
            'title' => 'Editar nota de salida',
            'moduleTitle' => 'Nota de salida',
            'mode' => 'edit',
            'formAction' => route('modules.almacen.nota-salida.update', $record->idcompras),
            'backRoute' => route('modules.almacen.nota-salida.index'),
            'record' => $record,
            'fields' => $this->buildFields($record),
            'readOnly' => true,
        ] + $this->prepareLockViewData(self::LOCK_RESOURCE, $record->idcompras));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, $id, 'nota de salida', 'modules.almacen.nota-salida.index')) {
            return $redirect;
        }

        $validated = $request->validate([
            'motivo' => ['nullable', 'string', 'max:200'],
            'docReferencia' => ['nullable', 'string', 'max:50'],
            'selectedImeis' => ['nullable', 'array'],
            'selectedImeis.*' => ['string', 'max:30'],
        ]);

        DB::transaction(function () use ($validated, $id, $request): void {
            // sólo actualizar cabecera compras; cambios en items requieren validaciones más completas
            DB::table('compras')->where('idcompras', $id)->update([
                'motivo' => $validated['motivo'] ?? null,
                'docReferencia' => $validated['docReferencia'] ?? null,
            ]);

            $this->publishResourceEvent(self::LOCK_RESOURCE, $id, 'updated');
            $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, $id);
        });

        return redirect()
            ->route('modules.almacen.nota-salida.index')
            ->with('success', 'Nota de salida actualizada correctamente.');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, $id, 'nota de salida', 'modules.almacen.nota-salida.index')) {
            return $redirect;
        }

        $imeis = DB::table('detallemovalmacen')->where('compras_idcompras', $id)->pluck('elementoAlmacen_imei')->values();

        if ($imeis->isEmpty()) {
            DB::table('compras')->where('idcompras', $id)->delete();
            $this->publishResourceEvent(self::LOCK_RESOURCE, $id, 'deleted');
            $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, $id);

            return redirect()->route('modules.almacen.nota-salida.index')->with('success', 'Nota de salida eliminada correctamente.');
        }

        $imeisByDevice = DB::table('elementoalmacen')->whereIn('imei', $imeis->all())->select('imei', 'dispositivo_iddispositivo')->get()->groupBy('dispositivo_iddispositivo');

        foreach ($imeisByDevice as $deviceId => $rows) {
            $countToRemove = count($rows);
            $currentStock = (int) DB::table('elementoalmacen')->where('dispositivo_iddispositivo', $deviceId)->whereIn('estado', [1, 2, 4])->count();
            $newStock = $currentStock - $countToRemove;
            if ($newStock <= 0) {
                return redirect()
                    ->route('modules.almacen.nota-salida.index')
                    ->with('error', 'No se puede eliminar la nota porque su eliminación dejaría el stock en 0 o negativo para algún dispositivo.');
            }
        }

        DB::transaction(function () use ($id, $imeis, $request): void {
            // Para notas de salida no debemos borrar los elementos; los devolvemos (estado = 1)
            DB::table('detallemovalmacen')->where('compras_idcompras', $id)->delete();

            DB::table('elementoalmacen')->whereIn('imei', $imeis->all())->update([
                'estado' => 1,
                'fechaIngreso' => now(),
            ]);

            DB::table('compras')->where('idcompras', $id)->delete();

            $this->publishResourceEvent(self::LOCK_RESOURCE, $id, 'deleted');
            $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, $id);
        });

        return redirect()->route('modules.almacen.nota-salida.index')->with('success', 'Nota de salida eliminada correctamente.');
    }

    private function baseQuery(Request $request)
    {
        $query = DB::table('compras as c')
            ->leftJoin('tipodocumento as td', 'td.idtipoDocumento', '=', 'c.tipoDocumento_idtipoDocumento')
            ->select([
                'c.idcompras',
                'c.usuario_usuario',
                'c.fechaRealizacion',
                'c.motivo',
                'c.docReferencia',
                'c.cantidadTotal',
                DB::raw('COALESCE(td.detalle, "") as tipoDocumento_nombre'),
            ]);

        if ($search = trim((string) $request->input('q', ''))) {
            $term = '%' . $search . '%';
            $query->where(function ($builder) use ($term) {
                $builder->where('c.idcompras', 'like', $term)
                    ->orWhere('c.usuario_usuario', 'like', $term)
                    ->orWhere('c.motivo', 'like', $term)
                    ->orWhere('c.docReferencia', 'like', $term)
                    ->orWhere('td.detalle', 'like', $term);
            });
        }

        if ($idcompras = trim((string) $request->input('idcompras', ''))) {
            $query->where('c.idcompras', 'like', '%' . $idcompras . '%');
        }

        if ($usuario = trim((string) $request->input('usuario_usuario', ''))) {
            $query->where('c.usuario_usuario', 'like', '%' . $usuario . '%');
        }

        if ($tipo = trim((string) $request->input('tipoDocumento_idtipoDocumento', ''))) {
            $query->where('c.tipoDocumento_idtipoDocumento', (int) $tipo);
        }

        if ($fechaRealizacion = trim((string) $request->input('fechaRealizacion', ''))) {
            $query->whereDate('c.fechaRealizacion', $fechaRealizacion);
        }

        if ($motivo = trim((string) $request->input('motivo', ''))) {
            $query->where('c.motivo', 'like', '%' . $motivo . '%');
        }

        if ($docReferencia = trim((string) $request->input('docReferencia', ''))) {
            $query->where('c.docReferencia', 'like', '%' . $docReferencia . '%');
        }

        if ($cantidadTotal = trim((string) $request->input('cantidadTotal', ''))) {
            if (is_numeric($cantidadTotal)) {
                $query->where('c.cantidadTotal', (int) $cantidadTotal);
            }
        }

        // Mostrar únicamente notas de salida: filtrar por detalle en tipodocumento que empiece por 'NS'
            $query->whereRaw("LOWER(TRIM(COALESCE(td.detalle, ''))) LIKE 'nota de salida%'");

        return $query;
    }

    private function buildFields(?object $record = null): array
    {
        // Preparar datos iniciales para el partial de devices cuando haya un registro (editar)
        $devicesData = [];
        if ($record && isset($record->idcompras)) {
            $rows = DB::table('detallemovalmacen as dm')
                ->join('elementoalmacen as e', 'dm.elementoAlmacen_imei', '=', 'e.imei')
                ->where('dm.compras_idcompras', $record->idcompras)
                ->select(['e.dispositivo_iddispositivo', 'e.imei'])
                ->orderBy('e.dispositivo_iddispositivo')
                ->get();

            if ($rows->isNotEmpty()) {
                $grouped = $rows->groupBy('dispositivo_iddispositivo');
                foreach ($grouped as $deviceId => $group) {
                    $imeis = $group->pluck('imei')->all();
                    $devicesData[] = [
                        'dispositivo_iddispositivo' => (int) $deviceId,
                        'cantidad' => count($imeis),
                        'manual' => true,
                        'imeis' => $imeis,
                    ];
                }
            }
        }

        // Si estamos en modo edición y el registro ya tiene un tipo de documento que
        // comienza por 'Nota de salida', convertir el select en hidden + campo readonly
        if ($record && isset($record->tipoDocumento_nombre) && str_starts_with(mb_strtolower(trim((string) $record->tipoDocumento_nombre)), 'nota de salida')) {
            $tipoField = [
                [
                    'name' => 'tipoDocumento_idtipoDocumento',
                    'type' => 'hidden',
                    'value' => (int) ($record->tipoDocumento_idtipoDocumento ?? 0),
                ],
                [
                    'name' => 'tipoDocumento_nombre',
                    'type' => 'text',
                    'label' => 'Tipo documento',
                    'required' => true,
                    'readonly' => true,
                    'value' => trim((string) ($record->tipoDocumento_nombre ?? 'Nota de salida')),
                ],
            ];
        } else {
            $tipoField = [[
                'name' => 'tipoDocumento_idtipoDocumento',
                'type' => 'select',
                'label' => 'Tipo documento',
                'required' => true,
                'tomSelect' => true,
                'optionsData' => $this->tipoDocumentoOptions('nota de ingreso'),
                'optionKey' => 'value',
                'optionLabel' => 'label',
                'placeholder' => 'Selecciona tipo de documento',
            ]];
        }

        // Si estamos en edición, mostrar el ID como campo readonly al inicio
        $baseFields = [];
        if ($record && isset($record->idcompras)) {
            $baseFields[] = [
                'name' => 'idcompras_preview',
                'type' => 'text',
                'label' => 'ID',
                'readonly' => true,
                'value' => trim((string) ($record->idcompras ?? '')),
            ];
        }

        return array_merge($baseFields, $tipoField, [
            [
                'name' => 'fechaRealizacion',
                'type' => 'date',
                'label' => 'Fecha realización',
                'required' => false,
                'placeholder' => 'Fecha',
                'value' => $record ? $this->formatDateTimeForFormValue($record->fechaRealizacion ?? null) : now()->format('Y-m-d\TH:i'),
            ],
            [
                'name' => 'motivo',
                'type' => 'text',
                'label' => 'Motivo',
                'required' => false,
                'maxlength' => 200,
            ],
            [
                'name' => 'docReferencia',
                'type' => 'text',
                'label' => 'Documento referencia',
                'required' => false,
                'maxlength' => 50,
            ],
            [
                'name' => 'devices_partial',
                'type' => 'partial',
                'partial' => 'almacen.partials.devices-form-salida',
                'data' => array_merge(['almacenOptions' => $this->almacenOptions()], ['devices' => $devicesData]),
                'colSpan' => 2,
            ],
        ]);
    }

    private function validateCompra(Request $request): array
    {
        return $request->validate([
            'tipoDocumento_idtipoDocumento' => ['required', 'integer', 'exists:tipodocumento,idtipoDocumento'],
            'fechaRealizacion' => ['nullable', 'date'],
            'dispositivo_iddispositivo' => ['required', 'integer', 'exists:almacen,idalmacen'],
            'selectedImeis' => ['required', 'string'],
            'motivo' => ['nullable', 'string', 'max:200'],
            'docReferencia' => ['nullable', 'string', 'max:50'],
        ]);
    }

    private function almacenOptions(): Collection
    {
        // Obtener stock activo por dispositivo en una sola consulta
        $stockByDevice = DB::table('elementoalmacen')
            ->whereIn('estado', [1, 2, 4])
            ->selectRaw('dispositivo_iddispositivo, COUNT(*) as stock')
            ->groupBy('dispositivo_iddispositivo')
            ->pluck('stock', 'dispositivo_iddispositivo');

        return DB::table('almacen as a')
            ->leftJoin('empresapropietaria as ep', 'a.empresaPropietaria_RUC', '=', 'ep.RUC')
            ->leftJoin('tipoelemento as te', 'a.tipoElemento_idtipoElemento', '=', 'te.idtipoElemento')
            ->whereRaw("LOWER(TRIM(COALESCE(te.nombre, ''))) NOT LIKE '%plan%'")
            ->whereRaw("LOWER(TRIM(COALESCE(te.nombre, ''))) NOT LIKE '%servicio%'")
            ->select([
                'a.idalmacen',
                'a.detalle',
                'ep.razonSocial',
            ])
            ->orderBy('ep.razonSocial')
            ->orderBy('a.detalle')
            ->get()
            ->map(fn($row): array => [
                'value' => (string) $row->idalmacen,
                'label' => trim((string) ($row->detalle ?? 'Sin detalle')),
                'idalmacen' => (int) $row->idalmacen,
                'detalle' => trim((string) ($row->detalle ?? 'Sin detalle')),
                'stock' => (int) ($stockByDevice[$row->idalmacen] ?? 0),
            ]);
    }

    private function tipoDocumentoOptions(?string $excludeStartsWith = null): Collection
    {
        $rows = DB::table('tipodocumento as td')
            ->orderBy('td.detalle')
            ->orderBy('td.idtipoDocumento')
            ->get();

        if ($excludeStartsWith !== null) {
            $exclude = strtolower(trim($excludeStartsWith));
            $rows = $rows->filter(fn($r) => strpos(strtolower(trim((string) ($r->detalle ?? ''))), $exclude) !== 0);
        }

        return $rows->map(function ($row): array {
            $detalle = trim((string) ($row->detalle ?? ''));
            return [
                'value' => (int) $row->idtipoDocumento,
                'label' => trim((string) ($detalle !== '' ? $detalle : 'Sin detalle')),
            ];
        });
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
