<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CuentasPorCobrarService
{
    private array $tipoCobroIdCache = [];

    public function prepareServicesIndex(): array
    {
        $services = $this->loadServices();
        $maps = $this->loadDeviceMaps($services);
        $historicalByClient = $this->loadHistoricalCharges($services);
        $creditDeadlines = $this->loadCreditDeadlines($historicalByClient);
        $tipoCobroDetails = DB::table('tipocobro')
            ->get(['idtipoCobros', 'recurrencia', 'tiempo'])
            ->keyBy('idtipoCobros');

        $services = $this->decorateServices($services, $maps);
        $services = $this->appendHistoricalPeriods($services, $historicalByClient);
        $grouped = $this->groupServices($services, $historicalByClient, $creditDeadlines, $tipoCobroDetails);
        $this->sortGroups($grouped);

        return [
            'serviciosPorVencer' => $services,
            'serviciosPorVencerGrouped' => $grouped,
        ];
    }

    private function loadServices(): Collection
    {
        return DB::table('cuentasporcobrar as c')
            ->leftJoin('serviciocliente as sc', function ($join) {
                $join->on('sc.cliente_idcliente', '=', 'c.cliente_idcliente')
                    ->where(function ($serviceMatch) {
                        $serviceMatch->whereRaw(
                            "c.descripcion REGEXP CONCAT('(^|[^A-Za-z0-9])SC-', sc.idservicioCliente, '([^0-9]|$)')"
                        )->orWhere(function ($invoiceMatch) {
                            $invoiceMatch->whereNotNull('sc.docReferencia')
                                ->where('sc.docReferencia', '<>', '')
                                ->whereColumn('c.docReferencia', 'sc.docReferencia');
                        }                        )->orWhere(function ($legacyMatch) {
                            $legacyMatch->whereColumn('c.montoOriginal', 'sc.monto')
                                ->whereColumn('c.fechaCancelacion', 'sc.fecheVencimiento');
                        });
                    });
            })
            ->leftJoinSub($this->paymentDatesQuery(), 'pago', function ($join) {
                $join->on('pago.cxc_id', '=', 'c.idcuentasPorCobrar');
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
                'c.idcuentasPorCobrar', 'c.estado as cxc_estado', 'c.docReferencia as cxc_docReferencia',
                'c.montoOriginal as cxc_montoOriginal', 'c.montoActual as cxc_montoActual',
                'c.tipoCobro_idtipoCobros as cxc_tipoCobro_idtipoCobros',
                'c.descripcion as cxc_descripcion', 'c.canCuotas as cxc_canCuotas',
                    'pago.fecha_pago as cxc_fecha_pago',
                'c.moneda_idmoneda as moneda_idmoneda', 'c.fechaRegistro as cxc_fechaRegistro',
                'c.fechaCancelacion as cxc_fechaCancelacion', 'sc.idservicioCliente',
                'sc.cliente_idcliente', 'sc.almacen_idalmacen', 'sc.moneda_idmoneda as servicio_moneda_id',
                'sc.docReferencia', DB::raw('COALESCE(cli.razonSocial, cli.nombreComercial, cli.idcliente) as cliente_nombre'),
                'cli.flag_integrador as flag_integrador', 'sc.vehiculo_placa', 'sc.fechaInicio',
                'sc.fecheVencimiento', 'sc.monto', 'sc.estado', DB::raw('COALESCE(a.detalle, "") as servicio_detalle'),
                'a.periodo as servicio_periodo', DB::raw('COALESCE(v.marca, "") as vehiculo_marca'),
                DB::raw('COALESCE(v.modelo, "") as vehiculo_modelo'), DB::raw('COALESCE(v.tracto, "") as vehiculo_tracto'),
                DB::raw('COALESCE(tv.nombre, "") as tipo_vehiculo'), DB::raw('COALESCE(p.nombrePlataforma, "") as plataforma'),
                DB::raw('COALESCE(m.simbolo, "") as moneda_simbolo'),
            ])
            ->whereRaw('LOWER(COALESCE(sc.estado, "")) = ?', ['activo'])
            ->whereIn('c.estado', ['1', '2', '4', 'PENDIENTE', 'FACTURADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito'])
            ->whereRaw('COALESCE(c.montoActual, c.montoOriginal) >= 0')
            ->whereNotNull('sc.fecheVencimiento')
            ->orderBy('sc.fecheVencimiento')
            ->orderByDesc('c.idcuentasPorCobrar')
            ->get();
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

        $latestDetails = DB::table('detalle_serviciodispositivo')
            ->whereIn('servicioCliente_idservicioCliente', $serviceIds)
            ->orderByDesc('iddetalle_serviciodispositivo')
            ->get([
                'servicioCliente_idservicioCliente',
                'dispositivoCliente_iddispositivoCliente',
            ])
            ->unique('servicioCliente_idservicioCliente');
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
            $numbersByDevice = [];
            foreach (DB::table('detnumerosdispositivo as n')->whereIn('n.dispositivoCliente_iddispositivoCliente', $ids)
                ->orderByDesc('n.iddetNumerosDispositivo')
                ->select('n.dispositivoCliente_iddispositivoCliente', 'n.numeroTelefonico_numeroTelefonico')->get() as $number) {
                $numbersByDevice[$number->dispositivoCliente_iddispositivoCliente] ??= $number->numeroTelefonico_numeroTelefonico;
            }
            foreach ($deviceIds as $serviceId => $deviceId) {
                $numbers[$serviceId] = $numbersByDevice[$deviceId] ?? '-';
            }
        }

        return [$devices, $deviceIds, $numbers];
    }

    private function loadHistoricalCharges(Collection $services): Collection
    {
        $clientIds = $services->pluck('cliente_idcliente')->filter()->unique()->values()->all();
        if ($clientIds === []) {
            return collect();
        }

        return DB::table('cuentasporcobrar as c')
            ->leftJoinSub($this->paymentDatesQuery(), 'pago', function ($join) {
                $join->on('pago.cxc_id', '=', 'c.idcuentasPorCobrar');
            })
                ->whereIn('c.cliente_idcliente', $clientIds)
                ->whereIn('c.estado', ['1', '2', '3', '4', 'PENDIENTE', 'FACTURADO', 'CANCELADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito', 'Cancelado Detracción'])
                ->orderByDesc('c.idcuentasPorCobrar')
                ->get(['c.idcuentasPorCobrar', 'c.cliente_idcliente', 'c.montoOriginal', 'c.montoActual', 'c.estado', 'c.docReferencia', 'c.fechaRegistro', 'c.fechaCancelacion', 'c.descripcion', 'c.moneda_idmoneda', 'pago.fecha_pago'])
            ->groupBy('cliente_idcliente');
    }

    private function decorateServices(Collection $services, array $maps): Collection
    {
        [$devices, $deviceIds, $numbers] = $maps;
        $cxcIds = $services->pluck('idcuentasPorCobrar')->filter()->unique()->values();
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

        return $services->transform(function ($row) use ($devices, $deviceIds, $numbers, $paidInstallmentsByCxc) {
            $cancelled = in_array((string) ($row->cxc_estado ?? ''), ['3', 'CANCELADO', 'Cancelado Detracción'], true);
            if (!empty($row->cxc_fechaCancelacion)) {
                $row->fecheVencimiento = Carbon::parse($row->cxc_fechaCancelacion)->toDateString();
            }
            $row->fecha_inicio_display = $row->fechaInicio ? Carbon::parse($row->fechaInicio)->format('d/m/Y') : '-';
            $row->fecha_vencimiento_display = $row->fecheVencimiento ? Carbon::parse($row->fecheVencimiento)->format('d/m/Y') : '-';
            $amount = $cancelled ? ($row->cxc_montoOriginal ?: $row->monto) : ($row->cxc_montoActual ?? $row->cxc_montoOriginal ?? $row->monto);
            $row->monto_display = $amount !== null ? $this->currency($amount, $row->moneda_simbolo ?? null) : '-';
            $row->estado_display = $this->creditInstallmentDisplay(
                (string) ($row->cxc_estado ?? ''),
                (int) ($row->cxc_canCuotas ?? 0),
                (int) ($paidInstallmentsByCxc[$row->idcuentasPorCobrar] ?? 0)
            );
            $period = $this->servicePeriod($row->servicio_periodo ?? null);
            $row->servicio_periodo_dias = $this->servicePeriodDays($row->servicio_periodo ?? null);
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
        $loadedCxcIds = $services->pluck('idcuentasPorCobrar')->filter()->map(fn($id) => (int) $id)->all();

        foreach ($historicalByClient->flatten(1) as $historical) {
            $cxcId = (int) ($historical->idcuentasPorCobrar ?? 0);
            $clientId = (string) ($historical->cliente_idcliente ?? '');
            $representative = $representatives[$clientId] ?? null;
            if ($cxcId <= 0 || in_array($cxcId, $loadedCxcIds, true) || !$representative) {
                continue;
            }

            $periodEnd = !empty($historical->fechaCancelacion)
                ? Carbon::parse($historical->fechaCancelacion)->toDateString()
                : null;
            if (!$periodEnd) {
                continue;
            }

            $candidateServices = $servicesByClient[$clientId] ?? collect();
            $amountMatches = $candidateServices
                ->filter(fn($service): bool =>
                    abs((float) ($service->monto ?? 0) - (float) ($historical->montoOriginal ?? 0)) < 0.005
                );
            $matchedServices = $amountMatches->count() === 1
                ? $amountMatches
                : ($candidateServices->count() === 1 ? $candidateServices : collect());

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
                $unlinkedService = clone $representative;
                $unlinkedService->idservicioCliente = 0;
                $unlinkedService->vehiculo_placa = null;
                $unlinkedService->servicio_detalle = 'Cargo histórico sin servicio asociado';
                $unlinkedService->servicio_periodo = null;
                $unlinkedService->plataforma = '-';
                $unlinkedService->tipo_vehiculo = '';
                $unlinkedService->vehiculo_marca = '';
                $unlinkedService->vehiculo_modelo = '';
                $unlinkedService->vehiculo_tracto = '';
                $unlinkedService->id_dispositivo = '-';
                $unlinkedService->numero_sim = '-';
                $unlinkedService->monto = $historical->montoOriginal;
                $matchedServices = collect([$unlinkedService]);
            }

            foreach ($matchedServices as $service) {
                $periodService = clone $service;
                $periodService->idcuentasPorCobrar = $cxcId;
                $periodService->cxc_estado = $historical->estado;
                $periodService->cxc_docReferencia = $historical->docReferencia;
                $periodService->cxc_montoOriginal = $historical->montoOriginal;
                $periodService->cxc_montoActual = $historical->montoActual;
                $periodService->cxc_fechaRegistro = $historical->fechaRegistro;
                $periodService->cxc_fechaCancelacion = $historical->fechaCancelacion;
                $periodService->cxc_descripcion = $historical->descripcion;
                $periodService->cxc_fecha_pago = $historical->fecha_pago ?? null;
                $periodService->moneda_idmoneda = $historical->moneda_idmoneda ?? $service->moneda_idmoneda;
                $periodService->fechaInicio = $periodStart;
                $periodService->fecheVencimiento = $periodEnd;
                $periodService->docReferencia = $historical->docReferencia;
                $periodService->monto = $historical->montoOriginal;
                $periodService->fecha_inicio_display = $periodStart ? Carbon::parse($periodStart)->format('d/m/Y') : '-';
                $periodService->fecha_vencimiento_display = $periodEnd ? Carbon::parse($periodEnd)->format('d/m/Y') : '-';
                $periodService->dias_restantes = $periodEnd
                    ? (int) Carbon::today()->diffInDays(Carbon::parse($periodEnd)->startOfDay(), false)
                    : 0;
                $historicalAmount = in_array((string) $historical->estado, ['3', 'CANCELADO'], true)
                    ? $historical->montoOriginal
                    : $historical->montoActual;
                $periodService->monto_display = $this->currency($historicalAmount, $periodService->moneda_simbolo ?? null);
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
        if (!$periodStart) {
            return null;
        }

        $days = is_numeric($periodValue) ? max((int) $periodValue, 1) : 30;
        return Carbon::parse($periodStart)->addDays($days)->format('Y-m-d');
    }

    private function groupServices(
        Collection $services,
        Collection $historicalByClient,
        array $creditDeadlines,
        Collection $tipoCobroDetails
    ): array
    {
        $groups = [];
        $byPeriod = $services->groupBy(fn($service) => implode('|', [
            (string) $service->cliente_idcliente,
            Carbon::parse($service->fecheVencimiento)->format('Y-m'),
            (string) ($service->servicio_moneda_id ?? $service->moneda_idmoneda ?? ''),
            trim((string) ($service->servicio_periodo ?? '')),
        ]));

        foreach ($byPeriod as $clientServices) {
            $first = $clientServices->first();
            $activeStates = ['1', '2', '4', 'PENDIENTE', 'FACTURADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito'];
            $unique = $clientServices->unique(fn($service): string =>
                (int) ($service->idservicioCliente ?? 0) > 0
                    ? 'service-' . $service->idservicioCliente
                    : 'cxc-' . ($service->idcuentasPorCobrar ?? spl_object_id($service))
            );
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

            $cancelledCxc = $clientServices->first(fn($service) => in_array((string) ($service->cxc_estado ?? ''), ['3', 'CANCELADO', 'Cancelado Detracción'], true));
            $groupCharges = $clientServices
                ->filter(fn($service): bool => (int) ($service->idcuentasPorCobrar ?? 0) > 0
                    && in_array((string) ($service->cxc_estado ?? ''), $activeStates, true))
                ->unique('idcuentasPorCobrar')
                ->sortByDesc('idcuentasPorCobrar')
                ->values();
            $cxc = $groupCharges->sortByDesc('idcuentasPorCobrar')->first()
                ?: ($matchingCxc
                    ?: ($cancelledCxc ?: ($clientServices->firstWhere('cxc_estado', '!=', null) ?: $first)));

            $groupNeedsInvoice = $groupCharges->contains(fn($charge): bool =>
                in_array((string) ($charge->cxc_estado ?? ''), ['1', '4', 'PENDIENTE', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito'], true)
                || trim((string) ($charge->cxc_docReferencia ?? '')) === ''
            );
            $hasUnpaid = $groupNeedsInvoice || trim((string) ($cxc->cxc_docReferencia ?? '')) === '';
            $state = $groupNeedsInvoice
                ? '1'
                : (string) ($cxc->cxc_estado ?? $cxc->estado ?? $first->cxc_estado);
            $creditDeadline = $creditDeadlines[(int) ($cxc->idcuentasPorCobrar ?? 0)] ?? null;
            $effectiveState = $this->effectivePaymentState($state, $periodEnd->format('Y-m-d'), $creditDeadline);
            $original = round((float) ($cxc->cxc_montoOriginal ?? $cxc->montoOriginal ?? $servicesAmount), 2);
            $groupBalance = $groupCharges->sum(fn($charge): float =>
                (float) ($charge->cxc_montoActual ?? $charge->cxc_montoOriginal ?? $charge->montoActual ?? $charge->montoOriginal ?? 0)
            );
            $balance = $groupCharges->isNotEmpty()
                ? round((float) $groupBalance, 2)
                : (in_array($state, array_merge($activeStates, ['3', 'CANCELADO', 'Cancelado Detracción']), true)
                    ? round((float) ($cxc->cxc_montoActual ?? $cxc->montoActual ?? $original), 2)
                    : $original);
            $montoTotal = $servicesAmount;
            $currency = $this->currencySymbol($first->moneda_simbolo ?? null);
            $periodTipoCobroId = $this->tipoCobroIdFromPeriod($first->servicio_periodo ?? null);
            $deudaAnterior = $this->previousOutstandingDebt(
                $historicalByClient->get($first->cliente_idcliente) ?? collect(),
                $cxc
            );
            $paymentDate = $cxc->cxc_fecha_pago
                ?? ($historicalByClient->get($first->cliente_idcliente) ?? collect())
                    ->firstWhere('idcuentasPorCobrar', (int) ($cxc->idcuentasPorCobrar ?? 0))?->fecha_pago;
            $revertUntil = $paymentDate ? Carbon::parse($paymentDate)->addDays(7)->startOfDay() : null;
            $currentCxcId = (int) ($cxc->idcuentasPorCobrar ?? 0);
            $successorPrefix = 'REN-CXC-' . $currentCxcId . ' ';
            $successorCxc = ($historicalByClient->get($first->cliente_idcliente) ?? collect())
                ->first(fn($charge) => str_starts_with((string) ($charge->descripcion ?? ''), $successorPrefix));
            $successorPeriod = $successorCxc
                ? $this->periodFromDescription((string) $successorCxc->descripcion)
                : [];
            $nextPeriodEnd = $successorPeriod[1] ?? ($successorCxc->fechaCancelacion ?? null);
            if (!$nextPeriodEnd) {
                $tipoCobro = $tipoCobroDetails->get($periodTipoCobroId);
                $nextStart = $periodEnd->copy();
                if (strtoupper(trim((string) ($tipoCobro->recurrencia ?? ''))) === 'D' && (int) ($tipoCobro->tiempo ?? 0) > 0) {
                    $nextPeriodEnd = $nextStart->addDays((int) $tipoCobro->tiempo)->format('Y-m-d');
                } else {
                    $months = strtoupper(trim((string) ($tipoCobro->recurrencia ?? ''))) === 'M'
                        ? max(1, (int) ($tipoCobro->tiempo ?? 1))
                        : 1;
                    $nextPeriodEnd = $nextStart->addMonthsNoOverflow($months)->format('Y-m-d');
                }
            }

            $groups[] = [
                'cliente_id' => $first->cliente_idcliente,
                'cxc_id' => (int) ($cxc->idcuentasPorCobrar ?? 0),
                'tipo_cobro_id' => $periodTipoCobroId ?: (int) ($cxc->cxc_tipoCobro_idtipoCobros ?? $first->cxc_tipoCobro_idtipoCobros ?? 0),
                'cliente_nombre' => $first->cliente_nombre ?? '-',
                'is_integrador' => (bool) ($first->is_integrador ?? false),
                'num_unidades' => $unique->pluck('vehiculo_placa')->filter()->unique()->count(),
                'num_tservicios' => $types->count(),
                'fecha_inicio' => $periodStart->format('d/m/Y'),
                'fecha_registro' => $paymentDate ? Carbon::parse($paymentDate)->format('d/m/Y') : '-',
                'fecha_fin' => $periodEnd->format('d/m/Y'),
                'fecha_fin_iso' => $periodEnd->format('Y-m-d'),
                'fecha_fin_siguiente_iso' => Carbon::parse($nextPeriodEnd)->format('Y-m-d'),
                'documento' => trim((string) ($cxc->cxc_docReferencia ?? '')) ?: '-',
                'cxc_estado' => $state,
                'cxc_doc' => trim((string) ($cxc->cxc_docReferencia ?? '')),
                'adelanto_meses' => CxcService::advanceMonthsFromDescription((string) ($cxc->descripcion ?? '')),
                'cxc_fecha_cancelacion' => $cxc->cxc_fechaCancelacion ?? null,
                'puede_revertir' => in_array((string) ($cxc->cxc_estado ?? $cxc->estado ?? ''), ['3', 'CANCELADO', 'Cancelado Detracción'], true)
                    && $revertUntil !== null
                    && Carbon::today()->lte($revertUntil),
                'mes' => $periodEnd->locale('es')->monthName,
                'anio' => $periodEnd->year,
                'estado_pago' => $this->paymentState($effectiveState, $hasUnpaid),
                'estado_servicio' => $active->isNotEmpty() ? 'activo' : 'vencido',
                'dias_restantes' => $closest->dias_restantes ?? 0,
                'monto_total' => $montoTotal,
                'monto_total_display' => $this->currency($montoTotal, $currency),
                'monto_saldo' => $balance,
                'monto_saldo_display' => $this->currency($balance, $currency),
                'monto_servicios' => $servicesAmount,
                'monto_servicios_display' => $this->currency($servicesAmount, $currency),
                'cxc_ids' => $groupCharges->pluck('idcuentasPorCobrar')->map(fn($id): int => (int) $id)->all(),
                'deuda_anterior' => $deudaAnterior,
                'deuda_anterior_display' => $this->currency($deudaAnterior, $currency),
                'moneda_idmoneda' => (int) ($first->moneda_idmoneda ?? 1),
                'moneda_simbolo' => $currency,
                'all_service_ids' => $unique->pluck('idservicioCliente')->filter(fn($id): bool => (int) $id > 0)->all(),
                'tipos_servicios' => $this->serviceTypes($types, $currency, $deudaAnterior, $activeStates),
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
        $eligibleCharges = $charges->filter(fn($charge): bool =>
            in_array((string) ($charge->estado ?? ''), $activeStates, true)
        );

        if ($normalizedPeriodRange !== '') {
            $periodMatch = $eligibleCharges->first(fn($charge): bool =>
                str_contains(mb_strtolower((string) ($charge->descripcion ?? ''), 'UTF-8'), $normalizedPeriodRange)
            );
            if ($periodMatch) {
                $matchedCxc = clone $periodMatch;
                $matchedCxc->cxc_docReferencia = $periodMatch->docReferencia ?? null;
                return $matchedCxc;
            }
        }

        if ($periodEnd !== null) {
            $dateMatch = $eligibleCharges->first(fn($charge): bool =>
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

    private function serviceTypes(Collection $types, string $currency, float $previousDebt, array $activeStates): array
    {
        $result = [];
        foreach ($types as $name => $services) {
            $uniqueServices = $services->unique(fn($service): string =>
                (int) ($service->idservicioCliente ?? 0) > 0
                    ? 'service-' . $service->idservicioCliente
                    : 'cxc-' . ($service->idcuentasPorCobrar ?? spl_object_id($service))
            )->values();
            $vehicles = $uniqueServices->map(fn($service) => [
                'idservicioCliente' => $service->idservicioCliente, 'placa' => $service->vehiculo_placa ?? '-',
                'tipo_cobro_id' => $this->tipoCobroIdFromPeriod($service->servicio_periodo ?? null) ?: (int) ($service->cxc_tipoCobro_idtipoCobros ?? 0),
                'id_dispositivo' => $service->id_dispositivo ?? '-', 'numero' => $service->numero_sim ?? '-',
                'tipo' => $service->tipo_vehiculo ?: '-', 'marca' => $service->vehiculo_marca ?: '-',
                'modelo' => $service->vehiculo_modelo ?: '-', 'tracto' => $service->vehiculo_tracto ?: '-',
                'monto' => (float) ($service->monto ?? 0), 'monto_display' => $this->currency($service->monto ?? 0, $currency),
                'monto_saldo' => (float) ($service->cxc_montoActual ?? $service->cxc_montoOriginal ?? $service->monto ?? 0),
                'cxc_ids' => (int) ($service->idcuentasPorCobrar ?? 0) > 0 ? [(int) $service->idcuentasPorCobrar] : [],
                'cxc_estado' => $service->cxc_estado ?? $service->estado ?? '1',
                'doc_referencia' => trim((string) ($service->cxc_docReferencia ?? '')) ?: '-', 'fecha_inicio_display' => $service->fecha_inicio_display ?? '-',
                'fecha_vencimiento_display' => $service->fecha_vencimiento_display ?? '-',
                'mes' => Carbon::parse($service->fecheVencimiento)->locale('es')->monthName,
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
            $typeBalance = round((float) $typeCharges->sum(fn($charge): float =>
                (float) ($charge->cxc_montoActual ?? $charge->cxc_montoOriginal ?? 0)
            ), 2);
            $typeCxc = $typeCharges->first() ?? $uniqueServices->first();
            $typeNeedsInvoice = $typeCharges->contains(fn($charge): bool =>
                in_array((string) ($charge->cxc_estado ?? ''), ['1', '4', 'PENDIENTE', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito'], true)
                || trim((string) ($charge->cxc_docReferencia ?? '')) === ''
            );
            $result[] = [
                'servicio_nombre' => $name,
                'plataforma' => $services->first()->plataforma ?? '-',
                'tipo_cobro_id' => $this->tipoCobroIdFromPeriod($services->first()->servicio_periodo ?? null) ?: (int) ($services->first()->cxc_tipoCobro_idtipoCobros ?? 0),
                'monto_subtotal' => $subtotal,
                'monto_subtotal_display' => $this->currency($subtotal, $currency),
                'monto_saldo' => $typeCharges->isNotEmpty() ? $typeBalance : $subtotal,
                'monto_saldo_display' => $this->currency($typeCharges->isNotEmpty() ? $typeBalance : $subtotal, $currency),
                'cxc_ids' => $typeCxcIds,
                'cxc_id' => (int) ($typeCxc->idcuentasPorCobrar ?? 0),
                'cxc_estado' => $typeNeedsInvoice ? '1' : ($typeCxc->cxc_estado ?? $typeCxc->estado ?? '1'),
                'cxc_doc' => trim((string) ($typeCxc->cxc_docReferencia ?? '')),
                'deuda' => $previousDebt > 0 && $result === [] ? $this->currency($previousDebt, $currency) : null,
                'service_ids' => $uniqueServices->pluck('idservicioCliente')->filter(fn($id): bool => (int) $id > 0)->all(),
                'vehiculos' => $vehicles,
            ];
        }
        return $result;
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

        $unpaidStates = ['1', '2', '4', 'PENDIENTE', 'FACTURADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito'];
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
            $rank = ['facturado' => 0, 'pendiente' => 1, 'vencido' => 2, 'cancelado' => 3];
            $rankA = $rank[strtolower((string) ($a['estado_pago'] ?? ''))] ?? 9;
            $rankB = $rank[strtolower((string) ($b['estado_pago'] ?? ''))] ?? 9;
            if ($rankA !== $rankB) return $rankA <=> $rankB;
            return strcmp(strtolower((string) $a['cliente_nombre']), strtolower((string) $b['cliente_nombre']));
        });
    }

    private function paymentDatesQuery()
    {
        return DB::table('detallecxc')
            ->where('estado', '1')
            ->whereNotNull('fechaPago')
            ->selectRaw('cuentasPorCobrar_idcuentasPorCobrar as cxc_id, MAX(fechaPago) as fecha_pago')
            ->groupBy('cuentasPorCobrar_idcuentasPorCobrar');
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

    private function effectivePaymentState(string $state, ?string $periodEnd, ?Carbon $creditDeadline): string
    {
        $normalized = strtoupper(trim($state));
        if (mb_strtolower(trim($state), 'UTF-8') === 'cancelado detracción') return 'CANCELADO_DETRACCION';
        if (in_array($normalized, ['3', 'CANCELADO'], true)) return 'CANCELADO';
        if (in_array($normalized, ['2', 'FACTURADO'], true)) return 'FACTURADO';
        $isUnpaid = in_array($normalized, ['1', '4', 'PENDIENTE', 'VENCIDO', 'PENDIENTE PAGO PARCIAL', 'PENDIENTE A CREDITO'], true);
        if (!$isUnpaid) return $normalized;

        $period = $periodEnd ? Carbon::parse($periodEnd)->startOfDay() : null;
        if ($period && $period->lt(Carbon::today())) return 'VENCIDO';
        if ($normalized === 'PENDIENTE PAGO PARCIAL') return 'PENDIENTE_PAGO_PARCIAL';
        if ($normalized === 'PENDIENTE A CREDITO') return 'PENDIENTE_A_CREDITO';
        if ($creditDeadline && $creditDeadline->lt(Carbon::today())) return 'VENCIDO';
        return 'PENDIENTE';
    }

    private function paymentState(string $state, bool $unpaid): string
    {
        return match ($state) {
            '1', 'PENDIENTE' => 'Pendiente', '2', 'FACTURADO' => 'Facturado',
            '3', 'CANCELADO' => 'Cancelado', 'CANCELADO_DETRACCION' => 'Cancelado Detracción',
            'PENDIENTE_PAGO_PARCIAL' => 'Pendiente Pago parcial', 'PENDIENTE_A_CREDITO' => 'Pendiente a credito',
            '4', 'VENCIDO' => 'Vencido', default => $unpaid ? 'Pendiente' : 'Facturado',
        };
    }

    private function currency(mixed $value, ?string $symbol = null): string
    {
        return $this->currencySymbol($symbol) . ' ' . number_format((float) $value, 2, '.', ',');
    }

    private function currencySymbol(?string $currency): string
    {
        $value = mb_strtolower(trim((string) $currency), 'UTF-8');
        return str_contains($value, 'dolar') || str_contains($value, 'dólar') || str_contains($value, '$') ? '$' : (str_contains($value, 'euro') || str_contains($value, '€') ? '€' : 'S/');
    }

    private function servicePeriod(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '' || trim((string) $value) === 'No') return '';
        if (!is_numeric($value)) return trim((string) $value);
        return match ((int) $value) { 30 => 'Mensual', 90 => '3 Meses', 180 => '6 Meses', 365 => '12 Meses', 730 => '24 Meses', 1095 => '36 Meses', 1460 => '48 Meses', default => trim((string) $value) };
    }

    private function servicePeriodDays(mixed $value): ?int
    {
        return $value === null || trim((string) $value) === '' || trim((string) $value) === 'No' ? null : (is_numeric($value) ? (int) $value : null);
    }

    private function tipoCobroIdFromPeriod(mixed $periodo): int
    {
        $months = match (mb_strtolower(trim((string) $periodo), 'UTF-8')) {
            'mensual', '1 mes', '1 meses' => 1,
            '3 meses' => 3,
            '6 meses' => 6,
            '12 meses' => 12,
            '24 meses' => 24,
            '36 meses' => 36,
            '48 meses' => 48,
            default => null,
        };

        if ($months === null && is_numeric($periodo)) {
            $months = match ((int) $periodo) {
                30 => 1,
                90 => 3,
                180 => 6,
                365 => 12,
                730 => 24,
                1095 => 36,
                1460 => 48,
                default => null,
            };
        }

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
