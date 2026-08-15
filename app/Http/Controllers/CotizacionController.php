<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Export\ExportableList;
use App\Http\Controllers\Permission\HandlesResourceLock;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Services\CorrelativoService;
use App\Services\CotizacionService;
use App\Services\TicketsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Validation\Rule;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class CotizacionController extends Controller
{
    use ExportableList;
    use HandlesResourceLock;

    protected const SAFE_TEXT_REGEX = '/^[^;<>`]+$/u';

    private const LOCK_RESOURCE = 'ventas.cotizaciones';

    private CotizacionService $cotizacionService;
    private TicketsService $ticketsService;

    public function __construct(CotizacionService $cotizacionService, TicketsService $ticketsService)
    {
        $this->cotizacionService = $cotizacionService;
        $this->ticketsService = $ticketsService;
    }

    public function index(Request $request): View
    {
        $baseQuery = $this->baseQuery($request);
        $statsQuery = clone $baseQuery;

        $items = $baseQuery
            ->orderByDesc('c.nroCotizacion')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $items->through(function ($row) {
            $row->moneda_simbolo = $this->currencySymbol($row->moneda_detalle ?? null);
            $row->total_label = $this->formatMoney(round((float) ($row->total ?? 0), 0), $row->moneda_simbolo);
            $row->subtotal_label = $this->formatMoney($row->subtotal ?? null, $row->moneda_simbolo);
            $row->fecha_emision_label = $row->fechaHoraEmision ? date('d/m/Y H:i', strtotime($row->fechaHoraEmision)) : '-';
            // Cliente legible: respetar `cliente_label` devuelto por la consulta si existe
            $row->cliente_label = isset($row->cliente_label) && trim((string) ($row->cliente_label ?? '')) !== ''
                ? $row->cliente_label
                : ($row->razonSocial ?? $row->nombreComercial ?? '-');
            $formaPago = trim((string) ($row->formaPago_detalle ?? ''));
            $tiempo = (int) ($row->formaPago_tiempo ?? 0);
            if (mb_strtolower($formaPago, 'UTF-8') === 'credito' && $tiempo > 0) {
                $row->formaPago_label = 'Credito (' . $tiempo . ' DÍAS)';
            } elseif ($formaPago !== '') {
                $row->formaPago_label = $formaPago;
            } else {
                $row->formaPago_label = '-';
            }

            $nombreVendedor = trim((string) ($row->personal_nombre ?? ''));
            $apellidoVendedor = trim((string) ($row->personal_apellido ?? ''));
            if ($nombreVendedor !== '' || $apellidoVendedor !== '') {
                $row->personal_label = trim($nombreVendedor . ' ' . $apellidoVendedor);
            } elseif (trim((string) ($row->personal_dniPersonal ?? '')) !== '') {
                $row->personal_label = trim((string) $row->personal_dniPersonal);
            } else {
                $row->personal_label = '-';
            }

            if (!isset($row->estado) || $row->estado === null || $row->estado === '') {
                $row->estado_label = '-';
            } else {
                $row->estado_label = $this->formatCotizacionEstadoHtmlLabel($row->estado);
            }

            $row->is_vigencia_expired = $this->isCotizacionExpired($row);
            $estado = (string) ($row->estado ?? '');
            $notEditableStates = [CotizacionService::STATE_FINALIZADO, CotizacionService::STATE_EJECUTADO_SP, CotizacionService::STATE_ANULADO];
            $row->canEdit = !in_array($estado, $notEditableStates, true) && !$row->is_vigencia_expired;
            $row->canApprove = ($estado === CotizacionService::STATE_GENERADO) && !$row->is_vigencia_expired && $row->canEdit;
            $row->approveRoute = $row->canApprove ? route('modules.ventas.cotizaciones.approve', ['id' => $row->nroCotizacion]) : null;
            $row->canDelete = !in_array($estado, [CotizacionService::STATE_FINALIZADO, CotizacionService::STATE_EJECUTADO_SP, CotizacionService::STATE_ANULADO], true);
            $row->canAnular = ($estado === CotizacionService::STATE_GENERADO);
            $row->anularRoute = $row->canAnular ? route('modules.ventas.cotizaciones.anular', ['id' => $row->nroCotizacion]) : null;
            $row->download_link = '<a href="' . route('modules.ventas.cotizaciones.pdf', ['id' => $row->nroCotizacion]) . '" data-download-cotizacion="' . e($row->nroCotizacion) . '" class="cursor-pointer flex items-center p-2 transition duration-300 ease-in-out rounded-md hover:bg-slate-200/60 dark:bg-darkmode-600 dark:hover:bg-darkmode-400 dropdown-item text-slate-600 w-full text-left" title="Descargar PDF"><i data-lucide="download" class="mr-1 h-4 w-4 stroke-[1.3]"></i>Descargar</a>';
            $row->copyRoute = route('modules.ventas.cotizaciones.create', ['copy_from' => $row->nroCotizacion]);
            $batchId = $row->batch_id ?? null;
            $groupText = $this->formatCotizacionGroupLabel($batchId);
            if ($batchId) {
                $groupDownloadUrl = route('modules.ventas.cotizaciones.pdf-grupo', ['batch_id' => $batchId]);
                $row->group_label = '<a href="' . $groupDownloadUrl . '" class="font-medium text-slate-700 hover:text-primary hover:underline  whitespace-nowrap " title="Descargar PDF Grupal" target="_blank">' . e($groupText) . '</a>';
            } else {
                $row->group_label = e($groupText);
            }

            return $row;
        });

        $stats = [
            'total' => (clone $statsQuery)->count('c.nroCotizacion'),
            'vendedores' => (clone $statsQuery)->distinct('c.personal_dniPersonal')->count('c.personal_dniPersonal'),
        ];

        return view('ventas.cotizaciones.cotizacion', [
            'title' => 'Cotizaciones',
            'singularTitle' => 'Cotización',
            'items' => $items,
            'createRoute' => route('modules.ventas.cotizaciones.create'),
            'editRoute' => 'modules.ventas.cotizaciones.edit',
            'showRoute' => 'modules.ventas.cotizaciones.edit',
            'destroyRoute' => 'modules.ventas.cotizaciones.destroy',
            'bulkDestroyRoute' => route('modules.ventas.cotizaciones.bulk-destroy'),
            'identifierKey' => 'nroCotizacion',
            'lockResource' => self::LOCK_RESOURCE,
            'showActionsColumn' => true,
            'columns' => [
                ['key' => 'nroCotizacion', 'label' => 'Nro. Cotización', 'type' => 'text'],
                ['key' => 'cliente_label', 'label' => 'Cliente', 'type' => 'text', 'wrap' => true],
                ['key' => 'vigencia_detalle', 'label' => 'Vigencia Oferta', 'type' => 'text'],
                ['key' => 'formaPago_label', 'label' => 'Formato de pago', 'type' => 'text'],
                ['key' => 'personal_label', 'label' => 'Vendedor', 'type' => 'text'],
                ['key' => 'subtotal_label', 'label' => 'Subtotal', 'type' => 'text'],
                ['key' => 'total_label', 'label' => 'Total', 'type' => 'text'],
                ['key' => 'fecha_emision_label', 'label' => 'Fecha Emisión', 'type' => 'text'],
                ['key' => 'group_label', 'label' => 'Grupo', 'type' => 'custom'],
                ['key' => 'estado_label', 'label' => 'Estado', 'type' => 'custom'],
            ],
            'stats' => [
                ['label' => 'Total de Cotizaciones', 'value' => $stats['total']],
                ['label' => 'Total de Vendedores', 'value' => $stats['vendedores'] ?? 0],
            ],
            'filters' => [
                [
                    'name' => 'vigencia_search',
                    'label' => 'Vigencia de oferta',
                    'type' => 'text',
                    'placeholder' => 'Buscar por vigencia',
                ],
                [
                    'name' => 'forma_pago_search',
                    'label' => 'Formato de pago',
                    'type' => 'text',
                    'placeholder' => 'Buscar por forma de pago',
                ],
                [
                    'name' => 'vendedor_search',
                    'label' => 'Vendedor (DNI)',
                    'type' => 'text',
                    'placeholder' => 'Buscar por DNI del vendedor',
                ],
                [
                    'name' => 'subtotal_search',
                    'label' => 'Subtotal',
                    'type' => 'text',
                    'placeholder' => 'Buscar por subtotal',
                ],
                [
                    'name' => 'fecha_search',
                    'label' => 'Fecha Emisión',
                    'type' => 'date',
                    'placeholder' => 'Buscar por fecha',
                ],
                [
                    'name' => 'total_search',
                    'label' => 'Total',
                    'type' => 'text',
                    'placeholder' => 'Buscar por total',
                ],
                [
                    'name' => 'estado_search',
                    'label' => 'Estado',
                    'type' => 'select',
                    'options' => [
                        ['value' => '0', 'label' => 'Generado'],
                        ['value' => '1', 'label' => 'Aprobado(SP)'],
                        ['value' => '2', 'label' => 'Aprobado'],
                        ['value' => '3', 'label' => 'Ejecutado(SP)'],
                        ['value' => '4', 'label' => 'Finalizado'],
                        ['value' => '5', 'label' => 'Anulado'],
                    ],
                ],
            ],
            'exportRoutes' => [
                'pdf' => route('modules.ventas.cotizaciones.export', ['format' => 'pdf']),
                'xlsx' => route('modules.ventas.cotizaciones.export', ['format' => 'xlsx']),
            ],
        ]);
    }

    public function export(Request $request, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $selectedIds = $request->input('selectedIds', []);

        $rows = $this->baseQuery($request)->orderByDesc('c.fechaHoraEmision')->get();

        $rows->transform(function ($row) {
            $row->moneda_simbolo = $this->currencySymbol($row->moneda_detalle ?? null);
            $row->total_label = $this->formatMoney($row->total ?? null, $row->moneda_simbolo);
            $row->fecha_emision_label = $row->fechaHoraEmision ? date('d/m/Y H:i', strtotime($row->fechaHoraEmision)) : '-';
            $row->cliente_label = isset($row->cliente_label) && trim((string) ($row->cliente_label ?? '')) !== ''
                ? $row->cliente_label
                : ($row->razonSocial ?? $row->nombreComercial ?? '-');
            if (!isset($row->estado) || $row->estado === null || $row->estado === '') {
                $row->estado_label = '-';
            } else {
                $row->estado_label = $this->formatCotizacionEstadoName($row->estado);
            }
            $row->group_label = $this->formatCotizacionGroupLabel($row->batch_id ?? null);
            return $row;
        });

        $columns = [
            ['key' => 'nroCotizacion', 'label' => 'Nro. Cotización'],
            ['key' => 'cliente_label', 'label' => 'Cliente'],
            ['key' => 'vigencia_detalle', 'label' => 'Vigencia de oferta'],
            // ['key' => 'entidadCotizadora', 'label' => 'Entidad Cotizadora'],
            ['key' => 'group_label', 'label' => 'Grupo / Batch ID'],
            ['key' => 'estado_label', 'label' => 'Estado'],
            ['key' => 'fecha_emision_label', 'label' => 'Fecha Emisión'],
            ['key' => 'total_label', 'label' => 'Total'],
        ];

        $filename = 'cotizaciones_export_' . now()->format('Ymd_His') . '.' . $format;

        if (!empty($selectedIds) && is_array($selectedIds)) {
            $rows = $this->baseQuery($request)->whereIn('c.nroCotizacion', array_values($selectedIds))->orderBy('c.fechaHoraEmision')->get();

            $rows->transform(function ($row) {
                $row->moneda_simbolo = $this->currencySymbol($row->moneda_detalle ?? null);
                $row->total_label = $this->formatMoney($row->total ?? null, $row->moneda_simbolo);
                $row->fecha_emision_label = $row->fechaHoraEmision ? date('d/m/Y H:i', strtotime($row->fechaHoraEmision)) : '-';
                $row->cliente_label = isset($row->cliente_label) && trim((string) ($row->cliente_label ?? '')) !== ''
                    ? $row->cliente_label
                    : ($row->razonSocial ?? $row->nombreComercial ?? '-');
                if (!isset($row->estado) || $row->estado === null || $row->estado === '') {
                    $row->estado_label = '-';
                } else {
                    $row->estado_label = $this->formatCotizacionEstadoName($row->estado);
                }
                $row->group_label = $this->formatCotizacionGroupLabel($row->batch_id ?? null);
                return $row;
            });

            if ($format === 'xlsx') {
                return $this->exportXlsxResponse($rows, $columns, $filename);
            }

            return $this->exportPdfResponse($rows, $columns, 'Listado de Cotizaciones', $filename);
        }

        if ($format === 'xlsx') {
            return $this->exportXlsxResponse($rows, $columns, $filename);
        }

        return $this->exportPdfResponse($rows, $columns, 'Listado de Cotizaciones', $filename);
    }

    public function create(Request $request): View
    {
        $data = $this->cotizacionService->prepareCreateViewData($request);

        return view('ventas.cotizaciones.cotizacion-form', [
            'title' => 'Nueva Cotización',
            'moduleTitle' => 'Cotizaciones',
            'mode' => 'create',
            'formAction' => route('modules.ventas.cotizaciones.store'),
            'backRoute' => route('modules.ventas.cotizaciones.index'),
            'record' => $data['record'],
            'fields' => $data['fields'],
            'readOnly' => false,
        ] + ['almacenes' => $data['almacenes'], 'paquetes' => $data['paquetes'], 'detalles' => $data['detalles']]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateCotizacion($request, 'create');
        $downloadAfterSave = $request->boolean('download_after_save', false);
        $includeImage = $request->input('include_image', '1') === '1' ? '1' : '0';
        $groupConfirm = $request->boolean('group_confirm', false);

        if ($request->has('cotizaciones')) {
            $cotizaciones = $request->input('cotizaciones');
            $downloadUrls = [];
            $createdIds = [];
            $batchId = null;

            DB::transaction(function () use ($validated, $cotizaciones, $request, &$downloadUrls, &$createdIds, &$batchId, $includeImage, $groupConfirm): void {
                $nextIndividualBatchId = null;
                if ($groupConfirm && is_array($cotizaciones) && count($cotizaciones) >= 2) {
                    $batchId = $this->generateBatchId('GRP');
                }

                foreach ($cotizaciones as $tipo => $datosCotizacion) {
                    $payload = $this->preparePayload($validated);
                    // Determinar entidadCotizadora: preferir valor enviado, sino derivar desde tipodocumento, sino usar 'Cotización'
                    if ($request->has('entidadCotizadora') && trim((string) $request->input('entidadCotizadora', '')) !== '') {
                        $payload['entidadCotizadora'] = trim((string) $request->input('entidadCotizadora'));
                    } else {
                        $tipoForEntity = $payload['tipoDocumento_idtipoDocumento'] ?? null;
                        if (!empty($tipoForEntity)) {
                            $detalle = DB::table('tipodocumento')->where('idtipoDocumento', $tipoForEntity)->value('detalle');
                            $payload['entidadCotizadora'] = $detalle ? trim((string) $detalle) : 'Cotización';
                        } else {
                            $payload['entidadCotizadora'] = 'Cotización';
                        }
                    }
                    $payload['estado'] = CotizacionService::STATE_GENERADO;

                    // Merge group-specific general values if present
                    $payload['vigenciaOferta_idvigenciaOferta'] = $datosCotizacion['vigenciaOferta_idvigenciaOferta'] ?? $validated['vigenciaOferta_idvigenciaOferta'] ?? null;
                    $payload['formaPago_idformaPago'] = $datosCotizacion['formaPago_idformaPago'] ?? $validated['formaPago_idformaPago'] ?? null;
                    $payload['moneda_idmoneda'] = $datosCotizacion['moneda_idmoneda'] ?? $validated['moneda_idmoneda'] ?? null;
                    $payload['comentario'] = $datosCotizacion['comentario'] ?? $validated['comentario'] ?? null;

                    // Sobrescribir totales por cotización
                    $payload['subtotal'] = $datosCotizacion['subtotal'] ?? 0;
                    $payload['descuento'] = $datosCotizacion['descuento'] ?? 0;
                    $payload['igv'] = $datosCotizacion['igv'] ?? 0;
                    $payload['total'] = $datosCotizacion['total'] ?? 0;

                    $tipoId = (int) ($payload['tipoDocumento_idtipoDocumento'] ?? 0);
                    if ($tipoId > 0) {
                        $alloc = CorrelativoService::allocateNext($tipoId, 'cotizacion', 'nroCotizacion');
                        $payload['nroCotizacion'] = $alloc['formatted'];
                    }

                    if (empty($payload['fechaHoraEmision'])) {
                        $payload['fechaHoraEmision'] = now()->format('Y-m-d H:i:s');
                    }

                    if ($batchId !== null) {
                        $payload['batch_id'] = $batchId;
                    } else {
                        if ($nextIndividualBatchId === null) {
                            $nextIndividualBatchId = $this->generateBatchId('IND');
                        } else {
                            $nextIndividualBatchId = $this->incrementBatchId($nextIndividualBatchId);
                        }
                        $payload['batch_id'] = $nextIndividualBatchId;
                    }

                    DB::table('cotizacion')->insert($payload);
                    $createdIds[] = $payload['nroCotizacion'];
                    $downloadUrls[] = route('modules.ventas.cotizaciones.pdf', [
                        'id' => $payload['nroCotizacion'],
                        'include_image' => $includeImage,
                    ]);

                    $detalles = $datosCotizacion['detalle'] ?? [];
                    $rows = [];
                    foreach ($detalles as $d) {
                        $precio = isset($d['precioUnitario']) ? (float) $d['precioUnitario'] : 0.0;
                        $cantidad = isset($d['cantidad']) ? (float) $d['cantidad'] : 0.0;
                        $descuento = isset($d['descuento']) ? (float) $d['descuento'] : 0.0;
                        $total = round($cantidad * $precio * (1 - ($descuento / 100)), 2);

                        $rows[] = [
                            'cotizacion_nroCotizacion' => $payload['nroCotizacion'],
                            'almacen_idalmacen' => isset($d['almacen_idalmacen']) ? (int) $d['almacen_idalmacen'] : null,
                            'precioUnitario' => $precio,
                            'cantidad' => $cantidad,
                            'descuento' => $descuento,
                            'total' => $total,
                        ];
                    }

                    if (!empty($rows)) {
                        DB::table('detallecotizacion')->insert($rows);
                    }

                    $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $payload['nroCotizacion'], 'created');
                }
            });

            if ($downloadAfterSave && !empty($createdIds)) {
                if ($groupConfirm && is_array($cotizaciones) && count($cotizaciones) >= 2 && !empty($batchId)) {
                    $downloadUrls = [route('modules.ventas.cotizaciones.pdf-grupo', [
                        'batch_id' => $batchId,
                        'include_image' => $includeImage,
                    ])];
                }

                return redirect()
                    ->route('modules.ventas.cotizaciones.index')
                    ->with('success', 'Cotizaciones generadas correctamente.')
                    ->with('download_pdf_urls', $downloadUrls)
                    ->with('download_pdf_url', $downloadUrls[0] ?? null);
            }

            return redirect()
                ->route('modules.ventas.cotizaciones.index')
                ->with('success', 'Cotizaciones generadas correctamente.');
        }

        // LOGICA ANTERIOR PARA UNA SOLA COTIZACION
        $payload = $this->preparePayload($validated);
        if ($request->has('entidadCotizadora') && trim((string) $request->input('entidadCotizadora', '')) !== '') {
            $payload['entidadCotizadora'] = trim((string) $request->input('entidadCotizadora'));
        } else {
            $tipoForEntity = $payload['tipoDocumento_idtipoDocumento'] ?? null;
            if (!empty($tipoForEntity)) {
                $detalle = DB::table('tipodocumento')->where('idtipoDocumento', $tipoForEntity)->value('detalle');
                $payload['entidadCotizadora'] = $detalle ? trim((string) $detalle) : 'Cotización';
            } else {
                $payload['entidadCotizadora'] = 'Cotización';
            }
        }

        $payload['estado'] = CotizacionService::STATE_GENERADO;
        $payload['batch_id'] = $this->generateBatchId('IND');

        $tipoId = (int) ($payload['tipoDocumento_idtipoDocumento'] ?? 0);
        if ($tipoId > 0) {
            $alloc = CorrelativoService::allocateNext($tipoId, 'cotizacion', 'nroCotizacion');
            $payload['nroCotizacion'] = $alloc['formatted'];
        }

        if (empty($payload['fechaHoraEmision'])) {
            $payload['fechaHoraEmision'] = now()->format('Y-m-d H:i:s');
        }

        DB::transaction(function () use ($payload, $request): void {
            DB::table('cotizacion')->insert($payload);

            $detalles = $request->input('detalle', []);
            $rows = [];
            foreach ($detalles as $d) {
                $precio = isset($d['precioUnitario']) ? (float) $d['precioUnitario'] : 0.0;
                $cantidad = isset($d['cantidad']) ? (float) $d['cantidad'] : 0.0;
                $descuento = isset($d['descuento']) ? (float) $d['descuento'] : 0.0;
                $total = round($cantidad * $precio * (1 - ($descuento / 100)), 2);

                $rows[] = [
                    'cotizacion_nroCotizacion' => $payload['nroCotizacion'],
                    'almacen_idalmacen' => isset($d['almacen_idalmacen']) ? (int) $d['almacen_idalmacen'] : null,
                    'precioUnitario' => $precio,
                    'cantidad' => $cantidad,
                    'descuento' => $descuento,
                    'total' => $total,
                ];
            }

            if (!empty($rows)) {
                DB::table('detallecotizacion')->insert($rows);
            }

            $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $payload['nroCotizacion'], 'created');
        });

        $downloadUrl = route('modules.ventas.cotizaciones.pdf', [
            'id' => $payload['nroCotizacion'],
            'include_image' => $includeImage,
        ]);

        if ($downloadAfterSave) {
            return redirect()
                ->route('modules.ventas.cotizaciones.index')
                ->with('success', 'Registro de cotización creado correctamente.')
                ->with('download_pdf_urls', [$downloadUrl])
                ->with('download_pdf_url', $downloadUrl);
        }

        return redirect()
            ->route('modules.ventas.cotizaciones.index')
            ->with('success', 'Registro de cotización creado correctamente.');
    }

    public function downloadPdf(Request $request, string $id)
    {
        $includeImage = $request->input('include_image', '1') === '1';
        $quote = DB::table('cotizacion as c')
            ->leftJoin('cliente as cli', 'c.cliente_idcliente', '=', 'cli.idcliente')
            ->leftJoin('tipodocumento as td', 'c.tipoDocumento_idtipoDocumento', '=', 'td.idtipoDocumento')
            ->leftJoin('vigenciaoferta as v', 'c.vigenciaOferta_idvigenciaOferta', '=', 'v.idvigenciaOferta')
            ->leftJoin('formapago as fp', 'c.formaPago_idformaPago', '=', 'fp.idformaPago')
            ->leftJoin('moneda as m', 'c.moneda_idmoneda', '=', 'm.idmoneda')
            ->leftJoin('personal as p', 'c.personal_dniPersonal', '=', 'p.dniPersonal')
            ->select([
                'c.*',
                'td.detalle as tipoDocumento_nombre',
                DB::raw("COALESCE(cli.razonSocial, cli.nombreComercial, c.cliente_idcliente, 'Cliente sin nombre') as cliente_label"),
                'v.detalle as vigencia_detalle',
                'fp.detalle as formaPago_detalle',
                'fp.tiempo as formaPago_tiempo',
                'm.detalle as moneda_detalle',
                'p.nombre as personal_nombre',
                'p.apellido as personal_apellido',
            ])
            ->where('c.nroCotizacion', $id)
            ->first();

        if (!$quote) {
            abort(404);
        }

        $formaPagoDetalle = trim((string) ($quote->formaPago_detalle ?? ''));
        $formaPagoTiempo = (int) ($quote->formaPago_tiempo ?? 0);
        if ($formaPagoTiempo > 0 && !str_contains(mb_strtolower($formaPagoDetalle), 'contado')) {
            $formaPagoDetalle .= ' (' . $formaPagoTiempo . ' días)';
        }
        $quote->formaPago_detalle = $formaPagoDetalle;

        $quote->moneda_simbolo = $this->currencySymbol($quote->moneda_detalle ?? null);

        $items = DB::table('detallecotizacion as d')
            ->leftJoin('almacen as a', 'd.almacen_idalmacen', '=', 'a.idalmacen')
            ->leftJoin('tipoelemento as te', 'a.tipoElemento_idtipoElemento', '=', 'te.idtipoElemento')
            ->where('d.cotizacion_nroCotizacion', $id)
            ->select([
                'a.detalle as producto',
                'a.imagen as producto_imagen',
                'a.periodo as periodo',
                'te.nombre as tipo_nombre',
                'd.precioUnitario',
                'd.cantidad',
                'd.descuento',
                'd.total',
            ])
            ->orderBy('a.detalle')
            ->get()
            ->map(function ($item) use ($quote) {
                $item->tipo_nombre = trim((string) ($item->tipo_nombre ?? ''));
                $item->precio_label = $this->formatMoney($item->precioUnitario, $quote->moneda_simbolo);
                $item->total_label = $this->formatMoney($item->total, $quote->moneda_simbolo);
                $item->descuento_label = is_numeric($item->descuento) ? number_format($item->descuento, 2, '.', ',') . '%' : '-';
                return $item;
            });

        $importe = (float) ($quote->subtotal ?? 0);
        $descuentoPercent = (float) ($quote->descuento ?? 0);
        $descuentoAmount = round($importe * $descuentoPercent / 100, 2);
        $subtotalAfterDiscount = round($importe - $descuentoAmount, 2);
        $igvAmount = round($subtotalAfterDiscount * 0.18, 2);
        $totalGeneral = round($subtotalAfterDiscount + $igvAmount, 2);

        $sectionTitle = 'EQUIPAMIENTO';
        foreach ($items as $item) {
            $tipo = strtoupper($item->tipo_nombre ?? '');
            if (str_contains($tipo, 'SERVIC')) {
                $sectionTitle = 'SERVICIOS TÉCNICOS';
                break;
            }
            if (str_contains($tipo, 'PLAN')) {
                $sectionTitle = 'PLANES';
                break;
            }
        }

        $viewName = $includeImage ? 'ventas.cotizaciones.pdf-img' : 'ventas.cotizaciones.pdf';

        $pdf = Pdf::loadView($viewName, [
            'quote' => $quote,
            'items' => $items,
            'section_title' => $sectionTitle,
            'importe_label' => $this->formatMoney($importe, $quote->moneda_simbolo),
            'descuento_amount_label' => $this->formatMoney($descuentoAmount, $quote->moneda_simbolo),
            'subtotal_after_discount_label' => $this->formatMoney($subtotalAfterDiscount, $quote->moneda_simbolo),
            'igv_amount_label' => $this->formatMoney($igvAmount, $quote->moneda_simbolo),
            'total_general_label' => $this->formatMoney($totalGeneral, $quote->moneda_simbolo),
            'descuento_percent' => $descuentoPercent,
            'include_image' => $includeImage,
        ]);

        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $pageText = 'Página {PAGE_NUM} de {PAGE_COUNT}';
        $font = 'helvetica';
        $fontSize = 10;
        $marginRight = -95;
        $marginBottom = 28;
        $textWidth = $canvas->get_text_width($pageText, $font, $fontSize);
        $x = max(30, $canvas->get_width() - $textWidth - $marginRight);
        $y = $canvas->get_height() - $marginBottom;
        $canvas->page_text($x, $y, $pageText, $font, $fontSize, [0, 0, 0]);

        return $pdf->download($this->buildQuotePdfFileName($quote, $id));
    }

    public function downloadGroupPdf(Request $request, string $batch_id)
    {
        $includeImage = $request->input('include_image', '1') === '1';

        $quotes = DB::table('cotizacion as c')
            ->leftJoin('cliente as cli', 'c.cliente_idcliente', '=', 'cli.idcliente')
            ->leftJoin('tipodocumento as td', 'c.tipoDocumento_idtipoDocumento', '=', 'td.idtipoDocumento')
            ->leftJoin('vigenciaoferta as v', 'c.vigenciaOferta_idvigenciaOferta', '=', 'v.idvigenciaOferta')
            ->leftJoin('formapago as fp', 'c.formaPago_idformaPago', '=', 'fp.idformaPago')
            ->leftJoin('moneda as m', 'c.moneda_idmoneda', '=', 'm.idmoneda')
            ->leftJoin('personal as p', 'c.personal_dniPersonal', '=', 'p.dniPersonal')
            ->select([
                'c.*',
                'td.detalle as tipoDocumento_nombre',
                DB::raw("COALESCE(cli.razonSocial, cli.nombreComercial, c.cliente_idcliente, 'Cliente sin nombre') as cliente_label"),
                'v.detalle as vigencia_detalle',
                'fp.detalle as formaPago_detalle',
                'fp.tiempo as formaPago_tiempo',
                'm.detalle as moneda_detalle',
                'p.nombre as personal_nombre',
                'p.apellido as personal_apellido',
            ])
            ->where('c.batch_id', $batch_id)
            ->orderBy('c.fechaHoraEmision', 'asc')
            ->get();

        if ($quotes->isEmpty()) {
            abort(404, 'No se encontraron cotizaciones para este grupo.');
        }

        $quotesData = [];

        foreach ($quotes as $quote) {
            $formaPagoDetalle = trim((string) ($quote->formaPago_detalle ?? ''));
            $formaPagoTiempo = (int) ($quote->formaPago_tiempo ?? 0);
            if ($formaPagoTiempo > 0 && !str_contains(mb_strtolower($formaPagoDetalle), 'contado')) {
                $formaPagoDetalle .= ' (' . $formaPagoTiempo . ' días)';
            }
            $quote->formaPago_detalle = $formaPagoDetalle;

            $quote->moneda_simbolo = $this->currencySymbol($quote->moneda_detalle ?? null);

            $items = DB::table('detallecotizacion as d')
                ->leftJoin('almacen as a', 'd.almacen_idalmacen', '=', 'a.idalmacen')
                ->leftJoin('tipoelemento as te', 'a.tipoElemento_idtipoElemento', '=', 'te.idtipoElemento')
                ->where('d.cotizacion_nroCotizacion', $quote->nroCotizacion)
                ->select([
                    'a.detalle as producto',
                    'a.imagen as producto_imagen',
                    'a.periodo as periodo',
                    'te.nombre as tipo_nombre',
                    'd.precioUnitario',
                    'd.cantidad',
                    'd.descuento',
                    'd.total',
                ])
                ->orderBy('a.detalle')
                ->get()
                ->map(function ($item) use ($quote) {
                    $item->tipo_nombre = trim((string) ($item->tipo_nombre ?? ''));
                    $item->precio_label = $this->formatMoney($item->precioUnitario, $quote->moneda_simbolo);
                    $itemTaxed = round((float) $item->total * 1.18, 2);
                    $item->igv_label = $this->formatMoney(round((float) $item->total * 0.18, 2), $quote->moneda_simbolo);
                    $item->total_label = $this->formatMoney($itemTaxed, $quote->moneda_simbolo);
                    $item->descuento_label = is_numeric($item->descuento) ? number_format($item->descuento, 2, '.', ',') . '%' : '-';
                    return $item;
                });

            $importe = (float) ($quote->subtotal ?? 0);
            $descuentoPercent = (float) ($quote->descuento ?? 0);
            $descuentoAmount = round($importe * $descuentoPercent / 100, 2);
            $subtotalAfterDiscount = round($importe - $descuentoAmount, 2);
            $igvAmount = round($subtotalAfterDiscount * 0.18, 0);
            $totalGeneral = round($subtotalAfterDiscount + $igvAmount, 0);

            $sectionTitle = 'EQUIPAMIENTO';
            foreach ($items as $item) {
                $tipo = strtoupper($item->tipo_nombre ?? '');
                if (str_contains($tipo, 'SERVIC')) {
                    $sectionTitle = 'SERVICIOS TÉCNICOS';
                    break;
                }
                if (str_contains($tipo, 'PLAN')) {
                    $sectionTitle = 'PLANES';
                    break;
                }
            }

            $quotesData[] = [
                'quote' => $quote,
                'items' => $items,
                'section_title' => $sectionTitle,
                'importe_label' => $this->formatMoney($importe, $quote->moneda_simbolo),
                'descuento_amount_label' => $this->formatMoney($descuentoAmount, $quote->moneda_simbolo),
                'subtotal_after_discount_label' => $this->formatMoney($subtotalAfterDiscount, $quote->moneda_simbolo),
                'igv_amount_label' => $this->formatMoney($igvAmount, $quote->moneda_simbolo),
                'total_general_label' => $this->formatMoney($totalGeneral, $quote->moneda_simbolo),
                'descuento_percent' => $descuentoPercent,
            ];
        }

        $pdf = Pdf::loadView('ventas.cotizaciones.pdf-grupo', [
            'quotesData' => $quotesData,
            'include_image' => $includeImage,
            'batchId' => $batch_id,
        ]);

        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $pageText = 'Página {PAGE_NUM} de {PAGE_COUNT}';
        $font = 'helvetica';
        $fontSize = 10;
        $marginRight = -95;
        $marginBottom = 28;
        $textWidth = $canvas->get_text_width($pageText, $font, $fontSize);
        $x = max(30, $canvas->get_width() - $textWidth - $marginRight);
        $y = $canvas->get_height() - $marginBottom;
        $canvas->page_text($x, $y, $pageText, $font, $fontSize, [0, 0, 0]);

        return $pdf->download($this->buildQuotePdfFileName($quotes->first(), $batch_id));
    }

    public function edit(string $id): View|RedirectResponse
    {
        $previous = DB::table('cotizacion as c')
            ->leftJoin('vigenciaoferta as v', 'c.vigenciaOferta_idvigenciaOferta', '=', 'v.idvigenciaOferta')
            ->select('c.*', 'v.dias as vigencia_dias')
            ->where('c.nroCotizacion', $id)
            ->first();

        if (!$previous) {
            return redirect()
                ->route('modules.ventas.cotizaciones.index')
                ->with('error', 'No se encontró el registro solicitado.');
        }

        $blockedStates = [CotizacionService::STATE_FINALIZADO, CotizacionService::STATE_EJECUTADO_SP, CotizacionService::STATE_ANULADO];
        if (in_array((string) ($previous->estado ?? ''), $blockedStates, true) || $this->isCotizacionExpired($previous)) {
            return redirect()
                ->route('modules.ventas.cotizaciones.index')
                ->with('error', 'No se puede actualizar una cotización en estado Finalizado, Ejecutado(SP) o Anulado, o con vigencia vencida.');
        }

        $data = $this->cotizacionService->prepareEditViewData(request(), $id);

        return view('ventas.cotizaciones.cotizacion-form', [
            'title' => 'Editar Cotización',
            'moduleTitle' => 'Cotizaciones',
            'mode' => 'edit',
            'formAction' => route('modules.ventas.cotizaciones.update', $id),
            'backRoute' => route('modules.ventas.cotizaciones.index'),
            'record' => $data['record'],
            'fields' => $data['fields'],
            'readOnly' => true,
        ] + $this->prepareLockViewData(self::LOCK_RESOURCE, (string) $id) + ['almacenes' => $data['almacenes'], 'detalles' => $data['detalles'], 'paquetes' => $data['paquetes']]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $exists = DB::table('cotizacion')->where('nroCotizacion', $id)->exists();

        if (!$exists) {
            return redirect()
                ->route('modules.ventas.cotizaciones.index')
                ->with('error', 'No se encontró el registro solicitado.');
        }

        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, (string) $id, 'registro de cotización', 'modules.ventas.cotizaciones.index')) {
            return $redirect;
        }

        $validated = $this->validateCotizacion($request, 'update', $id);

        $previous = DB::table('cotizacion as c')
            ->leftJoin('vigenciaoferta as v', 'c.vigenciaOferta_idvigenciaOferta', '=', 'v.idvigenciaOferta')
            ->select('c.*', 'v.dias as vigencia_dias')
            ->where('c.nroCotizacion', $id)
            ->first();

        if (!$previous) {
            return redirect()
                ->route('modules.ventas.cotizaciones.index')
                ->with('error', 'No se encontró el registro solicitado.');
        }

        $blockedStates = [CotizacionService::STATE_FINALIZADO, CotizacionService::STATE_EJECUTADO_SP, CotizacionService::STATE_ANULADO];
        if (in_array((string) ($previous->estado ?? ''), $blockedStates, true) || $this->isCotizacionExpired($previous)) {
            return redirect()
                ->route('modules.ventas.cotizaciones.index')
                ->with('error', 'No se puede actualizar una cotización en estado Finalizado, Ejecutado(SP) o Anulado, o con vigencia vencida.');
        }

        $payload = $this->preparePayload($validated);

        // Preservar campos que pudieran no enviarse por estar disabled en el form
        $preserveIfMissing = ['formaPago_idformaPago', 'moneda_idmoneda', 'comentario'];
        foreach ($preserveIfMissing as $fieldKey) {
            if (!$request->has($fieldKey) && isset($previous->$fieldKey)) {
                $payload[$fieldKey] = $previous->$fieldKey;
            }
        }

        // Conservar estado en ediciones que no cambian el flujo de aprobación.
        $payload['estado'] = $previous->estado;

        // Validar y resolver correlativo si se solicitó cambiar el nro de cotización
        $requestedNro = $payload['nroCotizacion'] ?? null;
        $tipoId = (int) ($payload['tipoDocumento_idtipoDocumento'] ?? $previous->tipoDocumento_idtipoDocumento ?? 0);
        if (!empty($requestedNro) && $requestedNro !== $id && $tipoId > 0) {
            $requestedCorrelativo = $this->extractCorrelativoFromFormattedNro($requestedNro, $tipoId);
            $result = CorrelativoService::resolveCorrelativo($tipoId, $requestedCorrelativo, 'cotizacion', 'nroCotizacion');
            if (!$result['accepted']) {
                return redirect()->back()->withInput()->with('error', 'Correlativo de cotización inválido: ' . ($result['reason'] ?? 'rechazado'));
            }

            if (isset($result['final'])) {
                $td = DB::table('tipodocumento')->where('idtipoDocumento', $tipoId)->first();
                if ($td && (int) ($td->correlativo ?? 0) !== $result['final']) {
                    DB::table('tipodocumento')->where('idtipoDocumento', $tipoId)->update(['correlativo' => $result['final']]);
                }
            }
        }

        // Eliminamos el ID del payload para evitar actualizar la PK si no es necesario.
        unset($payload['nroCotizacion']);

        // Procesar líneas de detalle si se enviaron
        $detalles = $request->input('detalle', []);
        $detalleRows = [];
        $rawSubtotal = 0.0;
        if (!empty($detalles) && is_array($detalles)) {
            foreach ($detalles as $d) {
                $precio = isset($d['precioUnitario']) ? (float) $d['precioUnitario'] : 0.0;
                $cantidad = isset($d['cantidad']) ? (float) $d['cantidad'] : 0.0;
                $descuento = isset($d['descuento']) ? (float) $d['descuento'] : 0.0;
                $rowTotal = round($cantidad * $precio * (1 - ($descuento / 100)), 2);
                $rawSubtotal += $rowTotal;

                $detalleRows[] = [
                    'cotizacion_nroCotizacion' => $id,
                    'almacen_idalmacen' => isset($d['almacen_idalmacen']) ? (int) $d['almacen_idalmacen'] : null,
                    'precioUnitario' => $precio,
                    'cantidad' => $cantidad,
                    'descuento' => $descuento,
                    'total' => $rowTotal,
                ];
            }
        }

        // Recalcular totales de la cotización a partir de las filas de detalle
        if (!empty($detalleRows)) {
            $descGlobal = (float) ($request->input('descuento') ?? $validated['descuento'] ?? 0);
            $igvPercent = (float) ($request->input('igv') ?? $validated['igv'] ?? 18);
            $descAmount = round($rawSubtotal * ($descGlobal / 100), 2);
            $baseNeto = round($rawSubtotal - $descAmount, 2);
            $igvAmount = round($baseNeto * ($igvPercent / 100), 2);
            $totalNeto = round($baseNeto + $igvAmount, 2);

            $payload['subtotal'] = $rawSubtotal;
            $payload['descuento'] = $descGlobal;
            $payload['igv'] = $igvPercent;
            $payload['total'] = $totalNeto;
        }

        DB::transaction(function () use ($payload, $id, $detalleRows): void {
            DB::table('cotizacion')->where('nroCotizacion', $id)->update($payload);

            // Actualizar líneas de detalle: eliminar las existentes y reinsertar las enviadas
            try {
                DB::table('detallecotizacion')->where('cotizacion_nroCotizacion', $id)->delete();
            } catch (\Throwable $e) {
                throw $e;
            }

            if (!empty($detalleRows)) {
                DB::table('detallecotizacion')->insert($detalleRows);
            }
        });

        $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $id, 'updated', ['estado' => $payload['estado'] ?? null]);

        return redirect()
            ->route('modules.ventas.cotizaciones.index')
            ->with('success', 'Registro de cotización actualizado correctamente.');
    }

    public function anular(Request $request, string $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, (string) $id, 'registro de cotización', 'modules.ventas.cotizaciones.index')) {
            return $redirect;
        }

        $record = DB::table('cotizacion')->where('nroCotizacion', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.ventas.cotizaciones.index')
                ->with('error', 'No se encontró la cotización solicitada.');
        }

        // Only allow anular when state is Generado
        if ((string) ($record->estado ?? '') !== CotizacionService::STATE_GENERADO) {
            return redirect()
                ->route('modules.ventas.cotizaciones.index')
                ->with('error', 'Solo se puede anular una cotización en estado Generado.');
        }

        DB::table('cotizacion')->where('nroCotizacion', $id)->update(['estado' => CotizacionService::STATE_ANULADO]);
        $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $id, 'updated', ['estado' => CotizacionService::STATE_ANULADO]);

        return redirect()
            ->route('modules.ventas.cotizaciones.index')
            ->with('success', 'Cotización anulada correctamente.');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, (string) $id, 'registro de cotización', 'modules.ventas.cotizaciones.index')) {
            return $redirect;
        }

        $record = DB::table('cotizacion')->where('nroCotizacion', $id)->first();
        $protectedStates = [CotizacionService::STATE_FINALIZADO, CotizacionService::STATE_EJECUTADO_SP, CotizacionService::STATE_ANULADO];
        if ($record && in_array((string) ($record->estado ?? ''), $protectedStates, true)) {
            return redirect()
                ->route('modules.ventas.cotizaciones.index')
                ->with('error', 'No se puede eliminar una cotización en estado Finalizado, Ejecutado(SP) o Anulado.');
        }

        try {
            DB::table('cotizacion')->where('nroCotizacion', $id)->delete();
            $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $id, 'deleted');
            $this->releaseLockIfOwned($request, self::LOCK_RESOURCE, (string) $id);

            return redirect()
                ->route('modules.ventas.cotizaciones.index')
                ->with('success', 'Registro eliminado correctamente.');
        } catch (QueryException) {
            return redirect()
                ->route('modules.ventas.cotizaciones.index')
                ->with('error', 'No se puede eliminar el registro porque tiene relaciones asociadas.');
        }
    }

    /**
     * Devuelve la información para autocompletar contacto/dirección
     * del cliente solicitado. Responde JSON con { direccion, correo, telefono }.
     */
    public function clienteInfo(Request $request, string $cliente): JsonResponse
    {
        $cliente = trim((string) $cliente);
        if ($cliente === '') {
            return response()->json(['ok' => false, 'message' => 'Cliente inválido.'], 400);
        }

        $exists = DB::table('cliente')->where('idcliente', $cliente)->exists();
        if (!$exists) {
            return response()->json(['ok' => false, 'message' => 'No se encontró el cliente.'], 404);
        }

        // Dirección por defecto (si existe)
        $direccion = DB::table('direccioncliente')
            ->where('cliente_idcliente', $cliente)
            ->orderByDesc('default')
            ->orderByDesc('iddireccionCliente')
            ->value('direccion');

        // Contacto por defecto (correo y número)
        $contacto = DB::table('contacto')
            ->where('cliente_idcliente', $cliente)
            ->orderByDesc('default')
            ->orderByDesc('idcontacto')
            ->select('correo', 'numero')
            ->first();

        return response()->json([
            'ok' => true,
            'data' => [
                'clienteId' => $cliente,
                'direccion' => $direccion ?? null,
                'correo' => $contacto->correo ?? null,
                'telefono' => $contacto->numero ?? null,
            ],
        ]);
    }

    private function baseQuery(Request $request)
    {
        return $this->cotizacionService->baseQuery($request);
    }

    private function buildFields(?string $id = null): array
    {
        return $this->cotizacionService->buildFields($id);
    }

    private function validateCotizacion(Request $request, string $mode, ?string $id = null): array
    {
        return $this->cotizacionService->validateCotizacion($request, $mode, $id);
    }

    private function preparePayload(array $validated): array
    {
        return $this->cotizacionService->preparePayload($validated);
    }

    private function nullableString(mixed $value): ?string
    {
        return $this->cotizacionService->nullableString($value);
    }

    private function nullableNumber(mixed $value): ?string
    {
        return $this->cotizacionService->nullableNumber($value);
    }

    private function extractCorrelativoFromFormattedNro(string $formatted, int $tipoId): int
    {
        return $this->cotizacionService->extractCorrelativoFromFormattedNro($formatted, $tipoId);
    }

    private function createTicketForApprovedCotizacion(string $nroCotizacion, string $currentUser): ?int
    {
        $tipoOperacionId = DB::table('tipooperacion')
            ->where('nomenclatura', 'CT')
            ->orWhere('detalle', 'like', '%Cotizaci%')
            ->value('idtipoOperacion');

        if (!$tipoOperacionId) {
            return null;
        }

        $exists = DB::table('ticket')
            ->where('pedidoReferencia', $nroCotizacion)
            ->where('tipoOperacion_idtipoOperacion', $tipoOperacionId)
            ->exists();

        if ($exists) {
            return null;
        }

        $usuarioEmisor = $this->ticketsService->resolveUserDisplayNameFromUsername($currentUser);

        $ticketId = DB::table('ticket')->insertGetId([
            'tipoOperacion_idtipoOperacion' => (int) $tipoOperacionId,
            'pedidoReferencia' => $nroCotizacion,
            'usuarioEmisor' => $usuarioEmisor,
            'usuarioReceptor' => null,
            'fechaHoraRegistro' => now()->format('Y-m-d H:i:s'),
            'fechaHoraCierre' => null,
            'detalle' => 'Nueva cotización',
            'ImagenEvidencia' => null,
            'respuesta' => null,
            'estado' => 'Activo',
        ]);

        $this->ticketsService->ensureInitialHistorialForTicket((int) $ticketId, (int) $tipoOperacionId, $currentUser);

        return (int) $ticketId;
    }

    public function approve(Request $request, string $id): RedirectResponse
    {
        if ($redirect = $this->assertLockAvailable($request, self::LOCK_RESOURCE, (string) $id, 'registro de cotización', 'modules.ventas.cotizaciones.index')) {
            return $redirect;
        }

        $record = DB::table('cotizacion')->where('nroCotizacion', $id)->first();
        if (!$record) {
            return redirect()
                ->route('modules.ventas.cotizaciones.index')
                ->with('error', 'No se encontró la cotización solicitada.');
        }

        $currentState = (string) ($record->estado ?? '');
        if ($currentState !== CotizacionService::STATE_GENERADO) {
            return redirect()
                ->route('modules.ventas.cotizaciones.index')
                ->with('error', 'Solo se puede aprobar una cotización en estado Generado.');
        }

        if ($this->isCotizacionExpired($record)) {
            return redirect()
                ->route('modules.ventas.cotizaciones.index')
                ->with('error', 'No se puede aprobar una cotización con vigencia vencida.');
        }

        $origin = trim((string) $request->input('origen', ''));
        $originLower = mb_strtolower($origin, 'UTF-8');

        // Aprobación desde la vista de Cotizaciones debe guardar Aprobado(SP) por defecto.
        // Solo se usa Aprobado cuando el origen es explícito desde otro módulo.
        $newEstado = CotizacionService::STATE_APROBADO_SP;
        if ($originLower !== '' && !str_contains($originLower, 'cotiz') && !str_contains($originLower, 'coti')) {
            $newEstado = CotizacionService::STATE_APROBADO;
        }

        $currentUser = (string) session('erp_auth.usuario', '');
        $ticketId = null;
        $isGroup = $this->isCotizacionGroup($record);
        $referenciaToUse = $isGroup ? $record->batch_id : $id;

        DB::transaction(function () use ($id, $isGroup, $record, $newEstado, $currentUser, &$ticketId, $referenciaToUse) {
            if ($isGroup) {
                DB::table('cotizacion')
                    ->where('batch_id', $record->batch_id)
                    ->update(['estado' => $newEstado]);
            } else {
                DB::table('cotizacion')
                    ->where('nroCotizacion', $id)
                    ->update(['estado' => $newEstado]);
            }

            $ticketId = $this->createTicketForApprovedCotizacion($referenciaToUse, $currentUser);
        });

        if ($isGroup) {
            $updatedCotizaciones = DB::table('cotizacion')->where('batch_id', $record->batch_id)->pluck('nroCotizacion');
            foreach ($updatedCotizaciones as $cId) {
                $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $cId, 'updated', [
                    'estado' => $newEstado,
                    'origen' => $origin,
                ]);
            }
        } else {
            $this->publishResourceEvent(self::LOCK_RESOURCE, (string) $id, 'updated', [
                'estado' => $newEstado,
                'origen' => $origin,
            ]);
        }

        if ($ticketId !== null) {
            $this->publishResourceEvent('ticket', (string) $ticketId, 'created');
        }

        return redirect()
            ->route('modules.ventas.cotizaciones.index')
            ->with('success', 'Cotización aprobada correctamente.');
    }

    private function isCotizacionGroup(?object $record): bool
    {
        if (!$record) {
            return false;
        }

        $batchId = trim((string) ($record->batch_id ?? ''));
        if ($batchId === '') {
            return false;
        }

        return DB::table('cotizacion')
            ->where('batch_id', $batchId)
            ->where('nroCotizacion', '!=', $record->nroCotizacion)
            ->exists();
    }

    private function generateBatchId(string $type): string
    {
        $prefix = $type === 'GRP' ? 'GRP-' : 'IND-';
        $existingIds = DB::table('cotizacion')
            ->where('batch_id', 'like', $prefix . '%')
            ->pluck('batch_id');

        $maxIndex = 0;
        foreach ($existingIds as $id) {
            $id = trim((string) $id);
            if (!str_starts_with($id, $prefix)) {
                continue;
            }
            $number = (int) substr($id, strlen($prefix));
            $maxIndex = max($maxIndex, $number);
        }

        return $prefix . ($maxIndex + 1);
    }

    private function incrementBatchId(string $batchId): string
    {
        if (preg_match('/^(IND|GRP)-(\d+)$/', $batchId, $matches)) {
            return $matches[1] . '-' . ((int) $matches[2] + 1);
        }

        return $batchId . '-1';
    }

    private function formatCotizacionGroupLabel(mixed $batchId): string
    {
        $value = trim((string) ($batchId ?? ''));

        if ($value === '') {
            return 'Sin batch';
        }

        return $value;
    }

    private function buildQuotePdfFileName(?object $quote, string $identifier): string
    {
        $clientLabel = trim((string) ($quote->cliente_label ?? ''));
        $identifierValue = trim((string) $identifier);

        $safeClient = preg_replace('/[^\pL\pN._-]+/u', '_', $clientLabel);
        $safeClient = trim($safeClient, "._-");
        $safeIdentifier = preg_replace('/[^\pL\pN._-]+/u', '_', $identifierValue);
        $safeIdentifier = trim($safeIdentifier, "._-");

        if ($safeClient === '') {
            $safeClient = 'cliente';
        }

        if ($safeIdentifier === '') {
            $safeIdentifier = 'sin-identificador';
        }

        return $safeClient . '_' . $safeIdentifier . '.pdf';
    }

    private function formatCotizacionEstadoName(?string $estado): string
    {
        return $this->cotizacionService->formatCotizacionEstadoName($estado);
    }

    private function formatCotizacionEstadoHtmlLabel(?string $estado): string
    {
        return $this->cotizacionService->formatCotizacionEstadoHtmlLabel($estado);
    }

    private function isCotizacionExpired(object $row): bool
    {
        return $this->cotizacionService->isCotizacionExpired($row);
    }

    private function formatMoney(mixed $value, string $currencySymbol = 'S/'): string
    {
        return $this->cotizacionService->formatMoney($value, $currencySymbol);
    }

    private function currencySymbol(?string $moneda): string
    {
        return $this->cotizacionService->currencySymbol($moneda);
    }
}
