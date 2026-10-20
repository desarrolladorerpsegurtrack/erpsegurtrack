<?php

namespace App\Services;

use App\Support\ServicePeriod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CxcService
{
    private const SCHEDULED_DEACTIVATION_MARKER = '[BAJA_PROGRAMADA:';
    private const CXC_SERVICE_LINK_TABLE = 'cuentasporcobrar_serviciocliente';

    public static function convertPaymentAmountToDebtCurrency(
        float $paymentAmount,
        float $exchangeRate,
        int $debtCurrencyId,
        int $paymentCurrencyId
    ): float {
        if ($debtCurrencyId <= 0 || $paymentCurrencyId <= 0 || $debtCurrencyId === $paymentCurrencyId) {
            throw new \RuntimeException('La moneda de pago debe ser distinta a la moneda de la deuda.');
        }
        if ($exchangeRate <= 0) {
            throw new \RuntimeException('Debe indicar un tipo de cambio válido.');
        }

        $currencies = DB::table('moneda')
            ->whereIn('idmoneda', [$debtCurrencyId, $paymentCurrencyId])
            ->get(['idmoneda', 'detalle', 'simbolo'])
            ->keyBy('idmoneda');
        $debtCurrency = $currencies->get($debtCurrencyId);
        $paymentCurrency = $currencies->get($paymentCurrencyId);
        if (!$debtCurrency || !$paymentCurrency) {
            throw new \RuntimeException('No se encontraron las monedas seleccionadas.');
        }

        $isSol = static function (object $currency): bool {
            $description = Str::ascii(mb_strtolower((string) ($currency->detalle ?? ''), 'UTF-8'));
            $symbol = Str::ascii(mb_strtolower(trim((string) ($currency->simbolo ?? '')), 'UTF-8'));

            return in_array($symbol, ['s/', 's/.', 's'], true)
                || preg_match('/\bsol(es)?\b|\bpen\b/i', $description) === 1;
        };

        $convertedAmount = $isSol($paymentCurrency)
            ? $paymentAmount / $exchangeRate
            : $paymentAmount * $exchangeRate;

        return round($convertedAmount, 2);
    }

    public function darDeBajaServicios(
        array $serviceIds,
        string $mode,
        string $comment,
        string $keepSim,
        string $user,
        ?Carbon $today = null
    ): int {
        $today ??= Carbon::today();
        $serviceIds = array_values(array_unique(array_map('intval', $serviceIds)));

        return DB::transaction(function () use ($serviceIds, $mode, $comment, $keepSim, $user, $today): int {
            $processed = 0;
            foreach (DB::table('serviciocliente')->whereIn('idservicioCliente', $serviceIds)->lockForUpdate()->get() as $service) {
                if (strtolower((string) $service->estado) !== 'activo') {
                    continue;
                }

                $serviceEnd = $mode === 'periodo' && $service->fecheVencimiento
                    ? Carbon::parse($service->fecheVencimiento)->startOfDay()
                    : $today->copy()->startOfDay();
                $this->adjustOpenChargesForServiceDeactivation($service, $serviceEnd, $mode === 'ahora');

                if ($mode === 'periodo' && $serviceEnd->gt($today->copy()->startOfDay())) {
                    $this->scheduleServiceDeactivation($service, $serviceEnd, $comment, $keepSim, $user);
                } else {
                    $this->deactivateService($service, $comment, $keepSim, $user);
                }

                $processed++;
            }

            return $processed;
        });
    }

    public function procesarBajasProgramadas(?Carbon $today = null): int
    {
        $today ??= Carbon::today();
        $processed = 0;
        $scheduledServices = DB::table('serviciocliente')
            ->whereRaw('LOWER(COALESCE(estado, "")) = ?', ['activo'])
            ->where('observacion', 'like', '%' . self::SCHEDULED_DEACTIVATION_MARKER . '%')
            ->lockForUpdate()
            ->get();

        foreach ($scheduledServices as $service) {
            if (!preg_match(
                '/\[BAJA_PROGRAMADA:fecha=(\d{4}-\d{2}-\d{2});mantener_sim=(si|no)\]/',
                (string) ($service->observacion ?? ''),
                $scheduled
            )) {
                continue;
            }

            if (Carbon::parse($scheduled[1])->startOfDay()->gt($today->copy()->startOfDay())) {
                continue;
            }

            DB::transaction(function () use ($service, $scheduled, $today): void {
                $lockedService = DB::table('serviciocliente')
                    ->where('idservicioCliente', $service->idservicioCliente)
                    ->lockForUpdate()
                    ->first();
                if (!$lockedService || strtolower((string) $lockedService->estado) !== 'activo') {
                    return;
                }

                $comment = DB::table('historial_servicio')
                    ->where('servicioCliente_idservicioCliente', $lockedService->idservicioCliente)
                    ->where('motivo', 'Baja programada al finalizar periodo')
                    ->orderByDesc('idhistorial_servicio')
                    ->value('descripcion') ?? 'Servicio dado de baja desde Cuentas por Cobrar';
                $comment = preg_replace('/\s+\(Mantener SIM\/Número: (Sí|No)\)$/u', '', trim((string) $comment)) ?? trim((string) $comment);
                $this->deactivateService(
                    $lockedService,
                    $comment,
                    $scheduled[2],
                    (string) (DB::table('historial_servicio')
                        ->where('servicioCliente_idservicioCliente', $lockedService->idservicioCliente)
                        ->where('motivo', 'Baja programada al finalizar periodo')
                        ->orderByDesc('idhistorial_servicio')
                        ->value('usuario_usuario') ?? 'anonimo')
                );
            });
            $processed++;
        }

        return $processed;
    }

    private function scheduleServiceDeactivation(object $service, Carbon $serviceEnd, string $comment, string $keepSim, string $user): void
    {
        $observacion = (string) ($service->observacion ?? '');
        $observacion = preg_replace('/\s*\[BAJA_PROGRAMADA:fecha=\d{4}-\d{2}-\d{2};mantener_sim=(?:si|no)\]/', '', $observacion) ?? $observacion;
        $marker = sprintf(
            '[BAJA_PROGRAMADA:fecha=%s;mantener_sim=%s]',
            $serviceEnd->format('Y-m-d'),
            $keepSim
        );
        DB::table('serviciocliente')
            ->where('idservicioCliente', $service->idservicioCliente)
            ->update(['observacion' => trim($observacion . ' ' . $marker)]);

        DB::table('historial_servicio')->insert([
            'usuario_usuario' => $user,
            'serv_usuario' => $user,
            'servicioCliente_idservicioCliente' => $service->idservicioCliente,
            'fecha_accion' => Carbon::now()->format('Y-m-d H:i:s'),
            'motivo' => 'Baja programada al finalizar periodo',
            'descripcion' => $comment . ' (Mantener SIM/Número: ' . ($keepSim === 'si' ? 'Sí' : 'No') . ')',
            'doc_referencia' => $service->docReferencia ?? null,
            'dispositivo' => null,
            'numerotelefono' => null,
        ]);
    }

    private function deactivateService(object $service, string $comment, string $keepSim, string $user): void
    {
        $serviceId = (int) $service->idservicioCliente;
        $observacion = (string) ($service->observacion ?? '');
        $observacion = trim(preg_replace('/\s*\[BAJA_PROGRAMADA:fecha=\d{4}-\d{2}-\d{2};mantener_sim=(?:si|no)\]/', '', $observacion) ?? $observacion);
        DB::table('serviciocliente')->where('idservicioCliente', $serviceId)->update([
            'estado' => 'inactivo',
            'observacion' => $observacion !== '' ? $observacion : null,
        ]);

        $deviceInfo = DB::table('detalle_serviciodispositivo')
            ->where('servicioCliente_idservicioCliente', $serviceId)
            ->orderByDesc('iddetalle_serviciodispositivo')
            ->first();
        $deviceId = $deviceInfo->dispositivoCliente_iddispositivoCliente ?? null;
        $phoneNumber = null;

        if ($deviceId) {
            $phoneNumber = DB::table('detnumerosdispositivo')
                ->where('dispositivoCliente_iddispositivoCliente', $deviceId)
                ->orderByDesc('fechaAsignacion')
                ->value('numeroTelefonico_numeroTelefonico');
            DB::table('dispositivocliente')
                ->where('iddispositivoCliente', $deviceId)
                ->update(['estado' => '0', 'fechaBaja' => Carbon::now()->format('Y-m-d H:i:s')]);

            $stockDevice = DB::table('elementoalmacen')->where('imei', $deviceId)->first();
            if ($stockDevice) {
                DB::table('elementoalmacen')
                    ->where('imei', $deviceId)
                    ->update(['estado' => $this->stateAfterServiceDeactivation((int) $stockDevice->estado)]);
            }
        }

        if ($phoneNumber) {
            $activeSimPair = DB::table('detallesimcard')
                ->where('numeroTelefonico_numeroTelefonico', $phoneNumber)
                ->where('estado', '0')
                ->first();
            if ($keepSim === 'no') {
                if ($activeSimPair) {
                    DB::table('detallesimcard')
                        ->where('iddetalleSimCard', $activeSimPair->iddetalleSimCard)
                        ->update(['estado' => '1']);
                    DB::table('simcard')
                        ->where('idsimCard', $activeSimPair->simCard_idsimCard)
                        ->where('estado', '!=', '0')
                        ->update(['estado' => '1']);
                }
                DB::table('numerotelefonico')
                    ->where('numeroTelefonico', $phoneNumber)
                    ->where('estado', '!=', '0')
                    ->update(['estado' => '1']);
            } else {
                DB::table('numerotelefonico')
                    ->where('numeroTelefonico', $phoneNumber)
                    ->where('estado', '!=', '0')
                    ->update(['estado' => '2']);
                if ($activeSimPair) {
                    DB::table('simcard')
                        ->where('idsimCard', $activeSimPair->simCard_idsimCard)
                        ->where('estado', '!=', '0')
                        ->update(['estado' => '2']);
                }
            }

            if ($deviceId) {
                DB::table('detnumerosdispositivo')
                    ->where('dispositivoCliente_iddispositivoCliente', $deviceId)
                    ->where('numeroTelefonico_numeroTelefonico', $phoneNumber)
                    ->delete();
            }
        }

        DB::table('historial_servicio')->insert([
            'usuario_usuario' => $user,
            'serv_usuario' => $user,
            'servicioCliente_idservicioCliente' => $serviceId,
            'fecha_accion' => Carbon::now()->format('Y-m-d H:i:s'),
            'motivo' => 'Dado de baja',
            'descripcion' => $comment . ' (Mantener SIM/Número: ' . ($keepSim === 'si' ? 'Sí' : 'No') . ')',
            'doc_referencia' => $service->docReferencia ?? null,
            'dispositivo' => $deviceId,
            'numerotelefono' => $phoneNumber,
        ]);
    }

    private function adjustOpenChargesForServiceDeactivation(
        object $service,
        Carbon $serviceEnd,
        bool $includeServiceEnd
    ): void
    {
        $openStates = ['1', '2', '4', 'NUEVO', 'PagoCredito', 'PENDIENTE', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito'];
        $charges = DB::table('cuentasporcobrar')
            ->where('cliente_idcliente', $service->cliente_idcliente)
            ->where('moneda_idmoneda', $service->moneda_idmoneda)
            ->whereIn('estado', $openStates)
            ->whereRaw('montoActual = montoOriginal')
            ->whereNull('docReferencia')
            ->whereDate('fechaCancelacion', $includeServiceEnd ? '>=' : '>', $serviceEnd->toDateString())
            ->lockForUpdate()
            ->get();
        $hasInvoiceFile = Schema::hasColumn('cuentasporcobrar', 'docFactura');

        foreach ($charges as $charge) {
            if ($hasInvoiceFile && trim((string) ($charge->docFactura ?? '')) !== '') {
                continue;
            }
            if (preg_match('/SC-([0-9,]+)/', (string) $charge->descripcion, $matches) !== 1) {
                continue;
            }
            $serviceIds = array_values(array_unique(array_map('intval', explode(',', $matches[1]))));
            if (!in_array((int) $service->idservicioCliente, $serviceIds, true)) {
                continue;
            }
            $remainingIds = array_values(array_filter($serviceIds, fn(int $id): bool => $id !== (int) $service->idservicioCliente));
            $remainingServices = $remainingIds === []
                ? collect()
                : DB::table('serviciocliente')
                    ->whereIn('idservicioCliente', $remainingIds)
                    ->whereRaw('LOWER(COALESCE(estado, "")) = ?', ['activo'])
                    ->where('moneda_idmoneda', $service->moneda_idmoneda)
                    ->get(['idservicioCliente', 'monto', 'observacion']);
            $period = $this->periodFromDescription((string) $charge->descripcion);
            $chargeEnd = $charge->fechaCancelacion
                ? Carbon::parse($charge->fechaCancelacion)->startOfDay()
                : (isset($period[1]) ? Carbon::parse($period[1])->startOfDay() : null);
            $remainingServices = $remainingServices->filter(function (object $remaining) use ($chargeEnd): bool {
                if (!$chargeEnd || preg_match(
                    '/\[BAJA_PROGRAMADA:fecha=(\d{4}-\d{2}-\d{2});mantener_sim=(?:si|no)\]/',
                    (string) ($remaining->observacion ?? ''),
                    $scheduled
                ) !== 1) {
                    return true;
                }

                return Carbon::parse($scheduled[1])->startOfDay()->gte($chargeEnd);
            });
            $remainingIds = $remainingServices->pluck('idservicioCliente')->map(fn($id): int => (int) $id)->all();
            $remainingAmount = round((float) $remainingServices->sum('monto'), 2);
            if ($remainingIds === [] || $remainingAmount <= 0) {
                DB::table('cuentasporcobrar')
                    ->where('idcuentasPorCobrar', $charge->idcuentasPorCobrar)
                    ->update(['estado' => 'CANCELADO', 'montoActual' => 0]);
                continue;
            }

            $description = (string) $charge->descripcion;
            $range = preg_match('/\d{2}\/\d{2}\/\d{4}\s+a\s+\d{2}\/\d{2}\/\d{4}/', $description, $rangeMatch)
                ? $rangeMatch[0]
                : '';
            $prefix = $range !== '' ? mb_substr($description, 0, mb_strpos($description, $range, 0, 'UTF-8'), 'UTF-8') : '';
            $newDescription = mb_substr(
                $prefix . $range . ' SC-' . implode(',', $remainingIds),
                0,
                50
            );
            $newBalance = max(0, round((float) $charge->montoActual - (float) $service->monto, 2));
            DB::table('cuentasporcobrar')
                ->where('idcuentasPorCobrar', $charge->idcuentasPorCobrar)
                ->update([
                    'descripcion' => $newDescription,
                    'montoOriginal' => $remainingAmount,
                    'montoActual' => min($newBalance, $remainingAmount),
                ]);
        }
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

    public function ensureQuotationReceivable(string $quoteId): int
    {
        return DB::transaction(function () use ($quoteId): int {
            $quote = DB::table('cotizacion')
                ->where('nroCotizacion', $quoteId)
                ->lockForUpdate()
                ->first(['nroCotizacion', 'estado', 'cliente_idcliente', 'moneda_idmoneda', 'total', 'fechaHoraEmision']);

            if (!$quote || (string) $quote->estado !== '1') {
                return 0;
            }

            $marker = 'COTIZACION:' . $quoteId;
            $cxc = DB::table('cuentasporcobrar')
                ->where('descripcion', $marker)
                ->lockForUpdate()
                ->first(['idcuentasPorCobrar']);

            if (!$cxc) {
                $cxc = DB::table('cuentasporcobrar')
                    ->where('cliente_idcliente', $quote->cliente_idcliente)
                    ->where('moneda_idmoneda', $quote->moneda_idmoneda)
                    ->where(function ($query) use ($quoteId): void {
                        $query->where('descripcion', $quoteId)
                            ->orWhere('descripcion', 'like', '% ' . $quoteId);
                    })
                    ->orderByDesc('idcuentasPorCobrar')
                    ->lockForUpdate()
                    ->first(['idcuentasPorCobrar']);
            }

            if ($cxc) {
                DB::table('cuentasporcobrar')
                    ->where('idcuentasPorCobrar', $cxc->idcuentasPorCobrar)
                    ->update(['descripcion' => $marker]);

                return (int) $cxc->idcuentasPorCobrar;
            }

            $data = [
                'cliente_idcliente' => $quote->cliente_idcliente,
                'tipoCobro_idtipoCobros' => 1,
                'moneda_idmoneda' => $quote->moneda_idmoneda,
                'docReferencia' => null,
                'descripcion' => $marker,
                'montoOriginal' => (float) ($quote->total ?? 0),
                'montoActual' => (float) ($quote->total ?? 0),
                'canCuotas' => null,
                'fechaRegistro' => $quote->fechaHoraEmision ?? now()->format('Y-m-d H:i:s'),
                'fechaCancelacion' => null,
                'estado' => 'NUEVO',
            ];

            if (DB::getSchemaBuilder()->hasColumn('cuentasporcobrar', 'docFactura')) {
                $data['docFactura'] = null;
            }

            return (int) DB::table('cuentasporcobrar')->insertGetId($data);
        });
    }

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
                    ->whereIn('estado', ['1', '2', '3', '4', 'NUEVO', 'PagoCredito', 'PENDIENTE', 'FACTURADO', 'CANCELADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito', 'Cancelado Detracción', 'Pendiente Autodetracción', 'Pendiente Autoretención', 'Pendiente a Detracción', 'Pendiente a Retención'])
                    ->value('idcuentasPorCobrar');
                if ($existingId) {
                    return (int) $existingId;
                }
            } elseif ($serviceId > 0) {
                $existingQuery = DB::table('cuentasporcobrar')
                    ->where('cliente_idcliente', $clienteId)
                    ->where('montoOriginal', $montoServicio)
                    ->whereIn('estado', ['1', '2', '3', '4', 'NUEVO', 'PagoCredito', 'PENDIENTE', 'FACTURADO', 'CANCELADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito', 'Cancelado Detracción', 'Pendiente Autodetracción', 'Pendiente Autoretención', 'Pendiente a Detracción', 'Pendiente a Retención']);
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
                    ->whereIn('estado', ['1', '2', '3', '4', 'NUEVO', 'PagoCredito', 'PENDIENTE', 'FACTURADO', 'CANCELADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito', 'Cancelado Detracción', 'Pendiente Autodetracción', 'Pendiente Autoretención', 'Pendiente a Detracción', 'Pendiente a Retención']);

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

    public function registrarCxcsFaltantesServicios(?int $serviceId = null, bool $execute = false): array
    {
        $query = DB::table('serviciocliente')
            ->whereRaw('LOWER(TRIM(COALESCE(estado, ""))) = ?', ['activo'])
            ->whereNotNull('fechaInicio')
            ->whereNotNull('fecheVencimiento')
            ->orderBy('idservicioCliente');

        if ($serviceId !== null) {
            $query->where('idservicioCliente', $serviceId);
        }

        $summary = [
            'eligible' => 0,
            'created' => 0,
            'updated' => 0,
            'existing' => 0,
            'ambiguous' => 0,
            'to_create' => 0,
            'to_update' => 0,
            'service_ids' => [],
            'ambiguous_service_ids' => [],
            'skipped_reason' => null,
        ];

        $query->chunkById(200, function ($services) use (&$summary, $execute): void {
            foreach ($services as $service) {
                $summary['eligible']++;
                $match = $this->findInitialServiceCharge($service);

                if ($match['status'] === 'existing') {
                    $summary['existing']++;
                    continue;
                }
                if ($match['status'] === 'ambiguous') {
                    $summary['ambiguous']++;
                    $summary['ambiguous_service_ids'][] = (int) $service->idservicioCliente;
                    continue;
                }

                $summary['service_ids'][] = (int) $service->idservicioCliente;
                if (!$execute) {
                    $summary[$match['status'] === 'undated' ? 'to_update' : 'to_create']++;
                    continue;
                }

                $created = DB::transaction(function () use ($service): string {
                    $lockedService = DB::table('serviciocliente')
                        ->where('idservicioCliente', $service->idservicioCliente)
                        ->lockForUpdate()
                        ->first();
                    if (
                        !$lockedService
                        || strtolower(trim((string) $lockedService->estado)) !== 'activo'
                        || empty($lockedService->fechaInicio)
                        || empty($lockedService->fecheVencimiento)
                    ) {
                        return 'existing';
                    }

                    $currentMatch = $this->findInitialServiceCharge($lockedService);
                    if ($currentMatch['status'] === 'existing' || $currentMatch['status'] === 'ambiguous') {
                        return $currentMatch['status'];
                    }
                    if ($currentMatch['status'] === 'undated') {
                        $period = Carbon::parse($lockedService->fechaInicio)->format('d/m/Y')
                            . ' a ' . Carbon::parse($lockedService->fecheVencimiento)->format('d/m/Y');
                        DB::table('cuentasporcobrar')
                            ->where('idcuentasPorCobrar', $currentMatch['charge_id'])
                            ->update([
                                'fechaCancelacion' => Carbon::parse($lockedService->fecheVencimiento)->toDateString(),
                                'descripcion' => 'CXC ' . $period . ' SC-' . $lockedService->idservicioCliente,
                            ]);

                        return 'updated';
                    }

                    $this->crearCobroDesdeServicio([
                        'cliente_idcliente' => $lockedService->cliente_idcliente,
                        'monto' => $lockedService->monto,
                        'moneda_idmoneda' => $lockedService->moneda_idmoneda,
                        'tipoCobro_idtipoCobros' => $lockedService->tipoCobro_idtipoCobros,
                        'fechaInicio' => $lockedService->fechaInicio,
                        'fecheVencimiento' => $lockedService->fecheVencimiento,
                        'idservicioCliente' => $lockedService->idservicioCliente,
                        'vehiculo_placa' => $lockedService->vehiculo_placa ?? '',
                        'docReferencia' => $lockedService->docReferencia ?? null,
                    ]);

                    return 'created';
                });

                if ($created === 'created') {
                    $summary['created']++;
                } elseif ($created === 'updated') {
                    $summary['updated']++;
                } elseif ($created === 'ambiguous') {
                    $summary['ambiguous']++;
                    $summary['ambiguous_service_ids'][] = (int) $service->idservicioCliente;
                } else {
                    $summary['existing']++;
                }
            }
        }, 'idservicioCliente');

        if ($serviceId !== null && $summary['eligible'] === 0) {
            $service = DB::table('serviciocliente')
                ->where('idservicioCliente', $serviceId)
                ->first(['estado', 'fechaInicio', 'fecheVencimiento']);

            $summary['skipped_reason'] = match (true) {
                !$service => 'No existe un servicio con ese ID en esta base de datos.',
                strtolower(trim((string) $service->estado)) !== 'activo' => 'El servicio no está activo.',
                empty($service->fechaInicio) => 'El servicio no tiene fecha de inicio.',
                empty($service->fecheVencimiento) => 'El servicio todavía no tiene fecha fin.',
                default => 'El servicio no cumple los criterios de selección.',
            };
        }

        return $summary;
    }

    private function findInitialServiceCharge(object $service): array
    {
        $serviceId = (int) $service->idservicioCliente;
        $period = Carbon::parse($service->fechaInicio)->format('d/m/Y')
            . ' a ' . Carbon::parse($service->fecheVencimiento)->format('d/m/Y');
        $endDate = Carbon::parse($service->fecheVencimiento)->toDateString();
        $markerPattern = '/(^|[^A-Za-z0-9])SC-' . $serviceId . '([^0-9]|$)/';

        $charges = DB::table('cuentasporcobrar')
            ->where('cliente_idcliente', $service->cliente_idcliente)
            ->where('moneda_idmoneda', $service->moneda_idmoneda)
            ->where(function ($query) use ($endDate, $period): void {
                $query->whereDate('fechaCancelacion', $endDate)
                    ->orWhere('descripcion', 'like', '%' . $period . '%')
                    ->orWhereNull('fechaCancelacion');
            })
            ->get(['idcuentasPorCobrar', 'descripcion', 'fechaCancelacion', 'montoOriginal', 'montoActual', 'estado', 'docReferencia']);

        $marked = $charges->filter(static fn(object $charge): bool =>
            preg_match($markerPattern, (string) $charge->descripcion) === 1
        );
        $currentMarked = $marked->contains(static fn(object $charge): bool =>
            $charge->fechaCancelacion === $endDate
            || str_contains((string) $charge->descripcion, $period)
        );
        if ($currentMarked) {
            return ['status' => 'existing'];
        }

        $undatedMarked = $marked->filter(static fn(object $charge): bool =>
            $charge->fechaCancelacion === null
            && preg_match('/\d{2}\/\d{2}\/\d{4}\s+a\s+\d{2}\/\d{2}\/\d{4}/', (string) $charge->descripcion) !== 1
        );
        $editableUndatedMarked = $undatedMarked->filter(static fn(object $charge): bool =>
            in_array((string) $charge->estado, ['1', '4', 'NUEVO', 'PENDIENTE', 'VENCIDO'], true)
            && (float) $charge->montoActual > 0
            && abs((float) $charge->montoActual - (float) $charge->montoOriginal) < 0.005
        );
        if ($editableUndatedMarked->count() === 1 && $undatedMarked->count() === 1) {
            return [
                'status' => 'undated',
                'charge_id' => (int) $editableUndatedMarked->first()->idcuentasPorCobrar,
            ];
        }
        if ($undatedMarked->isNotEmpty()) {
            return ['status' => 'ambiguous'];
        }

        $legacyMatches = $charges->filter(static fn(object $charge): bool =>
            preg_match('/SC-[0-9]+/', (string) $charge->descripcion) !== 1
            && str_contains((string) $charge->descripcion, $period)
            && abs((float) $charge->montoOriginal - (float) $service->monto) < 0.005
            && (string) ($charge->docReferencia ?? '') === (string) ($service->docReferencia ?? '')
        );

        return match ($legacyMatches->count()) {
            0 => ['status' => 'missing'],
            1 => ['status' => 'existing'],
            default => ['status' => 'ambiguous'],
        };
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
        if (!$newEnd || !$newRange) {
            return false;
        }

        $service = DB::table('serviciocliente')
            ->where('idservicioCliente', $serviceId)
            ->first();

        if (!$service) {
            return false;
        }

        if (!$oldEnd) {
            if (strtolower(trim((string) $service->estado)) !== 'activo') {
                return false;
            }

            $markerPattern = '/(^|[^A-Za-z0-9])SC-' . $serviceId . '([^0-9]|$)/';
            $openStates = ['1', '4', 'NUEVO', 'PENDIENTE', 'VENCIDO'];
            $isEditableCharge = static fn(object $charge): bool =>
                in_array((string) $charge->estado, $openStates, true)
                && (float) $charge->montoActual > 0
                && abs((float) $charge->montoActual - (float) $charge->montoOriginal) < 0.005;
            $undatedCharges = DB::table('cuentasporcobrar')
                ->where('cliente_idcliente', $service->cliente_idcliente)
                ->where('moneda_idmoneda', $service->moneda_idmoneda)
                ->whereNull('fechaCancelacion')
                ->get([
                    'idcuentasPorCobrar',
                    'descripcion',
                    'docReferencia',
                    'estado',
                    'montoOriginal',
                    'montoActual',
                ])
                ->filter(function (object $charge) use ($markerPattern, $oldStart): bool {
                    if (preg_match($markerPattern, (string) $charge->descripcion) !== 1) {
                        return false;
                    }
                    $period = $this->periodFromDescription((string) $charge->descripcion);
                    return $period === [] || ($oldStart !== null && $period[0] === $oldStart);
                });

            if ($undatedCharges->count() > 1) {
                throw new \RuntimeException('ambiguous_service_cxc');
            }

            $undatedCharge = $undatedCharges->first();
            if ($undatedCharge && !$isEditableCharge($undatedCharge)) {
                return false;
            }

            if (!$undatedCharge) {
                $legacyCharges = DB::table('cuentasporcobrar')
                    ->where('cliente_idcliente', $service->cliente_idcliente)
                    ->where('moneda_idmoneda', $service->moneda_idmoneda)
                    ->whereNull('fechaCancelacion')
                    ->get([
                        'idcuentasPorCobrar',
                        'descripcion',
                        'docReferencia',
                        'estado',
                        'montoOriginal',
                        'montoActual',
                    ])
                    ->filter(function (object $charge) use ($service, $oldStart): bool {
                        $description = (string) $charge->descripcion;
                        if (
                            preg_match('/SC-[0-9]+/', $description) === 1
                            || abs((float) $charge->montoOriginal - (float) $service->monto) >= 0.005
                            || (string) ($charge->docReferencia ?? '') !== (string) ($service->docReferencia ?? '')
                        ) {
                            return false;
                        }

                        $period = $this->periodFromDescription($description);
                        if ($period !== []) {
                            return $oldStart !== null && $period[0] === $oldStart;
                        }

                        $vehiclePlate = trim((string) ($service->vehiculo_placa ?? ''));
                        return $vehiclePlate !== '' && stripos($description, $vehiclePlate) !== false;
                    });

                if ($legacyCharges->count() > 1) {
                    throw new \RuntimeException('ambiguous_service_cxc');
                }

                $undatedCharge = $legacyCharges->first();
                if ($undatedCharge && !$isEditableCharge($undatedCharge)) {
                    return false;
                }
            }

            if ($undatedCharge) {
                $description = $this->descriptionWithUpdatedServicePeriod(
                    (string) $undatedCharge->descripcion,
                    $newRange,
                    $serviceId
                );
                DB::table('cuentasporcobrar')
                    ->where('idcuentasPorCobrar', $undatedCharge->idcuentasPorCobrar)
                    ->update([
                        'fechaCancelacion' => $newEnd,
                        'descripcion' => $description,
                    ]);

                return true;
            }

            return $this->crearCobroDesdeServicio([
                'cliente_idcliente' => $service->cliente_idcliente,
                'monto' => $service->monto,
                'moneda_idmoneda' => $service->moneda_idmoneda,
                'tipoCobro_idtipoCobros' => $service->tipoCobro_idtipoCobros ?? null,
                'fechaInicio' => $newStart,
                'fecheVencimiento' => $newEnd,
                'idservicioCliente' => $serviceId,
                'vehiculo_placa' => $service->vehiculo_placa ?? '',
                'docReferencia' => $service->docReferencia ?? null,
            ]) > 0;
        }

        $openStates = ['1', '4', 'NUEVO', 'PagoCredito', 'PENDIENTE', 'VENCIDO'];
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
     * FASE 1.5: Solo las cuentas pendientes vencidas pasan a VENCIDO.
     */
    public function verificarYActualizarVencidos(?Carbon $hoy = null): int
    {
        $updatedCount = 0;
        $hoy ??= Carbon::today();
        $pendingStates = ['1', 'PENDIENTE'];

        $pendientes = DB::table('cuentasporcobrar as c')
            ->select([
                'c.idcuentasPorCobrar',
                'c.fechaRegistro',
                'c.fechaCancelacion',
                'c.descripcion',
            ])
            ->whereIn('c.estado', $pendingStates)
            ->where('c.montoActual', '>', 0)
            ->get();

        foreach ($pendientes as $row) {
            $periodo = $this->periodFromDescription((string) ($row->descripcion ?? ''));
            $fechaLimite = $periodo[1] ?? $row->fechaCancelacion;

            if ($fechaLimite && Carbon::parse($fechaLimite)->startOfDay()->lt($hoy->copy()->startOfDay())) {
                $updatedCount += DB::table('cuentasporcobrar')
                    ->where('idcuentasPorCobrar', $row->idcuentasPorCobrar)
                    ->whereIn('estado', $pendingStates)
                    ->update(['estado' => '4']);
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
        bool $applyDeduction = true,
        bool $allowDeductionBelowThreshold = false
    ): array {
        $grossAmount = round(max(0, $grossAmount), 2);
        $deductionRate = $isRetention ? 0.03 : 0.12;
        $deduction = $applyDeduction && ($allowDeductionBelowThreshold || $grossAmount >= 700)
            ? round($grossAmount * $deductionRate)
            : 0.0;
        $afterDeduction = round($grossAmount - $deduction, 2);
        $creditApplied = $useCredit ? round(min($afterDeduction, max(0, $availableCredit)), 2) : 0.0;
        $cashDue = round(max(0, $afterDeduction - $creditApplied), 2);
        $cashApplied = round(min($cashDue, max(0, $cashReceived)), 2);
        $grossCashDue = round(max(0, $grossAmount - $creditApplied), 2);
        $newCredit = round(max(0, $cashReceived - $grossCashDue), 2);

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
        ?int $totalInstallments = null,
        bool $isPartialPayment = false
    ): string {
        if (round(max(0, $remainingDebt), 2) <= 0) {
            return '3';
        }

        $method = mb_strtolower(trim($paymentMethod), 'UTF-8');
        if (str_contains($method, 'credito') || str_contains($method, 'crédito')) {
            return self::creditInstallmentLabel($currentInstallment, $totalInstallments);
        }

        if ($isPartialPayment) {
            return 'Pendiente Pago parcial';
        }

        $today ??= Carbon::today();
        if ($periodEnd && Carbon::parse($periodEnd)->startOfDay()->lt($today->copy()->startOfDay())) {
            return '4';
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
            return mb_substr($description, 0, 100);
        }

        $suffix = ' - ' . $months;
        $prefix = mb_substr($description, 0, max(0, 100 - mb_strlen($suffix, 'UTF-8')), 'UTF-8');

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

    public function notasCreditoDisponibles(string $clientId, int $currencyId): array
    {
        if ($clientId === '' || $currencyId <= 0) {
            return [];
        }

        $applied = DB::table('nota_credito_aplicacion')
            ->select('nota_credito_idnotacredito')
            ->where('estado', '1')
            ->groupBy('nota_credito_idnotacredito')
            ->selectRaw('SUM(monto) as monto_aplicado');

        return DB::table('nota_credito as nc')
            ->leftJoinSub($applied, 'aplicada', function ($join): void {
                $join->on('aplicada.nota_credito_idnotacredito', '=', 'nc.idnotacredito');
            })
            ->where('nc.cliente_idcliente', $clientId)
            ->where('nc.moneda_idmoneda', $currencyId)
            ->where('nc.estado', 'E')
            ->whereRaw('nc.monto > COALESCE(aplicada.monto_aplicado, 0)')
            ->select([
                'nc.idnotacredito',
                'nc.docReferencia',
                'nc.monto',
                'nc.descripcion',
                'nc.fechaEmision',
            ])
            ->selectRaw('ROUND(nc.monto - COALESCE(aplicada.monto_aplicado, 0), 2) as monto_disponible')
            ->orderBy('nc.fechaEmision')
            ->orderBy('nc.idnotacredito')
            ->get()
            ->map(fn ($note): array => [
                'idnotacredito' => (int) $note->idnotacredito,
                'docReferencia' => trim((string) ($note->docReferencia ?? '')),
                'descripcion' => trim((string) ($note->descripcion ?? '')),
                'fechaEmision' => $note->fechaEmision,
                'monto_disponible' => (float) $note->monto_disponible,
            ])
            ->all();
    }

    private function lockAvailableCreditNotes(array $noteIds, string $clientId, int $currencyId): array
    {
        $ids = collect($noteIds)
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        if (count($ids) > 1) {
            throw new \RuntimeException('Solo se puede aplicar una nota de crédito por cobro.');
        }
        if ($ids === []) {
            return [];
        }

        $notes = DB::table('nota_credito')
            ->whereIn('idnotacredito', $ids)
            ->orderBy('idnotacredito')
            ->lockForUpdate()
            ->get();
        if ($notes->count() !== count($ids)) {
            throw new \RuntimeException('Una de las notas de crédito seleccionadas ya no existe.');
        }

        $appliedAmounts = DB::table('nota_credito_aplicacion')
            ->whereIn('nota_credito_idnotacredito', $ids)
            ->where('estado', '1')
            ->select('nota_credito_idnotacredito')
            ->groupBy('nota_credito_idnotacredito')
            ->selectRaw('SUM(monto) as monto_aplicado')
            ->pluck('monto_aplicado', 'nota_credito_idnotacredito');

        $result = [];
        foreach ($notes as $note) {
            if (
                (string) $note->cliente_idcliente !== $clientId
                || (int) $note->moneda_idmoneda !== $currencyId
                || (string) $note->estado !== 'E'
            ) {
                throw new \RuntimeException('Las notas seleccionadas deben estar emitidas y corresponder al cliente y moneda del cobro.');
            }

            $available = round((float) $note->monto - (float) ($appliedAmounts[$note->idnotacredito] ?? 0), 2);
            if ($available <= 0) {
                throw new \RuntimeException('Una de las notas de crédito seleccionadas ya no tiene saldo disponible.');
            }
            $result[] = [
                'idnotacredito' => (int) $note->idnotacredito,
                'monto_disponible' => $available,
            ];
        }

        return $result;
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
        $withholdingProof = $pagoData['withholding_proof'] ?? null;
        $withholdingProofPath = $withholdingProof instanceof UploadedFile
            ? $withholdingProof->store('comprobantes-pago/cxc/detraccion-retencion', 'public')
            : null;
        if ($withholdingProof instanceof UploadedFile && !$withholdingProofPath) {
            throw new \RuntimeException('No se pudo guardar el comprobante de detracción/retención.');
        }
        $storedWithholdingProof = $withholdingProof instanceof UploadedFile;

        try {
            return DB::transaction(function () use ($ids, $pagoData, $storedPath, $withholdingProofPath): array {
                $unlocked = DB::table('cuentasporcobrar')->whereIn('idcuentasPorCobrar', $ids)
                    ->orderBy('idcuentasPorCobrar')->get();
                if ($unlocked->count() !== count($ids)) {
                    throw new \RuntimeException('Una de las cuentas por cobrar seleccionadas ya no existe.');
                }
                $quotationOperation = ($pagoData['origen_pago'] ?? '') === 'cotizacion';
                $quotationReceivables = $unlocked->filter(
                    fn ($cxc): bool => str_starts_with((string) ($cxc->descripcion ?? ''), 'COTIZACION:')
                );
                if (
                    ($quotationOperation && ($quotationReceivables->count() !== count($ids) || count($ids) !== 1))
                    || (!$quotationOperation && $quotationReceivables->isNotEmpty())
                ) {
                    throw new \RuntimeException('Las cuentas de cotización y de servicios deben procesarse por separado.');
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
                    || in_array((string) $cxc->estado, ['3', 'CANCELADO', 'Cancelado Detracción', 'Pendiente Autodetracción', 'Pendiente Autoretención', 'Pendiente a Detracción', 'Pendiente a Retención'], true))) {
                    throw new \RuntimeException('Una de las cuentas seleccionadas ya no tiene deuda pendiente.');
                }
                $paymentMethod = DB::table('formapago')
                    ->where('idformaPago', (int) ($pagoData['formaPago_idformaPago'] ?? 0))
                    ->value('detalle');
                if (
                    $cxcs->count() > 1
                    && (int) ($pagoData['canCuotas'] ?? 0) > 1
                    && str_contains(Str::ascii(mb_strtolower((string) $paymentMethod, 'UTF-8')), 'credito')
                ) {
                    throw new \RuntimeException('Un plan de crédito en cuotas debe registrarse para una sola cuenta por cobrar.');
                }

                $groupServiceIds = array_values(array_unique(array_filter(array_map(
                    'intval',
                    (array) ($pagoData['servicio_ids'] ?? [])
                ))));
                $serviceAmountsById = $groupServiceIds === []
                    ? []
                    : DB::table('serviciocliente')
                        ->whereIn('idservicioCliente', $groupServiceIds)
                        ->pluck('monto', 'idservicioCliente')
                        ->map(fn ($amount): float => (float) $amount)
                        ->all();
                $serviceIdsByCxc = [];
                $assignedServiceIds = [];
                foreach ($cxcs as $groupCxc) {
                    $groupCxcId = (int) $groupCxc->idcuentasPorCobrar;
                    $serviceIdsByCxc[$groupCxcId] = $this->serviceIdsForCxc(
                        $groupCxc,
                        $groupServiceIds,
                        $serviceAmountsById,
                        $assignedServiceIds,
                        $cxcs->count() === 1
                    );
                    $assignedServiceIds = array_values(array_unique(array_merge(
                        $assignedServiceIds,
                        $serviceIdsByCxc[$groupCxcId]
                    )));
                }
                $renewalCxcId = (int) ($pagoData['renewal_cxc_id'] ?? 0);
                $renewalCxcIds = array_fill_keys(array_values(array_unique(array_filter(array_map(
                    'intval',
                    (array) ($pagoData['renewal_cxc_ids'] ?? $ids)
                )))), true);
                if ($renewalCxcId > 0 && isset($serviceIdsByCxc[$renewalCxcId])) {
                    $serviceIdsByCxc[$renewalCxcId] = array_values(array_unique(array_merge(
                        $serviceIdsByCxc[$renewalCxcId],
                        array_diff($groupServiceIds, $assignedServiceIds)
                    )));
                }
                $this->persistCxcServiceLinks($serviceIdsByCxc, $serviceAmountsById);

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
                    $advanceFrom = $renewalCxc->fechaCancelacion ?? ($currentPeriod[1] ?? null);
                    if (!$advanceFrom) {
                        throw new \RuntimeException('No se pudo determinar la fecha final del periodo que se está pagando.');
                    }
                    $advanceUntil = self::advancePeriodEnd(
                        (string) $advanceFrom,
                        $advancePeriods,
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
                $isRetention = $quotationOperation
                    && array_key_exists('is_retention_por_tipo_servicio', $pagoData)
                    ? filter_var($pagoData['is_retention_por_tipo_servicio'], FILTER_VALIDATE_BOOLEAN)
                    : (string) ($client->detraccion ?? '') === '1';
                $applyDeduction = filter_var($pagoData['aplicar_deduccion'] ?? true, FILTER_VALIDATE_BOOLEAN);
                $selectedCreditNotes = $this->lockAvailableCreditNotes(
                    (array) ($pagoData['nota_credito_ids'] ?? []),
                    $clientId,
                    $currencyId
                );
                $selectedNoteCredit = round((float) collect($selectedCreditNotes)->sum('monto_disponible'), 2);
                $useCredit = filter_var($pagoData['usar_saldo_favor'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $availableBankCredit = 0.0;
                if ($useCredit) {
                    $availableBankCredit = $this->saldoFavorDisponible($clientId, $currencyId);
                    if (isset($pagoData['monto_saldo_favor']) && is_numeric($pagoData['monto_saldo_favor'])) {
                        $requestedBankCredit = round(max(0, (float) $pagoData['monto_saldo_favor']), 2);
                        $availableBankCredit = min($availableBankCredit, $requestedBankCredit);
                    }
                }
                $availableCredit = round($availableBankCredit + $selectedNoteCredit, 2);
                $applyCredit = $useCredit || $selectedNoteCredit > 0;
                $mode = ($pagoData['modo_pago'] ?? 'total') === 'parcial' ? 'parcial' : 'total';
                $currencyMode = ($pagoData['payment_currency_mode'] ?? 'same') === 'other' ? 'other' : 'same';
                $withholdingDecision = strtolower(trim((string) ($pagoData['withholding_decision'] ?? '')));
                $serviceGrossRetentionPayment = !$quotationOperation
                    && $mode === 'total'
                    && $isRetention
                    && $withholdingDecision === 'full_pending';
                $applyDeductionToPayment = $applyDeduction
                    && !$serviceGrossRetentionPayment
                    && ($quotationOperation || $mode === 'total' || $withholdingDecision !== '');
                $preview = self::calculatePaymentBreakdown(
                    $gross,
                    $isRetention,
                    $availableCredit,
                    $applyCredit,
                    0,
                    $applyDeductionToPayment,
                    $quotationOperation
                );

                if ($currencyMode === 'other') {
                    $received = round((float) ($pagoData['monto_cancelado'] ?? 0), 2);
                    $rate = round((float) ($pagoData['tipo_cambio'] ?? 0), 8);
                    $paymentCurrencyId = (int) ($pagoData['currency_selector'] ?? 0);
                    $cashReceived = self::convertPaymentAmountToDebtCurrency(
                        $received,
                        $rate,
                        $currencyId,
                        $paymentCurrencyId
                    );
                } else {
                    $rate = 1.0;
                    if ($mode === 'total' && $withholdingDecision === 'full_pending') {
                        $cashReceived = round(max(0, $gross - $preview['credit_applied']), 2);
                    } elseif ($mode === 'total' && $advancePeriods <= 0) {
                        $cashReceived = round(max(0, (float) (
                            $pagoData['montoPago']
                            ?? $pagoData['monto_factura']
                            ?? $preview['cash_due']
                        )), 2);
                    } else {
                        $cashReceived = round(max(0, (float) ($pagoData['montoPago'] ?? 0)), 2);
                    }
                }

                if (
                    $preview['deduction'] > 0
                    && ($mode === 'total' || $withholdingDecision !== '')
                    && !($mode === 'parcial' && $withholdingDecision === 'paid')
                    && $cashReceived + 0.01 < $preview['cash_due']
                ) {
                    throw new \RuntimeException('El monto pagado debe cubrir al menos el neto calculado.');
                }

                $withholdingPending = 0.0;
                $cashHeldForWithholding = 0.0;
                if ($preview['deduction'] > 0 && in_array($withholdingDecision, ['paid', 'pending', 'full_pending'], true)) {
                    if ($withholdingDecision === 'paid') {
                        $breakdown = self::calculatePaymentBreakdown(
                            $gross,
                            $isRetention,
                            $availableCredit,
                            $applyCredit,
                            $cashReceived,
                            $applyDeductionToPayment,
                            $quotationOperation
                        );
                    } else {
                        $expectedReceived = $withholdingDecision === 'full_pending'
                            ? round($gross - $preview['credit_applied'], 2)
                            : $preview['cash_due'];
                        if (abs($cashReceived - $expectedReceived) > 0.01) {
                            throw new \RuntimeException('El monto no coincide con la opción de detracción/retención seleccionada.');
                        }

                        $breakdown = self::calculatePaymentBreakdown(
                            $gross,
                            $isRetention,
                            $availableCredit,
                            $applyCredit,
                            0,
                            false,
                            $quotationOperation
                        );
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
                    $breakdown = self::calculatePaymentBreakdown(
                        $gross,
                        $isRetention,
                        $availableCredit,
                        $applyCredit,
                        $cashReceived,
                        $applyDeductionToPayment,
                        $quotationOperation
                    );
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
                $noteCreditRemaining = min($breakdown['credit_applied'], $selectedNoteCredit);
                $noteBalancesById = collect($selectedCreditNotes)
                    ->mapWithKeys(fn (array $note): array => [
                        $note['idnotacredito'] => $note['monto_disponible'],
                    ])
                    ->all();
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
                    $noteCredit = round(min($credit, $noteCreditRemaining), 2);
                    $noteCreditRemaining = round($noteCreditRemaining - $noteCredit, 2);
                    $bankCredit = round($credit - $noteCredit, 2);
                    $afterCredit = round(max(0, $afterDeduction - $credit), 2);
                    $cashApplied = round(min($afterCredit, $cashAppliedRemaining), 2);
                    $cashAppliedRemaining = round($cashAppliedRemaining - $cashApplied, 2);
                    $excess = $index === $cxcs->count() - 1 ? $newCreditRemaining : 0.0;
                    $isRenewalCxc = (int) $cxc->idcuentasPorCobrar === (int) ($pagoData['renewal_cxc_id'] ?? (count($ids) === 1 ? $ids[0] : 0));
                    $renewsServices = isset($renewalCxcIds[(int) $cxc->idcuentasPorCobrar]);
                    $advanceForCxc = $isRenewalCxc ? $advanceCashApplied : 0.0;
                    $pendingWithholdingForCxc = $isRenewalCxc ? $withholdingPending : 0.0;
                    $cashHeldForWithholdingCxc = $isRenewalCxc ? $cashHeldForWithholding : 0.0;
                    if ($deduction + $credit + $cashApplied + $excess + $advanceForCxc + $cashHeldForWithholdingCxc <= 0) {
                        continue;
                    }

                    $noteApplications = [];
                    $noteRemainingForCxc = $noteCredit;
                    foreach ($noteBalancesById as $noteId => $noteBalance) {
                        if ($noteRemainingForCxc <= 0) {
                            break;
                        }
                        $noteAmount = round(min($noteBalance, $noteRemainingForCxc), 2);
                        if ($noteAmount <= 0) {
                            continue;
                        }
                        $noteApplications[] = [
                            'nota_credito_idnotacredito' => (int) $noteId,
                            'monto' => $noteAmount,
                        ];
                        $noteBalancesById[$noteId] = round($noteBalance - $noteAmount, 2);
                        $noteRemainingForCxc = round($noteRemainingForCxc - $noteAmount, 2);
                    }

                    $cashConverted = round(
                        $cashApplied
                            + $advanceForCxc
                            + $cashHeldForWithholdingCxc
                            + ($quotationOperation ? $excess : 0),
                        2
                    );
                    $cashReceivedInPaymentCurrency = $currencyMode === 'other' && $cashConverted > 0
                        ? self::convertPaymentAmountToDebtCurrency(
                                $cashConverted,
                                $rate,
                                $paymentCurrencyId,
                                $currencyId
                        )
                        : $cashConverted;
                    $itemData = array_merge($pagoData, [
                        'payment_currency_mode' => $currencyMode,
                        'tipo_cambio' => $rate,
                        'moneda_idmoneda' => $currencyId,
                        'monto_recibido' => $cashReceivedInPaymentCurrency,
                        'monto_banco' => $quotationOperation && isset($pagoData['monto_banco'])
                            ? $pagoData['monto_banco']
                            : $cashReceivedInPaymentCurrency,
                        'monto_convertido' => $cashConverted,
                        'monto_aplicado' => $cashApplied,
                        'monto_deduccion' => $deduction,
                        'monto_saldo_favor_aplicado' => $bankCredit,
                        'monto_nota_credito_aplicado' => $noteCredit,
                        'nota_credito_aplicaciones' => $noteApplications,
                        'monto_excedente' => $excess,
                        'monto_adelantado' => $advanceForCxc,
                        'monto_deduccion_pendiente' => $pendingWithholdingForCxc,
                        'withholding_proof_path' => $withholdingProofPath,
                        'adelanto_hasta' => $isRenewalCxc ? $advanceUntil : null,
                        'adelanto_desde' => $isRenewalCxc ? $advanceFrom : null,
                        'fechaFin' => $renewsServices ? ($pagoData['fechaFin'] ?? null) : null,
                        'montoPago' => $cashApplied,
                        'modo_pago' => $mode,
                        'cuotas' => $installments,
                        'servicio_ids' => $serviceIdsByCxc[(int) $cxc->idcuentasPorCobrar] ?? [],
                        'renewal_service_ids' => $renewsServices
                            ? ($serviceIdsByCxc[(int) $cxc->idcuentasPorCobrar] ?? [])
                            : [],
                        'renewal_cxc_ids' => array_keys($renewalCxcIds),
                    ]);
                    $results[] = $this->procesarPagoCancelacion($cxc->idcuentasPorCobrar, $itemData, $storedPath);
                }

                $breakdown['retencion'] = $isRetention;
                $breakdown['cash_held_for_withholding'] = $cashHeldForWithholding;
                $breakdown['currency_symbol'] = (string) (DB::table('moneda')->where('idmoneda', $currencyId)->value('simbolo') ?: 'S/');
                $breakdown['results'] = $results;
                return $breakdown;
            });
        } catch (\Throwable $exception) {
            if ($storedByService && $storedPath && Storage::disk('public')->exists($storedPath)) {
                Storage::disk('public')->delete($storedPath);
            }
            if ($storedWithholdingProof && $withholdingProofPath && Storage::disk('public')->exists($withholdingProofPath)) {
                Storage::disk('public')->delete($withholdingProofPath);
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
        $paymentName = Str::ascii(mb_strtolower((string) ($payment->detalle ?? ''), 'UTF-8'));
        if (!$payment || !str_contains($paymentName, 'credito')) {
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

    private function createServiceCreditInstallmentReceivables(object $parentCxc, array $installments, array $paymentData): void
    {
        $parentId = (int) $parentCxc->idcuentasPorCobrar;
        $serviceIds = $this->serviceIdsForCreditPlan($parentCxc);
        $pendingInstallments = array_slice($installments, 1);
        $pendingTotal = round((float) collect($pendingInstallments)->sum('monto'), 2);
        $remainingBalance = (float) DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $parentId)
            ->value('montoActual');
        if (abs($pendingTotal - $remainingBalance) > 0.01) {
            throw new \RuntimeException('Las cuotas futuras deben coincidir con el saldo pendiente después del pago de la primera cuota.');
        }
        if (DB::table('cuentasporcobrar')->where('descripcion', 'like', 'CCXC:' . $parentId . ':%')->exists()) {
            throw new \RuntimeException('Esta cuenta ya tiene cuotas de crédito registradas.');
        }

        foreach ($pendingInstallments as $installment) {
            $serviceMarker = $serviceIds !== [] ? ' SC-' . $serviceIds[0] : '';
            $description = mb_substr(
                'CCXC:' . $parentId . ':' . (int) $installment['numero'] . $serviceMarker,
                0,
                50
            );
            $installmentCxcId = DB::table('cuentasporcobrar')->insertGetId([
                'cliente_idcliente' => $parentCxc->cliente_idcliente,
                'tipoCobro_idtipoCobros' => $parentCxc->tipoCobro_idtipoCobros,
                'moneda_idmoneda' => $parentCxc->moneda_idmoneda,
                'docReferencia' => $parentCxc->docReferencia,
                'descripcion' => $description,
                'montoOriginal' => round((float) $installment['monto'], 2),
                'montoActual' => round((float) $installment['monto'], 2),
                'canCuotas' => null,
                'fechaRegistro' => $this->paymentDateTime((string) ($paymentData['fechaPago'] ?? now()->format('Y-m-d'))),
                'fechaCancelacion' => $installment['fecha'],
                'estado' => 'PagoCredito',
            ]);

            if ($serviceIds !== [] && Schema::hasTable(self::CXC_SERVICE_LINK_TABLE)) {
                $serviceAmounts = DB::table('serviciocliente')
                    ->whereIn('idservicioCliente', $serviceIds)
                    ->pluck('monto', 'idservicioCliente');
                $linkRows = [];
                foreach ($serviceIds as $serviceId) {
                    $linkRows[] = [
                        'cuentasPorCobrar_idcuentasPorCobrar' => $installmentCxcId,
                        'servicioCliente_idservicioCliente' => $serviceId,
                        'montoServicio' => $serviceAmounts[$serviceId] ?? null,
                    ];
                }
                DB::table(self::CXC_SERVICE_LINK_TABLE)->insertOrIgnore($linkRows);
            }
        }
    }

    private function creditInstallmentParentId(string $description): ?int
    {
        return preg_match('/^CCXC:(\d+):(\d+)(?:\s|$)/', $description, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    private function creditInstallmentNumber(string $description): ?int
    {
        return preg_match('/^CCXC:\d+:(\d+)(?:\s|$)/', $description, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    private function serviceIdsForCreditPlan(object $parentCxc): array
    {
        $serviceIds = [];
        $parentDescription = (string) ($parentCxc->descripcion ?? '');
        if (preg_match('/SC-([0-9,]+)/', $parentDescription, $matches) === 1) {
            $serviceIds = array_values(array_filter(array_map('intval', explode(',', $matches[1]))));
        }
        if (
            Schema::hasTable(self::CXC_SERVICE_LINK_TABLE)
            && DB::table(self::CXC_SERVICE_LINK_TABLE)
                ->where('cuentasPorCobrar_idcuentasPorCobrar', $parentCxc->idcuentasPorCobrar)
                ->exists()
        ) {
            $serviceIds = array_merge($serviceIds, DB::table(self::CXC_SERVICE_LINK_TABLE)
                ->where('cuentasPorCobrar_idcuentasPorCobrar', $parentCxc->idcuentasPorCobrar)
                ->pluck('servicioCliente_idservicioCliente')
                ->map(fn ($id): int => (int) $id)
                ->all());
        }

        return array_values(array_unique(array_filter($serviceIds)));
    }

    private function syncServiceCreditPlanParent(
        int $parentId,
        int $paidInstallmentCxcId,
        string $paymentDate,
        ?int $bankMovementId
    ): void {
        $parent = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $parentId)
            ->lockForUpdate()
            ->first();
        $paidInstallment = DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $paidInstallmentCxcId)
            ->first(['descripcion', 'montoActual']);
        if (!$parent || !$paidInstallment) {
            throw new \RuntimeException('No se encontró la cuenta principal del plan de crédito.');
        }

        $installmentNumber = $this->creditInstallmentNumber((string) $paidInstallment->descripcion);
        if ($installmentNumber !== null && (float) $paidInstallment->montoActual <= 0.01) {
            DB::table('detallecxc')
                ->where('cuentasPorCobrar_idcuentasPorCobrar', $parentId)
                ->where('nCuota', $installmentNumber)
                ->where('estado', '0')
                ->update([
                    'fechaPago' => $paymentDate,
                    'bancos_idbancos' => $bankMovementId,
                    'estado' => '1',
                ]);
        }

        $children = DB::table('cuentasporcobrar')
            ->where('descripcion', 'like', 'CCXC:' . $parentId . ':%')
            ->get(['descripcion', 'montoActual']);
        $remainingBalance = round((float) $children->sum('montoActual'), 2);
        $newState = $remainingBalance <= 0.01 ? 'CANCELADO' : 'Pendiente a credito';

        DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $parentId)
            ->update([
                'montoActual' => $remainingBalance,
                'estado' => $newState,
            ]);

        if ($remainingBalance > 0.01 || !self::shouldRenewAfterFullPayment(
            $parent->fechaCancelacion ? Carbon::parse($parent->fechaCancelacion)->toDateString() : null
        )) {
            return;
        }

        $serviceIds = $this->serviceIdsForCreditPlan($parent);
        if ($serviceIds === []) {
            return;
        }
        $nextCxcId = $this->renovarServicioVinculado(
            $parent,
            0,
            Carbon::now(),
            $serviceIds,
            (int) ($parent->tipoCobro_idtipoCobros ?? 0),
            null,
            false,
            null,
            $paymentDate
        );
        if ($nextCxcId) {
            $this->registrarFechaPagoSiguienteCxc((int) $nextCxcId, $paymentDate);
        }
    }

    private function serviceIdsForCxc(
        object $cxc,
        array $selectedServiceIds,
        array $serviceAmountsById,
        array $assignedServiceIds,
        bool $singleCxc
    ): array {
        $selectedServiceIds = array_values(array_unique(array_map('intval', $selectedServiceIds)));
        if ($selectedServiceIds === []) {
            return [];
        }

        if ($singleCxc) {
            return $selectedServiceIds;
        }

        if (Schema::hasTable(self::CXC_SERVICE_LINK_TABLE)) {
            $linkedIds = DB::table(self::CXC_SERVICE_LINK_TABLE)
                ->where('cuentasPorCobrar_idcuentasPorCobrar', $cxc->idcuentasPorCobrar)
                ->pluck('servicioCliente_idservicioCliente')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $selectedLinkedIds = array_values(array_intersect($selectedServiceIds, $linkedIds));
            if ($selectedLinkedIds !== []) {
                return $selectedLinkedIds;
            }
        }

        if (preg_match('/SC-([0-9,]+)/', (string) ($cxc->descripcion ?? ''), $match) === 1) {
            $linkedIds = array_values(array_filter(array_map('intval', explode(',', $match[1]))));
            return array_values(array_intersect($selectedServiceIds, $linkedIds));
        }

        $unassignedIds = array_diff($selectedServiceIds, $assignedServiceIds);
        $matchingIds = array_values(array_filter($unassignedIds, fn (int $serviceId): bool =>
            isset($serviceAmountsById[$serviceId])
            && abs((float) $serviceAmountsById[$serviceId] - (float) ($cxc->montoOriginal ?? 0)) < 0.005
        ));

        return count($matchingIds) === 1 ? $matchingIds : [];
    }

    public function linkServicesToReceivables(array $cxcIds, array $selectedServiceIds, int $renewalCxcId = 0): void
    {
        $cxcIds = array_values(array_unique(array_filter(array_map('intval', $cxcIds))));
        $selectedServiceIds = array_values(array_unique(array_filter(array_map('intval', $selectedServiceIds))));
        if ($cxcIds === [] || $selectedServiceIds === [] || !Schema::hasTable(self::CXC_SERVICE_LINK_TABLE)) {
            return;
        }

        $cxcs = DB::table('cuentasporcobrar')
            ->whereIn('idcuentasPorCobrar', $cxcIds)
            ->orderBy('idcuentasPorCobrar')
            ->get(['idcuentasPorCobrar', 'cliente_idcliente', 'descripcion', 'montoOriginal']);
        if ($cxcs->count() !== count($cxcIds)) {
            throw new \RuntimeException('Una de las cuentas por cobrar seleccionadas ya no existe.');
        }

        $clientIds = $cxcs->pluck('cliente_idcliente')->map(fn ($id): string => (string) $id)->unique();
        $serviceRows = DB::table('serviciocliente')
            ->whereIn('idservicioCliente', $selectedServiceIds)
            ->get(['idservicioCliente', 'cliente_idcliente', 'monto']);
        if (
            $clientIds->count() !== 1
            || $serviceRows->count() !== count($selectedServiceIds)
            || $serviceRows->contains(fn ($service): bool =>
                (string) $service->cliente_idcliente !== (string) $clientIds->first()
            )
        ) {
            throw new \RuntimeException('Los servicios seleccionados no pertenecen al cliente de las cuentas por cobrar.');
        }

        $serviceAmountsById = $serviceRows
            ->mapWithKeys(fn ($service): array => [(int) $service->idservicioCliente => (float) $service->monto])
            ->all();
        $serviceIdsByCxc = [];
        $assignedServiceIds = [];
        foreach ($cxcs as $cxc) {
            $cxcId = (int) $cxc->idcuentasPorCobrar;
            $serviceIdsByCxc[$cxcId] = $this->serviceIdsForCxc(
                $cxc,
                $selectedServiceIds,
                $serviceAmountsById,
                $assignedServiceIds,
                $cxcs->count() === 1
            );
            $assignedServiceIds = array_values(array_unique(array_merge(
                $assignedServiceIds,
                $serviceIdsByCxc[$cxcId]
            )));
        }

        $renewalCxcId = $renewalCxcId > 0 ? $renewalCxcId : (int) $cxcs->first()->idcuentasPorCobrar;
        if (isset($serviceIdsByCxc[$renewalCxcId])) {
            $serviceIdsByCxc[$renewalCxcId] = array_values(array_unique(array_merge(
                $serviceIdsByCxc[$renewalCxcId],
                array_diff($selectedServiceIds, $assignedServiceIds)
            )));
        }
        $this->persistCxcServiceLinks($serviceIdsByCxc, $serviceAmountsById);
    }

    public function isolateSelectedServiceReceivables(array $cxcIds, array $selectedServiceIds): array
    {
        $cxcIds = array_values(array_unique(array_filter(array_map('intval', $cxcIds))));
        $selectedServiceIds = array_values(array_unique(array_filter(array_map('intval', $selectedServiceIds))));
        if ($selectedServiceIds === [] || $cxcIds === []) {
            return [
                'cxc_ids' => $cxcIds,
                'renewal_cxc_id' => $cxcIds[0] ?? 0,
                'renewal_cxc_ids' => $cxcIds,
            ];
        }

        $selectedServiceLookup = array_fill_keys($selectedServiceIds, true);
        $selectedCxcIds = [];
        $matchedServiceIds = [];
        foreach ($cxcIds as $cxcId) {
            $cxc = DB::table('cuentasporcobrar')
                ->where('idcuentasPorCobrar', $cxcId)
                ->lockForUpdate()
                ->first();
            if (!$cxc) {
                throw new \RuntimeException('Una de las cuentas por cobrar seleccionadas ya no existe.');
            }

            $linkedServiceAmounts = [];
            if (Schema::hasTable(self::CXC_SERVICE_LINK_TABLE)) {
                $linkedServiceAmounts = DB::table(self::CXC_SERVICE_LINK_TABLE)
                    ->where('cuentasPorCobrar_idcuentasPorCobrar', $cxcId)
                    ->pluck('montoServicio', 'servicioCliente_idservicioCliente')
                    ->map(fn ($amount): ?float => $amount === null ? null : (float) $amount)
                    ->all();
            }

            $descriptionServiceIds = [];
            if (preg_match('/SC-([0-9,]+)/', (string) ($cxc->descripcion ?? ''), $matches) === 1) {
                $descriptionServiceIds = array_values(array_filter(array_map('intval', explode(',', $matches[1]))));
            }

            $memberServiceIds = $linkedServiceAmounts !== []
                ? array_map('intval', array_keys($linkedServiceAmounts))
                : $descriptionServiceIds;
            if ($memberServiceIds === [] && !empty($cxc->fechaCancelacion)) {
                $legacyServices = DB::table('serviciocliente')
                    ->where('cliente_idcliente', $cxc->cliente_idcliente)
                    ->whereDate('fecheVencimiento', $cxc->fechaCancelacion)
                    ->get(['idservicioCliente', 'monto']);
                if (
                    $legacyServices->isNotEmpty()
                    && abs((float) $legacyServices->sum('monto') - (float) $cxc->montoOriginal) < 0.005
                ) {
                    $memberServiceIds = $legacyServices
                        ->pluck('idservicioCliente')
                        ->map(fn($serviceId): int => (int) $serviceId)
                        ->all();
                }
            }

            $cxcSelectedServiceIds = array_values(array_filter(
                $memberServiceIds,
                fn(int $serviceId): bool => isset($selectedServiceLookup[$serviceId])
            ));
            if ($cxcSelectedServiceIds === []) {
                continue;
            }

            $services = DB::table('serviciocliente')
                ->whereIn('idservicioCliente', $memberServiceIds)
                ->where('cliente_idcliente', $cxc->cliente_idcliente)
                ->get(['idservicioCliente', 'monto']);
            $serviceAmounts = $services->mapWithKeys(fn ($service): array => [
                (int) $service->idservicioCliente => $linkedServiceAmounts[(int) $service->idservicioCliente]
                    ?? (float) $service->monto,
            ])->all();

            $totalBasis = round(array_sum($serviceAmounts), 2);
            $gross = round((float) $cxc->montoOriginal, 2);
            $balance = round((float) $cxc->montoActual, 2);
            if ($totalBasis <= 0 || $gross <= 0 || count($serviceAmounts) !== count($memberServiceIds)) {
                throw new \RuntimeException('No se pudo determinar con seguridad el importe de cada vehículo seleccionado.');
            }

            $matchedServiceIds = array_merge($matchedServiceIds, $cxcSelectedServiceIds);
            if (count($cxcSelectedServiceIds) === count($memberServiceIds)) {
                $selectedCxcIds[] = $cxcId;
                continue;
            }

            $grossRemaining = $gross;
            $balanceRemaining = $balance;
            $grossByService = [];
            $balanceByService = [];
            foreach (array_keys($serviceAmounts) as $index => $serviceId) {
                $basis = $serviceAmounts[$serviceId];
                $isLast = $index === count($serviceAmounts) - 1;
                $serviceGross = $isLast
                    ? $grossRemaining
                    : round($gross * (float) $basis / $totalBasis, 2);
                $serviceBalance = $isLast
                    ? $balanceRemaining
                    : round($balance * (float) $basis / $totalBasis, 2);
                $grossByService[$serviceId] = $serviceGross;
                $balanceByService[$serviceId] = $serviceBalance;
                $grossRemaining = round($grossRemaining - $serviceGross, 2);
                $balanceRemaining = round($balanceRemaining - $serviceBalance, 2);
            }

            foreach ($cxcSelectedServiceIds as $serviceId) {
                $selectedRecord = get_object_vars($cxc);
                unset($selectedRecord['idcuentasPorCobrar']);
                $selectedRecord['descripcion'] = $this->descriptionWithServiceIds(
                    (string) ($cxc->descripcion ?? ''),
                    [$serviceId]
                );
                $selectedRecord['montoOriginal'] = $grossByService[$serviceId];
                $selectedRecord['montoActual'] = $balanceByService[$serviceId];
                foreach (array_keys($selectedRecord) as $column) {
                    if (strtolower((string) $column) === 'docfactura') {
                        $selectedRecord[$column] = null;
                    }
                }
                $isolatedCxcId = (int) DB::table('cuentasporcobrar')->insertGetId($selectedRecord);

                if (Schema::hasTable(self::CXC_SERVICE_LINK_TABLE)) {
                    DB::table(self::CXC_SERVICE_LINK_TABLE)
                        ->where('cuentasPorCobrar_idcuentasPorCobrar', $cxcId)
                        ->where('servicioCliente_idservicioCliente', $serviceId)
                        ->delete();
                    DB::table(self::CXC_SERVICE_LINK_TABLE)->insertOrIgnore([
                        'cuentasPorCobrar_idcuentasPorCobrar' => $isolatedCxcId,
                        'servicioCliente_idservicioCliente' => $serviceId,
                        'montoServicio' => $serviceAmounts[$serviceId],
                    ]);
                }
                $selectedCxcIds[] = $isolatedCxcId;
            }

            $remainingServiceIds = array_values(array_diff($memberServiceIds, $cxcSelectedServiceIds));
            if ($remainingServiceIds !== []) {
                DB::table('cuentasporcobrar')
                    ->where('idcuentasPorCobrar', $cxcId)
                    ->update([
                        'descripcion' => $this->descriptionWithServiceIds(
                            (string) ($cxc->descripcion ?? ''),
                            $remainingServiceIds
                        ),
                        'montoOriginal' => round(array_sum(array_intersect_key($grossByService, array_flip($remainingServiceIds))), 2),
                        'montoActual' => round(array_sum(array_intersect_key($balanceByService, array_flip($remainingServiceIds))), 2),
                    ]);

                if (Schema::hasTable(self::CXC_SERVICE_LINK_TABLE)) {
                    DB::table(self::CXC_SERVICE_LINK_TABLE)
                        ->where('cuentasPorCobrar_idcuentasPorCobrar', $cxcId)
                        ->whereIn('servicioCliente_idservicioCliente', $cxcSelectedServiceIds)
                        ->delete();
                }
            } else {
                $selectedCxcIds[] = $cxcId;
            }
        }

        $matchedServiceIds = array_values(array_unique($matchedServiceIds));
        $unmatchedSelectedServiceIds = array_values(array_diff($selectedServiceIds, $matchedServiceIds));
        if ($unmatchedSelectedServiceIds !== []) {
            throw new \RuntimeException('No se encontró una cuenta por cobrar pendiente para uno o más vehículos seleccionados.');
        }
        $selectedCxcIds = array_values(array_unique($selectedCxcIds));

        return [
            'cxc_ids' => $selectedCxcIds,
            'renewal_cxc_id' => $selectedCxcIds[0] ?? 0,
            'renewal_cxc_ids' => $selectedCxcIds,
        ];
    }

    private function descriptionWithServiceIds(string $description, array $serviceIds): string
    {
        $marker = $serviceIds === [] ? '' : 'SC-' . implode(',', $serviceIds);
        $description = preg_replace('/\s*SC-[0-9,]+/', '', $description, 1) ?? $description;
        $prefix = trim($description);
        $prefixLength = max(0, 50 - mb_strlen($marker, 'UTF-8') - ($marker === '' ? 0 : 1));
        $prefix = mb_substr($prefix, 0, $prefixLength, 'UTF-8');

        return trim($prefix . ($marker !== '' ? ' ' . $marker : ''));
    }

    private function persistCxcServiceLinks(array $serviceIdsByCxc, array $serviceAmountsById = []): void
    {
        if (!Schema::hasTable(self::CXC_SERVICE_LINK_TABLE)) {
            return;
        }

        $serviceIds = array_values(array_unique(array_merge(...array_values($serviceIdsByCxc ?: [[]]))));
        $storedAmounts = $serviceIds === []
            ? []
            : DB::table('serviciocliente')
                ->whereIn('idservicioCliente', $serviceIds)
                ->pluck('monto', 'idservicioCliente')
                ->map(fn ($amount): float => (float) $amount)
                ->all();
        $serviceAmountsById = $serviceAmountsById + $storedAmounts;
        foreach ($serviceIdsByCxc as $cxcId => $serviceIds) {
            $rows = [];
            foreach (array_unique(array_map('intval', $serviceIds)) as $serviceId) {
                if ($serviceId <= 0) {
                    continue;
                }
                $rows[] = [
                    'cuentasPorCobrar_idcuentasPorCobrar' => (int) $cxcId,
                    'servicioCliente_idservicioCliente' => $serviceId,
                    'montoServicio' => $serviceAmountsById[$serviceId] ?? null,
                ];
            }
            if ($rows !== []) {
                DB::table(self::CXC_SERVICE_LINK_TABLE)->insertOrIgnore($rows);
            }
        }
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
            $montoDeduccionPendiente = round(max(0, (float) ($pagoData['monto_deduccion_pendiente'] ?? 0)), 2);
            $montoFavorAplicado = round(max(0, (float) ($pagoData['monto_saldo_favor_aplicado'] ?? 0)), 2);
            $montoNotaCreditoAplicado = round(max(0, (float) ($pagoData['monto_nota_credito_aplicado'] ?? 0)), 2);
            $notaCreditoAplicaciones = array_values((array) ($pagoData['nota_credito_aplicaciones'] ?? []));
            $docRef = trim((string) ($pagoData['docReferencia'] ?? $cxc->docReferencia ?? ''));
            $descripcion = trim((string) ($pagoData['descripcion'] ?? 'Pago de CXC #' . $idCxc));
            $fechaPago = $this->paymentDateTime($pagoData['fechaPago'] ?? null);
            $cuotas = array_values($pagoData['cuotas'] ?? []);

            $deudaActual = round((float) $cxc->montoActual, 2);
            $montoNotasDetalle = round((float) collect($notaCreditoAplicaciones)->sum(
                fn (array $application): float => (float) ($application['monto'] ?? 0)
            ), 2);
            if ($montoPago < 0 || $montoPago > $deudaActual
                || abs($montoNotasDetalle - $montoNotaCreditoAplicado) > 0.01
                || $montoDeduccion + $montoFavorAplicado + $montoNotaCreditoAplicado + $montoPago <= 0
                || $montoDeduccion + $montoFavorAplicado + $montoNotaCreditoAplicado + $montoPago > $deudaActual) {
                throw new \RuntimeException('El monto aplicado a la CXC es inválido.');
            }
            foreach ($notaCreditoAplicaciones as $application) {
                if ((int) ($application['nota_credito_idnotacredito'] ?? 0) <= 0 || (float) ($application['monto'] ?? 0) <= 0) {
                    throw new \RuntimeException('El detalle de aplicación de la nota de crédito es inválido.');
                }
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
            if (
                ($pagoData['origen_pago'] ?? '') === 'cotizacion'
                && $paymentCurrencyMode === 'other'
                && $monedaPagoId === $monedaBaseId
            ) {
                throw new \RuntimeException('La moneda de pago debe ser distinta a la moneda de la deuda.');
            }
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
            $montoBanco = round((float) ($pagoData['monto_banco'] ?? 0), 2);
            if ($montoRecibido < 0 || $tipoCambio <= 0) {
                throw new \RuntimeException('El monto recibido y el tipo de cambio deben ser mayores a cero.');
            }

            $montoConvertido = round((float) ($pagoData['monto_convertido'] ?? ($montoRecibido * $tipoCambio)), 2);
            if ($montoBanco <= 0) {
                $montoBanco = $montoConvertido;
            }
            $montoAplicado = round(min($deudaActual, (float) ($pagoData['monto_aplicado'] ?? $montoPago)), 2);
            $montoExcedente = round(max(0, (float) ($pagoData['monto_excedente'] ?? ($montoConvertido - $montoAplicado))), 2);
            $montoAdelantado = round(max(0, (float) ($pagoData['monto_adelantado'] ?? 0)), 2);
            if ($montoConvertido + 0.01 < $montoAplicado
                || $montoConvertido + 0.01 < $montoAplicado + $montoAdelantado
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

            $paymentBankMovementId = null;
            if ($montoConvertido > 0 && $servicesList->isNotEmpty()) {
                $totalServiciosMonto = round((float) $servicesList->sum('monto'), 2);
                $montoRestante = $montoBanco;
                $count = $servicesList->count();
                $idx = 0;

                foreach ($servicesList as $srv) {
                    $idx++;
                    if ($idx === $count) {
                        $montoSrv = $montoRestante;
                    } else {
                        $prop = $totalServiciosMonto > 0 ? ((float) $srv->monto / $totalServiciosMonto) : (1 / $count);
                        $montoSrv = round($montoBanco * $prop, 2);
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
                    $paymentBankMovementId ??= $idBancoMov;

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
                        'descripcion' => mb_substr('Obs: ' . $descripcion, 0, 80),
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
                    'monto' => $montoBanco,
                    'tipoMov' => 'I',
                ]);
                $paymentBankMovementId = $idBancoMov;

                if ($storedPath) {
                    DB::table('evidenciapago')->insert([
                        'fechaCarga' => now()->format('Y-m-d H:i:s'),
                        'archivo' => $storedPath,
                        'bancos_idbancos' => $idBancoMov,
                    ]);
                }

                $quoteInstallmentNumber = ($pagoData['origen_pago'] ?? '') === 'cotizacion'
                    ? (int) ($pagoData['cuota_cotizacion_numero'] ?? 0)
                    : 0;
                $scheduledQuoteDetail = $quoteInstallmentNumber > 0 && empty($cuotas)
                    ? DB::table('detallecxc')
                        ->where('cuentasPorCobrar_idcuentasPorCobrar', $idCxc)
                        ->where('nCuota', $quoteInstallmentNumber)
                        ->where('estado', '0')
                        ->lockForUpdate()
                        ->first(['iddetalleCXC'])
                    : null;
                $paymentDetailData = [
                    'formaPago_idformaPago' => $formaPagoId,
                    'cuentasPorCobrar_idcuentasPorCobrar' => $idCxc,
                    'moneda_idmoneda' => $monedaPagoId,
                    'descripcion' => mb_substr($descripcion, 0, 80),
                    'fechaPagoProgramada' => $cuotas[0]['fecha'] ?? $pagoData['cuota_cotizacion_fecha'] ?? $fechaPago,
                    'fechaPago' => $fechaPago,
                    'nCuota' => $cuotas[0]['numero'] ?? ($quoteInstallmentNumber > 0 ? $quoteInstallmentNumber : null),
                    'bancos_idbancos' => $idBancoMov,
                    'estado' => '1',
                ];
                if ($scheduledQuoteDetail) {
                    DB::table('detallecxc')
                        ->where('iddetalleCXC', $scheduledQuoteDetail->iddetalleCXC)
                        ->update($paymentDetailData);
                    $detallePagoId = (int) $scheduledQuoteDetail->iddetalleCXC;
                } else {
                    $detallePagoId = DB::table('detallecxc')->insertGetId($paymentDetailData);
                }
            } else {
                $idBancoMov = null;
            }

            foreach ($idBancoMov ? array_slice($cuotas, 1) : [] as $cuota) {
                $scheduledDescription = ($pagoData['origen_pago'] ?? '') === 'cotizacion'
                    ? 'Cuota programada ' . $cuota['numero'] . ' - Monto: ' . number_format((float) $cuota['monto'], 2, '.', '')
                    : 'Cuota programada ' . $cuota['numero'];
                DB::table('detallecxc')->insert([
                    'formaPago_idformaPago' => $formaPagoId,
                    'cuentasPorCobrar_idcuentasPorCobrar' => $idCxc,
                    'moneda_idmoneda' => ($pagoData['origen_pago'] ?? '') === 'cotizacion'
                        ? $monedaBaseId
                        : $monedaPagoId,
                    'descripcion' => mb_substr($scheduledDescription, 0, 80),
                    'fechaPagoProgramada' => $cuota['fecha'],
                    'fechaPago' => null,
                    'nCuota' => $cuota['numero'],
                    'bancos_idbancos' => $idBancoMov,
                    'estado' => '0',
                ]);
            }

            foreach ($notaCreditoAplicaciones as $application) {
                DB::table('nota_credito_aplicacion')->insert([
                    'nota_credito_idnotacredito' => (int) $application['nota_credito_idnotacredito'],
                    'cuentasPorCobrar_idcuentasPorCobrar' => (int) $idCxc,
                    'monto' => round((float) $application['monto'], 2),
                    'fechaAplicacion' => $fechaPago,
                    'estado' => '1',
                ]);
            }
            foreach (collect($notaCreditoAplicaciones)->pluck('nota_credito_idnotacredito')->unique() as $noteId) {
                $noteAmount = (float) DB::table('nota_credito')->where('idnotacredito', $noteId)->value('monto');
                $noteUsed = (float) DB::table('nota_credito_aplicacion')
                    ->where('nota_credito_idnotacredito', $noteId)
                    ->where('estado', '1')
                    ->sum('monto');
                if ($noteAmount > 0 && $noteUsed + 0.01 >= $noteAmount) {
                    DB::table('nota_credito')
                        ->where('idnotacredito', $noteId)
                        ->where('estado', 'E')
                        ->update(['estado' => 'A']);
                }
            }

            $deudaPendiente = round(max(
                0,
                $deudaActual - $montoAplicado - $montoDeduccion - $montoFavorAplicado - $montoNotaCreditoAplicado
            ), 2);
            $pagoCompleto = $deudaPendiente <= 0;
            $paymentMethod = DB::table('formapago')->where('idformaPago', $formaPagoId)->value('detalle');
            $isRetention = ($pagoData['origen_pago'] ?? '') === 'cotizacion'
                && array_key_exists('is_retention_por_tipo_servicio', $pagoData)
                ? filter_var($pagoData['is_retention_por_tipo_servicio'], FILTER_VALIDATE_BOOLEAN)
                : (string) DB::table('cliente')
                    ->where('idcliente', $cxc->cliente_idcliente)
                    ->value('detraccion') === '1';
            $period = $this->periodFromDescription((string) ($cxc->descripcion ?? ''));
            $periodEnd = $pagoData['fechaFin'] ?? $cxc->fechaCancelacion ?? $period[1] ?? null;
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
                $creditInstallments,
                ($pagoData['modo_pago'] ?? 'total') === 'parcial'
            );
            if ($montoDeduccionPendiente > 0) {
                if (abs($deudaPendiente - $montoDeduccionPendiente) > 0.01) {
                    throw new \RuntimeException('El saldo pendiente de detracción/retención no coincide con el monto restante de la cuenta.');
                }
                $isAutodetraccionPending = !$isRetention
                    && ($pagoData['withholding_decision'] ?? null) === 'full_pending';
                $nuevoEstado = $isRetention
                    ? 'Pendiente a Retención'
                    : ($isAutodetraccionPending ? 'Pendiente Autodetracción' : 'Pendiente a Detracción');
            }
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

            $isServiceCreditPlan = ($pagoData['origen_pago'] ?? '') !== 'cotizacion'
                && count($cuotas) > 1
                && $paymentMethod
                && str_contains(Str::ascii(mb_strtolower((string) $paymentMethod, 'UTF-8')), 'credito');
            if ($isServiceCreditPlan) {
                $this->createServiceCreditInstallmentReceivables($cxc, $cuotas, $pagoData);
            }

            if (($creditInstallmentParentId = $this->creditInstallmentParentId((string) ($cxc->descripcion ?? ''))) !== null) {
                $this->syncServiceCreditPlanParent(
                    $creditInstallmentParentId,
                    (int) $cxc->idcuentasPorCobrar,
                    $fechaPago,
                    $paymentBankMovementId
                );
            }

            if ($montoDeduccion > 0) {
                $nacionBankId = $this->bancoNacionId();
                $deductionLabel = $isRetention ? 'Retención' : 'Detracción';
                $quotationNumber = preg_match('/^COTIZACION:(.+)$/u', (string) ($cxc->descripcion ?? ''), $quotationMatch) === 1
                    ? trim($quotationMatch[1])
                    : 'CXC #' . $idCxc;
                $currencySymbol = trim((string) DB::table('moneda')
                    ->where('idmoneda', $monedaBaseId)
                    ->value('simbolo')) ?: 'S/';
                if (mb_strtolower($currencySymbol, 'UTF-8') === 's') {
                    $currencySymbol = 'S/';
                }
                $deductionDateTime = $this->paymentDateTime(
                    (string) ($pagoData['withholding_fecha'] ?? $fechaPago)
                );
                $quotationClient = ($pagoData['origen_pago'] ?? '') === 'cotizacion'
                    ? DB::table('cliente')
                        ->where('idcliente', $cxc->cliente_idcliente)
                        ->first(['razonSocial', 'nombreComercial'])
                    : null;
                $clientName = trim((string) ($quotationClient->razonSocial ?? $quotationClient->nombreComercial ?? ''));
                $withholdingDescription = trim((string) ($pagoData['withholding_description'] ?? ''));
                $movementInformation = ($pagoData['origen_pago'] ?? '') === 'cotizacion'
                    ? implode(' | ', array_filter([
                        $deductionLabel . ' pagada ' . $quotationNumber,
                        $clientName !== '' ? 'Cliente: ' . $clientName : '',
                        $withholdingDescription,
                    ]))
                    : $deductionLabel . ' pagada de ' . $quotationNumber . ' del monto '
                        . $currencySymbol . ' ' . number_format((float) $cxc->montoOriginal, 2, '.', ',');
                $deductionBankId = DB::table('bancos')->insertGetId([
                    'entidadBancaria_identidadBancaria' => $nacionBankId,
                    'fechaRegistro' => $deductionDateTime,
                    'informacion' => mb_substr($movementInformation, 0, 100),
                    'monto' => $montoDeduccion,
                    'tipoMov' => 'T',
                ]);
                $deductionProofPath = $pagoData['withholding_proof_path'] ?? $storedPath;
                if ($deductionProofPath) {
                    DB::table('evidenciapago')->insert([
                        'fechaCarga' => now()->format('Y-m-d H:i:s'),
                        'archivo' => $deductionProofPath,
                        'bancos_idbancos' => $deductionBankId,
                    ]);
                }
                DB::table('detallecxc')->insert([
                    'formaPago_idformaPago' => $formaPagoId,
                    'cuentasPorCobrar_idcuentasPorCobrar' => $idCxc,
                    'moneda_idmoneda' => $monedaBaseId,
                    'descripcion' => $deductionLabel . ' aplicada',
                    'fechaPagoProgramada' => $deductionDateTime,
                    'fechaPago' => $deductionDateTime,
                    'nCuota' => null,
                    'bancos_idbancos' => $deductionBankId,
                    'estado' => '1',
                ]);
            }

            if ($montoDeduccionPendiente > 0) {
                if ($paymentBankMovementId === null) {
                    throw new \RuntimeException('No se encontró el movimiento bancario que respalda el pago con detracción/retención pendiente.');
                }
                $pendingWithholdingLabel = $isRetention ? 'Retención pendiente: ' : 'Detracción pendiente: ';
                DB::table('detallecxc')->insert([
                    'formaPago_idformaPago' => $formaPagoId,
                    'cuentasPorCobrar_idcuentasPorCobrar' => $idCxc,
                    'moneda_idmoneda' => $monedaBaseId,
                    'descripcion' => mb_substr($pendingWithholdingLabel . number_format($montoDeduccionPendiente, 2, '.', ''), 0, 50),
                    'fechaPagoProgramada' => $fechaPago,
                    'fechaPago' => null,
                    'nCuota' => null,
                    'bancos_idbancos' => $paymentBankMovementId,
                    'estado' => '0',
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
            $renewalServiceIds = $pagoData['renewal_service_ids'] ?? $serviceIds;
            $parsedRenewalServiceIds = array_values(array_unique(array_filter(array_map(
                'intval',
                (array) $renewalServiceIds
            ))));
            $tipoCobroId = !empty($pagoData['tipoCobro_idtipoCobros'])
                ? (int) $pagoData['tipoCobro_idtipoCobros']
                : null;
            $period = $this->periodFromDescription((string) ($cxc->descripcion ?? ''));
            $periodEnd = $cxc->fechaCancelacion ?? ($period[1] ?? null);
            $isCreditInstallment = $this->creditInstallmentParentId((string) ($cxc->descripcion ?? '')) !== null;
            $renewalCxcId = (int) ($pagoData['renewal_cxc_id'] ?? $idCxc);
            $renewalCxcIds = array_values(array_unique(array_filter(array_map(
                'intval',
                (array) ($pagoData['renewal_cxc_ids'] ?? [$renewalCxcId])
            ))));
            $isRenewalCxc = !$isCreditInstallment && in_array((int) $idCxc, $renewalCxcIds, true);
            $advanceUntil = $pagoData['adelanto_hasta'] ?? null;
            $advanceMonths = (int) ($pagoData['adelanto_meses'] ?? 0);
            if ($pagoCompleto && $isRenewalCxc && $periodEnd) {
                if ($advanceMonths > 1 && $advanceUntil && $parsedRenewalServiceIds !== []) {
                    $this->cancelarCxcsCubiertasPorAdelanto(
                        $cxc,
                        $parsedRenewalServiceIds,
                        (string) ($pagoData['adelanto_desde'] ?? $periodEnd),
                        (string) $advanceUntil
                    );
                }
            }
            $nextCxcId = $pagoCompleto
                && $isRenewalCxc
                && $parsedRenewalServiceIds !== []
                && self::shouldRenewAfterFullPayment($periodEnd)
                ? $this->renovarServicioVinculado(
                    $cxc,
                    0,
                    Carbon::now(),
                    $parsedRenewalServiceIds,
                    $tipoCobroId,
                    $advanceMonths > 1 ? (string) $advanceUntil : null,
                    $advanceMonths > 1,
                    $advanceMonths > 1 ? null : ($pagoData['fechaFin'] ?? null),
                    $fechaPago
                )
                : null;
            if ($nextCxcId) {
                $this->registrarFechaPagoSiguienteCxc((int) $nextCxcId, $fechaPago);
            }

            return [
                'idcuentasPorCobrar' => $idCxc,
                'montoPagado' => $montoAplicado,
                'montoDeduccion' => $montoDeduccion,
                'montoSaldoFavorAplicado' => $montoFavorAplicado,
                'montoNotaCreditoAplicado' => $montoNotaCreditoAplicado,
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

    private function registrarFechaPagoSiguienteCxc(int $nextCxcId, string $fechaPago): void
    {
        DB::table('cuentasporcobrar')
            ->where('idcuentasPorCobrar', $nextCxcId)
            ->whereNull('fechaRegistro')
            ->update(['fechaRegistro' => $fechaPago]);
    }

    private function paymentDateTime(?string $selectedDate): string
    {
        $date = Carbon::parse($selectedDate ?? now())->format('Y-m-d');

        return $date . ' ' . now()->format('H:i:s');
    }

    public function registrarDetraccionPendiente(
        int|string $idCxc,
        ?UploadedFile $comprobante,
        array|int|string|null $servicioIds = null,
        string $descripcion = '',
        ?string $fechaPago = null,
        bool $preservePendingAmountInDetail = false
    ): array {
        $storedPath = $comprobante?->store('comprobantes-pago/cxc', 'public');
        if ($comprobante !== null && !$storedPath) {
            throw new \RuntimeException('No se pudo guardar el comprobante de detracción/retención.');
        }

        try {
            return DB::transaction(function () use ($idCxc, $storedPath, $servicioIds, $descripcion, $fechaPago, $preservePendingAmountInDetail): array {
                $cxc = DB::table('cuentasporcobrar')
                    ->where('idcuentasPorCobrar', $idCxc)
                    ->lockForUpdate()
                    ->first();

                if (!$cxc || !in_array((string) $cxc->estado, ['Cancelado Detracción', 'Pendiente Autodetracción', 'Pendiente Autoretención', 'Pendiente a Detracción', 'Pendiente a Retención'], true) || (float) $cxc->montoActual <= 0) {
                    throw new \RuntimeException('La cuenta no tiene una detracción/retención pendiente por confirmar.');
                }

                $pendingDetail = DB::table('detallecxc')
                    ->where('cuentasPorCobrar_idcuentasPorCobrar', $idCxc)
                    ->where('estado', '0')
                    ->where(function ($query): void {
                        $query->where('descripcion', 'like', 'Detracción pendiente:%')
                            ->orWhere('descripcion', 'like', 'Retención pendiente:%');
                    })
                    ->orderByDesc('iddetalleCXC')
                    ->first();
                if (!$pendingDetail) {
                    throw new \RuntimeException('No se encontró el detalle de detracción/retención pendiente de esta cuenta.');
                }

                $nationBankId = $this->bancoNacionId();
                $paymentDateTime = $this->paymentDateTime($fechaPago);
                $deductionAmount = round((float) $cxc->montoActual, 2);
                $recordedDeductionAmount = preg_match(
                    '/:\s*([0-9]+(?:\.[0-9]{1,2})?)/',
                    (string) $pendingDetail->descripcion,
                    $pendingAmountMatch
                ) === 1
                    ? number_format((float) $pendingAmountMatch[1], 2, '.', '')
                    : number_format($deductionAmount, 2, '.', '');
                $isRetention = str_starts_with((string) $pendingDetail->descripcion, 'Retención pendiente:');
                $isAutodetraccion = !$isRetention && (string) $cxc->estado === 'Pendiente Autodetracción';
                $isAutoretention = !$preservePendingAmountInDetail
                    && $isRetention
                    && (string) $cxc->estado === 'Pendiente Autoretención';
                $deductionLabel = $isRetention ? 'Retención' : 'Detracción';
                $quotationNumber = preg_match('/^COTIZACION:(.+)$/u', (string) ($cxc->descripcion ?? ''), $quotationMatch) === 1
                    ? trim($quotationMatch[1])
                    : 'CXC #' . $idCxc;
                $currencySymbol = trim((string) DB::table('moneda')
                    ->where('idmoneda', $pendingDetail->moneda_idmoneda)
                    ->value('simbolo')) ?: 'S/';
                if (mb_strtolower($currencySymbol, 'UTF-8') === 's') {
                    $currencySymbol = 'S/';
                }
                $movementInformation = $isAutodetraccion || $isAutoretention
                    ? ($isAutodetraccion ? 'Autodetracción' : 'Autoretención') . ' pagada de ' . $quotationNumber . ' | '
                        . $currencySymbol . ' ' . number_format((float) $cxc->montoOriginal, 2, '.', ',')
                        . ' | Se pagó ' . ($isAutodetraccion ? 'autodetracción' : 'autoretención') . ': '
                        . number_format($deductionAmount, 0, '.', ',') . ' soles'
                    : $deductionLabel . ' pagada de ' . $quotationNumber . ' del monto '
                        . $currencySymbol . ' ' . number_format((float) $cxc->montoOriginal, 2, '.', ',');
                $paymentComment = trim($descripcion);
                if ($preservePendingAmountInDetail && $paymentComment !== '') {
                    $movementLabel = $isAutodetraccion
                        ? 'Autodetracción'
                        : ($isAutoretention ? 'Autoretención' : $deductionLabel);
                    $movementInformation = $movementLabel . ' pagada de CXC #' . $idCxc . ' | ' . $paymentComment;
                    if (mb_strlen($movementInformation, 'UTF-8') > 100) {
                        $movementInformation = $movementLabel . ' #' . $idCxc . ' | ' . $paymentComment;
                    }
                } elseif (!$isAutodetraccion && !$isAutoretention && $paymentComment !== '') {
                    $movementInformation .= ' | ' . $paymentComment;
                }
                $bankMovementId = DB::table('bancos')->insertGetId([
                    'entidadBancaria_identidadBancaria' => $nationBankId,
                    'fechaRegistro' => $paymentDateTime,
                    'informacion' => mb_substr($movementInformation, 0, 100),
                    'monto' => $deductionAmount,
                    'tipoMov' => 'T',
                ]);

                if ($storedPath !== null) {
                    DB::table('evidenciapago')->insert([
                        'fechaCarga' => now()->format('Y-m-d H:i:s'),
                        'archivo' => $storedPath,
                        'bancos_idbancos' => $bankMovementId,
                    ]);
                }
                DB::table('detallecxc')
                    ->where('iddetalleCXC', $pendingDetail->iddetalleCXC)
                    ->update([
                        'fechaPago' => $paymentDateTime,
                        'bancos_idbancos' => $bankMovementId,
                        'descripcion' => $deductionLabel . ' aplicada'
                            . ($preservePendingAmountInDetail ? ': ' . $recordedDeductionAmount : ''),
                        'estado' => '1',
                    ]);

                DB::table('cuentasporcobrar')
                    ->where('idcuentasPorCobrar', $idCxc)
                    ->update(['montoActual' => 0, 'estado' => '3']);

                $parsedServiceIds = [];
                if (is_array($servicioIds)) {
                    $parsedServiceIds = array_values(array_filter(array_map('intval', $servicioIds)));
                } elseif ($servicioIds !== null && (int) $servicioIds > 0) {
                    $parsedServiceIds = [(int) $servicioIds];
                }
                if ($parsedServiceIds === []
                    && preg_match('/SC-([0-9,]+)/', (string) ($cxc->descripcion ?? ''), $match) === 1) {
                    $parsedServiceIds = array_values(array_filter(array_map('intval', explode(',', $match[1]))));
                }

                $period = $this->periodFromDescription((string) ($cxc->descripcion ?? ''));
                $periodEnd = $cxc->fechaCancelacion ?? $period[1] ?? null;
                $nextCxcId = $periodEnd && $parsedServiceIds !== []
                    ? $this->renovarServicioVinculado(
                        $cxc,
                        0,
                        Carbon::now(),
                        $parsedServiceIds,
                        (int) ($cxc->tipoCobro_idtipoCobros ?? 0)
                    )
                    : null;

                return [
                    'idcuentasPorCobrar' => (int) $idCxc,
                    'montoDeduccion' => $deductionAmount,
                    'tipoDeduccion' => $deductionLabel,
                    'estado' => '3',
                    'renovado' => $nextCxcId !== null,
                    'siguienteCxcId' => $nextCxcId,
                ];
            });
        } catch (\Throwable $exception) {
            if ($storedPath !== null && Storage::disk('public')->exists($storedPath)) {
                Storage::disk('public')->delete($storedPath);
            }
            throw $exception;
        }
    }

    private function bancoNacionId(): int
    {
        $nameColumns = array_values(array_filter(
            ['razonSocial', 'descripcion'],
            static fn(string $column): bool => Schema::hasColumn('entidadbancaria', $column)
        ));
        if ($nameColumns === []) {
            throw new \RuntimeException('Debe registrar la entidad bancaria Banco de la Nación para contabilizar la detracción/retención.');
        }

        $bank = DB::table('entidadbancaria')->get(array_merge(['identidadBancaria'], $nameColumns))
            ->first(static function ($row) use ($nameColumns): bool {
                foreach ($nameColumns as $column) {
                    $name = Str::ascii(mb_strtolower(trim((string) ($row->{$column} ?? '')), 'UTF-8'));
                    if (preg_match('/\bbanco\s+de\s+la\s+nacion\b/', $name) === 1) {
                        return true;
                    }
                }

                return false;
            });

        if (!$bank) {
            throw new \RuntimeException('Debe registrar la entidad bancaria Banco de la Nación para contabilizar la detracción/retención.');
        }

        return (int) $bank->identidadBancaria;
    }

    /**
     * Renovación automática del servicio vinculado y generación de la siguiente CXC 'PENDIENTE'.
     */

    public function renovarServicioVinculado(object $cxc, float $deudaPendiente = 0, mixed $fechaProceso = null, array|int|string|null $servicioIds = null, ?int $tipoCobroId = null, ?string $periodEndOverride = null, bool $preserveServiceEnd = false, ?string $nextPeriodEndOverride = null, ?string $successorRegistrationDate = null): ?int
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
        if (Schema::hasColumn('serviciocliente', 'observacion')) {
            $serviciosQuery->where(function ($query): void {
                $query->whereNull('sc.observacion')
                    ->orWhere('sc.observacion', 'not like', '%' . self::SCHEDULED_DEACTIVATION_MARKER . '%');
            });
        }

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
        $naturalFirstPeriodEnd = null;
        $currentPeriod = $this->periodFromDescription((string) ($cxc->descripcion ?? ''));
        $nextStart = $periodEndOverride
            ?? $cxc->fechaCancelacion
            ?? ($currentPeriod[1] ?? ($servicios->first()->fecheVencimiento ?? $fechaProceso->format('Y-m-d')));
        $nextStart = Carbon::parse($nextStart)->format('Y-m-d');
        foreach ($servicios as $servicio) {
            $serviceStart = $periodEndOverride
                ?? ($servicio->fecheVencimiento ?? $nextStart);
            $serviceStart = Carbon::parse($serviceStart)->format('Y-m-d');
            $serviceTipoCobroId = $this->tipoCobroIdDesdeServicio([
                'servicio_periodo' => $servicio->servicio_periodo ?? null,
            ]) ?: (int) ($servicio->tipoCobro_idtipoCobros ?? $resolvedTipoCobroId ?? 0);
            $serviceTipoCobro = $serviceTipoCobroId > 0
                ? DB::table('tipocobro')->where('idtipoCobros', $serviceTipoCobroId)->first()
                : $tipoCobro;
            $rec = strtoupper(trim((string) ($serviceTipoCobro->recurrencia ?? '')));
            $tiempo = (int) ($serviceTipoCobro->tiempo ?? 0);
            $mesesPeriodo = ServicePeriod::months($servicio->servicio_periodo ?? null);

            if ($mesesPeriodo !== null) {
                $nuevaFechaVencimiento = ServicePeriod::addToDate($serviceStart, $mesesPeriodo)->format('Y-m-d');
            } elseif ($serviceTipoCobro && $rec === 'M' && $tiempo > 0) {
                $nuevaFechaVencimiento = Carbon::parse($serviceStart)->addMonthsNoOverflow($tiempo)->format('Y-m-d');
            } elseif ($serviceTipoCobro && $rec === 'D' && $tiempo > 0) {
                $nuevaFechaVencimiento = Carbon::parse($serviceStart)->addDays($tiempo)->format('Y-m-d');
            } else {
                $nuevaFechaVencimiento = ServicePeriod::addToDate($serviceStart, 1)->format('Y-m-d');
            }

            $naturalFirstPeriodEnd ??= $nuevaFechaVencimiento;
            $nuevaFechaVencimiento = $this->applyPeriodEndDay($nuevaFechaVencimiento, $nextPeriodEndOverride);

            $periodos[] = [$serviceStart, $nuevaFechaVencimiento];
        }

        if (
            $nextPeriodEndOverride
            && $naturalFirstPeriodEnd
            && Carbon::parse($nextPeriodEndOverride)->toDateString() === $naturalFirstPeriodEnd
        ) {
            $nextPeriodEndOverride = null;
        }

        $fechaInicio = collect($periodos)->min(fn(array $periodo) => $periodo[0]);
        $fechaFin = collect($periodos)->max(fn(array $periodo) => $periodo[1]);
        $montoServicios = round((float) $servicios->sum('monto'), 2);
        $serviceMarker = 'SC-' . implode(',', $servicios->pluck('idservicioCliente')->all());
        if ($servicios->count() > 1) {
            return $this->renovarServiciosIndividuales(
                $cxc,
                $servicios,
                $fechaInicio,
                $fechaFin,
                $resolvedTipoCobroId,
                $periodEndOverride,
                $preserveServiceEnd,
                $successorRegistrationDate,
                $nextPeriodEndOverride
            );
        }
        $singleServiceId = (int) $servicios->first()->idservicioCliente;
        $successorPrefix = 'REN-CXC-' . $cxc->idcuentasPorCobrar . ' P ';
        $openStates = ['1', '2', '4', 'NUEVO', 'PagoCredito', 'PENDIENTE', 'FACTURADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito'];
        $existingSuccessor = DB::table('cuentasporcobrar')
            ->where('cliente_idcliente', $cxc->cliente_idcliente)
            ->where('moneda_idmoneda', $cxc->moneda_idmoneda)
            ->where('descripcion', 'like', $successorPrefix . '%')
            ->whereIn('estado', $openStates)
            ->orderByDesc('idcuentasPorCobrar')
            ->get()
            ->first(fn($candidate): bool => preg_match(
                '/(^|[^A-Za-z0-9])SC-' . $singleServiceId . '([^0-9]|$)/',
                (string) ($candidate->descripcion ?? '')
            ) === 1);
        if ($existingSuccessor) {
            $description = mb_substr(
                $successorPrefix . Carbon::parse($fechaInicio)->format('d/m/Y') . ' a '
                    . Carbon::parse($fechaFin)->format('d/m/Y') . ' SC-' . $singleServiceId,
                0,
                50
            );
            DB::table('cuentasporcobrar')
                ->where('idcuentasPorCobrar', $existingSuccessor->idcuentasPorCobrar)
                ->update([
                    'descripcion' => $description,
                    'fechaCancelacion' => $fechaFin,
                    'tipoCobro_idtipoCobros' => $resolvedTipoCobroId ?? $cxc->tipoCobro_idtipoCobros,
                ]);
            DB::table('serviciocliente')
                ->where('idservicioCliente', $singleServiceId)
                ->update(['fecheVencimiento' => $fechaFin]);
            return (int) $existingSuccessor->idcuentasPorCobrar;
        }

        $nextRange = Carbon::parse($fechaInicio)->format('d/m/Y') . ' a ' . Carbon::parse($fechaFin)->format('d/m/Y');
        $existingPeriod = DB::table('cuentasporcobrar')
            ->where('cliente_idcliente', $cxc->cliente_idcliente)
            ->where('moneda_idmoneda', $cxc->moneda_idmoneda)
            ->where('descripcion', 'like', '%' . $nextRange . '%')
            ->whereIn('estado', ['1', '2', '3', '4', 'NUEVO', 'PagoCredito', 'PENDIENTE', 'FACTURADO', 'CANCELADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito', 'Cancelado Detracción', 'Pendiente Autodetracción', 'Pendiente Autoretención', 'Pendiente a Detracción', 'Pendiente a Retención'])
            ->get(['idcuentasPorCobrar', 'descripcion', 'montoOriginal', 'fechaRegistro'])
            ->first(function ($candidate) use ($singleServiceId, $servicios): bool {
                if (preg_match('/SC-([0-9,]+)/', (string) ($candidate->descripcion ?? ''), $match) === 1) {
                    return in_array(
                        $singleServiceId,
                        array_map('intval', explode(',', $match[1])),
                        true
                    );
                }

                if (Schema::hasTable(self::CXC_SERVICE_LINK_TABLE)) {
                    return DB::table(self::CXC_SERVICE_LINK_TABLE)
                        ->where('cuentasPorCobrar_idcuentasPorCobrar', $candidate->idcuentasPorCobrar)
                        ->where('servicioCliente_idservicioCliente', $singleServiceId)
                        ->exists();
                }

                return abs((float) $candidate->montoOriginal - (float) $servicios->first()->monto) < 0.005;
            });
        if ($existingPeriod) {
            if ($successorRegistrationDate !== null && $existingPeriod->fechaRegistro === null) {
                DB::table('cuentasporcobrar')
                    ->where('idcuentasPorCobrar', $existingPeriod->idcuentasPorCobrar)
                    ->whereNull('fechaRegistro')
                    ->update(['fechaRegistro' => $successorRegistrationDate]);
            }
            DB::table('serviciocliente')
                ->whereIn('idservicioCliente', $servicios->pluck('idservicioCliente')->all())
                ->update(['fecheVencimiento' => $fechaFin]);

            return (int) $existingPeriod;
        }

        if ($nextPeriodEndOverride) {
            $successorPrefix = 'REN-CXC-' . $cxc->idcuentasPorCobrar . ' P ';
            $existingSuccessor = DB::table('cuentasporcobrar')
                ->where('cliente_idcliente', $cxc->cliente_idcliente)
                ->where('moneda_idmoneda', $cxc->moneda_idmoneda)
                ->where('descripcion', 'like', $successorPrefix . '%')
                ->whereIn('estado', ['1', '2', '4', 'NUEVO', 'PagoCredito', 'PENDIENTE', 'FACTURADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito'])
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
            ->update(['fecheVencimiento' => $fechaFin]);

        return $nextCxcId;
    }

    private function renovarServiciosIndividuales(
        object $cxc,
        object $servicios,
        string $fechaInicio,
        string $fechaFin,
        ?int $tipoCobroId,
        ?string $periodEndOverride,
        bool $preserveServiceEnd,
        ?string $successorRegistrationDate,
        ?string $nextPeriodEndOverride
    ): ?int {
        $serviceTotal = round((float) $servicios->sum('monto'), 2);
        $openStates = ['1', '2', '4', 'NUEVO', 'PagoCredito', 'PENDIENTE', 'FACTURADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito'];
        $periodCharges = DB::table('cuentasporcobrar')
            ->where('cliente_idcliente', $cxc->cliente_idcliente)
            ->where('moneda_idmoneda', $cxc->moneda_idmoneda)
            ->whereIn('estado', $openStates)
            ->orderBy('idcuentasPorCobrar')
            ->get();
        $successorPrefix = 'REN-CXC-' . (int) $cxc->idcuentasPorCobrar . ' P ';
        $periodEndByService = [];
        $periodStartByService = [];
        $tipoCobroByService = [];
        foreach ($servicios as $servicio) {
            $serviceId = (int) $servicio->idservicioCliente;
            $serviceTipoCobroId = (int) ($servicio->tipoCobro_idtipoCobros ?? 0);
            if ($serviceTipoCobroId <= 0) {
                $serviceTipoCobroId = $this->tipoCobroIdDesdeServicio([
                    'servicio_periodo' => $servicio->servicio_periodo ?? null,
                ]);
            }
            if ($serviceTipoCobroId <= 0) {
                $serviceTipoCobroId = (int) ($servicio->tipoCobro_idtipoCobros ?? $tipoCobroId ?? $cxc->tipoCobro_idtipoCobros);
            }
            $serviceTipoCobro = DB::table('tipocobro')->where('idtipoCobros', $serviceTipoCobroId)->first();
            $months = ServicePeriod::months($servicio->servicio_periodo ?? null);
            $serviceStart = $periodEndOverride
                ?? ($servicio->fecheVencimiento ?? $fechaInicio);
            $serviceStart = Carbon::parse($serviceStart)->toDateString();
            $serviceEnd = $months !== null
                ? ServicePeriod::addToDate($serviceStart, $months)->format('Y-m-d')
                : (strtoupper(trim((string) ($serviceTipoCobro->recurrencia ?? ''))) === 'D'
                    ? Carbon::parse($serviceStart)->addDays(max(1, (int) ($serviceTipoCobro->tiempo ?? 1)))->format('Y-m-d')
                    : Carbon::parse($serviceStart)->addMonthsNoOverflow(max(1, (int) ($serviceTipoCobro->tiempo ?? 1)))->format('Y-m-d'));
            $serviceEnd = $this->applyPeriodEndDay($serviceEnd, $nextPeriodEndOverride);
            $periodStartByService[$serviceId] = $serviceStart;
            $periodEndByService[$serviceId] = $serviceEnd;
            $tipoCobroByService[$serviceId] = $serviceTipoCobroId;
        }

        $legacyGroup = $periodCharges->first(fn ($charge): bool =>
            abs((float) $charge->montoOriginal - $serviceTotal) < 0.005
            && (float) $charge->montoActual <= (float) $charge->montoOriginal
            && preg_match('/SC-([0-9,]+)/', (string) ($charge->descripcion ?? '')) === 1
            && str_starts_with((string) ($charge->descripcion ?? ''), $successorPrefix)
            && count(array_unique($periodEndByService)) === 1
            && !empty($charge->fechaCancelacion)
            && Carbon::parse($charge->fechaCancelacion)->toDateString() === reset($periodEndByService)
        );
        $legacyBalanceRemaining = round((float) ($legacyGroup->montoActual ?? 0), 2);
        $nextIds = [];

        foreach ($servicios as $index => $servicio) {
            $serviceId = (int) $servicio->idservicioCliente;
            $serviceAmount = round((float) $servicio->monto, 2);
            $serviceStart = $periodStartByService[$serviceId];
            $serviceEnd = $periodEndByService[$serviceId];
            $serviceTipoCobroId = $tipoCobroByService[$serviceId];
            $serviceDescription = mb_substr(
                'REN-CXC-' . (int) $cxc->idcuentasPorCobrar
                    . ' P ' . Carbon::parse($serviceStart)->format('d/m/Y')
                    . ' a ' . Carbon::parse($serviceEnd)->format('d/m/Y')
                    . ' SC-' . $serviceId,
                0,
                50
            );
            $unitBalance = $legacyGroup
                ? ($index === $servicios->count() - 1
                    ? $legacyBalanceRemaining
                    : round((float) $legacyGroup->montoActual * $serviceAmount / max($serviceTotal, 0.01), 2))
                : $serviceAmount;
            $legacyBalanceRemaining = round($legacyBalanceRemaining - ($legacyGroup ? $unitBalance : 0), 2);
            $existingServiceCxc = $periodCharges
                ->reject(fn ($charge): bool => $legacyGroup
                    && (int) $charge->idcuentasPorCobrar === (int) $legacyGroup->idcuentasPorCobrar)
                ->first(function ($charge) use ($serviceId, $serviceEnd, $successorPrefix): bool {
                    $hasServiceMarker = preg_match(
                        '/(^|[^A-Za-z0-9])SC-' . $serviceId . '([^0-9]|$)/',
                        (string) ($charge->descripcion ?? '')
                    ) === 1;
                    $isNextOfCurrentPeriod = str_starts_with((string) ($charge->descripcion ?? ''), $successorPrefix);
                    $isGeneratedRenewal = str_starts_with((string) ($charge->descripcion ?? ''), 'REN-CXC-');
                    $hasExpectedEnd = !empty($charge->fechaCancelacion)
                        && Carbon::parse($charge->fechaCancelacion)->toDateString() === $serviceEnd;
                    return $hasServiceMarker && $isGeneratedRenewal
                        && ($isNextOfCurrentPeriod || $hasExpectedEnd);
                });
            $successorId = null;

            if ($existingServiceCxc) {
                $successorId = (int) $existingServiceCxc->idcuentasPorCobrar;
                DB::table('cuentasporcobrar')
                    ->where('idcuentasPorCobrar', $successorId)
                    ->update([
                        'descripcion' => $serviceDescription,
                        'fechaCancelacion' => $serviceEnd,
                        'tipoCobro_idtipoCobros' => $serviceTipoCobroId,
                    ]);
            } elseif ($legacyGroup && $index === 0) {
                $successorId = (int) $legacyGroup->idcuentasPorCobrar;
                DB::table('cuentasporcobrar')
                    ->where('idcuentasPorCobrar', $successorId)
                    ->update([
                        'descripcion' => $serviceDescription,
                        'montoOriginal' => $serviceAmount,
                        'montoActual' => $unitBalance,
                        'fechaCancelacion' => $serviceEnd,
                        'tipoCobro_idtipoCobros' => $serviceTipoCobroId,
                    ]);
            } else {
                $successorId = $this->crearCobroDesdeServicio([
                    'cliente_idcliente' => $cxc->cliente_idcliente,
                    'montoServicioBase' => $serviceAmount,
                    'moneda_idmoneda' => $cxc->moneda_idmoneda,
                    'tipoCobro_idtipoCobros' => $serviceTipoCobroId,
                    'descripcion' => $serviceDescription,
                    'vehiculo_placa' => $servicio->vehiculo_placa ?? '',
                    'idservicioCliente' => $serviceId,
                    'fechaInicio' => $serviceStart,
                    'fecheVencimiento' => $serviceEnd,
                    'docReferencia' => $legacyGroup->docReferencia ?? null,
                ]);
            }

            if ($successorId > 0) {
                if ($legacyGroup && !($index === 0 && (int) $successorId === (int) $legacyGroup->idcuentasPorCobrar)) {
                    DB::table('cuentasporcobrar')
                        ->where('idcuentasPorCobrar', $successorId)
                        ->update([
                            'montoOriginal' => $serviceAmount,
                            'montoActual' => $unitBalance,
                            'estado' => $legacyGroup->estado,
                            'docReferencia' => $legacyGroup->docReferencia,
                            'tipoCobro_idtipoCobros' => $serviceTipoCobroId,
                        ]);
                }
                $nextIds[] = $successorId;
                $registrationDate = $successorRegistrationDate ?? ($legacyGroup->fechaRegistro ?? null);
                if ($registrationDate !== null) {
                    DB::table('cuentasporcobrar')
                        ->where('idcuentasPorCobrar', $successorId)
                        ->whereNull('fechaRegistro')
                        ->update(['fechaRegistro' => $registrationDate]);
                }
                if (Schema::hasTable(self::CXC_SERVICE_LINK_TABLE)) {
                    if (
                        $legacyGroup
                        && $index === 0
                        && (int) $successorId === (int) $legacyGroup->idcuentasPorCobrar
                    ) {
                        DB::table(self::CXC_SERVICE_LINK_TABLE)
                            ->where('cuentasPorCobrar_idcuentasPorCobrar', $successorId)
                            ->where('servicioCliente_idservicioCliente', '!=', $serviceId)
                            ->delete();
                        DB::table(self::CXC_SERVICE_LINK_TABLE)
                            ->where('cuentasPorCobrar_idcuentasPorCobrar', $successorId)
                            ->where('servicioCliente_idservicioCliente', $serviceId)
                            ->update(['montoServicio' => $serviceAmount]);
                    }
                    DB::table(self::CXC_SERVICE_LINK_TABLE)->insertOrIgnore([
                        'cuentasPorCobrar_idcuentasPorCobrar' => $successorId,
                        'servicioCliente_idservicioCliente' => $serviceId,
                        'montoServicio' => $serviceAmount,
                    ]);
                }
            }
        }

        foreach ($servicios as $servicio) {
            $serviceId = (int) $servicio->idservicioCliente;
            DB::table('serviciocliente')
                ->where('idservicioCliente', $serviceId)
                ->update(['fecheVencimiento' => $periodEndByService[$serviceId]]);
        }

        return $nextIds[0] ?? null;
    }

    private function applyPeriodEndDay(string $periodEnd, ?string $requestedPeriodEnd): string
    {
        $periodEndDate = Carbon::parse($periodEnd);
        if (!$requestedPeriodEnd) {
            return $periodEndDate->toDateString();
        }

        $requestedDay = Carbon::parse($requestedPeriodEnd)->day;

        return $periodEndDate->day(min($requestedDay, $periodEndDate->daysInMonth))->toDateString();
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
            ->whereIn('estado', ['1', '2', '4', 'NUEVO', 'PagoCredito', 'PENDIENTE', 'FACTURADO', 'VENCIDO', 'Pendiente Pago parcial', 'Pendiente a credito'])
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
        $serviciosQuery = DB::table('serviciocliente as sc')
            ->leftJoin('almacen as a', 'a.idalmacen', '=', 'sc.almacen_idalmacen')
            ->whereRaw('LOWER(COALESCE(sc.estado, "")) = ?', ['activo'])
            ->whereNotNull('sc.fechaInicio')
            ->whereNotNull('sc.fecheVencimiento')
            ->select('sc.*', 'a.periodo as servicio_periodo');
        if (Schema::hasColumn('serviciocliente', 'observacion')) {
            $serviciosQuery->where(function ($query): void {
                $query->whereNull('sc.observacion')
                    ->orWhere('sc.observacion', 'not like', '%' . self::SCHEDULED_DEACTIVATION_MARKER . '%');
            });
        }
        $servicios = $serviciosQuery->get()->groupBy('cliente_idcliente');

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
                    ->where('descripcion', 'not like', 'CCXC:%')
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

    public function revertirPagoCancelado(
        int|string $idCxc,
        string $usuario,
        string $motivo,
        ?int $expectedServiceId = null
    ): array
    {
        return DB::transaction(function () use ($idCxc, $usuario, $motivo, $expectedServiceId): array {
            $cxc = DB::table('cuentasporcobrar')
                ->where('idcuentasPorCobrar', $idCxc)
                ->lockForUpdate()
                ->first();

            if (!$cxc) {
                throw new \RuntimeException('Cuenta por cobrar no encontrada.');
            }

            if (!in_array((string) $cxc->estado, ['3', 'CANCELADO', 'Cancelado Detracción', 'Pendiente Autodetracción', 'Pendiente Autoretención', 'Pendiente a Detracción', 'Pendiente a Retención'], true)) {
                throw new \RuntimeException('Solo se pueden revertir cuentas canceladas.');
            }

            $cancelledAt = DB::table('detallecxc')
                ->where('cuentasPorCobrar_idcuentasPorCobrar', $idCxc)
                ->where('estado', '1')
                ->whereNotNull('fechaPago')
                ->max('fechaPago');
            $hasCreditNoteApplications = Schema::hasTable('nota_credito_aplicacion');
            $noteCancelledAt = $hasCreditNoteApplications
                ? DB::table('nota_credito_aplicacion')
                    ->where('cuentasPorCobrar_idcuentasPorCobrar', $idCxc)
                    ->where('estado', '1')
                    ->max('fechaAplicacion')
                : null;
            $cancelledAt = max(array_filter([$cancelledAt, $noteCancelledAt]) ?: [null]);
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
                ->where(function ($query): void {
                    $query->whereIn('b.tipoMov', ['I', 'T', 'C'])
                        ->orWhere(function ($autodetraccionQuery): void {
                            $autodetraccionQuery
                                ->where('b.tipoMov', 'S')
                                ->where('b.informacion', 'like', 'Autodetracción%');
                        });
                })
                ->where('b.informacion', 'not like', '[ANULADO]%')
                ->select(['dc.iddetalleCXC', 'dc.formaPago_idformaPago', 'dc.moneda_idmoneda', 'b.idbancos', 'b.monto', 'b.entidadBancaria_identidadBancaria'])
                ->get();

            $noteApplications = $hasCreditNoteApplications
                ? DB::table('nota_credito_aplicacion')
                    ->where('cuentasPorCobrar_idcuentasPorCobrar', $idCxc)
                    ->where('estado', '1')
                    ->get()
                : collect();

            if ($paymentDetails->isEmpty() && $noteApplications->isEmpty()) {
                throw new \RuntimeException('No se encontró un pago activo para revertir.');
            }

            $period = $this->periodFromDescription((string) ($cxc->descripcion ?? ''));
            $periodEnd = $cxc->fechaCancelacion ?? ($period[1] ?? null);
            $currentServiceIds = $this->serviceIdsForReversal($cxc);
            if (
                $expectedServiceId !== null
                && $currentServiceIds !== [$expectedServiceId]
            ) {
                throw new \RuntimeException('Este pago no corresponde únicamente al vehículo seleccionado; no se puede anular como pago individual.');
            }
            $deletedSuccessorIds = $this->deleteUnpaidSuccessorCxcs($cxc, $periodEnd, $currentServiceIds);

            $reason = trim($motivo) !== '' ? trim($motivo) : 'Reversión de pago dentro del plazo permitido';
            foreach ($paymentDetails as $detail) {
                DB::table('bancos')->where('idbancos', $detail->idbancos)->update([
                    'informacion' => mb_substr('[ANULADO] ' . $reason . ' | ' . (string) DB::table('bancos')->where('idbancos', $detail->idbancos)->value('informacion'), 0, 100),
                ]);

                DB::table('detallecxc')->where('iddetalleCXC', $detail->iddetalleCXC)->update([
                    'estado' => '0',
                ]);

            }
            if ($noteApplications->isNotEmpty()) {
                DB::table('nota_credito_aplicacion')
                    ->whereIn('idnotaCreditoAplicacion', $noteApplications->pluck('idnotaCreditoAplicacion'))
                    ->update(['estado' => '0']);
                foreach ($noteApplications->pluck('nota_credito_idnotacredito')->unique() as $noteId) {
                    $noteAmount = (float) DB::table('nota_credito')->where('idnotacredito', $noteId)->value('monto');
                    $noteUsed = (float) DB::table('nota_credito_aplicacion')
                        ->where('nota_credito_idnotacredito', $noteId)
                        ->where('estado', '1')
                        ->sum('monto');
                    if ($noteAmount > $noteUsed + 0.01) {
                        DB::table('nota_credito')
                            ->where('idnotacredito', $noteId)
                            ->where('estado', 'A')
                            ->update(['estado' => 'E']);
                    }
                }
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

            $restoredState = $periodEnd && Carbon::parse($periodEnd)->startOfDay()->lt(Carbon::today()) ? '4' : '1';
            DB::table('cuentasporcobrar')->where('idcuentasPorCobrar', $idCxc)->update([
                'montoActual' => $cxc->montoOriginal,
                'estado' => $restoredState,
                'docReferencia' => null,
                'docfactura' => null,
            ]);

            $serviceQuery = DB::table('serviciocliente')
                ->where('cliente_idcliente', $cxc->cliente_idcliente);
            if ($currentServiceIds !== []) {
                $serviceQuery->whereIn('idservicioCliente', $currentServiceIds);
            } else {
                if ($cxc->docReferencia === null || trim((string) $cxc->docReferencia) === '') {
                    $serviceQuery->whereNull('docReferencia');
                } else {
                    $serviceQuery->where('docReferencia', $cxc->docReferencia);
                }
            }
            $serviceQuery->update(['docReferencia' => null]);

            return [
                'idcuentasPorCobrar' => $idCxc,
                'estado' => $restoredState,
                'montoActual' => (float) $cxc->montoOriginal,
                'cxcSiguientesEliminados' => $deletedSuccessorIds,
            ];
        });
    }

    public function revertirPagoCanceladoGrupo(array $cxcIds, string $usuario, string $motivo): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $cxcIds))));
        if ($ids === []) {
            throw new \RuntimeException('Debe seleccionar al menos una cuenta por cobrar para revertir.');
        }

        return DB::transaction(function () use ($ids, $usuario, $motivo): array {
            $cxcs = DB::table('cuentasporcobrar')
                ->whereIn('idcuentasPorCobrar', $ids)
                ->lockForUpdate()
                ->get(['idcuentasPorCobrar', 'cliente_idcliente', 'moneda_idmoneda']);
            if ($cxcs->count() !== count($ids)) {
                throw new \RuntimeException('Una de las cuentas por cobrar del grupo ya no existe.');
            }
            if (
                $cxcs->pluck('cliente_idcliente')->map(fn ($id): string => (string) $id)->unique()->count() !== 1
                || $cxcs->pluck('moneda_idmoneda')->map(fn ($id): int => (int) $id)->unique()->count() !== 1
            ) {
                throw new \RuntimeException('Las cuentas del grupo deben pertenecer al mismo cliente y moneda.');
            }

            $results = [];
            foreach ($ids as $id) {
                $results[] = $this->revertirPagoCancelado($id, $usuario, $motivo);
            }

            return [
                'results' => $results,
                'count' => count($results),
                'estado' => collect($results)->contains(fn (array $result): bool => $result['estado'] === '1')
                    ? '1'
                    : '4',
            ];
        });
    }

    private function serviceIdsForReversal(object $cxc): array
    {
        preg_match('/SC-([0-9,]+)/', (string) ($cxc->descripcion ?? ''), $matches);
        $serviceIds = isset($matches[1])
            ? array_values(array_filter(array_map('intval', explode(',', $matches[1]))))
            : [];

        if (Schema::hasTable(self::CXC_SERVICE_LINK_TABLE)) {
            $serviceIds = array_merge(
                $serviceIds,
                DB::table(self::CXC_SERVICE_LINK_TABLE)
                    ->where('cuentasPorCobrar_idcuentasPorCobrar', $cxc->idcuentasPorCobrar)
                    ->pluck('servicioCliente_idservicioCliente')
                    ->map(fn($id): int => (int) $id)
                    ->all()
            );
        }

        return array_values(array_unique(array_filter($serviceIds)));
    }

    private function deleteUnpaidSuccessorCxcs(object $cxc, ?string $periodEnd, array $currentServiceIds): array
    {
        $successorPrefix = 'REN-CXC-' . $cxc->idcuentasPorCobrar . ' P ';
        $candidates = DB::table('cuentasporcobrar')
            ->where('cliente_idcliente', $cxc->cliente_idcliente)
            ->where('moneda_idmoneda', $cxc->moneda_idmoneda)
            ->where('idcuentasPorCobrar', '!=', $cxc->idcuentasPorCobrar)
            ->lockForUpdate()
            ->get([
                'idcuentasPorCobrar',
                'descripcion',
                'fechaCancelacion',
                'docReferencia',
            ]);

        if ($candidates->isEmpty()) {
            return [];
        }

        $candidateIds = $candidates->pluck('idcuentasPorCobrar')->all();
        $serviceIdsBySuccessor = [];
        foreach ($candidates as $candidate) {
            preg_match('/SC-([0-9,]+)/', (string) ($candidate->descripcion ?? ''), $matches);
            $serviceIdsBySuccessor[(int) $candidate->idcuentasPorCobrar] = isset($matches[1])
                ? array_values(array_filter(array_map('intval', explode(',', $matches[1]))))
                : [];
        }

        if (Schema::hasTable(self::CXC_SERVICE_LINK_TABLE)) {
            $linkedServices = DB::table(self::CXC_SERVICE_LINK_TABLE)
                ->whereIn('cuentasPorCobrar_idcuentasPorCobrar', $candidateIds)
                ->get(['cuentasPorCobrar_idcuentasPorCobrar', 'servicioCliente_idservicioCliente']);
            foreach ($linkedServices as $link) {
                $serviceIdsBySuccessor[(int) $link->cuentasPorCobrar_idcuentasPorCobrar][] =
                    (int) $link->servicioCliente_idservicioCliente;
            }
        }

        $periodEndDate = $periodEnd ? Carbon::parse($periodEnd)->toDateString() : null;
        $successors = $candidates->filter(function ($candidate) use (
            $successorPrefix,
            $periodEndDate,
            $currentServiceIds,
            $serviceIdsBySuccessor
        ): bool {
            if (str_starts_with((string) ($candidate->descripcion ?? ''), $successorPrefix)) {
                return true;
            }

            if (!$periodEndDate || $currentServiceIds === []) {
                return false;
            }

            $candidatePeriod = $this->periodFromDescription((string) ($candidate->descripcion ?? ''));
            $candidateServiceIds = $serviceIdsBySuccessor[(int) $candidate->idcuentasPorCobrar] ?? [];

            return ($candidatePeriod[0] ?? null) === $periodEndDate
                && array_intersect($currentServiceIds, $candidateServiceIds) !== [];
        })->values();

        if ($successors->isEmpty()) {
            return [];
        }

        $successorIds = $successors->pluck('idcuentasPorCobrar')->all();
        foreach ($successors as $successor) {
            $hasPaymentOrMovement = DB::table('detallecxc')
                ->where('cuentasPorCobrar_idcuentasPorCobrar', $successor->idcuentasPorCobrar)
                ->where(function ($query): void {
                    $query->whereNotNull('fechaPago')
                        ->orWhereNotNull('bancos_idbancos');
                })
                ->exists();
            $hasCreditNoteApplication = Schema::hasTable('nota_credito_aplicacion')
                && DB::table('nota_credito_aplicacion')
                    ->where('cuentasPorCobrar_idcuentasPorCobrar', $successor->idcuentasPorCobrar)
                    ->where('estado', '1')
                    ->exists();

            if ($hasPaymentOrMovement || $hasCreditNoteApplication || trim((string) ($successor->docReferencia ?? '')) !== '') {
                throw new \RuntimeException(
                    'No se puede revertir el pago porque el CXC siguiente #' . $successor->idcuentasPorCobrar
                    . ' ya tiene pagos, movimientos o un documento asociado.'
                );
            }
        }

        DB::table('detallecxc')
            ->whereIn('cuentasPorCobrar_idcuentasPorCobrar', $successorIds)
            ->delete();

        if (Schema::hasTable(self::CXC_SERVICE_LINK_TABLE)) {
            DB::table(self::CXC_SERVICE_LINK_TABLE)
                ->whereIn('cuentasPorCobrar_idcuentasPorCobrar', $successorIds)
                ->delete();
        }

        DB::table('cuentasporcobrar')
            ->whereIn('idcuentasPorCobrar', $successorIds)
            ->delete();

        if ($periodEnd && Schema::hasTable('serviciocliente')) {
            foreach ($successors as $successor) {
                $successorServiceIds = array_values(array_unique(
                    $serviceIdsBySuccessor[(int) $successor->idcuentasPorCobrar] ?? []
                ));
                if ($successorServiceIds === []) {
                    continue;
                }

                $successorPeriod = $this->periodFromDescription((string) ($successor->descripcion ?? ''));
                $successorPeriodEnd = $successor->fechaCancelacion ?? ($successorPeriod[1] ?? null);
                if (!$successorPeriodEnd) {
                    continue;
                }

                DB::table('serviciocliente')
                    ->where('cliente_idcliente', $cxc->cliente_idcliente)
                    ->whereIn('idservicioCliente', $successorServiceIds)
                    ->whereDate('fecheVencimiento', Carbon::parse($successorPeriodEnd)->toDateString())
                    ->update(['fecheVencimiento' => Carbon::parse($periodEnd)->toDateString()]);
            }
        }

        return array_map('intval', $successorIds);
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

    private function descriptionWithUpdatedServicePeriod(string $description, string $newRange, int $serviceId): string
    {
        $serviceMarker = 'SC-' . $serviceId;
        if (preg_match('/\d{2}\/\d{2}\/\d{4}\s+a\s+\d{2}\/\d{2}\/\d{4}/', $description, $matches) !== 1) {
            return 'CXC ' . $newRange . ' ' . $serviceMarker;
        }

        $range = $matches[0];
        $rangePosition = mb_strpos($description, $range, 0, 'UTF-8');
        $prefix = mb_substr($description, 0, $rangePosition, 'UTF-8');
        $suffix = mb_substr($description, $rangePosition + mb_strlen($range, 'UTF-8'), null, 'UTF-8');
        if (preg_match('/(^|[^A-Za-z0-9])' . preg_quote($serviceMarker, '/') . '([^0-9]|$)/', $description) !== 1) {
            $suffix .= ' ' . $serviceMarker;
        }

        $maxPrefixLength = max(0, 50 - mb_strlen($newRange, 'UTF-8') - mb_strlen($suffix, 'UTF-8'));
        return mb_substr($prefix, 0, $maxPrefixLength, 'UTF-8') . $newRange . $suffix;
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

        $meses = ServicePeriod::months($periodo);
        if ($meses === null) {
            return 0;
        }

        return (int) (DB::table('tipocobro')
            ->whereRaw('UPPER(TRIM(recurrencia)) = ?', ['M'])
            ->where('tiempo', $meses)
            ->value('idtipoCobros') ?? 0);
    }

}
