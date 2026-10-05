<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Export\ExportableList;
use App\Services\BancosService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class BancosController extends Controller
{
    use ExportableList;

    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';

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
        $items = $this->bancosService->getList($request, $this->resolvePerPage($request));
        $items->getCollection()->transform(function ($row): mixed {
            $row->fechaRegistro_display = $row->fechaRegistro ? date('d/m/Y H:i', strtotime((string) $row->fechaRegistro)) : '-';
            $row->monto_display = $this->formatMoney($row->monto, $row->moneda_simbolo ?? null);
            $row->tipoMov_display = $this->movementLabel($row->tipoMov, $row->informacion);
            return $row;
        });

        $stats = $this->bancosService->getStats($request);
        return view('finanzas.bancos', [
            'title' => 'Banco / Caja',
            'singularTitle' => 'Movimiento bancario',
            'items' => $items,
            'columns' => [
                ['key' => 'idbancos', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'entidad_bancaria_nombre', 'label' => 'Entidad bancaria', 'type' => 'text'],
                ['key' => 'cliente_nombre', 'label' => 'Cliente', 'type' => 'text'],
                ['key' => 'fechaRegistro_display', 'label' => 'Fecha de registro', 'type' => 'text'],
                ['key' => 'informacion', 'label' => 'Información', 'type' => 'text'],
                ['key' => 'monto_display', 'label' => 'Monto', 'type' => 'text'],
                ['key' => 'tipoMov_display', 'label' => 'Movimiento', 'type' => 'text'],
            ],
            'stats' => collect($stats['balances'])->flatMap(function (array $balance, string $symbol): array {
                return [
                    ['label' => 'Balance positivo (' . $symbol . ')', 'value' => $this->formatMoney($balance['balance_positivo'], $symbol)],
                    ['label' => 'Balance negativo (' . $symbol . ')', 'value' => $this->formatMoney($balance['balance_negativo'], $symbol)],
                    ['label' => 'Anulado/Revertido (' . $symbol . ')', 'value' => $this->formatMoney($balance['anulado_revertido'] ?? 0, $symbol)],
                ];
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
                ['name' => 'tipoMov', 'label' => 'Movimiento', 'type' => 'select', 'placeholder' => 'Todos', 'options' => [
                    ['value' => 'I', 'label' => 'Ingreso'],
                    ['value' => 'S', 'label' => 'Salida'],
                    ['value' => 'A', 'label' => 'Saldo a favor'],
                    ['value' => 'C', 'label' => 'Aplicación de saldo a favor'],
                    ['value' => 'T', 'label' => 'Detracción/retención aplicada'],
                ]],
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
        $request->merge(['tipoMov' => 'S']);
        $balances = $this->bancosService->getStats($request)['balances'] ?? [];
        $amountInSoles = (float) ($balances['S/']['balance_negativo'] ?? 0);
        $amountInDollars = collect($balances)
            ->filter(fn (array $balance, string $symbol): bool => in_array(strtoupper(str_replace(' ', '', trim($symbol))), ['$', 'US$', 'USD'], true))
            ->sum('balance_negativo');
        $bankOptions = $this->bancosService->getBankOptions()
            ->map(fn ($bank): array => [
                'value' => (string) $bank->identidadBancaria,
                'label' => $bank->label,
            ])
            ->all();
        $items = $this->bancosService->getList($request, $this->resolvePerPage($request));
        $items->getCollection()->transform(function ($row): mixed {
            $row->fechaRegistro_display = $row->fechaRegistro ? date('d/m/Y H:i', strtotime((string) $row->fechaRegistro)) : '-';
            $row->monto_display = $this->formatMoney($row->monto, $row->moneda_simbolo ?? null);
            return $row;
        });

        return view('finanzas.reporte', [
            'title' => 'Nota de créditos',
            'singularTitle' => 'Nota de crédito',
            'items' => $items,
            'columns' => [
                ['key' => 'idbancos', 'label' => 'ID', 'type' => 'text'],
                ['key' => 'entidad_bancaria_nombre', 'label' => 'Entidad bancaria', 'type' => 'text'],
                ['key' => 'cliente_nombre', 'label' => 'Cliente', 'type' => 'text'],
                ['key' => 'fechaRegistro_display', 'label' => 'Fecha de registro', 'type' => 'text'],
                ['key' => 'monto_display', 'label' => 'Monto', 'type' => 'text'],
            ],
            'stats' => [
                ['label' => 'Monto de salida en soles', 'value' => $this->formatMoney($amountInSoles, 'S/')],
                ['label' => 'Monto de salida en dólares', 'value' => $this->formatMoney((float) $amountInDollars, '$')],
            ],
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
            ],
            'createRoute' => route('modules.finanzas.bancos.create', ['tipoMov' => 'S', 'notaCredito' => 1]),
            'createButtonLabel' => 'Nueva Nota de crédito',
            'exportRoutes' => [
                'pdf' => route('modules.finanzas.nota-creditos.export', ['format' => 'pdf']),
                'xlsx' => route('modules.finanzas.nota-creditos.export', ['format' => 'xlsx']),
            ],
            'showActionsColumn' => false,
            'showResultStat' => true,
            'resultsLabel' => 'Notas de crédito encontradas',
            'identifierKey' => 'idbancos',
        ]);
    }

    public function export(Request $request, string $format)
    {
        abort_unless(in_array(strtolower($format), ['pdf', 'xlsx'], true), 404);
        $columns = [
            ['key' => 'idbancos', 'label' => 'ID'],
            ['key' => 'entidad_bancaria_nombre', 'label' => 'Entidad bancaria'],
            ['key' => 'cliente_nombre', 'label' => 'Cliente'],
            ['key' => 'fechaRegistro', 'label' => 'Fecha de registro'],
            ['key' => 'informacion', 'label' => 'Información'],
            [
                'key' => 'monto',
                'label' => 'Monto',
                'value' => fn ($row): string => $this->formatMoney($row->monto, $row->moneda_simbolo ?? null),
            ],
            [
                'key' => 'tipoMov',
                'label' => 'Tipo de movimiento',
                'value' => fn ($row): string => $this->movementLabel($row->tipoMov, $row->informacion),
            ],
        ];
        $filename = 'bancos_finanzas_' . now()->format('Ymd_His') . '.' . strtolower($format);
        $rows = $this->bancosService->getExportRows($request);
        return strtolower($format) === 'xlsx'
            ? $this->exportXlsxResponse($rows, $columns, $filename)
            : $this->exportPdfResponse($rows, $columns, 'Banco / Caja', $filename);
    }

    public function exportNotaCreditos(Request $request, string $format)
    {
        $request->merge(['tipoMov' => 'S']);

        return $this->export($request, $format);
    }

    public function create(Request $request): View
    {
        $type = strtoupper(trim((string) $request->query('tipoMov', '')));
        $isCreditNote = $request->boolean('notaCredito') && $type === 'S';
        $title = $isCreditNote ? 'Nueva Nota de crédito' : 'Nuevo movimiento bancario';

        return view('finanzas.bancos-form', $this->formViewData($title, 'create', null, null, $type, $isCreditNote));
    }

    public function store(Request $request): RedirectResponse
    {
        $isCreditNote = $request->boolean('notaCredito');
        $validated = $this->validateData($request, $isCreditNote);

        if ($isCreditNote) {
            $cxc = DB::table('cuentasporcobrar')
                ->where('cliente_idcliente', $validated['cliente_idcliente'])
                ->where('moneda_idmoneda', $validated['moneda_idmoneda'])
                ->where('montoActual', '>', 0)
                ->orderBy('idcuentasPorCobrar')
                ->first();
            if (!$cxc) {
                return redirect()->back()->withInput()->with('error', 'El cliente no tiene una cuenta por cobrar con saldo en la moneda seleccionada.');
            }
            if ((float) $validated['monto'] > $this->availableCredit((string) $cxc->cliente_idcliente, (int) $cxc->moneda_idmoneda)) {
                return redirect()->back()->withInput()->with('error', 'El monto de la nota de crédito supera el saldo a favor disponible.');
            }
            $description = trim((string) ($validated['informacion'] ?? ''));
            $validated['informacion'] = 'Nota de crédito: ' . ($description !== '' ? $description : 'Devolución de saldo a favor');
            $paymentFile = $request->file('archivoPago');
            $storedPath = null;

            try {
                DB::transaction(function () use ($validated, $cxc, $paymentFile, &$storedPath): void {
                    if ($paymentFile !== null) {
                        $storedPath = $paymentFile->store('comprobantes-pago/notas-credito', 'public');
                        if (!$storedPath) {
                            throw new \RuntimeException('No se pudo guardar el archivo de pago.');
                        }
                    }

                    $bankData = $validated;
                    unset($bankData['cliente_idcliente'], $bankData['moneda_idmoneda'], $bankData['archivoPago']);
                    $bankId = DB::table('bancos')->insertGetId($bankData);
                    DB::table('detallecxc')->insert([
                        'formaPago_idformaPago' => DB::table('formapago')->orderBy('idformaPago')->value('idformaPago'),
                        'cuentasPorCobrar_idcuentasPorCobrar' => $cxc->idcuentasPorCobrar,
                        'moneda_idmoneda' => $cxc->moneda_idmoneda,
                        'descripcion' => 'Nota de crédito / devolución de saldo a favor',
                        'fechaPagoProgramada' => $validated['fechaRegistro'],
                        'fechaPago' => $validated['fechaRegistro'],
                        'bancos_idbancos' => $bankId,
                        'estado' => '1',
                    ]);
                    if ($storedPath !== null) {
                        DB::table('evidenciapago')->insert([
                            'fechaCarga' => now()->format('Y-m-d H:i:s'),
                            'archivo' => $storedPath,
                            'bancos_idbancos' => $bankId,
                        ]);
                    }
                });
            } catch (\Throwable $exception) {
                if ($storedPath !== null) {
                    Storage::disk('public')->delete($storedPath);
                }

                throw $exception;
            }
        } else {
            DB::table('bancos')->insert($validated);
        }

        $returnRoute = $isCreditNote ? 'modules.finanzas.nota-creditos.index' : 'modules.finanzas.bancos.index';
        $message = $isCreditNote ? 'Nota de crédito creada correctamente.' : 'Movimiento bancario creado correctamente.';

        return redirect()->route($returnRoute)->with('success', $message);
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
        $ids = collect((array) $request->input('selectedIds', []))->filter('is_numeric')->map('intval')->unique()->values();
        if ($ids->isEmpty()) return redirect()->route('modules.finanzas.bancos.index')->with('error', 'Selecciona al menos un movimiento.');
        try { DB::table('bancos')->whereIn('idbancos', $ids->all())->delete(); }
        catch (QueryException) { return redirect()->route('modules.finanzas.bancos.index')->with('error', 'No se pueden eliminar movimientos con dependencias asociadas.'); }
        return redirect()->route('modules.finanzas.bancos.index')->with('success', 'Movimientos bancarios eliminados correctamente.');
    }

    private function validateData(Request $request, bool $isCreditNote = false): array
    {
        $rules = [
            'entidadBancaria_identidadBancaria' => ['required', 'integer', 'exists:entidadbancaria,identidadBancaria'],
            'fechaRegistro' => ['required', 'date'],
            'informacion' => ['nullable', 'string', 'max:' . ($isCreditNote ? 80 : 100), 'regex:' . self::SAFE_TEXT_REGEX],
            'monto' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
        ];

        if ($isCreditNote) {
            $rules += [
                'cliente_idcliente' => ['required', 'string', 'max:20', 'exists:cliente,idcliente'],
                'moneda_idmoneda' => ['required', 'integer', 'exists:moneda,idmoneda'],
                'archivoPago' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            ];
        } else {
            $rules['tipoMov'] = ['required', 'in:I,S'];
        }

        $data = $request->validate($rules);
        if ($isCreditNote) {
            $data['tipoMov'] = 'S';
        }

        $data['monto'] = number_format((float) $data['monto'], 2, '.', '');
        return $data;
    }

    private function formViewData(string $title, string $mode, object|array|null $record = null, ?int $id = null, ?string $defaultTipoMov = null, bool $isCreditNote = false): array
    {
        $value = fn(string $key, mixed $default = null): mixed => data_get($record, $key, $default);
        $type = $value('tipoMov', $defaultTipoMov);
        $fields = [
            ['name' => 'entidadBancaria_identidadBancaria', 'type' => 'select', 'label' => 'Entidad bancaria', 'required' => true, 'tomSelect' => true, 'optionsData' => $this->bancosService->getBankOptions(), 'optionKey' => 'identidadBancaria', 'optionLabel' => 'label', 'placeholder' => 'Selecciona entidad bancaria'],
        ];
        if ($isCreditNote && $type === 'S' && $mode === 'create') {
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
        }
        $fields[] = [
            'name' => 'fechaRegistro',
            'type' => 'datetime-local',
            'label' => 'Fecha de registro',
            'required' => true,
            'value' => $value('fechaRegistro') ? date('Y-m-d\\TH:i', strtotime((string) $value('fechaRegistro'))) : now()->format('Y-m-d\\TH:i'),
        ];
        if ($isCreditNote && $type === 'S' && $mode === 'create') {
            $fields[] = [
                'name' => 'informacion',
                'type' => 'text',
                'label' => 'Información',
                'required' => false,
                'maxlength' => 80,
                'datalistOptions' => ['Devolución de dinero', 'Reembolso'],
            ];
            $fields[] = [
                'name' => 'moneda_idmoneda',
                'type' => 'select',
                'label' => 'Tipo de moneda',
                'required' => true,
                'optionsData' => DB::table('moneda')
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
                    }),
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
            $fields[] = [
                'name' => 'archivoPago',
                'type' => 'file',
                'label' => 'Archivo de pago',
                'required' => false,
                'fileKind' => 'file',
                'accept' => 'image/jpeg,image/png,application/pdf',
            ];
        } else {
            $fields[] = ['name' => 'monto', 'type' => 'number', 'label' => 'Monto', 'required' => true, 'inputmode' => 'decimal', 'step' => '0.01', 'min' => 0, 'value' => number_format((float) $value('monto', 0), 2, '.', '')];
            $fields[] = ['name' => 'informacion', 'type' => 'text', 'label' => 'Información', 'required' => false, 'maxlength' => 100];
            $fields[] = ['name' => 'tipoMov', 'type' => 'select', 'label' => 'Tipo de movimiento', 'required' => true, 'value' => $type, 'options' => [['value' => 'I', 'label' => 'Ingreso'], ['value' => 'S', 'label' => 'Salida']], 'placeholder' => 'Selecciona tipo de movimiento'];
        }
        $backRoute = $isCreditNote
            ? route('modules.finanzas.nota-creditos.index')
            : route('modules.finanzas.bancos.index');

        return [
            'title' => $title,
            'moduleTitle' => 'Banco / Caja',
            'mode' => $mode,
            'formAction' => $mode === 'create'
                ? route('modules.finanzas.bancos.store', $isCreditNote ? ['notaCredito' => 1] : [])
                : route('modules.finanzas.bancos.update', ['id' => $id]),
            'backRoute' => $backRoute,
            'record' => $record,
            'fields' => $fields,
            'readOnly' => false,
        ];
    }

    private function availableCredit(string $clientId, int $currencyId): float
    {
        $credits = DB::table('bancos as b')->join('detallecxc as dc', 'dc.bancos_idbancos', '=', 'b.idbancos')->join('cuentasporcobrar as c', 'c.idcuentasPorCobrar', '=', 'dc.cuentasPorCobrar_idcuentasPorCobrar')->where('b.tipoMov', 'A')->where('b.informacion', 'not like', '[ANULADO]%')->where('c.cliente_idcliente', $clientId)->where('dc.moneda_idmoneda', $currencyId)->sum('b.monto');
        $used = DB::table('bancos as b')->join('detallecxc as dc', 'dc.bancos_idbancos', '=', 'b.idbancos')->join('cuentasporcobrar as c', 'c.idcuentasPorCobrar', '=', 'dc.cuentasPorCobrar_idcuentasPorCobrar')->whereIn('b.tipoMov', ['C', 'S'])->where(function ($q): void { $q->where('b.informacion', 'like', 'Aplicación saldo a favor%')->orWhere('b.informacion', 'like', 'Nota de crédito:%'); })->where('b.informacion', 'not like', '[ANULADO]%')->where('c.cliente_idcliente', $clientId)->where('dc.moneda_idmoneda', $currencyId)->sum('b.monto');
        return max(0, (float) $credits - (float) $used);
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

    private function movementLabel(mixed $type, mixed $information): string
    {
        if (str_starts_with(trim((string) ($information ?? '')), '[ANULADO]')) {
            return 'Anulado/Revertido';
        }

        return match ((string) $type) {
            'I' => 'Ingreso',
            'A' => 'Saldo a favor',
            'C' => 'Aplicación de saldo a favor',
            'T' => 'Detracción/retención aplicada',
            default => 'Salida',
        };
    }

}
