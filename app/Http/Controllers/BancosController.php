<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Export\ExportableList;
use App\Http\Controllers\Permission\HandlesResourceLock;
use App\Services\BancosService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BancosController extends Controller
{
    use ExportableList;
    use HandlesResourceLock;

    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';
    private const CREDIT_NOTE_LOCK_RESOURCE = 'finanzas.nota_creditos';

    public function __construct(private BancosService $bancosService)
    {
    }

    public function index(Request $request): View
    {
        $bankOptions = $this->bancosService->getBankOptions()
            ->map(fn ($bank): array => [
                'value' => (string) $bank->identidadBancaria,
                'label' => $bank->label,
            ])
            ->all();
        $perPage = $this->resolvePerPage($request);
        $allRows = $this->bancosService->getExportRows($request, false);
        $groupedRows = $this->filterGroupedMovementsByAmount(
            $this->groupBankMovements($allRows),
            $request
        );
        $movementIds = $groupedRows
            ->flatMap(fn ($row): array => $row->movement_ids ?? [])
            ->unique()
            ->values()
            ->all();
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $items = new LengthAwarePaginator(
            $groupedRows->forPage($currentPage, $perPage)->values(),
            $groupedRows->count(),
            $perPage,
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $stats = $this->bancosService->getStats($request, $movementIds);
        return view('finanzas.bancos', [
            'title' => 'Banco / Caja',
            'singularTitle' => 'Movimiento bancario',
            'items' => $items,
            'columns' => [
                ['key' => 'cliente_nombre', 'label' => 'Cliente', 'type' => 'text'],
                ['key' => 'fechaRegistro_display', 'label' => 'Fecha de registro', 'type' => 'text'],
                ['key' => 'informacion', 'label' => 'Información', 'type' => 'text'],
                ['key' => 'monto_display', 'label' => 'Monto total', 'type' => 'text'],
            ],
            'hideHistoryPanelTitle' => true,
            'historyTitle' => 'Movimientos del grupo',
            'stats' => collect($stats['balances'])->flatMap(function (array $balance, string $symbol): array {
                $cards = [];
                foreach ([
                    'balance_positivo' => 'Balance positivo',
                    'balance_negativo' => 'Balance negativo',
                    'saldo_a_favor' => 'Saldo a favor',
                    'anulado_revertido' => 'Anulado/Revertido',
                ] as $key => $label) {
                    $amount = (float) ($balance[$key] ?? 0);
                    if (abs($amount) < 0.005) {
                        continue;
                    }
                    $cards[] = ['label' => $label . ' (' . $symbol . ')', 'value' => $this->formatMoney($amount, $symbol)];
                }
                return $cards;
            })->values()->all(),
            'refreshStatsOnFilter' => true,
            'filters' => [
                ['name' => 'entidadBancaria_identidadBancaria', 'label' => 'Entidad bancaria', 'type' => 'select', 'placeholder' => 'Todas', 'options' => $bankOptions],
                ['name' => 'cliente', 'label' => 'Cliente', 'type' => 'text', 'placeholder' => 'RUC o nombre del cliente'],
                ['name' => 'moneda', 'label' => 'Tipo de moneda', 'type' => 'select', 'placeholder' => 'Todas', 'options' => [
                    ['value' => 'S/', 'label' => 'S/'],
                    ['value' => '$', 'label' => '$'],
                ]],
                ['name' => 'fechaDesde', 'toName' => 'fechaHasta', 'label' => 'Fecha de registro', 'type' => 'date_range'],
                ['name' => 'montoDesde', 'toName' => 'montoHasta', 'label' => 'Monto', 'type' => 'number_range', 'placeholder' => 'Ej. 35'],
                ['name' => 'tipoMov', 'label' => 'Movimiento', 'type' => 'select', 'placeholder' => 'Todos', 'options' => $this->movementFilterOptions()],
            ],
            'createRoute' => route('modules.finanzas.bancos.create'),
            'editRoute' => 'modules.finanzas.bancos.edit',
            'showRoute' => 'modules.finanzas.bancos.edit',
            'destroyRoute' => 'modules.finanzas.bancos.destroy',
            'bulkDestroyRoute' => route('modules.finanzas.bancos.bulk-destroy'),
            'identifierKey' => 'idbancos',
            'exportRoutes' => [
                'pdf' => route('modules.finanzas.bancos.export', ['format' => 'pdf']),
                'xlsx' => route('modules.finanzas.bancos.export', ['format' => 'xlsx']),
            ],
        ]);
    }

    public function estadoCuenta(Request $request): View
    {
        $currencyFilter = $request->validate([
            'moneda' => ['nullable', 'in:S/,$'],
        ])['moneda'] ?? null;
        $items = $this->bancosService->getEstadoCuentaList($request, $this->resolvePerPage($request, 25));
        $clientIds = $items->getCollection()->pluck('ruc_cliente')->filter()->unique()->values()->all();
        $credits = $this->bancosService->getMontosAFavorByClientCurrency($clientIds, $currencyFilter);
        $historyByClient = $this->bancosService->getEstadoCuentaDetailsByClient($clientIds);
        $items->getCollection()->transform(function ($row) use ($credits, $historyByClient): mixed {
            $clientCredits = $credits[$row->ruc_cliente] ?? [];
            $currency = $row->moneda_simbolo;
            $debt = (float) ($row->currency_totals[$currency]['monto_deuda'] ?? 0);
            $credit = (float) ($clientCredits[$currency] ?? 0);
            $row->monto_deuda_display = $this->formatMoney($debt, $currency);
            $row->monto_favor_display = $this->formatMoney($credit, $currency);
            $details = collect($historyByClient[$row->ruc_cliente] ?? [])
                ->filter(fn ($detail): bool => (float) ($detail->deuda_pendiente[$currency] ?? 0) > 0)
                ->values();
            $hasCredit = $credit > 0;
            $hasDebt = $details->isNotEmpty();
            $showCredit = false;
            $historyRows = $details->map(function ($detail) use ($currency, $credit, &$showCredit): array {
                $detailDebt = (float) ($detail->deuda_pendiente[$currency] ?? 0);
                $includeCredit = $credit > 0 && !$showCredit;
                if ($includeCredit) {
                    $showCredit = true;
                }

                return [
                    'cxc_id' => '#' . $detail->cxc_id,
                    'vehiculo' => $detail->vehiculo ?: 'Sin vehículo asociado',
                    'descripcion' => $detail->descripcion ?: 'Cuenta por cobrar #' . $detail->cxc_id,
                    'monto' => $this->formatMoney($detail->monto, (string) ($detail->moneda_simbolo ?? $currency)),
                    'deuda_pendiente' => $detailDebt > 0
                        ? $this->formatMoney($detailDebt, $currency)
                        : '',
                    'monto_favor' => $includeCredit ? $this->formatMoney($credit, $currency) : '',
                ];
            })->values();

            $historyColumns = [
                ['key' => 'cxc_id', 'label' => 'ID CXC'],
                ['key' => 'vehiculo', 'label' => 'Vehículo'],
                ['key' => 'descripcion', 'label' => 'Descripción CXC'],
                ['key' => 'monto', 'label' => 'Monto original CXC'],
            ];
            if ($hasDebt) {
                $historyColumns[] = ['key' => 'deuda_pendiente', 'label' => 'Deuda pendiente'];
            }
            if ($hasCredit) {
                $historyColumns[] = ['key' => 'monto_favor', 'label' => 'Monto a favor'];
            }
            $row->relation_groups = $historyRows->isNotEmpty() ? [[
                'label' => 'Cuentas por cobrar',
                'columns' => $historyColumns,
                'records' => $historyRows->all(),
            ]] : [];
            return $row;
        });
        $estadoStats = $this->bancosService->getEstadoCuentaStats($request);
        $stats = collect($estadoStats['monto_deuda'])
            ->map(fn ($amount, $symbol): array => [
                'label' => 'Deuda total (' . $symbol . ')',
                'value' => $this->formatMoney($amount, $symbol),
            ])
            ->values()
            ->all();
        foreach ($estadoStats['monto_favor'] as $symbol => $amount) {
            $stats[] = [
                'label' => 'Monto total a favor (' . $symbol . ')',
                'value' => $this->formatMoney($amount, $symbol),
            ];
        }

        return view('finanzas.reporte', [
            'title' => 'Estado de cuenta',
            'singularTitle' => 'Estado de cuenta',
            'items' => $items,
            'columns' => [
                ['key' => 'ruc_cliente', 'label' => 'RUC del cliente', 'type' => 'text'],
                ['key' => 'cliente_nombre', 'label' => 'Nombre del cliente', 'type' => 'text'],
                ['key' => 'servicio', 'label' => 'Comentario', 'type' => 'text'],
                ['key' => 'monto_deuda_display', 'label' => 'Monto deuda', 'type' => 'text', 'valueClass' => 'text-danger'],
                ['key' => 'monto_favor_display', 'label' => 'Monto a favor', 'type' => 'text', 'valueClass' => 'texto-monto-exito'],
            ],
            'stats' => $stats,
            'refreshStatsOnFilter' => true,
            'filters' => [
                ['name' => 'cliente', 'label' => 'Cliente', 'type' => 'text', 'placeholder' => 'RUC o nombre del cliente'],
                ['name' => 'moneda', 'label' => 'Tipo de moneda', 'type' => 'select', 'placeholder' => 'Todas', 'options' => [
                    ['value' => 'S/', 'label' => 'S/'],
                    ['value' => '$', 'label' => '$'],
                ]],
                ['name' => 'monto_deuda_desde', 'toName' => 'monto_deuda_hasta', 'label' => 'Monto deuda', 'type' => 'number_range', 'placeholder' => 'Monto'],
                ['name' => 'monto_favor_desde', 'toName' => 'monto_favor_hasta', 'label' => 'Monto a favor', 'type' => 'number_range', 'placeholder' => 'Monto'],
            ],
            'showActionsColumn' => true,
            'showResultStat' => true,
            'resultsLabel' => 'Clientes en cuentas por cobrar',
            'identifierKey' => 'estado_cuenta_key',
            'historyTitle' => 'Cuentas por cobrar',
            'hideHistoryPanelTitle' => true,
            'perPageOptions' => [25, 50, 100],
            'defaultPerPage' => 25,
            'exportRoutes' => [
                'pdf' => route('modules.finanzas.estado-cuenta.export', ['format' => 'pdf']),
                'xlsx' => route('modules.finanzas.estado-cuenta.export', ['format' => 'xlsx']),
            ],
        ]);
    }

    public function exportEstadoCuenta(Request $request, string $format)
    {
        abort_unless(in_array(strtolower($format), ['pdf', 'xlsx'], true), 404);
        $rows = $this->bancosService->getEstadoCuentaExportRows($request);
        $columns = [
            ['key' => 'ruc_cliente', 'label' => 'RUC del cliente'],
            ['key' => 'cliente_nombre', 'label' => 'Nombre del cliente'],
            ['key' => 'servicio', 'label' => 'Comentario'],
            ['key' => 'monto_deuda', 'label' => 'Monto deuda'],
            ['key' => 'monto_favor', 'label' => 'Monto a favor'],
        ];
        $filename = 'estado_cuenta_' . now()->format('Ymd_His') . '.' . strtolower($format);
        return strtolower($format) === 'xlsx'
            ? $this->exportXlsxResponse($rows, $columns, $filename)
            : $this->exportPdfResponse($rows, $columns, 'Estado de cuenta', $filename);
    }

    public function notaCreditos(Request $request): View
    {
        $items = $this->notaCreditoQuery($request)
            ->orderByDesc('nc.fechaEmision')
            ->orderByDesc('nc.idnotacredito')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();
        $items->getCollection()->transform(function ($row): mixed {
            $row->fechaEmision_display = $row->fechaEmision ? date('d/m/Y H:i', strtotime((string) $row->fechaEmision)) : '-';
            $row->monto_display = $this->formatMoney($row->monto, $row->moneda_simbolo ?? null);
            $row->monto_disponible_display = $this->formatMoney($row->monto_disponible, $row->moneda_simbolo ?? null);
            $row->estado_display = match ((string) $row->estado) {
                'E' => (float) $row->monto_disponible > 0 ? 'Disponible' : 'Aplicada',
                'A' => 'Aplicada',
                default => (string) $row->estado,
            };
            $row->canEdit = (bool) $row->can_modify;
            $row->canDelete = (bool) $row->can_modify;
            return $row;
        });
        $totals = DB::table('nota_credito as nc')
            ->leftJoin('moneda as m', 'm.idmoneda', '=', 'nc.moneda_idmoneda')
            ->selectRaw("COALESCE(NULLIF(TRIM(m.simbolo), ''), 'S/') as moneda_simbolo")
            ->selectRaw('SUM(nc.monto) as monto_emitido')
            ->groupBy('m.simbolo')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                $this->normalizeCurrencySymbol((string) $row->moneda_simbolo) => (float) $row->monto_emitido,
            ]);

        return view('finanzas.reporte', [
            'title' => 'Nota de créditos',
            'singularTitle' => 'Nota de crédito',
            'items' => $items,
            'columns' => [
                ['key' => 'idnotacredito', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'docReferencia', 'label' => 'Factura', 'type' => 'text'],
                ['key' => 'cliente_nombre', 'label' => 'Cliente', 'type' => 'text'],
                ['key' => 'descripcion', 'label' => 'Comentario', 'type' => 'text'],
                ['key' => 'fechaEmision_display', 'label' => 'Fecha de emisión', 'type' => 'text'],
                ['key' => 'monto_display', 'label' => 'Monto', 'type' => 'text'],
                ['key' => 'monto_disponible_display', 'label' => 'Disponible', 'type' => 'text'],
                ['key' => 'estado_display', 'label' => 'Estado', 'type' => 'text'],
            ],
            'stats' => [
                ['label' => 'Monto emitido en soles', 'value' => $this->formatMoney($totals['S/'] ?? 0, 'S/')],
                ['label' => 'Monto emitido en dólares', 'value' => $this->formatMoney($totals['$'] ?? 0, '$')],
            ],
            'refreshStatsOnFilter' => true,
            'filters' => [
                ['name' => 'cliente', 'label' => 'Cliente', 'type' => 'text', 'placeholder' => 'RUC o nombre del cliente'],
                ['name' => 'factura', 'label' => 'Factura', 'type' => 'text', 'placeholder' => 'Número de factura'],
                ['name' => 'moneda', 'label' => 'Tipo de moneda', 'type' => 'select', 'placeholder' => 'Todas', 'options' => [
                    ['value' => 'S/', 'label' => 'S/'],
                    ['value' => '$', 'label' => '$'],
                ]],
                ['name' => 'fechaDesde', 'toName' => 'fechaHasta', 'label' => 'Fecha de registro', 'type' => 'date_range'],
                ['name' => 'montoDesde', 'toName' => 'montoHasta', 'label' => 'Monto', 'type' => 'number_range', 'placeholder' => 'Ej. 35'],
            ],
            'createRoute' => route('modules.finanzas.nota-creditos.create'),
            'createButtonLabel' => 'Nueva Nota de crédito',
            'exportRoutes' => [
                'pdf' => route('modules.finanzas.nota-creditos.export', ['format' => 'pdf']),
                'xlsx' => route('modules.finanzas.nota-creditos.export', ['format' => 'xlsx']),
            ],
            'editRoute' => 'modules.finanzas.nota-creditos.edit',
            'showRoute' => 'modules.finanzas.nota-creditos.edit',
            'destroyRoute' => 'modules.finanzas.nota-creditos.destroy',
            'lockResource' => self::CREDIT_NOTE_LOCK_RESOURCE,
            'showResultStat' => true,
            'resultsLabel' => 'Notas de crédito encontradas',
            'identifierKey' => 'idnotacredito',
        ]);
    }

    public function export(Request $request, string $format)
    {
        $format = strtolower($format);
        abort_unless(in_array($format, ['pdf', 'xlsx'], true), 404);
        $isXlsx = $format === 'xlsx';
        $columns = [
            ['key' => 'idbancos', 'label' => 'ID'],
            ['key' => 'entidad_bancaria_nombre', 'label' => 'Entidad bancaria'],
            ['key' => 'cliente_nombre', 'label' => 'Cliente'],
            ['key' => 'fechaRegistro', 'label' => 'Fecha de registro'],
            ['key' => 'informacion', 'label' => 'Información'],
            [
                'key' => 'monto',
                'label' => 'Monto',
                'type' => $isXlsx ? 'number' : 'text',
                'value' => fn ($row) => $isXlsx
                    ? (float) $row->monto
                    : $this->formatMoney($row->monto, $row->moneda_simbolo ?? null),
                'numberFormat' => fn ($row): string => '"'
                    . str_replace('"', '""', $this->normalizeCurrencySymbol((string) ($row->moneda_simbolo ?? 'S/')))
                    . '" #,##0.00',
            ],
            [
                'key' => 'tipoMov',
                'label' => 'Tipo de movimiento',
                'value' => fn ($row): string => $this->movementLabel($row->tipoMov, $row->informacion),
            ],
        ];
        $filename = 'bancos_finanzas_' . now()->format('Ymd_His') . '.' . strtolower($format);
        $rows = $this->resolveBankExportRows(
            $this->bancosService->getExportRows($request, false),
            $request
        );
        return $format === 'xlsx'
            ? $this->exportXlsxResponse($rows, $columns, $filename)
            : $this->exportPdfResponse($rows, $columns, 'Banco / Caja', $filename);
    }

    public function exportNotaCreditos(Request $request, string $format)
    {
        abort_unless(in_array(strtolower($format), ['pdf', 'xlsx'], true), 404);
        $columns = [
            ['key' => 'idnotacredito', 'label' => 'ID'],
            ['key' => 'docReferencia', 'label' => 'Factura'],
            ['key' => 'cliente_nombre', 'label' => 'Cliente'],
            ['key' => 'descripcion', 'label' => 'Comentario'],
            ['key' => 'fechaEmision', 'label' => 'Fecha de emisión'],
            [
                'key' => 'monto',
                'label' => 'Monto',
                'value' => fn ($row): string => $this->formatMoney($row->monto, $row->moneda_simbolo ?? null),
            ],
            [
                'key' => 'monto_disponible',
                'label' => 'Disponible',
                'value' => fn ($row): string => $this->formatMoney($row->monto_disponible, $row->moneda_simbolo ?? null),
            ],
            [
                'key' => 'estado',
                'label' => 'Estado',
                'value' => fn ($row): string => match ((string) $row->estado) {
                    'E' => (float) $row->monto_disponible > 0 ? 'Disponible' : 'Aplicada',
                    'A' => 'Aplicada',
                    default => (string) $row->estado,
                },
            ],
        ];
        $filename = 'notas_credito_' . now()->format('Ymd_His') . '.' . strtolower($format);
        $rows = $this->notaCreditoQuery($request)
            ->orderByDesc('nc.fechaEmision')
            ->orderByDesc('nc.idnotacredito')
            ->get();

        return strtolower($format) === 'xlsx'
            ? $this->exportXlsxResponse($rows, $columns, $filename)
            : $this->exportPdfResponse($rows, $columns, 'Notas de crédito', $filename);
    }

    public function create(Request $request): View|RedirectResponse
    {
        $type = strtoupper(trim((string) $request->query('tipoMov', '')));
        $isCreditNote = $request->boolean('notaCredito') && $type === 'S';
        if ($isCreditNote) {
            return redirect()->route('modules.finanzas.nota-creditos.create');
        }
        return view('finanzas.bancos-form', $this->formViewData('Nuevo movimiento bancario', 'create', null, null, $type));
    }

    public function createNotaCredito(): View
    {
        return view('finanzas.bancos-form', $this->formViewData(
            'Nueva Nota de crédito',
            'create',
            null,
            null,
            null,
            true
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $isCreditNote = $request->boolean('notaCredito');
        $validated = $this->validateData($request, $isCreditNote);

        if ($isCreditNote) {
            $this->insertNotaCredito($validated);
            return redirect()->route('modules.finanzas.nota-creditos.index')
                ->with('success', 'Nota de crédito creada correctamente.');
        }

        DB::table('bancos')->insert($validated);
        return redirect()->route('modules.finanzas.bancos.index')
            ->with('success', 'Movimiento bancario creado correctamente.');
    }

    public function storeNotaCredito(Request $request): RedirectResponse
    {
        $validated = $this->validateData($request, true);
        $this->insertNotaCredito($validated);

        return redirect()->route('modules.finanzas.nota-creditos.index')
            ->with('success', 'Nota de crédito creada correctamente.');
    }

    public function editNotaCredito(int $id): View|RedirectResponse
    {
        $record = DB::table('nota_credito')->where('idnotacredito', $id)->first();
        abort_unless($record !== null, 404);

        if (!$this->notaCreditoEsModificable($id, (string) $record->estado)) {
            return redirect()->route('modules.finanzas.nota-creditos.index')
                ->with('error', 'No se puede editar una nota de crédito que ya tiene aplicaciones asociadas.');
        }

        return view('finanzas.bancos-form', $this->formViewData(
            'Editar Nota de crédito',
            'edit',
            $record,
            $id,
            null,
            true
        ) + $this->prepareLockViewData(self::CREDIT_NOTE_LOCK_RESOURCE, (string) $id));
    }

    public function updateNotaCredito(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable(
            $request,
            self::CREDIT_NOTE_LOCK_RESOURCE,
            (string) $id,
            'nota de crédito',
            'modules.finanzas.nota-creditos.index'
        )) {
            return $redirect;
        }

        $validated = $this->validateData($request, true);
        $updated = DB::transaction(function () use ($id, $validated): bool {
            $record = DB::table('nota_credito')
                ->where('idnotacredito', $id)
                ->lockForUpdate()
                ->first();
            abort_unless($record !== null, 404);

            if (!$this->notaCreditoEsModificable($id, (string) $record->estado)) {
                return false;
            }

            DB::table('nota_credito')->where('idnotacredito', $id)->update([
                'cliente_idcliente' => $validated['cliente_idcliente'],
                'moneda_idmoneda' => $validated['moneda_idmoneda'],
                'docReferencia' => $validated['docReferencia'],
                'monto' => $validated['monto'],
                'descripcion' => $validated['descripcion'] ?? null,
            ]);

            return true;
        });

        if (!$updated) {
            return redirect()->route('modules.finanzas.nota-creditos.index')
                ->with('error', 'No se puede editar una nota de crédito que ya tiene aplicaciones asociadas.');
        }

        $this->publishResourceEvent(self::CREDIT_NOTE_LOCK_RESOURCE, (string) $id, 'updated');
        $this->releaseLockIfOwned($request, self::CREDIT_NOTE_LOCK_RESOURCE, (string) $id);

        return redirect()->route('modules.finanzas.nota-creditos.index')
            ->with('success', 'Nota de crédito actualizada correctamente.');
    }

    public function destroyNotaCredito(Request $request, int $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable(
            $request,
            self::CREDIT_NOTE_LOCK_RESOURCE,
            (string) $id,
            'nota de crédito',
            'modules.finanzas.nota-creditos.index'
        )) {
            return $redirect;
        }

        try {
            $deleted = DB::transaction(function () use ($id): bool {
                $record = DB::table('nota_credito')
                    ->where('idnotacredito', $id)
                    ->lockForUpdate()
                    ->first();
                abort_unless($record !== null, 404);

                if (!$this->notaCreditoEsModificable($id, (string) $record->estado)) {
                    return false;
                }

                DB::table('nota_credito')->where('idnotacredito', $id)->delete();

                return true;
            });
        } catch (QueryException) {
            return redirect()->route('modules.finanzas.nota-creditos.index')
                ->with('error', 'No se puede eliminar la nota de crédito porque tiene dependencias asociadas.');
        }

        if (!$deleted) {
            return redirect()->route('modules.finanzas.nota-creditos.index')
                ->with('error', 'No se puede eliminar una nota de crédito que ya tiene aplicaciones asociadas.');
        }

        $this->publishResourceEvent(self::CREDIT_NOTE_LOCK_RESOURCE, (string) $id, 'deleted');
        $this->releaseLockIfOwned($request, self::CREDIT_NOTE_LOCK_RESOURCE, (string) $id);

        return redirect()->route('modules.finanzas.nota-creditos.index')
            ->with('success', 'Nota de crédito eliminada correctamente.');
    }

    public function edit(int $id): View
    {
        $record = DB::table('bancos')->where('idbancos', $id)->first();
        abort_unless($record !== null, 404);
        return view('finanzas.bancos-form', $this->formViewData('Editar movimiento bancario', 'edit', $record, $id));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        abort_unless(DB::table('bancos')->where('idbancos', $id)->exists(), 404);
        DB::table('bancos')->where('idbancos', $id)->update($this->validateData($request));
        return redirect()->route('modules.finanzas.bancos.index')->with('success', 'Movimiento bancario actualizado correctamente.');
    }

    public function destroy(int $id): RedirectResponse
    {
        try {
            DB::table('bancos')->where('idbancos', $id)->delete();
        } catch (QueryException) {
            return redirect()->route('modules.finanzas.bancos.index')->with('error', 'No se puede eliminar el movimiento porque tiene dependencias asociadas.');
        }
        return redirect()->route('modules.finanzas.bancos.index')->with('success', 'Movimiento bancario eliminado correctamente.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $ids = collect((array) $request->input('selectedIds', []))
            ->filter(fn (mixed $id): bool => is_numeric((string) $id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        if ($ids->isEmpty()) return redirect()->route('modules.finanzas.bancos.index')->with('error', 'Selecciona al menos un movimiento.');
        try { DB::table('bancos')->whereIn('idbancos', $ids->all())->delete(); }
        catch (QueryException) { return redirect()->route('modules.finanzas.bancos.index')->with('error', 'No se pueden eliminar movimientos con dependencias asociadas.'); }
        return redirect()->route('modules.finanzas.bancos.index')->with('success', 'Movimientos bancarios eliminados correctamente.');
    }

    private function validateData(Request $request, bool $isCreditNote = false): array
    {
        if ($isCreditNote) {
            $data = $request->validate([
                'cliente_idcliente' => ['required', 'string', 'max:20', 'exists:cliente,idcliente'],
                'docReferencia' => ['required', 'string', 'max:15', 'regex:' . self::SAFE_TEXT_REGEX],
                'descripcion' => ['nullable', 'string', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
                'moneda_idmoneda' => ['required', 'integer', 'exists:moneda,idmoneda'],
                'monto' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            ]);
            $data['monto'] = number_format((float) $data['monto'], 2, '.', '');

            return $data;
        }

        $rules = [
            'entidadBancaria_identidadBancaria' => ['required', 'integer', 'exists:entidadbancaria,identidadBancaria'],
            'fechaRegistro' => ['required', 'date'],
            'informacion' => ['nullable', 'string', 'max:100', 'regex:' . self::SAFE_TEXT_REGEX],
            'monto' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
        ];

        $rules['tipoMov'] = ['required', 'in:I,S,A'];

        $data = $request->validate($rules);
        $data['monto'] = number_format((float) $data['monto'], 2, '.', '');
        return $data;
    }

    private function insertNotaCredito(array $validated): void
    {
        DB::table('nota_credito')->insert([
            'cliente_idcliente' => $validated['cliente_idcliente'],
            'moneda_idmoneda' => $validated['moneda_idmoneda'],
            'docReferencia' => $validated['docReferencia'],
            'monto' => $validated['monto'],
            'descripcion' => $validated['descripcion'] ?? null,
            'fechaEmision' => now(),
            'estado' => 'E',
            'cuentasPorCobrar_idcuentasPorCobrar' => null,
            'fechaAplicacion' => null,
        ]);
    }

    private function notaCreditoEsModificable(int $id, string $estado): bool
    {
        return $estado === 'E'
            && !DB::table('nota_credito_aplicacion')
                ->where('nota_credito_idnotacredito', $id)
                ->exists();
    }

    private function formViewData(string $title, string $mode, object|array|null $record = null, ?int $id = null, ?string $defaultTipoMov = null, bool $isCreditNote = false): array
    {
        $value = fn(string $key, mixed $default = null): mixed => data_get($record, $key, $default);
        $type = $value('tipoMov', $defaultTipoMov);
        $fields = $isCreditNote ? [] : [
            ['name' => 'entidadBancaria_identidadBancaria', 'type' => 'select', 'label' => 'Entidad bancaria', 'required' => true, 'tomSelect' => true, 'optionsData' => $this->bancosService->getBankOptions(), 'optionKey' => 'identidadBancaria', 'optionLabel' => 'label', 'placeholder' => 'Selecciona entidad bancaria'],
        ];
        if ($isCreditNote) {
            $currencyOptions = DB::table('moneda')
                ->where('idmoneda', '!=', 4)
                ->select('idmoneda', 'detalle', 'simbolo')
                ->orderBy('detalle')
                ->get()
                ->map(function ($currency): object {
                    $symbol = trim((string) $currency->simbolo);

                    return (object) [
                        'idmoneda' => $currency->idmoneda,
                        'label' => strtoupper($symbol) === 'S' ? 'S/' : $symbol,
                    ];
                });
            $fields[] = [
                'name' => 'cliente_idcliente',
                'type' => 'select',
                'label' => 'Cliente',
                'required' => true,
                'tomSelect' => true,
                'optionsData' => DB::table('cliente')
                    ->select('idcliente', DB::raw('COALESCE(razonSocial, nombreComercial, idcliente) as cliente_label'))
                    ->orderBy('cliente_label')
                    ->get(),
                'optionKey' => 'idcliente',
                'optionLabel' => 'cliente_label',
                'placeholder' => 'Selecciona cliente',
            ];
            $fields[] = [
                'name' => 'docReferencia',
                'type' => 'text',
                'label' => 'Factura relacionada',
                'required' => true,
                'maxlength' => 15,
                'value' => $value('docReferencia'),
            ];
            $fields[] = [
                'name' => 'descripcion',
                'type' => 'text',
                'label' => 'Comentario',
                'required' => false,
                'maxlength' => 100,
                'value' => $value('descripcion'),
            ];
            $fields[] = [
                'name' => 'moneda_idmoneda',
                'type' => 'select',
                'label' => 'Tipo de moneda',
                'required' => true,
                'value' => $value('moneda_idmoneda', data_get($currencyOptions->firstWhere('label', 'S/'), 'idmoneda')),
                'optionsData' => $currencyOptions,
                'optionKey' => 'idmoneda',
                'optionLabel' => 'label',
                'placeholder' => 'Selecciona tipo de moneda',
            ];
            $fields[] = [
                'name' => 'monto',
                'type' => 'number',
                'label' => 'Monto',
                'required' => true,
                'inputmode' => 'decimal',
                'step' => '0.01',
                'min' => 0,
                'value' => number_format((float) $value('monto', 0), 2, '.', ''),
            ];
        } else {
            $fields[] = [
                'name' => 'fechaRegistro',
                'type' => 'datetime-local',
                'label' => 'Fecha de registro',
                'required' => true,
                'value' => $value('fechaRegistro') ? date('Y-m-d\\TH:i', strtotime((string) $value('fechaRegistro'))) : now()->format('Y-m-d\\TH:i'),
            ];
            $fields[] = ['name' => 'monto', 'type' => 'number', 'label' => 'Monto', 'required' => true, 'inputmode' => 'decimal', 'step' => '0.01', 'min' => 0, 'value' => number_format((float) $value('monto', 0), 2, '.', '')];
            $fields[] = ['name' => 'informacion', 'type' => 'text', 'label' => 'Información', 'required' => false, 'maxlength' => 100];
            $fields[] = ['name' => 'tipoMov', 'type' => 'select', 'label' => 'Tipo de movimiento', 'required' => true, 'value' => $type, 'options' => $this->movementTypeOptions(), 'placeholder' => 'Selecciona tipo de movimiento'];
        }
        $backRoute = $isCreditNote
            ? route('modules.finanzas.nota-creditos.index')
            : route('modules.finanzas.bancos.index');

        return [
            'title' => $title,
            'moduleTitle' => $isCreditNote ? 'Notas de crédito' : 'Banco / Caja',
            'mode' => $mode,
            'formAction' => $mode === 'create'
                ? ($isCreditNote
                    ? route('modules.finanzas.nota-creditos.store')
                    : route('modules.finanzas.bancos.store'))
                : ($isCreditNote
                    ? route('modules.finanzas.nota-creditos.update', ['id' => $id])
                    : route('modules.finanzas.bancos.update', ['id' => $id])),
            'backRoute' => $backRoute,
            'record' => $record,
            'fields' => $fields,
            'readOnly' => $mode === 'edit',
        ];
    }

    private function notaCreditoQuery(Request $request)
    {
        $filters = $request->validate([
            'cliente' => ['nullable', 'string', 'max:100'],
            'factura' => ['nullable', 'string', 'max:50'],
            'moneda' => ['nullable', 'in:S/,$'],
            'fechaDesde' => ['nullable', 'date_format:Y-m-d'],
            'fechaHasta' => ['nullable', 'date_format:Y-m-d'],
            'montoDesde' => ['nullable', 'numeric', 'min:0'],
            'montoHasta' => ['nullable', 'numeric', 'min:0'],
        ]);
        $applied = DB::table('nota_credito_aplicacion')
            ->select('nota_credito_idnotacredito')
            ->where('estado', '1')
            ->groupBy('nota_credito_idnotacredito')
            ->selectRaw('SUM(monto) as monto_aplicado');
        $query = DB::table('nota_credito as nc')
            ->leftJoin('cliente as cli', 'cli.idcliente', '=', 'nc.cliente_idcliente')
            ->leftJoin('moneda as m', 'm.idmoneda', '=', 'nc.moneda_idmoneda')
            ->leftJoinSub($applied, 'aplicada', function ($join): void {
                $join->on('aplicada.nota_credito_idnotacredito', '=', 'nc.idnotacredito');
            })
            ->select([
                'nc.idnotacredito',
                'nc.cliente_idcliente',
                'nc.docReferencia',
                'nc.descripcion',
                'nc.fechaEmision',
                'nc.monto',
                'nc.estado',
            ])
            ->selectRaw("CASE WHEN nc.estado = 'E' AND NOT EXISTS (
                SELECT 1 FROM nota_credito_aplicacion AS historial
                WHERE historial.nota_credito_idnotacredito = nc.idnotacredito
            ) THEN 1 ELSE 0 END AS can_modify")
            ->selectRaw('COALESCE(cli.razonSocial, cli.nombreComercial, cli.idcliente, "-") as cliente_nombre')
            ->selectRaw('COALESCE(NULLIF(TRIM(m.simbolo), ""), "S/") as moneda_simbolo')
            ->selectRaw('ROUND(nc.monto - COALESCE(aplicada.monto_aplicado, 0), 2) as monto_disponible');

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $query->where(function ($builder) use ($term): void {
                $builder->where('nc.idnotacredito', 'like', $term)
                    ->orWhere('nc.docReferencia', 'like', $term)
                    ->orWhere('nc.descripcion', 'like', $term)
                    ->orWhere('cli.idcliente', 'like', $term)
                    ->orWhere('cli.razonSocial', 'like', $term)
                    ->orWhere('cli.nombreComercial', 'like', $term);
            });
        }

        $client = trim((string) ($filters['cliente'] ?? ''));
        if ($client !== '') {
            $term = '%' . $client . '%';
            $query->where(function ($builder) use ($term): void {
                $builder->where('cli.idcliente', 'like', $term)
                    ->orWhere('cli.razonSocial', 'like', $term)
                    ->orWhere('cli.nombreComercial', 'like', $term);
            });
        }
        $invoice = trim((string) ($filters['factura'] ?? ''));
        if ($invoice !== '') {
            $query->where('nc.docReferencia', 'like', '%' . $invoice . '%');
        }
        if (($filters['moneda'] ?? null) === 'S/') {
            $query->whereIn(DB::raw("UPPER(TRIM(m.simbolo))"), ['S/', 'S', 'SOL', 'SOLES']);
        } elseif (($filters['moneda'] ?? null) === '$') {
            $query->whereIn(DB::raw("UPPER(TRIM(m.simbolo))"), ['$', 'US$', 'USD']);
        }
        if (!empty($filters['fechaDesde'])) {
            $query->whereDate('nc.fechaEmision', '>=', $filters['fechaDesde']);
        }
        if (!empty($filters['fechaHasta'])) {
            $query->whereDate('nc.fechaEmision', '<=', $filters['fechaHasta']);
        }
        if (isset($filters['montoDesde'])) {
            $query->where('nc.monto', '>=', $filters['montoDesde']);
        }
        if (isset($filters['montoHasta'])) {
            $query->where('nc.monto', '<=', $filters['montoHasta']);
        }

        return $query;
    }

    private function normalizeCurrencySymbol(string $symbol): string
    {
        $normalized = strtoupper(str_replace(' ', '', trim($symbol)));
        if (in_array($normalized, ['', 'S', 'S/', 'SOL', 'SOLES'], true)) {
            return 'S/';
        }
        if (in_array($normalized, ['$', 'US$', 'USD'], true)) {
            return '$';
        }

        return $symbol;
    }

    private function formatMoney(mixed $value, ?string $symbol = null): string
    {
        $symbol = trim((string) ($symbol ?: 'S/'));
        $normalizedSymbol = mb_strtolower($symbol, 'UTF-8');
        if (in_array($normalizedSymbol, ['', 's', 's/', 'sol', 'soles'], true)) {
            $symbol = 'S/';
        }

        return $symbol . ' ' . number_format((float) $value, 2, '.', ',');
    }

    private function groupBankMovements($rows): \Illuminate\Support\Collection
    {
        $linkedOperations = [];
        $movementCollection = collect($rows);
        $clientNameAliases = $movementCollection->map(function ($row): string {
            return mb_strtolower(trim((string) ($row->cliente_nombre ?? '')), 'UTF-8');
        })->filter(function ($name): bool {
            return $name !== '' && $name !== '-' && !str_starts_with($name, 'sin ');
        })->unique()->values()->all();
        $cxcLinkedGroups = $this->cxcLinkedWithholdingGroups($movementCollection, $clientNameAliases);
        foreach ($movementCollection->groupBy(function ($row) use ($clientNameAliases): string {
            $timestamp = strtotime((string) ($row->fechaRegistro ?? ''));
            $client = $this->movementClientKey($row, $clientNameAliases);
            $currency = trim((string) ($row->moneda_simbolo ?? 'S/'));
            $cxcId = trim((string) ($row->cxc_id ?? ''));
            return implode('|', [$client ?: '-', $currency, $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '', $cxcId]);
        }) as $operationKey => $operationRows) {
            $operationTypes = $operationRows->pluck('tipoMov')->map(fn ($type) => strtoupper(trim((string) $type)));
            if ($operationTypes->contains('I') && ($operationTypes->contains('A') || $operationTypes->contains('C'))) {
                $linkedOperations[$operationKey] = true;
            }
        }
        foreach ($movementCollection->groupBy(function ($row) use ($clientNameAliases): string {
            $timestamp = strtotime((string) ($row->fechaRegistro ?? ''));
            $client = $this->movementClientKey($row, $clientNameAliases);
            $currency = trim((string) ($row->moneda_simbolo ?? 'S/'));
            return implode('|', [$client ?: '-', $currency, $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '']);
        }) as $minuteKey => $minuteRows) {
            $types = $minuteRows->pluck('tipoMov')->map(fn ($type) => strtoupper(trim((string) $type)));
            $hasIncomeAndBalance = $types->contains('I') && ($types->contains('A') || $types->contains('C'));
            if ($types->contains('A') || $hasIncomeAndBalance || $minuteRows->count() >= 3 || $types->contains('T')) {
                $linkedOperations['minute|' . $minuteKey] = true;
            }
        }

        return $movementCollection->groupBy(function ($row) use ($linkedOperations, $clientNameAliases, $cxcLinkedGroups): string {
            $movementId = (string) ($row->idbancos ?? '');
            if (isset($cxcLinkedGroups[$movementId])) {
                return $cxcLinkedGroups[$movementId];
            }

            $client = $this->movementClientKey($row, $clientNameAliases);
            $currency = trim((string) ($row->moneda_simbolo ?? 'S/'));
            $information = trim((string) ($row->informacion ?? ''));
            $service = trim((string) ($row->servicio ?? ''));
            $text = $information !== '' ? $information : $service;
            $quotation = $this->movementQuotationKey($text);
            if ($quotation !== null) {
                return implode('|', [$client ?: '-', $currency, 'cotizacion-' . $quotation]);
            }

            $timestamp = strtotime((string) ($row->fechaRegistro ?? ''));
            $operationMinute = $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '';
            $minuteOperationKey = implode('|', [$client ?: '-', $currency, $operationMinute]);
            if ($operationMinute !== '' && ($linkedOperations['minute|' . $minuteOperationKey] ?? false) === true) {
                return implode('|', [$client ?: '-', $currency, 'operacion-' . $operationMinute]);
            }

            $period = $this->movementPeriodKey($text);
            if ($period === 'sin-periodo') {
                $timestamp = strtotime((string) ($row->fechaRegistro ?? ''));
                $period = $timestamp > 0 ? 'fecha-' . date('Y-m-d', $timestamp) : 'sin-periodo';
            }
            $comment = $this->movementCommentKey($text);

            $timestamp = strtotime((string) ($row->fechaRegistro ?? ''));
            $operationDate = $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '';
            $cxcId = trim((string) ($row->cxc_id ?? ''));
            $operationKey = implode('|', [$client ?: '-', $currency, $operationDate, $cxcId]);
            if (($linkedOperations[$operationKey] ?? false) === true) {
                return 'operacion|' . $operationKey;
            }

            return implode('|', [$client ?: '-', $currency, $period, $comment]);
        })->map(function ($group): object {
            $first = $group->sortByDesc(fn ($row) => strtotime((string) $row->fechaRegistro) ?: 0)->first();
            $latest = $group->max(fn ($row) => strtotime((string) $row->fechaRegistro) ?: 0);
            $total = array_sum(array_map(function ($row): float {
                $type = strtoupper(trim((string) ($row->tipoMov ?? '')));
                if (in_array($type, ['I'], true) && !preg_match('/^\[ANULADO\]/iu', trim((string) ($row->informacion ?? '')))) {
                    return (float) $row->monto;
                }
                if ($type === 'T') {
                    $information = trim((string) ($row->informacion ?? ''));
                    if (!preg_match('/^\[ANULADO\]/iu', $information)
                        && !preg_match('/autodetracci[oó]n|autoretenci[oó]n/iu', $information)) {
                        return (float) $row->monto;
                    }
                }

                return 0.0;
            }, $group->all()));
            $currency = $first->moneda_simbolo ?? null;

            $first->fechaRegistro_display = $latest > 0 ? date('d/m/Y H:i', $latest) : '-';
            $first->monto_total = round($total, 2);
            $first->movement_ids = $group->pluck('idbancos')->map(fn ($id): int => (int) $id)->values()->all();
            $first->monto_display = $this->formatMoney($total, $currency);
            $first->informacion = trim((string) $first->informacion);
            $first->tipoMov_display = $group->count() > 1
                ? 'Varios movimientos (' . $group->count() . ')'
                : $this->movementLabel($first->tipoMov, $first->informacion);
            $first->relation_groups = [[
                'label' => 'Movimientos del grupo',
                'columns' => [
                    ['key' => 'idbancos', 'label' => 'ID', 'type' => 'text'],
                    ['key' => 'entidad_bancaria_nombre', 'label' => 'Entidad bancaria', 'type' => 'text'],
                    ['key' => 'informacion', 'label' => 'Información', 'type' => 'text'],
                    ['key' => 'registro', 'label' => 'Fecha de registro', 'type' => 'text'],
                    ['key' => 'monto_display', 'label' => 'Monto', 'type' => 'text'],
                    ['key' => 'tipoMov_display', 'label' => 'Movimiento', 'type' => 'text'],
                ],
                'records' => $group->sort(function ($left, $right): int {
                    $leftRank = $this->movementGroupSortRank($left->tipoMov, $left->informacion);
                    $rightRank = $this->movementGroupSortRank($right->tipoMov, $right->informacion);
                    if ($leftRank !== $rightRank) {
                        return $leftRank <=> $rightRank;
                    }

                    $leftTime = strtotime((string) $left->fechaRegistro) ?: 0;
                    $rightTime = strtotime((string) $right->fechaRegistro) ?: 0;
                    if ($leftTime !== $rightTime) {
                        return $rightTime <=> $leftTime;
                    }

                    return (int) $right->idbancos <=> (int) $left->idbancos;
                })->map(function ($row): array {
                    $row = clone $row;
                    $timestamp = strtotime((string) ($row->fechaRegistro ?? ''));
                    $row->registro = $timestamp !== false ? date('d/m/Y, H:i', $timestamp) : '-';
                    $row->monto_display = $this->formatMoney($row->monto, $row->moneda_simbolo ?? null);
                    $row->tipoMov_display = $this->movementLabel($row->tipoMov, $row->informacion);

                    return (array) $row;
                })->values()->all(),
            ]];

            return $first;
        })->values();
    }

    private function cxcLinkedWithholdingGroups($rows, array $clientNameAliases): array
    {
        $movements = collect($rows)->values();
        $parents = range(0, max(0, $movements->count() - 1));
        $find = function (int $index) use (&$parents, &$find): int {
            if ($parents[$index] !== $index) {
                $parents[$index] = $find($parents[$index]);
            }

            return $parents[$index];
        };
        $union = function (int $left, int $right) use (&$parents, $find): void {
            $leftRoot = $find($left);
            $rightRoot = $find($right);
            if ($leftRoot !== $rightRoot) {
                $parents[$rightRoot] = $leftRoot;
            }
        };
        $firstMovementByCxc = [];
        $movementIndicesById = [];

        foreach ($movements as $index => $row) {
            $movementIndicesById[(string) ($row->idbancos ?? '')] = $index;
            $client = $this->movementClientKey($row, $clientNameAliases);
            $currency = trim((string) ($row->moneda_simbolo ?? 'S/'));
            $cxcIds = preg_split('/[,\s]+/', trim((string) ($row->cxc_ids ?? ''))) ?: [];
            if ($cxcIds === [] || $cxcIds === ['']) {
                $cxcIds = [trim((string) ($row->cxc_id ?? ''))];
            }

            foreach (array_unique(array_filter($cxcIds, fn ($id): bool => trim((string) $id) !== '')) as $cxcId) {
                $key = implode('|', [$client, $currency, trim((string) $cxcId)]);
                if (isset($firstMovementByCxc[$key])) {
                    $union($index, $firstMovementByCxc[$key]);
                } else {
                    $firstMovementByCxc[$key] = $index;
                }
            }
        }

        $components = [];
        foreach ($movements as $index => $row) {
            $components[$find($index)][] = $row;
        }

        $linkedGroups = [];
        foreach ($components as $root => $component) {
            if (count($component) < 2) {
                continue;
            }

            $hasIncome = false;
            $hasWithholding = false;
            foreach ($component as $row) {
                $type = strtoupper(trim((string) ($row->tipoMov ?? '')));
                $information = trim((string) ($row->informacion ?? ''));
                if (preg_match('/^\[ANULADO\]/iu', $information) === 1) {
                    continue;
                }
                $hasIncome = $hasIncome || $type === 'I';
                $hasWithholding = $hasWithholding || $type === 'T';
            }

            if (!$hasIncome || !$hasWithholding) {
                continue;
            }

            $first = $component[0];
            $groupKey = implode('|', [
                'cxc-operation',
                $this->movementClientKey($first, $clientNameAliases),
                trim((string) ($first->moneda_simbolo ?? 'S/')),
                (string) $root,
            ]);
            foreach ($component as $row) {
                $movementId = (string) ($row->idbancos ?? '');
                if (isset($movementIndicesById[$movementId])) {
                    $linkedGroups[$movementId] = $groupKey;
                }
            }
        }

        return $linkedGroups;
    }

    private function filterGroupedMovementsByAmount($rows, Request $request): \Illuminate\Support\Collection
    {
        $filters = $request->validate([
            'montoDesde' => ['nullable', 'numeric', 'min:0'],
            'montoHasta' => ['nullable', 'numeric', 'min:0'],
        ]);
        $amountFrom = isset($filters['montoDesde']) ? round((float) $filters['montoDesde'], 2) : null;
        $amountTo = isset($filters['montoHasta']) ? round((float) $filters['montoHasta'], 2) : null;

        return collect($rows)->filter(function ($row) use ($amountFrom, $amountTo): bool {
            $amount = (float) ($row->monto_total ?? 0);

            return ($amountFrom === null || $amount >= $amountFrom)
                && ($amountTo === null || $amount <= $amountTo);
        })->values();
    }

    private function resolveBankExportRows(\Illuminate\Support\Collection $rows, Request $request): \Illuminate\Support\Collection
    {
        $groups = $this->filterGroupedMovementsByAmount(
            $this->groupBankMovements($rows),
            $request
        );
        $rawSelectedIds = $request->input('selectedIds', []);
        $hasSelection = is_array($rawSelectedIds) && $rawSelectedIds !== [];

        if ($hasSelection) {
            $selectedIds = collect($rawSelectedIds)
                ->filter(fn ($id): bool => is_scalar($id) && ctype_digit((string) $id))
                ->map(fn ($id): string => (string) $id)
                ->unique()
                ->values();
            $groups = $groups->filter(
                fn ($group): bool => collect($group->movement_ids ?? [])
                    ->map(fn ($id): string => (string) $id)
                    ->intersect($selectedIds)
                    ->isNotEmpty()
            );
        }

        $exportMovementIds = $groups
            ->flatMap(fn ($group): array => $group->movement_ids ?? [])
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->all();

        return $rows
            ->filter(fn ($row): bool => in_array((string) $row->idbancos, $exportMovementIds, true))
            ->values();
    }

    private function movementClientKey(object $row, array $clientNameAliases = []): string
    {
        $information = mb_strtolower(trim((string) ($row->informacion ?? '')), 'UTF-8');
        foreach ($clientNameAliases as $alias) {
            if ($information !== '' && mb_strpos($information, $alias, 0, 'UTF-8') !== false) {
                return $alias;
            }
        }

        $name = mb_strtolower(trim((string) ($row->cliente_nombre ?? '')), 'UTF-8');
        $name = preg_replace('/\s+/', ' ', $name ?? '') ?? '';
        if ($name !== '' && $name !== '-') {
            foreach ($clientNameAliases as $alias) {
                if (mb_strpos($alias, $name, 0, 'UTF-8') !== false || mb_strpos($name, $alias, 0, 'UTF-8') !== false) {
                    return $alias;
                }
            }
            return $name;
        }

        $ruc = trim((string) ($row->cliente_ruc ?? ''));
        return $ruc !== '' && $ruc !== '-' ? $ruc : '-';
    }

    private function movementQuotationKey(string $text): ?string
    {
        if (preg_match('/\b(CT|COTIZACION|Cotización)\s*[-#:]?\s*([A-Za-z0-9_-]+)/iu', $text, $matches) === 1) {
            $prefix = mb_strtoupper(trim($matches[1]), 'UTF-8');
            $number = trim($matches[2]);
            if ($prefix === 'CT') {
                return 'CT-' . $number;
            }
            if (str_starts_with(mb_strtoupper($number, 'UTF-8'), 'CT')) {
                return 'CT-' . preg_replace('/^CT[-#:]?/i', '', $number);
            }
            return 'COTIZACION-' . $number;
        }

        return null;
    }

    private function movementPeriodKey(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        foreach ($months as $index => $month) {
            if (preg_match('/\b' . preg_quote($month, '/') . '\s*(?:del\s*)?(19|20)\d{2}\b/u', $text, $matches) === 1) {
                return str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) . '-' . substr((string) $matches[0], -4);
            }
        }
        if (preg_match('/\b(19|20)\d{2}[-\/](0?[1-9]|1[0-2])\b/u', $text, $matches) === 1) {
            $parts = preg_split('/[-\/]/', $matches[0]);
            return str_pad((string) $parts[1], 2, '0', STR_PAD_LEFT) . '-' . $parts[0];
        }
        if (preg_match('/\b(0?[1-9]|1[0-2])[\-\/](19|20)\d{2}\b/u', $text, $matches) === 1) {
            $parts = preg_split('/[-\/]/', $matches[0]);
            return str_pad((string) $parts[0], 2, '0', STR_PAD_LEFT) . '-' . $parts[1];
        }

        return 'sin-periodo';
    }

    private function movementCommentKey(string $text): string
    {
        $text = preg_replace('/\[ANULADO\]/iu', ' ', $text ?? '');
        $text = preg_replace('/\b(?:CXC|Cotización|Cotizacion)\s*(?:#|:)?\s*[A-Za-z0-9_-]+/iu', ' ', $text ?? '');
        $text = preg_replace('/\s+-\s+[A-Za-z0-9]{4,10}(?=\s*(?:\(|$))/u', ' ', $text ?? '');
        $text = preg_replace('/\b(?:PLACA|placa)\s*[:#]?\s*[A-Za-z0-9_-]+/iu', ' ', $text ?? '');
        $text = preg_replace('/\b(?:enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre)\b(?:\s+(?:del\s+)?(?:19|20)\d{2})?/iu', ' ', $text ?? '');
        $text = preg_replace('/\b(?:19|20)\d{2}[-\/](?:0?[1-9]|1[0-2])\b|\b(?:0?[1-9]|1[0-2])[-\/](?:19|20)\d{2}\b|\b\d{1,2}[\/-]\d{1,2}[\/-](?:19|20)\d{2}\b/iu', ' ', $text ?? '');
        $text = mb_strtolower(trim(preg_replace('/\s+/', ' ', $text ?? '') ?? ''), 'UTF-8');

        return $text !== '' ? $text : 'sin-comentario';
    }

    private function movementTypeOptions(): array
    {
        return [
            ['value' => 'I', 'label' => 'Ingreso'],
            ['value' => 'S', 'label' => 'Salida'],
            ['value' => 'A', 'label' => 'Saldo a favor'],
        ];
    }

    private function movementFilterOptions(): array
    {
        return array_merge($this->movementTypeOptions(), [
            ['value' => 'C', 'label' => 'Uso de saldo a favor'],
            ['value' => 'T', 'label' => 'Detracción/retención aplicada'],
            ['value' => 'AUTODETRACCION', 'label' => 'Autodetracción'],
            ['value' => 'ANULADO', 'label' => 'Anulado/Revertido'],
        ]);
    }

    private function movementGroupSortRank(mixed $type, mixed $information): int
    {
        $informationText = mb_strtolower(trim((string) ($information ?? '')), 'UTF-8');
        if (str_starts_with(trim((string) ($information ?? '')), '[ANULADO]')) {
            return 6;
        }
        if (preg_match('/^(?:autodetracci[oó]n|autoretenci[oó]n)/iu', $informationText) === 1) {
            return 2;
        }

        return match ((string) $type) {
            'I' => 0,
            'T' => 1,
            'A' => 3,
            'S' => 4,
            'C' => 5,
            default => 4,
        };
    }

    private function movementLabel(mixed $type, mixed $information): string
    {
        if (str_starts_with(trim((string) ($information ?? '')), '[ANULADO]')) {
            return 'Anulado/Revertido';
        }

        if (str_starts_with(mb_strtolower(trim((string) ($information ?? '')), 'UTF-8'), 'autodetracción')) {
            return 'Autodetracción';
        }

        if (str_starts_with(mb_strtolower(trim((string) ($information ?? '')), 'UTF-8'), 'autoretención')) {
            return 'Autoretención';
        }

        return match ((string) $type) {
            'I' => 'Ingreso',
            'A' => 'Saldo a favor',
            'C' => 'Uso de saldo a favor',
            'T' => str_starts_with(mb_strtolower(trim((string) ($information ?? '')), 'UTF-8'), 'retención')
                ? 'Retención'
                : 'Detracción',
            default => 'Salida',
        };
    }

}
