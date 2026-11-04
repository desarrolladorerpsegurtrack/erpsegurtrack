<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Export\ExportableList;
use App\Http\Controllers\Permission\HandlesResourceLock;
use App\Services\CotizacionService;
use App\Services\CuentasPorCobrarService;
use App\Services\CxcService;
use App\Support\ResourceLock;
use App\Support\ServicePeriod;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CuentasPorCobrarController extends Controller
{
    use ExportableList;
    use HandlesResourceLock;

    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';

    private const LOCK_RESOURCE = 'cuentasporcobrar';

    public function __construct(
        protected CxcService $cxcService,
        protected CuentasPorCobrarService $cuentasPorCobrarService,
        protected CotizacionService $cotizacionService,
    ) {
    }

    private function publishQuotationPaymentEventsAfterResponse(string $quotationId, int $cxcId, string $state): void
    {
        app()->terminating(function () use ($quotationId, $cxcId, $state): void {
            $this->publishResourceEvent('ventas.cotizaciones', $quotationId, 'updated', [
                'estado' => $state,
                'origen' => 'cuentasporcobrar',
            ]);
            $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $cxcId, 'updated');
        });
    }

    public function index(Request $request): View
    {
        $entidadBancariaOptions = $this->entidadBancariaOptions();
        $defaultBank = $entidadBancariaOptions->first(
            fn(array $option): bool => $option['is_bcp']
        );
        $activeTab = $request->query('tab') === 'servicios' ? 'servicios' : 'cotizaciones';
        $quoteClient = trim((string) $request->query('quote_client', ''));
        $quoteNumber = trim((string) $request->query('quote_number', ''));
        $quotePaymentFormat = trim((string) $request->query('quote_payment_format', ''));
        $quoteValidity = trim((string) $request->query('quote_validity', ''));
        $quoteService = trim((string) $request->query('quote_service', ''));
        $quoteDate = trim((string) $request->query('quote_date', ''));
        $quoteStatus = trim((string) $request->query('quote_status', 'all'));
        $quoteValidityOptions = DB::table('vigenciaoferta')
            ->select('idvigenciaOferta', 'detalle')
            ->orderBy('detalle')
            ->get()
            ->map(fn($row): array => [
                'value' => (string) $row->idvigenciaOferta,
                'label' => trim((string) $row->detalle),
            ]);

        $perPage = $this->resolvePerPage($request);
        $cotizacionesPendientes = new LengthAwarePaginator(
            [],
            0,
            $perPage,
            LengthAwarePaginator::resolveCurrentPage('quotes_page'),
            ['path' => $request->url(), 'query' => $request->query(), 'pageName' => 'quotes_page']
        );
        $quoteStats = [
            ['label' => 'Total de Cotizaciones', 'value' => 0],
            ['label' => 'Cotizaciones pendientes', 'value' => 0],
            ['label' => 'Cotizaciones con pago', 'value' => 0],
        ];

        if ($activeTab === 'cotizaciones') {
            $this->syncApprovedSpReceivables();
            $cotizacionesQuery = DB::table('cotizacion as c')
                ->leftJoin('cliente as cli', 'cli.idcliente', '=', 'c.cliente_idcliente')
                ->leftJoin('vigenciaoferta as v', 'v.idvigenciaOferta', '=', 'c.vigenciaOferta_idvigenciaOferta')
                ->leftJoin('formapago as fp', 'fp.idformaPago', '=', 'c.formaPago_idformaPago')
                ->leftJoinSub($this->quotationServiceTypeQuery(), 'cotizacion_tipo', 'cotizacion_tipo.cotizacion_nroCotizacion', '=', 'c.nroCotizacion')
                ->leftJoin('moneda as m', function ($join) {
                    $join->on('m.idmoneda', '=', 'c.moneda_idmoneda')->where('m.idmoneda', '!=', 4);
                })
                ->select([
                    'c.nroCotizacion',
                    'c.batch_id',
                    'c.cliente_idcliente',
                    'c.moneda_idmoneda',
                    'c.formaPago_idformaPago',
                    DB::raw('COALESCE(cli.razonSocial, cli.nombreComercial, cli.idcliente) as cliente_nombre'),
                    'cli.flag_integrador as flag_integrador',
                    'c.total',
                    'c.estado',
                    'c.fechaHoraEmision',
                    'c.archivoPago',
                    DB::raw("COALESCE(cli.detraccion, '0') as cliente_detraccion"),
                    'v.detalle as vigencia_detalle',
                    'fp.detalle as forma_pago_detalle',
                    'fp.tiempo as forma_pago_tiempo',
                    'm.detalle as moneda_detalle',
                    DB::raw("COALESCE(cotizacion_tipo.servicio, 'EQUIPAMIENTO') as servicio"),
                ]);

            $quoteStatsQuery = clone $cotizacionesQuery;
            $this->applyQuotationFilters($quoteStatsQuery, $request, 'all');
            $this->applyQuotationFilters($cotizacionesQuery, $request, 'all');

            $quoteStatsRow = (clone $quoteStatsQuery)
                ->select([])
                ->selectRaw('COUNT(*) as total, SUM(CASE WHEN c.estado = ? THEN 1 ELSE 0 END) as pendientes, SUM(CASE WHEN c.estado = ? THEN 1 ELSE 0 END) as con_pago', ['1', '2'])
                ->first();
            $groupCountsQuery = clone $cotizacionesQuery;

            $this->orderQuotationsByStatus($cotizacionesQuery);
            $cotizacionesPendientes = $cotizacionesQuery
                ->orderByDesc('c.fechaHoraEmision')
                ->orderBy('c.nroCotizacion')
                ->paginate($this->resolvePerPage($request), ['*'], 'quotes_page')
                ->withQueryString();

            $pageBatchIds = $cotizacionesPendientes->getCollection()
                ->pluck('batch_id')
                ->map(fn($batchId): string => trim((string) $batchId))
                ->filter()
                ->unique()
                ->values();
            $groupCounts = $pageBatchIds->isEmpty()
                ? []
                : $groupCountsQuery
                    ->whereNotNull('c.batch_id')
                    ->where('c.batch_id', '<>', '')
                    ->whereIn(DB::raw('TRIM(c.batch_id)'), $pageBatchIds)
                    ->select([])
                    ->selectRaw('TRIM(c.batch_id) as batch_id, COUNT(*) as total')
                    ->groupByRaw('TRIM(c.batch_id)')
                    ->pluck('total', 'batch_id')
                    ->all();

            $cotizacionesPendientes->transform(function ($row) use ($groupCounts): mixed {
                $row->monto_display = $this->formatCurrencyValue(
                    (float) ($row->total ?? 0),
                    $this->currencySymbol($row->moneda_detalle ?? null)
                );
                $batchIdClean = trim((string) ($row->batch_id ?? ''));
                $isGroup = $batchIdClean !== '';
                $row->es_grupo = $isGroup;
                $row->grupo_count = $isGroup ? ($groupCounts[$batchIdClean] ?? 1) : 1;
                $row->grupo_display = $batchIdClean !== '' ? $batchIdClean : 'Sin grupo';
                $row->fecha_emision_display = $row->fechaHoraEmision
                    ? Carbon::parse($row->fechaHoraEmision)->format('d/m/Y')
                    : '-';
                $row->vigencia_display = trim((string) ($row->vigencia_detalle ?? '')) !== '' ? trim((string) $row->vigencia_detalle) : '-';

                $formaPagoDetalle = trim((string) ($row->forma_pago_detalle ?? ''));
                $formaPagoTiempo = (int) ($row->forma_pago_tiempo ?? 0);
                if ($formaPagoTiempo > 0 && !str_contains(mb_strtolower($formaPagoDetalle, 'UTF-8'), 'contado')) {
                    $formaPagoDetalle .= ' (' . $formaPagoTiempo . ' días)';
                }
                $row->forma_pago_display = $formaPagoDetalle !== '' ? $formaPagoDetalle : '-';
                $row->tiene_archivo = !empty($row->archivoPago);
                $row->archivo_pago_url = $row->tiene_archivo ? asset('storage/' . $row->archivoPago) : null;
                $row->is_integrador = in_array(
                    strtolower(str_replace('í', 'i', trim((string) ($row->flag_integrador ?? '')))),
                    ['si', '1', 'true', 'on', 'yes', 'y'],
                    true
                );

                return $row;
            });

            $quoteStats = [
                ['label' => 'Total de Cotizaciones', 'value' => (int) ($quoteStatsRow->total ?? 0)],
                ['label' => 'Cotizaciones pendientes', 'value' => (int) ($quoteStatsRow->pendientes ?? 0)],
                ['label' => 'Cotizaciones con pago', 'value' => (int) ($quoteStatsRow->con_pago ?? 0)],
            ];

            $quoteIds = $cotizacionesPendientes->getCollection()
                ->pluck('nroCotizacion')
                ->map(fn($quoteId): string => (string) $quoteId)
                ->all();
            $quoteCxcRows = $quoteIds === []
                ? collect()
                : DB::table('cuentasporcobrar')
                    ->where(function ($query) use ($quoteIds): void {
                        $query->whereIn('descripcion', array_map(
                            static fn(string $quoteId): string => 'COTIZACION:' . $quoteId,
                            $quoteIds
                        ));
                        foreach ($quoteIds as $quoteId) {
                            $query->orWhere('descripcion', 'like', '%' . $quoteId);
                        }
                    })
                    ->orderByDesc('idcuentasPorCobrar')
                    ->leftJoin('moneda as cxc_moneda', 'cxc_moneda.idmoneda', '=', 'cuentasporcobrar.moneda_idmoneda')
                    ->get([
                        'cuentasporcobrar.idcuentasPorCobrar',
                        'cuentasporcobrar.descripcion',
                        'cuentasporcobrar.montoActual',
                        'cuentasporcobrar.montoOriginal as cxc_monto_total',
                        'cuentasporcobrar.moneda_idmoneda as cxc_moneda_id',
                        'cuentasporcobrar.estado',
                        'cuentasporcobrar.fechaRegistro as cxc_fecha_aprobacion',
                        'cuentasporcobrar.docReferencia',
                        'cxc_moneda.simbolo as cxc_moneda_simbolo',
                    ]);
            $quoteCxcById = [];
            $fallbackQuoteCxcById = [];
            foreach ($quoteCxcRows as $cxc) {
                $description = (string) $cxc->descripcion;
                $isExactMarker = str_starts_with($description, 'COTIZACION:');
                $quoteId = $isExactMarker
                    ? substr($description, strlen('COTIZACION:'))
                    : trim((string) substr($description, strrpos($description, ' ') ?: 0));
                if (!in_array($quoteId, $quoteIds, true)) {
                    continue;
                }
                if ($isExactMarker && !isset($quoteCxcById[$quoteId])) {
                    $quoteCxcById[$quoteId] = $cxc;
                } elseif (!$isExactMarker && !isset($fallbackQuoteCxcById[$quoteId])) {
                    $fallbackQuoteCxcById[$quoteId] = $cxc;
                }
            }
            $quoteCxcById += $fallbackQuoteCxcById;
            $cotizacionesPendientes->transform(function ($row) use ($quoteCxcById): mixed {
                $cxc = $quoteCxcById[(string) $row->nroCotizacion] ?? null;
                $row->cxc_id = $cxc->idcuentasPorCobrar ?? null;
                $row->cxc_estado = $cxc->estado ?? null;
                $approvalDate = $cxc->cxc_fecha_aprobacion ?? null;
                $row->can_annul_approved = false;
                if ((string) ($row->estado ?? '') === CotizacionService::STATE_APROBADO && $approvalDate) {
                    try {
                        $row->can_annul_approved = !Carbon::now()->startOfDay()->gt(
                            Carbon::parse($approvalDate)->startOfDay()->addDays(7)
                        );
                    } catch (\Throwable) {
                        $row->can_annul_approved = false;
                    }
                }
                $row->cxc_monto_pendiente = $cxc ? (float) $cxc->montoActual : null;
                $row->cxc_monto_total = $cxc ? (float) $cxc->cxc_monto_total : null;
                $row->cxc_moneda_id = $cxc->cxc_moneda_id ?? $row->moneda_idmoneda;
                $row->cxc_moneda_simbolo = $cxc->cxc_moneda_simbolo ?? '';
                $row->cxc_doc_referencia = $cxc->docReferencia ?? '';
                $quotationService = mb_strtoupper(trim((string) ($row->servicio ?? 'EQUIPAMIENTO')), 'UTF-8');
                $isEquipmentRetention = $quotationService === 'EQUIPAMIENTO'
                    && (string) ($row->cliente_detraccion ?? '') === '1';
                $row->withholding_applies = (float) ($cxc->cxc_monto_total ?? $row->total ?? 0) >= 700
                    && (
                        $isEquipmentRetention
                        || in_array($quotationService, ['SERVICIOS TÉCNICOS', 'PLANES'], true)
                    );
                $row->withholding_type = $isEquipmentRetention ? 'retención' : 'detracción';
                if ($cxc) {
                    $row->monto_display = $this->formatCurrencyValue(
                        (float) $cxc->cxc_monto_total,
                        $this->currencySymbol($cxc->cxc_moneda_simbolo ?: ($row->moneda_detalle ?? null))
                    );
                }

                return $row;
            });

            $quoteCxcIds = $cotizacionesPendientes->getCollection()
                ->pluck('cxc_id')
                ->filter()
                ->map(fn($cxcId): int => (int) $cxcId)
                ->all();
            $quotePaymentMovements = $quoteCxcIds === []
                ? collect()
                : DB::table('detallecxc as dc')
                    ->join('bancos as b', 'b.idbancos', '=', 'dc.bancos_idbancos')
                    ->leftJoin('moneda as payment_currency', 'payment_currency.idmoneda', '=', 'dc.moneda_idmoneda')
                    ->whereIn('dc.cuentasPorCobrar_idcuentasPorCobrar', $quoteCxcIds)
                    ->where('dc.estado', '1')
                    ->whereIn('b.tipoMov', ['I', 'T'])
                    ->orderByDesc('dc.iddetalleCXC')
                    ->get([
                        'dc.cuentasPorCobrar_idcuentasPorCobrar as cxc_id',
                        'dc.descripcion',
                        'dc.fechaPago',
                        'b.idbancos',
                        'b.informacion',
                        'b.tipoMov',
                        'b.monto',
                        'dc.moneda_idmoneda as payment_currency_id',
                        'payment_currency.simbolo as payment_currency_symbol',
                    ])
                    ->unique('idbancos')
                    ->values();
            $movementIds = $quotePaymentMovements->pluck('idbancos')->map(fn($id): int => (int) $id)->all();
            $movementEvidence = $movementIds === []
                ? collect()
                : DB::table('evidenciapago')
                    ->whereIn('bancos_idbancos', $movementIds)
                    ->orderByDesc('idevidenciaPago')
                    ->get(['bancos_idbancos', 'archivo'])
                    ->unique('bancos_idbancos')
                    ->keyBy('bancos_idbancos');
            $quotePaymentMovementsByCxc = $quotePaymentMovements->groupBy('cxc_id');
            $cotizacionesPendientes->transform(function ($row) use ($quotePaymentMovementsByCxc, $movementEvidence): mixed {
                $movements = $quotePaymentMovementsByCxc->get((int) ($row->cxc_id ?? 0), collect());
                $paymentMovements = $movements->where('tipoMov', 'I');
                $withholdingMovements = $movements->where('tipoMov', 'T')
                    ->filter(fn($movement): bool => in_array((string) $movement->descripcion, ['Detracción aplicada', 'Retención aplicada'], true));
                $paymentMovement = $paymentMovements->first();
                $withholdingMovement = $withholdingMovements->first();
                $paymentComment = trim((string) ($paymentMovement->informacion ?? ''));
                if (
                    $paymentMovement
                    && str_starts_with(trim((string) $paymentMovement->descripcion), 'Pago ')
                    && preg_match('/\((.*)\)$/u', $paymentComment, $commentMatch) === 1
                ) {
                    $paymentComment = trim($commentMatch[1]);
                } elseif (preg_match('/^Pago de CXC #\d+$/u', $paymentComment) === 1) {
                    $paymentComment = '';
                }
                $paymentEvidence = $paymentMovement
                    ? $movementEvidence->get((int) $paymentMovement->idbancos)
                    : null;
                $withholdingEvidence = $withholdingMovement
                    ? $movementEvidence->get((int) $withholdingMovement->idbancos)
                    : null;
                $row->payment_receipt_url = !empty($row->archivoPago)
                    ? asset('storage/' . $row->archivoPago)
                    : (!empty($paymentEvidence->archivo) ? asset('storage/' . $paymentEvidence->archivo) : null);
                $row->withholding_proof_url = !empty($withholdingEvidence->archivo)
                    ? asset('storage/' . $withholdingEvidence->archivo)
                    : null;
                $row->withholding_proof_type = $row->withholding_type;
                $row->quote_payment_date = $paymentMovement?->fechaPago
                    ? Carbon::parse($paymentMovement->fechaPago)->format('d/m/Y')
                    : '';
                $row->quote_payment_comment = $paymentComment;
                $row->quote_payment_amount = $paymentMovements->sum(fn($movement): float => (float) $movement->monto);
                $row->quote_withholding_date = $withholdingMovement?->fechaPago
                    ? Carbon::parse($withholdingMovement->fechaPago)->format('d/m/Y')
                    : '';
                $row->quote_withholding_amount = $withholdingMovements->sum(fn($movement): float => (float) $movement->monto);
                $currencySymbol = $this->currencySymbol($row->cxc_moneda_simbolo ?: ($row->moneda_detalle ?? null));
                $paymentAmountsByCurrency = $paymentMovements
                    ->groupBy(fn($movement): string => (string) ($movement->payment_currency_id ?? $row->cxc_moneda_id))
                    ->map(function ($currencyMovements) use ($currencySymbol): string {
                        $paymentSymbol = $this->currencySymbol(
                            $currencyMovements->first()->payment_currency_symbol ?? null
                        ) ?: $currencySymbol;

                        return $this->formatCurrencyValue(
                            (float) $currencyMovements->sum(fn($movement): float => (float) $movement->monto),
                            $paymentSymbol
                        );
                    })
                    ->values();
                $row->quote_payment_amount_display = $paymentAmountsByCurrency->implode(' + ');
                $row->quote_withholding_amount_display = $withholdingMovements->isNotEmpty()
                    ? $this->formatCurrencyValue((float) $row->quote_withholding_amount, $currencySymbol)
                    : '';
                $row->withholding_applies = $withholdingMovements->isNotEmpty()
                    || in_array((string) ($row->cxc_estado ?? ''), ['Pendiente Autodetracción', 'Pendiente a Detracción', 'Pendiente a Retención'], true);

                return $row;
            });
            $paidQuoteInstallments = !empty($quoteCxcIds)
                ? DB::table('detallecxc as dc')
                    ->leftJoin('bancos as b', 'b.idbancos', '=', 'dc.bancos_idbancos')
                    ->whereIn('dc.cuentasPorCobrar_idcuentasPorCobrar', $quoteCxcIds)
                    ->where('dc.estado', '1')
                    ->whereNotNull('dc.nCuota')
                    ->orderBy('dc.nCuota')
                    ->get([
                        'dc.cuentasPorCobrar_idcuentasPorCobrar as cxc_id',
                        'dc.nCuota as numero',
                        'b.monto as monto_pagado',
                    ])
                    ->groupBy('cxc_id')
                    ->map(fn($installments) => $installments
                        ->groupBy('numero')
                        ->map(fn($payments, $number): array => [
                            'numero' => (int) $number,
                            'monto' => round((float) $payments->sum('monto_pagado'), 2),
                        ])
                        ->values()
                        ->all())
                : collect();
            $cotizacionesPendientes->transform(function ($row) use ($paidQuoteInstallments): mixed {
                $row->cxc_cuotas_pagadas = $paidQuoteInstallments->get((int) ($row->cxc_id ?? 0), []);

                return $row;
            });
            $nextQuoteInstallments = !empty($quoteCxcIds)
                ? DB::table('detallecxc')
                    ->whereIn('cuentasPorCobrar_idcuentasPorCobrar', $quoteCxcIds)
                    ->where('estado', '0')
                    ->whereNotNull('nCuota')
                    ->orderBy('nCuota')
                    ->get([
                        'cuentasPorCobrar_idcuentasPorCobrar as cxc_id',
                        'nCuota as numero',
                        'formaPago_idformaPago',
                        'descripcion',
                        'fechaPagoProgramada as fecha_pago',
                    ])
                    ->unique('cxc_id')
                    ->keyBy('cxc_id')
                : collect();
            $cotizacionesPendientes->transform(function ($row) use ($nextQuoteInstallments): mixed {
                $installment = $nextQuoteInstallments->get((int) ($row->cxc_id ?? 0));
                $row->cxc_proxima_cuota_numero = $installment->numero ?? null;
                $row->cxc_proxima_cuota_forma_pago_id = $installment->formaPago_idformaPago ?? null;
                $row->cxc_proxima_cuota_monto = null;
                if (
                    isset($installment->descripcion)
                    && preg_match('/Monto:\s*([0-9]+(?:\.[0-9]{1,2})?)/', (string) $installment->descripcion, $match) === 1
                ) {
                    $row->cxc_proxima_cuota_monto = (float) $match[1];
                }
                $row->cxc_proxima_cuota_fecha = $installment->fecha_pago ?? null;

                return $row;
            });
        }

        $serviceFilters = $this->serviceFiltersFromRequest($request);

        $servicePerPage = $this->resolvePerPage($request);
        $serviceCurrentPage = LengthAwarePaginator::resolveCurrentPage('services_page');
        $serviciosPorVencer = collect();
        $serviciosPorVencerGrouped = new LengthAwarePaginator(
            [],
            0,
            $servicePerPage,
            $serviceCurrentPage,
            ['path' => $request->url(), 'query' => $request->query(), 'pageName' => 'services_page']
        );
        $serviceStats = [
            ['label' => 'Total de grupos', 'value' => 0],
            ['label' => 'Servicios pendientes', 'value' => 0],
            ['label' => 'Servicios vencidos', 'value' => 0],
            ['label' => 'Servicios cancelados', 'value' => 0],
        ];
        $serviceDataLoaded = $activeTab !== 'servicios' || $request->ajax();

        if ($activeTab === 'servicios' && $serviceDataLoaded) {
            $serviceIndexData = $this->cuentasPorCobrarService->prepareServicesIndex($serviceFilters);
            $serviciosPorVencer = $serviceIndexData['serviciosPorVencer'];
            $serviciosPorVencerGrouped = $serviceIndexData['serviciosPorVencerGrouped'];

            $serviciosPorVencerGrouped = $this->filterServiceGroups($serviciosPorVencerGrouped, $serviceFilters);
            $serviceGroups = collect($serviciosPorVencerGrouped);
            $isCancelled = fn(array $group): bool =>
                mb_strtolower(trim((string) ($group['estado_pago'] ?? ''))) === 'cancelado';
            $serviceStats = [
                ['label' => 'Total de grupos', 'value' => $serviceGroups->count()],
                ['label' => 'Servicios pendientes', 'value' => $serviceGroups->filter(
                    fn(array $group): bool => !$isCancelled($group) && ($group['estado_servicio'] ?? '') === 'activo'
                )->count()],
                ['label' => 'Servicios vencidos', 'value' => $serviceGroups->filter(
                    fn(array $group): bool => !$isCancelled($group) && ($group['estado_servicio'] ?? '') === 'vencido'
                )->count()],
                ['label' => 'Servicios cancelados', 'value' => $serviceGroups->filter($isCancelled)->count()],
            ];

            $serviciosPorVencerGrouped = new LengthAwarePaginator(
                array_slice($serviciosPorVencerGrouped, ($serviceCurrentPage - 1) * $servicePerPage, $servicePerPage),
                count($serviciosPorVencerGrouped),
                $servicePerPage,
                $serviceCurrentPage,
                [
                    'path' => $request->url(),
                    'query' => $request->query(),
                    'pageName' => 'services_page',
                ]
            );
        }

        return view('cuentasporcobrar.cuentasporcobrar', [
            'title' => 'Módulo Cuentas por Cobrar',
            'singularTitle' => 'Cuenta por Cobrar',
            'activeTab' => $activeTab,
            'items' => new LengthAwarePaginator(
                [],
                0,
                $perPage,
                1,
                ['path' => $request->url(), 'query' => $request->query()]
            ),
            'cotizacionesPendientes' => $cotizacionesPendientes,
            'quoteStats' => $quoteStats,
            'serviceStats' => $serviceStats,
            'serviceDataLoaded' => $serviceDataLoaded,
            'columns' => [],
            'stats' => [],
            'filters' => [],
            'formaPagoOptions' => $this->formaPagoOptions(),
            'entidadBancariaOptions' => $entidadBancariaOptions,
            'defaultBankId' => $defaultBank['value'] ?? '',
            'monedasOptions' => $this->monedasOptions(),
            'quoteValidityOptions' => $quoteValidityOptions,
            'quoteFilters' => [
                'quote_client' => $quoteClient,
                'quote_number' => $quoteNumber,
                'quote_payment_format' => $quotePaymentFormat,
                'quote_validity' => $quoteValidity,
                'quote_service' => $quoteService,
                'quote_date' => $quoteDate,
                'quote_status' => $quoteStatus,
            ],
            'serviceFilters' => $serviceFilters,
            'serviciosPorVencer' => $serviciosPorVencer,
            'serviciosPorVencerGrouped' => $serviciosPorVencerGrouped,
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

    private function syncApprovedSpReceivables(): void
    {
        DB::table('cotizacion')
            ->where('estado', '1')
            ->orderBy('nroCotizacion')
            ->select(['nroCotizacion'])
            ->chunk(250, function ($quotes): void {
                $quoteIds = $quotes->pluck('nroCotizacion')
                    ->map(fn($quoteId): string => (string) $quoteId)
                    ->all();
                $markers = array_map(static fn(string $quoteId): string => 'COTIZACION:' . $quoteId, $quoteIds);
                $existingMarkers = DB::table('cuentasporcobrar')
                    ->whereIn('descripcion', $markers)
                    ->pluck('descripcion')
                    ->flip();

                foreach ($quoteIds as $quoteId) {
                    if (!$existingMarkers->has('COTIZACION:' . $quoteId)) {
                        $this->cxcService->ensureQuotationReceivable($quoteId);
                    }
                }
            });
    }

    protected function serviceGroupPriority(array $group): int
    {
        $status = strtolower((string) ($group['estado_servicio'] ?? 'activo'));

        return $status === 'activo' ? 0 : 1;
    }

    private function serviceFiltersFromRequest(Request $request): array
    {
        $filters = [
            'service_client' => trim((string) $request->query('service_client', '')),
            'service_month' => trim((string) $request->query('service_month', strtolower(Carbon::now()->locale('es')->monthName))),
            'service_year' => trim((string) $request->query('service_year', (string) now()->year)),
            'service_document' => trim((string) $request->query('service_document', '')),
            'service_date' => trim((string) $request->query('service_date', '')),
            'service_expiration_date' => trim((string) $request->query('service_expiration_date', '')),
            'service_status' => trim((string) $request->query('service_status', '')),
        ];
        $filters['service_months'] = array_values(array_filter(
            array_map(static fn(string $month): string => mb_strtolower(trim($month), 'UTF-8'), explode(',', $filters['service_month'])),
            static fn(string $month): bool => $month !== '' && $month !== 'all'
        ));
        if ($filters['service_year'] === 'all') {
            $filters['service_year'] = '';
        }

        return $filters;
    }

    private function filterServiceGroups(array $groups, array $filters): array
    {
        usort($groups, function (array $a, array $b): int {
            $rank = [
                'facturado' => 0,
                'pendiente pago parcial' => 1,
                'pendiente a credito' => 2,
                'pagocredito' => 3,
                'pendiente cuota crédito' => 3,
                'pendiente autodetracción' => 4,
                'pendiente autoretención' => 5,
                'pendiente a detracción' => 6,
                'pendiente a retención' => 7,
                'pendiente' => 8,
                'vencido' => 9,
                'cancelado' => 10,
            ];
            $rankA = $rank[strtolower((string) ($a['estado_pago'] ?? ''))] ?? 9;
            $rankB = $rank[strtolower((string) ($b['estado_pago'] ?? ''))] ?? 9;
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            return strcmp(
                strtolower((string) ($a['cliente_nombre'] ?? '')),
                strtolower((string) ($b['cliente_nombre'] ?? ''))
            );
        });

        $clientFilter = mb_strtolower($filters['service_client'], 'UTF-8');
        $documentFilter = mb_strtolower($filters['service_document'], 'UTF-8');

        return array_values(array_filter($groups, function (array $group) use ($filters, $clientFilter, $documentFilter): bool {
            $clientId = mb_strtolower((string) ($group['cliente_id'] ?? ''), 'UTF-8');
            $clientName = mb_strtolower((string) ($group['cliente_nombre'] ?? ''), 'UTF-8');
            $month = mb_strtolower((string) ($group['mes'] ?? ''), 'UTF-8');
            $document = mb_strtolower((string) ($group['documento'] ?? ''), 'UTF-8');
            $status = mb_strtolower((string) ($group['estado_pago'] ?? ''), 'UTF-8');

            if ($clientFilter !== '' && !str_contains($clientId, $clientFilter) && !str_contains($clientName, $clientFilter)) {
                return false;
            }
            if ($filters['service_months'] !== [] && !in_array($month, $filters['service_months'], true)) {
                return false;
            }
            if ($filters['service_year'] !== '' && (string) ($group['anio'] ?? '') !== $filters['service_year']) {
                return false;
            }
            if ($documentFilter !== '' && !str_contains($document, $documentFilter)) {
                return false;
            }
            if ($filters['service_date'] !== '' && ($group['fecha_registro_iso'] ?? '') !== $filters['service_date']) {
                return false;
            }
            if ($filters['service_expiration_date'] !== '' && ($group['fecha_fin_iso'] ?? '') !== $filters['service_expiration_date']) {
                return false;
            }
            if ($filters['service_status'] === 'vencido') {
                if ($status !== 'vencido') {
                    return false;
                }
            } elseif ($filters['service_status'] !== '' && $status !== $filters['service_status']) {
                return false;
            }

            return true;
        }));
    }

    public function exportQuotations(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $query = DB::table('cotizacion as c')
            ->leftJoin('cliente as cli', 'cli.idcliente', '=', 'c.cliente_idcliente')
            ->leftJoin('vigenciaoferta as v', 'v.idvigenciaOferta', '=', 'c.vigenciaOferta_idvigenciaOferta')
            ->leftJoin('formapago as fp', 'fp.idformaPago', '=', 'c.formaPago_idformaPago')
            ->leftJoinSub($this->quotationServiceTypeQuery(), 'cotizacion_tipo', 'cotizacion_tipo.cotizacion_nroCotizacion', '=', 'c.nroCotizacion')
            ->leftJoin('moneda as m', 'm.idmoneda', '=', 'c.moneda_idmoneda')
            ->select([
                'c.nroCotizacion',
                'c.cliente_idcliente',
                'c.batch_id',
                'cli.razonSocial',
                'cli.nombreComercial',
                'cli.detraccion as cliente_detraccion',
                DB::raw('COALESCE(cli.razonSocial, cli.nombreComercial, cli.idcliente) as cliente_nombre'),
                'c.total',
                'c.fechaHoraEmision',
                'c.estado as cotizacion_estado',
                'v.detalle as vigencia_detalle',
                'fp.detalle as forma_pago_detalle',
                'fp.tiempo as forma_pago_tiempo',
                'm.detalle as moneda_detalle',
                'm.simbolo as moneda_simbolo',
                DB::raw("COALESCE(cotizacion_tipo.servicio, 'EQUIPAMIENTO') as servicio"),
            ]);

        if (Schema::hasColumn('cotizacion', 'docReferencia')) {
            $query->addSelect('c.docReferencia as cotizacion_doc_referencia');
        }

        $selectedIds = (array) ($request->input('selectedIds') ?? $request->query('selectedIds', []));
        if (!empty($selectedIds)) {
            $query->whereIn('c.nroCotizacion', $selectedIds);
        }

        $this->applyQuotationFilters($query, $request);
        $rawRows = $query->orderByDesc('c.fechaHoraEmision')->get();

        $rows = $this->prepareQuotationsExportData($rawRows);
        $filename = 'cotizaciones_cuentas_por_cobrar_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportCustomQuotationsXlsxResponse($rows, $filename);
        }

        $columns = [
            ['key' => 'cliente', 'label' => 'CLIENTE'],
            ['key' => 'ruc', 'label' => 'RUC'],
            ['key' => 'fecha_emision', 'label' => 'FECHA EMISION'],
            ['key' => 'nro_factura', 'label' => 'N° FACTURA'],
            ['key' => 'total', 'label' => 'TOTAL'],
            ['key' => 'pago_realizado', 'label' => 'PAGO REALIZADO'],
            ['key' => 'observacion', 'label' => 'OBSERVACION'],
            ['key' => 'detraccion', 'label' => 'DETRACCION'],
            ['key' => 'fecha_abono_detraccion', 'label' => 'FECHA ABONO'],
            ['key' => 'autodetraccion', 'label' => 'AUTODETRACCION'],
            ['key' => 'fecha_abono_autodetraccion', 'label' => 'FECHA ABONO'],
        ];

        return $this->exportPdfResponse(collect($rows), $columns, 'Cotizaciones por cobrar', $filename);
    }

    protected function prepareQuotationsExportData($rawRows): array
    {
        if ($rawRows->isEmpty()) {
            return [];
        }

        $quoteIds = $rawRows->pluck('nroCotizacion')
            ->map(fn($id): string => (string) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $cxcList = DB::table('cuentasporcobrar')
            ->where(function ($query) use ($quoteIds): void {
                $query->whereIn('descripcion', array_map(
                    static fn(string $id): string => 'COTIZACION:' . $id,
                    $quoteIds
                ));
                foreach ($quoteIds as $id) {
                    $query->orWhere('descripcion', 'like', '%' . $id);
                }
            })
            ->orderByDesc('idcuentasPorCobrar')
            ->get([
                'idcuentasPorCobrar',
                'descripcion',
                'docReferencia',
                'docFactura',
                'montoOriginal',
                'montoActual',
                'estado',
                'fechaRegistro',
                'fechaCancelacion',
            ]);

        $cxcByQuote = [];
        foreach ($cxcList as $cxc) {
            $quoteId = str_starts_with((string) $cxc->descripcion, 'COTIZACION:')
                ? substr((string) $cxc->descripcion, strlen('COTIZACION:'))
                : trim((string) substr((string) $cxc->descripcion, strrpos((string) $cxc->descripcion, ' ') ?: 0));
            if (in_array($quoteId, $quoteIds, true) && !isset($cxcByQuote[$quoteId])) {
                $cxcByQuote[$quoteId] = $cxc;
            }
        }

        $cxcIds = array_values(array_unique(array_filter(array_map(
            static fn($c): int => (int) $c->idcuentasPorCobrar,
            $cxcByQuote
        ))));

        $detailsByCxc = [];
        if (!empty($cxcIds)) {
            $details = DB::table('detallecxc as dc')
                ->leftJoin('bancos as b', 'b.idbancos', '=', 'dc.bancos_idbancos')
                ->leftJoin('moneda as payment_currency', 'payment_currency.idmoneda', '=', 'dc.moneda_idmoneda')
                ->whereIn('dc.cuentasPorCobrar_idcuentasPorCobrar', $cxcIds)
                ->orderBy('dc.iddetalleCXC')
                ->get([
                    'dc.iddetalleCXC',
                    'dc.cuentasPorCobrar_idcuentasPorCobrar as cxc_id',
                    'dc.moneda_idmoneda as payment_currency_id',
                    'dc.descripcion as detalle_descripcion',
                    'dc.fechaPago as detalle_fecha_pago',
                    'dc.fechaPagoProgramada as detalle_fecha_programada',
                    'dc.estado as detalle_estado',
                    'dc.nCuota',
                    'b.idbancos',
                    'b.monto as banco_monto',
                    'b.tipoMov as banco_tipo_mov',
                    'b.fechaRegistro as banco_fecha_registro',
                    'b.informacion as banco_informacion',
                    'payment_currency.simbolo as payment_currency_symbol',
                ]);

            foreach ($details as $d) {
                $detailsByCxc[(int) $d->cxc_id][] = $d;
            }
        }

        $exportData = [];
        foreach ($rawRows as $quote) {
            $quoteNumber = (string) $quote->nroCotizacion;
            $cxc = $cxcByQuote[$quoteNumber] ?? null;
            $cxcId = $cxc ? (int) $cxc->idcuentasPorCobrar : null;
            $items = $cxcId ? ($detailsByCxc[$cxcId] ?? []) : [];

            $cliente = trim((string) ($quote->razonSocial ?: ($quote->nombreComercial ?: $quote->cliente_nombre)));
            $ruc = trim((string) ($quote->cliente_idcliente ?? ''));

            $fechaEmision = $quote->fechaHoraEmision
                ? Carbon::parse($quote->fechaHoraEmision)->format('j/n/Y')
                : '-';

            $numFactura = trim((string) (
                $cxc->docReferencia
                ?? $cxc->docFactura
                ?? ($quote->cotizacion_doc_referencia ?? '')
            ));

            $total = round((float) ($quote->total ?? 0), 2);
            $simbolo = $this->currencySymbol($quote->moneda_simbolo ?: ($quote->moneda_detalle ?? null));
            $totalFormatted = $simbolo . ' ' . number_format($total, 2, '.', ',');

            // Pagos del cliente (tipoMov = 'I', 'C' o registros pagados que no sean detracción/retención)
            $pagosCliente = array_filter(
                $items,
                static fn($d): bool => (string) $d->detalle_estado === '1'
                    && in_array((string) ($d->banco_tipo_mov ?? ''), ['I', 'C', ''], true)
                    && !str_contains(mb_strtolower((string) $d->detalle_descripcion, 'UTF-8'), 'detracción')
                    && !str_contains(mb_strtolower((string) $d->detalle_descripcion, 'UTF-8'), 'retención')
            );

            $pagoRealizado = array_sum(array_map(
                static fn($d): float => (float) ($d->banco_monto ?? 0),
                $pagosCliente
            ));
            $paymentAmountUsesBaseCurrency = false;

            // Si la cuenta por cobrar está en 'Pendiente a Detracción'/'Pendiente a Retención':
            // El cliente pagó con descuento y la deuda actual es la detracción pendiente (ej. 840 - 101 = 739)
            if ($cxc && in_array((string) $cxc->estado, ['Pendiente a Detracción', 'Pendiente a Retención', 'Pendiente Autodetracción'], true)) {
                $deudaDetraccion = (float) ($cxc->montoActual ?? 0);
                $montoBase = (float) ($cxc->montoOriginal ?? $total);
                if (
                    $deudaDetraccion > 0
                    && $montoBase > $deudaDetraccion
                    && $pagoRealizado < $montoBase - 0.05
                ) {
                    $pagoRealizado = round($montoBase - $deudaDetraccion, 2);
                    $paymentAmountUsesBaseCurrency = true;
                }
            }

            // Si no se encontró monto en banco pero en detallecxc hay una descripción con monto de pago
            if ($pagoRealizado <= 0) {
                foreach ($items as $d) {
                    if (preg_match('/(?:Se\s*pago|pago|monto:?)\s*([0-9]+(?:\.[0-9]+)?)/iu', (string) $d->detalle_descripcion, $m)) {
                        $pagoRealizado = (float) $m[1];
                        $paymentAmountUsesBaseCurrency = false;
                        break;
                    }
                }
            }

            if ($pagoRealizado <= 0 && (string) ($quote->cotizacion_estado ?? '') === '2') {
                $pagoRealizado = $total;
                $paymentAmountUsesBaseCurrency = true;
            }
            $paymentCurrencySymbol = $paymentAmountUsesBaseCurrency || $pagosCliente === []
                ? $simbolo
                : $this->currencySymbol(reset($pagosCliente)->payment_currency_symbol ?: null);

            // Recopilar la observación directamente de detallecxc.descripcion
            $observaciones = [];
            foreach ($items as $item) {
                $desc = trim((string) ($item->detalle_descripcion ?? ''));
                if ($desc === '') {
                    $desc = trim((string) ($item->banco_informacion ?? ''));
                }

                if ($desc === '') {
                    continue;
                }

                if (str_contains($desc, '|')) {
                    $parts = explode('|', $desc);
                    $desc = trim(end($parts));
                }

                $descLower = mb_strtolower($desc, 'UTF-8');

                // Ignorar descripciones generadas automáticamente por el sistema
                if (
                    str_starts_with($descLower, 'pago de cotización')
                    || str_starts_with($descLower, 'cuota programada')
                    || str_starts_with($descLower, 'detracción pendiente')
                    || str_starts_with($descLower, 'retención pendiente')
                    || str_starts_with($descLower, 'detracción pagada de')
                    || str_starts_with($descLower, 'retención pagada de')
                    || $descLower === 'detracción aplicada'
                    || $descLower === 'retención aplicada'
                    || $descLower === 'aplicación saldo a favor'
                ) {
                    continue;
                }

                if (!in_array($desc, $observaciones, true)) {
                    $observaciones[] = $desc;
                }
            }

            $observacion = implode(' - ', $observaciones);

            $isAutodetraccionPending = $cxc && (string) $cxc->estado === 'Pendiente Autodetracción';
            $hasCxcDeductionState = $cxc && in_array((string) $cxc->estado, ['Pendiente a Detracción', 'Pendiente a Retención', 'Pendiente Autodetracción'], true);
            $isRetentionTable = false;
            $appliesWithholding = $hasCxcDeductionState;
            if ($hasCxcDeductionState && (string) $cxc->estado === 'Pendiente a Retención') {
                $isRetentionTable = true;
            }
            $withholdingRate = $isRetentionTable ? 0.03 : 0.12;

            // Verificar si en cxc o bancos ya existe un monto registrado para la deducción
            $registeredDeduction = 0.0;
            if ($hasCxcDeductionState) {
                $deudaDeduccion = (float) ($cxc->montoActual ?? 0);
                if ($deudaDeduccion > 0) {
                    $registeredDeduction = $deudaDeduccion;
                }
            }

            // Detalle de pago de detracción / retención en detallecxc (banco movimiento 'T')
            $withholdingPaidDetail = null;
            foreach ($items as $d) {
                $descLower = mb_strtolower((string) $d->detalle_descripcion, 'UTF-8');
                if (
                    (str_contains($descLower, 'detracción') || str_contains($descLower, 'retención') || (string) ($d->banco_tipo_mov ?? '') === 'T')
                    && (string) $d->detalle_estado === '1'
                    && (string) ($d->banco_tipo_mov ?? '') === 'T'
                ) {
                    $withholdingPaidDetail = $d;
                    if ((float) ($d->banco_monto ?? 0) > 0) {
                        $registeredDeduction = (float) $d->banco_monto;
                    }
                    break;
                }
            }

            if ($withholdingPaidDetail) {
                $appliesWithholding = true;
                $isRetentionTable = str_contains(
                    mb_strtolower((string) $withholdingPaidDetail->detalle_descripcion, 'UTF-8'),
                    'retención'
                );
            }

            $withholdingAmount = $registeredDeduction > 0
                ? round($registeredDeduction, 2)
                : round($total * $withholdingRate, $isRetentionTable ? 2 : 0);

            $withholdingPaidFecha = '';
            if ($withholdingPaidDetail) {
                $rawDate = $withholdingPaidDetail->detalle_fecha_pago ?: $withholdingPaidDetail->banco_fecha_registro;
                if ($rawDate) {
                    try {
                        $withholdingPaidFecha = Carbon::parse($rawDate)->format('j/n/Y');
                    } catch (\Throwable) {
                        $withholdingPaidFecha = '';
                    }
                }
            } elseif ($cxc && (string) $cxc->estado === 'CANCELADO' && (string) ($quote->cotizacion_estado ?? '') === '2') {
                $firstClientPayment = !empty($pagosCliente) ? reset($pagosCliente) : null;
                $rawDate = $cxc->fechaCancelacion
                    ?: ($firstClientPayment ? ($firstClientPayment->banco_fecha_registro ?: $firstClientPayment->detalle_fecha_pago) : null);
                if (!$rawDate) {
                    $rawDate = $cxc->fechaRegistro ?: null;
                }
                if ($rawDate) {
                    try {
                        $withholdingPaidFecha = Carbon::parse($rawDate)->format('j/n/Y');
                    } catch (\Throwable) {
                        $withholdingPaidFecha = '';
                    }
                }
            }

            $isAuto = ($pagoRealizado >= $total - 0.05);

            $detraccionMonto = '';
            $detraccionFecha = '';
            $autodetraccionMonto = '';
            $autodetraccionFecha = '';
            $retencionMonto = '';
            $retencionFecha = '';
            $autoretencionMonto = '';
            $autoretencionFecha = '';

            if ($appliesWithholding) {
                $formattedWithholding = $simbolo . ' ' . number_format($withholdingAmount, 2, '.', ',');

                if ($isRetentionTable) {
                    $retencionMonto = $formattedWithholding;
                    $retencionFecha = $withholdingPaidFecha;
                } else {
                    if ($isAuto || $isAutodetraccionPending) {
                        $autodetraccionMonto = $formattedWithholding;
                        if ($withholdingPaidDetail) {
                            $rawDate = $withholdingPaidDetail->detalle_fecha_pago ?: $withholdingPaidDetail->banco_fecha_registro;
                            if ($rawDate) {
                                try {
                                    $autodetraccionFecha = Carbon::parse($rawDate)->format('j/n/Y');
                                } catch (\Throwable) {
                                    $autodetraccionFecha = '';
                                }
                            }
                        }
                    } else {
                        $detraccionMonto = $formattedWithholding;
                        $detraccionFecha = $withholdingPaidFecha;
                    }
                }
            }

            $pagoRealizadoFormatted = $pagoRealizado > 0
                ? ($simbolo . ' ' . number_format($pagoRealizado, 2, '.', ','))
                : '';

            $exportData[] = [
                'cliente' => $cliente,
                'ruc' => $ruc,
                'fecha_emision' => $fechaEmision,
                'nro_factura' => $numFactura,
                'total' => $totalFormatted,
                'total_numeric' => $total,
                'total_currency_symbol' => $simbolo,
                'pago_realizado' => $pagoRealizadoFormatted,
                'pago_realizado_numeric' => $pagoRealizado > 0 ? $pagoRealizado : null,
                'pago_currency_symbol' => $paymentCurrencySymbol,
                'observacion' => $observacion,
                'detraccion' => $detraccionMonto,
                'detraccion_numeric' => $detraccionMonto !== '' ? $withholdingAmount : null,
                'fecha_abono_detraccion' => $detraccionFecha,
                'autodetraccion' => $autodetraccionMonto,
                'autodetraccion_numeric' => $autodetraccionMonto !== '' ? $withholdingAmount : null,
                'fecha_abono_autodetraccion' => $autodetraccionFecha,
                'retencion' => $retencionMonto,
                'retencion_numeric' => $retencionMonto !== '' ? $withholdingAmount : null,
                'deduction_currency_symbol' => $simbolo,
                'fecha_abono_retencion' => $retencionFecha,
                'autoretencion' => $autoretencionMonto,
                'fecha_abono_autoretencion' => $autoretencionFecha,
                'is_retention' => $isRetentionTable,
            ];
        }

        return $exportData;
    }

    protected function exportCustomQuotationsXlsxResponse(array $rows, string $filename)
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cotizaciones CXC');

        // Separar en detracciones y normales vs retenciones
        if (isset($rows['detracciones']) || isset($rows['retenciones'])) {
            $detraccionRows = $rows['detracciones'] ?? [];
            $retencionRows = $rows['retenciones'] ?? [];
        } else {
            $detraccionRows = [];
            $retencionRows = [];
            foreach ($rows as $r) {
                if (!empty($r['is_retention'])) {
                    $retencionRows[] = $r;
                } else {
                    $detraccionRows[] = $r;
                }
            }
        }

        $detraccionHeaders = [
            'A' => ['title' => 'CLIENTE', 'align' => Alignment::HORIZONTAL_LEFT, 'width' => 38],
            'B' => ['title' => 'RUC', 'align' => Alignment::HORIZONTAL_CENTER, 'width' => 16],
            'C' => ['title' => 'FECHA EMISION', 'align' => Alignment::HORIZONTAL_CENTER, 'width' => 16],
            'D' => ['title' => 'N° FACTURA', 'align' => Alignment::HORIZONTAL_CENTER, 'width' => 16],
            'E' => ['title' => 'TOTAL', 'align' => Alignment::HORIZONTAL_RIGHT, 'width' => 16],
            'F' => ['title' => 'PAGO REALIZADO', 'align' => Alignment::HORIZONTAL_RIGHT, 'width' => 18],
            'G' => ['title' => 'OBSERVACION', 'align' => Alignment::HORIZONTAL_LEFT, 'width' => 32],
            'H' => ['title' => 'DETRACCION', 'align' => Alignment::HORIZONTAL_RIGHT, 'width' => 22],
            'I' => ['title' => 'FECHA ABONO', 'align' => Alignment::HORIZONTAL_CENTER, 'width' => 16],
            'J' => ['title' => 'AUTODETRACCION', 'align' => Alignment::HORIZONTAL_RIGHT, 'width' => 22],
            'K' => ['title' => 'FECHA ABONO', 'align' => Alignment::HORIZONTAL_CENTER, 'width' => 16],
        ];

        $retencionHeaders = [
            'A' => ['title' => 'CLIENTE', 'align' => Alignment::HORIZONTAL_LEFT, 'width' => 38],
            'B' => ['title' => 'RUC', 'align' => Alignment::HORIZONTAL_CENTER, 'width' => 16],
            'C' => ['title' => 'FECHA EMISION', 'align' => Alignment::HORIZONTAL_CENTER, 'width' => 16],
            'D' => ['title' => 'N° FACTURA', 'align' => Alignment::HORIZONTAL_CENTER, 'width' => 16],
            'E' => ['title' => 'TOTAL', 'align' => Alignment::HORIZONTAL_RIGHT, 'width' => 16],
            'F' => ['title' => 'PAGO REALIZADO', 'align' => Alignment::HORIZONTAL_RIGHT, 'width' => 18],
            'G' => ['title' => 'OBSERVACION', 'align' => Alignment::HORIZONTAL_LEFT, 'width' => 32],
            'H' => ['title' => 'RETENCION', 'align' => Alignment::HORIZONTAL_RIGHT, 'width' => 22],
            'I' => ['title' => 'FECHA ABONO', 'align' => Alignment::HORIZONTAL_CENTER, 'width' => 16],
        ];

        $hasDetraccion = !empty($detraccionRows);
        $hasRetencion = !empty($retencionRows);

        if (!$hasDetraccion && !$hasRetencion) {
            $hasDetraccion = true;
        }

        $writeTable = function (array $headers, array $tableRows, int $startRow, bool $isRetencionTable) use ($sheet): int {
            $headerRow = $startRow;
            $lastColumn = array_key_last($headers);
            $sheet->getRowDimension($headerRow)->setRowHeight(28);

            foreach ($headers as $col => $meta) {
                $sheet->setCellValueExplicit($col . $headerRow, $meta['title'], DataType::TYPE_STRING);
                $sheet->getColumnDimension($col)->setWidth($meta['width']);
            }

            $sheet->getStyle('A' . $headerRow . ':' . $lastColumn . $headerRow)->applyFromArray([
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => '000000'],
                    'size' => 11,
                    'name' => 'Calibri',
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '42C2F5'],
                ],
            ]);

            $rowIdx = $headerRow + 1;
            foreach ($tableRows as $row) {
                $sheet->getRowDimension($rowIdx)->setRowHeight(22);

                $sheet->setCellValueExplicit('A' . $rowIdx, (string) ($row['cliente'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('B' . $rowIdx, (string) ($row['ruc'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('C' . $rowIdx, (string) ($row['fecha_emision'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('D' . $rowIdx, (string) ($row['nro_factura'] ?? ''), DataType::TYPE_STRING);
                $this->writeQuotationCurrencyCell(
                    $sheet,
                    'E' . $rowIdx,
                    $row['total_numeric'] ?? null,
                    (string) ($row['total_currency_symbol'] ?? 'S/')
                );
                $this->writeQuotationCurrencyCell(
                    $sheet,
                    'F' . $rowIdx,
                    $row['pago_realizado_numeric'] ?? null,
                    (string) ($row['pago_currency_symbol'] ?? $row['total_currency_symbol'] ?? 'S/')
                );
                $sheet->setCellValueExplicit('G' . $rowIdx, (string) ($row['observacion'] ?? ''), DataType::TYPE_STRING);

                if ($isRetencionTable) {
                    $this->writeQuotationCurrencyCell(
                        $sheet,
                        'H' . $rowIdx,
                        $row['retencion_numeric'] ?? $row['detraccion_numeric'] ?? null,
                        (string) ($row['deduction_currency_symbol'] ?? $row['total_currency_symbol'] ?? 'S/')
                    );
                    $sheet->setCellValueExplicit('I' . $rowIdx, (string) ($row['fecha_abono_retencion'] ?: ($row['fecha_abono_detraccion'] ?? '')), DataType::TYPE_STRING);
                } else {
                    $this->writeQuotationCurrencyCell(
                        $sheet,
                        'H' . $rowIdx,
                        $row['detraccion_numeric'] ?? null,
                        (string) ($row['deduction_currency_symbol'] ?? $row['total_currency_symbol'] ?? 'S/')
                    );
                    $sheet->setCellValueExplicit('I' . $rowIdx, (string) ($row['fecha_abono_detraccion'] ?? ''), DataType::TYPE_STRING);
                    $this->writeQuotationCurrencyCell(
                        $sheet,
                        'J' . $rowIdx,
                        $row['autodetraccion_numeric'] ?? null,
                        (string) ($row['deduction_currency_symbol'] ?? $row['total_currency_symbol'] ?? 'S/')
                    );
                    $sheet->setCellValueExplicit('K' . $rowIdx, (string) ($row['fecha_abono_autodetraccion'] ?? ''), DataType::TYPE_STRING);
                }

                foreach ($headers as $col => $meta) {
                    $sheet->getStyle($col . $rowIdx)->applyFromArray([
                        'font' => [
                            'size' => 11,
                            'name' => 'Calibri',
                        ],
                        'alignment' => [
                            'horizontal' => $meta['align'],
                            'vertical' => Alignment::VERTICAL_CENTER,
                            'wrapText' => $col === 'G',
                        ],
                    ]);
                }

                $rowIdx++;
            }

            $lastRow = max($headerRow, $rowIdx - 1);
            $sheet->getStyle('A' . $headerRow . ':' . $lastColumn . $lastRow)->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => '000000'],
                    ],
                ],
            ]);
            if (isset($headers['J'], $headers['K'])) {
                $sheet->getStyle('J' . $headerRow . ':K' . $lastRow)->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'A8E08C'],
                    ],
                ]);
            }

            return $lastRow;
        };

        $currentRow = 1;

        if ($hasDetraccion) {
            $lastRow = $writeTable($detraccionHeaders, $detraccionRows, $currentRow, false);
            $currentRow = $lastRow + 1;
        }

        if ($hasRetencion) {
            if ($hasDetraccion) {
                // Dejar 2 filas vacías de separación exactamente como en la imagen
                $currentRow += 2;
            }
            $lastRow = $writeTable($retencionHeaders, $retencionRows, $currentRow, true);
            $currentRow = $lastRow + 1;
        }

        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'export_cxc_cotiz_');
        $writer->save($tempFile);

        return response()->download($tempFile, $filename)->deleteFileAfterSend(true);
    }

    private function writeQuotationCurrencyCell(
        Worksheet $sheet,
        string $cell,
        float|int|null $amount,
        string $currencySymbol
    ): void {
        if ($amount === null) {
            $sheet->setCellValueExplicit($cell, '', DataType::TYPE_STRING);

            return;
        }

        $sheet->setCellValueExplicit($cell, (float) $amount, DataType::TYPE_NUMERIC);
        $currencyFormat = '"' . str_replace('"', '""', trim($currencySymbol)) . '" #,##0.00';
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($currencyFormat);
    }

    private function quotationServiceTypeQuery()
    {
        return DB::table('detallecotizacion as dc')
            ->join('almacen as a', 'a.idalmacen', '=', 'dc.almacen_idalmacen')
            ->leftJoin('tipoelemento as te', 'te.idtipoElemento', '=', 'a.tipoElemento_idtipoElemento')
            ->select('dc.cotizacion_nroCotizacion')
            ->selectRaw("
                CASE
                    WHEN MAX(CASE WHEN UPPER(COALESCE(te.nombre, '')) LIKE '%SERVIC%' THEN 1 ELSE 0 END) = 1
                        THEN 'SERVICIOS TÉCNICOS'
                    WHEN MAX(CASE WHEN UPPER(COALESCE(te.nombre, '')) LIKE '%PLAN%' THEN 1 ELSE 0 END) = 1
                        THEN 'PLANES'
                    ELSE 'EQUIPAMIENTO'
                END as servicio
            ")
            ->groupBy('dc.cotizacion_nroCotizacion');
    }

    public function approveQuotation(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $respond = static function (bool $success, string $message, int $status = 422) use ($request): RedirectResponse|JsonResponse {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => $success,
                    'message' => $message,
                ], $success ? 200 : $status);
            }

            $redirect = redirect()->route('modules.cuentasporcobrar', ['tab' => 'cotizaciones']);

            return $success
                ? $redirect->with('success', $message)
                : $redirect->withInput()->with('error', $message);
        };

        if ($request->boolean('completar_deduccion_pendiente')) {
            $request->validate([
                'archivoPago' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
                'descripcion' => ['nullable', 'string', 'max:100'],
                'fecha_pago' => ['required', 'date'],
            ]);

            try {
                $completion = DB::transaction(function () use ($id, $request): array {
                    $quote = DB::table('cotizacion')
                        ->where('nroCotizacion', $id)
                        ->lockForUpdate()
                        ->first(['nroCotizacion', 'estado']);
                    if (!$quote || (string) $quote->estado !== '1') {
                        abort(422, 'La cotización ya no tiene una detracción/retención pendiente.');
                    }

                    $cxc = DB::table('cuentasporcobrar')
                        ->where('descripcion', 'COTIZACION:' . $id)
                        ->lockForUpdate()
                        ->first(['idcuentasPorCobrar', 'estado']);
                    if (!$cxc) {
                        abort(404, 'No se encontró la cuenta por cobrar de esta cotización.');
                    }
                    $normalizedCxcState = mb_strtolower((string) $cxc->estado, 'UTF-8');
                    $withholdingType = str_contains($normalizedCxcState, 'autodetracción')
                        ? 'autodetracción'
                        : (str_contains($normalizedCxcState, 'retención') ? 'retención' : 'detracción');

                    $this->cxcService->registrarDetraccionPendiente(
                        (int) $cxc->idcuentasPorCobrar,
                        $request->file('archivoPago'),
                        null,
                        trim((string) $request->input('descripcion', '')),
                        Carbon::parse($request->input('fecha_pago'))->format('Y-m-d')
                    );
                    DB::table('cotizacion')->where('nroCotizacion', $id)->update(['estado' => '2']);

                    return [
                        'cxc_id' => (int) $cxc->idcuentasPorCobrar,
                        'withholding_type' => $withholdingType,
                    ];
                });
            } catch (\Throwable $exception) {
                return $respond(false, $exception->getMessage());
            }

            $this->publishQuotationPaymentEventsAfterResponse($id, $completion['cxc_id'], '2');

            $withholdingArticle = $completion['withholding_type'] === 'autodetracción' ? 'la ' : '';

            return $respond(
                true,
                'Depósito de ' . $withholdingArticle . $completion['withholding_type'] . ' registrado y cotización aprobada.'
            );
        }

        $validated = $request->validate([
            'archivoPago' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'fecha_pago' => ['required', 'date'],
            'descripcion' => ['nullable', 'string', 'max:100'],
            'docReferencia' => ['required', 'string', 'max:15', 'regex:' . self::SAFE_TEXT_REGEX],
            'formaPago_idformaPago' => ['required', 'integer', 'exists:formapago,idformaPago'],
            'entidadBancaria_identidadBancaria' => ['required', 'integer', 'exists:entidadbancaria,identidadBancaria'],
            'modo_pago' => ['required', 'in:total,parcial'],
            'montoPago' => ['required_if:modo_pago,parcial', 'nullable', 'numeric', 'min:0'],
            'aplicar_retencion' => ['sometimes', 'boolean'],
            'usar_saldo_favor' => ['sometimes', 'boolean'],
            'monto_saldo_favor' => ['nullable', 'numeric', 'min:0'],
            'canCuotas' => ['nullable', 'integer', 'min:1', 'max:60'],
            'cuotas_montos' => ['nullable', 'array'],
            'cuotas_montos.*' => ['required', 'numeric', 'gt:0'],
            'cuotas_fechas' => ['nullable', 'array'],
            'cuotas_fechas.*' => ['required', 'date'],
            'moneda_idmoneda' => ['required', 'integer', 'exists:moneda,idmoneda'],
            'currency_selector' => ['nullable', 'integer', 'exists:moneda,idmoneda'],
            'payment_currency_mode' => ['required', 'in:same,other'],
            'monto_cancelado' => ['nullable', 'numeric', 'gt:0'],
            'tipo_cambio' => ['nullable', 'numeric', 'gt:0'],
            'withholding_decision' => ['nullable', 'in:paid,pending,full_pending'],
            'nota_credito_ids' => ['nullable', 'array', 'max:1'],
            'nota_credito_ids.*' => ['required', 'integer', 'distinct', 'exists:nota_credito,idnotacredito'],
            'comprobante_detraccion' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'fecha_detraccion' => ['nullable', 'date'],
            'descripcion_detraccion' => ['nullable', 'string', 'max:100'],
        ]);

        $quote = DB::table('cotizacion')
            ->where('nroCotizacion', $id)
            ->first(['nroCotizacion', 'estado', 'cliente_idcliente', 'moneda_idmoneda', 'formaPago_idformaPago', 'total']);
        if (!$quote) {
            abort(404, 'No se encontró la cotización solicitada.');
        }
        $paymentMethod = DB::table('formapago')
            ->where('idformaPago', $validated['formaPago_idformaPago'])
            ->first(['detalle']);
        $paymentMethodName = Str::ascii(mb_strtolower((string) ($paymentMethod->detalle ?? ''), 'UTF-8'));
        $isCredit = $paymentMethod && str_contains($paymentMethodName, 'credito');
        if (
            $validated['payment_currency_mode'] === 'other'
            && (empty($validated['currency_selector']) || empty($validated['tipo_cambio']) || empty($validated['monto_cancelado']))
        ) {
            return $respond(false, 'Completa moneda, tipo de cambio y monto para registrar el pago en otra moneda.');
        }

        $file = $request->file('archivoPago');
        $storedPath = null;
        $result = null;
        try {
            if ($file) {
                $storedPath = $file->store('comprobantes-pago/cxc', 'public');
                if (!$storedPath) {
                    throw new \RuntimeException('No se pudo guardar el comprobante de pago.');
                }
            }

            $result = DB::transaction(function () use ($id, $validated, $storedPath, $isCredit): array {
                $quote = DB::table('cotizacion')
                    ->where('nroCotizacion', $id)
                    ->lockForUpdate()
                    ->first(['nroCotizacion', 'estado', 'cliente_idcliente', 'moneda_idmoneda', 'formaPago_idformaPago', 'total']);

                if (!$quote) {
                    abort(404, 'No se encontró la cotización solicitada.');
                }
                if ((string) $quote->estado !== '1') {
                    abort(422, 'La cotización ya no está en estado Aprobado(SP).');
                }
                $marker = 'COTIZACION:' . $id;
                $cxc = DB::table('cuentasporcobrar')
                    ->where('descripcion', $marker)
                    ->orderByDesc('idcuentasPorCobrar')
                    ->lockForUpdate()
                    ->first();
                if (!$cxc) {
                    $cxc = DB::table('cuentasporcobrar')
                        ->where('cliente_idcliente', $quote->cliente_idcliente)
                        ->where('moneda_idmoneda', $quote->moneda_idmoneda)
                        ->where('descripcion', 'like', '%' . $id)
                        ->orderByDesc('idcuentasPorCobrar')
                        ->lockForUpdate()
                        ->first();
                }

                $cxcState = mb_strtolower(trim((string) ($cxc->estado ?? '')), 'UTF-8');
                $nextInstallment = $cxc
                    ? DB::table('detallecxc')
                        ->where('cuentasPorCobrar_idcuentasPorCobrar', $cxc->idcuentasPorCobrar)
                        ->where('estado', '0')
                        ->whereNotNull('nCuota')
                        ->orderBy('nCuota')
                        ->lockForUpdate()
                        ->first([
                            'nCuota as numero',
                            'descripcion',
                            'fechaPagoProgramada as fecha_pago',
                            'formaPago_idformaPago',
                        ])
                    : null;
                $scheduledPaymentId = (int) ($nextInstallment->formaPago_idformaPago ?? 0);
                $hasPendingCreditInstallment = false;
                if ($scheduledPaymentId > 0) {
                    $scheduledPayment = DB::table('formapago')
                        ->where('idformaPago', $scheduledPaymentId)
                        ->first(['detalle']);
                    $scheduledPaymentName = Str::ascii(mb_strtolower((string) ($scheduledPayment->detalle ?? ''), 'UTF-8'));
                    $hasPendingCreditInstallment = str_contains($scheduledPaymentName, 'credito');
                    if ($hasPendingCreditInstallment) {
                        $validated['formaPago_idformaPago'] = $scheduledPaymentId;
                        $isCredit = true;
                    }
                }
                $isCreditContinuation = $isCredit && $cxc
                    && (in_array((string) $cxc->estado, ['PagoCredito', 'Pendiente cuota crédito', 'Pendiente a credito'], true)
                        || str_starts_with($cxcState, 'pendiente a credito')
                        || $hasPendingCreditInstallment);
                $isPartialPaymentContinuation = $cxc
                    && mb_strtolower(trim((string) $cxc->estado), 'UTF-8') === 'pendiente pago parcial';
                if ($cxc && (string) $cxc->estado !== 'NUEVO' && !$isCreditContinuation && !$isPartialPaymentContinuation) {
                    abort(422, 'La cuenta por cobrar ya no está pendiente de pago.');
                }
                $isInitialCredit = $isCredit && (!$cxc || (string) $cxc->estado === 'NUEVO');
                $isInitialPayment = !$cxc || (string) $cxc->estado === 'NUEVO';
                $sourceCurrencyId = (int) ($cxc->moneda_idmoneda ?? $quote->moneda_idmoneda);
                $targetCurrencyId = $sourceCurrencyId;
                $paymentCurrencyId = $sourceCurrencyId;
                $sourceBalance = round((float) ($cxc->montoActual ?? $quote->total), 2);
                $targetBalance = $sourceBalance;
                $changesDebtCurrency = $validated['payment_currency_mode'] === 'other';
                if ($changesDebtCurrency) {
                    $selectedCurrencyId = (int) ($validated['currency_selector'] ?? 0);
                    if ($selectedCurrencyId === $sourceCurrencyId) {
                        abort(422, 'Selecciona una moneda distinta a la moneda de la cuenta por cobrar.');
                    }
                    $paymentCurrencyId = $selectedCurrencyId;
                }
                $quotationService = DB::query()
                    ->fromSub($this->quotationServiceTypeQuery(), 'quotation_type')
                    ->where('cotizacion_nroCotizacion', $id)
                    ->value('servicio') ?? 'EQUIPAMIENTO';
                $quotationServiceUpper = mb_strtoupper((string) $quotationService, 'UTF-8');
                $isEquipamiento = $quotationServiceUpper === 'EQUIPAMIENTO';
                $canApplyQuotationWithholding = !$isCreditContinuation && !$isPartialPaymentContinuation;
                $quotationUsesRetention = $canApplyQuotationWithholding
                    && $isEquipamiento
                    && $targetBalance >= 700
                    && filter_var($validated['aplicar_retencion'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $quotationDeductionApplies = $canApplyQuotationWithholding
                    && $targetBalance >= 700
                    && ($isEquipamiento
                        ? $quotationUsesRetention
                        : !$isInitialCredit);
                $withholdingPreview = self::quotationWithholdingBreakdown(
                    $targetBalance,
                    $quotationDeductionApplies,
                    $quotationUsesRetention
                );
                $selectedCreditNoteAmount = 0.0;
                $selectedCreditNoteIds = array_map('intval', $validated['nota_credito_ids'] ?? []);
                if ($selectedCreditNoteIds !== []) {
                    $availableCreditNotes = collect($this->cxcService->notasCreditoDisponibles(
                        (string) $quote->cliente_idcliente,
                        $targetCurrencyId
                    ))->keyBy('idnotacredito');
                    $selectedCreditNote = $availableCreditNotes->get($selectedCreditNoteIds[0]);
                    if (!$selectedCreditNote) {
                        abort(422, 'La nota de crédito debe estar emitida, tener saldo y corresponder al cliente y moneda de la cotización.');
                    }
                    $selectedCreditNoteAmount = (float) $selectedCreditNote['monto_disponible'];
                }
                $selectedCreditNoteApplied = round(min(
                    $withholdingPreview['net'],
                    $selectedCreditNoteAmount
                ), 2);
                $selectedBankCreditApplied = 0.0;
                if (filter_var($validated['usar_saldo_favor'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $availableBankCredit = $this->cxcService->saldoFavorDisponible(
                        (string) $quote->cliente_idcliente,
                        $targetCurrencyId
                    );
                    $requestedBankCredit = isset($validated['monto_saldo_favor'])
                        ? round(max(0, (float) $validated['monto_saldo_favor']), 2)
                        : $availableBankCredit;
                    $selectedBankCreditApplied = round(min(
                        max(0, $withholdingPreview['net'] - $selectedCreditNoteApplied),
                        $availableBankCredit,
                        $requestedBankCredit
                    ), 2);
                }
                $installmentTotal = round(max(
                    0,
                    $withholdingPreview['net'] - $selectedCreditNoteApplied - $selectedBankCreditApplied
                ), 2);
                $hasInitialCreditPlan = $isInitialCredit && $installmentTotal > 0;
                if ($hasInitialCreditPlan && empty($validated['canCuotas'])) {
                    abort(422, 'Indica la cantidad de cuotas para registrar el pago a crédito.');
                }
                if ($hasInitialCreditPlan && count($validated['cuotas_montos'] ?? []) !== (int) $validated['canCuotas']) {
                    abort(422, 'Completa el monto de cada cuota del pago a crédito.');
                }
                $installmentNumber = null;
                $installmentDate = null;
                if ($hasInitialCreditPlan) {
                    $installmentAmounts = array_map(
                        static fn($amount): int => (int) round((float) $amount * 100),
                        $validated['cuotas_montos'] ?? []
                    );
                    $installmentDates = $validated['cuotas_fechas'] ?? [];
                    $quoteTotalCents = (int) round($installmentTotal * 100);
                    if (
                        count($installmentDates) !== (int) $validated['canCuotas']
                        || array_sum($installmentAmounts) !== $quoteTotalCents
                        || in_array(0, $installmentAmounts, true)
                    ) {
                        abort(422, 'Las cuotas deben tener monto y fecha, ser mayores a cero y sumar el total pendiente después de la detracción/retención.');
                    }
                    $installmentNumber = 1;
                    $installmentDate = Carbon::parse($installmentDates[0])->format('Y-m-d');
                    $expectedFirstAmount = $installmentAmounts[0] / 100;
                    $receivedFirstAmount = $changesDebtCurrency
                        ? CxcService::convertPaymentAmountToDebtCurrency(
                            (float) $validated['monto_cancelado'],
                            (float) $validated['tipo_cambio'],
                            $sourceCurrencyId,
                            $paymentCurrencyId
                        )
                        : round((float) ($validated['montoPago'] ?? 0), 2);
                    if (abs($receivedFirstAmount - $expectedFirstAmount) > 0.005) {
                        abort(422, 'El pago inicial debe coincidir con el monto de la primera cuota.');
                    }
                } elseif ($isCreditContinuation) {
                    if ($nextInstallment) {
                        $installmentNumber = (int) $nextInstallment->numero;
                        $installmentDate = (string) $nextInstallment->fecha_pago;
                        if (preg_match('/Monto:\s*([0-9]+(?:\.[0-9]{1,2})?)/', (string) $nextInstallment->descripcion, $match) === 1) {
                            $expectedAmount = round(max(
                                0,
                                (float) $match[1] - $selectedCreditNoteApplied
                            ), 2);
                            $receivedAmount = $changesDebtCurrency
                                ? CxcService::convertPaymentAmountToDebtCurrency(
                                    (float) $validated['monto_cancelado'],
                                    (float) $validated['tipo_cambio'],
                                    $sourceCurrencyId,
                                    $paymentCurrencyId
                                )
                                : round((float) ($validated['montoPago'] ?? 0), 2);
                            if (abs($receivedAmount - $expectedAmount) > 0.005) {
                                abort(422, 'El pago debe coincidir con el monto de la siguiente cuota pendiente.');
                            }
                        }
                    }
                }

                $submittedAmount = $changesDebtCurrency
                    ? CxcService::convertPaymentAmountToDebtCurrency(
                        (float) ($validated['monto_cancelado'] ?? 0),
                        (float) $validated['tipo_cambio'],
                        $sourceCurrencyId,
                        $paymentCurrencyId
                    )
                    : round((float) ($validated['montoPago'] ?? $installmentTotal), 2);
                $withholdingDecision = null;
                if ($isInitialPayment && $withholdingPreview['deduction'] > 0 && !$isCreditContinuation) {
                    $cashForFullPendingWithholding = round(
                        $targetBalance - $selectedCreditNoteApplied - $selectedBankCreditApplied,
                        2
                    );
                    $cashAfterWithholding = round(
                        $withholdingPreview['net'] - $selectedCreditNoteApplied - $selectedBankCreditApplied,
                        2
                    );
                    if (!$hasInitialCreditPlan && $submittedAmount + 0.01 < $cashAfterWithholding) {
                        $validated['modo_pago'] = 'parcial';
                        $withholdingDecision = 'partial_pending';
                    } elseif (($validated['modo_pago'] ?? 'total') === 'parcial') {
                        if ($submittedAmount + 0.01 >= $cashAfterWithholding) {
                            abort(422, 'El pago cubre el saldo neto de la cotización; regístralo como pago total y confirma la retención.');
                        }
                        $withholdingDecision = $validated['withholding_decision'] ?? null;
                    } elseif ($hasInitialCreditPlan) {
                        if (abs($submittedAmount - $cashForFullPendingWithholding) <= 0.01) {
                            $withholdingDecision = 'full_pending';
                        } elseif (abs($submittedAmount - $cashAfterWithholding) <= 0.01) {
                            $withholdingDecision = $validated['withholding_decision'] ?? null;
                            if (!in_array($withholdingDecision, ['paid', 'pending'], true)) {
                                abort(422, 'Confirma si el cliente ya pagó la retención antes de guardar.');
                            }
                        } else {
                            $withholdingDecision = 'paid';
                        }
                    } else {
                        $withholdingDecision = $validated['withholding_decision'] ?? null;
                        if ($withholdingDecision === null && abs($submittedAmount - $cashForFullPendingWithholding) <= 0.01) {
                            $withholdingDecision = 'full_pending';
                        } elseif ($withholdingDecision === 'full_pending') {
                            if (abs($submittedAmount - $cashForFullPendingWithholding) > 0.01) {
                                abort(422, 'El pago bruto completo debe coincidir con el total pendiente de la cotización.');
                            }
                        } elseif (!in_array($withholdingDecision, ['paid', 'pending'], true)) {
                            abort(422, 'Confirma si el cliente ya pagó la detracción/retención antes de guardar.');
                        } elseif (
                            $withholdingDecision === 'pending'
                            && abs($submittedAmount - $cashAfterWithholding) > 0.01
                            && abs($submittedAmount - $cashForFullPendingWithholding) > 0.01
                        ) {
                            abort(422, 'Si la retención aún no fue pagada, el monto debe ser el neto sugerido o el total bruto de la cotización.');
                        } elseif ($submittedAmount + 0.01 < $cashAfterWithholding) {
                            abort(422, 'El monto pagado debe cubrir el neto calculado para confirmar la retención.');
                        }
                    }
                }
                $requiresWithholdingProof = $isInitialPayment
                    && $withholdingPreview['deduction'] > 0
                    && $submittedAmount + 0.01 >= $cashAfterWithholding
                    && $withholdingDecision === 'paid';
                if ($requiresWithholdingProof && empty($validated['comprobante_detraccion'])) {
                    abort(422, 'Debe cargar el comprobante de detracción/retención para confirmar que ya fue pagada.');
                }

                if (!$cxc) {
                    $cxcData = [
                        'cliente_idcliente' => $quote->cliente_idcliente,
                        'tipoCobro_idtipoCobros' => 1,
                        'moneda_idmoneda' => $targetCurrencyId,
                        'docReferencia' => trim((string) ($validated['docReferencia'] ?? '')),
                        'descripcion' => $marker,
                        'montoOriginal' => $targetBalance,
                        'montoActual' => $targetBalance,
                        'canCuotas' => null,
                        'fechaRegistro' => now()->format('Y-m-d H:i:s'),
                        'fechaCancelacion' => null,
                        'estado' => 'NUEVO',
                    ];
                    if (DB::getSchemaBuilder()->hasColumn('cuentasporcobrar', 'docFactura')) {
                        $cxcData['docFactura'] = trim((string) ($validated['docReferencia'] ?? ''));
                    }
                    $cxcId = DB::table('cuentasporcobrar')->insertGetId($cxcData);
                } else {
                    $cxcId = (int) $cxc->idcuentasPorCobrar;
                }

                $invoiceReference = trim((string) $validated['docReferencia']);
                $cxcUpdate = ['docReferencia' => $invoiceReference];
                if (DB::getSchemaBuilder()->hasColumn('cuentasporcobrar', 'docFactura')) {
                    $cxcUpdate['docFactura'] = $invoiceReference;
                }
                DB::table('cuentasporcobrar')
                    ->where('idcuentasPorCobrar', $cxcId)
                    ->update($cxcUpdate);

                if (DB::getSchemaBuilder()->hasColumn('cotizacion', 'docReferencia')) {
                    DB::table('cotizacion')
                        ->where('nroCotizacion', $id)
                        ->update(['docReferencia' => $invoiceReference]);
                }

                $currentBalance = $targetBalance;
                if ($currentBalance <= 0) {
                    DB::table('cuentasporcobrar')
                        ->where('idcuentasPorCobrar', $cxcId)
                        ->update([
                            'montoActual' => (float) ($cxc->montoOriginal ?? $quote->total ?? 0),
                            'moneda_idmoneda' => $targetCurrencyId,
                            'estado' => 'CANCELADO',
                            'fechaCancelacion' => Carbon::parse($validated['fecha_pago'])->format('Y-m-d H:i:s'),
                        ]);

                    $quoteUpdate = ['estado' => '2'];
                    if ($storedPath !== null) {
                        $quoteUpdate['archivoPago'] = $storedPath;
                    }
                    if (DB::getSchemaBuilder()->hasColumn('cotizacion', 'fechaPago')) {
                        $quoteUpdate['fechaPago'] = $validated['fecha_pago'];
                    } elseif (DB::getSchemaBuilder()->hasColumn('cotizacion', 'fecha_pago')) {
                        $quoteUpdate['fecha_pago'] = $validated['fecha_pago'];
                    }
                    DB::table('cotizacion')->where('nroCotizacion', $id)->update($quoteUpdate);

                    return [
                        'cxc_id' => $cxcId,
                        'paid' => true,
                        'installment_number' => $installmentNumber,
                        'is_credit_continuation' => $isCreditContinuation,
                    ];
                }

                $descripcion = trim((string) ($validated['descripcion'] ?? ''));
                $paymentData = [
                    'entidadBancaria_identidadBancaria' => $validated['entidadBancaria_identidadBancaria'],
                    'formaPago_idformaPago' => $validated['formaPago_idformaPago'],
                    'tipoCobro_idtipoCobros' => 1,
                    'moneda_idmoneda' => $targetCurrencyId,
                    'currency_selector' => $paymentCurrencyId,
                    'payment_currency_mode' => $changesDebtCurrency ? 'other' : 'same',
                    'tipo_cambio' => $changesDebtCurrency ? $validated['tipo_cambio'] : 1,
                    'monto_cancelado' => $validated['monto_cancelado'] ?? null,
                    'monto_banco' => $changesDebtCurrency
                        ? ($validated['monto_cancelado'] ?? null)
                        : null,
                    'modo_pago' => $validated['modo_pago'],
                    'montoPago' => $changesDebtCurrency
                        ? $submittedAmount
                        : ($validated['montoPago'] ?? null),
                    'monto_recibido' => $changesDebtCurrency ? ($validated['monto_cancelado'] ?? null) : null,
                    'monto_convertido' => $changesDebtCurrency ? $submittedAmount : null,
                    'canCuotas' => $validated['canCuotas'] ?? null,
                    'cuotas_montos' => $validated['cuotas_montos'] ?? [],
                    'cuotas_fechas' => $validated['cuotas_fechas'] ?? [],
                    'fechaPago' => $validated['fecha_pago'],
                    'docReferencia' => trim((string) ($validated['docReferencia'] ?? '')),
                    'descripcion' => $descripcion !== '' ? $descripcion : 'Pago de cotización ' . $id,
                    'renewal_cxc_id' => $cxcId,
                    'aplicar_deduccion' => $isInitialPayment && $quotationDeductionApplies,
                    'is_retention_por_tipo_servicio' => $quotationUsesRetention,
                    'withholding_decision' => $withholdingDecision,
                    'usar_saldo_favor' => (bool) ($validated['usar_saldo_favor'] ?? false),
                    'monto_saldo_favor' => $validated['monto_saldo_favor'] ?? null,
                    'origen_pago' => 'cotizacion',
                    'nota_credito_ids' => $selectedCreditNoteIds,
                ];
                if ($requiresWithholdingProof) {
                    $paymentData['withholding_proof'] = $validated['comprobante_detraccion'];
                    $paymentData['withholding_fecha'] = $validated['fecha_detraccion'] ?? $validated['fecha_pago'];
                    $paymentData['withholding_description'] = $validated['descripcion_detraccion'] ?? '';
                }
                if ($installmentNumber !== null) {
                    $paymentData['cuota_cotizacion_numero'] = $installmentNumber;
                    $paymentData['cuota_cotizacion_fecha'] = $installmentDate;
                }
                $this->cxcService->procesarPagoGrupo([$cxcId], $paymentData, $storedPath);

                $updatedCxc = DB::table('cuentasporcobrar')
                    ->where('idcuentasPorCobrar', $cxcId)
                    ->lockForUpdate()
                    ->first(['montoActual', 'estado']);
                $isPaid = (float) ($updatedCxc->montoActual ?? 0) <= 0.01;
                $isWithholdingPending = in_array((string) ($updatedCxc->estado ?? ''), [
                    'Pendiente Autodetracción',
                    'Pendiente a Detracción',
                    'Pendiente a Retención',
                ], true);
                $paidCxcAmount = $targetBalance;
                $paidCurrencyId = $targetCurrencyId;
                $hasOtherCurrencyPayments = $changesDebtCurrency || DB::table('detallecxc')
                    ->where('cuentasPorCobrar_idcuentasPorCobrar', $cxcId)
                    ->where('estado', '1')
                    ->where('moneda_idmoneda', '<>', $targetCurrencyId)
                    ->exists();
                if ($isPaid && !$hasOtherCurrencyPayments) {
                    $paidCxcAmount = (float) DB::table('detallecxc as dc')
                        ->join('bancos as b', 'b.idbancos', '=', 'dc.bancos_idbancos')
                        ->where('dc.cuentasPorCobrar_idcuentasPorCobrar', $cxcId)
                        ->where('dc.moneda_idmoneda', $paidCurrencyId)
                        ->where('dc.estado', '1')
                        ->whereIn('b.tipoMov', ['I', 'T'])
                        ->where('b.informacion', 'not like', '[ANULADO]%')
                        ->sum('b.monto');
                    if (Schema::hasTable('nota_credito_aplicacion')) {
                        $paidCxcAmount += (float) DB::table('nota_credito_aplicacion')
                            ->where('cuentasPorCobrar_idcuentasPorCobrar', $cxcId)
                            ->where('estado', '1')
                            ->sum('monto');
                    }
                }
                DB::table('cuentasporcobrar')
                    ->where('idcuentasPorCobrar', $cxcId)
                    ->update([
                        'montoActual' => $isPaid
                            ? ($hasOtherCurrencyPayments ? $updatedCxc->montoActual : $paidCxcAmount)
                            : $updatedCxc->montoActual,
                        'moneda_idmoneda' => $paidCurrencyId,
                        'estado' => $isPaid ? 'CANCELADO' : $updatedCxc->estado,
                        'fechaCancelacion' => $isPaid ? Carbon::parse($validated['fecha_pago'])->format('Y-m-d H:i:s') : null,
                    ]);

                $quoteUpdate = [];
                if ($isPaid) {
                    $quoteUpdate['estado'] = '2';
                    if (DB::getSchemaBuilder()->hasColumn('cotizacion', 'fechaPago')) {
                        $quoteUpdate['fechaPago'] = $validated['fecha_pago'];
                    } elseif (DB::getSchemaBuilder()->hasColumn('cotizacion', 'fecha_pago')) {
                        $quoteUpdate['fecha_pago'] = $validated['fecha_pago'];
                    }
                }
                if ($storedPath !== null && $isInitialPayment) {
                    $quoteUpdate['archivoPago'] = $storedPath;
                }
                if ($quoteUpdate !== []) {
                    DB::table('cotizacion')->where('nroCotizacion', $id)->update($quoteUpdate);
                }

                return [
                    'cxc_id' => $cxcId,
                    'paid' => $isPaid,
                    'withholding_pending' => $isWithholdingPending,
                    'withholding_type' => $quotationUsesRetention ? 'retención' : 'detracción',
                    'installment_number' => $installmentNumber,
                    'is_credit_continuation' => $isCreditContinuation,
                ];
            });
        } catch (\Throwable $exception) {
            if ($storedPath !== null && Storage::disk('public')->exists($storedPath)) {
                Storage::disk('public')->delete($storedPath);
            }

            return $respond(false, $exception->getMessage());
        }

        $this->publishQuotationPaymentEventsAfterResponse(
            $id,
            $result['cxc_id'],
            $result['paid'] ? '2' : '1'
        );

        return $respond(true, $result['withholding_pending']
            ? 'Pago registrado. La ' . $result['withholding_type'] . ' quedó pendiente para registrar su depósito.'
            : (!empty($result['is_credit_continuation']) && !empty($result['installment_number'])
                ? 'Pago registrado correctamente. Se pagó la cuota ' . $result['installment_number'] . ' de la cotización ' . $id . '.'
                : ($result['paid']
                    ? 'Cotización aprobada correctamente.'
                    : 'Pago registrado.')));
    }

    private static function quotationWithholdingBreakdown(
        float $gross,
        bool $eligibleService,
        bool $isRetention
    ): array {
        $gross = round(max(0, $gross), 2);
        $deduction = $eligibleService
            ? round($gross * ($isRetention ? 0.03 : 0.12), $isRetention ? 2 : 0)
            : 0.0;

        return [
            'gross' => $gross,
            'deduction' => $deduction,
            'net' => round($gross - $deduction, 2),
            'is_retention' => $isRetention,
        ];
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
            $query->whereIn('c.idcuentasPorCobrar', $selectedIds);
        }

        $rows = $query->orderByDesc('c.fechaRegistro')->get();
        $rows->transform(function ($row) {
            $row->descripcion = CxcService::visibleServiceComment($row->descripcion);

            return $row;
        });
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
            ['key' => 'fechaRegistro', 'label' => 'Fecha de pago'],
            ['key' => 'fechaCancelacion', 'label' => 'Fecha fin del periodo'],
            ['key' => 'estado', 'label' => 'Estado'],
        ];

        $filename = 'cuentasporcobrar_export_' . now()->format('Ymd_His') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Cuentas por Cobrar', $filename);
    }

    public function exportServices(Request $request, string $format)
    {
        if (strtolower($format) !== 'xlsx') {
            abort(404);
        }

        $filters = $this->serviceFiltersFromRequest($request);
        $serviceIndexData = $this->cuentasPorCobrarService->prepareServicesIndex($filters);
        $groups = $this->filterServiceGroups($serviceIndexData['serviciosPorVencerGrouped'], $filters);

        return $this->exportServiceGroupsXlsx($groups);
    }

    private function exportServiceGroupsXlsx(array $groups)
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Servicios por vencer');

        $cxcIds = collect($groups)
            ->flatMap(function (array $group): array {
                $ids = array_merge(
                    (array) ($group['cxc_ids'] ?? []),
                    [(int) ($group['cxc_id'] ?? 0)]
                );
                foreach (($group['tipos_servicios'] ?? []) as $service) {
                    $ids = array_merge($ids, (array) ($service['cxc_ids'] ?? []), [(int) ($service['cxc_id'] ?? 0)]);
                }

                return array_merge($ids, (array) ($group['cxc_reversion_ids'] ?? []));
            })
            ->map(fn($id): int => (int) $id)
            ->filter(fn(int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $financials = $this->serviceExportFinancials($cxcIds);
        $detraccionRows = [];
        $retencionRows = [];

        foreach ($groups as $group) {
            $groupCxcIds = array_values(array_unique(array_filter(
                array_map('intval', array_merge(
                    (array) ($group['cxc_ids'] ?? []),
                    [(int) ($group['cxc_id'] ?? 0)],
                    collect($group['tipos_servicios'] ?? [])
                        ->flatMap(fn(array $service): array => array_merge(
                            (array) ($service['cxc_ids'] ?? []),
                            [(int) ($service['cxc_id'] ?? 0)]
                        ))
                        ->all(),
                    (array) ($group['cxc_reversion_ids'] ?? [])
                )),
                fn(int $id): bool => $id > 0
            )));
            $paymentAmount = 0.0;
            $withholdingTotals = [
                'detraccion' => 0.0,
                'retencion' => 0.0,
                'autodetraccion' => 0.0,
            ];
            $withholdingDates = [
                'detraccion' => [],
                'retencion' => [],
                'autodetraccion' => [],
            ];
            $chargeTotal = 0.0;
            $document = trim((string) ($group['documento'] ?? ''));

            foreach ($groupCxcIds as $cxcId) {
                $cxc = $financials['charges'][$cxcId] ?? null;
                $withholding = $financials['withholding'][$cxcId] ?? [];
                $chargeTotal += (float) ($cxc['total'] ?? 0);
                $paymentAmount += (float) ($financials['payments'][$cxcId] ?? 0);
                if ($document === '' || $document === '-') {
                    $document = trim((string) ($cxc['document'] ?? ''));
                }
                foreach (array_keys($withholdingTotals) as $type) {
                    $withholdingTotals[$type] += (float) ($withholding[$type] ?? 0);
                    $date = trim((string) ($withholding['fecha_' . $type] ?? ''));
                    if ($date !== '') {
                        $withholdingDates[$type][$date] = $date;
                    }
                }
            }

            $serviceSubtotal = collect($group['tipos_servicios'] ?? [])
                ->sum(fn(array $service): float => (float) ($service['monto_subtotal'] ?? 0));
            $isPendingWithholdingState = in_array((string) ($group['cxc_estado'] ?? ''), [
                'Pendiente Autodetracción',
                'Pendiente Autoretención',
                'Pendiente a Detracción',
                'Pendiente a Retención',
            ], true);
            $total = $isPendingWithholdingState
                ? ($chargeTotal > 0 ? $chargeTotal : (float) ($group['monto_servicios'] ?? $serviceSubtotal))
                : (float) ($group['monto_total'] ?? ($chargeTotal > 0 ? $chargeTotal : $serviceSubtotal));
            $symbol = $group['moneda_simbolo'] ?? 'S/';
            $row = [
                $group['cliente_id'] ?? '',
                $group['cliente_nombre'] ?? '',
                $group['fecha_fin'] ?? '-',
                $group['mes'] ?? '',
                $document !== '' ? $document : '-',
                $this->serviceExportAmount($total),
                $this->serviceExportAmount($paymentAmount),
            ];
            $isRetention = !empty($group['es_retencion']) || $withholdingTotals['retencion'] > 0;
            if ($isRetention) {
                $row[] = $this->serviceExportAmount($withholdingTotals['retencion']);
                $row[] = implode(', ', array_values($withholdingDates['retencion']));
                $retencionRows[] = [
                    'values' => array_slice($row, 0, 9),
                    'currency' => $symbol,
                ];
            } else {
                $row[] = $this->serviceExportAmount($withholdingTotals['detraccion']);
                $row[] = implode(', ', array_values($withholdingDates['detraccion']));
                $row[] = $this->serviceExportAmount($withholdingTotals['autodetraccion']);
                $row[] = implode(', ', array_values($withholdingDates['autodetraccion']));
                $detraccionRows[] = [
                    'values' => $row,
                    'currency' => $symbol,
                ];
            }
        }

        $detraccionHeaders = [
            'RUC',
            'CLIENTE',
            'FECHA LÍMITE',
            'MES',
            'FACTURA',
            'TOTAL',
            'PAGO REALIZADO',
            'DETRACCION',
            'FECHA ABONO',
            'AUTODETRACCIÓN',
            'FECHA ABONO',
        ];
        $retencionHeaders = [
            'RUC',
            'CLIENTE',
            'FECHA LÍMITE',
            'MES',
            'FACTURA',
            'TOTAL',
            'PAGO REALIZADO',
            'RETENCION',
            'FECHA ABONO',
        ];
        $rowIndex = 1;
        $writeTable = function (array $headers, array $rows, int $startRow, bool $isDetraccion) use ($sheet): int {
            foreach ($headers as $index => $header) {
                $sheet->setCellValueExplicit(chr(ord('A') + $index) . $startRow, $header, DataType::TYPE_STRING);
            }
            $lastColumn = chr(ord('A') + count($headers) - 1);
            $sheet->getStyle("A{$startRow}:{$lastColumn}{$startRow}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => 'FF1E293B']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFDBEAFE']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
            $rowIndex = $startRow + 1;
            foreach ($rows as $row) {
                $this->writeServiceExportRow($sheet, $rowIndex++, $row['values'], $row['currency']);
            }
            $lastRow = max($startRow, $rowIndex - 1);
            $sheet->getStyle("A{$startRow}:{$lastColumn}{$lastRow}")->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['argb' => 'FF000000'],
                    ],
                ],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
            if ($isDetraccion) {
                $sheet->getStyle("J{$startRow}:K{$lastRow}")->getFill()->setFillType(Fill::FILL_SOLID);
                $sheet->getStyle("J{$startRow}:K{$lastRow}")->getFill()->getStartColor()->setARGB('FFA8E08C');
            }

            return $lastRow;
        };

        if ($detraccionRows !== []) {
            $rowIndex = $writeTable($detraccionHeaders, $detraccionRows, $rowIndex, true) + 3;
        }
        if ($retencionRows !== []) {
            $writeTable($retencionHeaders, $retencionRows, $rowIndex, false);
        }
        foreach ([18, 36, 18, 15, 22, 18, 20, 18, 18, 20, 18] as $index => $width) {
            $sheet->getColumnDimension(chr(ord('A') + $index))->setWidth($width);
        }
        $sheet->freezePane('A2');

        $filename = 'servicios_por_vencer_' . now()->format('Ymd_His') . '.xlsx';
        $tempFile = tempnam(sys_get_temp_dir(), 'export_xlsx_');
        if ($tempFile === false) {
            throw new \RuntimeException('No se pudo crear el archivo temporal para exportar servicios.');
        }
        (new Xlsx($spreadsheet))->save($tempFile);

        return response()->download($tempFile, $filename)->deleteFileAfterSend(true);
    }

    private function serviceExportFinancials(array $cxcIds): array
    {
        if ($cxcIds === []) {
            return ['charges' => [], 'payments' => [], 'withholding' => []];
        }

        $charges = DB::table('cuentasporcobrar')
            ->whereIn('idcuentasPorCobrar', $cxcIds)
            ->get(['idcuentasPorCobrar', 'montoOriginal', 'docReferencia', 'estado'])
            ->mapWithKeys(static fn($charge): array => [
                (int) $charge->idcuentasPorCobrar => [
                    'total' => (float) $charge->montoOriginal,
                    'document' => trim((string) $charge->docReferencia),
                    'state' => (string) $charge->estado,
                ]
            ])
            ->all();

        $payments = [];
        $withholding = [];
        $details = DB::table('detallecxc as d')
            ->join('bancos as b', 'b.idbancos', '=', 'd.bancos_idbancos')
            ->whereIn('d.cuentasPorCobrar_idcuentasPorCobrar', $cxcIds)
            ->where('d.estado', '1')
            ->get([
                'd.cuentasPorCobrar_idcuentasPorCobrar as cxc_id',
                'd.descripcion',
                'd.fechaPago',
                'b.idbancos',
                'b.tipoMov',
                'b.monto',
                'b.informacion',
                'b.fechaRegistro',
            ]);
        foreach ($details as $detail) {
            $cxcId = (int) $detail->cxc_id;
            $amount = (float) $detail->monto;
            $information = mb_strtolower((string) $detail->informacion, 'UTF-8');
            if (str_starts_with($information, '[anulado]')) {
                continue;
            }
            if (
                (string) $detail->tipoMov === 'I'
                && !str_contains(mb_strtolower((string) $detail->descripcion, 'UTF-8'), 'detracción')
                && !str_contains(mb_strtolower((string) $detail->descripcion, 'UTF-8'), 'retención')
            ) {
                if (!isset($payments[$cxcId]['movement_ids'][(int) $detail->idbancos])) {
                    $payments[$cxcId]['amount'] = round(($payments[$cxcId]['amount'] ?? 0) + $amount, 2);
                    $payments[$cxcId]['movement_ids'][(int) $detail->idbancos] = true;
                }

                continue;
            }
            if (!in_array((string) $detail->tipoMov, ['T', 'S'], true)) {
                continue;
            }
            $isAutodetraccion = str_contains($information, 'autodetracción')
                || str_contains($information, 'autodetraccion');
            $description = mb_strtolower((string) $detail->descripcion, 'UTF-8');
            $isRetention = str_contains($description, 'retención')
                || str_contains($information, 'retención pagada');
            if (
                !$isAutodetraccion && !$isRetention
                && !str_contains($description, 'detracción')
            ) {
                continue;
            }
            $key = $isAutodetraccion ? 'autodetraccion' : ($isRetention ? 'retencion' : 'detraccion');
            $dateKey = 'fecha_' . $key;
            $withholding[$cxcId][$key] = round(($withholding[$cxcId][$key] ?? 0) + $amount, 2);
            $date = $detail->fechaPago ?: $detail->fechaRegistro;
            $withholding[$cxcId][$dateKey] = $date
                ? Carbon::parse($date)->format('d/m/Y')
                : '';
        }

        $pending = DB::table('detallecxc as d')
            ->join('cuentasporcobrar as c', 'c.idcuentasPorCobrar', '=', 'd.cuentasPorCobrar_idcuentasPorCobrar')
            ->whereIn('d.cuentasPorCobrar_idcuentasPorCobrar', $cxcIds)
            ->where('d.estado', '0')
            ->where(function ($query): void {
                $query->where('d.descripcion', 'like', 'Detracción pendiente:%')
                    ->orWhere('d.descripcion', 'like', 'Retención pendiente:%');
            })
            ->get([
                'd.cuentasPorCobrar_idcuentasPorCobrar as cxc_id',
                'd.descripcion',
                'c.estado',
            ]);
        foreach ($pending as $detail) {
            if (!preg_match('/:\s*([0-9]+(?:\.[0-9]{1,2})?)/', (string) $detail->descripcion, $match)) {
                continue;
            }
            $cxcId = (int) $detail->cxc_id;
            $isRetention = str_starts_with((string) $detail->descripcion, 'Retención pendiente:');
            $isAutodetraccion = !$isRetention
                && (string) $detail->estado === 'Pendiente Autodetracción';
            $key = $isAutodetraccion ? 'autodetraccion' : ($isRetention ? 'retencion' : 'detraccion');
            $withholding[$cxcId][$key] = round(($withholding[$cxcId][$key] ?? 0) + (float) $match[1], 2);
        }

        $payments = array_map(static fn(array $payment): float => (float) ($payment['amount'] ?? 0), $payments);

        return ['charges' => $charges, 'payments' => $payments, 'withholding' => $withholding];
    }

    private function serviceExportAmount(float|int|string $amount): float|string
    {
        $amount = (float) $amount;
        if ($amount <= 0) {
            return '';
        }

        return $amount;
    }

    private function writeServiceExportRow(Worksheet $sheet, int $row, array $values, string $currencySymbol): void
    {
        foreach ($values as $index => $value) {
            $column = chr(ord('A') + $index);
            if (in_array($index, [5, 6, 7, 9], true) && is_numeric($value) && $value !== '') {
                $cell = $column . $row;
                $sheet->setCellValueExplicit($cell, (float) $value, DataType::TYPE_NUMERIC);
                $currencyFormat = '"' . str_replace('"', '""', trim($currencySymbol)) . '" #,##0.00';
                $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($currencyFormat);

                continue;
            }

            $sheet->setCellValueExplicit($column . $row, (string) ($value ?? ''), DataType::TYPE_STRING);
        }
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
                    'maxlength' => 80,
                    'helpText' => 'Breve descripción.',
                ],
                $this->decimalFieldDefinition('montoOriginal', 'Monto original', null, true),
                $this->decimalFieldDefinition('montoActual', 'Monto actual', null, true),
                [
                    'name' => 'fechaRegistro',
                    'type' => 'date',
                    'label' => 'Fecha registro',
                    'required' => false,
                ],
                [
                    'name' => 'fechaCancelacion',
                    'type' => 'date',
                    'label' => 'Fecha fin del periodo',
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
                        ['value' => '1', 'label' => 'Pendiente'],
                        ['value' => '2', 'label' => 'Facturado'],
                        ['value' => '3', 'label' => 'Cancelado'],
                        ['value' => '4', 'label' => 'Vencido'],
                        ['value' => 'Pendiente Pago parcial', 'label' => 'Pendiente Pago parcial'],
                        ['value' => 'Pendiente a credito', 'label' => 'Pendiente a credito'],
                        ['value' => 'NUEVO', 'label' => 'Nuevo'],
                        ['value' => 'Pendiente cuota crédito', 'label' => 'Pendiente cuota crédito'],
                        ['value' => 'Aprobado', 'label' => 'Aprobado'],
                        ['value' => 'Pendiente Autodetracción', 'label' => 'Pendiente Autodetracción'],
                        ['value' => 'Pendiente a Detracción', 'label' => 'Pendiente a Detracción'],
                        ['value' => 'Pendiente a Retención', 'label' => 'Pendiente a Retención'],
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
            'descripcion' => ['required', 'string', 'max:80', 'regex:' . self::SAFE_TEXT_REGEX],
            'montoOriginal' => ['required', 'numeric', 'min:0'],
            'montoActual' => ['required', 'numeric', 'min:0'],
            'canCuotas' => ['nullable', 'numeric', 'min:1'],
            'fechaRegistro' => ['nullable', 'date'],
            'fechaCancelacion' => ['nullable', 'date'],
            'estado' => ['required', 'in:1,2,3,4,NUEVO,PagoCredito,Pendiente cuota crédito,Aprobado,Pendiente Pago parcial,Pendiente a credito,Cancelado Detracción,Pendiente Autodetracción,Pendiente a Detracción,Pendiente a Retención'],
        ]);
        $validated = $this->normalizeDecimalFields($validated);

        $id = DB::table('cuentasporcobrar')->insertGetId($validated);
        $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $id, 'created');

        return redirect()->route('modules.cuentasporcobrar')->with('success', 'Cuenta por cobrar creada correctamente.');
    }

    public function darDeBajaServicios(Request $request)
    {
        $validated = $request->validate([
            'servicio_ids' => ['required', 'array', 'min:1'],
            'servicio_ids.*' => ['required', 'integer', 'exists:serviciocliente,idservicioCliente'],
            'baja_modo' => ['required', 'in:ahora,periodo'],
            'comentario' => ['required', 'string', 'max:500'],
            'mantener_sim' => ['nullable', 'in:si,no'],
        ]);

        $serviceIds = array_values(array_map('intval', $validated['servicio_ids']));
        $modo = $validated['baja_modo'];
        $comentario = trim($validated['comentario']);
        $mantenerSim = $validated['mantener_sim'] ?? 'si';
        $usuario = (string) $request->session()->get('erp_auth.usuario', 'anonimo');

        try {
            $processed = $this->cxcService->darDeBajaServicios(
                $serviceIds,
                $modo,
                $comentario,
                $mantenerSim,
                $usuario
            );
        } catch (\Throwable $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => 'No se pudo procesar la baja: ' . $e->getMessage()], 422);
            }

            return redirect()->route('modules.cuentasporcobrar', ['tab' => 'servicios'])
                ->with('error', 'No se pudo procesar la baja de los servicios: ' . $e->getMessage());
        }

        $serviceLabel = $processed === 1 ? 'servicio' : 'servicios';
        $msg = $processed === 0
            ? 'No se encontraron servicios activos para dar de baja.'
            : ($modo === 'ahora'
                ? "{$processed} {$serviceLabel} " . ($processed === 1 ? 'dado' : 'dados') . ' de baja correctamente.'
                : "Baja programada para {$processed} {$serviceLabel} al finalizar el periodo.");

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => $msg]);
        }

        return redirect()->route('modules.cuentasporcobrar', ['tab' => 'servicios'])->with('success', $msg);
    }

    public function getPaymentDetails(string $id): JsonResponse
    {
        $requestedCxcIds = request()->query('cxc_ids', []);
        if (is_string($requestedCxcIds)) {
            $requestedCxcIds = explode(',', $requestedCxcIds);
        }
        $requestedCxcIds = collect(is_array($requestedCxcIds) ? $requestedCxcIds : [])
            ->push($id)
            ->map(fn($cxcId): int => (int) $cxcId)
            ->filter(fn(int $cxcId): bool => $cxcId > 0)
            ->unique()
            ->values()
            ->all();
        $baseCxc = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $id)
            ->first(['cliente_idcliente']);
        if (!$baseCxc) {
            return response()->json(['success' => false, 'message' => 'Cuenta por cobrar no encontrada'], 444);
        }

        $latestPayment = DB::table('detallecxc as payment_detail')
            ->leftJoin('bancos as payment_bank', 'payment_bank.idbancos', '=', 'payment_detail.bancos_idbancos')
            ->selectRaw('payment_detail.cuentasPorCobrar_idcuentasPorCobrar, MAX(payment_detail.iddetalleCXC) as iddetalleCXC')
            ->where('payment_detail.estado', '1')
            ->whereNotNull('payment_detail.fechaPago')
            ->where(function ($query): void {
                $query->whereNull('payment_bank.tipoMov')
                    ->orWhere('payment_bank.tipoMov', '<>', 'T');
            })
            ->groupBy('payment_detail.cuentasPorCobrar_idcuentasPorCobrar');
        $latestEvidence = DB::table('evidenciapago')
            ->selectRaw('bancos_idbancos, MAX(idevidenciaPago) as idevidenciaPago')
            ->groupBy('bancos_idbancos');

        $cxc = DB::table('cuentasporcobrar as c')
            ->leftJoin('cliente as cli', 'cli.idcliente', '=', 'c.cliente_idcliente')
            ->leftJoinSub($latestPayment, 'latest_payment', function ($join) {
                $join->on('latest_payment.cuentasPorCobrar_idcuentasPorCobrar', '=', 'c.idcuentasPorCobrar');
            })
            ->leftJoin('detallecxc as dc', 'dc.iddetalleCXC', '=', 'latest_payment.iddetalleCXC')
            ->leftJoin('bancos as b', 'b.idbancos', '=', 'dc.bancos_idbancos')
            ->leftJoin('entidadbancaria as eb', 'eb.identidadBancaria', '=', 'b.entidadBancaria_identidadBancaria')
            ->leftJoin('formapago as fp', 'fp.idformaPago', '=', 'dc.formaPago_idformaPago')
            ->leftJoin('moneda as payment_currency', 'payment_currency.idmoneda', '=', 'dc.moneda_idmoneda')
            ->leftJoinSub($latestEvidence, 'latest_evidence', function ($join) {
                $join->on('latest_evidence.bancos_idbancos', '=', 'dc.bancos_idbancos');
            })
            ->leftJoin('evidenciapago as ep', 'ep.idevidenciaPago', '=', 'latest_evidence.idevidenciaPago')
            ->leftJoin('moneda as m', 'm.idmoneda', '=', 'c.moneda_idmoneda')
            ->select([
                'c.idcuentasPorCobrar',
                'c.cliente_idcliente',
                'c.tipoCobro_idtipoCobros',
                'c.docReferencia',
                'c.montoOriginal',
                'c.fechaCancelacion',
                'c.estado',
                'c.descripcion as cxc_descripcion',
                'c.moneda_idmoneda',
                'cli.detraccion as cliente_detraccion',
                'dc.iddetalleCXC',
                DB::raw('COALESCE(cli.razonSocial, cli.nombreComercial, cli.idcliente) as cliente_nombre'),
                'dc.formaPago_idformaPago',
                'dc.bancos_idbancos',
                'dc.fechaPago',
                'dc.descripcion as detalle_descripcion',
                'dc.moneda_idmoneda as payment_currency_id',
                'b.informacion as pago_descripcion',
                'b.fechaRegistro as payment_bank_fecha_registro',
                'b.entidadBancaria_identidadBancaria',
                'eb.razonSocial as banco_nombre',
                'fp.detalle as forma_pago_nombre',
                'm.simbolo as moneda_simbolo',
                'payment_currency.simbolo as payment_currency_symbol',
                'ep.archivo as comprobante_archivo',
            ])
            ->whereIn('c.idcuentasPorCobrar', $requestedCxcIds)
            ->where('c.cliente_idcliente', $baseCxc->cliente_idcliente)
            ->orderByDesc('dc.iddetalleCXC')
            ->first();

        if (!$cxc) {
            return response()->json(['success' => false, 'message' => 'Cuenta por cobrar no encontrada'], 444);
        }
        $cxc->monto_cancelado = null;
        $cxc->monto_cancelado_simbolo = null;
        $cxc->mostrar_monto_cancelado = false;
        if (
            $cxc->fechaPago
            && $cxc->payment_currency_id
            && (int) $cxc->payment_currency_id !== (int) $cxc->moneda_idmoneda
        ) {
            $cxc->monto_cancelado = round((float) DB::table('detallecxc as payment_detail')
                ->join('bancos as payment_bank', 'payment_bank.idbancos', '=', 'payment_detail.bancos_idbancos')
                ->join('cuentasporcobrar as grouped_cxc', 'grouped_cxc.idcuentasPorCobrar', '=', 'payment_detail.cuentasPorCobrar_idcuentasPorCobrar')
                ->where('grouped_cxc.cliente_idcliente', $cxc->cliente_idcliente)
                ->where('payment_detail.descripcion', $cxc->detalle_descripcion)
                ->where('payment_detail.fechaPago', $cxc->fechaPago)
                ->where('payment_detail.moneda_idmoneda', $cxc->payment_currency_id)
                ->where('payment_bank.fechaRegistro', $cxc->payment_bank_fecha_registro)
                ->where('payment_bank.entidadBancaria_identidadBancaria', $cxc->entidadBancaria_identidadBancaria)
                ->where('payment_detail.estado', '1')
                ->where('payment_bank.tipoMov', 'I')
                ->sum('payment_bank.monto'), 2);
            $cxc->monto_cancelado_simbolo = $this->currencySymbol($cxc->payment_currency_symbol);
            $cxc->mostrar_monto_cancelado = $cxc->monto_cancelado > 0;
        }
        if (
            empty($cxc->comprobante_archivo)
            && str_starts_with((string) $cxc->cxc_descripcion, 'COTIZACION:')
            && Schema::hasTable('cotizacion')
            && Schema::hasColumn('cotizacion', 'archivoPago')
        ) {
            $quotationId = substr((string) $cxc->cxc_descripcion, strlen('COTIZACION:'));
            $cxc->comprobante_archivo = DB::table('cotizacion')
                ->where('nroCotizacion', $quotationId)
                ->value('archivoPago');
        }

        $withholding = DB::table('detallecxc as withholding_detail')
            ->join('bancos as withholding_bank', 'withholding_bank.idbancos', '=', 'withholding_detail.bancos_idbancos')
            ->where('withholding_detail.cuentasPorCobrar_idcuentasPorCobrar', $id)
            ->where('withholding_detail.estado', '1')
            ->whereNotNull('withholding_detail.fechaPago')
            ->where('withholding_bank.tipoMov', 'T')
            ->where(function ($query): void {
                $query->where('withholding_detail.descripcion', 'like', 'Detracción aplicada%')
                    ->orWhere('withholding_detail.descripcion', 'like', 'Retención aplicada%');
            })
            ->orderByDesc('withholding_detail.iddetalleCXC')
            ->first([
                'withholding_detail.descripcion',
                'withholding_detail.fechaPago',
                'withholding_bank.monto',
                'withholding_bank.idbancos',
                'withholding_bank.informacion',
            ]);

        $withholdingAmount = $withholding ? (float) $withholding->monto : 0.0;
        if (
            $withholding
            && trim((string) $cxc->docReferencia) !== ''
            && !str_starts_with((string) $cxc->cxc_descripcion, 'COTIZACION:')
        ) {
            $groupedWithholdingMovements = DB::table('detallecxc as group_withholding_detail')
                ->join('bancos as group_withholding_bank', 'group_withholding_bank.idbancos', '=', 'group_withholding_detail.bancos_idbancos')
                ->join('cuentasporcobrar as grouped_cxc', 'grouped_cxc.idcuentasPorCobrar', '=', 'group_withholding_detail.cuentasPorCobrar_idcuentasPorCobrar')
                ->where('grouped_cxc.cliente_idcliente', $cxc->cliente_idcliente)
                ->where('grouped_cxc.docReferencia', $cxc->docReferencia)
                ->where('group_withholding_detail.fechaPago', $withholding->fechaPago)
                ->where('group_withholding_detail.moneda_idmoneda', $cxc->moneda_idmoneda)
                ->where('group_withholding_detail.estado', '1')
                ->where('group_withholding_bank.tipoMov', 'T')
                ->where(function ($query): void {
                    $query->where('group_withholding_detail.descripcion', 'like', 'Detracción aplicada%')
                        ->orWhere('group_withholding_detail.descripcion', 'like', 'Retención aplicada%');
                })
                ->whereExists(function ($query) use ($cxc): void {
                    $query->selectRaw('1')
                        ->from('detallecxc as grouped_payment_detail')
                        ->join('bancos as grouped_payment_bank', 'grouped_payment_bank.idbancos', '=', 'grouped_payment_detail.bancos_idbancos')
                        ->whereColumn('grouped_payment_detail.cuentasPorCobrar_idcuentasPorCobrar', 'grouped_cxc.idcuentasPorCobrar')
                        ->where('grouped_payment_detail.fechaPago', $cxc->fechaPago)
                        ->where('grouped_payment_detail.estado', '1')
                        ->where('grouped_payment_detail.moneda_idmoneda', $cxc->payment_currency_id)
                        ->where('grouped_payment_detail.descripcion', $cxc->detalle_descripcion)
                        ->where('grouped_payment_bank.tipoMov', 'I');
                })
                ->select('group_withholding_bank.idbancos', 'group_withholding_bank.monto')
                ->distinct()
                ->get();
            if ($groupedWithholdingMovements->isNotEmpty()) {
                $withholdingAmount = round((float) $groupedWithholdingMovements->sum('monto'), 2);
            }
        }

        $withholdingEvidence = $withholding
            ? DB::table('evidenciapago')
                ->where('bancos_idbancos', $withholding->idbancos)
                ->orderByDesc('idevidenciaPago')
                ->value('archivo')
            : null;
        $withholdingComment = '';
        if ($withholding && str_contains((string) $withholding->informacion, '|')) {
            $informationParts = array_map('trim', explode('|', (string) $withholding->informacion));
            $lastInformationPart = trim((string) end($informationParts));
            if ($lastInformationPart !== '' && !str_starts_with(mb_strtolower($lastInformationPart, 'UTF-8'), 'se pagó ')) {
                $withholdingComment = $lastInformationPart;
            }
        }
        $paymentDescription = trim((string) ($cxc->pago_descripcion ?? ''));
        $serviceDescription = trim((string) ($cxc->detalle_descripcion ?? ''));
        if (str_starts_with($serviceDescription, 'Obs: ')) {
            $paymentDescription = trim(mb_substr($serviceDescription, mb_strlen('Obs: ', 'UTF-8'), null, 'UTF-8'));
        } elseif (
            str_starts_with($serviceDescription, 'Pago ')
            && preg_match('/\((.*)$/u', $paymentDescription, $matches) === 1
        ) {
            $paymentDescription = trim($matches[1]);
            if (str_ends_with($paymentDescription, ')')) {
                $paymentDescription = substr($paymentDescription, 0, -1);
            }
        }
        $cxc->detalle_descripcion = $paymentDescription;
        $cxc->fechaFin = $cxc->fechaCancelacion;
        $cxc->comprobante_url = $cxc->comprobante_archivo
            ? Storage::disk('public')->url($cxc->comprobante_archivo)
            : null;
        $withholdingInformation = mb_strtolower((string) ($withholding->informacion ?? ''), 'UTF-8');
        $cxc->withholding_applies = $withholding !== null;
        $cxc->withholding_type = str_contains($withholdingInformation, 'autodetracción')
            || str_contains($withholdingInformation, 'autodetraccion')
            ? 'autodetracción'
            : (str_contains($withholdingInformation, 'autoretención')
                || str_contains($withholdingInformation, 'autoretencion')
                ? 'autoretención'
                : ($withholding
                    ? (str_starts_with((string) $withholding->descripcion, 'Retención') ? 'retención' : 'detracción')
                    : ((string) ($cxc->cliente_detraccion ?? '') === '1' ? 'retención' : 'detracción')));
        $cxc->withholding_fecha = $withholding?->fechaPago
            ? Carbon::parse($withholding->fechaPago)->format('d/m/Y')
            : '';
        $cxc->withholding_monto = $withholding ? $withholdingAmount : null;
        $currencySymbol = $this->currencySymbol($cxc->moneda_simbolo ?? null);
        $cxc->withholding_monto_display = $withholding
            ? $this->formatCurrencyValue($withholdingAmount, $currencySymbol)
            : '';
        $cxc->withholding_comment = $withholdingComment;
        $cxc->withholding_comprobante_url = $withholdingEvidence
            ? Storage::disk('public')->url($withholdingEvidence)
            : null;

        return response()->json(['success' => true, 'data' => $cxc]);
    }

    public function updatePaymentDetails(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:100'],
            'comprobante' => ['sometimes', 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'eliminar_comprobante' => ['sometimes', 'boolean'],
        ]);

        $cxc = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $id)
            ->first(['estado', 'descripcion']);
        if (!$cxc) {
            return response()->json(['success' => false, 'message' => 'Cuenta por cobrar no encontrada.'], 404);
        }
        if (!in_array(strtoupper((string) $cxc->estado), ['3', 'CANCELADO'], true)) {
            return response()->json(['success' => false, 'message' => 'Solo se pueden editar los datos de una cuenta cancelada.'], 422);
        }

        $payment = DB::table('detallecxc as payment_detail')
            ->leftJoin('bancos as payment_bank', 'payment_bank.idbancos', '=', 'payment_detail.bancos_idbancos')
            ->where('payment_detail.cuentasPorCobrar_idcuentasPorCobrar', $id)
            ->where('payment_detail.estado', '1')
            ->whereNotNull('payment_detail.fechaPago')
            ->where(function ($query): void {
                $query->whereNull('payment_bank.tipoMov')
                    ->orWhere('payment_bank.tipoMov', '<>', 'T');
            })
            ->orderByDesc('payment_detail.iddetalleCXC')
            ->first([
                'payment_detail.iddetalleCXC',
                'payment_detail.bancos_idbancos',
                'payment_detail.descripcion',
            ]);
        if (!$payment || !$payment->bancos_idbancos) {
            return response()->json(['success' => false, 'message' => 'No se encontró el movimiento bancario del pago.'], 422);
        }

        $newPath = null;
        $oldPath = null;
        $removeProof = $request->boolean('eliminar_comprobante');

        try {
            DB::transaction(function () use ($request, $validated, $payment, $cxc, &$newPath, &$oldPath, $removeProof): void {
                $bankQuery = DB::table('bancos')
                    ->where('idbancos', $payment->bancos_idbancos)
                    ->lockForUpdate();
                if (!$bankQuery->exists()) {
                    throw new \RuntimeException('No se encontró el movimiento bancario del pago.');
                }
                if (array_key_exists('descripcion', $validated)) {
                    $description = trim((string) ($validated['descripcion'] ?? ''));
                    $bankQuery->update(['informacion' => $description]);
                    if (str_starts_with((string) $payment->descripcion, 'Obs: ')) {
                        DB::table('detallecxc')
                            ->where('iddetalleCXC', $payment->iddetalleCXC)
                            ->update(['descripcion' => mb_substr('Obs: ' . $description, 0, 80)]);
                    }
                }

                $evidenceQuery = DB::table('evidenciapago')
                    ->where('bancos_idbancos', $payment->bancos_idbancos)
                    ->orderByDesc('idevidenciaPago');
                $evidence = $evidenceQuery->first(['idevidenciaPago', 'archivo']);
                $quotationId = null;
                $quotationReceiptPath = null;
                if (
                    str_starts_with((string) $cxc->descripcion, 'COTIZACION:')
                    && Schema::hasTable('cotizacion')
                    && Schema::hasColumn('cotizacion', 'archivoPago')
                ) {
                    $quotationId = substr((string) $cxc->descripcion, strlen('COTIZACION:'));
                    $quotationReceiptPath = DB::table('cotizacion')
                        ->where('nroCotizacion', $quotationId)
                        ->value('archivoPago');
                }

                if ($request->hasFile('comprobante')) {
                    $newPath = $request->file('comprobante')->store('comprobantes-pago/cxc', 'public');
                    if (!$newPath) {
                        throw new \RuntimeException('No se pudo guardar el comprobante de pago.');
                    }
                    $oldPath = $evidence?->archivo ?: $quotationReceiptPath;
                    if ($evidence) {
                        DB::table('evidenciapago')->where('idevidenciaPago', $evidence->idevidenciaPago)
                            ->update(['archivo' => $newPath, 'fechaCarga' => now()]);
                    } else {
                        DB::table('evidenciapago')->insert([
                            'archivo' => $newPath,
                            'fechaCarga' => now(),
                            'bancos_idbancos' => $payment->bancos_idbancos,
                        ]);
                    }
                    if ($quotationId !== null) {
                        DB::table('cotizacion')->where('nroCotizacion', $quotationId)
                            ->update(['archivoPago' => $newPath]);
                    }
                } elseif ($removeProof) {
                    if ($evidence) {
                        $oldPath = $evidence->archivo;
                        DB::table('evidenciapago')->where('idevidenciaPago', $evidence->idevidenciaPago)->delete();
                    } elseif ($quotationReceiptPath) {
                        $oldPath = $quotationReceiptPath;
                    }
                    if ($quotationId !== null) {
                        DB::table('cotizacion')->where('nroCotizacion', $quotationId)
                            ->update(['archivoPago' => null]);
                    }
                }
            });
        } catch (\Throwable $exception) {
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }

            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        if ($oldPath && Storage::disk('public')->exists($oldPath) && !Storage::disk('public')->delete($oldPath)) {
            return response()->json(['success' => false, 'message' => 'Los datos se actualizaron, pero no se pudo eliminar el archivo anterior.'], 500);
        }

        return response()->json(['success' => true, 'message' => 'Los datos del pago se actualizaron correctamente.']);
    }

    public function registrarFactura(Request $request, string $id)
    {
        $validated = $request->validate([
            'archivoFactura' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'servicioCliente_idservicioCliente' => ['nullable', 'integer', 'exists:serviciocliente,idservicioCliente'],
            'servicio_ids' => ['nullable', 'array'],
            'servicio_ids.*' => ['integer', 'exists:serviciocliente,idservicioCliente'],
            'cxc_ids' => ['nullable', 'array'],
            'cxc_ids.*' => ['integer', 'exists:cuentasporcobrar,idcuentasPorCobrar'],
            'period_cxc_ids' => ['nullable', 'array', 'max:50'],
            'period_cxc_ids.*' => ['integer', 'distinct', 'exists:cuentasporcobrar,idcuentasPorCobrar'],
            'nota_credito_ids' => ['nullable', 'array', 'max:1'],
            'nota_credito_ids.*' => ['integer', 'distinct', 'exists:nota_credito,idnotacredito'],
            'usar_saldo_favor' => ['sometimes', 'boolean'],
            'monto_saldo_favor' => ['nullable', 'numeric', 'min:0'],
            'docReferencia' => ['nullable', 'string', 'max:50'],
            'descripcionFactura' => ['nullable', 'string', 'max:100'],
            'descripcionPago' => ['nullable', 'string', 'max:100'],
            'realizar_cobro' => ['nullable', 'in:0,1'],
            'monto_factura' => ['nullable', 'numeric', 'min:0.01'],
            'tipoCobro_idtipoCobros' => ['required_if:realizar_cobro,1', 'nullable', 'integer', 'exists:tipocobro,idtipoCobros'],
            'formaPago_idformaPago' => ['required_if:realizar_cobro,1', 'nullable', 'integer', 'exists:formapago,idformaPago'],
            'entidadBancaria_identidadBancaria' => ['required_if:realizar_cobro,1', 'nullable', 'integer', 'exists:entidadbancaria,identidadBancaria'],
            'withholding_decision' => ['nullable', 'in:paid,pending,full_pending'],
            'factura_withholding_decision' => ['nullable', 'in:paid,pending,full_pending'],
            'moneda_idmoneda' => ['nullable'],
            'currency_selector' => ['nullable', 'integer', 'exists:moneda,idmoneda'],
            'tipo_cambio' => ['nullable', 'numeric'],
            'monto_cancelado' => ['nullable', 'numeric'],
            'precio_convertido' => ['nullable', 'numeric'],
            'fechaPago' => ['nullable', 'date'],
            'fechaFin' => ['nullable', 'date'],
            'modo_pago' => ['nullable', 'in:total,parcial'],
            'montoPago' => ['nullable', 'numeric', 'min:0'],
            'adelanto_meses' => ['nullable', 'integer', 'min:1', 'max:120'],
            'canCuotas' => ['nullable', 'integer', 'min:1', 'max:60'],
            'factura_cuotas_montos' => ['nullable', 'array'],
            'factura_cuotas_montos.*' => ['nullable', 'numeric', 'gt:0'],
            'factura_cuotas_fechas' => ['nullable', 'array'],
            'factura_cuotas_fechas.*' => ['nullable', 'date'],
            'comprobante' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'withholding_proof' => ['required_if:factura_withholding_decision,paid', 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'withholding_fecha' => ['nullable', 'date'],
            'withholding_description' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $file = $request->file('archivoFactura');
            $docText = $request->input('docReferencia');
            $realizarCobro = $request->input('realizar_cobro') === '1';

            if (!$file && empty($docText)) {
                throw new \RuntimeException('Debe adjuntar un archivo de factura/boleta o ingresar el número de documento de referencia.');
            }

            $serviceIds = [];
            if (!empty($validated['servicio_ids'])) {
                $serviceIds = array_values(array_map('intval', $validated['servicio_ids']));
            } elseif (!empty($validated['servicioCliente_idservicioCliente'])) {
                $serviceIds = [(int) $validated['servicioCliente_idservicioCliente']];
            }

            $currentCxcIds = array_values(array_unique(array_map(
                'intval',
                $validated['cxc_ids'] ?? [(int) $id]
            )));
            if ($currentCxcIds === []) {
                $currentCxcIds = [(int) $id];
            }
            $periodCxcIds = $realizarCobro
                ? array_values(array_diff(
                    array_map('intval', $validated['period_cxc_ids'] ?? []),
                    $currentCxcIds
                ))
                : [];

            $paymentResult = DB::transaction(function () use ($id, $currentCxcIds, $periodCxcIds, $file, $docText, $serviceIds, $validated, $request, $realizarCobro): ?array {
                $renewalCxcId = (int) $id;
                $renewalCxcIds = [(int) $id];
                if ($serviceIds !== []) {
                    $isolatedReceivables = $this->cxcService->isolateSelectedServiceReceivables($currentCxcIds, $serviceIds);
                    $currentCxcIds = $isolatedReceivables['cxc_ids'];
                    $renewalCxcId = (int) ($isolatedReceivables['renewal_cxc_id'] ?: $id);
                    $renewalCxcIds = $isolatedReceivables['renewal_cxc_ids'] ?? $currentCxcIds;
                }
                $paymentCxcIds = array_values(array_unique(array_merge($currentCxcIds, $periodCxcIds)));
                $this->cxcService->linkServicesToReceivables($currentCxcIds, $serviceIds, $renewalCxcId);

                foreach ($currentCxcIds as $targetCxcId) {
                    $this->cxcService->registrarFactura(
                        $targetCxcId,
                        $file ?? '',
                        $docText,
                        !empty($serviceIds) ? $serviceIds : null,
                        (string) $request->session()->get('erp_auth.usuario', 'anonimo')
                    );

                    if (
                        !empty($validated['descripcionFactura'])
                        || ((int) $targetCxcId === (int) $id && !$realizarCobro && (int) ($validated['adelanto_meses'] ?? 0) > 1)
                    ) {
                        $description = $validated['descripcionFactura'] ?? '';
                        $invoiceComment = (int) $targetCxcId === (int) $id && !$realizarCobro
                            ? CxcService::descriptionWithAdvanceMonths(
                                $description,
                                (int) ($validated['adelanto_meses'] ?? 0)
                            )
                            : mb_substr($description, 0, 100);
                        $currentDescription = DB::table('cuentasporcobrar')
                            ->where('idcuentasPorCobrar', $targetCxcId)
                            ->value('descripcion');
                        $invoiceDescription = CxcService::descriptionWithServiceComment(
                            $currentDescription,
                            $invoiceComment
                        );
                        DB::table('cuentasporcobrar')
                            ->where('idcuentasPorCobrar', $targetCxcId)
                            ->update(['descripcion' => $invoiceDescription]);
                    }
                }

                if (!$realizarCobro) {
                    return null;
                }

                $paymentData = [
                    'entidadBancaria_identidadBancaria' => $validated['entidadBancaria_identidadBancaria'] ?? null,
                    'formaPago_idformaPago' => $validated['formaPago_idformaPago'] ?? null,
                    'tipoCobro_idtipoCobros' => $validated['tipoCobro_idtipoCobros'] ?? null,
                    'moneda_idmoneda' => $validated['moneda_idmoneda'] ?? null,
                    'currency_selector' => $validated['currency_selector'] ?? $validated['moneda_idmoneda'] ?? null,
                    'payment_currency_mode' => $request->input('factura_payment_currency_mode', 'same'),
                    'tipo_cambio' => $validated['tipo_cambio'] ?? null,
                    'monto_cancelado' => $validated['monto_cancelado'] ?? null,
                    'montoPago' => $validated['montoPago'] ?? null,
                    'adelanto_meses' => $validated['adelanto_meses'] ?? null,
                    'monto_factura' => $validated['monto_factura'] ?? null,
                    'modo_pago' => $request->input('factura_modo_pago', 'total'),
                    'usar_saldo_favor' => $request->boolean('usar_saldo_favor'),
                    'monto_saldo_favor' => $validated['monto_saldo_favor'] ?? null,
                    'nota_credito_ids' => $validated['nota_credito_ids'] ?? [],
                    'withholding_decision' => $validated['factura_withholding_decision'] ?? null,
                    'withholding_proof' => $request->file('withholding_proof'),
                    'withholding_fecha' => $validated['withholding_fecha'] ?? null,
                    'withholding_description' => $validated['withholding_description'] ?? '',
                    'canCuotas' => $validated['canCuotas'] ?? null,
                    'cuotas_montos' => $request->input('factura_cuotas_montos', []),
                    'cuotas_fechas' => $request->input('factura_cuotas_fechas', []),
                    'fechaPago' => $validated['fechaPago'] ?? now()->format('Y-m-d'),
                    'fechaFin' => $validated['fechaFin'] ?? null,
                    'servicio_ids' => $serviceIds,
                    'renewal_cxc_id' => $renewalCxcId,
                    'renewal_cxc_ids' => $renewalCxcIds,
                    'docReferencia' => $docText,
                    'descripcion' => $validated['descripcionPago'] ?? 'Pago y facturación de CXC',
                ];

                return $this->cxcService->procesarPagoGrupo($paymentCxcIds, $paymentData, $request->file('comprobante'));
            });

            $msg = $realizarCobro
                ? 'Factura y cobro registrados correctamente. ' . $this->paymentBreakdownMessage($paymentResult ?? [])
                : 'Documento de factura/boleta asignado correctamente. Estado actualizado a FACTURADO.';

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => true, 'message' => $msg, 'result' => $paymentResult]);
            }
        } catch (\Throwable $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return redirect()->route('modules.cuentasporcobrar', ['tab' => 'servicios'])->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('modules.cuentasporcobrar', ['tab' => 'servicios'])
            ->with('success', $msg);
    }

    public function cobrarServicios(Request $request)
    {
        $isWithholdingCompletion = $request->boolean('completar_detraccion_pendiente');
        $isPartialPaymentCompletion = $request->boolean('completar_pago_parcial');
        $request->merge([
            'payment_currency_mode' => $request->input('payment_currency_mode', 'same'),
        ]);

        if (!$request->filled('moneda_idmoneda')) {
            $cxcId = $request->input('idcuentasPorCobrar');

            if ($cxcId) {
                $cxcMonedaId = DB::table('cuentasporcobrar')
                    ->where('idcuentasPorCobrar', $cxcId)
                    ->value('moneda_idmoneda');

                if ($cxcMonedaId) {
                    $request->merge(['moneda_idmoneda' => $cxcMonedaId]);
                }
            }

            if (!$request->filled('moneda_idmoneda') && !empty($request->input('servicio_ids'))) {
                $firstServiceId = collect($request->input('servicio_ids'))
                    ->filter(fn($id) => $id !== null && $id !== '')
                    ->first();

                if ($firstServiceId) {
                    $serviceMonedaId = DB::table('serviciocliente')
                        ->where('idservicioCliente', $firstServiceId)
                        ->value('moneda_idmoneda');

                    if ($serviceMonedaId) {
                        $request->merge(['moneda_idmoneda' => $serviceMonedaId]);
                    }
                }
            }
        }

        $validated = $request->validate([
            'cxc_ids' => ['nullable', 'array'],
            'cxc_ids.*' => ['integer', 'exists:cuentasporcobrar,idcuentasPorCobrar'],
            'partial_original_cxc_ids' => ['nullable', 'array', 'max:500'],
            'partial_original_cxc_ids.*' => ['integer', 'distinct', 'exists:cuentasporcobrar,idcuentasPorCobrar'],
            'period_cxc_ids' => ['nullable', 'array', 'max:50'],
            'period_cxc_ids.*' => ['integer', 'distinct', 'exists:cuentasporcobrar,idcuentasPorCobrar'],
            'nota_credito_ids' => ['nullable', 'array', 'max:1'],
            'nota_credito_ids.*' => ['integer', 'distinct', 'exists:nota_credito,idnotacredito'],
            'servicio_ids' => ['nullable', 'array'],
            'servicio_ids.*' => ['integer', 'distinct', 'exists:serviciocliente,idservicioCliente'],
            'idcuentasPorCobrar' => [$isWithholdingCompletion || $isPartialPaymentCompletion ? 'required' : 'nullable', 'exists:cuentasporcobrar,idcuentasPorCobrar'],
            'completar_detraccion_pendiente' => ['nullable', 'boolean'],
            'completar_pago_parcial' => ['nullable', 'boolean'],
            'tipoCobro_idtipoCobros' => [$isWithholdingCompletion || $isPartialPaymentCompletion ? 'nullable' : 'required', 'integer'],
            'formaPago_idformaPago' => [$isWithholdingCompletion ? 'nullable' : 'required', 'integer', 'exists:formapago,idformaPago'],
            'entidadBancaria_identidadBancaria' => [$isWithholdingCompletion ? 'nullable' : 'required', 'integer', 'exists:entidadbancaria,identidadBancaria'],
            'modo_pago' => [$isWithholdingCompletion ? 'nullable' : 'required', 'in:total,parcial'],
            'usar_saldo_favor' => ['sometimes', 'boolean'],
            'monto_saldo_favor' => ['nullable', 'numeric', 'min:0'],
            'moneda_idmoneda' => ['nullable', 'integer', 'exists:moneda,idmoneda'],
            'currency_selector' => ['nullable', 'integer', 'exists:moneda,idmoneda'],
            'payment_currency_mode' => ['nullable', 'in:same,other'],
            'monto_cancelado' => ['nullable', 'numeric', 'min:0'],
            'precio_convertido' => ['nullable', 'numeric', 'gt:0'],
            'tipo_cambio' => ['nullable', 'numeric', 'gt:0'],
            'withholding_decision' => ['nullable', 'in:paid,pending,full_pending'],
            'montoPago' => [
                $isPartialPaymentCompletion ? 'required' : 'nullable',
                'numeric',
                $isPartialPaymentCompletion ? 'gt:0' : 'min:0',
            ],
            'monto_factura' => ['nullable', 'numeric', 'min:0'],
            'canCuotas' => ['nullable', 'integer', 'min:1', 'max:60'],
            'cuotas_montos' => ['nullable', 'array'],
            'cuotas_montos.*' => ['nullable', 'numeric', 'gt:0'],
            'cuotas_fechas' => ['nullable', 'array'],
            'cuotas_fechas.*' => ['nullable', 'date'],
            'fechaPago' => [$isPartialPaymentCompletion ? 'required' : 'nullable', 'date'],
            'fechaFin' => ['nullable', 'date'],
            'adelanto_meses' => ['nullable', 'integer', 'min:1', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:100'],
            'docReferencia' => ['nullable', 'string', 'max:15'],
            'comprobante' => [$isWithholdingCompletion ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'withholding_proof' => ['required_if:withholding_decision,paid', 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'withholding_fecha' => ['nullable', 'date'],
            'withholding_description' => ['nullable', 'string', 'max:100'],
        ]);

        $validated['moneda_idmoneda'] = $request->input('moneda_idmoneda') ?: ($validated['moneda_idmoneda'] ?? null);

        if ($validated['moneda_idmoneda'] === null && $request->filled('idcuentasPorCobrar')) {
            $cxcMonedaId = DB::table('cuentasporcobrar')
                ->where('idcuentasPorCobrar', $request->input('idcuentasPorCobrar'))
                ->value('moneda_idmoneda');

            if ($cxcMonedaId) {
                $validated['moneda_idmoneda'] = (int) $cxcMonedaId;
            }
        }

        if ($validated['moneda_idmoneda'] === null && !empty($request->input('servicio_ids'))) {
            $firstServiceId = collect($request->input('servicio_ids'))
                ->filter(fn($id) => $id !== null && $id !== '')
                ->first();

            if ($firstServiceId) {
                $serviceMonedaId = DB::table('serviciocliente')
                    ->where('idservicioCliente', $firstServiceId)
                    ->value('moneda_idmoneda');

                if ($serviceMonedaId) {
                    $validated['moneda_idmoneda'] = (int) $serviceMonedaId;
                }
            }
        }

        $partialPaymentOriginalGross = null;
        try {
            if ($isWithholdingCompletion) {
                $pendingCxcIds = array_values(array_unique(array_merge(
                    [(int) $validated['idcuentasPorCobrar']],
                    array_map('intval', $validated['cxc_ids'] ?? [])
                )));
                $result = count($pendingCxcIds) > 1
                    ? $this->cxcService->registrarDetraccionesPendientes(
                        $pendingCxcIds,
                        $request->file('comprobante'),
                        $validated['servicio_ids'] ?? [],
                        $validated['descripcion'] ?? '',
                        $validated['fechaPago'] ?? null
                    )
                    : $this->cxcService->registrarDetraccionPendiente(
                        $pendingCxcIds[0],
                        $request->file('comprobante'),
                        $validated['servicio_ids'] ?? [],
                        $validated['descripcion'] ?? '',
                        $validated['fechaPago'] ?? null,
                        true
                    );
                $message = 'Comprobante registrado. La ' . $result['tipoDeduccion'] . ' quedó pagada y la cuenta fue cancelada.';

                if ($request->wantsJson() || $request->ajax()) {
                    return response()->json(['success' => true, 'message' => $message, 'result' => $result]);
                }

                return redirect()->route('modules.cuentasporcobrar', ['tab' => 'servicios'])
                    ->with('success', $message);
            }

            $file = $request->file('comprobante');

            $currentCxcIds = $validated['cxc_ids'] ?? [];
            if (empty($currentCxcIds) && !empty($validated['idcuentasPorCobrar'])) {
                $currentCxcIds = [(int) $validated['idcuentasPorCobrar']];
            }

            if ($isPartialPaymentCompletion) {
                $partialCxcIds = array_values(array_unique(array_map('intval', array_merge(
                    [(int) $validated['idcuentasPorCobrar']],
                    $currentCxcIds
                ))));
                $partialCxcs = DB::table('cuentasporcobrar')
                    ->whereIn('idcuentasPorCobrar', $partialCxcIds)
                    ->lockForUpdate()
                    ->get([
                        'idcuentasPorCobrar',
                        'cliente_idcliente',
                        'moneda_idmoneda',
                        'fechaCancelacion',
                        'docReferencia',
                        'estado',
                        'montoOriginal',
                        'montoActual',
                    ]);
                $allowedPartialGroupStates = [
                    'pendiente pago parcial',
                    'facturado',
                    'vencido',
                    'pendiente',
                    '1',
                    '2',
                ];
                if (
                    $partialCxcs->count() !== count($partialCxcIds)
                    || $partialCxcs->contains(fn($cxc): bool =>
                        !in_array(mb_strtolower(trim((string) $cxc->estado), 'UTF-8'), $allowedPartialGroupStates, true)
                        || trim((string) $cxc->docReferencia) === ''
                        || (float) $cxc->montoActual <= 0
                    )
                ) {
                    abort(422, 'La cuenta ya no tiene un saldo pendiente de pago parcial.');
                }
                $partialOriginalCxcIds = array_values(array_unique(array_map('intval', array_merge(
                    $partialCxcIds,
                    $validated['partial_original_cxc_ids'] ?? []
                ))));
                $partialOriginalCxcs = DB::table('cuentasporcobrar')
                    ->whereIn('idcuentasPorCobrar', $partialOriginalCxcIds)
                    ->get([
                        'idcuentasPorCobrar',
                        'cliente_idcliente',
                        'moneda_idmoneda',
                        'fechaCancelacion',
                        'docReferencia',
                        'montoOriginal',
                    ]);
                $firstPartialCxc = $partialCxcs->first();
                if (
                    !$firstPartialCxc
                    || $partialOriginalCxcs->count() !== count($partialOriginalCxcIds)
                    || $partialOriginalCxcs->contains(fn($cxc): bool =>
                        (string) $cxc->cliente_idcliente !== (string) $firstPartialCxc->cliente_idcliente
                        || (int) $cxc->moneda_idmoneda !== (int) $firstPartialCxc->moneda_idmoneda
                        || substr((string) $cxc->fechaCancelacion, 0, 10) !== substr((string) $firstPartialCxc->fechaCancelacion, 0, 10)
                        || trim((string) $cxc->docReferencia) !== trim((string) $firstPartialCxc->docReferencia)
                    )
                ) {
                    abort(422, 'No se pudo verificar el monto original de las cuentas del pago parcial.');
                }
                $hasPriorPayment = DB::table('detallecxc')
                    ->whereIn('cuentasPorCobrar_idcuentasPorCobrar', $partialOriginalCxcIds)
                    ->where('estado', '1')
                    ->exists();
                if (!$hasPriorPayment) {
                    abort(422, 'No se encontró un abono anterior para completar este pago parcial.');
                }
                $partialPaymentOriginalGross = round((float) $partialOriginalCxcs->sum(
                    fn($cxc): float => max(0, (float) $cxc->montoOriginal)
                ), 2);
                $partialCurrentBalance = round((float) $partialCxcs->sum(
                    fn($cxc): float => max(0, (float) $cxc->montoActual)
                ), 2);
                if ($partialPaymentOriginalGross + 0.005 < $partialCurrentBalance) {
                    abort(422, 'El monto original de las cuentas no puede ser menor que el saldo pendiente.');
                }
                $currentCxcIds = $partialCxcIds;
            }

            if (empty($currentCxcIds) && !empty($validated['servicio_ids'])) {
                $serviceIds = array_values(array_map('intval', $validated['servicio_ids']));
                $services = DB::table('serviciocliente as sc')
                    ->whereIn('sc.idservicioCliente', $serviceIds)
                    ->get();
                $firstService = $services->first();

                if ($firstService) {
                    $groupTotal = round((float) $services->sum('monto'), 2);
                    $periodRange = Carbon::parse($firstService->fechaInicio)->format('d/m/Y') . ' a ' . Carbon::parse($firstService->fecheVencimiento)->format('d/m/Y');

                    $cxcId = DB::table('cuentasporcobrar')
                        ->where('cliente_idcliente', $firstService->cliente_idcliente)
                        ->whereIn('estado', ['PENDIENTE', 'VENCIDO', 'FACTURADO', 'NUEVO', 'PagoCredito', 'Pendiente cuota crédito', 'Pendiente Pago parcial', 'Pendiente a credito', '1', '2'])
                        ->where('descripcion', 'like', '%' . $periodRange . '%')
                        ->orderByDesc('fechaRegistro')
                        ->value('idcuentasPorCobrar');

                    if (!$cxcId) {
                        $cxcId = $this->cxcService->crearCobroDesdeServicio([
                            'cliente_idcliente' => $firstService->cliente_idcliente,
                            'montoServicioBase' => $groupTotal,
                            'moneda_idmoneda' => $firstService->moneda_idmoneda,
                            'descripcion' => 'CXC ' . $periodRange,
                            'vehiculo_placa' => $firstService->vehiculo_placa ?? '',
                            'idservicioCliente' => $firstService->idservicioCliente,
                        ]);
                    }
                    if ($cxcId) {
                        $currentCxcIds = [$cxcId];
                    }
                }
            }

            if (empty($currentCxcIds) && $request->input('cliente_idcliente')) {
                $cxcId = DB::table('cuentasporcobrar')
                    ->where('cliente_idcliente', $request->input('cliente_idcliente'))
                    ->whereIn('estado', ['PENDIENTE', 'VENCIDO', 'FACTURADO', 'NUEVO', 'PagoCredito', 'Pendiente cuota crédito', 'Pendiente Pago parcial', 'Pendiente a credito', '1', '2'])
                    ->orderByDesc('fechaRegistro')
                    ->value('idcuentasPorCobrar');
                if ($cxcId) {
                    $currentCxcIds = [$cxcId];
                }
            }

            if (empty($currentCxcIds)) {
                throw new \RuntimeException('Debe especificar al menos una cuenta por cobrar válida.');
            }
            $periodCxcIds = array_values(array_diff(
                array_map('intval', $validated['period_cxc_ids'] ?? []),
                array_map('intval', $currentCxcIds)
            ));

            $serviceIds = !empty($validated['servicio_ids'])
                ? array_values(array_map('intval', $validated['servicio_ids']))
                : [];
            $paymentData = [
                'entidadBancaria_identidadBancaria' => $validated['entidadBancaria_identidadBancaria'],
                'formaPago_idformaPago' => $validated['formaPago_idformaPago'],
                'tipoCobro_idtipoCobros' => $validated['tipoCobro_idtipoCobros'] ?? null,
                'moneda_idmoneda' => $validated['moneda_idmoneda'],
                'currency_selector' => $validated['currency_selector'] ?? null,
                'payment_currency_mode' => $validated['payment_currency_mode'] ?? 'same',
                'monto_cancelado' => $validated['monto_cancelado'] ?? null,
                'tipo_cambio' => $validated['tipo_cambio'] ?? null,
                'modo_pago' => $validated['modo_pago'],
                'montoPago' => $validated['montoPago'] ?? null,
                'monto_factura' => $validated['monto_factura'] ?? null,
                'usar_saldo_favor' => $request->boolean('usar_saldo_favor'),
                'monto_saldo_favor' => $validated['monto_saldo_favor'] ?? null,
                'nota_credito_ids' => $validated['nota_credito_ids'] ?? [],
                'withholding_decision' => $validated['withholding_decision'] ?? null,
                'withholding_proof' => $request->file('withholding_proof'),
                'withholding_fecha' => $validated['withholding_fecha'] ?? null,
                'withholding_description' => $validated['withholding_description'] ?? '',
                'aplicar_deduccion' => !$isPartialPaymentCompletion,
                'completar_pago_parcial' => $isPartialPaymentCompletion,
                'partial_payment_original_gross' => $partialPaymentOriginalGross,
                'canCuotas' => $validated['canCuotas'] ?? null,
                'cuotas_montos' => $request->input('cuotas_montos', []),
                'cuotas_fechas' => $request->input('cuotas_fechas', []),
                'fechaPago' => $validated['fechaPago'] ?? now()->format('Y-m-d'),
                'fechaFin' => $validated['fechaFin'] ?? null,
                'adelanto_meses' => $validated['adelanto_meses'] ?? null,
                'docReferencia' => $validated['docReferencia'] ?? '',
                'descripcion' => $validated['descripcion'] ?? '',
                'servicio_ids' => $serviceIds,
                'period_cxc_ids' => $periodCxcIds,
                'renewal_cxc_id' => (int) ($validated['idcuentasPorCobrar'] ?? ($currentCxcIds[0] ?? 0)),
                'renewal_cxc_ids' => array_map('intval', $currentCxcIds),
            ];
            $paymentResult = DB::transaction(function () use ($currentCxcIds, $periodCxcIds, $serviceIds, $paymentData, $file): array {
                if ($serviceIds !== []) {
                    $isolatedReceivables = $this->cxcService->isolateSelectedServiceReceivables($currentCxcIds, $serviceIds);
                    $paymentData['renewal_cxc_id'] = (int) ($isolatedReceivables['renewal_cxc_id'] ?: ($currentCxcIds[0] ?? 0));
                    $paymentData['renewal_cxc_ids'] = $isolatedReceivables['renewal_cxc_ids'] ?? $isolatedReceivables['cxc_ids'];

                    return $this->cxcService->procesarPagoGrupo(
                        array_values(array_unique(array_merge($isolatedReceivables['cxc_ids'], $periodCxcIds))),
                        $paymentData,
                        $file
                    );
                }

                return $this->cxcService->procesarPagoGrupo(
                    array_values(array_unique(array_merge($currentCxcIds, $periodCxcIds))),
                    $paymentData,
                    $file
                );
            });
            $msg = 'Pago registrado correctamente. ' . $this->paymentBreakdownMessage($paymentResult);

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => true, 'message' => $msg, 'result' => $paymentResult]);
            }

            return redirect()->route('modules.cuentasporcobrar', ['tab' => 'servicios'])
                ->with('success', $msg);

        } catch (\Throwable $exception) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
            }

            return redirect()->route('modules.cuentasporcobrar', ['tab' => 'servicios'])
                ->withInput()->with('error', $exception->getMessage());
        }
    }

    public function getNotasCreditoCliente(string $clienteId): JsonResponse
    {
        $validated = request()->validate([
            'moneda_idmoneda' => ['required', 'integer', 'exists:moneda,idmoneda'],
        ]);

        if (!DB::table('cliente')->where('idcliente', $clienteId)->exists()) {
            return response()->json(['success' => false, 'message' => 'Cliente no encontrado.'], 404);
        }

        return response()->json([
            'success' => true,
            'notas_credito' => $this->cxcService->notasCreditoDisponibles(
                $clienteId,
                (int) $validated['moneda_idmoneda']
            ),
        ]);
    }

    public function getDeudasCliente(string $clienteId): JsonResponse
    {
        $currentCxcId = request()->query('current_cxc_id');
        $currentCxc = is_numeric($currentCxcId)
            ? DB::table('cuentasporcobrar')
                ->where('idcuentasPorCobrar', (int) $currentCxcId)
                ->where('cliente_idcliente', $clienteId)
                ->first(['idcuentasPorCobrar', 'descripcion', 'fechaCancelacion', 'moneda_idmoneda'])
            : null;
        $currentPeriodEnd = null;
        if ($currentCxc?->fechaCancelacion) {
            $currentPeriodEnd = Carbon::parse($currentCxc->fechaCancelacion)->startOfDay();
        }
        if (!$currentPeriodEnd && preg_match('/\d{2}[\/.-]\d{2}[\/.-]\d{4}\s+(?:a|al|-)\s+(\d{2}[\/.-]\d{2}[\/.-]\d{4})/i', (string) ($currentCxc->descripcion ?? ''), $periodMatch)) {
            try {
                $currentPeriodEnd = Carbon::createFromFormat('d/m/Y', str_replace(['.', '-'], '/', $periodMatch[1]))->startOfDay();
            } catch (\Exception) {
                $currentPeriodEnd = null;
            }
        }
        $serviceIds = collect(request()->query('service_ids', []))
            ->filter(fn($id) => is_numeric($id))
            ->map(fn($id) => (int) $id)
            ->values();

        $currentServices = $serviceIds->isNotEmpty()
            ? DB::table('serviciocliente')
                ->where('cliente_idcliente', $clienteId)
                ->whereIn('idservicioCliente', $serviceIds->all())
                ->get(['idservicioCliente', 'almacen_idalmacen', 'vehiculo_placa', 'moneda_idmoneda', 'fecheVencimiento'])
            : collect();
        if (!$currentPeriodEnd && $currentServices->isNotEmpty()) {
            $serviceEnd = $currentServices
                ->pluck('fecheVencimiento')
                ->filter()
                ->sortDesc()
                ->first();
            if ($serviceEnd) {
                $currentPeriodEnd = Carbon::parse($serviceEnd)->startOfDay();
            }
        }
        $hasCurrentOrFuturePeriod = !$currentCxcId || $currentCxcId === '0'
            ? true
            : $currentPeriodEnd !== null
            && $currentPeriodEnd->copy()->startOfMonth()->gte(Carbon::today()->startOfMonth());
        $currencyId = $currentCxc
            ? (int) $currentCxc->moneda_idmoneda
            : null;
        if (!$currencyId && $currentServices->pluck('moneda_idmoneda')->filter()->unique()->count() === 1) {
            $currencyId = (int) $currentServices->first()->moneda_idmoneda;
        }
        $query = DB::table('cuentasporcobrar as c')
            ->select('c.idcuentasPorCobrar', 'c.cliente_idcliente', 'c.descripcion', 'c.montoActual', 'c.montoOriginal', 'c.fechaRegistro', 'c.fechaCancelacion', 'c.estado', 'c.docReferencia', 'c.moneda_idmoneda')
            ->where('c.cliente_idcliente', $clienteId)
            ->whereIn('c.estado', ['1', '2', '4', 'NUEVO', 'PagoCredito', 'Pendiente cuota crédito', 'PENDIENTE', 'FACTURADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito'])
            ->where('c.montoActual', '>', 0)
            ->orderByDesc('c.fechaCancelacion')
            ->orderByDesc('c.idcuentasPorCobrar');

        if ($currentCxcId && $currentCxcId !== '0') {
            $query->where('c.idcuentasPorCobrar', '!=', $currentCxcId);
        }

        $cxcs = $query->get();
        $cxcs = $cxcs->filter(function ($cxc) use ($currencyId, $currentPeriodEnd) {
            if ($currencyId !== null && (int) $cxc->moneda_idmoneda !== $currencyId) {
                return false;
            }
            $candidateEnd = null;
            if ($cxc->fechaCancelacion) {
                $candidateEnd = Carbon::parse($cxc->fechaCancelacion)->startOfDay();
            } elseif (preg_match('/\d{2}[\/\.-]\d{2}[\/\.-]\d{4}\s+(?:a|al|-)\s+(\d{2}[\/\.-]\d{2}[\/.-]\d{4})/i', (string) ($cxc->descripcion ?? ''), $match)) {
                try {
                    $candidateEnd = Carbon::createFromFormat(
                        'd/m/Y',
                        str_replace(['.', '-'], '/', $match[1])
                    )->startOfDay();
                } catch (\Exception) {
                    $candidateEnd = null;
                }
            }
            $periodCutoff = $currentPeriodEnd ?? Carbon::today()->startOfDay();

            return $candidateEnd !== null
                && $candidateEnd->startOfMonth()->lt($periodCutoff->copy()->startOfMonth());
        })->values();

        $serviceIdsByCxc = [];
        if ($cxcs->isNotEmpty()) {
            $cxcIds = $cxcs->pluck('idcuentasPorCobrar')->all();
            if (Schema::hasTable('cuentasporcobrar_serviciocliente')) {
                $serviceIdsByCxc = DB::table('cuentasporcobrar_serviciocliente')
                    ->whereIn('cuentasPorCobrar_idcuentasPorCobrar', $cxcIds)
                    ->get(['cuentasPorCobrar_idcuentasPorCobrar', 'servicioCliente_idservicioCliente'])
                    ->groupBy('cuentasPorCobrar_idcuentasPorCobrar')
                    ->map(fn($links): array => $links->pluck('servicioCliente_idservicioCliente')
                        ->map(fn($serviceId): int => (int) $serviceId)
                        ->all())
                    ->all();
            }

            foreach ($cxcs as $cxc) {
                if (preg_match('/SC-([0-9,]+)/', (string) ($cxc->descripcion ?? ''), $matches) === 1) {
                    $serviceIdsByCxc[$cxc->idcuentasPorCobrar] = array_merge(
                        $serviceIdsByCxc[$cxc->idcuentasPorCobrar] ?? [],
                        array_map('intval', explode(',', $matches[1]))
                    );
                }
                $serviceIdsByCxc[$cxc->idcuentasPorCobrar] = array_values(array_unique(
                    $serviceIdsByCxc[$cxc->idcuentasPorCobrar] ?? []
                ));
            }
        }
        $serviceIds = collect($serviceIdsByCxc)->flatten()->unique()->values();
        $platesByServiceId = $serviceIds->isNotEmpty()
            ? DB::table('serviciocliente')
                ->whereIn('idservicioCliente', $serviceIds->all())
                ->pluck('vehiculo_placa', 'idservicioCliente')
            : collect();
        $platesByCxc = collect($serviceIdsByCxc)->map(
            fn(array $ids): string => collect($ids)
                ->map(fn(int $id): ?string => $platesByServiceId->get($id))
                ->filter(fn(?string $plate): bool => trim((string) $plate) !== '')
                ->unique()
                ->implode(', ')
        );

        $paymentDatesByCxc = $cxcs->isNotEmpty()
            ? DB::table('detallecxc')
                ->whereIn('cuentasPorCobrar_idcuentasPorCobrar', $cxcs->pluck('idcuentasPorCobrar')->all())
                ->where('estado', '1')
                ->whereNotNull('fechaPago')
                ->selectRaw('cuentasPorCobrar_idcuentasPorCobrar as cxc_id, MAX(fechaPago) as fecha_pago')
                ->groupBy('cuentasPorCobrar_idcuentasPorCobrar')
                ->pluck('fecha_pago', 'cxc_id')
            : collect();

        $cxcs->transform(function ($cxc) use ($paymentDatesByCxc, $platesByCxc) {
            $desc = (string) ($cxc->descripcion ?? '');
            $range = '';
            if (preg_match('/(\d{2}[\/\.-]\d{2}[\/\.-]\d{4})\s+(?:a|al|-)\s+(\d{2}[\/\.-]\d{2}[\/\.-]\d{4})/i', $desc, $m)) {
                $range = $m[1] . ' a ' . $m[2];
                $cxc->periodo_inicio = $m[1];
                $cxc->periodo_fin = $m[2];
            }
            $cxc->rango_display = $range ?: $desc;
            $cxc->descripcion = CxcService::visibleServiceComment($desc);
            $periodEndDate = null;
            if ($cxc->fechaCancelacion) {
                try {
                    $periodEndDate = Carbon::parse($cxc->fechaCancelacion)->locale('es');
                } catch (\Exception) {
                    $periodEndDate = null;
                }
            }
            if (!$periodEndDate && !empty($cxc->periodo_fin)) {
                try {
                    $periodEndDate = Carbon::createFromFormat(
                        'd/m/Y',
                        str_replace(['.', '-'], '/', $cxc->periodo_fin)
                    )->locale('es');
                } catch (\Exception) {
                    $periodEndDate = null;
                }
            }
            if (!$periodEndDate && $cxc->fechaCancelacion) {
                $fechaCancelacion = trim((string) $cxc->fechaCancelacion);
                try {
                    $periodEndDate = preg_match('/^\d{2}[\/.-]\d{2}[\/.-]\d{4}$/', $fechaCancelacion)
                        ? Carbon::createFromFormat('d/m/Y', str_replace(['.', '-'], '/', $fechaCancelacion))->locale('es')
                        : Carbon::parse($fechaCancelacion)->locale('es');
                } catch (\Exception) {
                    $periodEndDate = null;
                }
            }
            $cxc->mes_display = $periodEndDate
                ? ucfirst($periodEndDate->monthName) . ' ' . $periodEndDate->year
                : 'Periodo anterior';
            $cxc->placa_display = $platesByCxc->get($cxc->idcuentasPorCobrar);
            $cxc->montoActualDisplay = number_format((float) $cxc->montoActual, 2);
            $paymentDate = $paymentDatesByCxc->get($cxc->idcuentasPorCobrar);
            $cxc->fechaDisplay = $paymentDate ? Carbon::parse($paymentDate)->format('d/m/Y') : '-';

            return $cxc;
        });

        $cliente = DB::table('cliente')
            ->where('idcliente', $clienteId)
            ->select('detraccion')
            ->first();

        $esRetencion = $cliente && (string) ($cliente->detraccion ?? '') === '1';
        $saldoFavorDisponible = $currencyId
            ? $this->cxcService->saldoFavorDisponible($clienteId, $currencyId)
            : 0.0;

        return response()->json([
            'success' => true,
            'cxcs' => $cxcs,
            'can_select_previous_debts' => $hasCurrentOrFuturePeriod,
            'es_retencion' => $esRetencion,
            'saldo_a_favor_disponible' => $saldoFavorDisponible,
            'moneda_idmoneda' => $currencyId,
        ]);
    }

    public function revertirPago(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'motivo' => ['required', 'string', 'max:255'],
            'cxc_ids' => ['sometimes', 'array', 'min:1', 'max:50'],
            'cxc_ids.*' => ['integer', 'distinct', 'exists:cuentasporcobrar,idcuentasPorCobrar'],
            'servicio_id' => ['sometimes', 'integer', 'exists:serviciocliente,idservicioCliente'],
        ]);

        try {
            $usuario = (string) $request->session()->get('erp_auth.usuario', 'anonimo');
            if (isset($validated['servicio_id'], $validated['cxc_ids'])) {
                throw new \RuntimeException('No se puede combinar la anulación individual con la anulación de un grupo.');
            }
            if (isset($validated['cxc_ids']) && !in_array((int) $id, array_map('intval', $validated['cxc_ids']), true)) {
                throw new \RuntimeException('La cuenta principal debe formar parte del grupo que se va a revertir.');
            }
            $result = isset($validated['cxc_ids'])
                ? $this->cxcService->revertirPagoCanceladoGrupo(
                    $validated['cxc_ids'],
                    $usuario,
                    $validated['motivo']
                )
                : $this->cxcService->revertirPagoCancelado(
                    $id,
                    $usuario,
                    $validated['motivo'],
                    isset($validated['servicio_id']) ? (int) $validated['servicio_id'] : null
                );

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Pago revertido correctamente. '

                        . 'La cuenta volvió a estado ' . ($result['estado'] === '4' ? 'VENCIDO.' : 'PENDIENTE.'),
                    'result' => $result,
                ]);
            }

            return redirect()->route('modules.cuentasporcobrar', ['tab' => 'servicios'])
                ->with('success', 'Pago revertido correctamente. '

                    . 'La cuenta volvió a estado ' . ($result['estado'] === '4' ? 'VENCIDO.' : 'PENDIENTE.'));
        } catch (\Throwable $exception) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
            }

            return redirect()->route('modules.cuentasporcobrar', ['tab' => 'servicios'])
                ->with('error', $exception->getMessage());
        }
    }

    public function updateServicePrice(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'monto' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'mantener_precio_siguiente' => ['sometimes', 'boolean'],
            'cuentasPorCobrar_idcuentasPorCobrar' => ['nullable', 'integer', 'min:1'],
        ]);

        $amount = round((float) $validated['monto'], 2);
        $keepPriceForNextPeriods = (bool) ($validated['mantener_precio_siguiente'] ?? true);
        $requestedCxcId = isset($validated['cuentasPorCobrar_idcuentasPorCobrar'])
            ? (int) $validated['cuentasPorCobrar_idcuentasPorCobrar']
            : null;
        $modMonto = $keepPriceForNextPeriods
            ? 'No mantener precio pasado'
            : 'Si mantener precio pasado';
        $user = (string) ($request->hasSession()
            ? $request->session()->get('erp_auth.usuario', 'anonimo')
            : 'anonimo');
        $service = DB::transaction(function () use (
            $id,
            $amount,
            $keepPriceForNextPeriods,
            $requestedCxcId,
            $modMonto,
            $user
        ): object {
            $service = DB::table('serviciocliente')
                ->where('idservicioCliente', $id)
                ->lockForUpdate()
                ->first([
                    'idservicioCliente',
                    'cliente_idcliente',
                    'monto',
                    'moneda_idmoneda',
                    'fecheVencimiento',
                    'docReferencia',
                ]);

            if (!$service) {
                abort(404, 'No se encontró el servicio del vehículo.');
            }

            $charges = DB::table('cuentasporcobrar')
                ->where('cliente_idcliente', $service->cliente_idcliente)
                ->where('moneda_idmoneda', $service->moneda_idmoneda)
                ->orderBy('idcuentasPorCobrar')
                ->lockForUpdate()
                ->get();
            $serviceIdsByCharge = [];
            $serviceAmountsByCharge = [];
            $linkedAmountsByCharge = [];
            if (Schema::hasTable('cuentasporcobrar_serviciocliente')) {
                $serviceIdsByCharge = DB::table('cuentasporcobrar_serviciocliente')
                    ->whereIn('cuentasPorCobrar_idcuentasPorCobrar', $charges->pluck('idcuentasPorCobrar'))
                    ->get(['cuentasPorCobrar_idcuentasPorCobrar', 'servicioCliente_idservicioCliente'])
                    ->groupBy('cuentasPorCobrar_idcuentasPorCobrar')
                    ->map(fn($links): array => $links->pluck('servicioCliente_idservicioCliente')
                        ->map(fn($serviceId): int => (int) $serviceId)
                        ->all())
                    ->all();
                $linkedAmountsByCharge = DB::table('cuentasporcobrar_serviciocliente')
                    ->whereIn('cuentasPorCobrar_idcuentasPorCobrar', $charges->pluck('idcuentasPorCobrar'))
                    ->get([
                        'cuentasPorCobrar_idcuentasPorCobrar',
                        'servicioCliente_idservicioCliente',
                        'montoServicio',
                    ])
                    ->groupBy('cuentasPorCobrar_idcuentasPorCobrar')
                    ->map(fn($links): array => $links->mapWithKeys(fn($link): array => [
                        (int) $link->servicioCliente_idservicioCliente => $link->montoServicio === null
                            ? null
                            : (float) $link->montoServicio,
                    ])->all())
                    ->all();
            }
            foreach ($charges as $charge) {
                if (preg_match('/SC-([0-9,]+)/', (string) ($charge->descripcion ?? ''), $matches) === 1) {
                    $serviceIdsByCharge[$charge->idcuentasPorCobrar] = array_merge(
                        $serviceIdsByCharge[$charge->idcuentasPorCobrar] ?? [],
                        array_map('intval', explode(',', $matches[1]))
                    );
                }
                $serviceIdsByCharge[$charge->idcuentasPorCobrar] = array_values(array_unique(
                    $serviceIdsByCharge[$charge->idcuentasPorCobrar] ?? []
                ));
            }
            $linkedCharges = $charges->filter(
                fn($charge): bool => in_array($id, $serviceIdsByCharge[$charge->idcuentasPorCobrar] ?? [], true)
            )->values();
            if (
                $requestedCxcId !== null
                && !$linkedCharges->contains(
                    fn($charge): bool => (int) $charge->idcuentasPorCobrar === $requestedCxcId
                )
            ) {
                abort(422, 'La cuenta por cobrar seleccionada ya no está vinculada a este servicio.');
            }
            foreach ($linkedCharges as $charge) {
                $linkedServiceIds = $serviceIdsByCharge[$charge->idcuentasPorCobrar] ?? [];
                $serviceAmountsById = DB::table('serviciocliente')
                    ->whereIn('idservicioCliente', $linkedServiceIds)
                    ->pluck('monto', 'idservicioCliente')
                    ->map(fn($serviceAmount): float => (float) $serviceAmount)
                    ->all();
                $serviceAmountsByCharge[$charge->idcuentasPorCobrar] = $serviceAmountsById;
                if ($this->cxcService->isMonthlyProrationCharge($charge, $linkedServiceIds, $serviceAmountsById)) {
                    if (Schema::hasColumn('cuentasporcobrar', 'mod_monto')) {
                        DB::table('cuentasporcobrar')
                            ->where('idcuentasPorCobrar', $charge->idcuentasPorCobrar)
                            ->update(['mod_monto' => CxcService::MONTHLY_PRORATION_PRICE_MARKER]);
                    }
                    $charge->mod_monto = CxcService::MONTHLY_PRORATION_PRICE_MARKER;
                }
            }
            $periodEndForCharge = static function (object $charge): string {
                if (preg_match(
                    '/\d{2}\/\d{2}\/\d{4}\s+a\s+(\d{2}\/\d{2}\/\d{4})/',
                    (string) ($charge->descripcion ?? ''),
                    $matches
                ) === 1) {
                    return Carbon::createFromFormat('d/m/Y', $matches[1])->toDateString();
                }

                return substr((string) ($charge->fechaCancelacion ?? ''), 0, 10);
            };
            $servicePeriodEnd = $service->fecheVencimiento
                ? Carbon::parse($service->fecheVencimiento)->toDateString()
                : '';
            $currentPeriodCharges = $requestedCxcId !== null
                ? $linkedCharges->filter(
                    fn($charge): bool => (int) $charge->idcuentasPorCobrar === $requestedCxcId
                )
                : $linkedCharges->filter(
                    fn($charge): bool => $periodEndForCharge($charge) === $servicePeriodEnd
                );
            $currentPeriodChargeIds = $currentPeriodCharges
                ->pluck('idcuentasPorCobrar')
                ->map(fn($chargeId): int => (int) $chargeId)
                ->all();
            $isMonthlyProrationCharge = static fn(object $charge): bool =>
                trim((string) ($charge->mod_monto ?? '')) === CxcService::MONTHLY_PRORATION_PRICE_MARKER;

            foreach ($currentPeriodCharges as $charge) {
                if ($periodEndForCharge($charge) < Carbon::today()->toDateString()) {
                    continue;
                }

                if (in_array(
                    mb_strtoupper(trim((string) $charge->estado), 'UTF-8'),
                    ['3', 'CANCELADO', 'CANCELADO DETRACCIÓN'],
                    true
                )) {
                    abort(422, 'No se puede editar el precio de un servicio con la cuenta del periodo cancelada.');
                }
            }

            if ($keepPriceForNextPeriods) {
                DB::table('serviciocliente')
                    ->where('idservicioCliente', $id)
                    ->update(['monto' => $amount]);
            }

            $chargesToReprice = $keepPriceForNextPeriods ? $linkedCharges : $currentPeriodCharges;
            foreach ($currentPeriodCharges as $charge) {
                if ($isMonthlyProrationCharge($charge)) {
                    continue;
                }

                DB::table('cuentasporcobrar')
                    ->where('idcuentasPorCobrar', $charge->idcuentasPorCobrar)
                    ->update(['mod_monto' => $modMonto]);
            }

            foreach ($chargesToReprice as $charge) {
                if (
                    $periodEndForCharge($charge) < Carbon::today()->toDateString()
                    && !in_array((int) $charge->idcuentasPorCobrar, $currentPeriodChargeIds, true)
                ) {
                    continue;
                }

                $isRequestedCurrentCharge = !$keepPriceForNextPeriods
                    && $requestedCxcId !== null
                    && (int) $charge->idcuentasPorCobrar === $requestedCxcId;
                if (
                    !in_array(
                        mb_strtoupper(trim((string) $charge->estado), 'UTF-8'),
                        ['1', 'PENDIENTE', '4', 'VENCIDO'],
                        true
                    )
                    || trim((string) ($charge->docReferencia ?? '')) !== ''
                    || trim((string) ($charge->docFactura ?? '')) !== ''
                    || abs((float) $charge->montoActual - (float) $charge->montoOriginal) >= 0.005
                ) {
                    if ($isRequestedCurrentCharge) {
                        abort(422, 'No se puede editar este CxC porque ya está facturado, pagado parcialmente o no está pendiente.');
                    }

                    continue;
                }
                if (
                    Schema::hasTable('detallecxc')
                    && DB::table('detallecxc')
                        ->where('cuentasPorCobrar_idcuentasPorCobrar', $charge->idcuentasPorCobrar)
                        ->where('estado', '1')
                        ->exists()
                ) {
                    if ($isRequestedCurrentCharge) {
                        abort(422, 'No se puede editar este CxC porque tiene cuotas o pagos registrados.');
                    }

                    continue;
                }

                $linkedServiceIds = $serviceIdsByCharge[$charge->idcuentasPorCobrar] ?? [];
                if ($linkedServiceIds === []) {
                    if ($isRequestedCurrentCharge) {
                        abort(422, 'No se encontraron servicios vinculados al CxC seleccionado.');
                    }

                    continue;
                }

                $linkedServices = DB::table('serviciocliente')
                    ->whereIn('idservicioCliente', $linkedServiceIds)
                    ->get(['idservicioCliente', 'monto']);
                $originalAmounts = $serviceAmountsByCharge[$charge->idcuentasPorCobrar] ?? [];
                $linkedAmounts = $linkedAmountsByCharge[$charge->idcuentasPorCobrar] ?? [];
                $isProratedCharge = $isMonthlyProrationCharge($charge);
                $originalTotal = round(array_sum($originalAmounts), 2);
                $fallbackProrationRatio = $originalTotal > 0
                    ? (float) $charge->montoOriginal / $originalTotal
                    : 1.0;
                $amountsToSave = [];
                foreach ($linkedServices as $linkedService) {
                    $serviceId = (int) $linkedService->idservicioCliente;
                    $originalServiceAmount = (float) ($originalAmounts[$serviceId] ?? $linkedService->monto);
                    $newServiceAmount = $keepPriceForNextPeriods
                        ? (float) $linkedService->monto
                        : (float) ($linkedAmounts[$serviceId] ?? $linkedService->monto);

                    if ($serviceId === $id) {
                        $newServiceAmount = $amount;
                    }

                    if ($keepPriceForNextPeriods && $isProratedCharge) {
                        $periodAmount = $linkedAmounts[$serviceId] ?? null;
                        $prorationRatio = $periodAmount !== null && $originalServiceAmount > 0
                            ? (float) $periodAmount / $originalServiceAmount
                            : $fallbackProrationRatio;
                        $newServiceAmount *= $prorationRatio;
                    }

                    $amountsToSave[$serviceId] = round($newServiceAmount, 2);
                }
                $total = round(array_sum($amountsToSave), 2);
                DB::table('cuentasporcobrar')
                    ->where('idcuentasPorCobrar', $charge->idcuentasPorCobrar)
                    ->update(['montoOriginal' => $total, 'montoActual' => $total]);

                if (Schema::hasTable('cuentasporcobrar_serviciocliente')) {
                    foreach ($amountsToSave as $serviceId => $serviceAmount) {
                        $linkQuery = DB::table('cuentasporcobrar_serviciocliente')
                            ->where('cuentasPorCobrar_idcuentasPorCobrar', $charge->idcuentasPorCobrar)
                            ->where('servicioCliente_idservicioCliente', $serviceId);
                        if ($linkQuery->exists()) {
                            $linkQuery->update(['montoServicio' => $serviceAmount]);
                        } else {
                            DB::table('cuentasporcobrar_serviciocliente')->insert([
                                'cuentasPorCobrar_idcuentasPorCobrar' => $charge->idcuentasPorCobrar,
                                'servicioCliente_idservicioCliente' => $serviceId,
                                'montoServicio' => $serviceAmount,
                            ]);
                        }
                    }
                }
            }

            $currentCharge = $currentPeriodCharges->first();
            $previousAmount = $keepPriceForNextPeriods
                ? (float) $service->monto
                : (float) ($currentCharge->montoOriginal ?? $service->monto);
            if (abs($previousAmount - $amount) >= 0.005) {
                $deviceId = null;
                if (Schema::hasTable('detalle_serviciodispositivo')) {
                    $deviceId = DB::table('detalle_serviciodispositivo')
                        ->where('servicioCliente_idservicioCliente', $id)
                        ->orderByDesc('iddetalle_serviciodispositivo')
                        ->value('dispositivoCliente_iddispositivoCliente');
                }

                $phoneNumber = null;
                if ($deviceId && Schema::hasTable('detnumerosdispositivo')) {
                    $phoneNumber = DB::table('detnumerosdispositivo')
                        ->where('dispositivoCliente_iddispositivoCliente', $deviceId)
                        ->orderByDesc('iddetNumerosDispositivo')
                        ->value('numeroTelefonico_numeroTelefonico');
                }

                $currencyRaw = DB::table('moneda')
                    ->where('idmoneda', $service->moneda_idmoneda)
                    ->value('simbolo');
                $currencySymbol = $this->currencySymbol($currencyRaw);
                $formatPrice = static fn(float $price): string => $currencySymbol . ' '
                    . rtrim(rtrim(number_format($price, 2, '.', ''), '0'), '.');
                $description = 'Cambio de precio de '
                    . $formatPrice($previousAmount)
                    . ' a '
                    . $formatPrice($amount);
                if ($keepPriceForNextPeriods) {
                    $description .= ' para los siguientes meses';
                } else {
                    $periodEnd = $currentCharge
                        ? $periodEndForCharge($currentCharge)
                        : $servicePeriodEnd;
                    $month = Carbon::parse($periodEnd)->locale('es')->monthName;
                    $description .= ' solo para el CXC '
                        . mb_convert_case($month, MB_CASE_TITLE, 'UTF-8');
                }

                DB::table('historial_servicio')->insert([
                    'usuario_usuario' => $user,
                    'serv_usuario' => $user,
                    'servicioCliente_idservicioCliente' => $id,
                    'fecha_accion' => Carbon::now()->format('Y-m-d H:i:s'),
                    'motivo' => 'Cambio de precio',
                    'descripcion' => $description,
                    'doc_referencia' => $service->docReferencia,
                    'dispositivo' => $deviceId,
                    'numerotelefono' => $phoneNumber,
                ]);
            }

            return $service;
        });

        $symbol = DB::table('moneda')->where('idmoneda', $service->moneda_idmoneda)->value('simbolo') ?: 'S/';

        return response()->json([
            'success' => true,
            'idservicioCliente' => $id,
            'monto' => $amount,
            'monto_display' => $symbol . ' ' . number_format($amount, 2, '.', ''),
            'mantener_precio_siguiente' => $keepPriceForNextPeriods,
        ]);
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
                ['name' => 'descripcion', 'type' => 'text', 'label' => 'Descripción', 'required' => false, 'maxlength' => 100, 'helpText' => 'Breve descripción.'],
                $this->decimalFieldDefinition('montoOriginal', 'Monto original', data_get($record, 'montoOriginal'), true),
                $this->decimalFieldDefinition('montoActual', 'Monto actual', data_get($record, 'montoActual'), true),
                ['name' => 'canCuotas', 'type' => 'number', 'label' => 'Cuotas', 'required' => false, 'inputmode' => 'numeric', 'maxlength' => 5],
                ['name' => 'fechaRegistro', 'type' => 'date', 'label' => 'Fecha registro', 'required' => false],
                ['name' => 'fechaCancelacion', 'type' => 'date', 'label' => 'Fecha fin del periodo', 'required' => false],
                [
                    'name' => 'estado',
                    'type' => 'select',
                    'label' => 'Estado',
                    'required' => true,
                    'options' => [
                        ['value' => '1', 'label' => 'Pendiente'],
                        ['value' => '2', 'label' => 'Facturado'],
                        ['value' => '3', 'label' => 'Cancelado'],
                        ['value' => '4', 'label' => 'Vencido'],
                        ['value' => 'Pendiente Pago parcial', 'label' => 'Pendiente Pago parcial'],
                        ['value' => 'Pendiente a credito', 'label' => 'Pendiente a credito'],
                        ['value' => 'NUEVO', 'label' => 'Nuevo'],
                        ['value' => 'Pendiente cuota crédito', 'label' => 'Pendiente cuota crédito'],
                        ['value' => 'Aprobado', 'label' => 'Aprobado'],
                        ['value' => 'Pendiente Autodetracción', 'label' => 'Pendiente Autodetracción'],
                        ['value' => 'Pendiente a Detracción', 'label' => 'Pendiente a Detracción'],
                        ['value' => 'Pendiente a Retención', 'label' => 'Pendiente a Retención'],
                    ],
                    'placeholder' => 'Selecciona estado',
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

        $currentState = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $id)
            ->value('estado');
        if (in_array((string) $currentState, ['2', '3', '4', 'NUEVO', 'PagoCredito', 'Pendiente cuota crédito', 'Aprobado', 'FACTURADO', 'CANCELADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito', 'Cancelado Detracción', 'Pendiente Autodetracción', 'Pendiente a Detracción', 'Pendiente a Retención'], true)) {
            return redirect()->route('modules.cuentasporcobrar')
                ->with('error', 'Las cuentas históricas no se pueden editar.');
        }

        $validated = $request->validate([
            'cliente_idcliente' => ['required', 'string', 'exists:cliente,idcliente'],
            'tipoCobro_idtipoCobros' => ['required', 'integer', 'exists:tipocobro,idtipoCobros'],
            'docReferencia' => ['nullable', 'string', 'max:15', 'regex:' . self::SAFE_TEXT_REGEX],
            'descripcion' => ['nullable', 'string', 'max:80', 'regex:' . self::SAFE_TEXT_REGEX],
            'montoOriginal' => ['required', 'numeric', 'min:0'],
            'montoActual' => ['required', 'numeric', 'min:0'],
            'canCuotas' => ['nullable', 'numeric', 'min:1'],
            'fechaRegistro' => ['nullable', 'date'],
            'fechaCancelacion' => ['nullable', 'date'],
            'estado' => ['required', 'in:1,2,3,4,NUEVO,PagoCredito,Pendiente cuota crédito,Aprobado,Pendiente Pago parcial,Pendiente a credito,Cancelado Detracción,Pendiente Autodetracción,Pendiente a Detracción,Pendiente a Retención'],
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

    private function formatCurrencyValue(mixed $value, string $symbol = 'S/'): string
    {
        return trim($symbol) . ' ' . number_format((float) $value, 2, '.', ',');
    }

    public function anularCotizaciones(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'quotation_ids' => ['required', 'array', 'min:1', 'max:100'],
            'quotation_ids.*' => ['required', 'string', 'max:100', 'distinct'],
        ]);

        $quoteIds = array_map('strval', $validated['quotation_ids']);
        foreach ($quoteIds as $quoteId) {
            if (
                $redirect = $this->assertLockAvailable(
                    $request,
                    'ventas.cotizaciones',
                    $quoteId,
                    'registro de cotización',
                    'modules.cuentasporcobrar'
                )
            ) {
                return $redirect->with('error', 'No se pudo anular la selección porque una cotización está bloqueada.');
            }
        }

        $result = $this->cotizacionService->anularCotizaciones($quoteIds);
        if (!$result['success']) {
            return redirect()
                ->route('modules.cuentasporcobrar', ['tab' => 'cotizaciones'])
                ->with('error', $result['message']);
        }

        foreach ($result['quote_ids'] as $quoteId) {
            $this->publishResourceEvent('ventas.cotizaciones', $quoteId, 'updated', [
                'estado' => CotizacionService::STATE_ANULADO,
            ]);
        }
        foreach ($result['ticket_ids'] as $ticketId) {
            $this->publishResourceEvent('ticket', (string) $ticketId, 'updated', [
                'estado' => 'Resuelto',
                'motivo' => 'cotizacion_anulada',
            ]);
        }

        return redirect()
            ->route('modules.cuentasporcobrar', ['tab' => 'cotizaciones'])
            ->with('success', count($result['quote_ids']) === 1
                ? 'Cotización anulada correctamente.'
                : 'Cotizaciones anuladas correctamente.');
    }

    private function applyQuotationFilters($query, Request $request, string $defaultStatus = ''): void
    {
        $client = trim((string) $request->query('quote_client', ''));
        $number = trim((string) $request->query('quote_number', ''));
        $paymentFormat = trim((string) $request->query('quote_payment_format', ''));
        $validity = trim((string) $request->query('quote_validity', ''));
        $service = trim((string) $request->query('quote_service', ''));
        $date = trim((string) $request->query('quote_date', ''));

        if ($client !== '') {
            $term = '%' . $client . '%';
            $query->where(function ($builder) use ($term) {
                $builder->where('c.cliente_idcliente', 'like', $term)
                    ->orWhere('cli.razonSocial', 'like', $term)
                    ->orWhere('cli.nombreComercial', 'like', $term);
            });
        }

        if ($number !== '') {
            $query->where('c.nroCotizacion', 'like', '%' . $number . '%');
        }

        if (ctype_digit($paymentFormat)) {
            $query->where('c.formaPago_idformaPago', (int) $paymentFormat);
        }

        if (ctype_digit($validity)) {
            $query->where('c.vigenciaOferta_idvigenciaOferta', (int) $validity);
        }

        if (in_array($service, ['EQUIPAMIENTO', 'PLANES', 'SERVICIOS TÉCNICOS'], true)) {
            $query->whereRaw("COALESCE(cotizacion_tipo.servicio, 'EQUIPAMIENTO') = ?", [$service]);
        }

        $status = trim((string) $request->query('quote_status', $defaultStatus));
        $status = ['1' => 'pending', '2' => 'approved', '' => 'all'][$status] ?? $status;
        $quoteCxcMatch = DB::connection()->getDriverName() === 'sqlite'
            ? "(quote_cxc.descripcion = 'COTIZACION:' || c.nroCotizacion OR quote_cxc.descripcion LIKE '%' || c.nroCotizacion)"
            : "(quote_cxc.descripcion = CONCAT('COTIZACION:', c.nroCotizacion) OR quote_cxc.descripcion LIKE CONCAT('%', c.nroCotizacion))";
        if ($status === 'pending') {
            $query->where(function ($builder) use ($quoteCxcMatch): void {
                $builder->where('c.estado', '1')
                    ->orWhereExists(function ($subquery) use ($quoteCxcMatch): void {
                        $subquery->selectRaw('1')
                            ->from('cuentasporcobrar as quote_cxc')
                            ->whereRaw($quoteCxcMatch)
                            ->whereRaw('LOWER(quote_cxc.estado) LIKE ?', ['%pendiente%']);
                    });
            });
        } elseif ($status === 'pending_payment') {
            $query->whereExists(function ($subquery) use ($quoteCxcMatch): void {
                $subquery->selectRaw('1')
                    ->from('cuentasporcobrar as quote_cxc')
                    ->whereRaw($quoteCxcMatch)
                    ->where('quote_cxc.estado', 'Pendiente Pago parcial');
            });
        } elseif (in_array($status, ['pending_credit', 'pending_autodetraccion', 'pending_detraccion', 'pending_retencion'], true)) {
            $query->whereExists(function ($subquery) use ($status, $quoteCxcMatch): void {
                $subquery->selectRaw('1')
                    ->from('cuentasporcobrar as quote_cxc')
                    ->whereRaw($quoteCxcMatch);

                if ($status === 'pending_credit') {
                    $subquery->where(function ($stateQuery): void {
                        $stateQuery->whereRaw('LOWER(quote_cxc.estado) LIKE ?', ['%pendiente%credito%'])
                            ->orWhereRaw("LOWER(quote_cxc.estado) IN ('pagocredito', 'pendiente cuota crédito')");
                    });
                } elseif (in_array($status, ['pending_autodetraccion', 'pending_detraccion'], true)) {
                    $expectedGrossPayment = $status === 'pending_autodetraccion';
                    $paymentDetailExists = function ($paymentDetail) use ($expectedGrossPayment): void {
                        $paymentDetail->selectRaw('1')
                            ->from('detallecxc as pending_detail')
                            ->join('bancos as pending_bank', 'pending_bank.idbancos', '=', 'pending_detail.bancos_idbancos')
                            ->whereColumn('pending_detail.cuentasPorCobrar_idcuentasPorCobrar', 'quote_cxc.idcuentasPorCobrar')
                            ->where('pending_detail.estado', '0')
                            ->where('pending_detail.descripcion', 'like', 'Detracción pendiente:%')
                            ->whereRaw(
                                $expectedGrossPayment
                                ? 'pending_bank.monto + 0.01 >= quote_cxc.montoOriginal'
                                : 'pending_bank.monto + 0.01 < quote_cxc.montoOriginal'
                            );
                    };
                    if ($expectedGrossPayment) {
                        $subquery->where(function ($stateQuery) use ($paymentDetailExists): void {
                            $stateQuery->where('quote_cxc.estado', 'Pendiente Autodetracción')
                                ->orWhere(function ($legacyStateQuery) use ($paymentDetailExists): void {
                                    $legacyStateQuery->where('quote_cxc.estado', 'Pendiente a Detracción')
                                        ->whereExists($paymentDetailExists);
                                });
                        });
                    } else {
                        $subquery->where('quote_cxc.estado', 'Pendiente a Detracción')
                            ->whereExists($paymentDetailExists);
                    }
                } else {
                    $subquery->where('quote_cxc.estado', 'Pendiente a Retención');
                }
            });
        } elseif ($status === 'approved') {
            $query->where('c.estado', '2')
                ->whereNotExists(function ($subquery) use ($quoteCxcMatch): void {
                    $subquery->selectRaw('1')
                        ->from('cuentasporcobrar as quote_cxc')
                        ->whereRaw($quoteCxcMatch)
                        ->whereRaw('LOWER(quote_cxc.estado) LIKE ?', ['%pendiente%']);
                });
        } else {
            $query->whereIn('c.estado', ['1', '2']);
        }

        if ($date !== '') {
            $query->whereDate('c.fechaHoraEmision', $date);
        }
    }

    private function orderQuotationsByStatus($query): void
    {
        $quoteCxcMatch = DB::connection()->getDriverName() === 'sqlite'
            ? "(quote_sort_cxc.descripcion = 'COTIZACION:' || c.nroCotizacion OR quote_sort_cxc.descripcion LIKE '%' || c.nroCotizacion)"
            : "(quote_sort_cxc.descripcion = CONCAT('COTIZACION:', c.nroCotizacion) OR quote_sort_cxc.descripcion LIKE CONCAT('%', c.nroCotizacion))";
        $latestQuoteCxcStatus = DB::table('cuentasporcobrar as quote_sort_cxc')
            ->whereRaw($quoteCxcMatch)
            ->orderByDesc('quote_sort_cxc.idcuentasPorCobrar')
            ->limit(1)
            ->select('quote_sort_cxc.estado');

        $query->selectSub($latestQuoteCxcStatus, 'quote_sort_status')
            ->orderByRaw("
                CASE
                WHEN LOWER(COALESCE(quote_sort_status, '')) = 'pendiente a detracción' THEN 1
                WHEN LOWER(COALESCE(quote_sort_status, '')) = 'pendiente autodetracción' THEN 2
                WHEN LOWER(COALESCE(quote_sort_status, '')) IN ('pendiente a retención', 'pendiente autoretención') THEN 3
                WHEN LOWER(COALESCE(quote_sort_status, '')) = 'pendiente pago parcial' THEN 4
                WHEN LOWER(COALESCE(quote_sort_status, '')) LIKE '%pendiente%credito%'
                    OR LOWER(COALESCE(quote_sort_status, '')) IN ('pagocredito', 'pendiente cuota crédito') THEN 5
                WHEN c.estado = '1' THEN 6
                WHEN c.estado = '2' THEN 7
                ELSE 8
            END
        ");
    }

    private function currencySymbol(?string $currency): string
    {
        $rawCurrency = trim((string) ($currency ?? ''));
        $currency = mb_strtolower($rawCurrency, 'UTF-8');

        if (str_contains($currency, 'dolar') || str_contains($currency, 'dólar') || str_contains($currency, '$')) {
            return '$';
        }

        if (str_contains($currency, 'euro') || str_contains($currency, '€')) {
            return '€';
        }

        if (
            $rawCurrency === '' || in_array($currency, ['s', 's/', 's/.', 'sol', 'soles', 'pen'], true)
            || str_contains($currency, 'sol peru')
        ) {
            return 'S/';
        }

        return $rawCurrency;
    }

    private function formatServicioPeriodo(mixed $value): string
    {
        return ServicePeriod::label($value);
    }

    private function formatDisplayDate(mixed $value): string
    {
        if (empty($value) || in_array((string) $value, ['0000-00-00', '0000-00-00 00:00:00'], true)) {
            return '-';
        }

        try {
            $carbonDate = Carbon::parse($value);
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
            ->leftJoinSub(
                DB::table('detallecxc')
                    ->where('estado', '1')
                    ->whereNotNull('fechaPago')
                    ->selectRaw('cuentasPorCobrar_idcuentasPorCobrar as cxc_id, MAX(fechaPago) as fecha_pago')
                    ->groupBy('cuentasPorCobrar_idcuentasPorCobrar'),
                'pago',
                fn($join) => $join->on('pago.cxc_id', '=', 'c.idcuentasPorCobrar')
            )
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
                DB::raw('pago.fecha_pago as fechaRegistro'),
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
                    ->where('c.idcuentasPorCobrar', 'like', $term)
                    ->orWhere('c.cliente_idcliente', 'like', $term)
                    ->orWhere('cl.nombreComercial', 'like', $term)
                    ->orWhere('cl.razonSocial', 'like', $term)
                    ->orWhere('tc.nombre', 'like', $term)
                    ->orWhere('c.docReferencia', 'like', $term)
                    ->orWhere('c.descripcion', 'like', $term);
            });
        }

        $cliente = trim((string) $request->input('cliente_idcliente', ''));
        if ($cliente !== '') {
            $query->where('c.cliente_idcliente', 'like', '%' . $cliente . '%');
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
            ->orderBy('idtipoCobros')
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

    private function formaPagoOptions()
    {
        return DB::table('formapago')
            ->select(['idformaPago', 'detalle', 'tiempo'])
            ->orderBy('detalle')
            ->get()
            ->map(fn($row): array => [
                'value' => (string) $row->idformaPago,
                'label' => trim((string) $row->detalle) . ((int) ($row->tiempo ?? 0) > 0 ? ' - ' . $row->tiempo . ' días' : ''),
                'credit_days' => (int) ($row->tiempo ?? 0),
            ]);
    }

    private function paymentBreakdownMessage(array $breakdown): string
    {
        $symbol = trim((string) ($breakdown['currency_symbol'] ?? 'S/')) ?: 'S/';
        if ($symbol === 'S') {
            $symbol = 'S/';
        }
        $format = fn(mixed $amount): string => $symbol . ' ' . number_format((float) $amount, 2, '.', ',');
        $parts = [];
        $cashHeldForWithholding = round(max(0, (float) ($breakdown['cash_held_for_withholding'] ?? 0)), 2);

        if ((float) ($breakdown['deduction'] ?? 0) > 0) {
            $label = !empty($breakdown['retencion']) ? 'Retención (3%) aplicada' : 'Detracción (12%) aplicada';
            $parts[] = $label . ': ' . $format($breakdown['deduction']);
        }
        if ((float) ($breakdown['monto_saldo_favor_aplicado'] ?? 0) > 0) {
            $parts[] = 'Saldo a favor aplicado: ' . $format($breakdown['monto_saldo_favor_aplicado']);
        }
        if ((float) ($breakdown['monto_nota_credito_aplicado'] ?? 0) > 0) {
            $parts[] = 'Nota de crédito aplicada: ' . $format($breakdown['monto_nota_credito_aplicado']);
        }
        if ($cashHeldForWithholding > 0 && (float) ($breakdown['cash_received'] ?? 0) > 0) {
            $parts[] = 'Efectivo recibido: ' . $format($breakdown['cash_received']);
        } elseif ((float) ($breakdown['cash_applied'] ?? 0) > 0) {
            $parts[] = 'Efectivo aplicado: ' . $format($breakdown['cash_applied']);
        }
        if ((float) ($breakdown['new_credit'] ?? 0) > 0) {
            $parts[] = 'Nuevo saldo a favor: ' . $format($breakdown['new_credit']);
        }
        $remainingDebt = round(max(0, (float) ($breakdown['remaining_debt'] ?? 0)), 2);
        if ($cashHeldForWithholding > 0) {
            $label = !empty($breakdown['retencion']) ? 'Autoretención pendiente' : 'Autodetracción pendiente';
            $parts[] = $label . ': ' . $format($cashHeldForWithholding);
            $remainingDebt = round(max(0, $remainingDebt - $cashHeldForWithholding), 2);
        }
        if ($remainingDebt > 0) {
            $parts[] = 'Saldo pendiente: ' . $format($remainingDebt);
        }

        return $parts === [] ? 'La deuda quedó cancelada.' : implode('. ', $parts) . '.';
    }

    private function buildCreditInstallments($formaPagoId, $count, array $amounts, array $dates, $paymentDate, float $total): array
    {
        if (!$formaPagoId || !$count) {
            return [];
        }

        $payment = DB::table('formapago')->where('idformaPago', $formaPagoId)->first(['detalle', 'tiempo']);
        if (!$payment || !str_contains(mb_strtolower((string) $payment->detalle, 'UTF-8'), 'credito')) {
            return [];
        }

        $count = max(1, min((int) $count, 60));
        $defaultAmount = round($total / $count, 2);
        $creditDays = max(0, (int) ($payment->tiempo ?? 0));
        $baseDate = Carbon::parse($paymentDate ?: now()->format('Y-m-d'));
        $installments = [];
        $remaining = round($total, 2);

        for ($index = 0; $index < $count; $index++) {
            $amount = isset($amounts[$index]) && (float) $amounts[$index] > 0
                ? round((float) $amounts[$index], 2)
                : ($index === $count - 1 ? $remaining : $defaultAmount);
            $amount = $index === $count - 1 ? $remaining : min($amount, $remaining);
            $dueDate = !empty($dates[$index])
                ? Carbon::parse($dates[$index])
                : $baseDate->copy()->addDays($creditDays * $index);
            $installments[] = ['numero' => $index + 1, 'monto' => $amount, 'fecha' => $dueDate->format('Y-m-d')];
            $remaining = round($remaining - $amount, 2);
        }

        if ($remaining !== 0.0 && !empty($installments)) {
            $last = array_key_last($installments);
            $installments[$last]['monto'] = round($installments[$last]['monto'] + $remaining, 2);
        }

        return $installments;
    }

    private function monedasOptions()
    {
        return DB::table('moneda')
            ->select(['idmoneda', 'detalle', 'simbolo'])
            ->where('idmoneda', '!=', 4)
            ->orderBy('detalle')
            ->get()
            ->map(fn($row): array => [
                'value' => (string) $row->idmoneda,
                'label' => trim((string) ($row->detalle ?? '')) . (trim((string) ($row->simbolo ?? '')) !== '' ? ' (' . trim((string) $row->simbolo) . ')' : ''),
                'simbolo' => trim((string) ($row->simbolo ?? '')),
                'detalle' => trim((string) ($row->detalle ?? '')),
            ]);
    }

    private function entidadBancariaOptions()
    {
        return DB::table('entidadbancaria')
            ->select(['identidadBancaria', 'razonSocial', 'descripcion'])
            ->orderBy('razonSocial')
            ->orderBy('descripcion')
            ->get()
            ->map(function ($row): array {
                $description = trim((string) ($row->descripcion ?? ''));
                $companyName = trim((string) ($row->razonSocial ?? ''));
                $searchText = mb_strtoupper($description . ' ' . $companyName, 'UTF-8');
                $searchText = str_replace('É', 'E', $searchText);

                return [
                    'value' => (string) $row->identidadBancaria,
                    'label' => $description ?: ($companyName ?: 'Entidad bancaria #' . $row->identidadBancaria),
                    'is_bcp' => preg_match('/\bBCP\b|BANCO DE CREDITO/u', $searchText) === 1,
                ];
            });
    }
}
