<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Export\ExportableList;
use App\Http\Controllers\Permission\HandlesResourceLock;
use App\Support\ResourceLock;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use App\Services\CxcService;
use App\Services\CuentasPorCobrarService;

class CuentasPorCobrarController extends Controller
{
    use ExportableList;
    use HandlesResourceLock;

    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';
    private const LOCK_RESOURCE = 'cuentasporcobrar';

    public function __construct(
        protected CxcService $cxcService,
        protected CuentasPorCobrarService $cuentasPorCobrarService,
    )
    {   
    }

    public function index(Request $request): View
    {
        $activeTab = $request->query('tab') === 'servicios' ? 'servicios' : 'cotizaciones';
        $quoteSearch = trim((string) $request->query('quote_q', ''));
        $quoteGroup = trim((string) $request->query('quote_group', ''));
        $quoteClient = trim((string) $request->query('quote_client', ''));
        $quoteCurrency = trim((string) $request->query('quote_currency', ''));
        $quoteService = trim((string) $request->query('quote_service', ''));
        $quoteDate = trim((string) $request->query('quote_date', ''));
        $quoteStatus = trim((string) $request->query('quote_status', '1'));

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
                DB::raw('COALESCE(cli.razonSocial, cli.nombreComercial, cli.idcliente) as cliente_nombre'),
                'cli.flag_integrador as flag_integrador',
                'c.total',
                'c.estado',
                'c.fechaHoraEmision',
                'c.archivoPago',
                'v.detalle as vigencia_detalle',
                'fp.detalle as forma_pago_detalle',
                'fp.tiempo as forma_pago_tiempo',
                'm.detalle as moneda_detalle',
                DB::raw("COALESCE(cotizacion_tipo.servicio, 'EQUIPAMIENTO') as servicio"),
            ]);

        $quoteStatsQuery = clone $cotizacionesQuery;
        $this->applyQuotationFilters($quoteStatsQuery, $request);
        $this->applyQuotationFilters($cotizacionesQuery, $request, '1');

        $quoteStatsRow = (clone $quoteStatsQuery)
            ->select([])
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN c.estado = ? THEN 1 ELSE 0 END) as pendientes, SUM(CASE WHEN c.estado = ? THEN 1 ELSE 0 END) as con_pago', ['1', '2'])
            ->first();
        $groupCountsQuery = clone $cotizacionesQuery;

        $cotizacionesPendientes = $cotizacionesQuery
            ->orderByDesc('c.fechaHoraEmision')
            ->paginate($this->resolvePerPage($request), ['*'], 'quotes_page')
            ->withQueryString();

        $pageBatchIds = $cotizacionesPendientes->getCollection()
            ->pluck('batch_id')
            ->map(fn ($batchId): string => trim((string) $batchId))
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
        }

        $serviceFilters = [
            'service_client' => trim((string) $request->query('service_client', '')),
            'service_month' => trim((string) $request->query('service_month', strtolower(Carbon::now()->locale('es')->monthName))),
            'service_year' => trim((string) $request->query('service_year', (string) now()->year)),
            'service_document' => trim((string) $request->query('service_document', '')),
            'service_date' => trim((string) $request->query('service_date', '')),
            'service_status' => trim((string) $request->query('service_status', '')),
        ];
        $serviceFilters['service_months'] = array_values(array_filter(
            array_map(static fn (string $month): string => mb_strtolower(trim($month), 'UTF-8'), explode(',', $serviceFilters['service_month'])),
            static fn (string $month): bool => $month !== '' && $month !== 'all'
        ));
        if ($serviceFilters['service_year'] === 'all') {
            $serviceFilters['service_year'] = '';
        }

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
            ['label' => 'Servicios activos', 'value' => 0],
            ['label' => 'Servicios vencidos', 'value' => 0],
        ];

        if ($activeTab === 'servicios') {
        $serviceIndexData = $this->cuentasPorCobrarService->prepareServicesIndex();
        $serviciosPorVencer = $serviceIndexData['serviciosPorVencer'];
        $serviciosPorVencerGrouped = $serviceIndexData['serviciosPorVencerGrouped'];

        usort($serviciosPorVencerGrouped, function ($a, $b) {
            $rank = ['facturado' => 0, 'pendiente' => 1, 'vencido' => 2, 'cancelado' => 3];
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

        $serviceStats = [
            ['label' => 'Total de grupos', 'value' => count($serviciosPorVencerGrouped)],
            ['label' => 'Servicios activos', 'value' => collect($serviciosPorVencerGrouped)->where('estado_servicio', 'activo')->count()],
            ['label' => 'Servicios vencidos', 'value' => collect($serviciosPorVencerGrouped)->where('estado_servicio', 'vencido')->count()],
        ];

        $serviciosPorVencerGrouped = array_values(array_filter($serviciosPorVencerGrouped, function (array $group) use ($serviceFilters): bool {
            $clientId = mb_strtolower((string) ($group['cliente_id'] ?? ''), 'UTF-8');
            $clientName = mb_strtolower((string) ($group['cliente_nombre'] ?? ''), 'UTF-8');
            $month = mb_strtolower((string) ($group['mes'] ?? ''), 'UTF-8');
            $clientFilter = mb_strtolower($serviceFilters['service_client'], 'UTF-8');
            $document = mb_strtolower((string) ($group['documento'] ?? ''), 'UTF-8');
            $documentFilter = mb_strtolower($serviceFilters['service_document'], 'UTF-8');
            $status = mb_strtolower((string) ($group['estado_pago'] ?? ''), 'UTF-8');

            if ($clientFilter !== '' && !str_contains($clientId, $clientFilter) && !str_contains($clientName, $clientFilter)) {
                return false;
            }
            if ($serviceFilters['service_months'] !== [] && !in_array($month, $serviceFilters['service_months'], true)) {
                return false;
            }
            if ($serviceFilters['service_year'] !== '' && (string) ($group['anio'] ?? '') !== $serviceFilters['service_year']) {
                return false;
            }
            if ($documentFilter !== '' && !str_contains($document, $documentFilter)) {
                return false;
            }
            if ($serviceFilters['service_date'] !== '' && ($group['fecha_fin_iso'] ?? '') !== $serviceFilters['service_date']) {
                return false;
            }
            if ($serviceFilters['service_status'] === 'vencido') {
                if ($status !== 'vencido') return false;
            } elseif ($serviceFilters['service_status'] !== '' && $status !== $serviceFilters['service_status']) {
                return false;
            }

            return true;
        }));

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
            'columns' => [],
            'stats' => [],
            'filters' => [],
            'formaPagoOptions' => $this->formaPagoOptions(),
            'entidadBancariaOptions' => $this->entidadBancariaOptions(),
            'monedasOptions' => $this->monedasOptions(),
            'quoteFilters' => [
                'quote_q' => $quoteSearch,
                'quote_group' => $quoteGroup,
                'quote_client' => $quoteClient,
                'quote_currency' => $quoteCurrency,
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

    protected function serviceGroupPriority(array $group): int
    {
        $status = strtolower((string) ($group['estado_servicio'] ?? 'activo'));

        return $status === 'activo' ? 0 : 1;
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
                'c.batch_id',
                DB::raw('COALESCE(cli.razonSocial, cli.nombreComercial, cli.idcliente) as cliente_nombre'),
                'c.total',
                'c.fechaHoraEmision',
                'v.detalle as vigencia_detalle',
                'fp.detalle as forma_pago_detalle',
                'fp.tiempo as forma_pago_tiempo',
                'm.detalle as moneda_detalle',
                DB::raw("COALESCE(cotizacion_tipo.servicio, 'EQUIPAMIENTO') as servicio"),
            ]);

        $this->applyQuotationFilters($query, $request);
        $rows = $query->orderByDesc('c.fechaHoraEmision')->get();

        $rows->transform(function ($row): mixed {
            $row->monto_display = $this->formatCurrencyValue(
                (float) ($row->total ?? 0),
                $this->currencySymbol($row->moneda_detalle ?? null)
            );
            $row->grupo_display = trim((string) ($row->batch_id ?? '')) ?: 'Sin grupo';
            $row->fecha_emision_display = $row->fechaHoraEmision
                ? Carbon::parse($row->fechaHoraEmision)->format('d/m/Y')
                : '-';
            $row->vigencia_display = trim((string) ($row->vigencia_detalle ?? '')) ?: '-';
            $formaPago = trim((string) ($row->forma_pago_detalle ?? ''));
            $tiempo = (int) ($row->forma_pago_tiempo ?? 0);
            if ($tiempo > 0 && !str_contains(mb_strtolower($formaPago, 'UTF-8'), 'contado')) {
                $formaPago .= ' (' . $tiempo . ' días)';
            }
            $row->forma_pago_display = $formaPago !== '' ? $formaPago : '-';

            return $row;
        });

        $columns = [
            ['key' => 'nroCotizacion', 'label' => 'N° Cotización'],
            ['key' => 'cliente_nombre', 'label' => 'Cliente'],
            ['key' => 'monto_display', 'label' => 'Monto'],
            ['key' => 'vigencia_display', 'label' => 'Vigencia de oferta'],
            ['key' => 'forma_pago_display', 'label' => 'Formato de pago'],
            ['key' => 'fecha_emision_display', 'label' => 'Fecha Emisión'],
            ['key' => 'grupo_display', 'label' => 'Grupo'],
            ['key' => 'servicio', 'label' => 'Servicio'],
        ];
        $filename = 'cotizaciones_cuentas_por_cobrar_' . now()->format('Ymd_His') . '.' . $format;

        return $format === 'xlsx'
            ? $this->exportXlsxResponse($rows, $columns, $filename)
            : $this->exportPdfResponse($rows, $columns, 'Cotizaciones por cobrar', $filename);
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

    public function approveQuotation(Request $request, string $id): RedirectResponse
    {
        $request->validate([
            'archivoPago' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'fecha_pago' => ['nullable', 'date'],
            'hora_pago' => ['nullable', 'string'],
            'descripcion' => ['nullable', 'string', 'max:50'],
        ]);

        $file = $request->file('archivoPago');
        $storedPath = null;
        $updatedIds = [];
        $fechaPago = $request->input('fecha_pago', date('Y-m-d'));
        $horaPago = $request->input('hora_pago', date('H:i'));
        $descripcion = trim((string) $request->input('descripcion', ''));

        $fechaHoraPago = null;
        if (!empty($fechaPago)) {
            $timePart = !empty($horaPago) ? $horaPago : '00:00';
            try {
                $fechaHoraPago = Carbon::parse("{$fechaPago} {$timePart}")->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                $fechaHoraPago = Carbon::now()->format('Y-m-d H:i:s');
            }
        }

        try {
            DB::transaction(function () use ($id, $file, $fechaHoraPago, $descripcion, &$updatedIds, &$storedPath): void {
                $quote = DB::table('cotizacion')
                    ->where('nroCotizacion', $id)
                    ->lockForUpdate()
                    ->first(['nroCotizacion', 'estado', 'cliente_idcliente', 'moneda_idmoneda', 'total']);

                if (!$quote) {
                    abort(404, 'No se encontró la cotización solicitada.');
                }

                if ((string) $quote->estado !== '1') {
                    abort(422, 'La cotización ya no está en estado Aprobado(SP).');
                }

                $directory = 'comprobantes-pago/ind';
                $filename = $this->buildPaymentFileName($id, $file->getClientOriginalExtension());
                $storedPath = $file->storeAs($directory, $filename, 'public');
                if (!$storedPath) {
                    throw new \RuntimeException('No se pudo guardar el comprobante de pago.');
                }

                $updateData = [
                    'archivoPago' => $storedPath,
                    'estado' => '2',
                ];

                if ($fechaHoraPago !== null) {
                    if (DB::getSchemaBuilder()->hasColumn('cotizacion', 'fechaPago')) {
                        $updateData['fechaPago'] = $fechaHoraPago;
                    } elseif (DB::getSchemaBuilder()->hasColumn('cotizacion', 'fecha_pago')) {
                        $updateData['fecha_pago'] = $fechaHoraPago;
                    }
                }

                if ($descripcion !== '') {
                    if (DB::getSchemaBuilder()->hasColumn('cotizacion', 'observacion')) {
                        $updateData['observacion'] = $descripcion;
                    } elseif (DB::getSchemaBuilder()->hasColumn('cotizacion', 'descripcion')) {
                        $updateData['descripcion'] = $descripcion;
                    }
                }

                DB::table('cotizacion')
                    ->where('nroCotizacion', $id)
                    ->update($updateData);

                $cxcDescripcion = trim($descripcion !== '' ? "{$descripcion} {$id}" : $id);

                $cxcData = [
                    'cliente_idcliente' => $quote->cliente_idcliente ?? null,
                    'tipoCobro_idtipoCobros' => 1,
                    'moneda_idmoneda' => $quote->moneda_idmoneda ?? null,
                    'docReferencia' => null,
                    'descripcion' => $cxcDescripcion,
                    'montoOriginal' => (float) ($quote->total ?? 0),
                    'montoActual' => (float) ($quote->total ?? 0),
                    'canCuotas' => null,
                    'fechaRegistro' => $fechaHoraPago ?? now()->format('Y-m-d H:i:s'),
                    'fechaCancelacion' => null,
                    'estado' => 'NUEVO',
                ];

                if (DB::getSchemaBuilder()->hasColumn('cuentasporcobrar', 'docFactura')) {
                    $cxcData['docFactura'] = null;
                }

                DB::table('cuentasporcobrar')->insert($cxcData);

                $updatedIds = [$id];
            });
        } catch (\Throwable $exception) {
            if ($storedPath !== null && Storage::disk('public')->exists($storedPath)) {
                Storage::disk('public')->delete($storedPath);
            }

            throw $exception;
        }

        foreach ($updatedIds as $updatedId) {
            $this->publishResourceEvent('ventas.cotizaciones', (string) $updatedId, 'updated', [
                'estado' => '2',
                'archivoPago' => $storedPath,
                'origen' => 'cuentasporcobrar',
            ]);
        }

        return redirect()
            ->route('modules.cuentasporcobrar', ['tab' => 'cotizaciones'])
            ->with('success', 'Comprobante guardado y cotización aprobada correctamente.');
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
                        ['value' => 'Cancelado Detracción', 'label' => 'Cancelado Detracción'],
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
            'descripcion' => ['required', 'string', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'montoOriginal' => ['required', 'numeric', 'min:0'],
            'montoActual' => ['required', 'numeric', 'min:0'],
            'canCuotas' => ['nullable', 'numeric', 'min:1'],
            'fechaRegistro' => ['nullable', 'date'],
            'fechaCancelacion' => ['nullable', 'date'],
            'estado' => ['required', 'in:1,2,3,4,Pendiente Pago parcial,Pendiente a credito,Cancelado Detracción'],
        ]);
        $validated = $this->normalizeDecimalFields($validated);

        $id = DB::table('cuentasporcobrar')->insertGetId($validated);
        $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $id, 'created');

        return redirect()->route('modules.cuentasporcobrar')->with('success', 'Cuenta por cobrar creada correctamente.');
    }

    public function darDeBajaServicios(Request $request)
    {
        $validated = $request->validate([
            'servicio_ids'   => ['required', 'array', 'min:1'],
            'servicio_ids.*' => ['required', 'integer', 'exists:serviciocliente,idservicioCliente'],
            'baja_modo'      => ['required', 'in:ahora,periodo'],
            'comentario'     => ['nullable', 'string', 'max:500'],
            'mantener_sim'   => ['nullable', 'in:si,no'],
        ]);

        $serviceIds = array_values(array_map('intval', $validated['servicio_ids']));
        $modo = $validated['baja_modo'];
        $comentario = trim($validated['comentario'] ?? 'Servicio dado de baja desde Cuentas por Cobrar');
        $mantenerSim = $validated['mantener_sim'] ?? 'si';
        $usuario = (string) $request->session()->get('erp_auth.usuario', 'anonimo');
        $now = Carbon::now()->format('Y-m-d H:i:s');
        $today = Carbon::today();

        try {
            DB::transaction(function () use ($serviceIds, $modo, $comentario, $mantenerSim, $usuario, $now, $today): void {
                $services = DB::table('serviciocliente as sc')
                    ->whereIn('sc.idservicioCliente', $serviceIds)
                    ->get();

                foreach ($services as $svc) {
                    $svcId = $svc->idservicioCliente;
                    $vencimiento = $svc->fecheVencimiento ? Carbon::parse($svc->fecheVencimiento)->startOfDay() : null;
                    $isExpiredOrToday = $vencimiento ? $vencimiento->lte($today) : true;

                    if ($modo === 'ahora' || ($modo === 'periodo' && $isExpiredOrToday)) {
                        // 1. Inactivar servicio inmediatamente
                        DB::table('serviciocliente')->where('idservicioCliente', $svcId)->update([
                            'estado' => 'inactivo',
                        ]);

                        $deviceInfo = DB::table('detalle_serviciodispositivo')
                            ->where('servicioCliente_idservicioCliente', $svcId)
                            ->orderByDesc('iddetalle_serviciodispositivo')
                            ->first();

                        $deviceId = $deviceInfo->dispositivoCliente_iddispositivoCliente ?? null;
                        $phoneNumber = null;

                        if ($deviceId) {
                            $phoneNumber = DB::table('detnumerosdispositivo')
                                ->where('dispositivoCliente_iddispositivoCliente', $deviceId)
                                ->orderByDesc('fechaAsignacion')
                                ->value('numeroTelefonico_numeroTelefonico');

                            // Desvincular dispositivo del cliente
                            DB::table('dispositivocliente')
                                ->where('iddispositivoCliente', $deviceId)
                                ->update(['estado' => '0', 'fechaBaja' => $now]);

                            $latestStockDevice = DB::table('elementoalmacen')->where('imei', $deviceId)->first();
                            if ($latestStockDevice) {
                                $deviceState = (int) $latestStockDevice->estado;
                                DB::table('elementoalmacen')
                                    ->where('imei', $deviceId)
                                    ->update(['estado' => $this->stateAfterServiceDeactivation($deviceState)]);
                            }
                        }

                        // 2. Manejo de SIM y Número Telefónico
                        if ($phoneNumber) {
                            if ($mantenerSim === 'no') {
                                // No mantener: Numero y SIM se separan, ambos pasan a estado LIBRE (estado = '1')
                                $activeSimPair = DB::table('detallesimcard')
                                    ->where('numeroTelefonico_numeroTelefonico', $phoneNumber)
                                    ->where('estado', '0')
                                    ->first();

                                if ($activeSimPair) {
                                    DB::table('detallesimcard')
                                        ->where('iddetalleSimCard', $activeSimPair->iddetalleSimCard)
                                        ->update(['estado' => '1']); // desacoplado/libre

                                    DB::table('simcard')
                                        ->where('idsimCard', $activeSimPair->simCard_idsimCard)
                                        ->where('estado', '!=', '0')
                                        ->update(['estado' => '1']); // libre
                                }

                                DB::table('numerotelefonico')
                                    ->where('numeroTelefonico', $phoneNumber)
                                    ->where('estado', '!=', '0')
                                    ->update(['estado' => '1']); // libre
                            } else {
                                // Sí mantener: Numero y SIM se mantienen juntos para uso futuro (estado = '2' o preservado)
                                DB::table('numerotelefonico')
                                    ->where('numeroTelefonico', $phoneNumber)
                                    ->where('estado', '!=', '0')
                                    ->update(['estado' => '2']);

                                $activeSimPair = DB::table('detallesimcard')
                                    ->where('numeroTelefonico_numeroTelefonico', $phoneNumber)
                                    ->where('estado', '0')
                                    ->first();

                                if ($activeSimPair) {
                                    DB::table('simcard')
                                        ->where('idsimCard', $activeSimPair->simCard_idsimCard)
                                        ->where('estado', '!=', '0')
                                        ->update(['estado' => '2']);
                                }
                            }

                            // Desvincular número del dispositivo
                            if ($deviceId) {
                                DB::table('detnumerosdispositivo')
                                    ->where('dispositivoCliente_iddispositivoCliente', $deviceId)
                                    ->where('numeroTelefonico_numeroTelefonico', $phoneNumber)
                                    ->delete();
                            }
                        }

                        // 3. Historial de servicio
                        DB::table('historial_servicio')->insert([
                            'usuario_usuario' => $usuario,
                            'serv_usuario' => $usuario,
                            'servicioCliente_idservicioCliente' => $svcId,
                            'fecha_accion' => $now,
                            'motivo' => 'Dado de baja',
                            'descripcion' => $comentario . ' (Mantener SIM/Número: ' . ($mantenerSim === 'si' ? 'Sí' : 'No') . ')',
                            'doc_referencia' => $svc->docReferencia ?? null,
                            'dispositivo' => $deviceId,
                            'numerotelefono' => $phoneNumber,
                        ]);
                    } else {
                        // Baja programada al terminar periodo
                        DB::table('historial_servicio')->insert([
                            'usuario_usuario' => $usuario,
                            'serv_usuario' => $usuario,
                            'servicioCliente_idservicioCliente' => $svcId,
                            'fecha_accion' => $now,
                            'motivo' => 'Baja programada al finalizar periodo',
                            'descripcion' => $comentario . ' (Mantener SIM/Número: ' . ($mantenerSim === 'si' ? 'Sí' : 'No') . ')',
                            'doc_referencia' => $svc->docReferencia ?? null,
                            'dispositivo' => null,
                            'numerotelefono' => null,
                        ]);

                        DB::table('serviciocliente')
                            ->where('idservicioCliente', $svcId)
                            ->update([
                                'observacion' => DB::raw("CONCAT(COALESCE(observacion, ''), ' [Baja programada fin de periodo]')")
                            ]);
                    }
                }
            });
        } catch (\Throwable $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => 'No se pudo procesar la baja: ' . $e->getMessage()], 422);
            }
            return redirect()->route('modules.cuentasporcobrar', ['tab' => 'servicios'])
                ->with('error', 'No se pudo procesar la baja de los servicios: ' . $e->getMessage());
        }

        $msg = $modo === 'ahora' ? 'Servicio(s) dado(s) de baja correctamente.' : 'Baja programada al finalizar el periodo.';

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => $msg]);
        }

        return redirect()->route('modules.cuentasporcobrar', ['tab' => 'servicios'])->with('success', $msg);
    }

    public function getPaymentDetails(string $id): JsonResponse
    {
        $cxc = DB::table('cuentasporcobrar as c')
            ->leftJoin('cliente as cli', 'cli.idcliente', '=', 'c.cliente_idcliente')
            ->leftJoin('detallecxc as dc', 'dc.cuentasPorCobrar_idcuentasPorCobrar', '=', 'c.idcuentasPorCobrar')
            ->leftJoin('bancos as b', 'b.idbancos', '=', 'dc.bancos_idbancos')
            ->leftJoin('evidenciapago as ep', 'ep.idevidenciaPago', '=', 'dc.bancos_idbancos')
            ->leftJoin('moneda as m', 'm.idmoneda', '=', 'c.moneda_idmoneda')
            ->select([
                'c.idcuentasPorCobrar',
                'c.cliente_idcliente',
                'c.tipoCobro_idtipoCobros',
                'c.docReferencia',
                'c.montoOriginal',
                'c.montoActual',
                'c.fechaRegistro',
                'c.fechaCancelacion',
                'c.estado',
                'c.descripcion as cxc_descripcion',
                'c.docFactura',
                'c.moneda_idmoneda',
                DB::raw('COALESCE(cli.razonSocial, cli.nombreComercial, cli.idcliente) as cliente_nombre'),
                'dc.formaPago_idformaPago',
                'dc.bancos_idbancos',
                'dc.fechaPago',
                'dc.descripcion as detalle_descripcion',
                'b.informacion as banco_nombre',
                'ep.archivo as comprobante_archivo',
            ])
            ->where('c.idcuentasPorCobrar', $id)
            ->first();

        if (!$cxc) {
            return response()->json(['success' => false, 'message' => 'Cuenta por cobrar no encontrada'], 444);
        }

        $cxc->detalle_descripcion = trim((string) ($cxc->detalle_descripcion ?? ''))
            ?: trim((string) ($cxc->cxc_descripcion ?? ''));


        if (empty($cxc->comprobante_archivo) && !empty($cxc->bancos_idbancos)) {
            $evid = DB::table('evidenciapago')
                ->where('bancos_idbancos', $cxc->bancos_idbancos)
                ->latest('idevidenciaPago')
                ->value('archivo');

            if ($evid) {
                $cxc->comprobante_archivo = $evid;
            }
        }

        return response()->json(['success' => true, 'data' => $cxc]);
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
            'usar_saldo_favor' => ['sometimes', 'boolean'],
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

            $cxcIds = $request->input('cxc_ids', []);
            if (empty($cxcIds)) {
                $cxcIds = [$id];
            }

            $paymentResult = DB::transaction(function () use ($id, $cxcIds, $file, $docText, $serviceIds, $validated, $request, $realizarCobro): ?array {
                foreach ($cxcIds as $targetCxcId) {
                    $this->cxcService->registrarFactura(
                        $targetCxcId,
                        $file ?? ($docText ?: 'FAC-' . time()),
                        $docText,
                        !empty($serviceIds) ? $serviceIds : null
                    );

                    if (!empty($validated['descripcionFactura'])
                        || ((int) $targetCxcId === (int) $id && !$realizarCobro && (int) ($validated['adelanto_meses'] ?? 0) > 1)) {
                        $description = $validated['descripcionFactura'] ?? '';
                        $invoiceDescription = (int) $targetCxcId === (int) $id && !$realizarCobro
                            ? CxcService::descriptionWithAdvanceMonths(
                                $description,
                                (int) ($validated['adelanto_meses'] ?? 0)
                            )
                            : mb_substr($description, 0, 50);
                        DB::table('cuentasporcobrar')
                            ->where('idcuentasPorCobrar', $targetCxcId)
                            ->update(['descripcion' => $invoiceDescription]);
                    }
                }

                if (!$realizarCobro) {
                    if (!empty($validated['monto_factura'])) {
                        DB::table('cuentasporcobrar')
                            ->where('idcuentasPorCobrar', $id)
                            ->update([
                                'montoOriginal' => round((float) $validated['monto_factura'], 2),
                                'montoActual' => round((float) $validated['monto_factura'], 2),
                            ]);
                    }
                    return null;
                }

                if ((int) ($validated['adelanto_meses'] ?? 0) <= 1 && !empty($validated['monto_factura'])) {
                    DB::table('cuentasporcobrar')
                        ->where('idcuentasPorCobrar', $id)
                        ->update([
                            'montoOriginal' => round((float) $validated['monto_factura'], 2),
                            'montoActual' => round((float) $validated['monto_factura'], 2),
                        ]);
                }

                $paymentData = [
                    'entidadBancaria_identidadBancaria' => $validated['entidadBancaria_identidadBancaria'] ?? null,
                    'formaPago_idformaPago' => $validated['formaPago_idformaPago'] ?? null,
                    'tipoCobro_idtipoCobros' => $validated['tipoCobro_idtipoCobros'] ?? null,
                    'moneda_idmoneda' => $validated['moneda_idmoneda'] ?? null,
                    'currency_selector' => $validated['moneda_idmoneda'] ?? null,
                    'payment_currency_mode' => $request->input('factura_payment_currency_mode', 'same'),
                    'tipo_cambio' => $validated['tipo_cambio'] ?? null,
                    'monto_cancelado' => $validated['monto_cancelado'] ?? null,
                    'montoPago' => $validated['montoPago'] ?? null,
                    'adelanto_meses' => $validated['adelanto_meses'] ?? null,
                    'monto_factura' => $validated['monto_factura'] ?? null,
                    'modo_pago' => $request->input('factura_modo_pago', 'total'),
                    'usar_saldo_favor' => $request->boolean('usar_saldo_favor'),
                    'withholding_decision' => $validated['factura_withholding_decision'] ?? null,
                    'canCuotas' => $validated['canCuotas'] ?? null,
                    'cuotas_montos' => $request->input('factura_cuotas_montos', []),
                    'cuotas_fechas' => $request->input('factura_cuotas_fechas', []),
                    'fechaPago' => $validated['fechaPago'] ?? now()->format('Y-m-d'),
                    'fechaFin' => $validated['fechaFin'] ?? null,
                    'servicio_ids' => $serviceIds,
                    'renewal_cxc_id' => (int) $id,
                    'docReferencia' => $docText,
                    'descripcion' => $validated['descripcionPago'] ?? 'Pago y facturación de CXC',
                ];

                return $this->cxcService->procesarPagoGrupo($cxcIds, $paymentData, $request->file('comprobante'));
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
            'servicio_ids' => ['nullable', 'array'],
            'servicio_ids.*' => ['integer', 'distinct', 'exists:serviciocliente,idservicioCliente'],
            'idcuentasPorCobrar' => ['nullable'],
            'tipoCobro_idtipoCobros' => ['required', 'integer'],
            'formaPago_idformaPago' => ['required', 'integer'],
            'entidadBancaria_identidadBancaria' => ['required', 'integer', 'exists:entidadbancaria,identidadBancaria'],
            'modo_pago' => ['required', 'in:total,parcial'],
            'usar_saldo_favor' => ['sometimes', 'boolean'],
            'moneda_idmoneda' => ['nullable', 'integer', 'exists:moneda,idmoneda'],
            'currency_selector' => ['nullable', 'integer', 'exists:moneda,idmoneda'],
            'payment_currency_mode' => ['nullable', 'in:same,other'],
            'monto_cancelado' => ['nullable', 'numeric', 'min:0'],
            'precio_convertido' => ['nullable', 'numeric', 'gt:0'],
            'tipo_cambio' => ['nullable', 'numeric', 'gt:0'],
            'withholding_decision' => ['nullable', 'in:paid,pending,full_pending'],
            'montoPago' => ['nullable', 'numeric', 'min:0'],
            'canCuotas' => ['nullable', 'integer', 'min:1', 'max:60'],
            'cuotas_montos' => ['nullable', 'array'],
            'cuotas_montos.*' => ['nullable', 'numeric', 'gt:0'],
            'cuotas_fechas' => ['nullable', 'array'],
            'cuotas_fechas.*' => ['nullable', 'date'],
            'fechaPago' => ['nullable', 'date'],
            'fechaFin' => ['nullable', 'date'],
            'adelanto_meses' => ['nullable', 'integer', 'min:1', 'max:120'],
            'descripcion' => ['required', 'string', 'max:100'],
            'docReferencia' => ['nullable', 'string', 'max:15'],
            'comprobante' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
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

        try {
            $file = $request->file('comprobante');
            
            $cxcIds = $validated['cxc_ids'] ?? [];
            if (empty($cxcIds) && !empty($validated['idcuentasPorCobrar'])) {
                $cxcIds = [(int) $validated['idcuentasPorCobrar']];
            }

            if (empty($cxcIds) && !empty($validated['servicio_ids'])) {
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
                        ->whereIn('estado', ['PENDIENTE', 'VENCIDO', 'FACTURADO', 'Pendiente Pago parcial', 'Pendiente a credito', '1', '2'])
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
                        $cxcIds = [$cxcId];
                    }
                }
            }

            if (empty($cxcIds) && $request->input('cliente_idcliente')) {
                $cxcId = DB::table('cuentasporcobrar')
                    ->where('cliente_idcliente', $request->input('cliente_idcliente'))
                    ->whereIn('estado', ['PENDIENTE', 'VENCIDO', 'FACTURADO', 'Pendiente Pago parcial', 'Pendiente a credito', '1', '2'])
                    ->orderByDesc('fechaRegistro')
                    ->value('idcuentasPorCobrar');
                if ($cxcId) {
                    $cxcIds = [$cxcId];
                }
            }

            if (empty($cxcIds)) {
                throw new \RuntimeException('Debe especificar al menos una cuenta por cobrar válida.');
            }

            $serviceIds = !empty($validated['servicio_ids'])
                ? array_values(array_map('intval', $validated['servicio_ids']))
                : [];
            $paymentData = [
                'entidadBancaria_identidadBancaria' => $validated['entidadBancaria_identidadBancaria'],
                'formaPago_idformaPago' => $validated['formaPago_idformaPago'],
                'tipoCobro_idtipoCobros' => $validated['tipoCobro_idtipoCobros'],
                'moneda_idmoneda' => $validated['moneda_idmoneda'],
                'currency_selector' => $validated['currency_selector'] ?? null,
                'payment_currency_mode' => $validated['payment_currency_mode'] ?? 'same',
                'monto_cancelado' => $validated['monto_cancelado'] ?? null,
                'tipo_cambio' => $validated['tipo_cambio'] ?? null,
                'modo_pago' => $validated['modo_pago'],
                'montoPago' => $validated['montoPago'] ?? 0,
                'usar_saldo_favor' => $request->boolean('usar_saldo_favor'),
                'withholding_decision' => $validated['withholding_decision'] ?? null,
                'canCuotas' => $validated['canCuotas'] ?? null,
                'cuotas_montos' => $request->input('cuotas_montos', []),
                'cuotas_fechas' => $request->input('cuotas_fechas', []),
                'fechaPago' => $validated['fechaPago'] ?? now()->format('Y-m-d'),
                'fechaFin' => $validated['fechaFin'] ?? null,
                'adelanto_meses' => $validated['adelanto_meses'] ?? null,
                'docReferencia' => $validated['docReferencia'] ?? '',
                'descripcion' => $validated['descripcion'],
                'servicio_ids' => $serviceIds,
                'renewal_cxc_id' => (int) ($validated['idcuentasPorCobrar'] ?? ($cxcIds[0] ?? 0)),
            ];
            $paymentResult = $this->cxcService->procesarPagoGrupo($cxcIds, $paymentData, $file);
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
            ->whereIn('c.estado', ['1', '2', '4', 'PENDIENTE', 'FACTURADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito'])
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
            return $candidateEnd !== null && $candidateEnd->lt($periodCutoff);
        })->values();

        $paymentDatesByCxc = $cxcs->isNotEmpty()
            ? DB::table('detallecxc')
                ->whereIn('cuentasPorCobrar_idcuentasPorCobrar', $cxcs->pluck('idcuentasPorCobrar')->all())
                ->where('estado', '1')
                ->whereNotNull('fechaPago')
                ->selectRaw('cuentasPorCobrar_idcuentasPorCobrar as cxc_id, MAX(fechaPago) as fecha_pago')
                ->groupBy('cuentasPorCobrar_idcuentasPorCobrar')
                ->pluck('fecha_pago', 'cxc_id')
            : collect();

        $cxcs->transform(function ($cxc) use ($paymentDatesByCxc) {
            $desc = (string) ($cxc->descripcion ?? '');
            $range = '';
            if (preg_match('/(\d{2}[\/\.-]\d{2}[\/\.-]\d{4})\s+(?:a|al|-)\s+(\d{2}[\/\.-]\d{2}[\/\.-]\d{4})/i', $desc, $m)) {
                $range = $m[1] . ' a ' . $m[2];
                $cxc->periodo_inicio = $m[1];
                $cxc->periodo_fin = $m[2];
            }
            $cxc->rango_display = $range ?: $desc;
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
            $cxc->montoActualDisplay = number_format((float)$cxc->montoActual, 2);
            $paymentDate = $paymentDatesByCxc->get($cxc->idcuentasPorCobrar);
            $cxc->fechaDisplay = $paymentDate ? Carbon::parse($paymentDate)->format('d/m/Y') : '-';
            return $cxc;
        });

        $cliente = DB::table('cliente')
            ->where('idcliente', $clienteId)
            ->select('detraccion')
            ->first();

        $esRetencion = $cliente && (string)($cliente->detraccion ?? '') === '1';
        $saldoFavorDisponible = $currencyId
            ? $this->cxcService->saldoFavorDisponible($clienteId, $currencyId)
            : 0.0;

        return response()->json([
            'success' => true,
            'cxcs' => $cxcs,
            'can_select_previous_debts' => $hasCurrentOrFuturePeriod,
            'es_retencion' => $esRetencion,
            'saldo_a_favor_disponible' => $saldoFavorDisponible,
        ]);
    }

    public function revertirPago(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'motivo' => ['required', 'string', 'max:255'],
        ]);

        try {
            $result = $this->cxcService->revertirPagoCancelado(
                $id,
                (string) $request->session()->get('erp_auth.usuario', 'anonimo'),
                $validated['motivo']
            );

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Pago revertido correctamente. La cuenta volvió a estado ' . ($result['estado'] === '4' ? 'VENCIDO.' : 'PENDIENTE.'),
                    'result' => $result,
                ]);
            }

            return redirect()->route('modules.cuentasporcobrar', ['tab' => 'servicios'])
                ->with('success', 'Pago revertido correctamente. La cuenta volvió a estado ' . ($result['estado'] === '4' ? 'VENCIDO.' : 'PENDIENTE.'));
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
        ]);

        $amount = round((float) $validated['monto'], 2);
        $service = DB::transaction(function () use ($id, $amount) {
            $service = DB::table('serviciocliente')
                ->where('idservicioCliente', $id)
                ->lockForUpdate()
                ->first(['idservicioCliente', 'monto', 'moneda_idmoneda']);

            if (!$service) {
                abort(404, 'No se encontró el servicio del vehículo.');
            }

            DB::table('serviciocliente')
                ->where('idservicioCliente', $id)
                ->update(['monto' => $amount]);

            return $service;
        });

        $symbol = DB::table('moneda')->where('idmoneda', $service->moneda_idmoneda)->value('simbolo') ?: 'S/';

        return response()->json([
            'success' => true,
            'idservicioCliente' => $id,
            'monto' => $amount,
            'monto_display' => $symbol . ' ' . number_format($amount, 2, '.', ''),
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
                ['name' => 'descripcion', 'type' => 'text', 'label' => 'Descripción', 'required' => false, 'maxlength' => 50, 'helpText' => 'Breve descripción.'],
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
                        ['value' => 'Cancelado Detracción', 'label' => 'Cancelado Detracción'],
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

        $currentState = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $id)
            ->value('estado');
        if (in_array((string) $currentState, ['2', '3', '4', 'FACTURADO', 'CANCELADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito', 'Cancelado Detracción'], true)) {
            return redirect()->route('modules.cuentasporcobrar')
                ->with('error', 'Las cuentas históricas no se pueden editar.');
        }

        $validated = $request->validate([
            'cliente_idcliente' => ['required', 'string', 'exists:cliente,idcliente'],
            'tipoCobro_idtipoCobros' => ['required', 'integer', 'exists:tipocobro,idtipoCobros'],
            'docReferencia' => ['nullable', 'string', 'max:15', 'regex:' . self::SAFE_TEXT_REGEX],
            'descripcion' => ['nullable', 'string', 'max:50', 'regex:' . self::SAFE_TEXT_REGEX],
            'montoOriginal' => ['required', 'numeric', 'min:0'],
            'montoActual' => ['required', 'numeric', 'min:0'],
            'canCuotas' => ['nullable', 'numeric', 'min:1'],
            'fechaRegistro' => ['nullable', 'date'],
            'fechaCancelacion' => ['nullable', 'date'],
            'estado' => ['required', 'in:1,2,3,4,Pendiente Pago parcial,Pendiente a credito,Cancelado Detracción'],
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

    private function applyQuotationFilters($query, Request $request, string $defaultStatus = ''): void
    {
        $search = trim((string) $request->query('quote_q', ''));
        $group = trim((string) $request->query('quote_group', ''));
        $client = trim((string) $request->query('quote_client', ''));
        $currency = trim((string) $request->query('quote_currency', ''));
        $service = trim((string) $request->query('quote_service', ''));
        $date = trim((string) $request->query('quote_date', ''));

        if ($search !== '') {
            $term = '%' . $search . '%';
            $query->where(function ($builder) use ($term) {
                $builder->where('c.nroCotizacion', 'like', $term)
                    ->orWhere('c.batch_id', 'like', $term)
                    ->orWhere('cli.razonSocial', 'like', $term)
                    ->orWhere('cli.nombreComercial', 'like', $term);
            });
        }

        if ($group !== '') {
            $query->where('c.batch_id', 'like', '%' . $group . '%');
        }

        if ($client !== '') {
            $term = '%' . $client . '%';
            $query->where(function ($builder) use ($term) {
                $builder->where('c.cliente_idcliente', 'like', $term)
                    ->orWhere('cli.razonSocial', 'like', $term)
                    ->orWhere('cli.nombreComercial', 'like', $term);
            });
        }

        if ($currency !== '') {
            $query->where('m.detalle', 'like', '%' . $currency . '%');
        }

        if (in_array($service, ['EQUIPAMIENTO', 'PLANES', 'SERVICIOS TÉCNICOS'], true)) {
            $query->whereRaw("COALESCE(cotizacion_tipo.servicio, 'EQUIPAMIENTO') = ?", [$service]);
        }

        $status = trim((string) $request->query('quote_status', $defaultStatus));
        if ($status === '2') {
            $query->where('c.estado', '2');
        } elseif ($status === '1') {
            $query->where('c.estado', '1');
        } else {
            $query->whereIn('c.estado', ['1', '2']);
        }

        if ($date !== '') {
            $query->whereDate('c.fechaHoraEmision', $date);
        }
    }

    private function buildPaymentFileName(string $quoteId, string $extension): string
    {
        $safeId = preg_replace('/[^A-Za-z0-9_-]+/', '_', $quoteId) ?: 'cotizacion';
        $safeExtension = strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $extension) ?: 'bin');

        return $safeId . '_' . now()->format('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $safeExtension;
    }

    private function currencySymbol(?string $currency): string
    {
        $currency = mb_strtolower(trim((string) ($currency ?? '')), 'UTF-8');

        if (str_contains($currency, 'dolar') || str_contains($currency, 'dólar') || str_contains($currency, '$')) {
            return '$';
        }

        if (str_contains($currency, 'euro') || str_contains($currency, '€')) {
            return '€';
        }

        return 'S/';
    }

    private function formatServicioPeriodo(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '' || trim((string) $value) === 'No') {
            return '';
        }

        if (!is_numeric($value)) {
            return trim((string) $value);
        }

        return match ((int) $value) {
            30 => 'Mensual',
            90 => '3 Meses',
            180 => '6 Meses',
            365 => '12 Meses',
            730 => '24 Meses',
            1095 => '36 Meses',
            1460 => '48 Meses',
            default => trim((string) $value),
        };
    }

    private function normalizeServicioPeriodoDays(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '' || trim((string) $value) === 'No') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
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
                fn ($join) => $join->on('pago.cxc_id', '=', 'c.idcuentasPorCobrar')
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
        $format = fn (mixed $amount): string => $symbol . ' ' . number_format((float) $amount, 2, '.', ',');
        $parts = [];

        if ((float) ($breakdown['deduction'] ?? 0) > 0) {
            $label = !empty($breakdown['retencion']) ? 'Retención (3%) aplicada' : 'Detracción (12%) aplicada';
            $parts[] = $label . ': ' . $format($breakdown['deduction']);
        }
        if ((float) ($breakdown['credit_applied'] ?? 0) > 0) {
            $parts[] = 'Saldo a favor aplicado: ' . $format($breakdown['credit_applied']);
        }
        if ((float) ($breakdown['cash_applied'] ?? 0) > 0) {
            $parts[] = 'Efectivo aplicado: ' . $format($breakdown['cash_applied']);
        }
        if ((float) ($breakdown['new_credit'] ?? 0) > 0) {
            $parts[] = 'Nuevo saldo a favor: ' . $format($breakdown['new_credit']);
        }
        if ((float) ($breakdown['remaining_debt'] ?? 0) > 0) {
            $parts[] = 'Saldo pendiente: ' . $format($breakdown['remaining_debt']);
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
            ->map(fn($row): array => [
                'value' => (string) $row->identidadBancaria,
                'label' => trim((string) ($row->descripcion ?? ''))
                    ?: (trim((string) ($row->razonSocial ?? '')) ?: 'Entidad bancaria #' . $row->identidadBancaria),
            ]);
    }

    private function stateAfterServiceDeactivation(int $state): int
    {
        return match ($state) {
            3 => 2,
            5 => 4,
            2, 4 => $state,
            default => 0,
        };
    }
}
