<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CxcService
{
    /**
     * FASE 1: PENDIENTE (Creación Automática)
     * Inserta automáticamente un registro en cuentasPorCobrar al crear/activar un servicio.
     */
    public function crearCobroDesdeServicio(array $dataServicio): int
    {
        $clienteId = (string) ($dataServicio['cliente_idcliente'] ?? '');
        $montoServicio = round((float) ($dataServicio['montoServicioBase'] ?? $dataServicio['monto'] ?? 0), 2);
        // Cada cuenta representa un periodo independiente. La deuda anterior no se suma al nuevo periodo.
        $montoActual = $montoServicio;
        $monedaId = isset($dataServicio['moneda_idmoneda']) && $dataServicio['moneda_idmoneda'] !== ''
            ? (int) $dataServicio['moneda_idmoneda']
            : null;
        $tipoCobroId = (int) ($dataServicio['tipoCobro_idtipoCobros'] ?? 0);

        if ($tipoCobroId <= 0) {
            $tipoCobroId = $this->tipoCobroIdDesdeServicio($dataServicio);
        }

        if ($tipoCobroId <= 0) {
            $tipoCobroId = (int) DB::table('tipocobro')->orderBy('idtipoCobros')->value('idtipoCobros') ?: 1;
        }

        $periodo = '';
        if (!empty($dataServicio['fechaInicio']) && !empty($dataServicio['fecheVencimiento'])) {
            $periodo = ' ' . Carbon::parse($dataServicio['fechaInicio'])->format('d/m/Y')
                . ' a ' . Carbon::parse($dataServicio['fecheVencimiento'])->format('d/m/Y');
        }
        $descripcion = trim((string) ($dataServicio['descripcion'] ?? ($periodo !== ''
            ? 'CXC' . $periodo
            : 'Cobro por servicio ' . ($dataServicio['vehiculo_placa'] ?? ''))));
        $serviceId = (int) ($dataServicio['idservicioCliente'] ?? 0);
        $serviceMarkerPattern = '/(^|[^A-Za-z0-9])SC-' . $serviceId . '([^0-9]|$)/';
        if ($serviceId > 0 && preg_match($serviceMarkerPattern, $descripcion) !== 1) {
            $serviceMarker = ' SC-' . $serviceId;
            $descripcion = rtrim(mb_substr($descripcion, 0, 50 - mb_strlen($serviceMarker, 'UTF-8'), 'UTF-8')) . $serviceMarker;
        }
        if (mb_strlen($descripcion) > 50) {
            $descripcion = mb_substr($descripcion, 0, 50);
        }

        $docRef = trim((string) ($dataServicio['docReferencia'] ?? ''));
        if (mb_strlen($docRef) > 15) {
            $docRef = mb_substr($docRef, 0, 15);
        }

        if ($clienteId !== '') {
            if (str_contains($descripcion, 'REN-CXC-')) {
                $existingId = DB::table('cuentasporcobrar')
                    ->where('cliente_idcliente', $clienteId)
                    ->where('descripcion', $descripcion)
                    ->whereIn('estado', ['1', '2', '3', '4', 'PENDIENTE', 'FACTURADO', 'CANCELADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito', 'Cancelado Detracción'])
                    ->value('idcuentasPorCobrar');
                if ($existingId) {
                    return (int) $existingId;
                }
            } elseif ($serviceId > 0) {
                $existingQuery = DB::table('cuentasporcobrar')
                    ->where('cliente_idcliente', $clienteId)
                    ->where('montoOriginal', $montoServicio)
                    ->whereIn('estado', ['1', '2', '3', '4', 'PENDIENTE', 'FACTURADO', 'CANCELADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito', 'Cancelado Detracción']);
                if ($docRef !== '') {
                    $existingQuery->where('docReferencia', $docRef);
                } else {
                    $existingQuery->whereNull('docReferencia');
                }
                $existing = $existingQuery->orderByDesc('idcuentasPorCobrar')
                    ->get(['idcuentasPorCobrar', 'descripcion'])
                    ->first(static fn(object $charge): bool =>
                        preg_match($serviceMarkerPattern, (string) $charge->descripcion) === 1
                        && ($periodo === '' || str_contains((string) $charge->descripcion, trim($periodo)))
                    );
                if ($existing) {
                    return (int) $existing->idcuentasPorCobrar;
                }
            } else {
                $existingQuery = DB::table('cuentasporcobrar')
                    ->where('cliente_idcliente', $clienteId)
                    ->where('montoOriginal', $montoServicio)
                    ->whereIn('estado', ['1', '2', '3', '4', 'PENDIENTE', 'FACTURADO', 'CANCELADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito', 'Cancelado Detracción']);

                if ($docRef !== '') {
                    $existingQuery->where('docReferencia', $docRef);
                } else {
                    $existingQuery->whereNull('docReferencia');
                    if ($periodo !== '') {
                        $existingQuery->where('descripcion', 'like', '%' . trim($periodo) . '%');
                    }
                }

                $existingId = $existingQuery->value('idcuentasPorCobrar');
                if ($existingId) {
                    return (int) $existingId;
                }
            }
        }

        return DB::table('cuentasporcobrar')->insertGetId([
            'cliente_idcliente' => $clienteId,
            'tipoCobro_idtipoCobros' => $tipoCobroId,
            'moneda_idmoneda' => $monedaId,
            'docReferencia' => $docRef !== '' ? $docRef : null,
            'descripcion' => $descripcion,
            'montoOriginal' => $montoServicio,
            'montoActual' => $montoActual,
            'canCuotas' => null,
            'fechaRegistro' => null,
            'fechaCancelacion' => $dataServicio['fecheVencimiento'] ?? null,
            'estado' => '1',
        ]);
    }

    public function synchronizeCurrentServiceChargeDates(int $serviceId, object $oldService, array $updatedService): bool
    {
        $oldStart = !empty($oldService->fechaInicio) ? Carbon::parse($oldService->fechaInicio)->toDateString() : null;
        $oldEnd = !empty($oldService->fecheVencimiento) ? Carbon::parse($oldService->fecheVencimiento)->toDateString() : null;
        $newStartValue = array_key_exists('fechaInicio', $updatedService)
            ? $updatedService['fechaInicio']
            : $oldStart;
        $newEndValue = array_key_exists('fecheVencimiento', $updatedService)
            ? $updatedService['fecheVencimiento']
            : $oldEnd;
        $newStart = !empty($newStartValue) ? Carbon::parse($newStartValue)->toDateString() : null;
        $newEnd = !empty($newEndValue) ? Carbon::parse($newEndValue)->toDateString() : null;

        if ($oldStart === $newStart && $oldEnd === $newEnd) {
            return false;
        }

        $oldRange = $oldStart && $oldEnd
            ? Carbon::parse($oldStart)->format('d/m/Y') . ' a ' . Carbon::parse($oldEnd)->format('d/m/Y')
            : null;
        $newRange = $newStart && $newEnd
            ? Carbon::parse($newStart)->format('d/m/Y') . ' a ' . Carbon::parse($newEnd)->format('d/m/Y')
            : null;
        $service = DB::table('serviciocliente')
            ->where('idservicioCliente', $serviceId)
            ->first(['cliente_idcliente', 'moneda_idmoneda', 'monto', 'docReferencia']);

        if (!$service || !$oldEnd || !$newEnd || !$newRange) {
            return false;
        }

        $openStates = ['1', '4', 'PENDIENTE', 'VENCIDO'];
        $periodCandidates = DB::table('cuentasporcobrar')
            ->where('cliente_idcliente', $service->cliente_idcliente)
            ->where('moneda_idmoneda', $service->moneda_idmoneda)
            ->where(function ($period) use ($oldEnd, $oldRange): void {
                $period->whereDate('fechaCancelacion', $oldEnd);
                if ($oldRange !== null) {
                    $period->orWhere('descripcion', 'like', '%' . $oldRange . '%');
                }
            })
            ->orderByDesc('idcuentasPorCobrar')
            ->get(['idcuentasPorCobrar', 'descripcion', 'docReferencia', 'estado', 'montoOriginal', 'montoActual']);
        $markerPattern = '(^|[^A-Za-z0-9])SC-' . $serviceId . '([^0-9]|$)';
        $hasMarkedCharge = $periodCandidates->contains(
            static fn(object $charge): bool => preg_match('/' . $markerPattern . '/', (string) $charge->descripcion) === 1
        );
        $eligible = static fn(object $charge): bool => in_array((string) $charge->estado, $openStates, true)
            && (float) $charge->montoActual > 0
            && abs((float) $charge->montoActual - (float) $charge->montoOriginal) < 0.005;
        $markedCharges = $periodCandidates->filter(
            static fn(object $charge): bool => $eligible($charge)
                && preg_match('/' . $markerPattern . '/', (string) $charge->descripcion) === 1
        );

        if (!$hasMarkedCharge && $oldRange !== null) {
            $markedCharges = $periodCandidates->filter(static function (object $charge) use ($oldRange, $service, $eligible): bool {
                return preg_match('/SC-[0-9]+/', (string) $charge->descripcion) !== 1
                    && $eligible($charge)
                    && str_contains((string) $charge->descripcion, $oldRange)
                    && abs((float) $charge->montoOriginal - (float) $service->monto) < 0.005
                    && (empty($service->docReferencia) || (string) $charge->docReferencia === (string) $service->docReferencia);
            });
        }

        if ($markedCharges->count() > 1) {
            throw new \RuntimeException('ambiguous_service_cxc');
        }

        $charge = $markedCharges->first();
        if (!$charge) {
            return false;
        }

        $description = (string) $charge->descripcion;
        $prefix = $oldRange !== null && str_contains($description, $oldRange)
            ? mb_substr($description, 0, mb_strpos($description, $oldRange, 0, 'UTF-8'), 'UTF-8')
            : 'CXC ';
        $marker = 'SC-' . $serviceId;
        $maxPrefixLength = max(0, 50 - mb_strlen($newRange, 'UTF-8') - mb_strlen($marker, 'UTF-8') - 2);
        $description = mb_substr($prefix, 0, $maxPrefixLength, 'UTF-8')
            . $newRange . ' ' . $marker;

        DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $charge->idcuentasPorCobrar)
            ->update([
                'fechaCancelacion' => $newEnd,
                'descripcion' => $description,
            ]);

        return true;
    }

    /**
     * FASE 1.5: VENCIDO (Automatización por fecha de corte)
    * Desde el día 5, los periodos no pagados pasan a VENCIDO.
     */
    public function verificarYActualizarVencidos(?Carbon $hoy = null): int
    {
        $updatedCount = 0;
        $hoy ??= Carbon::today();

        $pendientes = DB::table('cuentasporcobrar as c')
            ->select([
                'c.idcuentasPorCobrar',
                'c.fechaRegistro',
                'c.fechaCancelacion',
                'c.descripcion',
            ])
            ->whereIn('c.estado', ['1', '2', 'PENDIENTE', 'FACTURADO', 'Pendiente Pago parcial', 'Pendiente a credito'])
            ->where('c.montoActual', '>', 0)
            ->get();

        foreach ($pendientes as $row) {
            $periodo = $this->periodFromDescription((string) ($row->descripcion ?? ''));
            $fechaLimite = $periodo[1] ?? $row->fechaCancelacion;

            if ($fechaLimite && Carbon::parse($fechaLimite)->startOfDay()->lt($hoy->copy()->startOfDay())) {
                DB::table('cuentasporcobrar')
                    ->where('idcuentasPorCobrar', $row->idcuentasPorCobrar)
                    ->update(['estado' => '4']);

                $updatedCount++;
            }
        }

        return $updatedCount;
    }

    /**
     * FASE 2: FACTURADO (Carga / Asignación de Documento)
     * Asigna el número de documento de referencia y actualiza el estado a 'FACTURADO'.
     */
    public function registrarFactura(int|string $idCxc, UploadedFile|string $docOrFile, ?string $docNumText = null, array|int|string|null $servicioIds = null): bool
    {
        $cxc = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $idCxc)
            ->first();

        if (!$cxc) {
            throw new \RuntimeException('Cuenta por cobrar no encontrada.');
        }

        $parsedServiceIds = [];
        if (is_array($servicioIds)) {
            $parsedServiceIds = array_values(array_filter(array_map('intval', $servicioIds)));
        } elseif ($servicioIds !== null && (int) $servicioIds > 0) {
            $parsedServiceIds = [(int) $servicioIds];
        }

        if (!empty($parsedServiceIds)) {
            $validCount = DB::table('serviciocliente')
                ->whereIn('idservicioCliente', $parsedServiceIds)
                ->where('cliente_idcliente', $cxc->cliente_idcliente)
                ->count();

            if ($validCount === 0) {
                throw new \RuntimeException('El servicio seleccionado no pertenece al cliente de la cuenta.');
            }
        }

        $docRef = '';
        if (!empty($docNumText)) {
            $docRef = mb_substr(trim($docNumText), 0, 15);
        }

        $docFactura = null;
        if ($docOrFile instanceof UploadedFile) {
            $docFactura = mb_substr($docOrFile->store('cuentasxcobrar/facturas', 'public'), 0, 50);
            if (empty($docRef)) {
                $docRef = mb_substr(basename($docFactura), 0, 15);
            }
        } elseif (is_string($docOrFile) && !empty($docOrFile)) {
            $docFactura = mb_substr(trim($docOrFile), 0, 50);
            if (empty($docRef)) {
                $docRef = mb_substr(basename($docFactura), 0, 15);
            }
        }

        if (empty($docRef)) {
            $docRef = 'FAC-' . time();
        }

        $updateData = [
            'docReferencia' => $docRef,
            'estado' => '2',
        ];

        // Solo incluir docFactura si la columna existe (algunos entornos no la tienen)
        if ($docFactura !== null && DB::getSchemaBuilder()->hasColumn('cuentasporcobrar', 'docFactura')) {
            $updateData['docFactura'] = $docFactura;
        }

        DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $idCxc)
            ->update($updateData);

        // El documento pertenece al grupo de servicios de esta CXC.
        $serviceQuery = DB::table('serviciocliente')
            ->where('cliente_idcliente', $cxc->cliente_idcliente);

        if (!empty($parsedServiceIds)) {
            $serviceQuery->whereIn('idservicioCliente', $parsedServiceIds);
        } else {
            $serviceQuery->where('monto', $cxc->montoOriginal);
        }

        $serviceQuery->where(function ($q) {
            $q->whereNull('docReferencia')->orWhere('docReferencia', '');
        });

        $serviceQuery->update(['docReferencia' => $docRef]);

        return true;
    }

    /**
     * FASE 3: CANCELADO Y RENOVACIÓN (Subir Comprobante de Pago y Transacciones)
     * Transaccionalmente guarda la evidencia de pago, movimiento en bancos, transacción en detalleCXC,
     * deduce el saldo, actualiza a 'CANCELADO' y dispara la renovación del servicio.
     */
    public static function calculatePaymentBreakdown(
        float $grossAmount,
        bool $isRetention,
        float $availableCredit,
        bool $useCredit,
        float $cashReceived,
        bool $applyDeduction = true
    ): array {
        $grossAmount = round(max(0, $grossAmount), 2);
        $deductionRate = $isRetention ? 0.03 : 0.12;
        $deduction = $applyDeduction && $grossAmount > 700 ? round($grossAmount * $deductionRate, 2) : 0.0;
        $afterDeduction = round($grossAmount - $deduction, 2);
        $creditApplied = $useCredit ? round(min($afterDeduction, max(0, $availableCredit)), 2) : 0.0;
        $cashDue = round(max(0, $afterDeduction - $creditApplied), 2);
        $cashApplied = round(min($cashDue, max(0, $cashReceived)), 2);
        $newCredit = round(max(0, $cashReceived - $cashApplied), 2);

        return [
            'gross' => $grossAmount,
            'deduction_rate' => $deductionRate,
            'deduction' => $deduction,
            'after_deduction' => $afterDeduction,
            'credit_available' => round(max(0, $availableCredit), 2),
            'credit_applied' => $creditApplied,
            'cash_due' => $cashDue,
            'cash_received' => round(max(0, $cashReceived), 2),
            'cash_applied' => $cashApplied,
            'new_credit' => $newCredit,
            'remaining_debt' => round(max(0, $grossAmount - $deduction - $creditApplied - $cashApplied), 2),
        ];
    }

    public static function creditInstallmentLabel(?int $currentInstallment = null, ?int $totalInstallments = null): string
    {
        $currentInstallment = $currentInstallment === null ? null : max(1, (int) $currentInstallment);
        $totalInstallments = $totalInstallments === null ? null : max(1, (int) $totalInstallments);

        if ($currentInstallment === null || $totalInstallments === null || $totalInstallments <= 1) {
            return 'Pendiente a credito';
        }

        return 'Pendiente a credito: ' . min($currentInstallment, $totalInstallments) . '/' . $totalInstallments;
    }

    public static function resolvePaymentState(
        float $remainingDebt,
        float $deduction,
        string $paymentMethod,
        bool $isRetention,
        ?string $periodEnd,
        ?Carbon $today = null,
        ?int $currentInstallment = null,
        ?int $totalInstallments = null
    ): string {
        if (round(max(0, $remainingDebt), 2) <= 0) {
            return $deduction > 0 && !$isRetention ? 'Cancelado Detracción' : '3';
        }

        $today ??= Carbon::today();
        if ($periodEnd && Carbon::parse($periodEnd)->startOfDay()->lt($today->copy()->startOfDay())) {
            return '4';
        }

        $method = mb_strtolower(trim($paymentMethod), 'UTF-8');
        if (str_contains($method, 'credito') || str_contains($method, 'crédito')) {
            return self::creditInstallmentLabel($currentInstallment, $totalInstallments);
        }

        return 'Pendiente Pago parcial';
    }

    public static function automaticGenerationDate(?string $periodEnd): ?Carbon
    {
        if (!$periodEnd) {
            return null;
        }

        return Carbon::parse($periodEnd)->startOfMonth()->startOfDay();
    }

    public static function shouldRenewAfterFullPayment(?string $periodEnd): bool
    {
        return self::automaticGenerationDate($periodEnd) !== null;
    }

    public static function shouldGenerateNextPeriod(?string $state, ?string $periodEnd, ?Carbon $today = null): bool
    {
        $renewableStates = ['1', '2', '4', 'PENDIENTE', 'FACTURADO', 'VENCIDO', 'PENDIENTE PAGO PARCIAL', 'PENDIENTE A CREDITO'];
        $generationDate = self::automaticGenerationDate($periodEnd);
        $today ??= Carbon::today();

        return $generationDate !== null
            && in_array(mb_strtoupper(trim((string) $state), 'UTF-8'), $renewableStates, true)
            && !$generationDate->gt($today->copy()->startOfDay());
    }

    public static function advancePeriodEnd(string $periodEnd, int $coveredPeriods, int $intervalMonths = 1): Carbon
    {
        $monthsToAdd = max(0, $coveredPeriods - 1) * max(1, $intervalMonths);

        return Carbon::parse($periodEnd)->addMonthsNoOverflow($monthsToAdd);
    }

    public static function advanceMonthsFromDescription(?string $description): int
    {
        if (preg_match('/(?:^|\s)-\s(\d{1,3})$/u', trim((string) $description), $matches) !== 1) {
            return 0;
        }

        $months = (int) $matches[1];
        return $months >= 2 && $months <= 120 ? $months : 0;
    }

    public static function descriptionWithAdvanceMonths(?string $description, int $months): string
    {
        $description = trim((string) $description);
        if ($months < 2 || $months > 120) {
            return mb_substr($description, 0, 50);
        }

        $suffix = ' - ' . $months;
        $prefix = mb_substr($description, 0, max(0, 50 - mb_strlen($suffix, 'UTF-8')), 'UTF-8');

        return rtrim($prefix) . $suffix;
    }

    public static function isPreviousBillingPeriod(?Carbon $periodEnd, ?Carbon $today = null): bool
    {
        if (!$periodEnd) {
            return false;
        }

        $today ??= Carbon::today();

        return $periodEnd->copy()->startOfMonth()->lt($today->copy()->startOfMonth());
    }

    public function saldoFavorDisponible(string $clientId, int $currencyId): float
    {
        if ($clientId === '' || $currencyId <= 0) {
            return 0.0;
        }

        $credits = DB::table('bancos as b')
            ->join('detallecxc as dc', 'dc.bancos_idbancos', '=', 'b.idbancos')
            ->join('cuentasporcobrar as origen', 'origen.idcuentasPorCobrar', '=', 'dc.cuentasPorCobrar_idcuentasPorCobrar')
            ->where('b.tipoMov', 'A')
            ->where('b.informacion', 'not like', '[ANULADO]%')
            ->where('dc.estado', '!=', '0')
            ->where('origen.cliente_idcliente', $clientId)
            ->where('dc.moneda_idmoneda', $currencyId)
            ->sum('b.monto');

        $used = DB::table('bancos as b')
            ->join('detallecxc as dc', 'dc.bancos_idbancos', '=', 'b.idbancos')
            ->join('cuentasporcobrar as destino', 'destino.idcuentasPorCobrar', '=', 'dc.cuentasPorCobrar_idcuentasPorCobrar')
            ->whereIn('b.tipoMov', ['C', 'S'])
            ->where(function ($query) {
                $query->where('b.informacion', 'like', 'Aplicación saldo a favor%')
                    ->orWhere('b.informacion', 'like', 'Nota de crédito:%');
            })
            ->where('b.informacion', 'not like', '[ANULADO]%')
            ->where('dc.estado', '!=', '0')
            ->where('destino.cliente_idcliente', $clientId)
            ->where('dc.moneda_idmoneda', $currencyId)
            ->sum('b.monto');

        return round(max(0, (float) $credits - (float) $used), 2);
    }

    public function procesarPagoGrupo(array $cxcIds, array $pagoData, UploadedFile|string|null $fileComprobante = null): array
    {
        $ids = collect($cxcIds)->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)->unique()->values()->all();
        if ($ids === []) {
            throw new \RuntimeException('Debe seleccionar al menos una cuenta por cobrar.');
        }

        $storedPath = $fileComprobante instanceof UploadedFile
            ? $fileComprobante->store('comprobantes-pago/cxc', 'public')
            : $fileComprobante;
        $storedByService = $fileComprobante instanceof UploadedFile;

        try {
            return DB::transaction(function () use ($ids, $pagoData, $storedPath): array {
                $unlocked = DB::table('cuentasporcobrar')->whereIn('idcuentasPorCobrar', $ids)
                    ->orderBy('idcuentasPorCobrar')->get();
                if ($unlocked->count() !== count($ids)) {
                    throw new \RuntimeException('Una de las cuentas por cobrar seleccionadas ya no existe.');
                }

                $clientIds = $unlocked->pluck('cliente_idcliente')->map(fn ($id): string => (string) $id)->unique();
                $currencyIds = $unlocked->pluck('moneda_idmoneda')->map(fn ($id): int => (int) $id)->unique();
                if ($clientIds->count() !== 1 || $currencyIds->count() !== 1) {
                    throw new \RuntimeException('Las cuentas deben pertenecer al mismo cliente y moneda.');
                }

                $clientId = (string) $clientIds->first();
                $currencyId = (int) $currencyIds->first();
                $client = DB::table('cliente')->where('idcliente', $clientId)->lockForUpdate()->first();
                if (!$client) {
                    throw new \RuntimeException('No se encontró el cliente de la cuenta por cobrar.');
                }

                $cxcs = DB::table('cuentasporcobrar')->whereIn('idcuentasPorCobrar', $ids)
                    ->orderBy('idcuentasPorCobrar')->lockForUpdate()->get();
                if ($cxcs->contains(fn ($cxc): bool => (float) $cxc->montoActual <= 0
                    || in_array((string) $cxc->estado, ['3', 'CANCELADO', 'Cancelado Detracción'], true))) {
                    throw new \RuntimeException('Una de las cuentas seleccionadas ya no tiene deuda pendiente.');
                }

                $advancePeriods = (int) ($pagoData['adelanto_meses'] ?? 0);
                $advanceServiceIds = array_values(array_filter(array_map('intval', (array) ($pagoData['servicio_ids'] ?? []))));
                $advancePeriodAmount = 0.0;
                $advanceAlreadyInCxc = false;
                $advanceFrom = null;
                $advanceUntil = null;
                if ($advancePeriods > 0) {
                    $renewalCxcId = (int) ($pagoData['renewal_cxc_id'] ?? $ids[0]);
                    $renewalCxc = $cxcs->firstWhere('idcuentasPorCobrar', $renewalCxcId);
                    $services = DB::table('serviciocliente')->whereIn('idservicioCliente', $advanceServiceIds)->get();
                    $tipoCobro = $renewalCxc
                        ? DB::table('tipocobro')->where('idtipoCobros', $renewalCxc->tipoCobro_idtipoCobros)->first()
                        : null;
                    if (!$renewalCxc || $services->isEmpty() || strtoupper(trim((string) ($tipoCobro->recurrencia ?? ''))) !== 'M'
                        || (int) ($tipoCobro->tiempo ?? 0) <= 0) {
                        throw new \RuntimeException('El adelanto requiere una CXC vinculada a servicios con cobro mensual.');
                    }

                    $currentPeriod = $this->periodFromDescription((string) ($renewalCxc->descripcion ?? ''));
                    $advanceFrom = $renewalCxc->fechaCancelacion ?? $currentPeriod[1];
                    if (!$advanceFrom) {
                        throw new \RuntimeException('No se pudo determinar la fecha final del periodo que se está pagando.');
                    }
                    $advanceUntil = self::advancePeriodEnd(
                        (string) $advanceFrom,
                        $advancePeriods - 1,
                        (int) $tipoCobro->tiempo
                    )->toDateString();
                    $storedAdvancePeriods = self::advanceMonthsFromDescription((string) ($renewalCxc->descripcion ?? ''));
                    if ($storedAdvancePeriods > 1 && $storedAdvancePeriods !== $advancePeriods) {
                        throw new \RuntimeException('La cantidad de mensualidades adelantadas no coincide con la factura.');
                    }
                    $advanceAlreadyInCxc = $storedAdvancePeriods === $advancePeriods;
                    if (!$advanceAlreadyInCxc) {
                        $basePeriodAmount = round((float) ($renewalCxc->montoOriginal ?? $renewalCxc->montoActual), 2);
                        $invoiceAmount = round((float) ($pagoData['monto_factura'] ?? 0), 2);
                        $advancePeriodAmount = $invoiceAmount > 0
                            ? round(max(0, $invoiceAmount - $basePeriodAmount), 2)
                            : round((float) $services->sum('monto') * max(0, $advancePeriods - 1), 2);
                    }
                }

                $currentDebt = round((float) $cxcs->sum('montoActual'), 2);
                $gross = round($currentDebt + $advancePeriodAmount, 2);
                $isRetention = (string) ($client->detraccion ?? '') === '1';
                $availableCredit = $this->saldoFavorDisponible($clientId, $currencyId);
                $useCredit = filter_var($pagoData['usar_saldo_favor'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $mode = ($pagoData['modo_pago'] ?? 'total') === 'parcial' ? 'parcial' : 'total';
                $currencyMode = ($pagoData['payment_currency_mode'] ?? 'same') === 'other' ? 'other' : 'same';
                $withholdingDecision = strtolower(trim((string) ($pagoData['withholding_decision'] ?? '')));
                $preview = self::calculatePaymentBreakdown($gross, $isRetention, $availableCredit, $useCredit, 0);

                if ($currencyMode === 'other') {
                    $received = round((float) ($pagoData['monto_cancelado'] ?? 0), 2);
                    $rate = round((float) ($pagoData['tipo_cambio'] ?? 0), 8);
                    if ($rate <= 0) {
                        throw new \RuntimeException('Debe indicar un tipo de cambio válido.');
                    }
                    $cashReceived = round($received * $rate, 2);
                } else {
                    $rate = 1.0;
                    if ($mode === 'total' && $withholdingDecision === 'full_pending') {
                        $cashReceived = round(max(0, $gross - $preview['credit_applied']), 2);
                    } elseif ($mode === 'total' && $advancePeriods <= 0) {
                        $cashReceived = $preview['cash_due'];
                    } else {
                        $cashReceived = round(max(0, (float) ($pagoData['montoPago'] ?? 0)), 2);
                    }
                }

                $withholdingPending = 0.0;
                $cashHeldForWithholding = 0.0;
                if ($preview['deduction'] > 0 && in_array($withholdingDecision, ['paid', 'pending', 'full_pending'], true)) {
                    if ($withholdingDecision === 'paid') {
                        if ($cashReceived + 0.01 < $preview['cash_due']) {
                            throw new \RuntimeException('Para confirmar la detracción/retención pagada, el monto debe cubrir al menos el neto calculado.');
                        }
                        $breakdown = self::calculatePaymentBreakdown($gross, $isRetention, $availableCredit, $useCredit, $cashReceived);
                    } else {
                        $expectedReceived = $withholdingDecision === 'full_pending'
                            ? round($gross - $preview['credit_applied'], 2)
                            : $preview['cash_due'];
                        if (abs($cashReceived - $expectedReceived) > 0.01) {
                            throw new \RuntimeException('El monto no coincide con la opción de detracción/retención seleccionada.');
                        }

                        $breakdown = self::calculatePaymentBreakdown($gross, $isRetention, $availableCredit, $useCredit, 0, false);
                        $breakdown['deduction'] = 0.0;
                        $breakdown['credit_applied'] = $preview['credit_applied'];
                        $breakdown['cash_due'] = $preview['cash_due'];
                        $breakdown['cash_received'] = $cashReceived;
                        $breakdown['cash_applied'] = $preview['cash_due'];
                        $breakdown['new_credit'] = 0.0;
                        $breakdown['remaining_debt'] = $preview['deduction'];
                        $withholdingPending = $preview['deduction'];
                        $cashHeldForWithholding = $withholdingDecision === 'full_pending'
                            ? $preview['deduction']
                            : 0.0;
                    }
                } elseif ($preview['deduction'] > 0 && $cashReceived + 0.01 >= $preview['cash_due']) {
                    throw new \RuntimeException('Confirma si la detracción/retención ya fue pagada antes de guardar.');
                } else {
                    $breakdown = self::calculatePaymentBreakdown($gross, $isRetention, $availableCredit, $useCredit, $cashReceived, $preview['deduction'] <= 0);
                }

                if ($advancePeriods > 0 && $breakdown['remaining_debt'] > 0) {
                    throw new \RuntimeException('El monto ingresado no cubre las cuentas seleccionadas y los periodos adelantados.');
                }
                if ($advancePeriods > 0 && $breakdown['new_credit'] > 0.01) {
                    throw new \RuntimeException('Con mensualidades adelantadas, el monto debe coincidir con los periodos indicados; el excedente no se registra como saldo a favor.');
                }
                if ($breakdown['deduction'] + $breakdown['credit_applied'] + $breakdown['cash_applied'] <= 0) {
                    throw new \RuntimeException('El monto de pago, detracción/retención y saldo a favor no cubren ninguna parte de la deuda.');
                }
                if ($mode === 'total' && $cashReceived + 0.01 < $preview['cash_due']) {
                    throw new \RuntimeException('El efectivo recibido no cubre el total pendiente después de aplicar detracción/retención y saldo a favor.');
                }
                if ($mode === 'parcial' && $cashReceived <= 0 && $breakdown['remaining_debt'] > 0) {
                    throw new \RuntimeException('Debe indicar el monto en efectivo recibido para el pago parcial.');
                }

                $deductionRemaining = $breakdown['deduction'];
                $creditRemaining = $breakdown['credit_applied'];
                $cashAppliedRemaining = $breakdown['cash_applied'];
                $newCreditRemaining = $breakdown['new_credit'];
                $currentDebtAfterDeduction = max(0, $currentDebt - min($currentDebt, $breakdown['deduction']));
                $currentDebtAfterCredit = max(0, $currentDebtAfterDeduction - min($currentDebtAfterDeduction, $breakdown['credit_applied']));
                $currentCashRequired = min($breakdown['cash_applied'], $currentDebtAfterCredit);
                $advanceCashApplied = $advanceAlreadyInCxc
                    ? 0.0
                    : min($advancePeriodAmount, max(0, $breakdown['cash_applied'] - $currentCashRequired));
                $installments = count($ids) === 1
                    ? $this->buildPaymentInstallments($pagoData, $breakdown['cash_due'])
                    : [];
                $results = [];
                foreach ($cxcs as $index => $cxc) {
                    $debt = round((float) $cxc->montoActual, 2);
                    $deduction = round(min($debt, $deductionRemaining), 2);
                    $deductionRemaining = round($deductionRemaining - $deduction, 2);
                    $afterDeduction = round(max(0, $debt - $deduction), 2);
                    $credit = round(min($afterDeduction, $creditRemaining), 2);
                    $creditRemaining = round($creditRemaining - $credit, 2);
                    $afterCredit = round(max(0, $afterDeduction - $credit), 2);
                    $cashApplied = round(min($afterCredit, $cashAppliedRemaining), 2);
                    $cashAppliedRemaining = round($cashAppliedRemaining - $cashApplied, 2);
                    $excess = $index === $cxcs->count() - 1 ? $newCreditRemaining : 0.0;
                    $isRenewalCxc = (int) $cxc->idcuentasPorCobrar === (int) ($pagoData['renewal_cxc_id'] ?? (count($ids) === 1 ? $ids[0] : 0));
                    $advanceForCxc = $isRenewalCxc ? $advanceCashApplied : 0.0;
                    $pendingWithholdingForCxc = $isRenewalCxc ? $withholdingPending : 0.0;
                    $cashHeldForWithholdingCxc = $isRenewalCxc ? $cashHeldForWithholding : 0.0;
                    if ($deduction + $credit + $cashApplied + $excess + $advanceForCxc + $cashHeldForWithholdingCxc <= 0) {
                        continue;
                    }

                    $cashConverted = round($cashApplied + $advanceForCxc + $excess + $cashHeldForWithholdingCxc, 2);
                    $itemData = array_merge($pagoData, [
                        'payment_currency_mode' => $currencyMode,
                        'tipo_cambio' => $rate,
                        'moneda_idmoneda' => $currencyId,
                        'monto_recibido' => $currencyMode === 'other' && $cashConverted > 0
                            ? round($cashConverted / $rate, 2)
                            : $cashConverted,
                        'monto_convertido' => $cashConverted,
                        'monto_aplicado' => $cashApplied,
                        'monto_deduccion' => $deduction,
                        'monto_saldo_favor_aplicado' => $credit,
                        'monto_excedente' => $excess,
                        'monto_adelantado' => $advanceForCxc,
                        'monto_deduccion_pendiente' => $pendingWithholdingForCxc,
                        'adelanto_hasta' => $isRenewalCxc ? $advanceUntil : null,
                        'adelanto_desde' => $isRenewalCxc ? $advanceFrom : null,
                        'fechaFin' => $isRenewalCxc ? ($pagoData['fechaFin'] ?? null) : null,
                        'montoPago' => $cashApplied,
                        'modo_pago' => $mode,
                        'cuotas' => $installments,
                        'servicio_ids' => $isRenewalCxc
                            ? ($pagoData['servicio_ids'] ?? [])
                            : [],
                    ]);
                    $results[] = $this->procesarPagoCancelacion($cxc->idcuentasPorCobrar, $itemData, $storedPath);
                }

                $breakdown['retencion'] = $isRetention;
                $breakdown['currency_symbol'] = (string) (DB::table('moneda')->where('idmoneda', $currencyId)->value('simbolo') ?: 'S/');
                $breakdown['results'] = $results;
                return $breakdown;
            });
        } catch (\Throwable $exception) {
            if ($storedByService && $storedPath && Storage::disk('public')->exists($storedPath)) {
                Storage::disk('public')->delete($storedPath);
            }
            throw $exception;
        }
    }

    private function buildPaymentInstallments(array $pagoData, float $total): array
    {
        $count = (int) ($pagoData['canCuotas'] ?? 0);
        $formaPagoId = (int) ($pagoData['formaPago_idformaPago'] ?? 0);
        if ($count <= 0 || $formaPagoId <= 0 || $total <= 0) {
            return [];
        }

        $payment = DB::table('formapago')->where('idformaPago', $formaPagoId)->first(['detalle', 'tiempo']);
        if (!$payment || !str_contains(mb_strtolower((string) $payment->detalle, 'UTF-8'), 'credito')) {
            return [];
        }

        $count = max(1, min($count, 60));
        $defaultAmount = round($total / $count, 2);
        $creditDays = max(0, (int) ($payment->tiempo ?? 0));
        $baseDate = Carbon::parse($pagoData['fechaPago'] ?? now()->format('Y-m-d'));
        $amounts = (array) ($pagoData['cuotas_montos'] ?? []);
        $dates = (array) ($pagoData['cuotas_fechas'] ?? []);
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

        if ($remaining !== 0.0 && $installments !== []) {
            $last = array_key_last($installments);
            $installments[$last]['monto'] = round($installments[$last]['monto'] + $remaining, 2);
        }

        return $installments;
    }

    public function procesarPagoCancelacion(int|string $idCxc, array $pagoData, UploadedFile|string|null $fileComprobante = null): array
    {
        return DB::transaction(function () use ($idCxc, $pagoData, $fileComprobante) {
            $cxc = DB::table('cuentasporcobrar')
                ->where('idcuentasPorCobrar', $idCxc)
                ->lockForUpdate()
                ->first();

            if (!$cxc) {
                throw new \RuntimeException('Cuenta por cobrar no encontrada.');
            }

            $entidadBancariaId = (int) ($pagoData['entidadBancaria_identidadBancaria'] ?? 0);
            $formaPagoId = (int) ($pagoData['formaPago_idformaPago'] ?? 0);
            $montoPago = round((float) ($pagoData['montoPago'] ?? $cxc->montoActual), 2);
            $montoDeduccion = round(max(0, (float) ($pagoData['monto_deduccion'] ?? 0)), 2);
            $montoFavorAplicado = round(max(0, (float) ($pagoData['monto_saldo_favor_aplicado'] ?? 0)), 2);
            $docRef = trim((string) ($pagoData['docReferencia'] ?? $cxc->docReferencia ?? ''));
            $descripcion = trim((string) ($pagoData['descripcion'] ?? 'Pago de CXC #' . $idCxc));
            $fechaPago = Carbon::parse($pagoData['fechaPago'] ?? now()->format('Y-m-d'))->format('Y-m-d H:i:s');
            $cuotas = array_values($pagoData['cuotas'] ?? []);

            $deudaActual = round((float) $cxc->montoActual, 2);
            if ($montoPago < 0 || $montoPago > $deudaActual
                || $montoDeduccion + $montoFavorAplicado + $montoPago <= 0
                || $montoDeduccion + $montoFavorAplicado + $montoPago > $deudaActual) {
                throw new \RuntimeException('El monto aplicado a la CXC es inválido.');
            }

            $entidadBancaria = DB::table('entidadbancaria')
                ->where('identidadBancaria', $entidadBancariaId)
                ->first();
            if (!$entidadBancaria) {
                throw new \RuntimeException('La entidad bancaria seleccionada no existe.');
            }

            $monedaBaseId = (int) ($cxc->moneda_idmoneda ?? 1);
            $paymentCurrencyMode = ($pagoData['payment_currency_mode'] ?? 'same') === 'other' ? 'other' : 'same';
            $monedaPagoId = $paymentCurrencyMode === 'other'
                ? (int) ($pagoData['currency_selector'] ?? $pagoData['moneda_idmoneda'] ?? 0)
                : $monedaBaseId;
            if ($monedaPagoId <= 0 && (float) ($pagoData['monto_convertido'] ?? 0) <= 0) {
                $monedaPagoId = $monedaBaseId;
            }
            if ($monedaPagoId <= 0) {
                throw new \RuntimeException('Debe seleccionar la moneda del pago.');
            }

            $montoRecibido = round((float) ($pagoData['monto_recibido'] ?? $pagoData['monto_cancelado'] ?? $montoPago), 2);
            $tipoCambio = $paymentCurrencyMode === 'other'
                ? round((float) ($pagoData['tipo_cambio'] ?? 0), 8)
                : 1.0;
            if ($montoRecibido < 0 || $tipoCambio <= 0) {
                throw new \RuntimeException('El monto recibido y el tipo de cambio deben ser mayores a cero.');
            }

            $montoConvertido = round((float) ($pagoData['monto_convertido'] ?? ($montoRecibido * $tipoCambio)), 2);
            $montoAplicado = round(min($deudaActual, (float) ($pagoData['monto_aplicado'] ?? $montoPago)), 2);
            $montoExcedente = round(max(0, (float) ($pagoData['monto_excedente'] ?? ($montoConvertido - $montoAplicado))), 2);
            $montoAdelantado = round(max(0, (float) ($pagoData['monto_adelantado'] ?? 0)), 2);
            if ($montoConvertido + 0.01 < $montoAplicado + $montoExcedente
                || $montoConvertido + 0.01 < $montoAplicado + $montoExcedente + $montoAdelantado
                || ($montoConvertido <= 0 && $montoRecibido > 0)) {
                throw new \RuntimeException('El monto recibido no coincide con el efectivo aplicado.');
            }
            $montoPago = $montoAplicado;
            $serviceIds = $pagoData['servicio_ids'] ?? null;
            $parsedServiceIds = !empty($serviceIds)
                ? array_values(array_filter(array_map('intval', (array) $serviceIds)))
                : [];

            $servicesList = collect();
            if (!empty($parsedServiceIds)) {
                $servicesList = DB::table('serviciocliente as sc')
                    ->leftJoin('almacen as a', 'a.idalmacen', '=', 'sc.almacen_idalmacen')
                    ->whereIn('sc.idservicioCliente', $parsedServiceIds)
                    ->select(['sc.idservicioCliente', 'sc.monto', 'sc.vehiculo_placa', DB::raw('COALESCE(a.detalle, "Servicio") as servicio_nombre')])
                    ->get();
            }

            $storedPath = null;
            if ($fileComprobante instanceof UploadedFile) {
                $storedPath = $fileComprobante->store('comprobantes-pago/cxc', 'public');
            } elseif (is_string($fileComprobante)) {
                $storedPath = $fileComprobante;
            }

            if ($montoConvertido > 0 && $servicesList->isNotEmpty()) {
                $totalServiciosMonto = round((float) $servicesList->sum('monto'), 2);
                $montoRestante = $montoConvertido;
                $count = $servicesList->count();
                $idx = 0;

                foreach ($servicesList as $srv) {
                    $idx++;
                    if ($idx === $count) {
                        $montoSrv = $montoRestante;
                    } else {
                        $prop = $totalServiciosMonto > 0 ? ((float) $srv->monto / $totalServiciosMonto) : (1 / $count);
                        $montoSrv = round($montoConvertido * $prop, 2);
                        $montoRestante = round($montoRestante - $montoSrv, 2);
                    }

                    $advanceLabel = $montoAdelantado > 0 ? 'Pago adelantado ' . (int) ($pagoData['adelanto_meses'] ?? 0) . ' periodos - ' : 'Pago ';
                    $infoMov = mb_substr($advanceLabel . $srv->servicio_nombre . ($srv->vehiculo_placa ? ' - ' . $srv->vehiculo_placa : '') . ' (' . $descripcion . ')', 0, 100);

                    $idBancoMov = DB::table('bancos')->insertGetId([
                        'entidadBancaria_identidadBancaria' => $entidadBancariaId,
                        'fechaRegistro' => $fechaPago,
                        'informacion' => $infoMov,
                        'monto' => round($montoSrv, 2),
                        'tipoMov' => 'I',
                    ]);

                    if ($storedPath) {
                        DB::table('evidenciapago')->insert([
                            'fechaCarga' => now()->format('Y-m-d H:i:s'),
                            'archivo' => $storedPath,
                            'bancos_idbancos' => $idBancoMov,
                        ]);
                    }

                    DB::table('detallecxc')->insertGetId([
                        'formaPago_idformaPago' => $formaPagoId,
                        'cuentasPorCobrar_idcuentasPorCobrar' => $idCxc,
                        'moneda_idmoneda' => $monedaPagoId,
                        'descripcion' => mb_substr($advanceLabel . $srv->servicio_nombre, 0, 50),
                        'fechaPagoProgramada' => $cuotas[0]['fecha'] ?? $fechaPago,
                        'fechaPago' => $fechaPago,
                        'nCuota' => $cuotas[0]['numero'] ?? null,
                        'bancos_idbancos' => $idBancoMov,
                        'estado' => '1',
                    ]);
                }
            } elseif ($montoConvertido > 0) {
                $idBancoMov = DB::table('bancos')->insertGetId([
                    'entidadBancaria_identidadBancaria' => $entidadBancariaId,
                    'fechaRegistro' => $fechaPago,
                    'informacion' => mb_substr($descripcion, 0, 100),
                    'monto' => $montoConvertido,
                    'tipoMov' => 'I',
                ]);

                if ($storedPath) {
                    DB::table('evidenciapago')->insert([
                        'fechaCarga' => now()->format('Y-m-d H:i:s'),
                        'archivo' => $storedPath,
                        'bancos_idbancos' => $idBancoMov,
                    ]);
                }

                $detallePagoId = DB::table('detallecxc')->insertGetId([
                    'formaPago_idformaPago' => $formaPagoId,
                    'cuentasPorCobrar_idcuentasPorCobrar' => $idCxc,
                    'moneda_idmoneda' => $monedaPagoId,
                    'descripcion' => mb_substr($descripcion, 0, 50),
                    'fechaPagoProgramada' => $cuotas[0]['fecha'] ?? $fechaPago,
                    'fechaPago' => $fechaPago,
                    'nCuota' => $cuotas[0]['numero'] ?? null,
                    'bancos_idbancos' => $idBancoMov,
                    'estado' => '1',
                ]);
            } else {
                $idBancoMov = null;
            }

            foreach ($idBancoMov ? array_slice($cuotas, 1) : [] as $cuota) {
                DB::table('detallecxc')->insert([
                    'formaPago_idformaPago' => $formaPagoId,
                    'cuentasPorCobrar_idcuentasPorCobrar' => $idCxc,
                    'moneda_idmoneda' => $monedaPagoId,
                    'descripcion' => mb_substr('Cuota programada ' . $cuota['numero'], 0, 50),
                    'fechaPagoProgramada' => $cuota['fecha'],
                    'fechaPago' => null,
                    'nCuota' => $cuota['numero'],
                    'bancos_idbancos' => $idBancoMov,
                    'estado' => '0',
                ]);
            }

            $deudaPendiente = round(max(0, $deudaActual - $montoAplicado - $montoDeduccion - $montoFavorAplicado), 2);
            $pagoCompleto = $deudaPendiente <= 0;
            $paymentMethod = DB::table('formapago')->where('idformaPago', $formaPagoId)->value('detalle');
            $isRetention = (string) DB::table('cliente')
                ->where('idcliente', $cxc->cliente_idcliente)
                ->value('detraccion') === '1';
            $period = $this->periodFromDescription((string) ($cxc->descripcion ?? ''));
            $periodEnd = $cxc->fechaCancelacion ?? $period[1] ?? $pagoData['fechaFin'] ?? null;
            $creditInstallments = max(1, (int) ($cxc->canCuotas ?? count($cuotas) ?: 1));
            $paidInstallments = (int) DB::table('detallecxc')
                ->where('cuentasPorCobrar_idcuentasPorCobrar', $idCxc)
                ->where('estado', '1')
                ->whereNotNull('nCuota')
                ->max('nCuota');
            $nextCreditInstallment = $creditInstallments > 1 ? min($creditInstallments, max(1, ($paidInstallments ?? 0) + 1)) : 1;
            $nuevoEstado = self::resolvePaymentState(
                $deudaPendiente,
                $montoDeduccion,
                (string) $paymentMethod,
                $isRetention,
                $periodEnd,
                null,
                $nextCreditInstallment,
                $creditInstallments
            );
            $persistedState = str_contains(mb_strtolower((string) $nuevoEstado, 'UTF-8'), 'pendiente a credito')
                ? 'Pendiente a credito'
                : $nuevoEstado;

            DB::table('cuentasporcobrar')
                ->where('idcuentasPorCobrar', $idCxc)
                ->update([
                    'montoActual' => $deudaPendiente,
                    'canCuotas' => !empty($cuotas) ? count($cuotas) : $cxc->canCuotas,
                    'moneda_idmoneda' => $monedaBaseId,
                    'docReferencia' => $docRef !== '' ? mb_substr($docRef, 0, 15) : $cxc->docReferencia,
                    'estado' => $persistedState,
                ]);

            if ($montoDeduccion > 0) {
                $deductionBankId = DB::table('bancos')->insertGetId([
                    'entidadBancaria_identidadBancaria' => $entidadBancariaId,
                    'fechaRegistro' => $fechaPago,
                    'informacion' => mb_substr('Detracción/retención aplicada a CXC #' . $idCxc, 0, 100),
                    'monto' => $montoDeduccion,
                    'tipoMov' => 'T',
                ]);
                if ($storedPath) {
                    DB::table('evidenciapago')->insert([
                        'fechaCarga' => now()->format('Y-m-d H:i:s'),
                        'archivo' => $storedPath,
                        'bancos_idbancos' => $deductionBankId,
                    ]);
                }
                DB::table('detallecxc')->insert([
                    'formaPago_idformaPago' => $formaPagoId,
                    'cuentasPorCobrar_idcuentasPorCobrar' => $idCxc,
                    'moneda_idmoneda' => $monedaBaseId,
                    'descripcion' => 'Detracción/retención aplicada',
                    'fechaPagoProgramada' => $fechaPago,
                    'fechaPago' => $fechaPago,
                    'nCuota' => null,
                    'bancos_idbancos' => $deductionBankId,
                    'estado' => '1',
                ]);
            }

            if ($montoFavorAplicado > 0) {
                $creditBankId = DB::table('bancos')->insertGetId([
                    'entidadBancaria_identidadBancaria' => $entidadBancariaId,
                    'fechaRegistro' => $fechaPago,
                    'informacion' => mb_substr('Aplicación saldo a favor a CXC #' . $idCxc, 0, 100),
                    'monto' => $montoFavorAplicado,
                    'tipoMov' => 'C',
                ]);
                if ($storedPath) {
                    DB::table('evidenciapago')->insert([
                        'fechaCarga' => now()->format('Y-m-d H:i:s'),
                        'archivo' => $storedPath,
                        'bancos_idbancos' => $creditBankId,
                    ]);
                }
                DB::table('detallecxc')->insert([
                    'formaPago_idformaPago' => $formaPagoId,
                    'cuentasPorCobrar_idcuentasPorCobrar' => $idCxc,
                    'moneda_idmoneda' => $monedaBaseId,
                    'descripcion' => 'Aplicación saldo a favor',
                    'fechaPagoProgramada' => $fechaPago,
                    'fechaPago' => $fechaPago,
                    'nCuota' => null,
                    'bancos_idbancos' => $creditBankId,
                    'estado' => '1',
                ]);
            }

            if ($montoExcedente > 0) {
                $saldoBancoId = DB::table('bancos')->insertGetId([
                    'entidadBancaria_identidadBancaria' => $entidadBancariaId,
                    'fechaRegistro' => $fechaPago,
                    'informacion' => mb_substr('Saldo a favor del cliente por sobrepago de CXC #' . $idCxc, 0, 100),
                    'monto' => $montoExcedente,
                    'tipoMov' => 'A',
                ]);

                DB::table('detallecxc')->insert([
                    'formaPago_idformaPago' => $formaPagoId,
                    'cuentasPorCobrar_idcuentasPorCobrar' => $idCxc,
                    'moneda_idmoneda' => $monedaBaseId,
                    'descripcion' => mb_substr('Saldo a favor por sobrepago: ' . number_format($montoExcedente, 2, '.', ''), 0, 50),
                    'fechaPagoProgramada' => $fechaPago,
                    'fechaPago' => $fechaPago,
                    'nCuota' => null,
                    'bancos_idbancos' => $saldoBancoId,
                    'estado' => 'A',
                ]);

            }

            // 5. Solo un pago completo renueva el servicio y crea el siguiente periodo.
            $serviceIds = $pagoData['servicio_ids'] ?? null;
            if (empty($serviceIds) && !empty($pagoData['servicioCliente_idservicioCliente'])) {
                $serviceIds = [(int) $pagoData['servicioCliente_idservicioCliente']];
            }
            $tipoCobroId = !empty($pagoData['tipoCobro_idtipoCobros'])
                ? (int) $pagoData['tipoCobro_idtipoCobros']
                : null;
            $period = $this->periodFromDescription((string) ($cxc->descripcion ?? ''));
            $periodEnd = $cxc->fechaCancelacion ?? $period[1];
            $renewalCxcId = (int) ($pagoData['renewal_cxc_id'] ?? $idCxc);
            $advanceUntil = $pagoData['adelanto_hasta'] ?? null;
            $advanceMonths = (int) ($pagoData['adelanto_meses'] ?? 0);
            if ($pagoCompleto && (int) $idCxc === $renewalCxcId && $periodEnd) {
                if ($advanceMonths > 1 && $advanceUntil && !empty($parsedServiceIds)) {
                    $this->cancelarCxcsCubiertasPorAdelanto(
                        $cxc,
                        $parsedServiceIds,
                        (string) ($pagoData['adelanto_desde'] ?? $periodEnd),
                        (string) $advanceUntil
                    );
                }
            }
            $nextCxcId = $pagoCompleto
                && (int) $idCxc === $renewalCxcId
                && !empty($serviceIds)
                && self::shouldRenewAfterFullPayment($periodEnd)
                ? $this->renovarServicioVinculado(
                    $cxc,
                    0,
                    Carbon::now(),
                    $serviceIds,
                    $tipoCobroId,
                    $advanceMonths > 1 ? (string) $advanceUntil : null,
                    $advanceMonths > 1,
                    $advanceMonths > 1 ? null : ($pagoData['fechaFin'] ?? null)
                )
                : null;

            return [
                'idcuentasPorCobrar' => $idCxc,
                'montoPagado' => $montoAplicado,
                'montoDeduccion' => $montoDeduccion,
                'montoSaldoFavorAplicado' => $montoFavorAplicado,
                'montoRecibido' => $montoRecibido,
                'montoConvertido' => $montoConvertido,
                'montoExcedente' => $montoExcedente,
                'montoActual' => $deudaPendiente,
                'estado' => $nuevoEstado,
                'renovado' => $pagoCompleto,
                'siguienteCxcId' => $nextCxcId,
            ];
        });
    }

    /**
     * Renovación automática del servicio vinculado y generación de la siguiente CXC 'PENDIENTE'.
     */

    public function renovarServicioVinculado(object $cxc, float $deudaPendiente = 0, mixed $fechaProceso = null, array|int|string|null $servicioIds = null, ?int $tipoCobroId = null, ?string $periodEndOverride = null, bool $preserveServiceEnd = false, ?string $nextPeriodEndOverride = null): ?int
    {
        $fechaProceso = $fechaProceso !== null
            ? Carbon::parse($fechaProceso)
            : Carbon::now();

        $parsedServiceIds = [];
        if (is_array($servicioIds)) {
            $parsedServiceIds = array_values(array_filter(array_map('intval', $servicioIds)));
        } elseif ($servicioIds !== null && (int) $servicioIds > 0) {
            $parsedServiceIds = [(int) $servicioIds];
        }

        $serviciosQuery = DB::table('serviciocliente as sc')
            ->leftJoin('almacen as a', 'a.idalmacen', '=', 'sc.almacen_idalmacen')
            ->where('sc.cliente_idcliente', $cxc->cliente_idcliente)
            ->where('sc.estado', 'activo');

        if (!empty($parsedServiceIds)) {
            $serviciosQuery->whereIn('sc.idservicioCliente', $parsedServiceIds);
        } else {
            preg_match('/SC-([0-9,]+)/', (string) ($cxc->descripcion ?? ''), $serviceMatch);
            $serviceIdsFromDescription = isset($serviceMatch[1])
                ? array_values(array_filter(array_map('intval', explode(',', $serviceMatch[1]))))
                : [];
            if ($serviceIdsFromDescription === []) {
                return null;
            }
            $serviciosQuery->whereIn('sc.idservicioCliente', $serviceIdsFromDescription);
        }

        $servicios = $serviciosQuery
            ->select(['sc.*', 'a.periodo as servicio_periodo'])
            ->orderBy('sc.fecheVencimiento')
            ->get();

        if ($servicios->isEmpty()) {
            return null;
        }

        $serviceTipoCobroId = $this->tipoCobroIdDesdeServicio([
            'servicio_periodo' => $servicios->first()->servicio_periodo ?? null,
        ]);
        $resolvedTipoCobroId = $serviceTipoCobroId > 0
            ? $serviceTipoCobroId
            : ($tipoCobroId ?? $cxc->tipoCobro_idtipoCobros ?? null);
        $tipoCobro = $resolvedTipoCobroId
            ? DB::table('tipocobro')->where('idtipoCobros', $resolvedTipoCobroId)->first()
            : null;

        $periodos = [];
        $currentPeriod = $this->periodFromDescription((string) ($cxc->descripcion ?? ''));
        $nextStart = $periodEndOverride
            ?? $cxc->fechaCancelacion
            ?? ($currentPeriod[1] ?? ($servicios->first()->fecheVencimiento ?? $fechaProceso->format('Y-m-d')));
        $nextStart = Carbon::parse($nextStart)->format('Y-m-d');
        foreach ($servicios as $servicio) {
            $rec = strtoupper(trim((string) ($tipoCobro->recurrencia ?? '')));
            $tiempo = (int) ($tipoCobro->tiempo ?? 0);

            if ($tipoCobro && $rec === 'M' && $tiempo > 0) {
                $nuevaFechaInicio = Carbon::parse($nextStart)->format('Y-m-d');
                $nuevaFechaVencimiento = Carbon::parse($nextStart)->addMonthsNoOverflow($tiempo)->format('Y-m-d');
            } elseif ($tipoCobro && $rec === 'D' && $tiempo > 0) {
                $nuevaFechaInicio = Carbon::parse($nextStart)->format('Y-m-d');
                $nuevaFechaVencimiento = Carbon::parse($nextStart)->addDays($tiempo)->format('Y-m-d');
            } else {
                $diasPeriodo = is_numeric($servicio->servicio_periodo ?? null)
                    ? max((int) $servicio->servicio_periodo, 1)
                    : 30;
                $nuevaFechaInicio = Carbon::parse($nextStart)->format('Y-m-d');
                $nuevaFechaVencimiento = Carbon::parse($nextStart)->addDays($diasPeriodo)->format('Y-m-d');
            }

            if ($nextPeriodEndOverride) {
                $nuevaFechaVencimiento = Carbon::parse($nextPeriodEndOverride)->format('Y-m-d');
            }

            $periodos[] = [$nuevaFechaInicio, $nuevaFechaVencimiento];
        }

        $fechaInicio = collect($periodos)->min(fn(array $periodo) => $periodo[0]);
        $fechaFin = collect($periodos)->max(fn(array $periodo) => $periodo[1]);
        $montoServicios = round((float) $servicios->sum('monto'), 2);
        $serviceMarker = 'SC-' . implode(',', $servicios->pluck('idservicioCliente')->all());
        $nextRange = Carbon::parse($fechaInicio)->format('d/m/Y') . ' a ' . Carbon::parse($fechaFin)->format('d/m/Y');
        $existingPeriod = DB::table('cuentasporcobrar')
            ->where('cliente_idcliente', $cxc->cliente_idcliente)
            ->where('moneda_idmoneda', $cxc->moneda_idmoneda)
            ->where('descripcion', 'like', '%' . $nextRange . '%')
            ->whereIn('estado', ['1', '2', '3', '4', 'PENDIENTE', 'FACTURADO', 'CANCELADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito', 'Cancelado Detracción'])
            ->value('idcuentasPorCobrar');
        if ($existingPeriod) {
            DB::table('serviciocliente')
                ->whereIn('idservicioCliente', $servicios->pluck('idservicioCliente')->all())
                ->update(['fecheVencimiento' => $preserveServiceEnd && $periodEndOverride ? $periodEndOverride : $fechaFin]);

            return (int) $existingPeriod;
        }

        if ($nextPeriodEndOverride) {
            $successorPrefix = 'REN-CXC-' . $cxc->idcuentasPorCobrar . ' P ';
            $existingSuccessor = DB::table('cuentasporcobrar')
                ->where('cliente_idcliente', $cxc->cliente_idcliente)
                ->where('moneda_idmoneda', $cxc->moneda_idmoneda)
                ->where('descripcion', 'like', $successorPrefix . '%')
                ->whereIn('estado', ['1', '2', '4', 'PENDIENTE', 'FACTURADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito'])
                ->orderByDesc('idcuentasPorCobrar')
                ->first();
            if ($existingSuccessor) {
                $marker = 'REN-CXC-' . $cxc->idcuentasPorCobrar;
                $description = mb_substr(
                    $marker . ' P ' . Carbon::parse($fechaInicio)->format('d/m/Y') . ' a ' . Carbon::parse($fechaFin)->format('d/m/Y') . ' ' . $serviceMarker,
                    0,
                    50
                );
                DB::table('cuentasporcobrar')
                    ->where('idcuentasPorCobrar', $existingSuccessor->idcuentasPorCobrar)
                    ->update(['descripcion' => $description, 'fechaCancelacion' => $fechaFin]);
                DB::table('serviciocliente')
                    ->whereIn('idservicioCliente', $servicios->pluck('idservicioCliente')->all())
                    ->update(['fecheVencimiento' => $fechaFin]);

                return (int) $existingSuccessor->idcuentasPorCobrar;
            }
        }

        $marker = 'REN-CXC-' . $cxc->idcuentasPorCobrar;
        $descripcion = mb_substr(
            $marker . ' P ' . Carbon::parse($fechaInicio)->format('d/m/Y') . ' a ' . Carbon::parse($fechaFin)->format('d/m/Y') . ' ' . $serviceMarker,
            0,
            50
        );

        $nextCxcId = $this->crearCobroDesdeServicio([
            'cliente_idcliente' => $cxc->cliente_idcliente,
            'montoServicioBase' => $montoServicios,
            'moneda_idmoneda' => $cxc->moneda_idmoneda,
            'tipoCobro_idtipoCobros' => $resolvedTipoCobroId ?? $cxc->tipoCobro_idtipoCobros,
            'descripcion' => $descripcion,
            'vehiculo_placa' => $servicios->first()->vehiculo_placa ?? '',
            'idservicioCliente' => $servicios->first()->idservicioCliente ?? null,
            'fechaInicio' => $fechaInicio,
            'fecheVencimiento' => $fechaFin,
            'docReferencia' => null,
        ]);

        DB::table('serviciocliente')
            ->whereIn('idservicioCliente', $servicios->pluck('idservicioCliente')->all())
            ->update(['fecheVencimiento' => $preserveServiceEnd && $periodEndOverride ? $periodEndOverride : $fechaFin]);

        return $nextCxcId;
    }

    private function cancelarCxcsCubiertasPorAdelanto(object $currentCxc, array $serviceIds, string $periodEnd, string $advanceEnd): void
    {
        $tipoCobro = DB::table('tipocobro')->where('idtipoCobros', $currentCxc->tipoCobro_idtipoCobros)->first();
        $intervalMonths = max(1, (int) ($tipoCobro->tiempo ?? 1));
        $coveredEndDates = [];
        $periodCursor = Carbon::parse($periodEnd)->startOfDay();
        $advanceEndDate = Carbon::parse($advanceEnd)->startOfDay();
        while ($periodCursor->lt($advanceEndDate)) {
            $periodCursor = $periodCursor->copy()->addMonthsNoOverflow($intervalMonths);
            if (!$periodCursor->gt($advanceEndDate)) {
                $coveredEndDates[] = $periodCursor->toDateString();
            }
        }

        $candidates = DB::table('cuentasporcobrar')
            ->where('cliente_idcliente', $currentCxc->cliente_idcliente)
            ->where('moneda_idmoneda', $currentCxc->moneda_idmoneda)
            ->where('tipoCobro_idtipoCobros', $currentCxc->tipoCobro_idtipoCobros)
            ->where('idcuentasPorCobrar', '!=', $currentCxc->idcuentasPorCobrar)
            ->whereIn('estado', ['1', '2', '4', 'PENDIENTE', 'FACTURADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito'])
            ->get(['idcuentasPorCobrar', 'descripcion', 'fechaCancelacion']);

        $currentEnd = Carbon::parse($periodEnd)->startOfDay();
        foreach ($candidates as $candidate) {
            $hasServiceMarker = preg_match('/SC-([0-9,]+)/', (string) $candidate->descripcion, $match) === 1;
            if ($hasServiceMarker) {
                $candidateServiceIds = array_map('intval', explode(',', $match[1]));
                if (array_intersect($serviceIds, $candidateServiceIds) === []) {
                    continue;
                }
            }
            $period = $this->periodFromDescription((string) $candidate->descripcion);
            if ($period) {
                $start = Carbon::parse($period[0])->startOfDay();
                $end = Carbon::parse($period[1])->startOfDay();
                $isCovered = !$start->lt($currentEnd) && !$end->gt($advanceEndDate);
            } else {
                $candidateEnd = $candidate->fechaCancelacion
                    ? Carbon::parse($candidate->fechaCancelacion)->toDateString()
                    : '';
                $isCovered = !$hasServiceMarker && in_array($candidateEnd, $coveredEndDates, true);
            }
            if ($isCovered) {
                DB::table('cuentasporcobrar')
                    ->where('idcuentasPorCobrar', $candidate->idcuentasPorCobrar)
                    ->update(['estado' => 'CANCELADO', 'montoActual' => 0]);
            }
        }
    }

    public function generarPeriodosAutomaticos(?Carbon $hoy = null): int
    {
        $hoy ??= Carbon::today();
        $generados = 0;
        $this->verificarYActualizarVencidos($hoy);
        $servicios = DB::table('serviciocliente as sc')
            ->leftJoin('almacen as a', 'a.idalmacen', '=', 'sc.almacen_idalmacen')
            ->whereRaw('LOWER(COALESCE(sc.estado, "")) = ?', ['activo'])
            ->whereNotNull('sc.fechaInicio')
            ->whereNotNull('sc.fecheVencimiento')
            ->select('sc.*', 'a.periodo as servicio_periodo')
            ->get()
            ->groupBy('cliente_idcliente');

        foreach ($servicios as $clientServices) {
            foreach ($clientServices->groupBy(fn($service) => implode('|', [
                (string) ($service->moneda_idmoneda ?? ''),
                Carbon::parse($service->fechaInicio)->format('Y-m-d'),
                Carbon::parse($service->fecheVencimiento)->format('Y-m-d'),
                trim((string) ($service->servicio_periodo ?? '')),
            ])) as $serviceGroup) {
                $ids = $serviceGroup->pluck('idservicioCliente')->all();
                $first = $serviceGroup->first();
                $originalRange = Carbon::parse($first->fechaInicio)->format('d/m/Y') . ' a ' . Carbon::parse($first->fecheVencimiento)->format('d/m/Y');
                $latest = DB::table('cuentasporcobrar')
                    ->where('cliente_idcliente', $first->cliente_idcliente)
                    ->where('moneda_idmoneda', $first->moneda_idmoneda)
                    ->where(function ($query) use ($ids, $originalRange) {
                        $query->where('descripcion', 'like', '%' . $originalRange . '%');
                        foreach ($ids as $id) {
                            $query->orWhere('descripcion', 'like', '%SC-' . $id . '%');
                        }
                    })
                    ->orderByRaw('CASE WHEN descripcion LIKE ? THEN 0 ELSE 1 END', ['%' . $originalRange . '%'])
                    ->orderByDesc('idcuentasPorCobrar')
                    ->first();

                if (!$latest) {
                    continue;
                }

                $guard = 0;
                while ($guard++ < 24) {
                    $period = $this->periodFromDescription((string) ($latest->descripcion ?? ''));
                    $periodEnd = $latest->fechaCancelacion ?? ($period[1] ?? $first->fecheVencimiento);
                    if (!self::shouldGenerateNextPeriod((string) ($latest->estado ?? ''), $periodEnd, $hoy)) {
                        break;
                    }

                    $nextId = $this->renovarServicioVinculado(
                        $latest,
                        0,
                        Carbon::parse($periodEnd),
                        $ids,
                        (int) ($latest->tipoCobro_idtipoCobros ?? 0)
                    );
                    if (!$nextId || $nextId === (int) $latest->idcuentasPorCobrar) {
                        break;
                    }
                    $latest = DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $nextId)->first();
                    $generados++;
                }
            }
        }

        return $generados;
    }

    public function revertirPagoCancelado(int|string $idCxc, string $usuario, string $motivo): array
    {
        return DB::transaction(function () use ($idCxc, $usuario, $motivo): array {
            $cxc = DB::table('cuentasporcobrar')
                ->where('idcuentasPorCobrar', $idCxc)
                ->lockForUpdate()
                ->first();

            if (!$cxc) {
                throw new \RuntimeException('Cuenta por cobrar no encontrada.');
            }

            if (!in_array((string) $cxc->estado, ['3', 'CANCELADO', 'Cancelado Detracción'], true)) {
                throw new \RuntimeException('Solo se pueden revertir cuentas canceladas.');
            }

            $cancelledAt = DB::table('detallecxc')
                ->where('cuentasPorCobrar_idcuentasPorCobrar', $idCxc)
                ->where('estado', '1')
                ->whereNotNull('fechaPago')
                ->max('fechaPago');
            if (!$cancelledAt) {
                throw new \RuntimeException('No se encontró una fecha de pago activa para revertir.');
            }
            $cancelledAt = Carbon::parse($cancelledAt);
            if (Carbon::now()->startOfDay()->gt($cancelledAt->copy()->addDays(7)->startOfDay())) {
                throw new \RuntimeException('El plazo de reversión de 7 días ya venció.');
            }

            $paymentDetails = DB::table('detallecxc as dc')
                ->join('bancos as b', 'b.idbancos', '=', 'dc.bancos_idbancos')
                ->where('dc.cuentasPorCobrar_idcuentasPorCobrar', $idCxc)
                ->whereNotNull('dc.fechaPago')
                ->where('dc.estado', '1')
                ->whereIn('b.tipoMov', ['I', 'T', 'C'])
                ->where('b.informacion', 'not like', '[ANULADO]%')
                ->select(['dc.iddetalleCXC', 'dc.formaPago_idformaPago', 'dc.moneda_idmoneda', 'b.idbancos', 'b.monto', 'b.entidadBancaria_identidadBancaria'])
                ->get();

            if ($paymentDetails->isEmpty()) {
                throw new \RuntimeException('No se encontró un pago activo para revertir.');
            }

            $reason = trim($motivo) !== '' ? trim($motivo) : 'Reversión de pago dentro del plazo permitido';
            foreach ($paymentDetails as $detail) {
                DB::table('bancos')->where('idbancos', $detail->idbancos)->update([
                    'informacion' => mb_substr('[ANULADO] ' . $reason . ' | ' . (string) DB::table('bancos')->where('idbancos', $detail->idbancos)->value('informacion'), 0, 100),
                ]);

                DB::table('detallecxc')->where('iddetalleCXC', $detail->iddetalleCXC)->update([
                    'estado' => '0',
                ]);

            }

            $creditDetails = DB::table('detallecxc as dc')
                ->join('bancos as b', 'b.idbancos', '=', 'dc.bancos_idbancos')
                ->where('dc.cuentasPorCobrar_idcuentasPorCobrar', $idCxc)
                ->where('b.tipoMov', 'A')
                ->where('b.informacion', 'not like', '[ANULADO]%')
                ->select(['dc.iddetalleCXC', 'dc.formaPago_idformaPago', 'dc.moneda_idmoneda', 'b.idbancos', 'b.monto', 'b.entidadBancaria_identidadBancaria'])
                ->get();

            foreach ($creditDetails as $credit) {
                DB::table('bancos')->where('idbancos', $credit->idbancos)->update([
                    'informacion' => mb_substr('[ANULADO] ' . $reason . ' | ' . (string) DB::table('bancos')->where('idbancos', $credit->idbancos)->value('informacion'), 0, 100),
                ]);

                DB::table('detallecxc')->where('iddetalleCXC', $credit->iddetalleCXC)->update([
                    'estado' => '0',
                ]);

            }

            $period = $this->periodFromDescription((string) ($cxc->descripcion ?? ''));
            $periodEnd = $cxc->fechaCancelacion ?? $period[1];
            $restoredState = $periodEnd && Carbon::parse($periodEnd)->startOfDay()->lt(Carbon::today()) ? '4' : '1';
            DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $idCxc)->update([
                'montoActual' => $cxc->montoOriginal,
                'estado' => $restoredState,
                'docReferencia' => null,
                'docfactura' => null,
            ]);

            $serviceQuery = DB::table('serviciocliente')
                ->where('cliente_idcliente', $cxc->cliente_idcliente);
            if ($cxc->docReferencia === null || trim((string) $cxc->docReferencia) === '') {
                $serviceQuery->whereNull('docReferencia');
            } else {
                $serviceQuery->where('docReferencia', $cxc->docReferencia);
            }
            $serviceQuery->update(['docReferencia' => null]);

            return [
                'idcuentasPorCobrar' => $idCxc,
                'estado' => $restoredState,
                'montoActual' => (float) $cxc->montoOriginal,
            ];
        });
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

    private function estadoSegunFechaCorte(?string $fechaFinPeriodo): string
    {
        if (!$fechaFinPeriodo) {
            return '1';
        }

        return Carbon::parse($fechaFinPeriodo)->startOfDay()->lt(Carbon::today()) ? '4' : '1';
    }

    private function serviceDevice(int $serviceId): ?string
    {
        return DB::table('detalle_serviciodispositivo')
            ->where('servicioCliente_idservicioCliente', $serviceId)
            ->orderByDesc('iddetalle_serviciodispositivo')
            ->value('dispositivoCliente_iddispositivoCliente');
    }

    private function servicePhone(int $serviceId): ?string
    {
        $deviceId = $this->serviceDevice($serviceId);
        if (!$deviceId) {
            return null;
        }

        return DB::table('detnumerosdispositivo')
            ->where('dispositivoCliente_iddispositivoCliente', $deviceId)
            ->orderByDesc('fechaAsignacion')
            ->orderByDesc('iddetNumerosDispositivo')
            ->value('numeroTelefonico_numeroTelefonico');
    }

    private function clienteTienePeriodoVencido(string $clienteId): bool
    {
        return DB::table('serviciocliente')
            ->where('cliente_idcliente', $clienteId)
            ->where('estado', 'activo')
            ->whereNotNull('fecheVencimiento')
            ->whereDate('fecheVencimiento', '<', Carbon::today())
            ->exists();
    }

    public function tipoCobroIdDesdeServicio(array $dataServicio): int
    {
        $periodo = $dataServicio['periodo'] ?? $dataServicio['servicio_periodo'] ?? null;

        if ($periodo === null && !empty($dataServicio['almacen_idalmacen'])) {
            $periodo = DB::table('almacen')
                ->where('idalmacen', $dataServicio['almacen_idalmacen'])
                ->value('periodo');
        }

        $meses = match (mb_strtolower(trim((string) $periodo), 'UTF-8')) {
            'mensual', '1 mes', '1 meses' => 1,
            '3 meses' => 3,
            '6 meses' => 6,
            '12 meses' => 12,
            '24 meses' => 24,
            '36 meses' => 36,
            '48 meses' => 48,
            default => null,
        };

        if ($meses === null && is_numeric($periodo)) {
            $meses = match ((int) $periodo) {
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

        if ($meses === null) {
            return 0;
        }

        return (int) (DB::table('tipocobro')
            ->whereRaw('UPPER(TRIM(recurrencia)) = ?', ['M'])
            ->where('tiempo', $meses)
            ->value('idtipoCobros') ?? 0);
    }

}
