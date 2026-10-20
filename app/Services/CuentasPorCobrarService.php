<?php

namespace App\Services;

use App\Support\ServicePeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CuentasPorCobrarService
{
    private array $tipoCobroIdCache = [];

    public function prepareServicesIndex(array $filters = []): array
    {
        $services = $this->loadServices($filters);
        $maps = $this->loadDeviceMaps($services);
        $historicalByClient = $this->loadHistoricalCharges($services, $filters);
        $creditDeadlines = $this->loadCreditDeadlines($historicalByClient);
        $tipoCobroDetails = DB::table('tipocobro')
            ->get(['idtipoCobros', 'recurrencia', 'tiempo'])
            ->keyBy('idtipoCobros');

        $services = $this->decorateServices($services, $maps);
        $services = $this->appendHistoricalPeriods($services, $historicalByClient);
        [$services, $creditChildrenByParent] = $this->appendCreditInstallmentReceivables($services, $filters);
        $grouped = $this->groupServices(
            $services,
            $historicalByClient,
            $creditDeadlines,
            $tipoCobroDetails,
            $creditChildrenByParent
        );
        $this->sortGroups($grouped);

        return [
            'serviciosPorVencer' => $services,
            'serviciosPorVencerGrouped' => $grouped,
        ];
    }

    private function loadServices(array $filters = []): Collection
    {
        return DB::table('cuentasporcobrar as c')
            ->leftJoin('serviciocliente as sc', function ($join) use ($filters) {
                $join->on('sc.cliente_idcliente', '=', 'c.cliente_idcliente')
                    ->where(function ($serviceMatch) {
                        $serviceMatch->where(function ($markerMatch) {
                            $markerMatch->whereRaw(
                                "c.descripcion REGEXP CONCAT('(^|[^A-Za-z0-9])SC-([0-9]+,)*', sc.idservicioCliente, '([^0-9]|$)')"
                                )->where(function ($periodMatch) {
                                    $periodMatch->whereColumn('c.fechaCancelacion', 'sc.fecheVencimiento')
                                        ->orWhere(function ($successorMatch) {
                                            $successorMatch->where('c.descripcion', 'like', 'REN-CXC-%')
                                                ->whereRaw(
                                                    "STR_TO_DATE(SUBSTRING_INDEX(SUBSTRING_INDEX(c.descripcion, ' P ', -1), ' a ', 1), '%d/%m/%Y') = DATE(sc.fecheVencimiento)"
                                                );
                                        });
                                });
                        })->orWhere(function ($invoiceMatch) {
                            $invoiceMatch->where('c.descripcion', 'not like', '%SC-%')
                                ->whereNotNull('sc.docReferencia')
                                ->where('sc.docReferencia', '<>', '')
                                ->whereColumn('c.docReferencia', 'sc.docReferencia')
                                ->whereColumn('c.fechaCancelacion', 'sc.fecheVencimiento');
                        })->orWhere(function ($groupMatch) {
                            $this->matchAggregateCxcServices($groupMatch);
                        })->orWhere(function ($legacyMatch) {
                            $legacyMatch->where('c.descripcion', 'not like', '%SC-%')
                                ->whereColumn('c.montoOriginal', 'sc.monto')
                                ->whereColumn('c.fechaCancelacion', 'sc.fecheVencimiento');
                        })->orWhere(function ($pendingWithholdingMatch) {
                            $pendingWithholdingMatch->whereIn('c.estado', [
                                'Cancelado Detracción',
                                'Pendiente Autodetracción',
                                'Pendiente Autoretención',
                                'Pendiente a Detracción',
                                'Pendiente a Retención',
                            ])
                                ->whereNotNull('c.docReferencia')
                                ->where('c.docReferencia', '<>', '')
                                ->whereColumn('c.docReferencia', 'sc.docReferencia');
                        });
                        if (Schema::hasTable('cuentasporcobrar_serviciocliente')) {
                            $serviceMatch->orWhereExists(function ($linkedService): void {
                                $linkedService->selectRaw('1')
                                    ->from('cuentasporcobrar_serviciocliente as cxs')
                                    ->whereColumn('cxs.cuentasPorCobrar_idcuentasPorCobrar', 'c.idcuentasPorCobrar')
                                    ->whereColumn('cxs.servicioCliente_idservicioCliente', 'sc.idservicioCliente')
                                    ->where(function ($periodMatch): void {
                                        $periodMatch->whereColumn('c.fechaCancelacion', 'sc.fecheVencimiento')
                                            ->orWhere(function ($successorMatch): void {
                                                $successorMatch->where('c.descripcion', 'like', 'REN-CXC-%')
                                                    ->whereRaw(
                                                        "STR_TO_DATE(SUBSTRING_INDEX(SUBSTRING_INDEX(c.descripcion, ' P ', -1), ' a ', 1), '%d/%m/%Y') = DATE(sc.fecheVencimiento)"
                                                    );
                                            });
                                    });
                            });
                        }

                    });
            })
            ->leftJoin('cliente as cli', 'cli.idcliente', '=', 'sc.cliente_idcliente')
            ->leftJoin('vehiculo as v', 'v.placa', '=', 'sc.vehiculo_placa')
            ->leftJoin('tipovehiculo as tv', 'tv.idtipoVehiculo', '=', 'v.tipoUnidad_idtable1')
            ->leftJoin('almacen as a', 'a.idalmacen', '=', 'sc.almacen_idalmacen')
            ->leftJoin('tipoelemento as te', 'te.idtipoElemento', '=', 'a.tipoElemento_idtipoElemento')
            ->leftJoin('plataforma as p', 'p.idplataforma', '=', 'te.plataforma_idplataforma')
            ->leftJoin('moneda as m', function ($join) {
                $join->on('m.idmoneda', '=', 'c.moneda_idmoneda')->where('m.idmoneda', '!=', 4);
            })
            ->select([
                'c.idcuentasPorCobrar',
                'c.estado as cxc_estado',
                'c.docReferencia as cxc_docReferencia',
                'c.montoOriginal as cxc_montoOriginal',
                'c.montoActual as cxc_montoActual',
                'c.tipoCobro_idtipoCobros as cxc_tipoCobro_idtipoCobros',
                'c.descripcion as cxc_descripcion',
                'c.canCuotas as cxc_canCuotas',
                'c.moneda_idmoneda as moneda_idmoneda',
                'c.fechaRegistro as cxc_fechaRegistro',
                'c.fechaCancelacion as cxc_fechaCancelacion',
                'sc.idservicioCliente',
                'sc.cliente_idcliente',
                'sc.almacen_idalmacen',
                'sc.moneda_idmoneda as servicio_moneda_id',
                'sc.docReferencia',
                DB::raw('COALESCE(cli.razonSocial, cli.nombreComercial, cli.idcliente) as cliente_nombre'),
                'cli.flag_integrador as flag_integrador',
                'cli.detraccion as cliente_detraccion',
                'sc.vehiculo_placa',
                'sc.fechaInicio',
                'sc.fecheVencimiento',
                'sc.monto',
                'sc.estado',
                DB::raw('COALESCE(a.detalle, "") as servicio_detalle'),
                'a.periodo as servicio_periodo',
                DB::raw('COALESCE(v.marca, "") as vehiculo_marca'),
                DB::raw('COALESCE(v.modelo, "") as vehiculo_modelo'),
                DB::raw('COALESCE(v.tracto, "") as vehiculo_tracto'),
                DB::raw('COALESCE(tv.nombre, "") as tipo_vehiculo'),
                DB::raw('COALESCE(p.nombrePlataforma, "") as plataforma'),
                DB::raw('COALESCE(m.simbolo, "") as moneda_simbolo'),
            ])
            ->selectSub($this->latestPaymentDateSubquery(), 'cxc_fecha_pago')
            ->whereRaw('LOWER(COALESCE(sc.estado, "")) = ?', ['activo'])
            ->whereIn('c.estado', ['1', '2', '4', 'NUEVO', 'PagoCredito', 'PENDIENTE', 'FACTURADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito', 'Cancelado Detracción', 'Pendiente Autodetracción', 'Pendiente Autoretención', 'Pendiente a Detracción', 'Pendiente a Retención'])
            ->where(function ($query): void {
                $query->whereNull('c.descripcion')
                    ->orWhere(function ($descriptionQuery): void {
                        $descriptionQuery->where('c.descripcion', 'not like', 'COTIZACION:%')
                            ->where('c.descripcion', 'not like', 'CCXC:%');
                    });
            })
            ->whereRaw('COALESCE(c.montoActual, c.montoOriginal) >= 0')
            ->whereNotNull('sc.fecheVencimiento')
            ->orderBy('sc.fecheVencimiento')
            ->orderByDesc('c.idcuentasPorCobrar')
            ->get();
    }

    private function appendCreditInstallmentReceivables(Collection $services, array $filters = []): array
    {
        $clientIds = $services->pluck('cliente_idcliente')->filter()->unique()->values()->all();
        if ($clientIds === []) {
            return [$services, []];
        }

        $queryInstallments = fn() => DB::table('cuentasporcobrar')
            ->whereIn('cliente_idcliente', $clientIds)
            ->where('descripcion', 'like', 'CCXC:%')
            ->where('montoActual', '>', 0)
            ->whereNotNull('fechaCancelacion')
            ->whereNotIn('estado', ['3', 'CANCELADO']);
        $allInstallments = $queryInstallments()->get();
        $creditChildrenByParent = [];
        foreach ($allInstallments as $installment) {
            if (preg_match('/^CCXC:(\d+):(\d+)(?:\s|$)/', (string) $installment->descripcion, $matches) !== 1) {
                continue;
            }
            $creditChildrenByParent[(int) $matches[1]][] = [
                'id' => (int) $installment->idcuentasPorCobrar,
                'number' => (int) $matches[2],
                'amount' => round((float) $installment->montoActual, 2),
                'date' => Carbon::parse($installment->fechaCancelacion)->toDateString(),
            ];
        }
        foreach ($creditChildrenByParent as &$children) {
            usort($children, static fn(array $left, array $right): int => $left['number'] <=> $right['number']);
        }
        unset($children);

        $installmentsQuery = $queryInstallments();
        $this->applyPeriodFilters($installmentsQuery, 'fechaCancelacion', $filters);
        $installments = $installmentsQuery->get();
        if ($installments->isEmpty()) {
            return [$services, $creditChildrenByParent];
        }

        $serviceRows = $services
            ->filter(fn($service): bool => (int) ($service->idservicioCliente ?? 0) > 0)
            ->keyBy(fn($service): int => (int) $service->idservicioCliente);
        $serviceRowsByClient = $services
            ->filter(fn($service): bool => (int) ($service->cliente_idcliente ?? 0) > 0)
            ->groupBy(fn($service): int => (int) $service->cliente_idcliente);
        foreach ($installments as $installment) {
            if (preg_match('/^CCXC:(\d+):(\d+)(?:\s|$)/', (string) $installment->descripcion, $matches) !== 1) {
                continue;
            }
            $serviceId = preg_match('/SC-([0-9,]+)/', (string) $installment->descripcion, $serviceMatch) === 1
                ? (int) explode(',', $serviceMatch[1])[0]
                : 0;
            $service = $serviceRows->get($serviceId)
                ?? $serviceRowsByClient->get((int) $installment->cliente_idcliente)?->first();
            if (!$service) {
                continue;
            }

            $row = clone $service;
            $dueDate = Carbon::parse($installment->fechaCancelacion)->toDateString();
            $amount = round((float) $installment->montoActual, 2);
            $row->idcuentasPorCobrar = (int) $installment->idcuentasPorCobrar;
            $row->cxc_estado = (string) $installment->estado;
            $row->cxc_docReferencia = $installment->docReferencia;
            $row->cxc_montoOriginal = (float) $installment->montoOriginal;
            $row->cxc_montoActual = $amount;
            $row->cxc_canCuotas = null;
            $row->cxc_descripcion = (string) $installment->descripcion;
            $row->cxc_fechaRegistro = $installment->fechaRegistro;
            $row->cxc_fechaCancelacion = $dueDate;
            $row->fechaInicio = $dueDate;
            $row->fecheVencimiento = $dueDate;
            $row->idservicioCliente = 0;
            $row->servicio_detalle = 'Cuota ' . (int) $matches[2] . ' crédito';
            $row->vehiculo_placa = '-';
            $row->monto = $amount;
            $row->service_cxc_monto_actual = $amount;
            $row->moneda_idmoneda = (int) $installment->moneda_idmoneda;
            $row->service_credit_installment = true;
            $row->fecha_inicio_display = Carbon::parse($dueDate)->format('d/m/Y');
            $row->fecha_vencimiento_display = $row->fecha_inicio_display;
            $row->dias_restantes = (int) Carbon::today()->diffInDays(Carbon::parse($dueDate)->startOfDay(), false);
            $services->push($row);
        }

        return [$services, $creditChildrenByParent];
    }

    private function matchAggregateCxcServices($query): void
    {
        $query->where('c.descripcion', 'like', '%SC-%')
            ->whereColumn('c.fechaCancelacion', 'sc.fecheVencimiento')
            ->whereRaw(
                'c.montoOriginal = (SELECT SUM(sc_group.monto) FROM serviciocliente as sc_group'
                . ' WHERE sc_group.cliente_idcliente = c.cliente_idcliente'
                . ' AND LOWER(COALESCE(sc_group.estado, "")) = ?'
                . ' AND sc_group.fecheVencimiento = c.fechaCancelacion'
                . ' AND sc_group.moneda_idmoneda = c.moneda_idmoneda)',
                ['activo']
            );
    }

    private function loadDeviceMaps(Collection $services): array
    {
        $devices = [];
        $deviceIds = [];
        $numbers = [];
        $serviceIds = $services->pluck('idservicioCliente')->filter()->unique()->values()->all();

        if ($serviceIds === []) {
            return [$devices, $deviceIds, $numbers];
        }

        $latestDetailIds = DB::table('detalle_serviciodispositivo')
            ->whereIn('servicioCliente_idservicioCliente', $serviceIds)
            ->selectRaw('MAX(iddetalle_serviciodispositivo) as latest_id')
            ->groupBy('servicioCliente_idservicioCliente');
        $latestDetails = DB::table('detalle_serviciodispositivo as ds')
            ->joinSub($latestDetailIds, 'latest', function ($join): void {
                $join->on('ds.iddetalle_serviciodispositivo', '=', 'latest.latest_id');
            })
            ->get([
                'ds.servicioCliente_idservicioCliente',
                'ds.dispositivoCliente_iddispositivoCliente',
            ]);
        $deviceIdsByService = $latestDetails->mapWithKeys(fn($detail): array => [
            $detail->servicioCliente_idservicioCliente => $detail->dispositivoCliente_iddispositivoCliente,
        ])->all();
        $ids = array_values(array_unique(array_filter($deviceIdsByService)));

        foreach ($deviceIdsByService as $serviceId => $deviceId) {
            if (!$deviceId) {
                continue;
            }
            $deviceIds[$serviceId] = $deviceId;
            $devices[$serviceId] = $deviceId;
        }

        if ($ids !== []) {
            $latestNumberIds = DB::table('detnumerosdispositivo')
                ->whereIn('dispositivoCliente_iddispositivoCliente', $ids)
                ->selectRaw('MAX(iddetNumerosDispositivo) as latest_id')
                ->groupBy('dispositivoCliente_iddispositivoCliente');
            $numbersByDevice = DB::table('detnumerosdispositivo as n')
                ->joinSub($latestNumberIds, 'latest', function ($join): void {
                    $join->on('n.iddetNumerosDispositivo', '=', 'latest.latest_id');
                })
                ->pluck('n.numeroTelefonico_numeroTelefonico', 'n.dispositivoCliente_iddispositivoCliente')
                ->all();
            foreach ($deviceIds as $serviceId => $deviceId) {
                $numbers[$serviceId] = $numbersByDevice[$deviceId] ?? '-';
            }
        }

        return [$devices, $deviceIds, $numbers];
    }

    private function loadHistoricalCharges(Collection $services, array $filters = []): Collection
    {
        $clientIds = $services->pluck('cliente_idcliente')->filter()->unique()->values()->all();
        if ($clientIds === []) {
            return collect();
        }

        $pendingWithholdingQuery = DB::table('detallecxc as pending_detail')
            ->join(
                'cuentasporcobrar as pending_cxc',
                'pending_cxc.idcuentasPorCobrar',
                '=',
                'pending_detail.cuentasPorCobrar_idcuentasPorCobrar'
            )
            ->whereIn('pending_cxc.cliente_idcliente', $clientIds)
            ->whereIn('pending_cxc.estado', ['1', '2', '3', '4', 'NUEVO', 'PagoCredito', 'PENDIENTE', 'FACTURADO', 'CANCELADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito', 'Cancelado Detracción', 'Pendiente Autodetracción', 'Pendiente Autoretención', 'Pendiente a Detracción', 'Pendiente a Retención'])
            ->where('pending_detail.estado', '0')
            ->where(function ($query): void {
                $query->where('pending_detail.descripcion', 'like', 'Detracción pendiente:%')
                    ->orWhere('pending_detail.descripcion', 'like', 'Retención pendiente:%');
            })
            ->selectRaw('pending_detail.cuentasPorCobrar_idcuentasPorCobrar as cxc_id, MAX(pending_detail.iddetalleCXC) as detail_id')
            ->groupBy('pending_detail.cuentasPorCobrar_idcuentasPorCobrar');
        $this->applyPeriodFilters($pendingWithholdingQuery, 'pending_cxc.fechaCancelacion', $filters);

        $query = DB::table('cuentasporcobrar as c')
            ->leftJoinSub(
                $pendingWithholdingQuery,
                'pending_withholding',
                function ($join): void {
                    $join->on('pending_withholding.cxc_id', '=', 'c.idcuentasPorCobrar');
                }
            )
            ->leftJoin('detallecxc as withholding_detail', 'withholding_detail.iddetalleCXC', '=', 'pending_withholding.detail_id')
            ->whereIn('c.cliente_idcliente', $clientIds)
            ->whereIn('c.estado', ['1', '2', '3', '4', 'NUEVO', 'PagoCredito', 'PENDIENTE', 'FACTURADO', 'CANCELADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito', 'Cancelado Detracción', 'Pendiente Autodetracción', 'Pendiente Autoretención', 'Pendiente a Detracción', 'Pendiente a Retención'])
            ->where(function ($query): void {
                $query->whereNull('c.descripcion')
                    ->orWhere(function ($descriptionQuery): void {
                        $descriptionQuery->where('c.descripcion', 'not like', 'COTIZACION:%')
                            ->where('c.descripcion', 'not like', 'CCXC:%');
                    });
            })
            ->orderByDesc('c.idcuentasPorCobrar')
            ->select([
                'c.idcuentasPorCobrar',
                'c.cliente_idcliente',
                'c.montoOriginal',
                'c.montoActual',
                'c.estado',
                'c.docReferencia',
                'c.fechaRegistro',
                'c.fechaCancelacion',
                'c.descripcion',
                'c.moneda_idmoneda',
            ])
            ->selectSub($this->latestPaymentDateSubquery(), 'fecha_pago');

        $this->applyPeriodFilters($query, 'c.fechaCancelacion', $filters);

        return $query
            ->addSelect('withholding_detail.descripcion as withholding_description')
            ->get()
            ->groupBy('cliente_idcliente');
    }

    private function applyPeriodFilters($query, string $dateColumn, array $filters): void
    {
        $year = trim((string) ($filters['service_year'] ?? ''));
        $monthNumbers = array_values(array_filter(array_map(
            static fn(string $month): ?int => [
                'enero' => 1,
                'febrero' => 2,
                'marzo' => 3,
                'abril' => 4,
                'mayo' => 5,
                'junio' => 6,
                'julio' => 7,
                'agosto' => 8,
                'septiembre' => 9,
                'octubre' => 10,
                'noviembre' => 11,
                'diciembre' => 12,
            ][mb_strtolower(trim($month), 'UTF-8')] ?? null,
            $filters['service_months'] ?? []
        )));

        if ($year !== '' && $year !== 'all') {
            $months = $monthNumbers !== [] && count($monthNumbers) < 12
                ? $monthNumbers
                : range(1, 12);
            $query->where(function ($periodQuery) use ($dateColumn, $year, $months): void {
                foreach ($months as $monthNumber) {
                    $start = Carbon::createFromDate((int) $year, $monthNumber, 1)->startOfDay();
                    $end = $start->copy()->addMonthNoOverflow();
                    $periodQuery->orWhere(function ($monthQuery) use ($dateColumn, $start, $end): void {
                        $monthQuery->where($dateColumn, '>=', $start->toDateString())
                            ->where($dateColumn, '<', $end->toDateString());
                    });
                }
            });
        } elseif ($monthNumbers !== [] && count($monthNumbers) < 12) {
            $query->where(function ($monthQuery) use ($dateColumn, $monthNumbers): void {
                foreach ($monthNumbers as $monthNumber) {
                    $monthQuery->orWhereMonth($dateColumn, $monthNumber);
                }
            });
        }
    }

    private function decorateServices(Collection $services, array $maps): Collection
    {
        [$devices, $deviceIds, $numbers] = $maps;
        $cxcIds = $services->pluck('idcuentasPorCobrar')->filter()->unique()->values();
        $linkedServiceAmountsByCxc = [];
        if ($cxcIds->isNotEmpty() && Schema::hasTable('cuentasporcobrar_serviciocliente')) {
            $linkedServiceAmountsByCxc = DB::table('cuentasporcobrar_serviciocliente')
                ->whereIn('cuentasPorCobrar_idcuentasPorCobrar', $cxcIds)
                ->get([
                    'cuentasPorCobrar_idcuentasPorCobrar',
                    'servicioCliente_idservicioCliente',
                    'montoServicio',
                ])
                ->groupBy('cuentasPorCobrar_idcuentasPorCobrar')
                ->map(fn(Collection $links): array => $links
                    ->filter(fn($link): bool => $link->montoServicio !== null)
                    ->mapWithKeys(fn($link): array => [
                        (int) $link->servicioCliente_idservicioCliente => (float) $link->montoServicio,
                    ])
                    ->all())
                ->all();
        }
        $paidInstallmentsByCxc = $cxcIds->isEmpty()
            ? []
            : DB::table('detallecxc')
                ->whereIn('cuentasPorCobrar_idcuentasPorCobrar', $cxcIds)
                ->where('estado', '1')
                ->whereNotNull('nCuota')
                ->selectRaw('cuentasPorCobrar_idcuentasPorCobrar as cxc_id, MAX(nCuota) as max_cuota')
                ->groupBy('cuentasPorCobrar_idcuentasPorCobrar')
                ->pluck('max_cuota', 'cxc_id')
                ->all();

        return $services->transform(function ($row) use ($devices, $deviceIds, $numbers, $paidInstallmentsByCxc, $services, $linkedServiceAmountsByCxc) {
            $cancelled = in_array((string) ($row->cxc_estado ?? ''), ['3', 'CANCELADO'], true);
            if (!empty($row->cxc_fechaCancelacion)) {
                $row->fecheVencimiento = Carbon::parse($row->cxc_fechaCancelacion)->toDateString();
            }
            $row->fecha_inicio_display = $row->fechaInicio ? Carbon::parse($row->fechaInicio)->format('d/m/Y') : '-';
            $row->fecha_vencimiento_display = $row->fecheVencimiento ? Carbon::parse($row->fecheVencimiento)->format('d/m/Y') : '-';
            $amount = $cancelled ? ($row->monto ?? 0) : ($row->cxc_montoActual ?? $row->cxc_montoOriginal ?? $row->monto);
            $row->service_cxc_monto_actual = $cancelled
                ? 0.0
                : $this->serviceReceivableBalance($row, $services, $linkedServiceAmountsByCxc);
            $row->monto_display = $amount !== null ? $this->currency($amount, $row->moneda_simbolo ?? null) : '-';
            $row->estado_display = $this->creditInstallmentDisplay(
                (string) ($row->cxc_estado ?? ''),
                (int) ($row->cxc_canCuotas ?? 0),
                (int) ($paidInstallmentsByCxc[$row->idcuentasPorCobrar] ?? 0)
            );
            $period = $this->servicePeriod($row->servicio_periodo ?? null);
            $row->servicio_periodo_meses = ServicePeriod::months($row->servicio_periodo ?? null);
            $detail = trim((string) ($row->servicio_detalle ?? ''));
            $row->servicio_detalle = $period !== '' ? trim($detail . ' - ' . $period) : ($detail ?: '-');
            if ($row->fecheVencimiento) {
                $row->dias_restantes = (int) Carbon::today()->diffInDays(Carbon::parse($row->fecheVencimiento)->startOfDay(), false);
            }
            $serviceId = $row->idservicioCliente ?? null;
            $row->id_dispositivo = $devices[$serviceId] ?? '-';
            $row->numero_sim = $numbers[$serviceId] ?? '-';
            $row->is_integrador = $this->isTruthy($row->flag_integrador ?? '');
            return $row;
        });
    }

    private function serviceReceivableBalance(object $service, Collection $services, array $linkedServiceAmountsByCxc): float
    {
        $cxcId = (int) ($service->idcuentasPorCobrar ?? 0);
        if ($cxcId <= 0) {
            return round((float) ($service->monto ?? 0), 2);
        }

        $linkedServices = $services
            ->where('idcuentasPorCobrar', $cxcId)
            ->unique('idservicioCliente')
            ->values();
        if ($linkedServices->count() <= 1) {
            return round((float) ($service->cxc_montoActual ?? $service->cxc_montoOriginal ?? $service->monto ?? 0), 2);
        }

        $weights = $linkedServices->mapWithKeys(fn($row): array => [
            (int) $row->idservicioCliente => max(
                0,
                (float) ($linkedServiceAmountsByCxc[$cxcId][(int) $row->idservicioCliente] ?? $row->monto ?? 0)
            ),
        ]);
        $totalWeight = (float) $weights->sum();
        if ($totalWeight <= 0) {
            return 0.0;
        }

        $remainingBalance = round(max(0, (float) ($service->cxc_montoActual ?? 0)), 2);
        foreach ($linkedServices as $index => $linkedService) {
            $serviceId = (int) $linkedService->idservicioCliente;
            $share = $index === $linkedServices->count() - 1
                ? $remainingBalance
                : round((float) ($service->cxc_montoActual ?? 0) * (float) ($weights[$serviceId] ?? 0) / $totalWeight, 2);
            $remainingBalance = round($remainingBalance - $share, 2);
            if ($serviceId === (int) $service->idservicioCliente) {
                return $share;
            }
        }

        return 0.0;
    }

    private function appendHistoricalPeriods(Collection $services, Collection $historicalByClient): Collection
    {
        $representatives = [];
        $servicesByClient = [];
        foreach ($services->groupBy('cliente_idcliente') as $clientServices) {
            $uniqueServices = $clientServices
                ->filter(fn($service): bool => (int) ($service->idservicioCliente ?? 0) > 0)
                ->unique('idservicioCliente')
                ->sortBy('idservicioCliente')
                ->values();
            $representative = $uniqueServices->first() ?? $clientServices->first();
            if (is_object($representative)) {
                $representatives[(string) $representative->cliente_idcliente] = $representative;
                $servicesByClient[(string) $representative->cliente_idcliente] = $uniqueServices;
            }
        }
        $loadedCxcIds = array_fill_keys(
            $services->pluck('idcuentasPorCobrar')->filter()->map(fn($id) => (int) $id)->all(),
            true
        );
        $linkedServiceIdsByCxc = [];
        $linkedServiceAmountsByCxc = [];
        $historicalCharges = $historicalByClient->flatten(1);
        $historicalCxcIds = $historicalCharges
            ->pluck('idcuentasPorCobrar')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $historicalCxcLookup = array_fill_keys($historicalCxcIds->all(), true);
        if (Schema::hasTable('cuentasporcobrar_serviciocliente')) {
            if ($historicalCxcIds->isNotEmpty()) {
                $linksByCxc = DB::table('cuentasporcobrar_serviciocliente')
                    ->whereIn('cuentasPorCobrar_idcuentasPorCobrar', $historicalCxcIds)
                    ->get([
                        'cuentasPorCobrar_idcuentasPorCobrar',
                        'servicioCliente_idservicioCliente',
                        'montoServicio',
                    ])
                    ->groupBy('cuentasPorCobrar_idcuentasPorCobrar');
                $linksByCxc->each(function (Collection $links, string|int $cxcId) use (&$linkedServiceIdsByCxc, &$linkedServiceAmountsByCxc): void {
                    $linkedServiceIdsByCxc[(int) $cxcId] = $links
                        ->pluck('servicioCliente_idservicioCliente')
                        ->map(fn ($id): int => (int) $id)
                        ->all();
                    $linkedServiceAmountsByCxc[(int) $cxcId] = $links
                        ->filter(fn ($link): bool => $link->montoServicio !== null)
                        ->mapWithKeys(fn ($link): array => [
                            (int) $link->servicioCliente_idservicioCliente => (float) $link->montoServicio,
                        ])
                        ->all();
                });
            }
        }
        if ($historicalCxcIds->isNotEmpty() && Schema::hasTable('cuentasporcobrar')) {
            $clientIds = $historicalCharges
                ->pluck('cliente_idcliente')
                ->filter()
                ->unique()
                ->values();
            $successorCharges = DB::table('cuentasporcobrar')
                ->whereIn('cliente_idcliente', $clientIds)
                ->where('descripcion', 'like', 'REN-CXC-%')
                ->get(['descripcion', 'montoOriginal']);
            foreach ($successorCharges as $successorCharge) {
                if (
                    preg_match('/^REN-CXC-(\d+)\b/', (string) $successorCharge->descripcion, $parentMatch) !== 1
                    || !isset($historicalCxcLookup[(int) $parentMatch[1]])
                    || preg_match('/SC-([0-9,]+)/', (string) $successorCharge->descripcion, $serviceMatch) !== 1
                ) {
                    continue;
                }

                $parentCxcId = (int) $parentMatch[1];
                $linkedServiceIdsByCxc[$parentCxcId] = array_values(array_unique(array_merge(
                    $linkedServiceIdsByCxc[$parentCxcId] ?? [],
                    array_filter(array_map('intval', explode(',', $serviceMatch[1])))
                )));
                $successorServiceIds = array_values(array_filter(array_map('intval', explode(',', $serviceMatch[1]))));
                if (count($successorServiceIds) === 1 && $successorCharge->montoOriginal !== null) {
                    $linkedServiceAmountsByCxc[$parentCxcId][$successorServiceIds[0]]
                        ??= (float) $successorCharge->montoOriginal;
                }
            }
        }
        $assignedServicesByPeriod = [];

        foreach ($historicalByClient->flatten(1) as $historical) {
            $cxcId = (int) ($historical->idcuentasPorCobrar ?? 0);
            $clientId = (string) ($historical->cliente_idcliente ?? '');
            $representative = $representatives[$clientId] ?? null;
            if ($cxcId <= 0 || isset($loadedCxcIds[$cxcId]) || !$representative) {
                continue;
            }

            $periodEnd = !empty($historical->fechaCancelacion)
                ? Carbon::parse($historical->fechaCancelacion)->toDateString()
                : null;
            if (!$periodEnd) {
                continue;
            }

            $candidateServices = $servicesByClient[$clientId] ?? collect();
            $periodAssignmentKey = $clientId . '|' . substr($periodEnd, 0, 7);
            $usedServiceIds = $assignedServicesByPeriod[$periodAssignmentKey] ?? [];
            $hasServiceMarker = preg_match('/SC-([0-9,]+)/', (string) ($historical->descripcion ?? ''), $serviceMatch) === 1;
            $linkedServices = $candidateServices->whereIn(
                'idservicioCliente',
                $linkedServiceIdsByCxc[$cxcId] ?? []
            );
            $markedServices = $hasServiceMarker
                ? $candidateServices->whereIn(
                    'idservicioCliente',
                    array_values(array_filter(array_map('intval', explode(',', $serviceMatch[1]))))
                )
                : collect();
            $isAggregateCxc = $hasServiceMarker
                && abs((float) $candidateServices->sum('monto') - (float) ($historical->montoOriginal ?? 0)) < 0.005;

            if ($linkedServices->isNotEmpty()) {
                $matchedServices = $linkedServices;
            } elseif ($isAggregateCxc) {
                $matchedServices = $candidateServices;
            } elseif ($markedServices->isNotEmpty()) {
                $matchedServices = $markedServices;
            } else {
                $amountMatches = $candidateServices->filter(
                    fn($service): bool =>
                        abs((float) ($service->monto ?? 0) - (float) ($historical->montoOriginal ?? 0)) < 0.005
                );
                $availableMatches = $amountMatches->reject(
                    fn($service): bool =>
                        in_array((int) $service->idservicioCliente, $usedServiceIds, true)
                );
                $matchedServices = $availableMatches->isNotEmpty()
                    ? $availableMatches->take(1)
                    : ($candidateServices->count() === 1 && $usedServiceIds === []
                        ? $candidateServices
                        : collect());
            }

            if ($matchedServices->isNotEmpty()) {
                $assignedServicesByPeriod[$periodAssignmentKey] = array_values(array_unique(array_merge(
                    $usedServiceIds,
                    $matchedServices->pluck('idservicioCliente')->map(fn($id): int => (int) $id)->all()
                )));
            }

            $periodService = $matchedServices
                ->filter(fn($service): bool => !empty($service->fecheVencimiento)
                    && Carbon::parse($service->fecheVencimiento)->lte(Carbon::parse($periodEnd)))
                ->sortByDesc('fecheVencimiento')
                ->first();
            $periodStart = $periodService->fechaInicio ?? $representative->fechaInicio;
            if ($periodStart && Carbon::parse($periodStart)->gt(Carbon::parse($periodEnd))) {
                $periodStart = $periodEnd;
            }

            if ($matchedServices->isEmpty()) {
                continue;
            }

            $serviceWeights = $matchedServices->mapWithKeys(function ($service) use ($linkedServiceAmountsByCxc, $cxcId): array {
                $serviceId = (int) ($service->idservicioCliente ?? 0);
                return [$serviceId => $linkedServiceAmountsByCxc[$cxcId][$serviceId] ?? (float) ($service->monto ?? 0)];
            });
            $totalServiceWeight = (float) $serviceWeights->sum();
            $remainingServiceBalance = round(max(0, (float) ($historical->montoActual ?? 0)), 2);
            $matchedServices = $matchedServices->values();
            foreach ($matchedServices as $serviceIndex => $service) {
                $periodService = clone $service;
                $serviceId = (int) ($service->idservicioCliente ?? 0);
                $serviceAmount = $linkedServiceAmountsByCxc[$cxcId][$serviceId]
                    ?? (float) ($service->monto ?? 0);
                $serviceBalance = $serviceIndex === $matchedServices->count() - 1
                    ? $remainingServiceBalance
                    : ($totalServiceWeight > 0
                        ? round((float) ($historical->montoActual ?? 0) * (float) ($serviceWeights[$serviceId] ?? 0) / $totalServiceWeight, 2)
                        : 0.0);
                $remainingServiceBalance = round($remainingServiceBalance - $serviceBalance, 2);
                $periodService->idcuentasPorCobrar = $cxcId;
                $periodService->cxc_estado = $historical->estado;
                $periodService->cxc_docReferencia = $historical->docReferencia;
                $periodService->cxc_montoOriginal = $historical->montoOriginal;
                $periodService->cxc_montoActual = $historical->montoActual;
                $periodService->cxc_fechaRegistro = $historical->fechaRegistro;
                $periodService->cxc_fechaCancelacion = $historical->fechaCancelacion;
                $periodService->cxc_descripcion = $historical->descripcion;
                $periodService->cxc_fecha_pago = $historical->fecha_pago ?? null;
                $periodService->withholding_description = $historical->withholding_description ?? null;
                $periodService->service_cxc_monto_actual = $serviceBalance;
                $periodService->moneda_idmoneda = $historical->moneda_idmoneda ?? $service->moneda_idmoneda;
                $periodService->fechaInicio = $periodStart;
                $periodService->fecheVencimiento = $periodEnd;
                $periodService->docReferencia = $historical->docReferencia;
                $periodService->monto = $serviceAmount;
                $periodService->fecha_inicio_display = $periodStart ? Carbon::parse($periodStart)->format('d/m/Y') : '-';
                $periodService->fecha_vencimiento_display = $periodEnd ? Carbon::parse($periodEnd)->format('d/m/Y') : '-';
                $periodService->dias_restantes = $periodEnd
                    ? (int) Carbon::today()->diffInDays(Carbon::parse($periodEnd)->startOfDay(), false)
                    : 0;
                $periodService->monto_display = $this->currency($serviceAmount, $periodService->moneda_simbolo ?? null);
                $services->push($periodService);
            }
        }

        return $services;
    }

    private function periodFromDescription(string $description): array
    {
        if (preg_match('/(\d{2}\/\d{2}\/\d{4})\s+a\s+(\d{2}\/\d{2}\/\d{4})/', $description, $matches)) {
            return [
                Carbon::createFromFormat('d/m/Y', $matches[1])->format('Y-m-d'),
                Carbon::createFromFormat('d/m/Y', $matches[2])->format('Y-m-d'),
            ];
        }

        return [];
    }

    private function periodEndFromStart(?string $periodStart, mixed $periodValue): ?string
    {
        $months = ServicePeriod::months($periodValue);
        if (!$periodStart || $months === null) {
            return null;
        }

        return ServicePeriod::addToDate($periodStart, $months)->format('Y-m-d');
    }

    private function groupServices(
        Collection $services,
        Collection $historicalByClient,
        array $creditDeadlines,
        Collection $tipoCobroDetails,
        array $creditChildrenByParent
    ): array {
        $groups = [];
        $byPeriod = $services->groupBy(fn($service) => implode('|', [
            (string) $service->cliente_idcliente,
            Carbon::parse($service->fecheVencimiento)->format('Y-m'),
            (string) ($service->servicio_moneda_id ?? $service->moneda_idmoneda ?? ''),
        ]));
        foreach ($byPeriod as $clientServices) {
            $first = $clientServices->first();
            $activeStates = ['1', '2', '4', 'NUEVO', 'PagoCredito', 'PENDIENTE', 'FACTURADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito', 'Cancelado Detracción', 'Pendiente Autodetracción', 'Pendiente Autoretención', 'Pendiente a Detracción', 'Pendiente a Retención'];
            $unique = $clientServices->unique(
                fn($service): string =>
                    (int) ($service->idservicioCliente ?? 0) > 0
                    ? 'service-' . $service->idservicioCliente
                    : 'cxc-' . ($service->idcuentasPorCobrar ?? spl_object_id($service))
            );
            foreach ($unique as $service) {
                $parentId = (int) ($service->idcuentasPorCobrar ?? 0);
                $nextInstallment = collect($creditChildrenByParent[$parentId] ?? [])
                    ->first(fn(array $item): bool => $item['amount'] > 0.01);
                if ($nextInstallment) {
                    $service->credit_target_cxc_id = $nextInstallment['id'];
                    $service->credit_target_amount = $nextInstallment['amount'];
                    $service->credit_target_number = $nextInstallment['number'];
                    $service->credit_target_date = $nextInstallment['date'];
                }
            }
            $types = $unique->groupBy('servicio_detalle');
            $active = $unique->filter(fn($service) => ($service->dias_restantes ?? 0) >= 0);
            $closest = ($active->isNotEmpty() ? $active : $unique)->sortBy('dias_restantes')->first() ?: $first;
            $earliestStart = $unique->sortBy(fn($service) => Carbon::parse($service->fechaInicio)->timestamp)->first() ?: $first;
            $latestEnd = $unique->sortByDesc(fn($service) => Carbon::parse($service->fecheVencimiento)->timestamp)->first() ?: $first;
            $periodStart = Carbon::parse($earliestStart->fechaInicio);
            $periodEnd = Carbon::parse($latestEnd->fecheVencimiento);
            $state = (string) ($cxc->cxc_estado ?? $first->cxc_estado);
            $original = round((float) ($cxc->cxc_montoOriginal ?? $unique->sum('monto')), 2);
            $balance = in_array($state, array_merge($activeStates, ['3', 'CANCELADO']), true) ? round((float) ($cxc->cxc_montoActual ?? $original), 2) : $original;
            $servicesAmount = round((float) $unique->sum('monto'), 2);
            $periodRange = Carbon::parse($first->fechaInicio)->format('d/m/Y') . ' a ' . Carbon::parse($first->fecheVencimiento)->format('d/m/Y');
            $normalizedPeriodRange = mb_strtolower($periodRange, 'UTF-8');
            $matchingCxc = $this->matchHistoricalCharge(
                $historicalByClient->get($first->cliente_idcliente) ?? collect(),
                $activeStates,
                $normalizedPeriodRange,
                Carbon::parse($latestEnd->fecheVencimiento)->toDateString()
            );

            $withholdingStates = ['Cancelado Detracción', 'Pendiente Autodetracción', 'Pendiente Autoretención', 'Pendiente a Detracción', 'Pendiente a Retención'];
            $pendingWithholdingCxc = $clientServices->first(
                fn($service): bool => in_array((string) ($service->cxc_estado ?? ''), $withholdingStates, true)
            );
            $cancelledCxc = $clientServices->first(fn($service) => in_array((string) ($service->cxc_estado ?? ''), array_merge(['3', 'CANCELADO'], $withholdingStates), true));
            $cancelledGroupCxc = $clientServices
                ->filter(fn($service): bool =>
                    (int) ($service->idcuentasPorCobrar ?? 0) > 0
                    && in_array((string) ($service->cxc_estado ?? ''), array_merge(['3', 'CANCELADO'], $withholdingStates), true)
                )
                ->unique('idcuentasPorCobrar')
                ->values();
            $canRevertGroup = $cancelledGroupCxc->isNotEmpty()
                && $cancelledGroupCxc->every(fn($charge): bool =>
                    !empty($charge->cxc_fecha_pago)
                    && Carbon::today()->lte(Carbon::parse($charge->cxc_fecha_pago)->addDays(7)->startOfDay())
                );
            $groupCharges = $clientServices
                ->filter(fn($service): bool => (int) ($service->idcuentasPorCobrar ?? 0) > 0
                    && in_array((string) ($service->cxc_estado ?? ''), $activeStates, true))
                ->unique('idcuentasPorCobrar')
                ->sortByDesc('idcuentasPorCobrar')
                ->values();
            $cxc = $pendingWithholdingCxc
                ?: ($groupCharges
                    ->reject(fn($charge): bool => (bool) ($charge->service_credit_installment ?? false))
                    ->sortByDesc('idcuentasPorCobrar')
                    ->first()
                ?: ($matchingCxc
                    ?: ($cancelledCxc ?: ($clientServices->firstWhere('cxc_estado', '!=', null) ?: $first))));

            $groupNeedsInvoice = $groupCharges->contains(
                fn($charge): bool => !(bool) ($charge->service_credit_installment ?? false)
                    && !isset($creditChildrenByParent[(int) ($charge->idcuentasPorCobrar ?? 0)])
                    && (
                        in_array((string) ($charge->cxc_estado ?? ''), ['1', '4', 'NUEVO', 'PagoCredito', 'PENDIENTE', 'VENCIDO', 'Pendiente Pago parcial'], true)
                        || (!in_array((string) ($charge->cxc_estado ?? ''), $withholdingStates, true)
                            && trim((string) ($charge->cxc_docReferencia ?? '')) === '')
                    )
            );
            $hasUnpaid = $groupNeedsInvoice
                || (!(bool) ($cxc->service_credit_installment ?? false)
                    && !isset($creditChildrenByParent[(int) ($cxc->idcuentasPorCobrar ?? 0)])
                    && !in_array((string) ($cxc->cxc_estado ?? ''), $withholdingStates, true)
                    && trim((string) ($cxc->cxc_docReferencia ?? '')) === '');
            $state = $pendingWithholdingCxc
                ? (string) $pendingWithholdingCxc->cxc_estado
                : ($groupNeedsInvoice
                    ? '1'
                    : (string) ($cxc->cxc_estado ?? $cxc->estado ?? $first->cxc_estado));
            $creditDeadline = $creditDeadlines[(int) ($cxc->idcuentasPorCobrar ?? 0)] ?? null;
            $effectiveState = $this->effectivePaymentState(
                $state,
                $periodEnd->format('Y-m-d'),
                $creditDeadline,
                $cxc->withholding_description ?? null
            );
            $original = round((float) ($cxc->cxc_montoOriginal ?? $cxc->montoOriginal ?? $servicesAmount), 2);
            $financialGroupCharges = $groupCharges->reject(
                fn($charge): bool => (bool) ($charge->service_credit_installment ?? false)
                    || isset($creditChildrenByParent[(int) $charge->idcuentasPorCobrar])
            );
            $groupBalance = $financialGroupCharges->sum(
                fn($charge): float =>
                    (float) ($charge->cxc_montoActual ?? $charge->cxc_montoOriginal ?? $charge->montoActual ?? $charge->montoOriginal ?? 0)
            );
            $creditInstallmentTotal = round((float) $unique
                ->filter(fn($service): bool => (bool) ($service->service_credit_installment ?? false))
                ->sum(fn($service): float => (float) ($service->monto ?? 0)), 2);
            $isCreditPlanControl = isset($creditChildrenByParent[(int) ($cxc->idcuentasPorCobrar ?? 0)]);
            $balance = $pendingWithholdingCxc
                ? round((float) ($pendingWithholdingCxc->cxc_montoActual ?? $pendingWithholdingCxc->montoActual ?? 0), 2)
                : ($isCreditPlanControl
                    ? 0.0
                    : ($financialGroupCharges->isNotEmpty()
                    ? round((float) $groupBalance, 2)
                : (in_array($state, array_merge($activeStates, ['3', 'CANCELADO', 'Cancelado Detracción', 'Pendiente Autodetracción', 'Pendiente Autoretención', 'Pendiente a Detracción', 'Pendiente a Retención']), true)
                    ? round((float) ($cxc->cxc_montoActual ?? $cxc->montoActual ?? $original), 2)
                    : $original)));
            $montoTotal = $financialGroupCharges->isNotEmpty()
                ? round($groupBalance + $creditInstallmentTotal, 2)
                : $servicesAmount;
            $groupChargeIds = $groupCharges->pluck('idcuentasPorCobrar')
                ->map(fn($id): int => (int) $id)
                ->all();
            $paymentServiceIds = $unique
                ->filter(fn($service): bool =>
                    (int) ($service->idcuentasPorCobrar ?? 0) <= 0
                    || in_array((int) $service->idcuentasPorCobrar, $groupChargeIds, true)
                )
                ->pluck('idservicioCliente')
                ->filter(fn($id): bool => (int) $id > 0)
                ->map(fn($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
            $currency = $this->currencySymbol($first->moneda_simbolo ?? null);
            $periodTipoCobroId = $this->tipoCobroIdFromPeriod($first->servicio_periodo ?? null);
            $deudaAnterior = $this->previousOutstandingDebt(
                $historicalByClient->get($first->cliente_idcliente) ?? collect(),
                $cxc
            );
            $registrationDate = $cxc->cxc_fechaRegistro ?? $cxc->fechaRegistro ?? null;
            $currentCxcId = (int) ($cxc->idcuentasPorCobrar ?? 0);
            $nextPeriodEnds = $unique
                ->map(fn($service): ?string => $this->nextServicePeriodEnd($service, $tipoCobroDetails))
                ->filter()
                ->unique()
                ->values();

            $groups[] = [
                'cliente_id' => $first->cliente_idcliente,
                'es_retencion' => (string) ($first->cliente_detraccion ?? '') === '1',
                'cxc_id' => (int) ($cxc->idcuentasPorCobrar ?? 0),
                'tipo_cobro_id' => $periodTipoCobroId ?: (int) ($cxc->cxc_tipoCobro_idtipoCobros ?? $first->cxc_tipoCobro_idtipoCobros ?? 0),
                'cliente_nombre' => $first->cliente_nombre ?? '-',
                'is_integrador' => (bool) ($first->is_integrador ?? false),
                'num_unidades' => $unique->pluck('vehiculo_placa')->filter()->unique()->count(),
                'num_tservicios' => $types->count(),
                'fecha_inicio' => $periodStart->format('d/m/Y'),
                'fecha_registro' => $registrationDate ? Carbon::parse($registrationDate)->format('d/m/Y') : '-',
                'fecha_registro_iso' => $registrationDate ? Carbon::parse($registrationDate)->toDateString() : '',
                'fecha_fin' => $periodEnd->format('d/m/Y'),
                'fecha_fin_iso' => $periodEnd->format('Y-m-d'),
                'fecha_fin_siguiente_iso' => (string) ($nextPeriodEnds->first() ?? ''),
                'documento' => trim((string) ($cxc->cxc_docReferencia ?? '')) ?: '-',
                'cxc_estado' => $state,
                'cxc_doc' => trim((string) ($cxc->cxc_docReferencia ?? '')),
                'cxc_reversion_ids' => $cancelledGroupCxc
                    ->pluck('idcuentasPorCobrar')
                    ->map(fn($id): int => (int) $id)
                    ->all(),
                'adelanto_meses' => CxcService::advanceMonthsFromDescription((string) ($cxc->descripcion ?? '')),
                'cxc_fecha_cancelacion' => $cxc->cxc_fechaCancelacion ?? null,
                'puede_revertir' => $canRevertGroup,
                'mes' => $periodEnd->locale('es')->monthName,
                'anio' => $periodEnd->year,
                'estado_pago' => $this->paymentState($effectiveState, $hasUnpaid),
                'estado_servicio' => $active->isNotEmpty() ? 'activo' : 'vencido',
                'dias_restantes' => $closest->dias_restantes ?? 0,
                'monto_total' => $montoTotal,
                'monto_total_display' => $this->currency($montoTotal, $currency),
                'monto_factura' => $montoTotal,
                'monto_saldo' => $balance,
                'monto_saldo_display' => $this->currency($balance, $currency),
                'monto_servicios' => $servicesAmount,
                'monto_servicios_display' => $this->currency($servicesAmount, $currency),
                'cxc_ids' => $groupCharges
                    ->reject(fn($charge): bool => (bool) ($charge->service_credit_installment ?? false))
                    ->pluck('idcuentasPorCobrar')
                    ->map(fn($id): int => (int) $id)
                    ->all(),
                'deuda_anterior' => $deudaAnterior,
                'deuda_anterior_display' => $this->currency($deudaAnterior, $currency),
                'moneda_idmoneda' => (int) ($first->moneda_idmoneda ?? 1),
                'moneda_simbolo' => $currency,
                'all_service_ids' => $unique->pluck('idservicioCliente')->filter(fn($id): bool => (int) $id > 0)->all(),
                'payment_service_ids' => $paymentServiceIds,
                'credit_target_cxc_id' => (int) ($creditChildrenByParent[$currentCxcId][0]['id'] ?? 0),
                'credit_target_amount' => (float) ($creditChildrenByParent[$currentCxcId][0]['amount'] ?? 0),
                'credit_target_number' => (int) ($creditChildrenByParent[$currentCxcId][0]['number'] ?? 0),
                'credit_target_date' => (string) ($creditChildrenByParent[$currentCxcId][0]['date'] ?? ''),
                'tipos_servicios' => $this->serviceTypes(
                    $types,
                    $currency,
                    $deudaAnterior,
                    $activeStates,
                    $tipoCobroDetails
                ),
            ];
        }

        return $groups;
    }

    private function matchHistoricalCharge(
        Collection $charges,
        array $activeStates,
        string $normalizedPeriodRange,
        ?string $periodEnd = null
    ): ?object {
        $eligibleCharges = $charges->filter(
            fn($charge): bool =>
                in_array((string) ($charge->estado ?? ''), $activeStates, true)
        );

        if ($normalizedPeriodRange !== '') {
            $periodMatch = $eligibleCharges->first(
                fn($charge): bool =>
                    str_contains(mb_strtolower((string) ($charge->descripcion ?? ''), 'UTF-8'), $normalizedPeriodRange)
            );
            if ($periodMatch) {
                $matchedCxc = clone $periodMatch;
                $matchedCxc->cxc_docReferencia = $periodMatch->docReferencia ?? null;
                return $matchedCxc;
            }
        }

        if ($periodEnd !== null) {
            $dateMatch = $eligibleCharges->first(
                fn($charge): bool =>
                    !empty($charge->fechaCancelacion)
                    && Carbon::parse($charge->fechaCancelacion)->toDateString() === $periodEnd
            );
            if ($dateMatch) {
                $matchedCxc = clone $dateMatch;
                $matchedCxc->cxc_docReferencia = $dateMatch->docReferencia ?? null;
                return $matchedCxc;
            }
        }

        return null;
    }

    private function serviceTypes(
        Collection $types,
        string $currency,
        float $previousDebt,
        array $activeStates,
        Collection $tipoCobroDetails
    ): array
    {
        $result = [];
        $withholdingStates = ['Cancelado Detracción', 'Pendiente Autodetracción', 'Pendiente Autoretención', 'Pendiente a Detracción', 'Pendiente a Retención'];
        foreach ($types as $name => $services) {
            $uniqueServices = $services->unique(
                fn($service): string =>
                    (int) ($service->idservicioCliente ?? 0) > 0
                    ? 'service-' . $service->idservicioCliente
                    : 'cxc-' . ($service->idcuentasPorCobrar ?? spl_object_id($service))
            )->values();
            $linkedServiceIdsByCxc = [];
            $typeCxcIds = $uniqueServices->pluck('idcuentasPorCobrar')->filter()->map(fn($id): int => (int) $id)->unique();
            if ($typeCxcIds->isNotEmpty() && Schema::hasTable('cuentasporcobrar_serviciocliente')) {
                $linkedServiceIdsByCxc = DB::table('cuentasporcobrar_serviciocliente')
                    ->whereIn('cuentasPorCobrar_idcuentasPorCobrar', $typeCxcIds)
                    ->get(['cuentasPorCobrar_idcuentasPorCobrar', 'servicioCliente_idservicioCliente'])
                    ->groupBy('cuentasPorCobrar_idcuentasPorCobrar')
                    ->map(fn(Collection $links): array => $links
                        ->pluck('servicioCliente_idservicioCliente')
                        ->map(fn($id): int => (int) $id)
                        ->unique()
                        ->values()
                        ->all())
                    ->all();
            }
            $vehicles = $uniqueServices->map(fn($service) => [
                'idservicioCliente' => $service->idservicioCliente,
                'placa' => $service->vehiculo_placa ?? '-',
                'tipo_cobro_id' => $this->tipoCobroIdFromPeriod($service->servicio_periodo ?? null) ?: (int) ($service->cxc_tipoCobro_idtipoCobros ?? 0),
                'id_dispositivo' => $service->id_dispositivo ?? '-',
                'numero' => $service->numero_sim ?? '-',
                'tipo' => $service->tipo_vehiculo ?: '-',
                'marca' => $service->vehiculo_marca ?: '-',
                'modelo' => $service->vehiculo_modelo ?: '-',
                'tracto' => $service->vehiculo_tracto ?: '-',
                'monto' => (float) ($service->monto ?? 0),
                'monto_display' => $this->currency($service->monto ?? 0, $currency),
                'monto_saldo' => (float) ($service->service_cxc_monto_actual ?? $service->cxc_montoActual ?? $service->cxc_montoOriginal ?? $service->monto ?? 0),
                'cxc_ids' => (int) ($service->idcuentasPorCobrar ?? 0) > 0 ? [(int) $service->idcuentasPorCobrar] : [],
                'cxc_estado' => $service->cxc_estado ?? $service->estado ?? '1',
                'service_credit_installment' => (bool) ($service->service_credit_installment ?? false),
                'credit_installment_date' => (string) (($service->service_credit_installment ?? false) ? $service->cxc_fechaCancelacion : ''),
                'credit_target_cxc_id' => (int) ($service->credit_target_cxc_id ?? 0),
                'credit_target_amount' => (float) ($service->credit_target_amount ?? 0),
                'credit_target_number' => (int) ($service->credit_target_number ?? 0),
                'credit_target_date' => (string) ($service->credit_target_date ?? ''),
                'puede_revertir' => $this->canRevertIndividualService(
                    $service,
                    $linkedServiceIdsByCxc[(int) ($service->idcuentasPorCobrar ?? 0)] ?? []
                ),
                'doc_referencia' => trim((string) ($service->cxc_docReferencia ?? '')) ?: '-',
                'fecha_inicio_display' => $service->fecha_inicio_display ?? '-',
                'fecha_vencimiento_display' => $service->fecha_vencimiento_display ?? '-',
                'mes' => Carbon::parse($service->fecheVencimiento)->locale('es')->monthName,
                'fecha_fin_siguiente_iso' => $this->nextServicePeriodEnd($service, $tipoCobroDetails) ?? '',
                'dias_restantes' => $service->dias_restantes,
            ])->values()->all();
            $subtotal = round((float) collect($vehicles)->sum('monto'), 2);
            $typeCharges = $uniqueServices
                ->filter(fn($service): bool => (int) ($service->idcuentasPorCobrar ?? 0) > 0
                    && in_array((string) ($service->cxc_estado ?? ''), $activeStates, true))
                ->unique('idcuentasPorCobrar')
                ->sortByDesc('idcuentasPorCobrar')
                ->values();
            $typeCxcIds = $typeCharges->pluck('idcuentasPorCobrar')->map(fn($id): int => (int) $id)->all();
            $financialTypeCharges = $typeCharges->reject(
                fn($charge): bool => (bool) ($charge->service_credit_installment ?? false)
                    || isset($creditChildrenByParent[(int) $charge->idcuentasPorCobrar])
            );
            $typeBalance = round((float) $financialTypeCharges->sum(
                fn($charge): float =>
                    (float) ($charge->cxc_montoActual ?? $charge->cxc_montoOriginal ?? 0)
            ), 2);
            $typeCxc = $typeCharges
                ->reject(fn($charge): bool => (bool) ($charge->service_credit_installment ?? false))
                ->first() ?? $uniqueServices->first();
            $typeNeedsInvoice = $typeCharges->contains(
                fn($charge): bool => !(bool) ($charge->service_credit_installment ?? false)
                    && (
                        in_array((string) ($charge->cxc_estado ?? ''), ['1', '4', 'NUEVO', 'PagoCredito', 'PENDIENTE', 'VENCIDO', 'Pendiente Pago parcial'], true)
                        || (!in_array((string) ($charge->cxc_estado ?? ''), $withholdingStates, true)
                            && trim((string) ($charge->cxc_docReferencia ?? '')) === '')
                    )
            );
            $pendingTypeWithholdingCxc = $typeCharges->first(
                fn($charge): bool => !(bool) ($charge->service_credit_installment ?? false)
                    && in_array((string) ($charge->cxc_estado ?? ''), $withholdingStates, true)
            );
            if ($pendingTypeWithholdingCxc) {
                $typeCxc = $pendingTypeWithholdingCxc;
                $typeNeedsInvoice = false;
            }
            $typeBalance = $pendingTypeWithholdingCxc
                ? round((float) ($pendingTypeWithholdingCxc->cxc_montoActual ?? $pendingTypeWithholdingCxc->montoActual ?? 0), 2)
                : $typeBalance;
            $isCreditPlanControl = isset($creditChildrenByParent[(int) ($typeCxc->idcuentasPorCobrar ?? 0)]);
            $typeOutstanding = $isCreditPlanControl
                ? 0.0
                : ($financialTypeCharges->isNotEmpty() ? $typeBalance : $subtotal);
            $typeChargeIds = $typeCharges->pluck('idcuentasPorCobrar')
                ->map(fn($id): int => (int) $id)
                ->all();
            $paymentServiceIds = $uniqueServices
                ->filter(fn($service): bool =>
                    (int) ($service->idcuentasPorCobrar ?? 0) <= 0
                    || in_array((int) $service->idcuentasPorCobrar, $typeChargeIds, true)
                )
                ->pluck('idservicioCliente')
                ->filter(fn($id): bool => (int) $id > 0)
                ->map(fn($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
            $nextPeriodEnds = collect($vehicles)
                ->pluck('fecha_fin_siguiente_iso')
                ->filter()
                ->unique()
                ->values();
            $result[] = [
                'servicio_nombre' => $name,
                'plataforma' => $services->first()->plataforma ?? '-',
                'tipo_cobro_id' => $this->tipoCobroIdFromPeriod($services->first()->servicio_periodo ?? null) ?: (int) ($services->first()->cxc_tipoCobro_idtipoCobros ?? 0),
                'monto_subtotal' => $typeOutstanding,
                'monto_subtotal_display' => $this->currency($typeOutstanding, $currency),
                'monto_factura' => $typeOutstanding,
                'monto_saldo' => $typeOutstanding,
                'monto_saldo_display' => $this->currency($typeOutstanding, $currency),
                'cxc_ids' => $typeCharges
                    ->reject(fn($charge): bool => (bool) ($charge->service_credit_installment ?? false))
                    ->pluck('idcuentasPorCobrar')
                    ->map(fn($id): int => (int) $id)
                    ->all(),
                'cxc_id' => (int) ($typeCxc->idcuentasPorCobrar ?? 0),
                'cxc_estado' => $typeNeedsInvoice ? '1' : ($typeCxc->cxc_estado ?? $typeCxc->estado ?? '1'),
                'cxc_doc' => trim((string) ($typeCxc->cxc_docReferencia ?? '')),
                'service_credit_installment' => (bool) ($typeCxc->service_credit_installment ?? false),
                'credit_installment_date' => (string) (($typeCxc->service_credit_installment ?? false) ? $typeCxc->cxc_fechaCancelacion : ''),
                'credit_target_cxc_id' => (int) ($uniqueServices->first()->credit_target_cxc_id ?? 0),
                'credit_target_amount' => (float) ($uniqueServices->first()->credit_target_amount ?? 0),
                'credit_target_number' => (int) ($uniqueServices->first()->credit_target_number ?? 0),
                'credit_target_date' => (string) ($uniqueServices->first()->credit_target_date ?? ''),
                'deuda' => $previousDebt > 0 && $result === [] ? $this->currency($previousDebt, $currency) : null,
                'service_ids' => $paymentServiceIds,
                'fecha_fin_siguiente_iso' => (string) ($nextPeriodEnds->first() ?? ''),
                'vehiculos' => $vehicles,
            ];
        }
        return $result;
    }

    private function nextServicePeriodEnd(object $service, Collection $tipoCobroDetails): ?string
    {
        if (empty($service->fecheVencimiento)) {
            return null;
        }

        $periodEnd = Carbon::parse($service->fecheVencimiento);
        $months = ServicePeriod::months($service->servicio_periodo ?? null);
        if ($months !== null) {
            return $periodEnd->addMonthsNoOverflow($months)->toDateString();
        }

        $tipoCobroId = $this->tipoCobroIdFromPeriod($service->servicio_periodo ?? null)
            ?: (int) ($service->cxc_tipoCobro_idtipoCobros ?? $service->tipoCobro_idtipoCobros ?? 0);
        $tipoCobro = $tipoCobroDetails->get($tipoCobroId);
        $recurrencia = strtoupper(trim((string) ($tipoCobro->recurrencia ?? '')));
        $tiempo = max(1, (int) ($tipoCobro->tiempo ?? 1));

        return $recurrencia === 'D'
            ? $periodEnd->addDays($tiempo)->toDateString()
            : $periodEnd->addMonthsNoOverflow($recurrencia === 'M' ? $tiempo : 1)->toDateString();
    }

    private function canRevertIndividualService(object $service, array $linkedServiceIds): bool
    {
        $cxcId = (int) ($service->idcuentasPorCobrar ?? 0);
        $serviceId = (int) ($service->idservicioCliente ?? 0);
        $state = (string) ($service->cxc_estado ?? '');
        $paidAt = $service->cxc_fecha_pago ?? null;

        if (
            $cxcId <= 0
            || $serviceId <= 0
            || !in_array($state, ['3', 'CANCELADO', 'Cancelado Detracción', 'Pendiente Autodetracción', 'Pendiente Autoretención', 'Pendiente a Detracción', 'Pendiente a Retención'], true)
            || !$paidAt
            || Carbon::today()->gt(Carbon::parse($paidAt)->addDays(7)->startOfDay())
        ) {
            return false;
        }

        $description = (string) ($service->cxc_descripcion ?? '');
        if (preg_match('/SC-([0-9,]+)/', $description, $matches) === 1) {
            $linkedServiceIds = array_merge(
                $linkedServiceIds,
                array_map('intval', explode(',', $matches[1]))
            );
        }
        $linkedServiceIds = array_values(array_unique(array_filter($linkedServiceIds)));

        return count($linkedServiceIds) === 1 && $linkedServiceIds[0] === $serviceId;
    }

    private function previousOutstandingDebt(Collection $history, object $currentCxc): float
    {
        $currentId = (int) ($currentCxc->idcuentasPorCobrar ?? 0);
        $currencyId = (int) ($currentCxc->moneda_idmoneda ?? 0);
        $description = (string) ($currentCxc->cxc_descripcion ?? $currentCxc->descripcion ?? '');
        $previousIds = [];

        while (preg_match('/REN-CXC-(\d+)/', $description, $match)) {
            $previousId = (int) $match[1];
            if ($previousId <= 0 || $previousId === $currentId || isset($previousIds[$previousId])) {
                break;
            }

            $previousIds[$previousId] = true;
            $previous = $history->firstWhere('idcuentasPorCobrar', $previousId);
            if (!$previous) {
                break;
            }
            $description = (string) ($previous->descripcion ?? '');
        }

        $unpaidStates = ['1', '2', '4', 'NUEVO', 'PagoCredito', 'PENDIENTE', 'FACTURADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito'];
        return round((float) $history
            ->filter(fn($charge): bool => isset($previousIds[(int) ($charge->idcuentasPorCobrar ?? 0)])
                && ($currencyId === 0 || (int) ($charge->moneda_idmoneda ?? 0) === $currencyId)
                && in_array((string) ($charge->estado ?? ''), $unpaidStates, true)
                && (float) ($charge->montoActual ?? 0) > 0)
            ->sum('montoActual'), 2);
    }

    private function paymentDate(Collection $history, int $cxcId): ?string
    {
        if ($cxcId <= 0) {
            return null;
        }

        $date = DB::table('detallecxc')
            ->where('cuentasPorCobrar_idcuentasPorCobrar', $cxcId)
            ->where('estado', '1')
            ->whereNotNull('fechaPago')
            ->max('fechaPago');

        return $date ? Carbon::parse($date)->toDateString() : null;
    }

    private function history(Collection $history, string $range, object $first, string $currency, ?object $cxc = null, array $serviceIds = [], array $plates = []): array
    {
        $chainCxcIds = [];
        if ($cxc) {
            $currId = (int) ($cxc->idcuentasPorCobrar ?? $cxc->cxc_id ?? 0);
            if ($currId > 0) {
                $chainCxcIds[$currId] = true;
            }
            $currDesc = (string) ($cxc->descripcion ?? $cxc->cxc_descripcion ?? '');
            if (preg_match('/REN-CXC-(\d+)/', $currDesc, $m)) {
                $prevId = (int) $m[1];
                while ($prevId > 0 && !isset($chainCxcIds[$prevId])) {
                    $chainCxcIds[$prevId] = true;
                    $prevCxc = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $prevId)->first();
                    if ($prevCxc && !empty($prevCxc->descripcion) && preg_match('/REN-CXC-(\d+)/', $prevCxc->descripcion, $pm)) {
                        $prevId = (int) $pm[1];
                    } else {
                        $prevId = 0;
                    }
                }
            }
        }

        $serviceCxcIds = [];
        if (!empty($serviceIds)) {
            $historialDescs = DB::table('historial_servicio')
                ->whereIn('servicioCliente_idservicioCliente', $serviceIds)
                ->pluck('descripcion')
                ->all();

            foreach ($historialDescs as $desc) {
                if (preg_match_all('/CXC\s*#?(\d+)/i', (string) $desc, $matches)) {
                    foreach ($matches[1] as $matchedId) {
                        $serviceCxcIds[(int) $matchedId] = true;
                    }
                }
            }
        }

        return $history->filter(function ($item) use ($range, $chainCxcIds, $serviceCxcIds, $plates) {
            $itemCxcId = (int) ($item->idcuentasPorCobrar ?? 0);

            if (isset($chainCxcIds[$itemCxcId])) {
                return true;
            }

            if (isset($serviceCxcIds[$itemCxcId])) {
                return true;
            }

            if (!empty($item->vehiculo_placa) && in_array($item->vehiculo_placa, $plates, true)) {
                return true;
            }

            $desc = (string) ($item->descripcion ?? '');
            if ($range !== '' && str_contains($desc, $range)) {
                return true;
            }

            if (empty($plates) && empty($chainCxcIds) && empty($serviceCxcIds)) {
                return true;
            }

            return false;
        })->map(fn($item) => [
                'id' => (int) $item->idcuentasPorCobrar,
                'estado' => 'Cancelado',
                'monto_original_raw' => round((float) $item->montoOriginal, 2),
                'monto_a_pagar' => $this->currency($item->montoOriginal, $currency),
                'monto_pagado' => $this->currency(max((float) $item->montoOriginal - (float) $item->montoActual, 0), $currency),
                'deuda' => (float) $item->montoActual > 0 ? $this->currency($item->montoActual, $currency) : null,
                'fecha_pago' => $item->fechaCancelacion ? Carbon::parse($item->fechaCancelacion)->format('d/m/Y') : '-',
                'documento' => trim((string) ($item->docReferencia ?? '')) ?: '-',
                'comentario' => trim((string) ($item->descripcion ?? '')) ?: '-',
            ])->values()->all();
    }

    private function sortGroups(array &$groups): void
    {
        usort($groups, function ($a, $b) {
            $rank = [
                'facturado' => 0,
                'pendiente pago parcial' => 1,
                'pendiente a credito' => 2,
                'pagocredito' => 3,
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
            if ($rankA !== $rankB)
                return $rankA <=> $rankB;
            return strcmp(strtolower((string) $a['cliente_nombre']), strtolower((string) $b['cliente_nombre']));
        });
    }

    private function latestPaymentDateSubquery()
    {
        $paymentDates = DB::table('detallecxc as payment_detail')
            ->whereColumn(
                'payment_detail.cuentasPorCobrar_idcuentasPorCobrar',
                'c.idcuentasPorCobrar'
            )
            ->where('payment_detail.estado', '1')
            ->whereNotNull('payment_detail.fechaPago')
            ->selectRaw('MAX(payment_detail.fechaPago) as payment_date');

        if (Schema::hasTable('nota_credito_aplicacion')) {
            $creditNoteDates = DB::table('nota_credito_aplicacion as credit_note_application')
                ->whereColumn(
                    'credit_note_application.cuentasPorCobrar_idcuentasPorCobrar',
                    'c.idcuentasPorCobrar'
                )
                ->where('credit_note_application.estado', '1')
                ->whereNotNull('credit_note_application.fechaAplicacion')
                ->selectRaw('MAX(credit_note_application.fechaAplicacion) as payment_date');

            $paymentDates->unionAll($creditNoteDates);
        }

        return DB::query()
            ->fromSub($paymentDates, 'payment_dates')
            ->selectRaw('MAX(payment_dates.payment_date)');
    }

    private function loadCreditDeadlines(Collection $historicalByClient): array
    {
        $cxcIds = $historicalByClient->flatten(1)
            ->pluck('idcuentasPorCobrar')
            ->unique()
            ->values()
            ->all();

        if ($cxcIds === []) {
            return [];
        }

        return DB::table('detallecxc as dc')
            ->join('formapago as fp', 'fp.idformaPago', '=', 'dc.formaPago_idformaPago')
            ->whereIn('dc.cuentasPorCobrar_idcuentasPorCobrar', $cxcIds)
            ->whereNull('dc.fechaPago')
            ->where('dc.estado', '0')
            ->where('fp.tiempo', '>', 0)
            ->whereNotNull('dc.fechaPagoProgramada')
            ->selectRaw('dc.cuentasPorCobrar_idcuentasPorCobrar as cxc_id, MIN(dc.fechaPagoProgramada) as fecha_vencimiento')
            ->groupBy('dc.cuentasPorCobrar_idcuentasPorCobrar')
            ->get()
            ->mapWithKeys(fn($row): array => [
                (int) $row->cxc_id => Carbon::parse($row->fecha_vencimiento)->startOfDay(),
            ])
            ->all();
    }

    private function creditInstallmentDisplay(string $state, int $totalInstallments, ?int $paidInstallments): string
    {
        $normalized = trim($state);
        if (!str_contains(mb_strtolower($normalized, 'UTF-8'), 'credito')) {
            return $normalized;
        }

        $totalInstallments = max(1, $totalInstallments);
        $currentInstallment = $paidInstallments === null ? 1 : min($totalInstallments, max(1, (int) $paidInstallments + 1));

        return $totalInstallments > 1
            ? 'Pendiente a credito: ' . $currentInstallment . '/' . $totalInstallments
            : 'Pendiente a credito';
    }

    private function effectivePaymentState(
        string $state,
        ?string $periodEnd,
        ?Carbon $creditDeadline,
        ?string $pendingWithholdingDescription = null
    ): string {
        $normalized = strtoupper(trim($state));
        $normalizedLabel = mb_strtolower(trim($state), 'UTF-8');
        if ($normalizedLabel === 'pendiente a detracción') {
            return 'PENDIENTE_A_DETRACCION';
        }
        if ($normalizedLabel === 'pendiente autodetracción') {
            return 'PENDIENTE_AUTODETRACCION';
        }
        if ($normalizedLabel === 'pendiente a retención') {
            return 'PENDIENTE_A_RETENCION';
        }
        if ($normalizedLabel === 'pendiente autoretención') {
            return 'PENDIENTE_A_RETENCION';
        }
        if ($normalizedLabel === 'cancelado detracción') {
            return str_starts_with(mb_strtolower((string) $pendingWithholdingDescription, 'UTF-8'), 'retención pendiente:')
                ? 'PENDIENTE_A_RETENCION'
                : 'PENDIENTE_A_DETRACCION';
        }
        if (in_array($normalized, ['3', 'CANCELADO'], true))
            return 'CANCELADO';
        if (in_array($normalized, ['2', 'FACTURADO'], true))
            return 'FACTURADO';
        $isUnpaid = in_array($normalized, ['1', '4', 'NUEVO', 'PAGOCREDITO', 'PENDIENTE', 'VENCIDO', 'PENDIENTE PAGO PARCIAL', 'PENDIENTE A CREDITO'], true);
        if (!$isUnpaid)
            return $normalized;

        $period = $periodEnd ? Carbon::parse($periodEnd)->startOfDay() : null;
        if ($period && $period->lt(Carbon::today()))
            return 'VENCIDO';
        if ($normalized === 'PENDIENTE PAGO PARCIAL')
            return 'PENDIENTE_PAGO_PARCIAL';
        if ($normalized === 'PENDIENTE A CREDITO')
            return 'PENDIENTE_A_CREDITO';
        if ($normalized === 'PAGOCREDITO')
            return 'PAGO_CREDITO';
        if ($normalized === 'NUEVO')
            return 'NUEVO';
        if ($creditDeadline && $creditDeadline->lt(Carbon::today()))
            return 'VENCIDO';
        return 'PENDIENTE';
    }

    private function paymentState(string $state, bool $unpaid): string
    {
        return match ($state) {
            '1', 'PENDIENTE' => 'Pendiente', '2', 'FACTURADO' => 'Facturado',
            '3', 'CANCELADO' => 'Cancelado',
            'PENDIENTE_A_DETRACCION' => 'Pendiente a Detracción',
            'PENDIENTE_AUTODETRACCION' => 'Pendiente Autodetracción',
            'PENDIENTE_A_RETENCION' => 'Pendiente a Retención',
            'PENDIENTE_PAGO_PARCIAL' => 'Pendiente Pago parcial', 'PENDIENTE_A_CREDITO' => 'Pendiente a credito',
            'PAGO_CREDITO' => 'PagoCredito', 'NUEVO' => 'Nuevo',
            'APROBADO' => 'Aprobado',
            '4', 'VENCIDO' => 'Vencido', default => $unpaid ? 'Pendiente' : 'Facturado',
        };
    }

    private function currency(mixed $value, ?string $symbol = null): string
    {
        return $this->currencySymbol($symbol) . ' ' . number_format((float) $value, 2, '.', ',');
    }

    private function currencySymbol(?string $currency): string
    {
        $symbol = trim((string) $currency);
        $value = mb_strtolower($symbol, 'UTF-8');

        if (str_contains($value, 'dolar') || str_contains($value, 'dólar') || str_contains($value, '$')) {
            return '$';
        }

        if (str_contains($value, 'euro') || str_contains($value, '€')) {
            return '€';
        }

        if (
            $symbol === '' || in_array($value, ['s', 's/', 's/.', 'sol', 'soles', 'pen'], true)
            || str_contains($value, 'sol peru')
        ) {
            return 'S/';
        }

        return $symbol;
    }

    private function servicePeriod(mixed $value): string
    {
        return ServicePeriod::label($value);
    }

    private function tipoCobroIdFromPeriod(mixed $periodo): int
    {
        $months = ServicePeriod::months($periodo);
        if ($months === null) {
            return 0;
        }

        if (!array_key_exists($months, $this->tipoCobroIdCache)) {
            $this->tipoCobroIdCache[$months] = (int) (
                DB::table('tipocobro')
                    ->where('recurrencia', 'M')
                    ->where('tiempo', $months)
                    ->value('idtipoCobros') ?? 0
            );
        }

        return $this->tipoCobroIdCache[$months];
    }

    private function isTruthy(mixed $value): bool
    {
        return in_array(strtolower(str_replace('í', 'i', trim((string) $value))), ['si', '1', 'true', 'on', 'yes', 'y'], true);
    }
}
