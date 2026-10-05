<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BancosService
{
    public function getList(Request $request, int $perPage): LengthAwarePaginator
    {
        $query = $this->applyFilters($this->baseQuery(), $request);

        return $query
            ->orderByDesc('b.fechaRegistro')
            ->orderByDesc('b.idbancos')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function getStats(Request $request): array
    {
        $balanceQuery = $this->applyOperationalMovementFilter(
            $this->applyFilters($this->joinedQuery(), $request)
        );
        $rows = (clone $balanceQuery)
            ->selectRaw("COALESCE(NULLIF(TRIM(m.simbolo), ''), 'S/') as moneda_simbolo")
            ->selectRaw("SUM(CASE WHEN b.tipoMov = 'I' THEN b.monto ELSE 0 END) as balance_positivo")
            ->selectRaw("SUM(CASE WHEN b.tipoMov = 'S' THEN b.monto ELSE 0 END) as balance_negativo")
            ->groupBy('m.simbolo')
            ->get();
        $annulledRows = $this->applyFilters($this->joinedQuery(), $request)
            ->whereRaw('LTRIM(b.informacion) LIKE ?', ['[ANULADO]%'])
            ->selectRaw("COALESCE(NULLIF(TRIM(m.simbolo), ''), 'S/') as moneda_simbolo")
            ->selectRaw('SUM(b.monto) as anulado_revertido')
            ->groupBy('m.simbolo')
            ->get();

        $balances = [];
        foreach ($rows as $row) {
            $symbol = $this->normalizeCurrencySymbol($row->moneda_simbolo);
            $balances[$symbol] ??= ['balance_positivo' => 0.0, 'balance_negativo' => 0.0, 'anulado_revertido' => 0.0];
            $balances[$symbol]['balance_positivo'] += (float) $row->balance_positivo;
            $balances[$symbol]['balance_negativo'] += (float) $row->balance_negativo;
        }
        foreach ($annulledRows as $row) {
            $symbol = $this->normalizeCurrencySymbol($row->moneda_simbolo);
            $amount = (float) $row->anulado_revertido;
            $balances[$symbol] ??= ['balance_positivo' => 0.0, 'balance_negativo' => 0.0, 'anulado_revertido' => 0.0];
            $balances[$symbol]['balance_negativo'] += $amount;
            $balances[$symbol]['anulado_revertido'] += $amount;
        }

        return [
            'balances' => $balances,
        ];
    }

    public function getExportRows(Request $request): Collection
    {
        return $this->applyFilters($this->baseQuery(), $request)
            ->orderByDesc('b.fechaRegistro')
            ->orderByDesc('b.idbancos')
            ->get();
    }

    public function getEstadoCuentaList(Request $request, int $perPage): LengthAwarePaginator
    {
        $rows = $this->getEstadoCuentaClientRows($request);
        $page = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => $request->query()]
        );
    }

    public function getEstadoCuentaExportRows(Request $request): Collection
    {
        $rows = $this->getEstadoCuentaClientRows($request);
        $clientIds = $rows
            ->pluck('ruc_cliente')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $creditsByClient = $this->getMontosAFavorByClientCurrency($clientIds, $request->query('moneda'));

        return $rows->map(function (object $row) use ($creditsByClient): object {
            $currency = $row->moneda_simbolo;
            $row->moneda = $currency;
            $row->monto_deuda = (float) ($row->currency_totals[$currency]['monto_deuda'] ?? 0);
            $row->monto_favor = (float) ($creditsByClient[$row->ruc_cliente][$currency] ?? 0);

            return $row;
        });
    }

    public function getEstadoCuentaStats(Request $request): array
    {
        $rows = $this->getEstadoCuentaClientRows($request);
        $clientIds = $rows->pluck('ruc_cliente')->filter()->unique()->values()->all();
        $credits = $this->getMontosAFavorByClientCurrency($clientIds, $request->query('moneda'));
        $debtTotals = [];
        $creditTotals = [];

        foreach ($rows as $row) {
            $symbol = $row->moneda_simbolo;
            $debtTotals[$symbol] = ($debtTotals[$symbol] ?? 0)
                + (float) ($row->currency_totals[$symbol]['monto_deuda'] ?? 0);
            $creditTotals[$symbol] = ($creditTotals[$symbol] ?? 0)
                + (float) ($credits[$row->ruc_cliente][$symbol] ?? 0);
        }

        return ['monto_deuda' => $debtTotals, 'monto_favor' => $creditTotals];
    }

    public function getEstadoCuentaDetailsByClient(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $charges = DB::table('cuentasporcobrar as c')
            ->leftJoin('moneda as m', 'm.idmoneda', '=', 'c.moneda_idmoneda')
            ->whereIn('c.cliente_idcliente', $clientIds)
            ->whereIn('c.estado', ['1', '2', '4', 'PENDIENTE', 'FACTURADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito'])
            ->where('c.montoActual', '>', 0)
            ->orderBy('c.cliente_idcliente')
            ->orderByDesc('c.idcuentasPorCobrar')
            ->get([
                'c.idcuentasPorCobrar as cxc_id',
                'c.cliente_idcliente as ruc_cliente',
                'c.descripcion',
                'c.docReferencia',
                'c.montoOriginal',
                'c.montoActual as deuda_pendiente',
                'c.estado',
                DB::raw('COALESCE(NULLIF(TRIM(m.simbolo), ""), "S/") as moneda_simbolo'),
            ]);

        $servicesByClient = DB::table('serviciocliente as sc')
            ->leftJoin('vehiculo as v', 'v.placa', '=', 'sc.vehiculo_placa')
            ->whereIn('sc.cliente_idcliente', $clientIds)
            ->get([
                'sc.idservicioCliente',
                'sc.cliente_idcliente',
                'sc.docReferencia',
                'sc.fechaInicio',
                'sc.fecheVencimiento',
                'sc.monto',
                'sc.vehiculo_placa',
                DB::raw('COALESCE(v.placa, sc.vehiculo_placa, "") as vehiculo'),
            ])
            ->groupBy('cliente_idcliente');

        $result = [];
        foreach ($charges as $charge) {
            $clientServices = $servicesByClient->get($charge->ruc_cliente, collect());
            $description = (string) ($charge->descripcion ?? '');
            $matchedServices = collect();
            $hasServiceMarker = preg_match('/SC-([0-9,]+)/', $description, $matches) === 1;

            if ($hasServiceMarker) {
                $serviceIds = array_values(array_filter(array_map('intval', explode(',', $matches[1]))));
                $matchedServices = $clientServices->whereIn('idservicioCliente', $serviceIds)->unique('idservicioCliente')->values();
            }

            if ($matchedServices->isEmpty()
                && preg_match('/(\d{2}\/\d{2}\/\d{4})\s+a\s+(\d{2}\/\d{2}\/\d{4})/', $description, $period) === 1) {
                $periodServices = $clientServices->filter(function (object $service) use ($period): bool {
                    return !empty($service->fechaInicio)
                        && !empty($service->fecheVencimiento)
                        && date('d/m/Y', strtotime((string) $service->fechaInicio)) === $period[1]
                        && date('d/m/Y', strtotime((string) $service->fecheVencimiento)) === $period[2];
                })->unique('idservicioCliente')->values();
                if ($periodServices->isNotEmpty()) {
                    $matchedServices = $periodServices;
                }
            }

            if (!$hasServiceMarker && $matchedServices->isEmpty()
                && preg_match('/Cobro por servicio\s+(.+)$/i', $description, $plateMatch) === 1) {
                $plate = trim($plateMatch[1]);
                $plateServices = $clientServices
                    ->filter(fn (object $service): bool => strcasecmp(trim((string) $service->vehiculo_placa), $plate) === 0)
                    ->unique('idservicioCliente')
                    ->take(1)
                    ->values();
                if ($plateServices->isNotEmpty()) {
                    $matchedServices = $plateServices;
                }
            }

            if (!$hasServiceMarker && $matchedServices->isEmpty() && trim((string) ($charge->docReferencia ?? '')) !== '') {
                $documentServices = $clientServices
                    ->filter(fn (object $service): bool => trim((string) ($service->docReferencia ?? '')) === trim((string) $charge->docReferencia))
                    ->unique('idservicioCliente')
                    ->values();
                if ($documentServices->count() === 1) {
                    $documentService = $documentServices->first();
                    if (abs((float) ($documentService->monto ?? 0) - (float) ($charge->montoOriginal ?? 0)) < 0.005) {
                        $matchedServices = $documentServices;
                    }
                }
            }

            if ($matchedServices->isEmpty()) {
                $matchedServices = collect([(object) [
                    'idservicioCliente' => null,
                    'vehiculo' => 'Sin servicio vinculado',
                    'monto' => (float) ($charge->montoOriginal ?? 0),
                ]]);
            }

            $totalServiceAmount = (float) $matchedServices->sum(fn (object $service): float => (float) ($service->monto ?? 0));
            $remainingOriginal = round((float) ($charge->montoOriginal ?? 0), 2);
            $remainingDebt = round((float) ($charge->deuda_pendiente ?? 0), 2);
            $serviceCount = $matchedServices->count();

            foreach ($matchedServices as $index => $service) {
                $share = $totalServiceAmount > 0
                    ? (float) ($service->monto ?? 0) / $totalServiceAmount
                    : 1 / $serviceCount;
                $originalAmount = $index === $serviceCount - 1
                    ? $remainingOriginal
                    : round((float) $charge->montoOriginal * $share, 2);
                $debtAmount = $index === $serviceCount - 1
                    ? $remainingDebt
                    : round((float) $charge->deuda_pendiente * $share, 2);
                $remainingOriginal = round($remainingOriginal - $originalAmount, 2);
                $remainingDebt = round($remainingDebt - $debtAmount, 2);
                $currency = $this->normalizeCurrencySymbol($charge->moneda_simbolo);

                $result[(string) $charge->ruc_cliente][] = (object) [
                    'cxc_id' => (int) $charge->cxc_id,
                    'vehiculo' => trim((string) ($service->vehiculo ?? '')) ?: 'Sin vehículo asociado',
                    'descripcion' => trim($description) ?: (trim((string) ($charge->docReferencia ?? '')) ?: 'Cuenta por cobrar #' . $charge->cxc_id),
                    'monto' => $originalAmount,
                    'moneda_simbolo' => $currency,
                    'deuda_pendiente' => [$currency => $debtAmount],
                    'estado' => (string) $charge->estado,
                ];
            }
        }

        return $result;
    }

    public function getMontosAFavorByClientCurrency(array $clientIds, ?string $currency = null): array
    {
        if ($clientIds === []) {
            return [];
        }

        $credits = DB::table('bancos as b')
            ->join('detallecxc as dc', 'dc.bancos_idbancos', '=', 'b.idbancos')
            ->join('cuentasporcobrar as cxc', 'cxc.idcuentasPorCobrar', '=', 'dc.cuentasPorCobrar_idcuentasPorCobrar')
            ->leftJoin('moneda as m', 'm.idmoneda', '=', 'dc.moneda_idmoneda')
            ->where('b.tipoMov', 'A')
            ->where('b.informacion', 'not like', '[ANULADO]%')
            ->where('dc.estado', '!=', '0')
            ->whereIn('cxc.cliente_idcliente', $clientIds)
            ->when($currency !== null && trim($currency) !== '', fn ($query) => $this->applyCurrencySymbolFilter($query, $currency))
            ->select('cxc.cliente_idcliente', DB::raw('COALESCE(NULLIF(TRIM(m.simbolo), ""), "S/") as moneda_simbolo'))
            ->selectRaw('SUM(b.monto) as total_favor')
            ->groupBy('cxc.cliente_idcliente', 'm.simbolo')
            ->get();

        $applied = DB::table('bancos as b')
            ->join('detallecxc as dc', 'dc.bancos_idbancos', '=', 'b.idbancos')
            ->join('cuentasporcobrar as cxc', 'cxc.idcuentasPorCobrar', '=', 'dc.cuentasPorCobrar_idcuentasPorCobrar')
            ->leftJoin('moneda as m', 'm.idmoneda', '=', 'dc.moneda_idmoneda')
            ->whereIn('b.tipoMov', ['C', 'S'])
            ->where('b.informacion', 'like', 'Aplicación saldo a favor%')
            ->where('b.informacion', 'not like', '[ANULADO]%')
            ->where('dc.estado', '!=', '0')
            ->whereIn('cxc.cliente_idcliente', $clientIds)
            ->when($currency !== null && trim($currency) !== '', fn ($query) => $this->applyCurrencySymbolFilter($query, $currency))
            ->select('cxc.cliente_idcliente', DB::raw('COALESCE(NULLIF(TRIM(m.simbolo), ""), "S/") as moneda_simbolo'))
            ->selectRaw('SUM(b.monto) as total_aplicado')
            ->groupBy('cxc.cliente_idcliente', 'm.simbolo')
            ->get();

        $result = [];
        foreach ($clientIds as $clientId) {
            $result[$clientId] = [];
        }
        foreach ($credits as $row) {
            $symbol = $this->normalizeCurrencySymbol($row->moneda_simbolo);
            $result[$row->cliente_idcliente][$symbol] = (float) $row->total_favor;
        }
        foreach ($applied as $row) {
            $symbol = $this->normalizeCurrencySymbol($row->moneda_simbolo);
            $result[$row->cliente_idcliente][$symbol] = max(
                0,
                (float) ($result[$row->cliente_idcliente][$symbol] ?? 0) - (float) $row->total_aplicado
            );
        }
        foreach ($result as &$clientAmounts) {
            foreach ($clientAmounts as &$amount) {
                $amount = max(0, $amount);
            }
            unset($amount);
        }
        unset($clientAmounts);

        return $result;
    }

    private function getEstadoCuentaClientRows(Request $request): Collection
    {
        $currencyRows = $this->applyEstadoCuentaFilters($this->estadoCuentaBaseQuery(), $request, false)
            ->orderBy('cli.idcliente')
            ->get();

        $clients = $currencyRows->groupBy('ruc_cliente')->flatMap(function (Collection $rows): array {
            $first = $rows->first();
            $currencyRows = [];
            foreach ($rows as $row) {
                $symbol = $this->normalizeCurrencySymbol($row->moneda_simbolo ?? null);
                $currencyRows[$symbol] ??= [
                    'currency_totals' => [],
                    'services' => [],
                ];
                $currencyRows[$symbol]['currency_totals'][$symbol] ??= ['monto_deuda' => 0.0, 'monto_pagar' => 0.0];
                $currencyRows[$symbol]['currency_totals'][$symbol]['monto_deuda'] += (float) ($row->monto_deuda ?? 0);
                $currencyRows[$symbol]['currency_totals'][$symbol]['monto_pagar'] += (float) ($row->monto_pagar ?? 0);
                $description = trim((string) ($row->servicio ?? ''));
                if ($description !== '') {
                    $currencyRows[$symbol]['services'][$description] = true;
                }
            }

            $result = [];
            foreach ($currencyRows as $symbol => $data) {
                $result[] = (object) [
                    'estado_cuenta_key' => (string) $first->ruc_cliente . '|' . $symbol,
                    'ruc_cliente' => $first->ruc_cliente,
                    'cliente_nombre' => $first->cliente_nombre,
                    'moneda_simbolo' => $symbol,
                    'servicio' => implode(' | ', array_keys($data['services'])),
                    'currency_totals' => $data['currency_totals'],
                ];
            }

            return $result;
        })->values();

        $allCxcClientIds = DB::table('cuentasporcobrar as c')
            ->join('cliente as cli', 'cli.idcliente', '=', 'c.cliente_idcliente')
            ->whereNotNull('c.cliente_idcliente')
            ->distinct()
            ->pluck('c.cliente_idcliente')
            ->map(fn ($id): string => (string) $id)
            ->all();
        $creditClientIds = $clients->pluck('ruc_cliente')
            ->merge($allCxcClientIds)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $creditsByClient = $this->getMontosAFavorByClientCurrency($creditClientIds, $request->query('moneda'));
        $existingKeys = $clients->keyBy('estado_cuenta_key');
        $missingCurrencyRows = collect();
        $clientNames = DB::table('cliente')
            ->whereIn('idcliente', $allCxcClientIds)
            ->get([
                'idcliente',
                DB::raw('COALESCE(razonSocial, nombreComercial, idcliente) as cliente_nombre'),
            ])
            ->keyBy('idcliente');

        foreach ($allCxcClientIds as $clientId) {
            $name = $clientNames->get($clientId)?->cliente_nombre ?? $clientId;
            foreach (($creditsByClient[$clientId] ?? []) as $symbol => $amount) {
                if ((float) $amount <= 0 || $existingKeys->has($clientId . '|' . $symbol)) {
                    continue;
                }
                $missingCurrencyRows->push((object) [
                    'estado_cuenta_key' => $clientId . '|' . $symbol,
                    'ruc_cliente' => $clientId,
                    'cliente_nombre' => $name,
                    'moneda_simbolo' => $symbol,
                    'servicio' => '',
                    'currency_totals' => [
                        $symbol => ['monto_deuda' => 0.0, 'monto_pagar' => 0.0],
                    ],
                ]);
            }
        }
        $clients = $clients->concat($missingCurrencyRows)->values();

        $amountRanges = $request->validate([
            'monto_deuda_desde' => ['nullable', 'numeric', 'min:0'],
            'monto_deuda_hasta' => ['nullable', 'numeric', 'min:0'],
            'monto_favor_desde' => ['nullable', 'numeric', 'min:0'],
            'monto_favor_hasta' => ['nullable', 'numeric', 'min:0'],
        ]);

        $debtAmount = trim((string) $request->query('monto_deuda', ''));
        if ($debtAmount !== '' && is_numeric($debtAmount) && (float) $debtAmount >= 0) {
            $expectedAmount = round((float) $debtAmount, 2);
            $operator = $this->amountComparisonOperator($request);
            $clients = $clients->filter(function (object $client) use ($expectedAmount, $operator): bool {
                $totals = $client->currency_totals[$client->moneda_simbolo] ?? [];

                return $this->matchesAmountComparison((float) ($totals['monto_deuda'] ?? 0), $expectedAmount, $operator);
            })->values();
        }

        $debtFrom = $amountRanges['monto_deuda_desde'] ?? null;
        $debtTo = $amountRanges['monto_deuda_hasta'] ?? null;
        $favorFrom = $amountRanges['monto_favor_desde'] ?? null;
        $favorTo = $amountRanges['monto_favor_hasta'] ?? null;
        if ($debtFrom === null && $debtTo === null && $favorFrom === null && $favorTo === null) {
            return $clients;
        }

        $clientIds = $clients->pluck('ruc_cliente')->filter()->unique()->values()->all();
        $creditsByClient = $this->getMontosAFavorByClientCurrency($clientIds, $request->query('moneda'));

        return $clients->filter(function (object $client) use ($creditsByClient, $debtFrom, $debtTo, $favorFrom, $favorTo): bool {
            $currency = $client->moneda_simbolo;
            $debt = (float) ($client->currency_totals[$currency]['monto_deuda'] ?? 0);
            $favor = (float) ($creditsByClient[$client->ruc_cliente][$currency] ?? 0);
            $matchesDebt = ($debtFrom === null && $debtTo === null)
                || $this->matchesAmountRange($debt, $debtFrom, $debtTo);
            $matchesFavor = ($favorFrom === null && $favorTo === null)
                || $this->matchesAmountRange($favor, $favorFrom, $favorTo);

            return $matchesDebt && $matchesFavor;
        })->values();
    }

    /**
     * Devuelve el monto a favor (tipoMov='A') en bancos agrupado por cliente_idcliente.
     *
     * @param  string[]  $clientIds
     */
    public function getMontosAFavorByClient(array $clientIds): array
    {
        if (empty($clientIds)) {
            return [];
        }

        $credits = DB::table('bancos as b')
            ->leftJoin('detallecxc as dc', 'dc.bancos_idbancos', '=', 'b.idbancos')
            ->leftJoin('cuentasporcobrar as cxc', 'cxc.idcuentasPorCobrar', '=', 'dc.cuentasPorCobrar_idcuentasPorCobrar')
            ->where('b.tipoMov', 'A')
            ->where('b.informacion', 'not like', '[ANULADO]%')
            ->where('dc.estado', '!=', '0')
            ->whereNotNull('cxc.cliente_idcliente')
            ->whereIn('cxc.cliente_idcliente', $clientIds)
            ->select('cxc.cliente_idcliente', DB::raw('SUM(b.monto) as total_favor'))
            ->groupBy('cxc.cliente_idcliente')
            ->pluck('total_favor', 'cliente_idcliente')
            ->all();

        $applied = DB::table('bancos as b')
            ->leftJoin('detallecxc as dc', 'dc.bancos_idbancos', '=', 'b.idbancos')
            ->leftJoin('cuentasporcobrar as cxc', 'cxc.idcuentasPorCobrar', '=', 'dc.cuentasPorCobrar_idcuentasPorCobrar')
            ->whereIn('b.tipoMov', ['C', 'S'])
            ->where('b.informacion', 'like', 'Aplicación saldo a favor%')
            ->where('b.informacion', 'not like', '[ANULADO]%')
            ->where('dc.estado', '!=', '0')
            ->whereNotNull('cxc.cliente_idcliente')
            ->whereIn('cxc.cliente_idcliente', $clientIds)
            ->select('cxc.cliente_idcliente', DB::raw('SUM(b.monto) as total_aplicado'))
            ->groupBy('cxc.cliente_idcliente')
            ->pluck('total_aplicado', 'cliente_idcliente')
            ->all();

        $result = [];
        foreach ($clientIds as $clientId) {
            $result[$clientId] = max(
                0,
                (float) ($credits[$clientId] ?? 0) - (float) ($applied[$clientId] ?? 0)
            );
        }

        return $result;
    }

    private function estadoCuentaBaseQuery()
    {
        return DB::table('cuentasporcobrar as c')
            ->join('cliente as cli', 'cli.idcliente', '=', 'c.cliente_idcliente')
            ->leftJoin('moneda as m', 'm.idmoneda', '=', 'c.moneda_idmoneda')
            ->select([
                'cli.idcliente as ruc_cliente',
                DB::raw('COALESCE(cli.razonSocial, cli.nombreComercial, cli.idcliente, "-") as cliente_nombre'),
                DB::raw('COALESCE(NULLIF(TRIM(m.simbolo), ""), "S/") as moneda_simbolo'),
                DB::raw('
                    GROUP_CONCAT(
                        DISTINCT NULLIF(TRIM(c.descripcion), "")
                        ORDER BY c.idcuentasPorCobrar DESC
                        SEPARATOR " | "
                    ) as servicio'),
                DB::raw('
                    SUM(CASE
                        WHEN c.montoActual > 0
                        THEN c.montoActual ELSE 0
                    END) as monto_deuda'),
                DB::raw('
                    SUM(CASE
                        WHEN c.estado IN ("1", "2", "PENDIENTE", "FACTURADO", "Pendiente Pago parcial", "Pendiente a credito") AND c.montoActual > 0
                        THEN c.montoActual ELSE 0
                    END) as monto_pagar'),
            ])
            ->whereIn('c.estado', ['1', '2', '4', 'PENDIENTE', 'FACTURADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito'])
            ->where('c.montoActual', '>', 0)
            ->groupBy('cli.idcliente', 'cli.razonSocial', 'cli.nombreComercial', 'm.simbolo');
    }

    private function applyEstadoCuentaFilters($query, Request $request, bool $filterAmount = true)
    {
        $currencyFilters = $request->validate([
            'moneda' => ['nullable', 'in:S/,$'],
        ]);
        $this->applyCurrencySymbolFilter($query, $currencyFilters['moneda'] ?? null);

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $query->where(function ($builder) use ($term): void {
                $builder
                    ->where('cli.idcliente', 'like', $term)
                    ->orWhere('cli.razonSocial', 'like', $term)
                    ->orWhere('cli.nombreComercial', 'like', $term);
            });
        }

        $clientSearch = trim((string) $request->query('cliente', ''));
        if ($clientSearch !== '') {
            $term = '%' . $clientSearch . '%';
            $query->where(function ($builder) use ($term): void {
                $builder
                    ->where('cli.idcliente', 'like', $term)
                    ->orWhere('cli.razonSocial', 'like', $term)
                    ->orWhere('cli.nombreComercial', 'like', $term);
            });
        }

        $debtAmount = trim((string) $request->query('monto_deuda', ''));
        if ($filterAmount && $debtAmount !== '' && is_numeric($debtAmount) && (float) $debtAmount >= 0) {
            $operator = $this->amountComparisonOperator($request);
            $query->havingRaw('
                SUM(CASE WHEN c.estado IN ("4", "VENCIDO", "1", "2", "PENDIENTE", "FACTURADO", "Pendiente Pago parcial", "Pendiente a credito") AND c.montoActual > 0 THEN c.montoActual ELSE 0 END) ' . $operator . ' ?
            ', [round((float) $debtAmount, 2)]);
        }

        return $query;
    }

    public function getBankOptions(): Collection
    {
        return DB::table('entidadbancaria')
            ->select('identidadBancaria', 'razonSocial', 'descripcion')
            ->orderBy('razonSocial')
            ->orderBy('descripcion')
            ->get()
            ->map(function ($bank): object {
                $name = trim((string) ($bank->razonSocial ?? ''));
                $description = trim((string) ($bank->descripcion ?? ''));
                $bank->label = $description !== '' ? $description : ($name !== '' ? $name : 'Entidad bancaria #' . $bank->identidadBancaria);

                return $bank;
            });
    }

    private function baseQuery()
    {
        return $this->joinedQuery()
            ->where('b.informacion', 'not like', 'Reversión de pago CXC #%')
            ->where('b.informacion', 'not like', 'Reversión de saldo a favor CXC #%')
            ->select([
                'b.idbancos',
                'b.entidadBancaria_identidadBancaria',
                'b.fechaRegistro',
                'b.informacion',
                'b.monto',
                'b.tipoMov',
                'cli.idcliente as cliente_ruc',
                'cxc.idcuentasPorCobrar as cxc_id',
                'cxc.montoActual as cxc_monto_actual',
                'cxc.estado as cxc_estado',
                DB::raw('COALESCE(eb.razonSocial, eb.descripcion, CONCAT("Entidad #", b.entidadBancaria_identidadBancaria)) as entidad_bancaria_nombre'),
                DB::raw('CASE WHEN b.tipoMov = "S" AND b.informacion NOT LIKE "Nota de crédito:%" THEN "SEGURTRAK S.A.C." ELSE COALESCE(cli.razonSocial, cli.nombreComercial, cli.idcliente, "-") END as cliente_nombre'),
                DB::raw('COALESCE(NULLIF(TRIM(cxc.descripcion), ""), NULLIF(TRIM(dc.descripcion), ""), NULLIF(TRIM(b.informacion), ""), "-") as servicio'),
                DB::raw('COALESCE(m.simbolo, "S/") as moneda_simbolo'),
            ]);
    }

    private function joinedQuery()
    {
        return DB::table('bancos as b')
            ->leftJoin('entidadbancaria as eb', 'eb.identidadBancaria', '=', 'b.entidadBancaria_identidadBancaria')
            ->leftJoin('detallecxc as dc', 'dc.bancos_idbancos', '=', 'b.idbancos')
            ->leftJoin('cuentasporcobrar as cxc', 'cxc.idcuentasPorCobrar', '=', 'dc.cuentasPorCobrar_idcuentasPorCobrar')
            ->leftJoin('cliente as cli', 'cli.idcliente', '=', 'cxc.cliente_idcliente')
            ->leftJoin('moneda as m', 'm.idmoneda', '=', 'dc.moneda_idmoneda')
        ;
    }

    private function applyOperationalMovementFilter($query)
    {
        return $query
            ->where('b.informacion', 'not like', '[ANULADO]%')
            ->where('b.informacion', 'not like', 'Reversión de pago CXC #%')
            ->where('b.informacion', 'not like', 'Reversión de saldo a favor CXC #%')
            ->where(function ($builder): void {
                $builder->whereNull('dc.estado')->orWhere('dc.estado', '!=', '0');
            });
    }

    private function applyFilters($query, Request $request)
    {
        $currencyFilters = $request->validate([
            'moneda' => ['nullable', 'in:S/,$'],
        ]);
        $this->applyCurrencySymbolFilter($query, $currencyFilters['moneda'] ?? null);

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $query->where(function ($builder) use ($term): void {
                $builder
                    ->where('b.idbancos', 'like', $term)
                    ->orWhere('b.informacion', 'like', $term)
                    ->orWhere('b.tipoMov', 'like', $term)
                    ->orWhere('eb.razonSocial', 'like', $term)
                    ->orWhere('eb.descripcion', 'like', $term)
                    ->orWhere('cli.idcliente', 'like', $term)
                    ->orWhere('cli.razonSocial', 'like', $term)
                    ->orWhere('cli.nombreComercial', 'like', $term);
            });
        }

        $entityId = trim((string) $request->query('entidadBancaria_identidadBancaria', ''));
        if ($entityId !== '') {
            $query->where('b.entidadBancaria_identidadBancaria', $entityId);
        }

        $clientSearch = trim((string) $request->query('cliente', ''));
        if ($clientSearch !== '') {
            $term = '%' . $clientSearch . '%';
            $query->where(function ($builder) use ($term): void {
                $builder
                    ->where('cli.idcliente', 'like', $term)
                    ->orWhere('cli.razonSocial', 'like', $term)
                    ->orWhere('cli.nombreComercial', 'like', $term);
            });
        }

        $rangeFilters = $request->validate([
            'fechaDesde' => ['nullable', 'date_format:Y-m-d'],
            'fechaHasta' => ['nullable', 'date_format:Y-m-d'],
            'montoDesde' => ['nullable', 'numeric', 'min:0'],
            'montoHasta' => ['nullable', 'numeric', 'min:0'],
        ]);

        $dateFrom = trim((string) ($rangeFilters['fechaDesde'] ?? ''));
        if ($dateFrom !== '') {
            $query->whereDate('b.fechaRegistro', '>=', $dateFrom);
        }

        $dateTo = trim((string) ($rangeFilters['fechaHasta'] ?? ''));
        if ($dateTo !== '') {
            $query->whereDate('b.fechaRegistro', '<=', $dateTo);
        }

        $movementType = strtoupper(trim((string) $request->query('tipoMov', '')));
        if (in_array($movementType, ['I', 'S', 'A', 'C', 'T'], true)) {
            $query->where('b.tipoMov', $movementType);
        }

        $amountFrom = trim((string) ($rangeFilters['montoDesde'] ?? ''));
        if ($amountFrom !== '') {
            $query->where('b.monto', '>=', round((float) $amountFrom, 2));
        }

        $amountTo = trim((string) ($rangeFilters['montoHasta'] ?? ''));
        if ($amountTo !== '') {
            $query->where('b.monto', '<=', round((float) $amountTo, 2));
        }

        return $query;
    }

    private function amountComparisonOperator(Request $request): string
    {
        return match ((string) $request->query('monto_deuda_operador', 'eq')) {
            'gt' => '>',
            'gte' => '>=',
            'lt' => '<',
            'lte' => '<=',
            default => '=',
        };
    }

    private function matchesAmountComparison(float $amount, float $expected, string $operator): bool
    {
        $amount = round($amount, 2);

        return match ($operator) {
            '>' => $amount > $expected,
            '>=' => $amount >= $expected,
            '<' => $amount < $expected,
            '<=' => $amount <= $expected,
            default => $amount === $expected,
        };
    }

    private function matchesAmountRange(float $amount, mixed $from, mixed $to): bool
    {
        $amount = round($amount, 2);

        return ($from === null || $amount >= round((float) $from, 2))
            && ($to === null || $amount <= round((float) $to, 2));
    }

    private function applyCurrencySymbolFilter($query, ?string $currency)
    {
        return match (trim((string) $currency)) {
            'S/' => $query->where(function ($builder): void {
                $builder
                    ->whereNull('m.simbolo')
                    ->orWhereRaw('TRIM(COALESCE(m.simbolo, "")) = ""')
                    ->orWhereRaw('UPPER(TRIM(m.simbolo)) IN (?, ?, ?, ?)', ['S', 'S/', 'SOL', 'SOLES']);
            }),
            '$' => $query->whereRaw('UPPER(TRIM(m.simbolo)) IN (?, ?, ?)', ['$', 'US$', 'USD']),
            default => $query,
        };
    }

    private function normalizeCurrencySymbol(?string $symbol): string
    {
        $symbol = trim((string) ($symbol ?? ''));
        if ($symbol === '' || in_array(mb_strtolower($symbol, 'UTF-8'), ['s', 's/', 'sol', 'soles'], true)) {
            return 'S/';
        }

        return $symbol;
    }
}
