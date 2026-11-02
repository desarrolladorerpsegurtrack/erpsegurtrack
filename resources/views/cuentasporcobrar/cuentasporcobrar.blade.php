@extends('layouts.crud-table')

@section('content')
    @php
        $erpAuthState = session()->get('erp_auth', []);
        $servicePriceActions = collect($erpAuthState['permissions']['cuentasporcobrar'] ?? [])
            ->map(fn($action) => \App\Support\ErpPermission::normalizeAction((string) $action));
        $isErpAdmin = collect($erpAuthState['roles'] ?? [])
            ->map(fn($role) => mb_strtolower(trim((string) $role)))
            ->contains('admin');
        $canEditServicePrice = $isErpAdmin || $servicePriceActions->contains('editar');
        $erpPermissions = collect($erpAuthState['permissions'] ?? []);
        $cxcActions = collect($erpPermissions->get('cuentasporcobrar', []))
            ->map(fn($action) => \App\Support\ErpPermission::normalizeAction((string) $action));
        $canCreateCxc = $isErpAdmin || $cxcActions->contains('crear');
        $canEditCxc = $isErpAdmin || $cxcActions->contains('editar');
        $canApproveCxc = $isErpAdmin || $cxcActions->contains('aprobar');
        $canExportCxc = $isErpAdmin || $cxcActions->contains('exportar');
        $canDeactivateCxcServices = $isErpAdmin || $cxcActions->contains('dar_de_baja');
        $quotationActions = collect($erpPermissions->get('ventas.cotizaciones', []))
            ->map(fn($action) => \App\Support\ErpPermission::normalizeAction((string) $action));
        if (!$isErpAdmin && $quotationActions->isEmpty()) {
            $hasGranularSalesPermissions = $erpPermissions->keys()
                ->contains(fn($key) => is_string($key) && str_starts_with(mb_strtolower(trim($key)), 'ventas.'));
            if (!$hasGranularSalesPermissions) {
                $quotationActions = collect($erpPermissions->get('ventas', []))
                    ->map(fn($action) => \App\Support\ErpPermission::normalizeAction((string) $action));
            }
        }
        $canAnnulQuotations = $isErpAdmin || $quotationActions->contains('anular');
    @endphp
    @if($canAnnulQuotations)
        @push('modals')
            <div id="quote-annul-modal" class="erp-modal-backdrop items-center justify-center p-4"
                style="z-index:2147483647 !important;background:rgba(0,0,0,.8);" role="dialog" aria-modal="true"
                aria-labelledby="quote-annul-title">
                <div
                    style="width:720px;max-width:92%;min-height:260px;margin:0 auto;padding:32px;position:relative;border-radius:10px;background:#ffffff;box-shadow:0 20px 40px rgba(2,6,23,0.22);">
                    <button type="button" data-quote-annul-close aria-label="Cerrar"
                        title="Si confirmas, el registro quedará anulado."
                        style="position:absolute;right:16px;top:16px;height:40px;width:40px;border-radius:9999px;border:1px solid #e2e8f0;background:#ffffff;color:#64748b;display:flex;align-items:center;justify-content:center;">
                        <i data-lucide="x" aria-hidden="true" class="h-4 w-4"></i>
                    </button>
                    <div
                        style="margin:0 auto 24px;display:flex;height:64px;width:64px;align-items:center;justify-content:center;border-radius:9999px;border:1px solid #ef4444;background:#fff7f7;color:#ef4444;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            data-lucide="x-circle" style="width:22px;height:22px" class="lucide lucide-x-circle">
                            <circle cx="12" cy="12" r="10"></circle>
                            <path d="m15 9-6 6"></path>
                            <path d="m9 9 6 6"></path>
                        </svg>
                    </div>
                    <h2 id="quote-annul-title" class="text-left text-lg font-semibold text-slate-800">¿Estás seguro de anular?</h2>
                    <p id="quote-annul-summary" class="mt-2 text-sm leading-5 text-slate-500"></p>
                    <p class="mt-2 text-sm leading-5 text-slate-500">Si tiene pagos, también se revertirán sus movimientos bancarios
                        y quedará anulada su cuenta por cobrar.</p>
                    <div class="mt-5 flex justify-end gap-3">
                        <button type="button" data-quote-annul-close class="erp-btn-secondary"
                            style="min-width:120px;justify-content:center;">Cancelar</button>
                        <form method="POST" action="{{ route('modules.cuentasporcobrar.quotations.anular') }}">
                            @csrf
                            <div id="quote-annul-hidden-ids"></div>
                            <button type="submit" class="erp-btn-primary"
                                style="min-width:120px;justify-content:center;background-color:#b41B29;">Anular</button>
                        </form>
                    </div>
                </div>
            </div>
        @endpush
    @endif
    <div class="erp-cuentas-layout">
        <div id="cxc-list-wrapper" class="erp-cuentas-card"
            data-loaded-tab="{{ $activeTab !== 'servicios' || $serviceDataLoaded ? $activeTab : '' }}">
            <div class="erp-cuentas-header">
                <h2>Cuentas por Cobrar</h2>

                <div class="erp-tabs" aria-label="Tabs de cuentas por cobrar">
                    <button type="button" class="tab-button {{ request('tab') === 'servicios' ? '' : 'active' }}"
                        data-tab="cotizaciones">Cotizaciones</button>
                    <button type="button" class="tab-button {{ request('tab') === 'servicios' ? 'active' : '' }}"
                        data-tab="servicios">Servicios por vencer</button>
                </div>
            </div>

            <div class="erp-cuentas-body">
                <div id="cxc-global-success-alert" data-cxc-session-notice
                    class="{{ session('success') ? '' : 'hidden' }} mb-4 rounded-lg border px-4 py-3 text-base font-semibold relative"
                    style="border-color:#16a34a;background-color:#dcfce7;color:#14532d;"
                    role="status" aria-live="polite">
                    <span id="cxc-global-success-text">✓ {{ session('success') }}</span>
                    <button type="button"
                        class="absolute top-0 right-0 mt-2 mr-2 text-lg font-bold text-gray-600 hover:text-gray-800"
                        onclick="this.parentElement.classList.add('hidden');">&times;</button>
                </div>
                <div id="cxc-global-error-alert" data-cxc-session-notice
                    class="{{ session('error') ? '' : 'hidden' }} mb-4 rounded-lg border px-4 py-3 text-base font-semibold relative"
                    style="border-color:#a31616;background-color:#fcdcdc;color:#531414;" role="alert">
                    <span id="cxc-global-error-text">✕ {{ session('error') }}</span>
                    <button type="button"
                        class="absolute top-0 right-0 mt-2 mr-2 text-lg font-bold text-gray-600 hover:text-gray-800"
                        onclick="this.parentElement.classList.add('hidden');">&times;</button>
                </div>
                <div id="tab-cotizaciones" class="tab-panel {{ request('tab') === 'servicios' ? 'hidden' : '' }}">
                    <div id="quote-stats" class="erp-section-stats">
                        @foreach($quoteStats ?? [] as $stat)
                            <div class="erp-section-stat">
                                <div class="erp-section-stat-label">{{ $stat['label'] }}</div>
                                <div class="erp-section-stat-value">{{ number_format($stat['value'], 0, ',', '.') }}</div>
                            </div>
                        @endforeach
                    </div>
                    <div class="erp-toolbar">
                        <span class="erp-subtitle">Cotizaciones en estado <strong id="quote-subtitle-status">Aprobado(SP) -
                                Pendiente</strong></span>
                        <div class="flex items-center gap-2">
                            @if($canAnnulQuotations)
                                <button type="button" id="quote-annul-open"
                                    class="erp-btn-primary bg-red-600 hover:bg-red-700 text-white font-bold px-4 py-1.5 rounded-md text-xs transition duration-200"
                                    disabled>
                                    Anular
                                </button>
                            @endif
                            @if($canExportCxc)
                                <a href="{{ route('modules.cuentasporcobrar.quotations.export', ['format' => 'xlsx', 'quote_status' => 'all']) }}"
                                    id="quote-export-xlsx" class="erp-btn-secondary"
                                    style="display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; text-decoration: none;">
                                    <i data-lucide="download" aria-hidden="true" class="h-4 w-4"></i>
                                    Exportar XLSX
                                </a>
                            @endif
                        </div>
                    </div>

                    <form id="quote-filter-form" class="erp-service-filters" onsubmit="return false;">
                        <label class="erp-service-filter-field">
                            <span>N° Cotiz</span>
                            <input type="search" id="quote-filter-number" value="{{ $quoteFilters['quote_number'] ?? '' }}"
                                placeholder="N° de cotización" autocomplete="off">
                        </label>
                        <label class="erp-service-filter-field">
                            <span>Cliente</span>
                            <input type="search" id="quote-filter-client" value="{{ $quoteFilters['quote_client'] ?? '' }}"
                                placeholder="Nombre o ID del cliente" autocomplete="off">
                        </label>
                        <label class="erp-service-filter-field">
                            <span>Formato de pago</span>
                            <select id="quote-filter-payment-format">
                                <option value="">Todos</option>
                                @foreach($formaPagoOptions as $paymentOption)
                                    <option value="{{ $paymentOption['value'] }}"
                                        @selected(($quoteFilters['quote_payment_format'] ?? '') === $paymentOption['value'])>
                                        {{ $paymentOption['label'] }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                        <label class="erp-service-filter-field">
                            <span>Vigencia de oferta</span>
                            <select id="quote-filter-validity">
                                <option value="">Todas</option>
                                @foreach($quoteValidityOptions as $validityOption)
                                    <option value="{{ $validityOption['value'] }}" @selected(($quoteFilters['quote_validity'] ?? '') === $validityOption['value'])>{{ $validityOption['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="erp-service-filter-field">
                            <span>Estado</span>
                            <select id="quote-filter-status">
                                <option value="all" @selected(in_array(($quoteFilters['quote_status'] ?? 'all'), ['', 'all'], true))>Todos</option>
                                <option value="pending" @selected(in_array(($quoteFilters['quote_status'] ?? ''), ['1', 'pending'], true))>Pendiente</option>
                                <option value="pending_credit" @selected(($quoteFilters['quote_status'] ?? '') === 'pending_credit')>Pendiente Credito</option>
                                <option value="pending_payment" @selected(($quoteFilters['quote_status'] ?? '') === 'pending_payment')>Pendiente pago</option>
                                <option value="pending_autodetraccion" @selected(($quoteFilters['quote_status'] ?? '') === 'pending_autodetraccion')>Pendiente Autodetracción</option>
                                <option value="pending_detraccion" @selected(($quoteFilters['quote_status'] ?? '') === 'pending_detraccion')>Pendiente Detracción</option>
                                <option value="pending_retencion" @selected(($quoteFilters['quote_status'] ?? '') === 'pending_retencion')>Pendiente Retención</option>
                                <option value="approved" @selected(in_array(($quoteFilters['quote_status'] ?? ''), ['2', 'approved'], true))>Aprobado</option>
                            </select>
                        </label>
                        <label class="erp-service-filter-field">
                            <span>Servicio</span>
                            <select id="quote-filter-service">
                                <option value="">Todos</option>
                                @foreach(['EQUIPAMIENTO' => 'EQUIPAMIENTO', 'PLANES' => 'PLANES', 'SERVICIOS TÉCNICOS' => 'SERVICIOS TÉCNICOS'] as $serviceValue => $serviceLabel)
                                    <option value="{{ $serviceValue }}" @selected(($quoteFilters['quote_service'] ?? '') === $serviceValue)>{{ $serviceLabel }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="erp-service-filter-field">
                            <span>Fecha Emisión</span>
                            <input type="date" id="quote-filter-date" value="{{ $quoteFilters['quote_date'] ?? '' }}">
                        </label>
                        <div class="erp-service-filter-actions">
                            <button type="button" id="quote-filter-apply-btn" class="erp-btn-primary">
                                <i data-lucide="list-filter" aria-hidden="true"></i> Aplicar
                            </button>
                            <button type="button" id="quote-filter-clear-btn" class="erp-btn-secondary">
                                Limpiar
                            </button>
                        </div>
                    </form>

                    <div id="quote-results">
                        @if($activeTab !== 'cotizaciones')
                            <div class="erp-empty-state" aria-live="polite" hidden></div>
                        @elseif($cotizacionesPendientes->isEmpty())
                            <div class="erp-empty-state">
                                No se encontraron cotizaciones.
                            </div>
                        @else
                            <div class="erp-table-wrap">
                                <table class="erp-table erp-quotations-table">
                                    <colgroup>
                                        <col class="col-select">
                                        <col class="col-quote">
                                        <col class="col-client">
                                        <col class="col-amount">
                                        <col class="col-validity">
                                        <col class="col-payment">
                                        <col class="col-date">
                                        <col class="col-group">
                                        <col class="col-service">
                                        <col class="col-file">
                                        <col class="col-action">
                                    </colgroup>
                                    <thead>
                                        <tr>
                                            <th class="bg-slate-50 text-center">
                                                <input type="checkbox" id="quote-select-all"
                                                    aria-label="Seleccionar todas las cotizaciones de esta página">
                                            </th>
                                            <th class="bg-slate-50">N° Cotiz</th>
                                            <th class="bg-slate-50">Cliente</th>
                                            <th class="bg-slate-50">Monto</th>
                                            <th class="bg-slate-50">Vigencia de oferta</th>
                                            <th class="bg-slate-50">Formato de pago</th>
                                            <th class="bg-slate-50">Fecha Emision</th>
                                            <th class="bg-slate-50">Grupo</th>
                                            <th class="bg-slate-50">Servicio</th>
                                            <th class="bg-slate-50">Estado</th>
                                            <th class="bg-slate-50">Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($cotizacionesPendientes as $cotizacion)
                                            <tr data-estado="{{ (string) ($cotizacion->estado ?? '1') }}">
                                                <td class="text-center">
                                                    @php
                                                        $quoteHasPayment = !empty($cotizacion->cxc_id)
                                                            && (string) ($cotizacion->cxc_estado ?? '') !== 'NUEVO';
                                                        $quoteCanBeAnnulled = (
                                                            (string) ($cotizacion->estado ?? '') === '2'
                                                            && ($cotizacion->can_annul_approved ?? false)
                                                        )
                                                            || (
                                                                in_array((string) ($cotizacion->estado ?? ''), ['0', '1'], true)
                                                                && !$quoteHasPayment
                                                            );
                                                    @endphp
                                                    <input type="checkbox" class="quote-row-select"
                                                        value="{{ $cotizacion->nroCotizacion ?? '' }}"
                                                        aria-label="Seleccionar cotización {{ $cotizacion->nroCotizacion ?? '-' }}"
                                                        @disabled(!$quoteCanBeAnnulled) @if(!$quoteCanBeAnnulled)
                                                            title="{{ (string) ($cotizacion->estado ?? '') === '2' ? 'Solo se puede anular dentro de los 7 días desde su aprobación' : 'No se puede anular una cotización en su estado actual' }}"
                                                        @endif>
                                                </td>
                                                <td>
                                                    <button type="button" class="erp-quote-number" style="text-decoration: none"
                                                        data-cotizacion-pdf-preview="{{ route('modules.ventas.cotizaciones.pdf-grupo', ['batch_id' => $cotizacion->batch_id, 'preview' => '1']) }}"
                                                        data-cotizacion-number="{{ $cotizacion->nroCotizacion ?? '-' }}"
                                                        title="Ver cotización {{ $cotizacion->nroCotizacion ?? '-' }}">
                                                        {{ $cotizacion->nroCotizacion ?? '-' }}
                                                    </button>
                                                </td>
                                                <td title="{{ $cotizacion->cliente_nombre ?? '-' }}">
                                                    @if(($cotizacion->is_integrador ?? false))
                                                        <span
                                                            class="inline-flex h-4 w-4 items-center justify-center rounded-full bg-danger font-bold leading-none text-white"
                                                            style="font-size: 10px; line-height: 1;"
                                                            title="Cliente integrador">IN</span>
                                                    @endif
                                                    {{ $cotizacion->cliente_nombre ?? '-' }}
                                                </td>
                                                <td title="{{ $cotizacion->monto_display ?? '-' }}">
                                                    {{ $cotizacion->monto_display ?? ' 0.00' }}
                                                </td>
                                                <td title="{{ $cotizacion->vigencia_display ?? '-' }}">
                                                    {{ $cotizacion->vigencia_display ?? '-' }}
                                                </td>
                                                <td title="{{ $cotizacion->forma_pago_display ?? '-' }}">
                                                    {{ $cotizacion->forma_pago_display ?? '-' }}
                                                </td>
                                                <td title="{{ $cotizacion->fecha_emision_display ?? '-' }}">
                                                    {{ $cotizacion->fecha_emision_display ?? '-' }}
                                                </td>
                                                <td title="{{ $cotizacion->grupo_display ?? 'Sin grupo' }}">
                                                    {{ $cotizacion->grupo_display ?? 'Sin grupo' }}
                                                </td>
                                                <td title="{{ $cotizacion->servicio ?? 'EQUIPAMIENTO' }}">
                                                    <span
                                                        class="erp-service-badge">{{ $cotizacion->servicio ?? 'EQUIPAMIENTO' }}</span>
                                                </td>
                                                <td>
                                                    @if(($cotizacion->cxc_estado ?? '') === 'Pendiente Pago parcial')
                                                        <span class="erp-badge warning">Pendiente pago</span>
                                                    @elseif(($cotizacion->cxc_estado ?? '') === 'NUEVO')
                                                        <span class="erp-badge warning">Pendiente</span>
                                                    @elseif(in_array(($cotizacion->cxc_estado ?? ''), ['PagoCredito', 'Pendiente cuota crédito', 'Pendiente a credito'], true) || str_starts_with(mb_strtolower((string) ($cotizacion->cxc_estado ?? ''), 'UTF-8'), 'pendiente a credito'))
                                                        <span class="erp-badge warning">Pendiente Credito</span>
                                                    @elseif(($cotizacion->cxc_estado ?? '') === 'Pendiente Autodetracción')
                                                        <span class="erp-badge warning">Pendiente Autodetraer</span>
                                                    @elseif(($cotizacion->cxc_estado ?? '') === 'Pendiente a Detracción')
                                                        <span class="erp-badge warning">Pendiente Detracción</span>
                                                    @elseif(($cotizacion->cxc_estado ?? '') === 'Pendiente a Retención')
                                                        <span class="erp-badge warning">Pendiente Retención</span>
                                                    @elseif(in_array(($cotizacion->cxc_estado ?? ''), ['Cancelado', 'CANCELADO', 'Aprobado'], true) || (string) ($cotizacion->estado ?? '') === '2')
                                                        <span class="erp-badge success">Aprobado</span>
                                                    @elseif(!empty($cotizacion->archivoPago))
                                                        <span class="erp-badge success">Adjunto</span>
                                                    @else
                                                        <span class="erp-badge warning">Pendiente</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @php
                                                        $cxcStatus = (string) ($cotizacion->cxc_estado ?? '');
                                                        $isPendingWithholdingQuote = in_array($cxcStatus, ['Pendiente Autodetracción', 'Pendiente a Detracción', 'Pendiente a Retención'], true);
                                                        $isPendingCreditQuote = in_array($cxcStatus, ['PagoCredito', 'Pendiente cuota crédito', 'Pendiente a credito'], true)
                                                            || str_starts_with(mb_strtolower($cxcStatus, 'UTF-8'), 'pendiente a credito');
                                                        $isPendingPaymentQuote = mb_strtolower($cxcStatus, 'UTF-8') === 'pendiente pago parcial';
                                                        $isPendingQuote = $cxcStatus === 'NUEVO' || $isPendingCreditQuote || $isPendingWithholdingQuote || $isPendingPaymentQuote;
                                                        $hasPaymentReceipt = !empty($cotizacion->payment_receipt_url) || !empty($cotizacion->archivoPago);
                                                        $isApprovedQuote = !$isPendingQuote && (
                                                            in_array($cxcStatus, ['Cancelado', 'CANCELADO', 'Aprobado'], true)
                                                            || (string) ($cotizacion->estado ?? '') === '2'
                                                        );
                                                        $hasQuoteReceipt = $isApprovedQuote;
                                                    @endphp
                                                    @if($hasQuoteReceipt)
                                                        <button type="button" data-open-quotation-receipts
                                                            data-quotation-number="{{ $cotizacion->nroCotizacion ?? '' }}"
                                                            data-cxc-id="{{ $cotizacion->cxc_id ?? '' }}"
                                                            data-payment-receipt-url="{{ $cotizacion->payment_receipt_url ?? ($cotizacion->archivo_pago_url ?? '') }}"
                                                            data-withholding-receipt-url="{{ $cotizacion->withholding_proof_url ?? '' }}"
                                                            data-withholding-applies="{{ !empty($cotizacion->withholding_applies) ? '1' : '0' }}"
                                                            data-withholding-type="{{ $cotizacion->withholding_type ?? 'detracción' }}"
                                                            data-invoice-number="{{ $cotizacion->cxc_doc_referencia ?? '' }}"
                                                            data-payment-date="{{ $cotizacion->quote_payment_date ?? '' }}"
                                                            data-payment-amount="{{ $cotizacion->quote_payment_amount_display ?? '' }}"
                                                            data-payment-comment="{{ $cotizacion->quote_payment_comment ?? '' }}"
                                                            data-withholding-date="{{ $cotizacion->quote_withholding_date ?? '' }}"
                                                            data-withholding-amount="{{ $cotizacion->quote_withholding_amount_display ?? '' }}"
                                                            class="erp-btn-secondary"
                                                            style="display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.35rem 0.35rem; text-decoration: none; font-size: 0.8125rem; font-weight: 600; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 0.375rem; color: #334155; transition: all 0.2s ease;">
                                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14"
                                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                                stroke-linecap="round" stroke-linejoin="round">
                                                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                                                <circle cx="12" cy="12" r="3"></circle>
                                                            </svg>
                                                            Recibo
                                                        </button>
                                                    @endif
                                                    @if($canApproveCxc && !$isApprovedQuote && (!$hasPaymentReceipt || $isPendingWithholdingQuote || $isPendingCreditQuote || $isPendingPaymentQuote))
                                                        <button type="button" class="erp-btn-primary erp-btn-gestion"
                                                            @if(in_array(($cotizacion->cxc_estado ?? ''), ['Pendiente Autodetracción', 'Pendiente a Detracción', 'Pendiente a Retención'], true))
                                                                style="width:76px;white-space:normal;line-height:1.1;text-align:center;"
                                                            @endif data-cotizacion-id="{{ $cotizacion->nroCotizacion ?? '' }}"
                                                            data-cotizacion-number="{{ $cotizacion->nroCotizacion ?? '' }}"
                                                            data-cotizacion-client="{{ $cotizacion->cliente_nombre ?? '-' }}"
                                                            data-cotizacion-client-id="{{ $cotizacion->cliente_idcliente ?? '' }}"
                                                            data-cotizacion-amount="{{ $cotizacion->monto_display ?? '-' }}"
                                                            data-cotizacion-payment="{{ $cotizacion->forma_pago_detalle ?? '' }}"
                                                            data-cotizacion-payment-id="{{ $cotizacion->formaPago_idformaPago ?? '' }}"
                                                            data-cotizacion-service="{{ $cotizacion->servicio ?? 'EQUIPAMIENTO' }}"
                                                            data-cotizacion-client-retention="{{ ($cotizacion->cliente_detraccion ?? '0') === '1' ? '1' : '0' }}"
                                                            data-withholding-retention="{{ ($cotizacion->withholding_type ?? 'detracción') === 'retención' ? '1' : '0' }}"
                                                            data-cotizacion-complete-withholding="{{ in_array(($cotizacion->cxc_estado ?? ''), ['Pendiente Autodetracción', 'Pendiente a Detracción', 'Pendiente a Retención'], true) ? '1' : '0' }}"
                                                            data-cotizacion-currency-id="{{ $cotizacion->cxc_moneda_id ?? $cotizacion->moneda_idmoneda ?? '' }}"
                                                            data-cotizacion-balance="{{ $cotizacion->cxc_monto_pendiente ?? $cotizacion->total ?? 0 }}"
                                                            data-cotizacion-credit-total="{{ $cotizacion->cxc_monto_total ?? '' }}"
                                                            data-cotizacion-paid-installments="{{ json_encode($cotizacion->cxc_cuotas_pagadas ?? []) }}"
                                                            data-cotizacion-currency-symbol="{{ $cotizacion->cxc_moneda_simbolo ?? '' }}"
                                                            data-cotizacion-next-installment="{{ $cotizacion->cxc_proxima_cuota_numero ?? '' }}"
                                                            data-cotizacion-next-installment-payment-id="{{ $cotizacion->cxc_proxima_cuota_forma_pago_id ?? '' }}"
                                                            data-cotizacion-next-installment-amount="{{ $cotizacion->cxc_proxima_cuota_monto ?? '' }}"
                                                            data-cotizacion-next-installment-date="{{ $cotizacion->cxc_proxima_cuota_fecha ?? '' }}"
                                                            data-cotizacion-reference="{{ $cotizacion->cxc_doc_referencia ?? '' }}"
                                                            data-cotizacion-cxc-state="{{ $cotizacion->cxc_estado ?? '' }}"
                                                            data-cotizacion-group="{{ $cotizacion->batch_id ?? '' }}"
                                                            data-cotizacion-is-group="{{ !empty($cotizacion->es_grupo) ? '1' : '0' }}"
                                                            data-cotizacion-group-count="{{ $cotizacion->grupo_count ?? 1 }}"
                                                            data-cotizacion-cxc-id="{{ $cotizacion->cxc_id ?? '' }}"
                                                            data-cotizacion-approve-url="{{ route('modules.cuentasporcobrar.quotations.approve', ['id' => $cotizacion->nroCotizacion]) }}">
                                                            @if(in_array(($cotizacion->cxc_estado ?? ''), ['Pendiente Autodetracción', 'Pendiente a Detracción', 'Pendiente a Retención'], true))
                                                                <span>Registrar<br>depósito</span>
                                                            @else
                                                                {{ in_array(($cotizacion->cxc_estado ?? ''), ['PagoCredito', 'Pendiente cuota crédito', 'Pendiente a credito', 'Pendiente Pago parcial'], true) || str_starts_with(mb_strtolower((string) ($cotizacion->cxc_estado ?? ''), 'UTF-8'), 'pendiente a credito') ? 'Continuar pago' : 'Registrar pago' }}
                                                            @endif
                                                        </button>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div data-cxc-pagination
                                class="flex-reverse flex flex-col-reverse flex-wrap items-center gap-y-2 p-5 sm:flex-row">
                                <div class="mr-auto w-full flex-1 sm:w-auto">
                                    {{ $cotizacionesPendientes->onEachSide(1)->links('layouts.pagination') }}
                                </div>
                                <select data-cxc-page-size name="perPage"
                                    class="transition duration-200 ease-in-out w-full text-sm border-slate-200 shadow-sm py-2 px-3 pr-8 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 rounded-[0.5rem] sm:w-20">
                                    @foreach([10, 25, 50, 100] as $limit)
                                        <option value="{{ $limit }}" @if((int) request('perPage', 10) === $limit) selected @endif>
                                            {{ $limit }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div id="quote-no-results" class="erp-empty-state" style="display: none;">
                                No se encontraron cotizaciones con los filtros aplicados.
                            </div>
                        @endif
                    </div>
                </div>

                <div id="tab-servicios" class="tab-panel {{ request('tab') === 'servicios' ? '' : 'hidden' }}">
                    <div id="service-stats" class="erp-section-stats">
                        @foreach($serviceStats ?? [] as $stat)
                            <div class="erp-section-stat">
                                <div class="erp-section-stat-label">{{ $stat['label'] }}</div>
                                <div class="erp-section-stat-value">{{ number_format($stat['value'], 0, ',', '.') }}</div>
                            </div>
                        @endforeach
                    </div>
                    <div class="erp-toolbar">
                        <div class="flex min-w-0 items-center gap-4">
                            <span id="service-subtitle" class="erp-subtitle font-semibold">Servicios por vencer y
                                vencidos</span>
                        </div>

                        <div class="flex items-center gap-2 sm:ml-auto">
                            @if($canDeactivateCxcServices)
                                <button type="button" id="btn-dar-de-baja"
                                    class="erp-btn-primary bg-red-600 hover:bg-red-700 text-white font-bold px-4 py-1.5 rounded-md text-xs transition duration-200"
                                    disabled>
                                    Dar de baja
                                </button>
                            @endif
                            @if($canExportCxc)
                                <a href="{{ route('modules.cuentasporcobrar.services.export', ['format' => 'xlsx']) }}"
                                    id="service-export-xlsx" class="erp-btn-secondary"
                                    style="display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; text-decoration: none;">
                                    <i data-lucide="download" aria-hidden="true" class="h-4 w-4"></i>
                                    Exportar XLSX
                                </a>
                            @endif
                            @if($canCreateCxc)
                                <button type="button" id="open-service-payment" class="erp-btn-primary" disabled
                                    style="display: none;">
                                    <i data-lucide="wallet-cards" class="mr-2 h-4 w-4"></i>
                                    Cobrar seleccionados
                                </button>
                            @endif
                        </div>
                    </div>

                    <form id="service-filter-form" class="erp-service-filters" onsubmit="return false;">
                        <label class="erp-service-filter-field">
                            <span>Cliente</span>
                            <input type="search" id="service-filter-client"
                                value="{{ $serviceFilters['service_client'] ?? '' }}" placeholder="Nombre o ID del cliente"
                                autocomplete="off">
                        </label>
                        <div class="erp-service-filter-field">
                            <span>Mes</span>
                            @php
                                $serviceMonthOptions = [
                                    'enero' => 'Enero',
                                    'febrero' => 'Febrero',
                                    'marzo' => 'Marzo',
                                    'abril' => 'Abril',
                                    'mayo' => 'Mayo',
                                    'junio' => 'Junio',
                                    'julio' => 'Julio',
                                    'agosto' => 'Agosto',
                                    'septiembre' => 'Septiembre',
                                    'octubre' => 'Octubre',
                                    'noviembre' => 'Noviembre',
                                    'diciembre' => 'Diciembre',
                                ];
                            @endphp
                            <details id="service-month-picker" class="erp-month-picker">
                                <summary id="service-month-summary" aria-label="Seleccionar meses">
                                    <span></span><i data-lucide="chevron-down" aria-hidden="true"></i>
                                </summary>
                                <div class="erp-month-picker-panel">
                                    <label class="erp-month-picker-select-all">
                                        <input type="checkbox" id="service-month-select-all">
                                        <span>Todos</span>
                                    </label>
                                    @foreach($serviceMonthOptions as $monthValue => $monthLabel)
                                        <label>
                                            <input type="checkbox" name="service_month[]" value="{{ $monthValue }}"
                                                @checked(in_array($monthValue, $serviceFilters['service_months'] ?? [], true))>
                                            <span>{{ $monthLabel }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </details>
                        </div>
                        <label class="erp-service-filter-field">
                            <span>Año</span>
                            <select id="service-filter-year">
                                @for($year = now()->year - 2; $year <= now()->year + 5; $year++)
                                    <option value="{{ $year }}" @selected((string) ($serviceFilters['service_year'] ?? '') === (string) $year)>{{ $year }}</option>
                                @endfor
                                <option value="all" @selected(($serviceFilters['service_year'] ?? '') === '')>Todos</option>
                            </select>
                        </label>
                        <label class="erp-service-filter-field">
                            <span>Documento</span>
                            <input type="search" id="service-filter-document"
                                value="{{ $serviceFilters['service_document'] ?? '' }}" placeholder="Ej. F00869769"
                                autocomplete="off">
                        </label>
                        <label class="erp-service-filter-field">
                            <span>Estado</span>
                            <select id="service-filter-status">
                                <option value="" @selected(($serviceFilters['service_status'] ?? '') === '')>Todos</option>
                                <option value="facturado" @selected(($serviceFilters['service_status'] ?? '') === 'facturado')>Facturado</option>
                                <option value="pendiente pago parcial" @selected(($serviceFilters['service_status'] ?? '') === 'pendiente pago parcial')>Pendiente Pago parcial</option>
                                <option value="pendiente a credito" @selected(($serviceFilters['service_status'] ?? '') === 'pendiente a credito')>Pendiente a credito</option>
                                <option value="pendiente cuota crédito" @selected(($serviceFilters['service_status'] ?? '') === 'pendiente cuota crédito')>Pendiente cuota crédito</option>
                                <option value="pendiente autodetracción" @selected(($serviceFilters['service_status'] ?? '') === 'pendiente autodetracción')>Pendiente Autodetracción</option>
                                <option value="pendiente a detracción" @selected(($serviceFilters['service_status'] ?? '') === 'pendiente a detracción')>Pendiente a Detracción</option>
                                <option value="pendiente a retención" @selected(($serviceFilters['service_status'] ?? '') === 'pendiente a retención')>Pendiente a Retención</option>
                                <option value="pendiente" @selected(($serviceFilters['service_status'] ?? '') === 'pendiente')>Pendiente</option>
                                <option value="vencido" @selected(($serviceFilters['service_status'] ?? '') === 'vencido')>
                                    Vencido</option>
                                <option value="cancelado" @selected(($serviceFilters['service_status'] ?? '') === 'cancelado')>Cancelado</option>
                            </select>
                        </label>
                        <label class="erp-service-filter-field">
                            <span>Fecha Registro </span>
                            <input type="date" id="service-filter-date" value="{{ $serviceFilters['service_date'] ?? '' }}">
                        </label>
                        <label class="erp-service-filter-field">
                            <span>Fecha Límite</span>
                            <input type="date" id="service-filter-expiration-date"
                                value="{{ $serviceFilters['service_expiration_date'] ?? '' }}">
                        </label>
                        <div class="erp-service-filter-actions">
                            <button type="button" id="service-filter-apply-btn" class="erp-btn-primary">
                                <i data-lucide="list-filter" aria-hidden="true"></i> Aplicar
                            </button>
                            <button type="button" id="service-filter-clear-btn" class="erp-btn-secondary">
                                Limpiar
                            </button>
                        </div>
                    </form>

                    <div id="service-results">
                        @if(!$serviceDataLoaded)
                            <div class="erp-empty-state" aria-live="polite" hidden></div>
                        @elseif($activeTab !== 'servicios')
                            <div class="erp-empty-state" aria-live="polite" hidden></div>
                        @elseif($serviciosPorVencerGrouped->count() === 0)
                            <div class="erp-empty-state">
                                No se encontraron servicios por vencer.
                            </div>
                        @else
                            <div class="erp-table-wrap erp-staircase-wrap">
                                <table class="erp-table erp-staircase-table">
                                    <thead>
                                        <tr class="text-xs">
                                            <th class="bg-slate-50 text-center" style="width: 32px;">
                                                <input type="checkbox" id="select-all-stair-checkboxes"
                                                    class="duration-100 ease-in-out shadow-sm border-slate-200 cursor-pointer rounded focus:ring-4 focus:ring-offset-0 focus:ring-primary focus:ring-opacity-20 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&[type='radio']]:checked:bg-primary [&[type='radio']]:checked:border-primary [&[type='radio']]:checked:border-opacity-10 [&[type='checkbox']]:checked:bg-primary [&[type='checkbox']]:checked:border-primary [&[type='checkbox']]:checked:border-opacity-10 [&:disabled:not(:checked)]:bg-slate-100 [&:disabled:not(:checked)]:cursor-not-allowed [&:disabled:not(:checked)]:dark:bg-darkmode-800/50 [&:disabled:checked]:opacity-70 [&:disabled:checked]:cursor-not-allowed [&:disabled:checked]:dark:bg-darkmode-800/50"
                                                    aria-label="Seleccionar todos los clientes">
                                            </th>
                                            <th class="bg-slate-50">RUC</th>
                                            <th class="bg-slate-50">Cliente</th>
                                            <th class="bg-slate-50 text-center">N° UNID</th>
                                            <th class="bg-slate-50 text-center">N° SERV</th>
                                            <th class="bg-slate-50">Fecha Registro</th>
                                            <th class="bg-slate-50">Fecha Límite</th>
                                            <th class="bg-slate-50">Mes</th>
                                            <th class="bg-slate-50">Documento</th>
                                            <th class="bg-slate-50 text-center">Estado</th>
                                            <th class="bg-slate-50 text-center">Días Serv</th>
                                            <th class="bg-slate-50">Monto</th>
                                            <th class="bg-slate-50 text-center">Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($serviciosPorVencerGrouped as $clientIndex => $clientGroup)
                                            @php
                                                $diasRestantes = (int) ($clientGroup['dias_restantes'] ?? 0);
                                                $diasBadgeClass = $diasRestantes <= 0
                                                    ? 'service-days-gray'
                                                    : ($diasRestantes <= 5
                                                        ? 'service-days-red'
                                                        : ($diasRestantes <= 15 ? 'service-days-yellow' : 'service-days-green'));
                                                $clientId = $clientGroup['cliente_id'];
                                                $groupId = 'cxc-g' . $clientIndex . '-' . ($clientGroup['cxc_id'] ?: '0');
                                                $allServiceIdsStr = implode(',', $clientGroup['all_service_ids']);
                                                $paymentServiceIdsStr = implode(',', $clientGroup['payment_service_ids'] ?? $clientGroup['all_service_ids']);
                                                $isVencido = in_array((string) ($clientGroup['cxc_estado'] ?? ''), ['4', 'VENCIDO'], true);
                                                $isCanceladoGroup = in_array((string) ($clientGroup['cxc_estado'] ?? ''), ['3', 'CANCELADO', 'Cancelado Detracción'], true) || str_starts_with(strtolower($clientGroup['estado_pago'] ?? ''), 'cancelado');
                                                $isWithholdingPendingGroup = in_array((string) ($clientGroup['cxc_estado'] ?? ''), ['Pendiente Autodetracción', 'Pendiente Autoretención', 'Pendiente a Detracción', 'Pendiente a Retención', 'Cancelado Detracción'], true);
                                            @endphp
                                            <!-- NIVEL 1: CLIENTE -->
                                            <tr class="stair-client-row cursor-pointer hover:bg-slate-50/80 transition-colors text-xs"
                                                data-client-id="{{ $clientId }}"
                                                data-client-name="{{ $clientGroup['cliente_nombre'] }}"
                                                data-group-id="{{ $groupId }}" data-client-days="{{ $diasRestantes }}"
                                                data-client-status="{{ strtolower($clientGroup['estado_pago']) }}"
                                                data-client-service-status="{{ strtolower($clientGroup['estado_servicio'] ?? 'activo') }}"
                                                data-month="{{ strtolower((string) ($clientGroup['mes'] ?? '')) }}"
                                                data-year="{{ $clientGroup['anio'] ?? '' }}"
                                                data-document="{{ $clientGroup['documento'] ?? '' }}"
                                                data-registration-date="{{ $clientGroup['fecha_registro_iso'] ?? '' }}"
                                                data-end-date="{{ $clientGroup['fecha_fin_iso'] ?? '' }}"
                                                data-all-service-ids="{{ $allServiceIdsStr }}"
                                                data-monto-total="{{ $clientGroup['monto_total'] }}">
                                                <td class="text-center py-2 px-1">
                                                    <input type="checkbox"
                                                        class="stair-check-client duration-100 ease-in-out shadow-sm border-slate-200 cursor-pointer rounded focus:ring-4 focus:ring-offset-0 focus:ring-primary focus:ring-opacity-20 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&[type='radio']]:checked:bg-primary [&[type='radio']]:checked:border-primary [&[type='radio']]:checked:border-opacity-10 [&[type='checkbox']]:checked:bg-primary [&[type='checkbox']]:checked:border-primary [&[type='checkbox']]:checked:border-opacity-10 [&:disabled:not(:checked)]:bg-slate-100 [&:disabled:not(:checked)]:cursor-not-allowed [&:disabled:not(:checked)]:dark:bg-darkmode-800/50 [&:disabled:checked]:opacity-70 [&:disabled:checked]:cursor-not-allowed [&:disabled:checked]:dark:bg-darkmode-800/50"
                                                        data-client-id="{{ $groupId }}"
                                                        data-service-ids="{{ $paymentServiceIdsStr }}">
                                                </td>
                                                <td class="font-semibold text-slate-700 py-2 px-2 text-xs" title="{{ $clientId }}">
                                                    {{ $clientId }}
                                                </td>
                                                <td class="font-bold text-slate-900 py-2 px-2 text-xs"
                                                    title="{{ $clientGroup['cliente_nombre'] }}">
                                                    @if(($clientGroup['is_integrador'] ?? false))
                                                        <span
                                                            class="inline-flex h-4 w-4 items-center justify-center rounded-full bg-danger font-bold leading-none text-white"
                                                            style="font-size: 10px; line-height: 1;"
                                                            title="Cliente integrador">IN</span>
                                                    @endif
                                                    {{ $clientGroup['cliente_nombre'] }}
                                                </td>
                                                <td class="text-center py-2 px-2">
                                                    <span
                                                        class="stair-badge-count bg-slate-100 text-slate-700 px-2 py-0.5 rounded-full font-bold text-xs border border-slate-200">
                                                        {{ $clientGroup['num_unidades'] }}
                                                    </span>
                                                </td>
                                                <td class="text-center py-2 px-2">
                                                    <span
                                                        class="stair-badge-count bg-danger text-white px-2 py-0.5 rounded-full font-bold text-xs">
                                                        {{ $clientGroup['num_tservicios'] }}
                                                    </span>
                                                </td>
                                                <td class="py-2 px-2 text-xs" title="{{ $clientGroup['fecha_registro'] ?? '-' }}">
                                                    {{ $clientGroup['fecha_registro'] ?? '-' }}
                                                </td>
                                                <td class="py-2 px-2 text-xs" title="{{ $clientGroup['fecha_fin'] }}">
                                                    {{ $clientGroup['fecha_fin'] }}
                                                </td>
                                                <td class="py-2 px-2 text-xs" title="{{ $clientGroup['mes'] }}">
                                                    <span class="erp-badge service-days-red">
                                                        {{ ucfirst($clientGroup['mes']) }}
                                                    </span>
                                                </td>
                                                <td class="py-2 px-2 text-xs" title="{{ $clientGroup['documento'] }}">
                                                    {{ $clientGroup['documento'] }}
                                                </td>
                                                <td class="text-center py-2 px-2">
                                                    @php
                                                        $statusLabel = $clientGroup['estado_pago'];
                                                        $statusDisplayLabel = $statusLabel === 'Pendiente Autodetracción'
                                                            ? 'Pendiente Autodetraer'
                                                            : $statusLabel;
                                                        $statusClass = match (strtolower($statusLabel)) {
                                                            'cancelado' => 'service-days-green',
                                                            'pendiente a detracción' => 'service-days-yellow',
                                                            'pendiente a retención' => 'service-days-yellow',
                                                            'pendiente autodetracción' => 'service-days-yellow',
                                                            'cancelado detracción' => 'service-days-yellow',
                                                            'facturado' => 'service-days-yellow',
                                                            'pendiente pago parcial' => 'service-days-yellow',
                                                            'pendiente a credito' => 'service-days-yellow',
                                                            'nuevo' => 'service-days-yellow',
                                                            'pagocredito' => 'service-days-yellow',
                                                            'pendiente cuota crédito' => 'service-days-yellow',
                                                            'vencido' => 'service-days-gray',
                                                            default => 'service-days-red',
                                                        };
                                                    @endphp
                                                    <span
                                                        class="erp-badge service-status-badge {{ $statusClass }}">{{ $statusDisplayLabel }}</span>
                                                </td>
                                                <td class="text-center py-2 px-2">
                                                    <span
                                                        class="erp-badge {{ $isCanceladoGroup ? 'service-days-green' : $diasBadgeClass }}">
                                                        {{ $isCanceladoGroup ? '-' : $diasRestantes . ' días' }}
                                                    </span>
                                                </td>
                                                <td class="font-bold text-slate-800 py-2 px-2 text-xs"
                                                    title="{{ $clientGroup['monto_total_display'] }}">
                                                    {{ $clientGroup['monto_total_display'] }}
                                                </td>
                                                <td class="text-center py-1 px-1">
                                                    <div class="stair-action-group">
                                                        <button type="button"
                                                            class="btn-manage-client inline-flex items-center justify-center rounded-md border px-2 py-1 text-[11px] font-bold transition duration-200 text-white"
                                                            @if($isWithholdingPendingGroup)
                                                                style="background-color: #b41B29;width:76px;white-space:normal;line-height:1.1;text-align:center;"
                                                            @else style="background-color: #b41B29;" @endif
                                                            data-service-ids="{{ $paymentServiceIdsStr }}"
                                                            data-client-id="{{ $clientId }}"
                                                            data-client-retention="{{ !empty($clientGroup['es_retencion']) ? '1' : '0' }}"
                                                            data-client-name="{{ $clientGroup['cliente_nombre'] }}"
                                                            data-amount="{{ $clientGroup['monto_factura'] ?? $clientGroup['monto_total'] }}"
                                                            data-payment-amount="{{ $isWithholdingPendingGroup || mb_strtolower(trim((string) ($clientGroup['cxc_estado'] ?? '')), 'UTF-8') === 'pendiente pago parcial' ? ($clientGroup['monto_saldo'] ?? 0) : ($clientGroup['monto_factura'] ?? $clientGroup['monto_saldo']) }}"
                                                            data-amount-display="{{ $clientGroup['monto_total_display'] }}"
                                                            data-tipo-cobro-id="{{ $clientGroup['tipo_cobro_id'] ?? '' }}"
                                                            data-moneda-id="{{ $clientGroup['moneda_idmoneda'] ?? 1 }}"
                                                            data-currency-symbol="{{ $clientGroup['moneda_simbolo'] ?? '$' }}"
                                                            data-cxc-id="{{ $clientGroup['cxc_id'] ?? '' }}"
                                                            data-cxc-ids="{{ implode(',', $clientGroup['cxc_ids'] ?? []) }}"
                                                            data-payment-detail-cxc-ids="{{ implode(',', $clientGroup['payment_detail_cxc_ids'] ?? []) }}"
                                                            data-cxc-estado="{{ $clientGroup['cxc_estado'] ?? 'PENDIENTE' }}"
                                                            data-advance-months="{{ $clientGroup['adelanto_meses'] ?? 0 }}"
                                                            data-credit-installment="0"
                                                            data-credit-installment-date=""
                                                            data-period-end="{{ $clientGroup['fecha_fin_siguiente_iso'] ?? '' }}"
                                                            data-next-period-end="{{ $clientGroup['fecha_fin_siguiente_iso'] ?? '' }}"
                                                            data-doc-ref="{{ $clientGroup['cxc_doc'] ?? '' }}"
                                                            data-credit-target-cxc-id="{{ $clientGroup['credit_target_cxc_id'] ?? '' }}"
                                                            data-credit-target-amount="{{ $clientGroup['credit_target_amount'] ?? '' }}"
                                                            data-credit-target-number="{{ $clientGroup['credit_target_number'] ?? '' }}"
                                                            data-credit-target-date="{{ $clientGroup['credit_target_date'] ?? '' }}"
                                                            data-credit-plan="{{ !empty($clientGroup['credit_plan_installments']) ? '1' : '0' }}"
                                                            data-credit-plan-installments="{{ json_encode($clientGroup['credit_plan_installments'] ?? []) }}">
                                                            @if($isWithholdingPendingGroup)
                                                                <span>Registrar<br>depósito</span>
                                                            @else
                                                                {{ $isCanceladoGroup ? 'Ver' : 'Gestionar' }}
                                                            @endif
                                                        </button>
                                                        @if($isCanceladoGroup && !empty($clientGroup['cxc_id']) && !empty($clientGroup['puede_revertir']))
                                                            <button type="button"
                                                                class="btn-revert-payment inline-flex items-center justify-center rounded-md border border-amber-500 px-2 py-1 text-[11px] font-bold text-amber-700 transition duration-200 hover:bg-amber-50"
                                                                data-revert-url="{{ route('modules.cuentasporcobrar.revertir-pago', ['id' => $clientGroup['cxc_id']]) }}"
                                                                data-revert-cxc-ids="{{ implode(',', $clientGroup['cxc_reversion_ids'] ?? []) }}"
                                                                title="Revertir pago dentro de 7 días">
                                                                Anular
                                                            </button>
                                                        @endif
                                                        <button type="button"
                                                            class="stair-toggle-btn stair-toggle-client p-0.5 text-slate-500 hover:text-slate-900 rounded transition-transform"
                                                            aria-label="Expandir servicios del cliente">
                                                            <svg xmlns="http://www.w3.org/2000/svg"
                                                                class="h-3 w-3 transition-transform duration-200 toggle-icon"
                                                                viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                                stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                                <polyline points="9 18 15 12 9 6"></polyline>
                                                            </svg>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>

                                            <!-- NIVEL 2: CONTENEDOR DE TIPOS DE SERVICIOS (DESPLEGABLE) -->
                                            <tr class="stair-client-detail-row hidden" id="client-detail-{{ $groupId }}">
                                                <td colspan="13" class="p-0 bg-slate-50/60 border-b border-slate-200">
                                                    <div
                                                        class="py-3 px-4 ml-6 my-2 border-l-4 border-primary/50 bg-white rounded-r-xl shadow-sm">
                                                        <div
                                                            class="flex flex-wrap items-center justify-between gap-2 mb-2 pb-1 border-b border-slate-100">
                                                            <span
                                                                class="text-xs font-bold uppercase tracking-wider text-slate-500 flex items-center gap-1.5">
                                                                <svg xmlns="http://www.w3.org/2000/svg"
                                                                    class="h-3.5 w-3.5 text-primary" viewBox="0 0 24 24" fill="none"
                                                                    stroke="currentColor" stroke-width="2">
                                                                    <path d="M22 12h-4l-3 9L9 3l-3 9H2" />
                                                                </svg>
                                                                Tipos de Servicios de {{ $clientGroup['cliente_nombre'] }}
                                                            </span>
                                                            <div class="flex flex-wrap items-center gap-2">
                                                                <button type="button"
                                                                    class="btn-manage-selected-vehicles erp-btn-primary px-2 py-1 text-[10px] disabled:cursor-not-allowed disabled:opacity-50"
                                                                    data-client-id="{{ $groupId }}" disabled>
                                                                    Gestionar seleccionados (0)
                                                                </button>
                                                                <button type="button"
                                                                    class="btn-annul-selected-vehicles erp-btn-secondary px-2 py-1 text-[10px] disabled:cursor-not-allowed disabled:opacity-50 hidden"
                                                                    data-client-id="{{ $groupId }}" style="display:none" disabled>
                                                                    Anular seleccionados (0)
                                                                </button>
                                                                @if($canEditServicePrice)
                                                                    <button type="button"
                                                                        class="btn-edit-selected-service-price px-2 py-1 text-[10px] erp-btn-primary disabled:cursor-not-allowed disabled:opacity-50 hidden" style="background-color: #ffeead; color: #92400e;"
                                                                        data-client-id="{{ $groupId }}" style="display:none" disabled>
                                                                        Editar precio seleccionados (0)
                                                                    </button>
                                                                @endif
                                                            </div>
                                                        </div>

                                                        <table class="w-full text-xs erp-services-nested-table">
                                                            <thead>
                                                                <tr
                                                                    class="text-xs font-semibold text-slate-500 bg-slate-100/70 border-b border-slate-200">
                                                                    <th class="py-2 px-3 text-left">Tipo de Servicio</th>
                                                                    <th class="py-2 px-3 text-left">Plataforma</th>
                                                                    <th class="py-2 px-3 text-right">Subtotal</th>
                                                                    <th class="py-2 px-3 text-center" style="width: 170px;">Acción
                                                                    </th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                @foreach($clientGroup['tipos_servicios'] as $typeIndex => $typeGroup)
                                                                    @php
                                                                        $typeDetailId = "type-detail-{$groupId}-{$typeIndex}";
                                                                        $serviceIdsStr = implode(',', $typeGroup['service_ids']);
                                                                        $typeCxcIdsStr = implode(',', $typeGroup['cxc_ids'] ?? []);
                                                                        $isTypeWithholdingPending = in_array((string) ($typeGroup['cxc_estado'] ?? ''), ['Pendiente Autodetracción', 'Pendiente Autoretención', 'Pendiente a Detracción', 'Pendiente a Retención', 'Cancelado Detracción'], true);
                                                                        $isTypeCancelled = in_array((string) ($typeGroup['cxc_estado'] ?? ''), ['3', 'CANCELADO'], true);
                                                                        $isCreditInstallmentType = !empty($typeGroup['service_credit_installment']);
                                                                        $hasSelectableServiceVehicle = collect($typeGroup['vehiculos'])->contains(function ($vehicle) {
                                                                            $vehicleState = mb_strtoupper(trim((string) ($vehicle['cxc_estado'] ?? '')), 'UTF-8');
                                                                            $isWithholdingPending = in_array($vehicleState, ['PENDIENTE AUTODETRACCIÓN', 'PENDIENTE AUTORETENCIÓN', 'PENDIENTE A DETRACCIÓN', 'PENDIENTE A RETENCIÓN', 'CANCELADO DETRACCIÓN'], true);
                                                                            $isCancelled = in_array($vehicleState, ['3', 'CANCELADO'], true);

                                                                            return !empty($vehicle['service_credit_installment'])
                                                                                || (!$isWithholdingPending && (!$isCancelled || !empty($vehicle['puede_revertir'])));
                                                                        });
                                                                    @endphp
                                                                    <!-- NIVEL 2: FILA DE TIPO DE SERVICIO -->
                                                                    <tr class="stair-service-row border-b border-slate-100 hover:bg-slate-50 transition-colors cursor-pointer"
                                                                        @unless($isCreditInstallmentType)data-target="#{{ $typeDetailId }}"@endunless>
                                                                        <td class="py-2 px-3 font-semibold text-slate-800">
                                                                            <div class="stair-service-name">
                                                                                @unless($isCreditInstallmentType)
                                                                                <input type="checkbox"
                                                                                    class="stair-check-type duration-100 ease-in-out shadow-sm border-slate-200 cursor-pointer rounded focus:ring-4 focus:ring-offset-0 focus:ring-primary focus:ring-opacity-20 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&[type='radio']]:checked:bg-primary [&[type='radio']]:checked:border-primary [&[type='radio']]:checked:border-opacity-10 [&[type='checkbox']]:checked:bg-primary [&[type='checkbox']]:checked:border-primary [&[type='checkbox']]:checked:border-opacity-10 [&:disabled:not(:checked)]:bg-slate-100 [&:disabled:not(:checked)]:cursor-not-allowed [&:disabled:not(:checked)]:dark:bg-darkmode-800/50 [&:disabled:checked]:opacity-70 [&:disabled:checked]:cursor-not-allowed [&:disabled:checked]:dark:bg-darkmode-800/50"
                                                                                    data-client-id="{{ $groupId }}"
                                                                                    data-service-ids="{{ $serviceIdsStr }}"
                                                                                    @if(!$hasSelectableServiceVehicle) disabled @endif>
                                                                                @endunless
                                                                                {{ $typeGroup['servicio_nombre'] }}
                                                                            </div>
                                                                        </td>
                                                                        <td class="py-2.5 px-3 text-slate-600">
                                                                            {{ $isCreditInstallmentType ? '-' : $typeGroup['plataforma'] }}
                                                                        </td>
                                                                        <td class="py-2.5 px-3 text-start font-bold text-slate-900">
                                                                            {{ $typeGroup['monto_subtotal_display'] }}
                                                                        </td>
                                                                        <td class="py-2.5 px-3 text-center">
                                                                            <div class="stair-service-action">
                                                                                <button type="button"
                                                                                    class="btn-pay-service-type erp-btn-primary py-1 px-3 text-[11px]"
                                                                                    data-service-ids="{{ $serviceIdsStr }}"
                                                                                    data-client-id="{{ $clientId }}"
                                                                                    data-client-retention="{{ !empty($clientGroup['es_retencion']) ? '1' : '0' }}"
                                                                                    data-client-name="{{ $clientGroup['cliente_nombre'] }}"
                                                                                    data-amount="{{ $typeGroup['monto_factura'] ?? $typeGroup['monto_subtotal'] }}"
                                                                                    data-payment-amount="{{ $isTypeWithholdingPending || mb_strtolower(trim((string) ($typeGroup['cxc_estado'] ?? '')), 'UTF-8') === 'pendiente pago parcial' ? ($typeGroup['monto_saldo'] ?? 0) : ($typeGroup['monto_factura'] ?? $typeGroup['monto_saldo']) }}"
                                                                                    data-amount-display="{{ $typeGroup['monto_subtotal_display'] }}"
                                                                                    data-tipo-cobro-id="{{ $typeGroup['tipo_cobro_id'] ?? ($clientGroup['tipo_cobro_id'] ?? '') }}"
                                                                                    data-moneda-id="{{ $clientGroup['moneda_idmoneda'] ?? 1 }}"
                                                                                    data-currency-symbol="{{ $clientGroup['moneda_simbolo'] ?? '$' }}"
                                                                                    data-cxc-id="{{ $typeGroup['cxc_id'] ?: $clientGroup['cxc_id'] }}"
                                                                                    data-cxc-ids="{{ $typeCxcIdsStr }}"
                                                                                    data-cxc-estado="{{ $typeGroup['cxc_estado'] }}"
                                                                                    data-advance-months="{{ $clientGroup['adelanto_meses'] ?? 0 }}"
                                                                                    data-credit-installment="{{ !empty($veh['service_credit_installment']) ? '1' : '0' }}"
                                                                                    data-credit-installment-date="{{ $veh['credit_installment_date'] ?? '' }}"
                                                                                    data-period-end="{{ $typeGroup['fecha_fin_siguiente_iso'] ?? '' }}"
                                                                                    data-next-period-end="{{ $typeGroup['fecha_fin_siguiente_iso'] ?? '' }}"
                                                                                    data-doc-ref="{{ $typeGroup['cxc_doc'] }}"
                                                                                    data-credit-target-cxc-id="{{ $typeGroup['credit_target_cxc_id'] ?? '' }}"
                                                                                    data-credit-target-amount="{{ $typeGroup['credit_target_amount'] ?? '' }}"
                                                                                    data-credit-target-number="{{ $typeGroup['credit_target_number'] ?? '' }}"
                                                                                    data-credit-target-date="{{ $typeGroup['credit_target_date'] ?? '' }}"
                                                                                    data-credit-plan="{{ !empty($typeGroup['credit_plan_installments']) ? '1' : '0' }}"
                                                                                    data-credit-plan-installments="{{ json_encode($typeGroup['credit_plan_installments'] ?? []) }}">
                                                                                    @if($isTypeWithholdingPending)
                                                                                        <span>Registrar<br>depósito</span>
                                                                                    @elseif($isTypeCancelled)
                                                                                        Ver
                                                                                    @else
                                                                                        Gestionar
                                                                                    @endif
                                                                                </button>
                                                                                @unless($isCreditInstallmentType)
                                                                                <button type="button"
                                                                                    class="stair-toggle-btn stair-toggle-service p-1 text-slate-500 hover:text-slate-800 rounded transition-transform"
                                                                                    data-target="#{{ $typeDetailId }}"
                                                                                    aria-label="Expandir vehículos del servicio">
                                                                                    <svg xmlns="http://www.w3.org/2000/svg"
                                                                                        class="h-3.5 w-3.5 transition-transform duration-200 toggle-icon"
                                                                                        viewBox="0 0 24 24" fill="none"
                                                                                        stroke="currentColor" stroke-width="2.5"
                                                                                        stroke-linecap="round" stroke-linejoin="round">
                                                                                        <polyline points="9 18 15 12 9 6"></polyline>
                                                                                    </svg>
                                                                                </button>
                                                                                @endunless
                                                                            </div>
                                                                        </td>
                                                                    </tr>

                                                                    <!-- NIVEL 3: CONTENEDOR DE VEHÍCULOS (DESPLEGABLE) -->
                                                                    <tr class="stair-service-detail-row hidden"
                                                                        id="{{ $typeDetailId }}">
                                                                        <td colspan="4" class="p-0 bg-white">
                                                                            <div
                                                                                class="py-2.5 px-3 ml-8 my-1.5 border-l-4 border-slate-400 bg-slate-100/50 rounded-r-lg border border-slate-200">
                                                                                <div
                                                                                    class="mb-1.5 pb-1 flex items-center justify-between border-b border-slate-200/80">
                                                                                    <span
                                                                                        class="text-[11px] font-bold uppercase tracking-wider text-slate-500 flex items-center gap-1">
                                                                                        <svg xmlns="http://www.w3.org/2000/svg"
                                                                                            class="h-3 w-3 text-slate-600"
                                                                                            viewBox="0 0 24 24" fill="none"
                                                                                            stroke="currentColor" stroke-width="2">
                                                                                            <rect x="1" y="3" width="15" height="13" />
                                                                                            <polygon
                                                                                                points="16 8 20 8 23 11 23 16 16 16 16 8" />
                                                                                            <circle cx="5.5" cy="18.5" r="2.5" />
                                                                                            <circle cx="18.5" cy="18.5" r="2.5" />
                                                                                        </svg>
                                                                                        Vehículos en {{ $typeGroup['servicio_nombre'] }}
                                                                                        ({{ count($typeGroup['vehiculos']) }})
                                                                                    </span>
                                                                                </div>

                                                                                <table class="w-full text-xs erp-vehicles-nested-table">
                                                                                    <thead>
                                                                                        <tr
                                                                                            class="text-[10px] text-slate-600 bg-slate-200/60 border-b border-slate-300 font-semibold">
                                                                                            <th class="py-1 px-1 text-left">Placa</th>
                                                                                            <th class="py-1 px-1 text-left">ID
                                                                                                Dispositivo
                                                                                            </th>
                                                                                            <th class="py-1 px-1 text-left">Número</th>
                                                                                            <th class="py-1 px-1 text-left">Fecha Inicio
                                                                                            </th>
                                                                                            <th class="py-1 px-1 text-left">Fecha Fin
                                                                                            </th>
                                                                                            <th class="py-1 px-1 text-left">Mes</th>
                                                                                            <th class="py-1 px-1 text-left">Documento
                                                                                            </th>
                                                                                            <th class="py-1 px-1 text-right">Monto</th>
                                                                                            <th class="py-1 px-1 text-center">Acción
                                                                                            </th>
                                                                                        </tr>
                                                                                    </thead>
                                                                                    <tbody>
                                                                                        @foreach($typeGroup['vehiculos'] as $veh)
                                                                                            <tr
                                                                                                class="hover:bg-white transition-colors border-b border-slate-200/50">
                                                                                                <td
                                                                                                    class="py-1 px-1 font-bold text-slate-900">
                                                                                                    <div class="stair-vehicle-plate">
                                                                                                        @if((int) $veh['idservicioCliente'] > 0)
                                                                                                            <input type="checkbox"
                                                                                                                class="stair-check-item duration-100 ease-in-out shadow-sm border-slate-200 cursor-pointer rounded focus:ring-4 focus:ring-offset-0 focus:ring-primary focus:ring-opacity-20 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&[type='radio']]:checked:bg-primary [&[type='radio']]:checked:border-primary [&[type='radio']]:checked:border-opacity-10 [&[type='checkbox']]:checked:bg-primary [&[type='checkbox']]:checked:border-primary [&[type='checkbox']]:checked:border-opacity-10 [&:disabled:not(:checked)]:bg-slate-100 [&:disabled:not(:checked)]:cursor-not-allowed [&:disabled:checked]:opacity-70 [&:disabled:checked]:cursor-not-allowed [&:disabled:checked]:dark:bg-darkmode-800/50"
                                                                                                                data-client-id="{{ $groupId }}"
                                                                                                                data-tipo-cobro-id="{{ $veh['tipo_cobro_id'] ?? ($typeGroup['tipo_cobro_id'] ?? ($clientGroup['tipo_cobro_id'] ?? '')) }}"
                                                                                                                @if(in_array((string) ($veh['cxc_estado'] ?? ''), ['3', 'CANCELADO'], true) && empty($veh['puede_revertir']))
                                                                                                                    disabled
                                                                                                                @elseif(in_array((string) ($veh['cxc_estado'] ?? ''), ['Pendiente Autodetracción', 'Pendiente Autoretención', 'Pendiente a Detracción', 'Pendiente a Retención', 'Cancelado Detracción'], true))
                                                                                                                disabled @endif
                                                                                                                value="{{ $veh['idservicioCliente'] }}">
                                                                                                        @elseif(!empty($veh['service_credit_installment']) && !empty($veh['cxc_ids'][0]))
                                                                                                            <input type="checkbox"
                                                                                                                class="stair-check-item duration-100 ease-in-out shadow-sm border-slate-200 cursor-pointer rounded focus:ring-4 focus:ring-offset-0 focus:ring-primary focus:ring-opacity-20"
                                                                                                                data-client-id="{{ $groupId }}"
                                                                                                                data-credit-installment="1"
                                                                                                                value="{{ $veh['cxc_ids'][0] }}">
                                                                                                        @endif
                                                                                                        <span class="stair-vehicle-plate-text">{{ $veh['placa'] }}</span>
                                                                                                        @if(in_array((string) ($veh['cxc_estado'] ?? ''), ['3', 'CANCELADO'], true))
                                                                                                            <span
                                                                                                                class="stair-vehicle-status inline-flex items-center rounded-full bg-danger px-1.5 py-0.5 text-[9px] font-bold text-white">Pagado</span>
                                                                                                        @elseif(in_array((string) ($veh['cxc_estado'] ?? ''), ['Pendiente a Detracción', 'Pendiente Autodetracción', 'Cancelado Detracción'], true))
                                                                                                            <span
                                                                                                                class="stair-vehicle-status inline-flex items-center rounded-full bg-amber-600 px-1.5 py-0.5 text-[9px] font-bold text-white">Pend.
                                                                                                                Detracción</span>
                                                                                                        @elseif(in_array((string) ($veh['cxc_estado'] ?? ''), ['Pendiente a Retención', 'Pendiente Autoretención'], true))
                                                                                                            <span
                                                                                                                class="stair-vehicle-status inline-flex items-center rounded-full bg-amber-600 px-1.5 py-0.5 text-[9px] font-bold text-white">Pend.
                                                                                                                Retención</span>
                                                                                                        @endif
                                                                                                    </div>
                                                                                                </td>
                                                                                                <td class="py-1 px-1 text-slate-700">
                                                                                                    {{ $veh['id_dispositivo'] }}
                                                                                                </td>
                                                                                                <td class="py-1 px-1 text-slate-700">
                                                                                                    {{ $veh['numero'] }}
                                                                                                </td>
                                                                                                <td class="py-1 px-1 text-slate-600">
                                                                                                    {{ $veh['fecha_inicio_display'] }}
                                                                                                </td>
                                                                                                <td class="py-1 px-1 text-slate-600">
                                                                                                    {{ $veh['fecha_vencimiento_display'] }}
                                                                                                </td>
                                                                                                <td class="py-1 px-1 text-slate-600">
                                                                                                    {{ ucfirst($veh['mes']) }}
                                                                                                </td>
                                                                                                <td class="py-1 px-1 text-slate-600">
                                                                                                    {{ $veh['doc_referencia'] }}
                                                                                                </td>
                                                                                                <td
                                                                                                    class="py-1 px-1 font-bold text-slate-800">
                                                                                                    {{ $veh['monto_display'] }}
                                                                                                </td>
                                                                                                <td class="py-1 px-1 text-center">
                                                                                                    @if($canEditServicePrice && (int) $veh['idservicioCliente'] > 0 && !in_array(mb_strtoupper(trim((string) ($veh['cxc_estado'] ?? '')), 'UTF-8'), ['3', 'CANCELADO', 'CANCELADO DETRACCIÓN'], true))
                                                                                                        <button type="button"
                                                                                                            class="btn-edit-service-price inline-flex h-7 w-7 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-600 hover:border-red-500 hover:text-red-700"
                                                                                                            data-service-id="{{ $veh['idservicioCliente'] }}"
                                                                                                            data-service-price="{{ $veh['monto'] }}"
                                                                                                            data-current-cxc-id="{{ $veh['cxc_ids'][0] ?? '' }}"
                                                                                                            data-service-name="{{ $typeGroup['servicio_nombre'] }}"
                                                                                                            data-service-plate="{{ $veh['placa'] }}"
                                                                                                            title="Editar precio de este vehículo"
                                                                                                            aria-label="Editar precio de {{ $veh['placa'] }}">
                                                                                                            <i data-lucide="pencil"
                                                                                                                class="h-3.5 w-3.5"
                                                                                                                aria-hidden="true"></i>
                                                                                                        </button>
                                                                                                    @endif
                                                                                                    <button type="button"
                                                                                                        class="btn-pay-vehicle erp-btn-primary py-0.5 px-2 text-[11px]"
                                                                                                        data-service-id="{{ (int) $veh['idservicioCliente'] > 0 ? $veh['idservicioCliente'] : '' }}"
                                                                                                        data-client-id="{{ $clientId }}"
                                                                                                        data-client-retention="{{ !empty($clientGroup['es_retencion']) ? '1' : '0' }}"
                                                                                                        data-client-name="{{ $clientGroup['cliente_nombre'] }}"
                                                                                                        data-placa="{{ $veh['placa'] }}"
                                                                                                        data-amount="{{ $veh['monto'] }}"
                                                                                                        data-payment-amount="{{ $veh['monto_saldo'] }}"
                                                                                                        data-amount-display="{{ $veh['monto_display'] }}"
                                                                                                        data-tipo-cobro-id="{{ $veh['tipo_cobro_id'] ?? ($typeGroup['tipo_cobro_id'] ?? ($clientGroup['tipo_cobro_id'] ?? '')) }}"
                                                                                                        data-moneda-id="{{ $clientGroup['moneda_idmoneda'] ?? 1 }}"
                                                                                                        data-currency-symbol="{{ $clientGroup['moneda_simbolo'] ?? '$' }}"
                                                                                                        data-cxc-id="{{ $veh['cxc_ids'][0] ?? $clientGroup['cxc_id'] }}"
                                                                                                        data-cxc-ids="{{ implode(',', $veh['cxc_ids'] ?? []) }}"
                                                                                                        data-cxc-estado="{{ $veh['cxc_estado'] }}"
                                                                                                        data-advance-months="{{ $clientGroup['adelanto_meses'] ?? 0 }}"
                                                                                                        data-period-end="{{ $veh['fecha_fin_siguiente_iso'] ?? '' }}"
                                                                                                        data-next-period-end="{{ $veh['fecha_fin_siguiente_iso'] ?? '' }}"
                                                                                                        data-doc-ref="{{ $veh['doc_referencia'] === '-' ? '' : $veh['doc_referencia'] }}"
                                                                                                        data-credit-target-cxc-id="{{ $veh['credit_target_cxc_id'] ?? '' }}"
                                                                                                        data-credit-target-amount="{{ $veh['credit_target_amount'] ?? '' }}"
                                                                                                        data-credit-target-number="{{ $veh['credit_target_number'] ?? '' }}"
                                                                                                        data-credit-target-date="{{ $veh['credit_target_date'] ?? '' }}">
                                                                                                        {{ in_array((string) ($veh['cxc_estado'] ?? ''), ['3', 'CANCELADO', 'Pendiente Autodetracción', 'Pendiente Autoretención', 'Pendiente a Detracción', 'Pendiente a Retención', 'Cancelado Detracción'], true) ? 'Ver' : 'Gestionar' }}
                                                                                                    </button>
                                                                                                    @if(!empty($veh['puede_revertir']) && !empty($veh['cxc_ids'][0]))
                                                                                                        <button type="button"
                                                                                                            class="btn-revert-payment inline-flex items-center justify-center rounded-md border border-amber-500 px-2 py-0.5 text-[10px] font-bold text-amber-700 transition duration-200 hover:bg-amber-50"
                                                                                                            data-revert-url="{{ route('modules.cuentasporcobrar.revertir-pago', ['id' => $veh['cxc_ids'][0]]) }}"
                                                                                                            data-revert-service-id="{{ $veh['idservicioCliente'] }}"
                                                                                                            title="Anular el pago de este vehículo">
                                                                                                            Anular
                                                                                                        </button>
                                                                                                    @endif

                                                                                                </td>
                                                                                            </tr>
                                                                                        @endforeach
                                                                                    </tbody>
                                                                                </table>
                                                                            </div>
                                                                        </td>
                                                                    </tr>
                                                                @endforeach
                                                            </tbody>
                                                        </table>

                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div data-cxc-pagination
                                class="flex-reverse flex flex-col-reverse flex-wrap items-center gap-y-2 p-5 sm:flex-row">
                                <div class="mr-auto w-full flex-1 sm:w-auto">
                                    {{ $serviciosPorVencerGrouped->onEachSide(1)->links('layouts.pagination') }}
                                </div>
                                <select data-cxc-page-size name="perPage"
                                    class="transition duration-200 ease-in-out w-full text-sm border-slate-200 shadow-sm py-2 px-3 pr-8 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 rounded-[0.5rem] sm:w-20">
                                    @foreach([10, 25, 50, 100] as $limit)
                                        <option value="{{ $limit }}" @if((int) request('perPage', 10) === $limit) selected @endif>
                                            {{ $limit }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="revert-payment-modal" class="erp-modal-backdrop hidden" role="dialog" aria-modal="true"
        aria-labelledby="revert-payment-title">
        <div class="erp-modal-card" style="max-width: 520px;">
            <div class="erp-modal-header">
                <div>
                    <h3 id="revert-payment-title">Revertir cancelación</h3>
                    <p id="revert-payment-description" class="mt-1 text-xs font-medium text-slate-500">La CXC volverá al flujo de facturación y pago.</p>
                </div>
                <button type="button" class="erp-modal-close" data-close-revert-payment aria-label="Cerrar">×</button>
            </div>
            <form id="revert-payment-form" method="POST" action="">
                @csrf
                <div class="erp-modal-body p-5">
                    <label for="revert-payment-reason" class="mb-1.5 block text-sm font-bold text-slate-800">
                        Motivo de la reversión <span class="text-red-500">*</span>
                    </label>
                    <textarea id="revert-payment-reason" name="motivo" rows="4" maxlength="255" required
                        class="w-full rounded-lg border border-slate-300 p-3 text-sm text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20"
                        placeholder="Explica por qué se debe registrar nuevamente la factura y el pago..."></textarea>
                    <p id="revert-payment-error" class="mt-2 hidden text-sm font-semibold text-red-700" role="alert"></p>
                </div>
                <div class="erp-modal-actions border-t border-slate-100 px-5 py-3">
                    <button type="button" class="erp-btn-secondary" data-close-revert-payment>Cancelar</button>
                    <button type="submit" id="revert-payment-submit" class="erp-btn-primary">Confirmar reversión</button>
                </div>
            </form>
        </div>
    </div>

    <div id="service-price-modal" class="erp-modal-backdrop hidden" role="dialog" aria-modal="true"
        aria-labelledby="service-price-title">
        <div class="erp-modal-card" style="max-width: 440px;">
            <div class="erp-modal-header">
                <div>
                    <h3 id="service-price-title">Editar precio del vehículo</h3>
                    <p id="service-price-context" class="mt-1 text-xs font-medium text-slate-500"></p>
                </div>
                <button type="button" class="erp-modal-close" data-close-service-price aria-label="Cerrar">×</button>
            </div>
            <form id="service-price-form"
                data-url-template="{{ route('modules.cuentasporcobrar.servicios.precio', ['id' => '__ID__']) }}">
                @csrf
                @method('PATCH')
                <input id="service-price-id" type="hidden" name="servicioCliente_idservicioCliente">
                <input id="service-price-cxc-id" type="hidden" name="cuentasPorCobrar_idcuentasPorCobrar">
                <input id="service-price-keep-future" type="hidden" name="mantener_precio_siguiente" value="1">
                <div id="service-price-bulk-ids"></div>
                <div class="erp-modal-body p-5">
                    <label for="service-price-amount" class="block text-sm font-semibold text-slate-700">
                        Nuevo precio
                        <input id="service-price-amount" name="monto" type="number" min="0.01" step="0.01" required
                            class="mt-1 w-full rounded-lg border border-slate-300 p-2.5 text-sm font-bold text-slate-900 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                    </label>
                    <p id="service-price-error" class="mt-2 hidden text-xs font-semibold text-red-700" role="alert"></p>
                </div>
                <div class="erp-modal-actions border-t border-slate-100 px-5 py-3">
                    <button type="button" class="erp-btn-secondary" data-close-service-price>Cancelar</button>
                    <button type="submit" id="service-price-save" class="erp-btn-primary">
                        <i data-lucide="save" aria-hidden="true"></i> Guardar precio
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="service-price-confirm-modal" class="erp-modal-backdrop hidden" role="dialog" aria-modal="true"
        aria-labelledby="service-price-confirm-title">
        <div class="erp-modal-card" style="max-width: 480px;">
            <div class="erp-modal-header">
                <h3 id="service-price-confirm-title">¿Deseas mantener el precio editado para el siguiente mes?</h3>
                <button type="button" class="erp-modal-close" data-close-service-price-confirm aria-label="Volver a editar">×</button>
            </div>
            <div class="erp-modal-actions border-t border-slate-100 px-5 py-3">
                <button type="button" id="service-price-confirm-no" class="erp-btn-secondary">No</button>
                <button type="button" id="service-price-confirm-yes" class="erp-btn-primary">Sí</button>
            </div>
        </div>
    </div>

    <style>
        @media (min-width: 1320px) {
            .container {
                max-width: 1550px;
            }
        }

        .erp-cuentas-layout {
            width: 100%;
        }

        .erp-cuentas-card {
            background: #ffffff;
            border: 1px solid #dfe6ee;
            border-radius: 12px;
            box-shadow: 0 1px 0 rgba(15, 23, 42, 0.02);
            overflow: visible;
        }

        .erp-cuentas-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 20px 14px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .erp-cuentas-header h2 {
            margin: 0;
            font-size: 2rem;
            line-height: 1.2;
            font-weight: 700;
            color: #1f2937;
        }

        .erp-tabs {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #edf2f7;
            padding: 4px;
            border-radius: 10px;
            border: 1px solid #dfe7f0;
        }

        .tab-button {
            border: 1px solid transparent;
            background: transparent;
            color: #475569;
            font-size: 0.9rem;
            font-weight: 600;
            padding: 9px 14px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .tab-button.active {
            background: #ffffff;
            border-color: #dfe7f0;
            color: #0f172a;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
        }

        .erp-cuentas-body {
            padding: 18px 20px 20px;
            background: #ffffff;
            min-width: 0;
        }

        .erp-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding-bottom: 16px;
            margin-bottom: 12px;
            border-bottom: 1px solid #edf2f7;
        }

        .erp-service-filters {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr)) auto;
            align-items: end;
            gap: 12px;
            padding: 4px 0 16px;
            margin-bottom: 12px;
            border-bottom: 1px solid #edf2f7;
        }

        .erp-service-filter-field {
            display: flex;
            min-width: 0;
            flex-direction: column;
            gap: 6px;
            color: #64748b;
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .erp-service-filter-field input,
        .erp-service-filter-field select,
        .erp-month-picker summary {
            width: 100%;
            min-width: 0;
            height: 38px;
            box-sizing: border-box;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            background: #ffffff;
            padding: 8px 10px;
            color: #475569;
            font-size: 0.78rem;
            font-weight: 400;
            text-transform: none;
        }

        .erp-service-filter-field input:focus,
        .erp-service-filter-field select:focus,
        .erp-month-picker[open] summary {
            border-color: #b41b29;
            outline: none;
            box-shadow: 0 0 0 3px rgba(180, 27, 41, 0.1);
        }

        .erp-month-picker {
            position: relative;
            font-weight: 400;
        }

        .erp-month-picker summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            cursor: pointer;
            list-style: none;
        }

        .erp-month-picker summary::-webkit-details-marker {
            display: none;
        }

        .erp-month-picker summary span {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .erp-month-picker summary svg {
            width: 14px;
            height: 14px;
            flex: 0 0 14px;
        }

        .erp-month-picker-panel {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            z-index: 40;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 2px 10px;
            width: 250px;
            padding: 10px;
            border: 1px solid #d9e1ea;
            border-radius: 7px;
            background: #ffffff;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.14);
        }

        .erp-month-picker-panel label {
            display: flex;
            align-items: center;
            gap: 7px;
            min-height: 30px;
            padding: 4px 5px;
            border-radius: 4px;
            color: #334155;
            font-size: 0.78rem;
            font-weight: 500;
            cursor: pointer;
            text-transform: none;
        }

        .erp-month-picker-panel label:hover {
            background: #f8fafc;
        }

        .erp-month-picker-select-all {
            grid-column: 1 / -1;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 4px;
            padding-bottom: 7px !important;
        }

        .erp-month-picker-panel input {
            width: 15px;
            height: 15px;
            accent-color: #b41b29;
        }

        .erp-service-filter-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .erp-service-filter-actions .erp-btn-primary,
        .erp-service-filter-actions .erp-btn-secondary {
            min-height: 38px;
            padding: 8px 12px;
            white-space: nowrap;
        }

        .erp-service-filter-actions svg {
            width: 14px;
            height: 14px;
        }

        @media (max-width: 1180px) {
            .erp-service-filters {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .erp-service-filter-actions {
                grid-column: 1 / -1;
                justify-content: flex-end;
            }
        }

        .erp-section-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 18px;
        }

        .erp-section-stat {
            padding: 18px 20px;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            background: #ffffff;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        }

        .erp-section-stat-label {
            color: #64748b;
            font-size: 0.95rem;
        }

        .erp-section-stat-value {
            margin-top: 6px;
            color: #475569;
            font-size: 1.65rem;
            font-weight: 500;
        }

        .erp-standard-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 0 -2px 14px;
            padding: 0 0 14px;
            border-bottom: 1px solid #edf2f7;
        }

        .erp-standard-search {
            min-width: 0;
        }

        .erp-standard-search-field {
            position: relative;
            width: 256px;
        }

        .erp-standard-search-field span {
            position: absolute;
            top: 50%;
            left: 12px;
            color: #64748b;
            font-size: 1.2rem;
            line-height: 1;
            transform: translateY(-53%);
        }

        .erp-standard-search-field input {
            width: 100%;
            min-height: 38px;
            box-sizing: border-box;
            border: 1px solid #d9e1ea;
            border-radius: 8px;
            padding: 9px 12px 9px 34px;
            color: #334155;
            font-size: 0.85rem;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        }

        .erp-standard-search-field input:focus {
            outline: none;
            border-color: #b41b29;
            box-shadow: 0 0 0 3px rgba(180, 27, 41, 0.1);
        }

        .erp-standard-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .erp-standard-menu {
            position: relative;
        }

        .erp-standard-menu summary {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            min-height: 38px;
            box-sizing: border-box;
            padding: 8px 12px;
            border: 1px solid #d9e1ea;
            border-radius: 8px;
            background: #fff;
            color: #64748b;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            list-style: none;
            white-space: nowrap;
        }

        .erp-standard-menu summary::-webkit-details-marker {
            display: none;
        }

        .erp-standard-menu summary:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        .erp-standard-menu-panel {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            z-index: 30;
            min-width: 130px;
            padding: 8px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.14);
        }

        .erp-standard-menu-panel>a {
            display: block;
            padding: 9px 10px;
            border-radius: 6px;
            color: #334155;
            font-size: 0.82rem;
            text-decoration: none;
        }

        .erp-standard-menu-panel>a:hover {
            background: #f1f5f9;
        }

        .erp-standard-filter-panel {
            width: 240px;
        }

        .erp-standard-filter-panel label {
            display: block;
            margin-bottom: 10px;
            color: #475569;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .erp-standard-filter-panel input,
        .erp-standard-filter-panel select {
            display: block;
            width: 100%;
            min-height: 36px;
            margin-top: 5px;
            box-sizing: border-box;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 7px 8px;
            color: #334155;
            font-size: 0.8rem;
        }

        .erp-standard-filter-actions {
            display: flex;
            gap: 6px;
            border-top: 1px solid #edf2f7;
            padding-top: 10px;
        }

        .erp-standard-filter-actions .erp-btn-primary,
        .erp-standard-filter-actions .erp-btn-secondary {
            min-height: 34px;
            padding: 7px 10px;
            font-size: 0.78rem;
            text-decoration: none;
        }

        .erp-subtitle {
            font-size: 1.05rem;
            color: #475569;
        }

        .erp-subtitle strong {
            color: #c2410c;
            font-weight: 700;
        }

        .erp-btn-primary,
        .erp-btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 600;
            line-height: 1;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .erp-quote-number {
            background: transparent;
            border: none;
            padding: 0;
            color: #b41B29;
            font-weight: 700;
            text-decoration: underline;
            text-underline-offset: 2px;
            cursor: pointer;
        }

        .erp-btn-primary {
            background: #b41B29;
            color: #fff;
            padding: 10px 16px;
            box-shadow: 0 2px 6px rgba(220, 38, 38, 0.18);
        }

        .erp-btn-primary:hover {
            background: #960914;
        }

        .erp-btn-primary:disabled {
            background: #b41b298f;
            color: #e2e2e2ff;
            box-shadow: none;
            cursor: not-allowed;
            opacity: 0.8;
        }

        .erp-btn-primary svg {
            width: 14px;
            height: 14px;
            stroke: currentColor;
            stroke-width: 2.2;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .erp-btn-secondary {
            background: #ffffff;
            color: #334155;
            border: 1px solid #334155;
            padding: 8px 12px;
        }

        .erp-btn-secondary:hover {
            background: #f7f7f7;
        }

        .erp-table-wrap {
            width: 100%;
            max-width: 100%;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            overflow-x: auto;
            overflow-y: hidden;
            background: #fff;
            -webkit-overflow-scrolling: touch;
        }

        .erp-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            table-layout: fixed;
            font-size: 0.95rem;
            color: #334155;
        }

        .erp-quotations-table {
            width: 100%;
            min-width: 1485px;
        }

        .erp-services-table {
            width: 100%;
            min-width: 1120px;
        }

        .erp-services-table th,
        .erp-services-table td {
            box-sizing: border-box;
        }

        .erp-services-table th:nth-child(1),
        .erp-services-table td:nth-child(1) {
            width: 48px;
            min-width: 48px;
            padding-right: 6px;
            padding-left: 6px;
        }

        .erp-services-table th:nth-child(2),
        .erp-services-table td:nth-child(2) {
            width: 150px;
        }

        .erp-services-table th:nth-child(3),
        .erp-services-table td:nth-child(3) {
            width: 100px;
        }

        .erp-services-table th:nth-child(4),
        .erp-services-table td:nth-child(4) {
            width: 260px;
        }

        .erp-services-table th:nth-child(5),
        .erp-services-table td:nth-child(5) {
            width: 150px;
        }

        .erp-services-table th:nth-child(6),
        .erp-services-table td:nth-child(6) {
            width: 120px;
        }

        .erp-services-table th:nth-child(7),
        .erp-services-table td:nth-child(7) {
            width: 120px;
        }

        .erp-services-table th:nth-child(8),
        .erp-services-table td:nth-child(8) {
            width: 100px;
        }

        .erp-services-table th:nth-child(9),
        .erp-services-table td:nth-child(9) {
            width: 90px;
        }

        .erp-services-table th:nth-child(10),
        .erp-services-table td:nth-child(10) {
            width: 110px;
        }

        .erp-services-table input[type="checkbox"] {
            appearance: none;
            -webkit-appearance: none;
            width: 16px;
            height: 16px;
            margin: 0;
            border: 1.5px solid #cbd5e1;
            border-radius: 4px;
            background: #ffffff;
            cursor: pointer;
            vertical-align: middle;
            transition: background-color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .erp-services-table input[type="checkbox"]:hover {
            border-color: #b41b29;
        }

        .erp-services-table input[type="checkbox"]:focus-visible {
            outline: none;
            border-color: #b41b29;
            box-shadow: 0 0 0 3px rgba(180, 27, 41, 0.16);
        }

        .erp-services-table input[type="checkbox"]:checked,
        .erp-services-table input[type="checkbox"]:indeterminate {
            border-color: #b41b29;
            background-color: #b41b29;
        }

        .erp-services-table input[type="checkbox"]:checked::after {
            content: "";
            display: block;
            width: 4px;
            height: 8px;
            margin: 2px 0 0 5px;
            border: solid #ffffff;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }

        .erp-services-table input[type="checkbox"]:indeterminate::after {
            content: "";
            display: block;
            width: 8px;
            height: 2px;
            margin: 6px auto 0;
            background: #ffffff;
        }

        .erp-badge.service-days-red {
            background: #fee2e2;
            color: #b91c1c;
        }

        .erp-badge.service-days-yellow {
            background: #fef3c7;
            color: #a16207;
        }

        .erp-badge.service-days-green {
            background: #dcfce7;
            color: #15803d;
        }

        .erp-badge.service-days-gray {
            background: #e5e7eb;
            color: #4b5563;
        }

        /* ── Tabla Escalera (Servicios por vencer) ── */
        .erp-staircase-table {
            width: 100%;
            min-width: 1292px;
            table-layout: fixed;
            font-size: 0.62rem;
        }

        .erp-staircase-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .erp-staircase-table thead th {
            padding: 6px 3px;
            font-size: 0.60rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .erp-staircase-table tbody td {
            padding: 4px 3px;
            font-size: 0.62rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .erp-staircase-table tbody td:nth-child(10) {
            overflow: visible;
            text-overflow: clip;
            white-space: normal;
        }

        .erp-staircase-table tbody td:nth-child(10) .service-status-badge {
            max-width: 100%;
            white-space: normal;
            line-height: 1.25;
            text-align: center;
        }

        .erp-staircase-table .stair-client-row td {
            padding-top: 15px;
            padding-bottom: 15px;
        }

        .erp-staircase-table th:nth-child(1),
        .erp-staircase-table td:nth-child(1) {
            width: 32px;
            min-width: 32px;
            padding-right: 4px;
            padding-left: 4px;
            overflow: visible;
            text-overflow: clip;
        }

        .erp-staircase-table .stair-check-client,
        .erp-staircase-table .stair-check-type,
        .erp-staircase-table .stair-check-item {
            width: 16px;
            min-width: 16px;
            height: 16px;
            flex: 0 0 16px;
        }

        .erp-staircase-table th:nth-child(2),
        .erp-staircase-table td:nth-child(2) {
            width: 105px;
            padding: 14px 5px;
        }

        .erp-staircase-table td:nth-child(2) {
            overflow: visible;
            text-overflow: clip;
        }

        .erp-staircase-table th:nth-child(3),
        .erp-staircase-table td:nth-child(3) {
            width: 220px;
            padding: 14px 5px;
        }

        .erp-staircase-table th:nth-child(4),
        .erp-staircase-table td:nth-child(4) {
            width: 60px;
            padding: 14px 5px;
        }

        .erp-staircase-table th:nth-child(5),
        .erp-staircase-table td:nth-child(5) {
            width: 60px;
            padding: 14px 5px;
        }

        .erp-staircase-table th:nth-child(6),
        .erp-staircase-table td:nth-child(6) {
            width: 105px;
            padding: 14px 5px;
        }

        .erp-staircase-table th:nth-child(7),
        .erp-staircase-table td:nth-child(7) {
            width: 95px;
            padding: 14px 5px;
        }

        .erp-staircase-table th:nth-child(8),
        .erp-staircase-table td:nth-child(8) {
            width: 105px;
            padding: 14px 5px;
        }

        .erp-staircase-table th:nth-child(9),
        .erp-staircase-table td:nth-child(9) {
            width: 100px;
            padding: 14px 5px;
        }

        .erp-staircase-table th:nth-child(10),
        .erp-staircase-table td:nth-child(10) {
            width: 95px;
            padding: 14px 5px;
        }

        .erp-staircase-table th:nth-child(11),
        .erp-staircase-table td:nth-child(11) {
            width: 85px;
            padding: 14px 5px;
        }

        .erp-staircase-table th:nth-child(12),
        .erp-staircase-table td:nth-child(12) {
            width: 85px;
            padding: 14px 5px;
        }

        .erp-staircase-table th:nth-child(13),
        .erp-staircase-table td:nth-child(13) {
            width: 100px;
            padding: 14px 5px;
        }

        .erp-staircase-table .stair-action-group {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 2px;
            max-width: 100%;
        }

        .erp-services-nested-table {
            table-layout: fixed;
            width: 100%;
        }

        .erp-services-nested-table th,
        .erp-services-nested-table td {
            box-sizing: border-box;
            white-space: nowrap;
        }

        .erp-services-nested-table th:nth-child(1),
        .erp-services-nested-table td:nth-child(1) {
            width: 58%;
        }

        .erp-services-nested-table th:nth-child(2),
        .erp-services-nested-table td:nth-child(2) {
            width: 17%;
        }

        .erp-services-nested-table th:nth-child(3),
        .erp-services-nested-table td:nth-child(3) {
            width: 12%;
        }

        .erp-services-nested-table th:nth-child(4),
        .erp-services-nested-table td:nth-child(4) {
            width: 13%;
        }

        .erp-services-nested-table .stair-service-name,
        .erp-services-nested-table .stair-service-action {
            display: inline-flex;
            align-items: center;
        }

        .erp-services-nested-table .stair-service-name {
            gap: 8px;
            min-width: 0;
        }

        .erp-services-nested-table .stair-service-name .stair-check-type {
            flex: 0 0 16px;
            width: 16px;
            min-width: 16px;
            height: 16px;
        }

        .erp-services-nested-table .stair-service-action {
            justify-content: center;
            gap: 3px;
            max-width: 100%;
        }

        .erp-services-nested-table .stair-service-action .btn-pay-service-type {
            white-space: nowrap;
        }

        .erp-vehicles-nested-table {
            table-layout: fixed;
            width: 100%;
        }

        .erp-vehicles-nested-table th,
        .erp-vehicles-nested-table td {
            box-sizing: border-box;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .erp-vehicles-nested-table th:nth-child(1),
        .erp-vehicles-nested-table td:nth-child(1) {
            width: 10%;
        }

        .erp-vehicles-nested-table th:nth-child(2),
        .erp-vehicles-nested-table td:nth-child(2) {
            width: 12%;
        }

        .erp-vehicles-nested-table th:nth-child(3),
        .erp-vehicles-nested-table td:nth-child(3) {
            width: 7%;
        }

        .erp-vehicles-nested-table th:nth-child(4),
        .erp-vehicles-nested-table td:nth-child(4),
        .erp-vehicles-nested-table th:nth-child(5),
        .erp-vehicles-nested-table td:nth-child(5),
        .erp-vehicles-nested-table th:nth-child(6),
        .erp-vehicles-nested-table td:nth-child(6),
        .erp-vehicles-nested-table th:nth-child(7),
        .erp-vehicles-nested-table td:nth-child(7),
        .erp-vehicles-nested-table th:nth-child(8),
        .erp-vehicles-nested-table td:nth-child(8) {
            width: 7%;
        }

        .erp-vehicles-nested-table th:nth-child(9),
        .erp-vehicles-nested-table td:nth-child(9) {
            width: 10%;
        }

        .erp-vehicles-nested-table th:nth-child(10),
        .erp-vehicles-nested-table td:nth-child(10) {
            width: 10%;
        }

        .erp-vehicles-nested-table .stair-vehicle-plate {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 4px;
            width: 100%;
            min-width: 0;
        }

        .erp-vehicles-nested-table .stair-vehicle-plate-text {
            min-width: 0;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        .erp-vehicles-nested-table .stair-vehicle-status {
            max-width: 100%;
            white-space: normal;
        }

        .erp-vehicles-nested-table .stair-check-item {
            flex: 0 0 16px;
            width: 16px;
            min-width: 16px;
            height: 16px;
        }

        .erp-staircase-table input[type="checkbox"]:indeterminate,
        .erp-staircase-table input[type="checkbox"]:checked {
            --tw-ring-color: rgba(180, 27, 41, 0.2) !important;
            background-color: #b41b29 !important;
            border-color: #b41b29 !important;
        }

        .erp-staircase-table input[type="checkbox"]:focus {
            --tw-ring-color: rgba(180, 27, 41, 0.2) !important;
            border-color: #b41b29 !important;
        }

        .erp-quotations-table input[type="checkbox"] {
            accent-color: #b41b29;
        }

        .erp-quotations-table input[type="checkbox"]:checked,
        .erp-quotations-table input[type="checkbox"]:indeterminate {
            --tw-ring-color: rgba(180, 27, 41, 0.2) !important;
            background-color: #b41b29 !important;
            border-color: #b41b29 !important;
        }

        .erp-quotations-table input[type="checkbox"]:focus {
            --tw-ring-color: rgba(180, 27, 41, 0.2) !important;
            border-color: #b41b29 !important;
        }

        .erp-quotations-table .col-quote {
            width: 105px;
        }

        .erp-quotations-table .col-select {
            width: 42px;
        }

        .erp-quotations-table .col-client {
            width: 250px;
        }

        .erp-quotations-table .col-amount {
            width: 100px;
        }

        .erp-quotations-table .col-validity {
            width: 170px;
        }

        .erp-quotations-table .col-payment {
            width: 150px;
        }

        .erp-quotations-table .col-date {
            width: 125px;
        }

        .erp-quotations-table .col-group {
            width: 105px;
        }

        .erp-quotations-table .col-service {
            width: 180px;
        }

        .erp-quotations-table .col-file {
            width: 110px;
        }

        .erp-quotations-table .col-action {
            width: 125px;
        }

        .erp-table thead th {
            color: #000000;
            font-weight: 500;
            text-align: left;
            padding: 14px 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.80rem;
            text-transform: none;
            white-space: nowrap;
        }

        .erp-table tbody td {
            padding: 13px 12px;
            font-weight: 500;
            font-size: 0.80rem;
            border-bottom: 1px solid #edf2f7;
            vertical-align: middle;
            color: #000000;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            text-decoration: none;
        }

        .erp-quotations-table th,
        .erp-quotations-table td {
            box-sizing: border-box;
        }

        .erp-quotations-table td:nth-child(2),
        .erp-quotations-table td:nth-child(3),
        .erp-quotations-table td:nth-child(4),
        .erp-quotations-table td:nth-child(5),
        .erp-quotations-table td:nth-child(6),
        .erp-quotations-table td:nth-child(7) {
            text-overflow: ellipsis;
        }

        .erp-quotations-table tbody td:nth-child(10) {
            white-space: normal;
            overflow: visible;
            text-overflow: clip;
        }

        .erp-quotations-table tbody td:nth-child(10) .erp-badge {
            max-width: 100%;
            white-space: normal;
            line-height: 1.25;
            text-align: center;
        }

        .erp-quotations-table .erp-btn-primary {
            box-sizing: border-box;
            width: 100%;
            min-width: 0;
            padding: 6px 5px;
            font-size: 0.68rem;
            white-space: nowrap;
        }

        .erp-table tbody tr:last-child td {
            border-bottom: none;
        }

        .erp-table tbody tr:hover td {
            background: #f8fafc;
        }

        .erp-table th.text-center,
        .erp-table td.text-center {
            text-align: center;
        }

        .erp-quotations-table th:nth-child(5),
        .erp-quotations-table td:nth-child(5),
        .erp-quotations-table th:nth-child(6),
        .erp-quotations-table td:nth-child(6),
        .erp-quotations-table th:nth-child(8),
        .erp-quotations-table td:nth-child(8),
        .erp-quotations-table th:nth-child(9),
        .erp-quotations-table td:nth-child(9),
        .erp-quotations-table th:nth-child(10),
        .erp-quotations-table td:nth-child(10),
        .erp-quotations-table th:nth-child(11),
        .erp-quotations-table td:nth-child(11) {
            text-align: center;
        }

        .erp-service-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 9999px;
            padding: 6px 8px;
            background: #e0f2fe;
            color: #075985;
            font-size: 0.7rem;
            font-weight: 700;
            line-height: 1;
        }

        .erp-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 25px;
            padding: 8px 10px;
            font-size: 0.75rem;
            font-weight: 700;
            line-height: 1;
        }

        .erp-badge.success {
            background: #dcfce7;
            color: #166534;
        }

        .erp-badge.warning {
            background: #fef3c7;
            color: #92400e;
        }

        .erp-badge.danger {
            background: #fee2e2;
            color: #b91c1c;
        }

        .erp-empty-state {
            border: 1px dashed #d7dee7;
            border-radius: 10px;
            background: #f8fafc;
            color: #64748b;
            text-align: center;
            padding: 34px 20px;
            font-size: 0.95rem;
        }

        .erp-modal-backdrop {
            position: fixed !important;
            inset: 0 !important;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(0, 0, 0, 0.78);
            z-index: 99999 !important;
            padding: 20px;
        }

        .erp-modal-backdrop.visible {
            display: flex;
        }

        #withholding-warning-modal {
            z-index: 100001 !important;
            padding: 16px;
        }

        #quotation-withholding-proof-modal {
            z-index: 100002 !important;
            padding: 16px;
        }

        #saldo-favor-selector-modal {
            z-index: 100004 !important;
        }

        #credit-note-selector-modal.quotation-credit-note-modal {
            z-index: 100003 !important;
        }

        #credit-note-selector-modal input[type="radio"] {
            accent-color: #b41b29 !important;
        }

        #cxc-document-preview-image {
            width: auto;
            height: auto;
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        #quotation-withholding-proof-modal .erp-upload-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 112px;
            padding: 0.875rem;
        }

        #quotation-withholding-proof-modal .erp-upload-title {
            font-size: 0.875rem;
        }

        #quotation-withholding-proof-modal .erp-upload-help,
        #quotation-withholding-proof-modal .erp-upload-file-name {
            font-size: 0.75rem;
        }

        #withholding-warning-modal .erp-modal-card {
            width: min(540px, calc(100vw - 32px));
            max-width: 500px;
            max-height: calc(100dvh - 32px);
        }

        #service-factura-modal input:focus,
        #service-factura-modal select:focus,
        #service-factura-modal textarea:focus,
        #service-payment-modal input:focus,
        #service-payment-modal select:focus,
        #service-payment-modal textarea:focus {
            border-color: #b41b29 !important;
            outline: none !important;
            box-shadow: 0 0 0 3px rgba(180, 27, 41, 0.18) !important;
        }

        #service-factura-modal input[type="radio"],
        #service-payment-modal input[type="radio"],
        #service-factura-modal input[type="checkbox"],
        #service-payment-modal input[type="checkbox"] {
            accent-color: #b41b29 !important;
            cursor: pointer;
        }

        #service-factura-modal input[type="radio"],
        #service-payment-modal input[type="radio"] {
            appearance: none;
            -webkit-appearance: none;
            width: 15px;
            height: 15px;
            margin: 0;
            border: 1.5px solid #94a3b8;
            border-radius: 50%;
            background: #ffffff;
            position: relative;
            vertical-align: middle;
        }

        #service-factura-modal input[type="radio"]:hover,
        #service-payment-modal input[type="radio"]:hover,
        #service-factura-modal input[type="radio"]:focus-visible,
        #service-payment-modal input[type="radio"]:focus-visible {
            border-color: #b41b29;
        }

        #service-factura-modal input[type="radio"]:focus-visible,
        #service-payment-modal input[type="radio"]:focus-visible {
            outline: none;
            box-shadow: 0 0 0 3px rgba(180, 27, 41, 0.14);
        }

        #service-factura-modal input[type="radio"]:checked,
        #service-payment-modal input[type="radio"]:checked {
            border-color: #b41b29;
            background-color: #ffffff;
            background-image: radial-gradient(circle, #b41b29 0 42%, #ffffff 43%);
            background-repeat: no-repeat;
            background-position: center;
            box-shadow: none;
        }

        #service-factura-modal .factura-upload-box,
        #service-payment-modal .payment-upload-box {
            border-color: #fca5a5;
            background: #fff7f7;
        }

        #service-factura-modal .factura-upload-box:hover,
        #service-factura-modal .factura-upload-box.is-dragging,
        #service-factura-modal .factura-upload-box:focus-within,
        #service-payment-modal .payment-upload-box:hover,
        #service-payment-modal .payment-upload-box.is-dragging,
        #service-payment-modal .payment-upload-box:focus-within {
            border-color: #b41b29;
            background: #fff1f2;
            box-shadow: 0 0 0 3px rgba(180, 27, 41, 0.1);
        }

        #service-factura-modal .factura-upload-box.is-invalid,
        #service-payment-modal .payment-upload-box.is-invalid {
            border-color: #b91c1c;
            background: #fef2f2;
        }

        #service-factura-modal .erp-factura-mode-shell {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 12px;
            margin-bottom: 5px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.8);
            transition: all 0.2s ease;
        }

        #service-factura-modal .erp-factura-mode-text {
            min-width: 0;
            flex: 1;
        }

        #service-factura-modal .erp-factura-mode-title {
            display: block;
            font-size: 9px;
            line-height: 1.2;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #0f172a;
        }

        #service-factura-modal .erp-factura-mode-desc {
            display: block;
            margin-top: 3px;
            font-size: 10px;
            line-height: 1.35;
            color: #64748b;
        }

        #service-factura-modal .erp-factura-mode-switch {
            position: relative;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            width: 146px;
            min-width: 146px;
            padding: 3px;
            border-radius: 999px;
            background: #e2e8f0;
            border: 1px solid #cbd5e1;
            cursor: pointer;
            box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.06);
            transition: all 0.2s ease;
        }

        #service-factura-modal .erp-factura-mode-switch:focus-visible {
            outline: 3px solid rgba(180, 27, 41, 0.18);
            outline-offset: 2px;
        }

        #service-factura-modal .erp-factura-mode-switch .erp-factura-mode-thumb {
            position: absolute;
            top: 3px;
            left: 3px;
            width: calc(50% - 5px);
            height: calc(100% - 6px);
            border-radius: 999px;
            background: linear-gradient(180deg, #b41b29 0%, #8f141d 100%);
            box-shadow: 0 4px 10px rgba(180, 27, 41, 0.25);
            transition: transform 0.22s ease;
        }

        #service-factura-modal .erp-factura-mode-switch[data-mode="cancelar"] .erp-factura-mode-thumb {
            transform: translateX(100%);
        }

        #service-factura-modal .erp-factura-mode-option {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 24px;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 8.5px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #475569;
            transition: color 0.2s ease;
        }

        #service-factura-modal .erp-factura-mode-option.is-active {
            color: #ffffff;
        }

        #service-factura-modal .erp-modal-card {
            transition: max-width 0.3s cubic-bezier(0.4, 0, 0.2, 1), width 0.3s ease;
            max-width: 550px;
            width: 100%;
        }

        #service-factura-modal.factura-mode-expanded .erp-modal-card {
            max-width: 1180px !important;
            width: 96vw;
            max-height: 96vh;
        }

        #service-factura-modal .factura-grid-container {
            display: block;
        }

        #service-factura-modal.factura-mode-expanded .factura-grid-container {
            display: grid;
            grid-template-columns: minmax(0, 0.75fr) minmax(0, 0.90fr);
            gap: 0.75rem;
            align-items: start;
        }

        #service-factura-modal .erp-factura-mode-extra {
            display: none;
            opacity: 0;
            transition: opacity 0.25s ease;
        }

        #service-factura-modal.factura-mode-expanded .erp-factura-mode-extra {
            display: block;
            opacity: 1;
            pointer-events: auto;
        }

        .erp-modal-card {
            width: min(660px, 100%);
            max-height: min(820px, 94vh);
            display: flex;
            flex-direction: column;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.24);
            overflow: hidden;
        }

        .erp-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 18px;
            border-bottom: 1px solid #edf2f7;
            background: #ffffff;
        }

        .erp-modal-header h3 {
            margin: 0;
            font-size: 1rem;
            font-weight: 700;
            color: #0f172a;
        }

        .erp-modal-close {
            border: none;
            background: transparent;
            color: #475569;
            cursor: pointer;
            font-size: 1.4rem;
            line-height: 1;
            padding: 0;
        }

        .erp-modal-body {
            padding: 8px 18px;
            overflow-y: auto;
            max-height: calc(90vh - 60px);
        }

        #service-factura-modal .erp-modal-body * {
            font-size: 0.8rem;
        }

        #service-factura-modal .erp-modal-header h3 {
            font-size: 0.95rem;
        }

        #service-factura-modal .erp-modal-body .erp-factura-mode-title,
        #service-factura-modal .erp-modal-body .erp-factura-mode-desc {
            font-size: 0.75rem;
        }

        #service-factura-modal .erp-modal-body h4 {
            font-size: 0.78rem;
        }

        #service-payment-modal .erp-modal-card {
            width: min(900px, calc(100vw - 32px));
            max-width: 900px;
            max-height: 94vh;
        }

        #service-payment-modal form {
            display: flex;
            min-height: 0;
            flex: 1;
            flex-direction: column;
        }

        #service-payment-modal .erp-modal-body {
            min-height: 0;
            flex: 1;
            color: #334155;
        }

        #erp-gestion-modal {
            overflow-y: auto;
            padding: 10px;
        }

        #erp-gestion-modal .erp-modal-card {
            width: min(560px, 100%);
            max-height: calc(100vh - 20px);
            max-height: calc(100dvh - 20px);
            margin: auto;
        }

        #erp-gestion-modal form {
            display: flex;
            min-height: 0;
            flex: 1;
            flex-direction: column;
        }

        #erp-gestion-modal .erp-modal-body {
            min-height: 0;
            max-height: none;
            flex: 1;
            overflow-y: auto;
            overscroll-behavior: contain;
        }

        #erp-gestion-modal .erp-modal-actions {
            flex-shrink: 0;
        }

        #service-payment-modal label,
        #service-payment-modal legend {
            color: #475569;
            font-size: 0.65rem;
            font-weight: 700;
        }

        #service-payment-modal input:not([type="radio"]),
        #service-payment-modal select,
        #service-payment-modal textarea {
            min-height: 36px;
            border: 1px solid #d5dde7;
            border-radius: 6px;
            color: #334155;
            font-size: 0.82rem;
            box-shadow: none;
        }

        #service-payment-modal #payment-original {
            min-height: 0;
            border: 0 !important;
            border-radius: 0;
            box-shadow: none !important;
        }

        #service-payment-modal #payment-original::-webkit-inner-spin-button,
        #service-payment-modal #payment-original::-webkit-outer-spin-button,
        #service-factura-modal #factura-amount::-webkit-inner-spin-button,
        #service-factura-modal #factura-amount::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        #service-payment-modal #payment-original,
        #service-factura-modal #factura-amount {
            -moz-appearance: textfield;
        }

        #service-payment-modal input:not([type="radio"]):focus,
        #service-payment-modal select:focus,
        #service-payment-modal textarea:focus {
            border-color: #b41b29;
            outline: none;
            box-shadow: 0 0 0 3px rgba(180, 27, 41, 0.12);
        }

        #service-payment-modal input[type="radio"] {
            appearance: none;
            -webkit-appearance: none;
            width: 15px;
            height: 15px;
            margin: 0;
            border: 1.5px solid #94a3b8;
            border-radius: 50%;
            background: #ffffff;
            accent-color: #b41b29;
            cursor: pointer;
            position: relative;
        }

        #service-payment-modal input[type="radio"]:hover,
        #service-payment-modal input[type="radio"]:focus-visible {
            border-color: #b41b29;
        }

        #service-payment-modal input[type="radio"]:focus-visible {
            outline: none;
            box-shadow: 0 0 0 3px rgba(180, 27, 41, 0.14);
        }

        #service-payment-modal input[type="radio"]:checked {
            border-color: #b41b29;
            background-color: #ffffff;
            background-image: radial-gradient(circle, #b41b29 0 42%, #ffffff 43%);
            background-repeat: no-repeat;
            background-position: center;
            box-shadow: none;
        }

        #service-payment-modal .erp-payment-summary,
        #service-payment-modal .erp-payment-mode {
            border: 1px;
            border-radius: 8px;
        }

        #service-payment-modal .erp-payment-summary {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 12px;
            margin-bottom: 14px;
            padding: 12px;
        }

        #service-payment-modal .erp-payment-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 12px;
        }

        #service-payment-modal .erp-payment-field {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        #service-payment-modal .erp-payment-field.hidden {
            display: none !important;
        }

        #service-payment-modal .erp-payment-full {
            grid-column: 1 / -1;
        }

        #service-payment-modal .erp-payment-mode {
            display: flex;
            align-items: center;
            gap: 18px;
            margin: 2px 0 0;
            padding: 5px 1px;
        }

        #service-payment-modal .erp-payment-mode label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            font-weight: 600;
        }

        #service-payment-modal .erp-modal-actions {
            padding-top: 14px;
            border-top: 1px solid #edf2f7;
        }

        #service-payment-modal .erp-btn-primary:hover {
            background: #960914;
        }

        @media (max-width: 640px) {

            #service-payment-modal .erp-payment-summary,
            #service-payment-modal .erp-payment-grid {
                grid-template-columns: 1fr;
            }

            #service-payment-modal .erp-payment-full {
                grid-column: auto;
            }
        }

        .erp-upload-box {
            display: block;
            width: 100%;
            box-sizing: border-box;
            border: 1.5px dashed #cbd5e1;
            border-radius: 12px;
            background: #f8fafc;
            padding: 12px 14px;
            text-align: center;
            cursor: pointer;
            position: relative;
            transition: border-color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
        }

        .erp-upload-box:hover,
        .erp-upload-box.is-dragging,
        .erp-upload-box:focus-within {
            border-color: #b41b29;
            background: #fff7f7;
            box-shadow: 0 0 0 3px rgba(180, 27, 41, 0.1);
        }

        .erp-upload-box.is-invalid {
            border-color: #b91c1c;
            background: #fef2f2;
        }

        .erp-upload-icon {
            display: block;
            width: 25px;
            height: 25px;
            margin: 0 auto 6px;
            color: #64748b;
        }

        .erp-upload-title,
        .erp-upload-help,
        .erp-upload-file-name {
            display: block;
        }

        .erp-upload-title {
            color: #1e293b;
            font-size: 0.65rem;
            font-weight: 700;
        }

        .erp-upload-help,
        .erp-upload-file-name {
            margin-top: 3px;
            color: #64748b;
            font-size: 0.52rem;
        }

        .erp-upload-file-name {
            color: #b41b29;
            font-weight: 700;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .erp-upload-box input[type="file"] {
            position: absolute;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
        }

        .erp-upload-preview {
            width: 100%;
            max-height: 180px;
            object-fit: contain;
            display: none;
            margin-top: 15px;
        }

        .erp-upload-preview.visible {
            display: block;
        }

        .erp-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 0;
        }

        .erp-gestion-saving-status {
            display: none;
            align-items: center;
            gap: 8px;
            margin-right: auto;
            color: #64748b;
            font-size: 0.875rem;
        }

        .erp-gestion-saving-status.is-visible {
            display: inline-flex;
        }

        .erp-gestion-spinner {
            width: 16px;
            height: 16px;
            flex: 0 0 16px;
            border: 2px solid #cbd5e1;
            border-top-color: #b41b29;
            border-radius: 50%;
            animation: erp-gestion-spin 0.8s linear infinite;
        }

        @keyframes erp-gestion-spin {
            to {
                transform: rotate(360deg);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .erp-gestion-spinner {
                animation: none;
            }
        }

        @media (max-width: 768px) {
            .erp-cuentas-body {
                padding-right: 12px;
                padding-left: 12px;
            }

            .erp-service-filters {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }

            .erp-table-wrap {
                border-radius: 8px;
                scrollbar-width: thin;
            }

            .erp-standard-toolbar {
                align-items: stretch;
                flex-direction: column;
            }

            .erp-standard-search-field {
                width: 100%;
            }

            .erp-standard-actions {
                justify-content: flex-end;
            }

            .erp-quotations-table {
                min-width: 1485px;
            }

            .erp-services-table {
                min-width: 1120px;
            }

            .erp-staircase-table {
                min-width: 1292px;
            }

            .erp-cuentas-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .erp-cuentas-header h2 {
                font-size: 1.7rem;
            }

            .erp-toolbar {
                flex-direction: column;
                align-items: flex-start;
            }

            .erp-section-stats {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .erp-tabs {
                width: 100%;
                justify-content: space-between;
            }

            .tab-button {
                flex: 1;
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            .erp-service-filters {
                grid-template-columns: 1fr;
            }

            .erp-service-filter-actions {
                display: grid;
                grid-template-columns: 1fr 1fr;
            }
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const showCxcNotice = (type, message) => {
                const isSuccess = type === 'success';
                const alert = document.getElementById(isSuccess ? 'cxc-global-success-alert' : 'cxc-global-error-alert');
                const text = document.getElementById(isSuccess ? 'cxc-global-success-text' : 'cxc-global-error-text');
                const otherAlert = document.getElementById(isSuccess ? 'cxc-global-error-alert' : 'cxc-global-success-alert');
                if (!alert || !text) {
                    console.error('No se pudo mostrar la notificación de Cuentas por Cobrar: falta el contenedor.');
                    return;
                }
                text.textContent = (isSuccess ? '✓ ' : '✕ ') + String(message || '');
                alert.classList.remove('hidden');
                otherAlert?.classList.add('hidden');
            };

            const pendingCxcSuccessNoticeRaw = sessionStorage.getItem('cxc-pending-success-notice');
            if (pendingCxcSuccessNoticeRaw) {
                sessionStorage.removeItem('cxc-pending-success-notice');
                let pendingCxcSuccessNotice = null;
                try {
                    const parsedNotice = JSON.parse(pendingCxcSuccessNoticeRaw);
                    pendingCxcSuccessNotice = {
                        message: parsedNotice?.message || String(pendingCxcSuccessNoticeRaw),
                        tab: parsedNotice?.tab || null
                    };
                } catch {
                    pendingCxcSuccessNotice = {
                        message: pendingCxcSuccessNoticeRaw,
                        tab: null
                    };
                }
                const activeTab = new URLSearchParams(window.location.search).get('tab') === 'servicios'
                    ? 'servicios'
                    : 'cotizaciones';
                if (!pendingCxcSuccessNotice.tab || pendingCxcSuccessNotice.tab === activeTab) {
                    showCxcNotice('success', pendingCxcSuccessNotice.message);
                    const notice = document.getElementById('service-inline-message');
                    if (notice) {
                        notice.textContent = pendingCxcSuccessNotice.message;
                        notice.classList.remove('hidden');
                    }
                }
            }

            let cxcRequestController = null;
            let cxcRequestId = 0;
            let cxcListLoading = false;
            const cxcControlDisabledStates = new WeakMap();
            const cxcPaginationLinkStates = new WeakMap();
            let activeCxcTableSkeleton = null;
            let cxcQuery = new URLSearchParams(window.location.search);
            const loadedCxcTab = document.getElementById('cxc-list-wrapper')?.dataset.loadedTab;
            const loadedCxcTabs = new Set([
                ...(loadedCxcTab ? [loadedCxcTab] : [])
            ]);
            function setCxcPaginationLinkLoading(link, loading) {
                if (loading) {
                    if (!cxcPaginationLinkStates.has(link)) {
                        cxcPaginationLinkStates.set(link, {
                            pointerEvents: link.style.pointerEvents,
                            opacity: link.style.opacity,
                            ariaDisabled: link.getAttribute('aria-disabled'),
                            tabIndex: link.getAttribute('tabindex'),
                        });
                    }
                    link.style.pointerEvents = 'none';
                    link.style.opacity = '0.5';
                    link.setAttribute('aria-disabled', 'true');
                    link.setAttribute('tabindex', '-1');
                } else if (cxcPaginationLinkStates.has(link)) {
                    const state = cxcPaginationLinkStates.get(link);
                    link.style.pointerEvents = state.pointerEvents;
                    link.style.opacity = state.opacity;
                    if (state.ariaDisabled === null) link.removeAttribute('aria-disabled');
                    else link.setAttribute('aria-disabled', state.ariaDisabled);
                    if (state.tabIndex === null) link.removeAttribute('tabindex');
                    else link.setAttribute('tabindex', state.tabIndex);
                    cxcPaginationLinkStates.delete(link);
                }
            }
            function setCxcListControlsLoading(loading) {
                document.querySelectorAll(
                    '#quote-filter-form input:not([type="hidden"]), #quote-filter-form select, ' +
                    '#service-filter-form input:not([type="hidden"]), #service-filter-form select, ' +
                    '#quote-filter-apply-btn, #quote-filter-clear-btn, #service-filter-apply-btn, #service-filter-clear-btn, ' +
                    '[data-cxc-page-size], .tab-button'
                ).forEach((control) => {
                    if (loading) {
                        if (!cxcControlDisabledStates.has(control)) {
                            cxcControlDisabledStates.set(control, control.disabled);
                        }
                        control.disabled = true;
                    } else if (cxcControlDisabledStates.has(control)) {
                        control.disabled = cxcControlDisabledStates.get(control);
                        cxcControlDisabledStates.delete(control);
                    }
                });
                document.querySelectorAll('[data-cxc-pagination] nav a[href]')
                    .forEach((link) => setCxcPaginationLinkLoading(link, loading));
            }
            function setCxcTableSkeletonLoading(tab, loading) {
                if (loading) {
                    if (activeCxcTableSkeleton) return;
                    const resultId = tab === 'servicios' ? 'service-results' : 'quote-results';
                    const container = document.getElementById(resultId);
                    let table = container?.querySelector('table');
                    let temporaryWrapper = null;
                    if (!table && container) {
                        const placeholder = container.querySelector('.erp-empty-state');
                        temporaryWrapper = document.createElement('div');
                        temporaryWrapper.className = tab === 'servicios'
                            ? 'erp-table-wrap erp-staircase-wrap'
                            : 'erp-table-wrap';
                        temporaryWrapper.style.minHeight = '360px';
                        table = document.createElement('table');
                        table.className = tab === 'servicios'
                            ? 'erp-table erp-staircase-table'
                            : 'erp-table erp-quotations-table';
                        const headerLabels = tab === 'servicios'
                            ? ['', 'RUC', 'Cliente', 'N° UNID', 'N° SERV', 'Fecha Registro', 'Fecha Límite', 'Mes', 'Documento', 'Estado', 'Días Serv', 'Monto', 'Acción']
                            : ['', 'N° Cotiz', 'Cliente', 'Monto', 'Vigencia de oferta', 'Formato de pago', 'Fecha Emision', 'Grupo', 'Servicio', 'Estado', 'Acción'];
                        const thead = document.createElement('thead');
                        const headerRow = document.createElement('tr');
                        headerLabels.forEach((label) => {
                            const cell = document.createElement('th');
                            cell.textContent = label;
                            headerRow.appendChild(cell);
                        });
                        thead.appendChild(headerRow);
                        table.appendChild(thead);
                        temporaryWrapper.appendChild(table);
                        if (placeholder) placeholder.replaceWith(temporaryWrapper);
                        else container.appendChild(temporaryWrapper);
                    }
                    const parent = table?.parentElement;
                    if (!table || !parent) return;

                    let animationStyle = document.getElementById('erp-table-skeleton-styles');
                    if (!animationStyle) {
                        animationStyle = document.createElement('style');
                        animationStyle.id = 'erp-table-skeleton-styles';
                        animationStyle.textContent = `
                            @keyframes erp-table-skeleton-shimmer { 100% { transform: translateX(100%); } }
                            .erp-table-skeleton-overlay { position:absolute; inset:0; z-index:20; overflow:hidden; background:rgba(255,255,255,.94); pointer-events:none; }
                            .erp-table-skeleton-overlay table { width:100%; min-width:100%; }
                            .erp-table-skeleton-bar { display:block; position:relative; overflow:hidden; height:12px; width:72%; border-radius:999px; background:#e8ebf0; }
                            .erp-table-skeleton-bar::after { position:absolute; inset:0; content:''; transform:translateX(-100%); background:linear-gradient(90deg,transparent,rgba(255,255,255,.8),transparent); animation:erp-table-skeleton-shimmer 1.2s infinite; }
                            .erp-table-skeleton-overlay tr:nth-child(2n) .erp-table-skeleton-bar { width:54%; }
                            .erp-table-skeleton-overlay td:nth-child(3n) .erp-table-skeleton-bar { width:86%; }
                        `;
                        document.head.appendChild(animationStyle);
                    }

                    const skeleton = table.cloneNode(true);
                    skeleton.removeAttribute('id');
                    skeleton.querySelectorAll('[id]').forEach((element) => element.removeAttribute('id'));
                    skeleton.querySelectorAll('button, input, select, a').forEach((element) => {
                        if (element.closest('thead')) element.replaceWith(document.createTextNode(element.textContent || ''));
                    });
                    skeleton.querySelectorAll('tbody, tfoot').forEach((section) => section.remove());
                    const body = document.createElement('tbody');
                    skeleton.appendChild(body);
                    const sourceRows = Array.from(table.querySelectorAll('tbody tr'))
                        .filter((row) => row.getClientRects().length > 0);
                    const columnCount = Math.max(1, skeleton.querySelectorAll('thead tr:first-child th').length);
                    const pageSize = Number(document.querySelector('[data-cxc-page-size]')?.value || 10);
                    const rowCount = Math.max(1, Math.min(100, pageSize));
                    for (let rowIndex = 0; rowIndex < rowCount; rowIndex++) {
                        const sourceRow = sourceRows.length ? sourceRows[rowIndex % sourceRows.length] : null;
                        const sourceCells = sourceRow
                            ? Array.from(sourceRow.children).filter((cell) => cell.tagName === 'TD')
                            : [];
                        const row = sourceRow ? sourceRow.cloneNode(true) : document.createElement('tr');
                        row.replaceChildren();
                        row.removeAttribute('id');
                        const sourceHeight = sourceRow?.getBoundingClientRect().height || 0;
                        row.style.height = `${sourceHeight > 0 ? sourceHeight : 44}px`;
                        const cells = sourceCells.length ? sourceCells : Array(columnCount).fill(null);
                        cells.forEach((sourceCell) => {
                            const cell = sourceCell?.cloneNode(false) || document.createElement('td');
                            cell.removeAttribute('id');
                            cell.replaceChildren();
                            const bar = document.createElement('span');
                            bar.className = 'erp-table-skeleton-bar';
                            cell.appendChild(bar);
                            row.appendChild(cell);
                        });
                        body.appendChild(row);
                    }

                    const previousPosition = parent.style.position;
                    if (getComputedStyle(parent).position === 'static') parent.style.position = 'relative';
                    const previousVisibility = table.style.visibility;
                    table.style.visibility = 'hidden';
                    const overlay = document.createElement('div');
                    overlay.className = 'erp-table-skeleton-overlay';
                    overlay.appendChild(skeleton);
                    parent.appendChild(overlay);
                    activeCxcTableSkeleton = { table, parent, overlay, previousPosition, previousVisibility, temporaryWrapper };
                    return;
                }

                if (!activeCxcTableSkeleton) return;
                const { table, parent, overlay, previousPosition, previousVisibility, temporaryWrapper } = activeCxcTableSkeleton;
                overlay.remove();
                table.style.visibility = previousVisibility;
                parent.style.position = previousPosition;
                temporaryWrapper?.remove();
                activeCxcTableSkeleton = null;
            }
            function refreshCxcIcons() {
                try {
                    if (typeof createIcons === 'function' && typeof icons !== 'undefined') {
                        createIcons({ icons, attrs: { 'stroke-width': 1.5 }, nameAttr: 'data-lucide' });
                        return;
                    }
                    if (window.lucide && typeof window.lucide.createIcons === 'function') {
                        if (window.lucide.icons) {
                            window.lucide.createIcons({
                                icons: window.lucide.icons,
                                attrs: { 'stroke-width': 1.5 },
                                nameAttr: 'data-lucide'
                            });
                        } else {
                            window.lucide.createIcons();
                        }
                    }
                } catch (error) {
                    console.warn('No se pudieron actualizar los iconos de Cuentas por Cobrar:', error);
                }
            }

            const quoteExportUrl = @json(route('modules.cuentasporcobrar.quotations.export', ['format' => 'xlsx']));
            function refreshQuoteExportUrl() {
                const exportLink = document.getElementById('quote-export-xlsx');
                if (!exportLink) return;

                const exportUrl = new URL(quoteExportUrl, window.location.origin);
                ['quote_client', 'quote_number', 'quote_payment_format', 'quote_validity', 'quote_service', 'quote_status', 'quote_date']
                    .forEach((name) => {
                        const value = cxcQuery.get(name);
                        if (value !== null) exportUrl.searchParams.set(name, value);
                    });
                if (!exportUrl.searchParams.has('quote_status')) {
                    exportUrl.searchParams.set('quote_status', 'all');
                }
                exportLink.href = exportUrl.toString();
            }

            const serviceExportUrl = @json(route('modules.cuentasporcobrar.services.export', ['format' => 'xlsx']));
            function refreshServiceExportUrl() {
                const exportLink = document.getElementById('service-export-xlsx');
                if (!exportLink) return;

                const exportUrl = new URL(serviceExportUrl, window.location.origin);
                ['service_client', 'service_month', 'service_year', 'service_document', 'service_date', 'service_expiration_date', 'service_status']
                    .forEach((name) => {
                        const value = cxcQuery.get(name);
                        if (value !== null) exportUrl.searchParams.set(name, value);
                    });
                exportLink.href = exportUrl.toString();
            }

            function refreshQuoteAnnulButton() {
                const annulButton = document.getElementById('quote-annul-open');
                if (!annulButton) return;

                const selectedCount = document.querySelectorAll('#quote-results .quote-row-select:checked:not(:disabled)').length;
                annulButton.disabled = selectedCount === 0;
                annulButton.style.opacity = selectedCount === 0 ? '0.75' : '1';
                annulButton.style.cursor = selectedCount === 0 ? 'not-allowed' : 'pointer';
            }

            async function loadCxcResults(url, tab, options = {}) {
                if (cxcListLoading) return;
                cxcListLoading = true;
                setCxcListControlsLoading(true);
                setCxcTableSkeletonLoading(tab, true);
                const requestId = ++cxcRequestId;
                cxcRequestController = new AbortController();
                const requestUrl = new URL(url, window.location.origin);
                requestUrl.searchParams.set('tab', tab);

                try {
                    const response = await fetch(requestUrl.pathname + requestUrl.search, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        signal: cxcRequestController.signal
                    });
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);

                    const documentHtml = new DOMParser().parseFromString(await response.text(), 'text/html');
                    if (requestId !== cxcRequestId) return;
                    cxcQuery = new URLSearchParams(requestUrl.search);
                    if (tab === 'cotizaciones') refreshQuoteExportUrl();
                    if (tab === 'servicios') refreshServiceExportUrl();

                    const resultId = tab === 'servicios' ? 'service-results' : 'quote-results';
                    const currentResults = document.getElementById(resultId);
                    const nextResults = documentHtml.getElementById(resultId);
                    if (!currentResults || !nextResults) throw new Error('No se encontró el bloque de resultados.');
                    currentResults.replaceWith(nextResults);
                    loadedCxcTabs.add(tab);
                    document.getElementById('cxc-list-wrapper')?.setAttribute('data-loaded-tab', tab);
                    if (tab === 'servicios') {
                        bindServiceResultActions(nextResults);
                        bindServiceResultPaymentActions(nextResults);
                    }

                    if (tab === 'cotizaciones') {
                        const currentStats = document.getElementById('quote-stats');
                        const nextStats = documentHtml.getElementById('quote-stats');
                        if (currentStats && nextStats) currentStats.replaceWith(nextStats);
                    } else {
                        const currentStats = document.getElementById('service-stats');
                        const nextStats = documentHtml.getElementById('service-stats');
                        if (currentStats && nextStats) currentStats.replaceWith(nextStats);
                    }

                    const pageSize = requestUrl.searchParams.get('perPage') || '10';
                    document.querySelectorAll('[data-cxc-page-size]').forEach((select) => {
                        select.value = pageSize;
                    });
                    refreshCxcIcons();
                    applyCotizacionFilters();
                    applyServicioSearch();
                    refreshServiceSelection();
                    refreshSelectedVehiclePaymentButtons();
                    refreshBajaBtn();
                    refreshQuoteAnnulButton();
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        console.error('Error actualizando Cuentas por Cobrar:', error);
                        if (requestId === cxcRequestId) {
                            const resultId = tab === 'servicios' ? 'service-results' : 'quote-results';
                            const results = document.getElementById(resultId);
                            if (results) {
                                const message = document.createElement('div');
                                message.className = 'erp-empty-state';
                                message.textContent = 'No se pudo cargar esta sección. Reintenta la carga.';

                                const retryButton = document.createElement('button');
                                retryButton.type = 'button';
                                retryButton.className = 'erp-btn-secondary mt-3';
                                retryButton.textContent = 'Reintentar';
                                retryButton.addEventListener('click', () => {
                                    loadCxcResults(requestUrl.pathname + requestUrl.search, tab, options);
                                }, { once: true });

                                results.replaceChildren(message, retryButton);
                            }
                        }
                    }
                } finally {
                    if (requestId === cxcRequestId) {
                        cxcRequestController = null;
                        cxcListLoading = false;
                        setCxcTableSkeletonLoading(tab, false);
                        setCxcListControlsLoading(false);
                    }
                }
            }

            document.addEventListener('change', function (event) {
                const quoteSelectAll = event.target.closest('#quote-select-all');
                if (quoteSelectAll) {
                    document.querySelectorAll('#quote-results .quote-row-select').forEach((checkbox) => {
                        if (!checkbox.disabled) checkbox.checked = quoteSelectAll.checked;
                    });
                    quoteSelectAll.indeterminate = false;
                    refreshQuoteAnnulButton();
                    return;
                }

                const quoteRowSelect = event.target.closest('.quote-row-select');
                if (quoteRowSelect) {
                    const quoteCheckboxes = Array.from(document.querySelectorAll('#quote-results .quote-row-select'));
                    const selectableCheckboxes = quoteCheckboxes.filter((checkbox) => !checkbox.disabled);
                    const quoteSelectAllInput = document.getElementById('quote-select-all');
                    if (quoteSelectAllInput) {
                        const selectedCount = selectableCheckboxes.filter((checkbox) => checkbox.checked).length;
                        quoteSelectAllInput.checked = selectableCheckboxes.length > 0 && selectedCount === selectableCheckboxes.length;
                        quoteSelectAllInput.indeterminate = selectedCount > 0 && selectedCount < selectableCheckboxes.length;
                    }
                    refreshQuoteAnnulButton();
                    return;
                }

                const pageSizeSelect = event.target.closest('[data-cxc-page-size]');
                if (!pageSizeSelect) return;
                const params = new URLSearchParams(cxcQuery);
                params.set('perPage', pageSizeSelect.value);
                params.delete('quotes_page');
                params.delete('services_page');
                const activeTab = document.querySelector('.tab-button.active')?.dataset.tab || 'cotizaciones';
                loadCxcResults(`${window.location.pathname}?${params.toString()}`, activeTab);
            });
            refreshQuoteAnnulButton();

            document.addEventListener('click', function (event) {
                const link = event.target.closest('[data-cxc-pagination] nav a[href]');
                if (!link || link.getAttribute('href') === 'javascript:;') return;
                event.preventDefault();
                event.stopImmediatePropagation();
                const tab = link.closest('#quote-results') ? 'cotizaciones' : 'servicios';
                loadCxcResults(link.href, tab);
            }, true);

            let quoteAnnulPreviousOverflow = null;
            function closeQuoteAnnulModal() {
                const annulModal = document.getElementById('quote-annul-modal');
                annulModal?.classList.remove('visible');
                if (quoteAnnulPreviousOverflow !== null) {
                    document.documentElement.style.overflow = quoteAnnulPreviousOverflow.html;
                    document.body.style.overflow = quoteAnnulPreviousOverflow.body;
                    quoteAnnulPreviousOverflow = null;
                }
            }

            document.addEventListener('click', function (event) {
                const openButton = event.target.closest('#quote-annul-open');
                const closeButton = event.target.closest('[data-quote-annul-close]');
                const annulModal = document.getElementById('quote-annul-modal');

                if (openButton) {
                    const selectedQuotes = Array.from(document.querySelectorAll('#quote-results .quote-row-select:checked:not(:disabled)'));
                    if (selectedQuotes.length === 0 || !annulModal) return;

                    const hiddenInputs = document.getElementById('quote-annul-hidden-ids');
                    const selectedNumbers = selectedQuotes.map((checkbox) => checkbox.value);
                    if (hiddenInputs) {
                        hiddenInputs.replaceChildren(...selectedNumbers.map((quoteId) => {
                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = 'quotation_ids[]';
                            input.value = quoteId;
                            return input;
                        }));
                    }
                    const quoteSummary = document.getElementById('quote-annul-summary');
                    if (quoteSummary) {
                        quoteSummary.textContent = selectedNumbers.length === 1
                            ? `Vas a anular "${selectedNumbers[0]}". Si tiene pagos, también se revertirán sus movimientos bancarios.`
                            : `Vas a anular ${selectedNumbers.length} cotizaciones (${selectedNumbers.join(', ')}). Si tienen pagos, también se revertirán sus movimientos bancarios.`;
                    }
                    if (quoteAnnulPreviousOverflow === null) {
                        quoteAnnulPreviousOverflow = {
                            html: document.documentElement.style.overflow,
                            body: document.body.style.overflow
                        };
                    }
                    document.documentElement.style.overflow = 'hidden';
                    document.body.style.overflow = 'hidden';
                    annulModal.classList.add('visible');
                    return;
                }

                if (closeButton) {
                    closeQuoteAnnulModal();
                }
            });

            function navigateToFilteredPage(tab, pageName, filters, options = {}) {
                const params = new URLSearchParams(cxcQuery);
                const url = new URL(window.location.href);
                url.searchParams.set('tab', tab);
                filters.forEach(({ name, value, emptyValue = null }) => {
                    const normalizedValue = String(value ?? '').trim();
                    if (normalizedValue !== '') {
                        params.set(name, normalizedValue);
                    } else if (emptyValue !== null) {
                        params.set(name, emptyValue);
                    } else {
                        params.delete(name);
                    }
                });
                params.set('tab', tab);
                params.delete(pageName);
                loadCxcResults(`${url.pathname}?${params.toString()}`, tab, options);
            }

            function submitQuoteFilters(options = {}) {
                ['quote_q', 'quote_group', 'quote_currency', 'quote_month', 'quote_year', 'quote_document', 'quote_expiration_date'].forEach((name) => cxcQuery.delete(name));
                const filters = [
                    { name: 'quote_client', value: document.getElementById('quote-filter-client')?.value },
                    { name: 'quote_number', value: document.getElementById('quote-filter-number')?.value },
                    { name: 'quote_payment_format', value: document.getElementById('quote-filter-payment-format')?.value },
                    { name: 'quote_validity', value: document.getElementById('quote-filter-validity')?.value },
                    { name: 'quote_service', value: document.getElementById('quote-filter-service')?.value },
                    { name: 'quote_status', value: document.getElementById('quote-filter-status')?.value, emptyValue: '' },
                    { name: 'quote_date', value: document.getElementById('quote-filter-date')?.value }
                ];
                navigateToFilteredPage('cotizaciones', 'quotes_page', filters, options);
            }
            refreshQuoteExportUrl();

            const serviceSearchInput = document.getElementById('service-filter-client');
            const serviceDocumentInput = document.getElementById('service-filter-document');
            const serviceDateInput = document.getElementById('service-filter-date');
            const serviceExpirationDateInput = document.getElementById('service-filter-expiration-date');
            const serviceMonthSummary = document.querySelector('#service-month-summary span');
            const serviceMonthInputs = document.querySelectorAll('#service-month-picker input[type="checkbox"]');
            const serviceMonthSelectAll = document.getElementById('service-month-select-all');
            const serviceSubtitle = document.getElementById('service-subtitle');
            const serviceInlineMessage = document.getElementById('service-inline-message');
            const servicePaymentModal = document.getElementById('service-payment-modal');
            const servicePaymentForm = document.getElementById('service-payment-form');
            const openServicePayment = document.getElementById('open-service-payment');
            const serviceSelectionError = document.getElementById('service-selection-error');
            const selectAllServices = document.getElementById('select-all-services');
            const paymentClient = document.getElementById('payment-client');
            const paymentOriginal = document.getElementById('payment-original');
            const paymentAmount = document.getElementById('payment-amount');
            const paymentAmountCurrency = document.getElementById('payment-amount-currency');
            const paymentBalance = document.getElementById('payment-balance');
            const paymentCurrencyExtra = document.getElementById('payment-currency-extra');
            const paymentCurrencyModeInputs = document.querySelectorAll('input[name="payment_currency_mode"]');
            const paymentCurrencySelect = document.getElementById('payment-currency');
            const paymentExchangeRateInput = document.getElementById('payment-exchange-rate');
            const paymentConvertedAmountInput = document.getElementById('payment-converted-amount');
            const paymentConvertedTotalInput = document.getElementById('payment-converted-total');
            const partialPaymentField = document.getElementById('partial-payment-field');
            const selectedServiceInputs = document.getElementById('selected-service-inputs');
            const paymentDetailsUrlTemplate = @json(route('modules.cuentasporcobrar.detalle', ['id' => '__id__']));
            const paymentDetailsUpdateUrlTemplate = @json(route('modules.cuentasporcobrar.detalle.update', ['id' => '__id__']));
            let paymentDetailsRequestToken = 0;

            function setPaymentViewMode(isViewMode, isEditing = false) {
                if (!servicePaymentModal || !servicePaymentForm) return;
                servicePaymentModal.classList.toggle('is-payment-view-mode', isViewMode);
                servicePaymentModal.classList.toggle('is-payment-editing', isViewMode && isEditing);
                servicePaymentModal.dataset.paymentViewMode = isViewMode ? '1' : '0';
                servicePaymentModal.dataset.paymentEditing = isEditing ? '1' : '0';

                servicePaymentForm.querySelectorAll('input, select, textarea').forEach((field) => {
                    if (field.type === 'hidden' || field.name === '_token' || field.name === '_method') return;
                    if (!isViewMode) {
                        if (field.dataset.disabledForPaymentView === '1') {
                            field.disabled = field.dataset.disabledBeforePaymentView === '1';
                            delete field.dataset.disabledForPaymentView;
                            delete field.dataset.disabledBeforePaymentView;
                        }
                        if (field.dataset.readonlyForPaymentView === '1') {
                            field.readOnly = field.dataset.readonlyBeforePaymentView === '1';
                            delete field.dataset.readonlyForPaymentView;
                            delete field.dataset.readonlyBeforePaymentView;
                        }
                        if (field.dataset.requiredForPaymentView === '1') {
                            field.required = field.dataset.requiredBeforePaymentView === '1';
                            delete field.dataset.requiredForPaymentView;
                            delete field.dataset.requiredBeforePaymentView;
                        }
                        return;
                    }

                    if (field.dataset.disabledForPaymentView !== '1') {
                        field.dataset.disabledBeforePaymentView = field.disabled ? '1' : '0';
                    }
                    field.dataset.disabledForPaymentView = '1';
                    const canEdit = isEditing && ['payment-description', 'payment-comprobante', 'payment-remove-comprobante'].includes(field.id);
                    field.disabled = !canEdit;

                    if (field.id === 'payment-date' || field.id === 'payment-period-end') {
                        if (field.dataset.readonlyForPaymentView !== '1') {
                            field.dataset.readonlyBeforePaymentView = field.readOnly ? '1' : '0';
                        }
                        field.dataset.readonlyForPaymentView = '1';
                        field.readOnly = true;
                    }
                    if (field.id === 'payment-comprobante') {
                        if (field.dataset.requiredForPaymentView !== '1') {
                            field.dataset.requiredBeforePaymentView = field.required ? '1' : '0';
                        }
                        field.dataset.requiredForPaymentView = '1';
                        field.required = false;
                    }
                });
                if (paymentOriginal) {
                    paymentOriginal.closest('#payment-original-input-shell')?.classList.toggle('hidden', isViewMode);
                }
                document.getElementById('payment-original-label')?.classList.toggle('hidden', isViewMode);
                document.getElementById('payment-amount-view-displays')?.classList.toggle('hidden', !isViewMode);
                document.getElementById('payment-period-end-help')?.classList.toggle('hidden', isViewMode && !isEditing);

                const editButton = document.getElementById('payment-view-edit-btn');
                const cancelEditButton = document.getElementById('payment-view-cancel-edit-btn');
                const submitButton = document.getElementById('payment-submit-btn');
                if (editButton) editButton.classList.toggle('hidden', !isViewMode || isEditing);
                if (cancelEditButton) cancelEditButton.classList.toggle('hidden', !isViewMode || !isEditing);
                if (submitButton) {
                    submitButton.classList.toggle('hidden', isViewMode && !isEditing);
                    if (isViewMode) submitButton.textContent = isEditing ? 'Guardar cambios' : 'Registrar cobro';
                }
            }

            async function loadPaymentDetails(cxcId, requestToken = ++paymentDetailsRequestToken, groupedCxcIds = []) {
                const detailsUrl = new URL(paymentDetailsUrlTemplate.replace('__id__', encodeURIComponent(cxcId)), window.location.origin);
                const paymentCxcIds = [...new Set([cxcId, ...groupedCxcIds].map(String).filter(Boolean))];
                if (paymentCxcIds.length > 1) {
                    detailsUrl.searchParams.set('cxc_ids', paymentCxcIds.join(','));
                }
                const response = await fetch(detailsUrl.toString(), { headers: { Accept: 'application/json' } });
                const result = await response.json();
                if (!response.ok || !result.success || !result.data) {
                    throw new Error(result.message || 'No se pudieron cargar los datos del pago.');
                }
                if (requestToken !== paymentDetailsRequestToken) return false;

                const details = result.data;
                if (details.idcuentasPorCobrar && servicePaymentModal) {
                    servicePaymentModal.dataset.paymentCxcId = String(details.idcuentasPorCobrar);
                    servicePaymentModal.dataset.paymentUpdateUrl = paymentDetailsUpdateUrlTemplate.replace(
                        '__id__',
                        encodeURIComponent(details.idcuentasPorCobrar)
                    );
                }
                const dateInput = document.getElementById('payment-date');
                const periodInput = document.getElementById('payment-period-end');
                const referenceInput = document.getElementById('payment-reference');
                const bankSelect = document.getElementById('payment-bank');
                const methodSelect = document.getElementById('payment-method');
                const descriptionInput = servicePaymentForm?.querySelector('#payment-description');
                const proofInput = document.getElementById('payment-comprobante');
                const proofName = document.getElementById('payment-comprobante-name');
                const existingProof = document.getElementById('payment-existing-proof');
                const removeProofInput = document.getElementById('payment-remove-comprobante');
                const removeProofButton = document.getElementById('payment-clear-comprobante');
                const withholdingReceiptSection = document.getElementById('payment-withholding-receipt-view');
                const withholdingReceiptLink = document.getElementById('payment-withholding-receipt-link');
                const withholdingReceiptMissing = document.getElementById('payment-withholding-receipt-missing');

                if (paymentClient) paymentClient.value = details.cliente_nombre || '';
                if (dateInput) dateInput.value = (details.fechaPago || '').slice(0, 10);
                if (periodInput) periodInput.value = (details.fechaFin || '').slice(0, 10);
                if (referenceInput) referenceInput.value = details.docReferencia || '-';
                if (bankSelect) bankSelect.value = details.entidadBancaria_identidadBancaria
                    ? String(details.entidadBancaria_identidadBancaria)
                    : '';
                if (methodSelect) methodSelect.value = details.formaPago_idformaPago
                    ? String(details.formaPago_idformaPago)
                    : '';
                if (descriptionInput) descriptionInput.value = details.detalle_descripcion || '';
                withholdingReceiptSection?.classList.toggle('hidden', !details.withholding_applies);
                if (withholdingReceiptSection && details.withholding_applies) {
                    const type = details.withholding_type || 'detracción';
                    const title = document.getElementById('payment-withholding-receipt-title');
                    const dateLabel = document.getElementById('payment-withholding-date-label');
                    const amountLabel = document.getElementById('payment-withholding-amount-label');
                    const date = document.getElementById('payment-withholding-date');
                    const amount = document.getElementById('payment-withholding-amount');
                    const comment = document.getElementById('payment-withholding-comment');
                    if (title) title.textContent = `Detalle de ${type}:`;
                    if (dateLabel) dateLabel.textContent = `Fecha de pago de ${type}`;
                    if (amountLabel) amountLabel.textContent = `Monto de ${type}`;
                    if (date) date.value = details.withholding_fecha || '—';
                    if (amount) amount.value = details.withholding_monto_display || '—';
                    if (comment) comment.value = details.withholding_comment || '—';
                    if (withholdingReceiptLink) {
                        withholdingReceiptLink.dataset.receiptUrl = details.withholding_comprobante_url || '';
                        withholdingReceiptLink.dataset.missingMessage = `Sin recibo de ${type}`;
                        withholdingReceiptLink.dataset.previewTitle = `Recibo de ${type}`;
                        withholdingReceiptLink.textContent = `Ver recibo de ${type}`;
                    }
                    if (withholdingReceiptMissing) {
                        withholdingReceiptMissing.textContent = `Sin recibo de ${type}`;
                        withholdingReceiptMissing.classList.add('hidden');
                    }
                }
                const paymentCurrencySymbol = normalizeCurrencySymbol(details.moneda_simbolo, details.moneda_idmoneda);
                if (paymentOriginal) {
                    paymentOriginal.value = Number(details.montoOriginal || 0).toFixed(2);
                    if (paymentOriginalCurrency) paymentOriginalCurrency.textContent = paymentCurrencySymbol;
                    if (paymentAmountCurrency) paymentAmountCurrency.textContent = paymentCurrencySymbol;
                }
                const paymentOriginalDisplay = document.getElementById('payment-original-display');
                if (paymentOriginalDisplay) {
                    const displayAmount = servicePaymentModal?.dataset.paymentDisplayAmount
                        || `${paymentCurrencySymbol} ${Number(details.montoOriginal || 0).toFixed(2)}`;
                    paymentOriginalDisplay.value = displayAmount;
                }
                const foreignPaymentAmountDisplay = document.getElementById('payment-foreign-amount-display');
                const foreignPaymentAmount = document.getElementById('payment-foreign-amount');
                const hasForeignPaymentAmount = Boolean(details.mostrar_monto_cancelado);
                const paymentAmountViewDisplays = document.getElementById('payment-amount-view-displays');
                paymentAmountViewDisplays?.classList.toggle('grid-cols-2', hasForeignPaymentAmount);
                paymentAmountViewDisplays?.classList.toggle('grid-cols-1', !hasForeignPaymentAmount);
                foreignPaymentAmountDisplay?.classList.toggle('hidden', !hasForeignPaymentAmount);
                if (foreignPaymentAmount) {
                    foreignPaymentAmount.value = hasForeignPaymentAmount
                        ? `${normalizeCurrencySymbol(details.monto_cancelado_simbolo, details.payment_currency_id)} ${Number(details.monto_cancelado || 0).toFixed(2)}`
                        : '';
                }
                if (proofInput) proofInput.value = '';
                if (proofName) proofName.textContent = '';
                if (removeProofInput) removeProofInput.value = '0';
                if (removeProofButton) removeProofButton.classList.add('hidden');
                if (existingProof) {
                    existingProof.replaceChildren();
                    if (details.comprobante_url) {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.dataset.openDocumentPreview = '1';
                        button.dataset.receiptUrl = details.comprobante_url;
                        button.dataset.previewTitle = 'Comprobante de pago';
                        button.className = 'erp-btn-secondary';
                        button.textContent = 'Ver comprobante de pago adjunto';
                        existingProof.append(button);
                        existingProof.classList.remove('hidden');
                    } else {
                        existingProof.textContent = 'No hay comprobante de pago adjunto.';
                        existingProof.classList.remove('hidden');
                    }
                }

                return true;
            }

            function getCurrencySymbolFromText(valueText = '') {
                const text = String(valueText || '').trim();
                if (text.includes('US$') || text.includes('$')) return '$';
                if (text.includes('€')) return '€';
                if (text === 'S' || text.includes('S/') || text.includes('soles') || text.includes('sol')) return 'S/';
                return '';
            }

            function normalizeCurrencySymbol(symbol = '', monedaId = '') {
                const value = String(symbol || '').trim();
                return getCurrencySymbolFromText(value)
                    || value
                    || getCurrencySymbolFromMonedaId(monedaId)
                    || 'S/';
            }

            function getCurrencySymbolFromMonedaId(monedaId) {
                const value = String(monedaId ?? '').trim();
                if (value !== '') {
                    const parsed = Number(value);
                    if (!Number.isNaN(parsed)) {
                        if (parsed === 1) return 'S/';
                        if (parsed === 2) return '$';
                    }
                }

                const currencySelect = document.getElementById('payment-currency');
                if (!currencySelect) return '';
                const option = Array.from(currencySelect.options).find((item) => String(item.value) === String(monedaId));
                if (!option) return '';
                return option.dataset.symbol || '';
            }

            function resolveCurrencySymbol(amountDisplay = '', monedaId = '') {
                const fromText = getCurrencySymbolFromText(amountDisplay || '');
                if (fromText) return fromText;
                if (monedaId !== '' && monedaId !== undefined && monedaId !== null) {
                    const fromId = getCurrencySymbolFromMonedaId(monedaId);
                    if (fromId) return fromId;
                }
                return 'S/';
            }

            function formatMoney(value, symbol = paymentOriginalCurrency?.textContent || 'S/') {
                const numericValue = Number(value || 0);
                if (!symbol) {
                    return numericValue.toFixed(2);
                }
                return `${symbol} ${numericValue.toFixed(2)}`;
            }

            function updateBalanceAndCreditDisplay(totalAmount, paidAmount, balanceId, creditId, symbol, creditThreshold = totalAmount, deductionAmount = 0) {
                const total = Math.max(Number(totalAmount) || 0, 0);
                const paid = Math.max(Number(paidAmount) || 0, 0);
                const deduction = Math.max(Number(deductionAmount) || 0, 0);
                const netDue = Math.max(Number(creditThreshold) || (total - deduction), 0);
                const balance = document.getElementById(balanceId);
                const credit = document.getElementById(creditId);
                const paymentModeName = balanceId === 'payment-balance' ? 'modo_pago' : 'factura_modo_pago';
                const modeContainer = balanceId === 'payment-balance' ? servicePaymentModal : facturaModal;
                const isPartialMode = modeContainer?.querySelector(`input[name="${paymentModeName}"][value="parcial"]`)?.checked;

                let remaining = 0;
                let creditAmount = 0;

                if (deduction > 0) {
                    if (paid + 0.005 < netDue) {
                        // Pagó menos que el monto neto (ej. pagó 500 de 905 total / 796 neto)
                        remaining = Math.max(total - paid, 0);
                        creditAmount = 0;
                    } else if (paid + 0.005 < total) {
                        // Pagó el neto (o entre neto y total, ej: pagó 796 o 900 de 905/1000):
                        // El saldo restante pendiente es la detracción / retención que queda por pagar
                        remaining = deduction;
                        creditAmount = Math.max(paid - netDue, 0);
                    } else {
                        // Pagó el total completo o más (ej. 905 o 1100)
                        remaining = 0;
                        creditAmount = Math.max(paid - total, 0);
                    }
                } else {
                    remaining = Math.max(total - paid, 0);
                    creditAmount = Math.max(paid - total, 0);
                }

                if (balance) balance.textContent = formatMoney(remaining, symbol);
                if (credit) {
                    credit.textContent = `Nuevo saldo a favor: ${formatMoney(creditAmount, symbol)}`;
                    credit.classList.toggle('hidden', creditAmount <= 0.005 || !isPartialMode);
                }
                const totalCredit = document.getElementById(
                    creditId === 'payment-credit-summary'
                        ? 'payment-total-credit-summary'
                        : creditId === 'factura-payment-credit-summary'
                            ? 'factura-payment-total-credit-summary'
                            : ''
                );
                if (totalCredit) {
                    totalCredit.textContent = `Nuevo saldo a favor: ${formatMoney(creditAmount, symbol)}`;
                    totalCredit.classList.toggle('hidden', creditAmount <= 0.005 || isPartialMode);
                }
            }

            function getPaymentBaseAmount() {
                const raw = paymentOriginal?.value || 'S/ 0.00';
                const numericValue = Number(String(raw).replace(/[^0-9.-]+/g, '')) || 0;
                return Number.isFinite(numericValue) ? numericValue : 0;
            }

            function syncPaymentModeUI() {
                const selectedMode = servicePaymentModal?.querySelector('input[name="modo_pago"]:checked')?.value || 'total';
                const isPartial = selectedMode === 'parcial';
                partialPaymentField?.classList.toggle('hidden', !isPartial);
                if (paymentAmount) paymentAmount.required = isPartial;
                if (paymentBalance) {
                    const currentSymbol = paymentOriginalCurrency?.textContent || 'S/';
                    const grossAmount = Number(servicePaymentModal?.dataset.grossAmount || 0);
                    const cashDue = Number(servicePaymentModal?.dataset.expectedCashDue || getPaymentBaseAmount());
                    const deduction = Number(servicePaymentModal?.dataset.deductionAmount || 0);
                    const baseGross = grossAmount > 0 ? grossAmount : cashDue;
                    const isOtherCurrency = document.querySelector('#service-payment-modal input[name="payment_currency_mode"][value="other"]')?.checked;
                    if (isOtherCurrency) {
                        const exchangeRate = Number(paymentExchangeRateInput?.value || 0);
                        const debtCurrencyId = document.getElementById('payment-moneda-id')?.value || '';
                        const paymentCurrencyId = paymentCurrencySelect?.value || '';
                        const convertedGross = convertDebtAmountToPaymentCurrency(
                            baseGross,
                            exchangeRate,
                            debtCurrencyId,
                            paymentCurrencyId
                        );
                        const convertedCashDue = convertDebtAmountToPaymentCurrency(
                            cashDue,
                            exchangeRate,
                            debtCurrencyId,
                            paymentCurrencyId
                        );
                        if (convertedGross !== null && convertedCashDue !== null) {
                            const paymentSymbol = normalizeCurrencySymbol(
                                paymentCurrencySelect?.selectedOptions?.[0]?.dataset.symbol
                            ) || currentSymbol;
                            updateBalanceAndCreditDisplay(
                                convertedGross,
                                Number(paymentConvertedAmountInput?.value || 0),
                                'payment-balance',
                                'payment-credit-summary',
                                paymentSymbol,
                                convertedCashDue,
                                Math.max(0, convertedGross - convertedCashDue)
                            );
                            return;
                        }
                    }
                    if (isPartial) {
                        const paidValue = Number(paymentAmount?.value || 0);
                        updateBalanceAndCreditDisplay(
                            baseGross,
                            paidValue,
                            'payment-balance',
                            'payment-credit-summary',
                            currentSymbol,
                            cashDue,
                            deduction
                        );
                    } else {
                        updateBalanceAndCreditDisplay(
                            baseGross,
                            baseGross,
                            'payment-balance',
                            'payment-credit-summary',
                            currentSymbol,
                            cashDue,
                            deduction
                        );
                    }
                }
            }

            function showPartialPaymentWithAmount(amount = '') {
                const partialRadio = document.querySelector('#service-payment-modal input[name="modo_pago"][value="parcial"]');
                const totalRadio = document.querySelector('#service-payment-modal input[name="modo_pago"][value="total"]');
                if (partialRadio) partialRadio.checked = true;
                if (totalRadio) totalRadio.checked = false;
                partialPaymentField?.classList.remove('hidden');
                if (paymentAmount) {
                    paymentAmount.required = true;
                    if (amount !== '') paymentAmount.value = Number(amount).toFixed(2);
                }
                syncPaymentModeUI();
            }

            function getCurrencyOption(currencyId, select = paymentCurrencySelect) {
                return Array.from(select?.options || []).find((option) => String(option.value) === String(currencyId));
            }

            function syncConvertedTotalCurrencySymbol(select, symbolElement, defaultSymbol) {
                if (!symbolElement) return;
                const selectedSymbol = select?.selectedOptions?.[0]?.dataset.symbol;
                symbolElement.textContent = normalizeCurrencySymbol(selectedSymbol || defaultSymbol || '');
            }

            function isSolCurrencyOption(option) {
                return Boolean(option) && (
                    /^(s\/|s\/\.|s)$/i.test(String(option.dataset.symbol || '').trim())
                    || /\bsol(es)?\b|\bpen\b/i.test(option.textContent || '')
                );
            }

            function convertPaymentAmountToDebtCurrency(amount, exchangeRate, debtCurrencyId, paymentCurrencyId, select) {
                if (
                    !Number.isFinite(amount)
                    || !Number.isFinite(exchangeRate)
                    || exchangeRate <= 0
                    || !debtCurrencyId
                    || !paymentCurrencyId
                    || String(debtCurrencyId) === String(paymentCurrencyId)
                ) return null;

                const paymentCurrency = getCurrencyOption(paymentCurrencyId, select);
                if (!paymentCurrency) return null;
                const converted = isSolCurrencyOption(paymentCurrency)
                    ? amount / exchangeRate
                    : amount * exchangeRate;
                return Number(converted.toFixed(2));
            }

            function convertDebtAmountToPaymentCurrency(amount, exchangeRate, debtCurrencyId, paymentCurrencyId, select) {
                return convertPaymentAmountToDebtCurrency(
                    amount,
                    exchangeRate,
                    paymentCurrencyId,
                    debtCurrencyId,
                    select
                );
            }

            function amountsMatchAtCentPrecision(firstAmount, secondAmount) {
                return Number.isFinite(firstAmount)
                    && Number.isFinite(secondAmount)
                    && Math.round(firstAmount * 100) === Math.round(secondAmount * 100);
            }

            function updateConvertedPaymentAmount() {
                const isOtherCurrency = document.querySelector('input[name="payment_currency_mode"]:checked')?.value === 'other';
                const exchangeRate = Number(paymentExchangeRateInput?.value || 0);
                const receivedAmount = Number(paymentConvertedAmountInput?.value || 0);
                const baseAmount = getPaymentBaseAmount();
                const debtCurrencyId = document.getElementById('payment-moneda-id')?.value || '';
                const paymentCurrencyId = paymentCurrencySelect?.value || '';
                syncConvertedTotalCurrencySymbol(
                    isOtherCurrency ? paymentCurrencySelect : null,
                    document.getElementById('payment-converted-total-symbol'),
                    paymentOriginalCurrency?.textContent || 'S/'
                );
                const totalRadio = document.querySelector('#service-payment-modal input[name="modo_pago"][value="total"]');
                const partialRadio = document.querySelector('#service-payment-modal input[name="modo_pago"][value="parcial"]');

                if (!isOtherCurrency) {
                    paymentConvertedTotalInput.value = baseAmount.toFixed(2);
                    if (paymentAmount) paymentAmount.value = baseAmount.toFixed(2);
                    if (paymentBalance) paymentBalance.textContent = formatMoney(0, paymentOriginalCurrency?.textContent || 'S/');
                    if (totalRadio) totalRadio.checked = true;
                    if (partialRadio) partialRadio.checked = false;
                    syncPaymentModeUI();
                    return;
                }

                const convertedTotal = convertDebtAmountToPaymentCurrency(
                    baseAmount,
                    exchangeRate,
                    debtCurrencyId,
                    paymentCurrencyId
                );
                const convertedReceivedAmount = convertPaymentAmountToDebtCurrency(
                    receivedAmount,
                    exchangeRate,
                    debtCurrencyId,
                    paymentCurrencyId
                );
                if (convertedTotal === null || convertedReceivedAmount === null) {
                    paymentConvertedTotalInput.value = '';
                    if (paymentAmount) paymentAmount.value = '';
                    if (partialRadio) partialRadio.checked = true;
                    if (totalRadio) totalRadio.checked = false;
                    syncPaymentModeUI();
                    return;
                }
                paymentConvertedTotalInput.value = convertedTotal.toFixed(2);

                const isTotalPayment = receivedAmount > 0
                    && amountsMatchAtCentPrecision(receivedAmount, convertedTotal);
                if (partialRadio) partialRadio.checked = !isTotalPayment;
                if (totalRadio) totalRadio.checked = isTotalPayment;
                if (paymentAmount) {
                    paymentAmount.value = receivedAmount > 0
                        ? (isTotalPayment ? baseAmount : convertedReceivedAmount).toFixed(2)
                        : '';
                }
                syncPaymentModeUI();
            }

            function clearPaymentCurrencyConversion() {
                if (paymentCurrencySelect) paymentCurrencySelect.value = '';
                if (paymentExchangeRateInput) paymentExchangeRateInput.value = '';
                if (paymentConvertedAmountInput) paymentConvertedAmountInput.value = '';
                if (paymentConvertedTotalInput) paymentConvertedTotalInput.value = '';
                if (paymentCurrencySelect) paymentCurrencySelect.required = false;
                if (paymentExchangeRateInput) paymentExchangeRateInput.required = false;
                if (paymentConvertedAmountInput) paymentConvertedAmountInput.required = false;
                const paymentMonedaIdInput = document.getElementById('payment-moneda-id');
                if (paymentMonedaIdInput) {
                    paymentMonedaIdInput.value = paymentMonedaIdInput.dataset.default
                        || paymentCurrencySelect?.dataset.defaultCurrency
                        || paymentMonedaIdInput.value
                        || '';
                }
                syncPaymentCurrencyHidden();
            }

            function selectedServiceRows() {
                return Array.from(document.querySelectorAll('.service-selector:checked'))
                    .map((checkbox) => checkbox.closest('tr'))
                    .filter(Boolean);
            }

            function setPaymentType(tipoCobroId) {
                const typeSelect = document.getElementById('payment-type');
                if (typeSelect && tipoCobroId) typeSelect.value = String(tipoCobroId);
            }

            function resolveSelectedCurrencyId() {
                const selected = selectedServiceRows();
                const row = selected.find((entry) => entry?.dataset?.monedaId || entry?.dataset?.serviceMonedaId) || selected[0];
                if (!row) return '';
                return String(row.dataset?.monedaId || row.dataset?.serviceMonedaId || '');
            }

            function refreshServiceSelection() {
                const selected = selectedServiceRows();
                if (openServicePayment) {
                    openServicePayment.disabled = selected.length === 0;
                }
                if (selectAllServices) {
                    const visibleSelectors = Array.from(document.querySelectorAll('.service-selector'))
                        .filter((checkbox) => checkbox.closest('tr')?.style.display !== 'none');
                    selectAllServices.checked = visibleSelectors.length > 0 && visibleSelectors.every((checkbox) => checkbox.checked);
                    selectAllServices.indeterminate = visibleSelectors.some((checkbox) => checkbox.checked) && !selectAllServices.checked;
                }
            }

            function openServicePaymentModal() {
                const selected = selectedServiceRows();
                if (!servicePaymentModal || selected.length === 0) {
                    return;
                }
                const pendingFlag = document.getElementById('payment-complete-withholding');
                if (pendingFlag) pendingFlag.value = '0';
                setWithholdingCompletionModalMode(false);
                const clients = [...new Set(selected.map((row) => row.dataset.serviceClient))];
                if (clients.length !== 1) {
                    if (serviceSelectionError) {
                        serviceSelectionError.textContent = 'Selecciona servicios de un solo cliente. No de distintos clientes.';
                    }
                    serviceSelectionError?.classList.remove('hidden');
                    return;
                }
                if (serviceSelectionError) {
                    serviceSelectionError.textContent = 'Selecciona servicios de un solo cliente. No de distintos clientes.';
                    serviceSelectionError.classList.add('hidden');
                }
                const total = selected.reduce((sum, row) => sum + Number(row.dataset.serviceAmount || 0), 0);
                const firstAmountDisplay = selected[0]?.dataset?.serviceAmountDisplay || selected[0]?.dataset?.amountDisplay || '';
                const detectedSymbol = getCurrencySymbolFromText(firstAmountDisplay || selected[0]?.dataset?.serviceClientAmountDisplay || '');
                const creditCheckbox = document.getElementById('payment-use-credit');
                if (creditCheckbox) creditCheckbox.checked = false;
                const bankCreditAmount = document.getElementById('payment-credit-amount');
                if (bankCreditAmount) bankCreditAmount.value = '';
                const creditSummary = document.getElementById('payment-credit-summary');
                creditSummary?.classList.add('hidden');
                if (creditSummary) creditSummary.textContent = '';
                const selectedTypes = [...new Set(selected.map((row) => row.dataset.tipoCobroId).filter(Boolean))];
                setPaymentType(selectedTypes.length === 1 ? selectedTypes[0] : '');
                if (paymentClient) paymentClient.value = selected[0].dataset.serviceClientName || '-';
                if (paymentOriginal) paymentOriginal.value = total.toFixed(2);
                if (paymentOriginalCurrency) paymentOriginalCurrency.textContent = detectedSymbol || 'S/';
                if (paymentAmountCurrency) paymentAmountCurrency.textContent = detectedSymbol || 'S/';
                syncConvertedTotalCurrencySymbol(
                    null,
                    document.getElementById('payment-converted-total-symbol'),
                    detectedSymbol || 'S/'
                );
                if (paymentAdvanceMonths) paymentAdvanceMonths.value = '';
                if (paymentAdvanceMonthsCount) {
                    paymentAdvanceMonthsCount.value = '';
                    paymentAdvanceMonthsCount.disabled = true;
                    paymentAdvanceMonthsCount.required = false;
                }
                paymentAdvanceFields?.classList.add('hidden');
                if (paymentAdvanceToggle) {
                    paymentAdvanceToggle.textContent = 'Pagar adelantado';
                    paymentAdvanceToggle.setAttribute('aria-expanded', 'false');
                    paymentAdvanceToggle.disabled = false;
                }
                paymentAmountWasEdited = false;
                paymentAdvanceSummary?.classList.add('hidden');
                if (paymentBalance) paymentBalance.textContent = formatMoney(total, detectedSymbol);
                const totalPaymentRadio = document.querySelector('#service-payment-modal input[name="modo_pago"][value="total"]');
                if (totalPaymentRadio) totalPaymentRadio.checked = true;
                const sameCurrencyMode = document.querySelector('#service-payment-modal input[name="payment_currency_mode"][value="same"]');
                if (sameCurrencyMode) sameCurrencyMode.checked = true;
                paymentCurrencyExtra?.classList.add('hidden');
                partialPaymentField?.classList.add('hidden');
                if (paymentAmount) {
                    paymentAmount.value = total.toFixed(2);
                    paymentAmount.required = false;
                }
                const withholdingDecision = document.getElementById('payment-withholding-decision');
                if (withholdingDecision) withholdingDecision.value = '';
                syncPaymentModeUI();
                if (paymentConvertedTotalInput) paymentConvertedTotalInput.value = total.toFixed(2);
                if (paymentExchangeRateInput) paymentExchangeRateInput.value = '';
                if (paymentConvertedAmountInput) paymentConvertedAmountInput.value = '';
                if (selectedServiceInputs) {
                    selectedServiceInputs.replaceChildren(...selected.map((row) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'servicio_ids[]';
                        input.value = row.dataset.serviceId;
                        return input;
                    }));
                }
                checkAndRenderVencidosButton(
                    clients[0],
                    '',
                    total,
                    detectedSymbol,
                    'pay',
                    selected.map((row) => row.dataset.serviceId).filter(Boolean),
                    selected[0]?.dataset.serviceClientName || '',
                    resolveSelectedCurrencyId()
                );
                const paymentMonedaIdInput = document.getElementById('payment-moneda-id');
                if (paymentMonedaIdInput) paymentMonedaIdInput.value = resolveSelectedCurrencyId();
                if (paymentCurrencySelect) {
                    paymentCurrencySelect.dataset.defaultCurrency = resolveSelectedCurrencyId();
                    syncPaymentCurrencyHidden();
                }
                servicePaymentModal.classList.add('visible');
                document.body.style.overflow = 'hidden';
            }

            function syncPaymentCurrencyHidden() {
                const hiddenField = document.getElementById('payment-moneda-id');
                const uiSelect = document.getElementById('payment-currency');
                const isOtherCurrency = document.querySelector('input[name="payment_currency_mode"]:checked')?.value === 'other';
                if (!hiddenField) return;

                const fallbackCurrencyId = hiddenField.value || uiSelect?.dataset?.defaultCurrency || '';
                hiddenField.value = fallbackCurrencyId || '';
                if (uiSelect) {
                    Array.from(uiSelect.options).forEach((option) => {
                        const isDebtCurrency = Boolean(option.value) && option.value === String(fallbackCurrencyId);
                        option.disabled = isDebtCurrency;
                        option.hidden = isDebtCurrency;
                    });
                    if (!isOtherCurrency || uiSelect.value === String(fallbackCurrencyId)) {
                        uiSelect.value = '';
                    }
                }
            }

            function resetServicePaymentForm() {
                setPaymentViewMode(false);
                if (servicePaymentForm) servicePaymentForm.reset();
                servicePaymentModal?.removeAttribute('data-credit-payment-mode');
                const fixedPaymentMethod = document.getElementById('payment-fixed-method');
                if (fixedPaymentMethod) {
                    fixedPaymentMethod.value = '';
                    fixedPaymentMethod.disabled = true;
                }
                const paymentMethodSelect = document.getElementById('payment-method');
                if (paymentMethodSelect) {
                    paymentMethodSelect.disabled = false;
                    paymentMethodSelect.required = true;
                }
                [
                    'payment-method-field',
                    'payment-bank-field',
                    'payment-currency-settings',
                    'payment-mode-field',
                    'btn-payment-advance-toggle',
                ].forEach((id) => document.getElementById(id)?.classList.remove('hidden'));
                servicePaymentModal?.querySelectorAll('[data-hidden-for-credit-payment="1"]').forEach((field) => {
                    field.classList.toggle('hidden', field.dataset.creditPaymentWasHidden === '1');
                    delete field.dataset.hiddenForCreditPayment;
                    delete field.dataset.creditPaymentWasHidden;
                });
                if (paymentOriginal) paymentOriginal.readOnly = false;
                document.getElementById('payment-period-end')?.closest('label')?.classList.remove('hidden');
                document.getElementById('payment-credit-plan-options')?.classList.add('hidden');
                document.getElementById('payment-credit-plan-list')?.replaceChildren();
                const pendingFlag = document.getElementById('payment-complete-withholding');
                if (pendingFlag) pendingFlag.value = '0';
                setWithholdingCompletionModalMode(false);
                const paymentSubmitButton = servicePaymentForm?.querySelector('button[type="submit"]');
                if (paymentSubmitButton) {
                    paymentSubmitButton.disabled = false;
                    paymentSubmitButton.textContent = 'Registrar cobro';
                }
                if (paymentClient) paymentClient.value = '';
                if (paymentOriginal) paymentOriginal.value = '';
                if (paymentOriginalCurrency) paymentOriginalCurrency.textContent = 'S/';
                if (paymentAmountCurrency) paymentAmountCurrency.textContent = 'S/';
                if (paymentAdvanceMonths) paymentAdvanceMonths.value = '';
                if (paymentAdvanceMonthsCount) {
                    paymentAdvanceMonthsCount.value = '';
                    paymentAdvanceMonthsCount.disabled = true;
                    paymentAdvanceMonthsCount.required = false;
                    paymentAdvanceMonthsCount.setCustomValidity('');
                }
                paymentAdvanceFields?.classList.add('hidden');
                if (paymentAdvanceToggle) {
                    paymentAdvanceToggle.textContent = 'Pagar adelantado';
                    paymentAdvanceToggle.setAttribute('aria-expanded', 'false');
                    paymentAdvanceToggle.disabled = false;
                }
                paymentAdvanceSummary?.classList.add('hidden');
                paymentAmountWasEdited = false;
                if (paymentBalance) paymentBalance.textContent = 'S/ 0.00';
                if (paymentAmount) {
                    paymentAmount.value = '';
                    paymentAmount.max = '';
                    paymentAmount.required = false;
                }
                if (paymentExchangeRateInput) paymentExchangeRateInput.value = '';
                if (paymentConvertedAmountInput) paymentConvertedAmountInput.value = '';
                if (paymentConvertedTotalInput) paymentConvertedTotalInput.value = '';
                if (paymentCurrencyExtra) paymentCurrencyExtra.classList.add('hidden');
                if (paymentCurrencySelect) paymentCurrencySelect.value = '';
                if (paymentCurrencySelect) paymentCurrencySelect.required = false;
                if (paymentExchangeRateInput) paymentExchangeRateInput.required = false;
                if (paymentConvertedAmountInput) paymentConvertedAmountInput.required = false;
                const paymentMonedaIdInput = document.getElementById('payment-moneda-id');
                if (paymentMonedaIdInput) paymentMonedaIdInput.value = '';
                if (partialPaymentField) partialPaymentField.classList.add('hidden');
                const withholdingDecision = document.getElementById('payment-withholding-decision');
                if (withholdingDecision) withholdingDecision.value = '';
                const sameCurrencyMode = document.querySelector('#service-payment-modal input[name="payment_currency_mode"][value="same"]');
                const totalPaymentRadio = document.querySelector('#service-payment-modal input[name="modo_pago"][value="total"]');
                if (sameCurrencyMode) sameCurrencyMode.checked = true;
                if (totalPaymentRadio) totalPaymentRadio.checked = true;
                if (selectedServiceInputs) selectedServiceInputs.replaceChildren();
                const paymentReference = document.getElementById('payment-reference');
                if (paymentReference) paymentReference.value = '';
                const paymentCxcIdInput = document.getElementById('payment-cxc-id');
                if (paymentCxcIdInput) paymentCxcIdInput.value = '';
                const facturaServiceId = document.getElementById('factura-service-id');
                if (facturaServiceId) facturaServiceId.value = '';
                const paymentComprobanteInput = document.getElementById('payment-comprobante');
                const paymentComprobanteName = document.getElementById('payment-comprobante-name');
                if (paymentComprobanteInput) paymentComprobanteInput.value = '';
                if (paymentComprobanteName) paymentComprobanteName.textContent = '';
                document.getElementById('payment-clear-comprobante')?.classList.add('hidden');
                const existingProof = document.getElementById('payment-existing-proof');
                if (existingProof) {
                    existingProof.replaceChildren();
                    existingProof.classList.add('hidden');
                }
                document.getElementById('payment-withholding-receipt-view')?.classList.add('hidden');
                document.getElementById('payment-withholding-receipt-missing')?.classList.add('hidden');
                const paymentDropzone = document.getElementById('payment-dropzone');
                if (paymentDropzone) paymentDropzone.classList.remove('is-invalid');
                const paymentDescription = servicePaymentForm?.querySelector('#payment-description');
                if (paymentDescription) paymentDescription.value = '';
            }

            function closeServicePaymentModal() {
                if (!servicePaymentModal) return;
                paymentDetailsRequestToken++;
                resetServicePaymentForm();
                servicePaymentModal.classList.remove('visible');
                servicePaymentModal.classList.add('hidden');
                document.body.style.overflow = '';
            }

            document.addEventListener('change', function (event) {
                if (event.target.matches('.service-selector')) {
                    if (serviceSelectionError) {
                        serviceSelectionError.textContent = 'Selecciona servicios de un solo cliente.';
                        serviceSelectionError.classList.add('hidden');
                    }
                    refreshServiceSelection();
                }
                if (
                    event.target.matches('input[name="modo_pago"]')
                    && servicePaymentModal?.contains(event.target)
                ) {
                    const isPartial = event.target.value === 'parcial' && event.target.checked;
                    partialPaymentField?.classList.toggle('hidden', !isPartial);
                    if (paymentAmount) paymentAmount.required = isPartial;
                    const gross = Number(servicePaymentModal?.dataset.grossAmount || 0);
                    const net = Number(servicePaymentModal?.dataset.expectedCashDue || getPaymentBaseAmount());
                    const deduction = Number(servicePaymentModal?.dataset.deductionAmount || 0);
                    const baseGross = gross > 0 ? gross : net;
                    if (event.target.value === 'total') {
                        const totalToSet = gross > 0 ? gross : net;
                        if (paymentAmount) paymentAmount.value = totalToSet.toFixed(2);
                        if (paymentOriginal) paymentOriginal.value = totalToSet.toFixed(2);
                        updateBalanceAndCreditDisplay(
                            baseGross,
                            totalToSet,
                            'payment-balance',
                            'payment-credit-summary',
                            paymentOriginalCurrency?.textContent || 'S/',
                            net,
                            deduction
                        );
                    } else if (event.target.value === 'parcial') {
                        if (paymentAmount) paymentAmount.value = net.toFixed(2);
                        if (paymentOriginal) paymentOriginal.value = net.toFixed(2);
                        updateBalanceAndCreditDisplay(
                            baseGross,
                            net,
                            'payment-balance',
                            'payment-credit-summary',
                            paymentOriginalCurrency?.textContent || 'S/',
                            net,
                            deduction
                        );
                    }
                    if (document.querySelector('input[name="payment_currency_mode"]:checked')?.value === 'other') {
                        updateConvertedPaymentAmount();
                    }
                }
                if (event.target.matches('input[name="payment_currency_mode"]')) {
                    const isOther = event.target.value === 'other' && event.target.checked;
                    paymentCurrencyExtra?.classList.toggle('hidden', !isOther);
                    if (!isOther) {
                        clearPaymentCurrencyConversion();
                        paymentAmount.value = getPaymentBaseAmount().toFixed(2);
                        paymentBalance.textContent = formatMoney(0, paymentOriginalCurrency?.textContent || 'S/');
                        syncPaymentModeUI();
                    } else {
                        updateConvertedPaymentAmount();
                    }
                }
                if (event.target === paymentExchangeRateInput || event.target === paymentConvertedAmountInput) {
                    updateConvertedPaymentAmount();
                }
                if (event.target === selectAllServices) {
                    const visibleSelectors = Array.from(document.querySelectorAll('.service-selector'))
                        .filter((checkbox) => checkbox.closest('tr')?.style.display !== 'none');
                    visibleSelectors.forEach((checkbox) => { checkbox.checked = selectAllServices.checked; });
                    refreshServiceSelection();
                }
            });

            openServicePayment?.addEventListener('click', openServicePaymentModal);
            document.addEventListener('click', function (event) {
                if (event.target.closest('[data-close-service-payment]')) closeServicePaymentModal();
            });
            paymentAmount?.addEventListener('input', function () {
                const paid = Math.max(Number(paymentAmount.value || 0), 0);
                const grossAmount = Number(servicePaymentModal?.dataset.grossAmount || 0);
                const cashDue = Number(servicePaymentModal?.dataset.expectedCashDue || getPaymentBaseAmount());
                const deduction = Number(servicePaymentModal?.dataset.deductionAmount || 0);
                const baseGross = grossAmount > 0 ? grossAmount : cashDue;
                const hasWithholding = grossAmount > 0 && Math.abs(grossAmount - cashDue) > 0.01;
                const fullThreshold = hasWithholding ? grossAmount : cashDue;
                const isPartial = paid + 0.01 < fullThreshold;

                const totalRadio = document.querySelector('#service-payment-modal input[name="modo_pago"][value="total"]');
                const partialRadio = document.querySelector('#service-payment-modal input[name="modo_pago"][value="parcial"]');
                if (totalRadio) totalRadio.checked = !isPartial;
                if (partialRadio) partialRadio.checked = isPartial;

                const currentSymbol = paymentOriginalCurrency?.textContent || 'S/';
                updateBalanceAndCreditDisplay(
                    baseGross,
                    paid,
                    'payment-balance',
                    'payment-credit-summary',
                    currentSymbol,
                    cashDue,
                    deduction
                );
            });
            paymentCurrencyModeInputs?.forEach(function (input) {
                input.addEventListener('change', function () {
                    const isOther = this.value === 'other' && this.checked;
                    paymentCurrencyExtra?.classList.toggle('hidden', !isOther);
                    if (paymentCurrencySelect) paymentCurrencySelect.required = isOther;
                    if (paymentExchangeRateInput) paymentExchangeRateInput.required = isOther;
                    if (paymentConvertedAmountInput) paymentConvertedAmountInput.required = isOther;
                    if (isOther) {
                        syncPaymentCurrencyHidden();
                        updateConvertedPaymentAmount();
                    } else {
                        clearPaymentCurrencyConversion();
                        if (paymentAmount) paymentAmount.value = getPaymentBaseAmount().toFixed(2);
                        if (paymentBalance) paymentBalance.textContent = formatMoney(0, paymentOriginalCurrency?.textContent || 'S/');
                    }
                });
            });
            paymentCurrencySelect?.addEventListener('change', function () {
                syncPaymentCurrencyHidden();
                updateConvertedPaymentAmount();
            });
            paymentExchangeRateInput?.addEventListener('input', updateConvertedPaymentAmount);
            paymentConvertedAmountInput?.addEventListener('input', updateConvertedPaymentAmount);

            let withholdingWarningConfirm;
            let quotationWithholdingSubmit = null;
            const quotationWithholdingProofModal = document.getElementById('quotation-withholding-proof-modal');
            const quotationWithholdingProofInput = document.getElementById('quotation-withholding-proof');
            const quotationWithholdingProofName = document.getElementById('quotation-withholding-proof-name');
            const quotationWithholdingProofBox = document.getElementById('quotation-withholding-proof-box');
            const quotationWithholdingProofClear = document.getElementById('quotation-withholding-proof-clear');

            function showWithholdingWarning(grossAmount, isRetention, decisionInput, onConfirm, currencySymbol = '', subject = 'periodo') {
                const modal = document.getElementById('withholding-warning-modal');
                const message = document.getElementById('withholding-warning-message');
                const payment = document.getElementById('withholding-warning-payment');
                const title = document.getElementById('withholding-warning-title');
                const noButton = modal?.querySelector('[data-withholding-answer="no"]');
                const yesButton = modal?.querySelector('[data-withholding-answer="yes"]');
                const cancelButton = modal?.querySelector('.erp-modal-body [data-close-withholding-warning]');
                const isQuotation = subject === 'cotización';
                const deductionAmount = Number(
                    onConfirm.getDeductionAmount?.()
                    ?? calculateDeduction(grossAmount, isRetention, isQuotation)
                );
                const deductionName = isRetention ? 'Retención' : 'Detracción';
                const symbol = currencySymbol || currentVencidosState.currencySymbol || 'S/';
                if (!modal || !message || !payment || !decisionInput) return;

                const enteredAmount = Number(onConfirm.getEnteredAmount() || 0);
                const netAmountDue = Number(
                    onConfirm.getNetAmount?.()
                    ?? calculateNetPayable(grossAmount, isRetention, isQuotation)
                );
                const grossAmountDue = Number(onConfirm.getGrossAmount?.() ?? grossAmount);
                const isGrossPayment = enteredAmount + 0.01 >= grossAmountDue;
                const isFullPaymentConfirmation = isGrossPayment;
                if (title) {
                    title.textContent = isFullPaymentConfirmation
                        ? 'Confirmar pago total'
                        : `Advertencia: ${deductionName}`;
                }
                message.replaceChildren(
                    document.createTextNode(isFullPaymentConfirmation
                        ? `¿El cliente está pagando el total de ${formatMoney(grossAmountDue, symbol)}?`
                        : `¿El cliente ya pagó la ${deductionName} de `)
                );
                if (!isFullPaymentConfirmation) {
                    const highlightedAmount = document.createElement('span');
                    highlightedAmount.className = 'font-bold text-danger';
                    highlightedAmount.textContent = formatMoney(deductionAmount, symbol);
                    message.append(highlightedAmount, document.createTextNode(subject === 'cotización' ? '?' : ' de este periodo?'));
                }
                const excessOverNet = Math.max(0, enteredAmount - netAmountDue);
                const pendingExplanation = isGrossPayment
                    ? ` Al elegir No, se registrará el pago bruto completo (${formatMoney(grossAmountDue, symbol)}) y quedará pendiente registrar la ${deductionName} de ${formatMoney(deductionAmount, symbol)}.`
                    : ` Al elegir No, se registrará el pago neto (${formatMoney(netAmountDue, symbol)}) y quedará pendiente registrar la ${deductionName}.${excessOverNet > 0.01 ? ` El excedente de ${formatMoney(excessOverNet, symbol)} quedará como saldo a favor.` : ''}`;
                if (isFullPaymentConfirmation) {
                    const pendingName = isRetention ? 'retención' : 'autodetracción';
                    payment.replaceChildren();
                    const yesDescription = document.createElement('div');
                    yesDescription.textContent = `Al elegir Sí: Se guardará el pago total y la ${pendingName} (${formatMoney(deductionAmount, symbol)}) quedará pendiente.`;
                    const noDescription = document.createElement('div');
                    noDescription.className = 'mt-2';
                    noDescription.textContent = 'Al elegir No: Regresar y no guardar.';
                    payment.append(yesDescription, noDescription);
                } else if (isQuotation) {
                    payment.replaceChildren();
                    const yesDescription = document.createElement('div');
                    yesDescription.textContent = 'Al elegir Sí: La cotización se aprobará y deberás adjuntar el comprobante de pago.';
                    const noDescription = document.createElement('div');
                    noDescription.className = 'mt-2';
                    noDescription.textContent = `Al elegir No: Se registrará solo el pago neto (${formatMoney(netAmountDue, symbol)}) y quedará pendiente registrar la ${deductionName.toLowerCase()} (${formatMoney(deductionAmount, symbol)}).`;
                    payment.append(yesDescription, noDescription);
                } else {
                    payment.replaceChildren();
                    const yesDescription = document.createElement('div');
                    yesDescription.textContent = `Al elegir Sí: Se registrará el pago y deberás adjuntar el comprobante de pago de la ${deductionName.toLowerCase()}.`;
                    const noDescription = document.createElement('div');
                    noDescription.className = 'mt-2';
                    noDescription.textContent = isGrossPayment
                        ? `Al elegir No: Se registrará el pago total (${formatMoney(grossAmountDue, symbol)}) y quedará pendiente registrar la ${deductionName.toLowerCase()} (${formatMoney(deductionAmount, symbol)}) quedará pendiente.`
                        : `Al elegir No: Se registrará solo el pago neto (${formatMoney(netAmountDue, symbol)}) y quedará pendiente registrar la ${deductionName.toLowerCase()} (${formatMoney(deductionAmount, symbol)}).${excessOverNet > 0.01 ? ` El excedente de ${formatMoney(excessOverNet, symbol)} quedará como saldo a favor.` : ''}`;
                    payment.append(yesDescription, noDescription);
                }
                cancelButton?.classList.toggle('hidden', isFullPaymentConfirmation);
                if (noButton) noButton.textContent = 'No';
                if (yesButton) yesButton.textContent = 'Sí';
                withholdingWarningConfirm = (answer) => {
                    const enteredAmount = Number(onConfirm.getEnteredAmount() || 0);
                    const netAmount = Number(onConfirm.getNetAmount?.() ?? calculateNetPayable(grossAmount, isRetention, isQuotation));
                    const grossAmountDue = Number(onConfirm.getGrossAmount?.() ?? grossAmount);
                    if (enteredAmount + 0.01 < netAmount) {
                        showServiceMessage(`El monto pagado debe ser al menos el neto calculado de ${formatMoney(netAmount, symbol)}.`);
                        return;
                    }

                    if (isFullPaymentConfirmation) {
                        if (answer !== 'yes') {
                            modal.classList.add('hidden');
                            modal.classList.remove('visible');
                            document.body.style.overflow = '';
                            decisionInput.value = '';
                            withholdingWarningConfirm = null;
                            quotationWithholdingSubmit = null;
                            return;
                        }
                        decisionInput.value = 'full_pending';
                        modal.classList.add('hidden');
                        modal.classList.remove('visible');
                        document.body.style.overflow = '';
                        withholdingWarningConfirm = null;
                        onConfirm.submit();
                        return;
                    }

                    decisionInput.value = answer === 'yes'
                        ? 'paid'
                        : (enteredAmount + 0.01 >= grossAmountDue
                            ? 'full_pending'
                            : 'pending');
                    modal.classList.add('hidden');
                    modal.classList.remove('visible');
                    document.body.style.overflow = '';
                    if (answer === 'yes' && typeof onConfirm.onPaid === 'function') {
                        quotationWithholdingSubmit = onConfirm.submit;
                        onConfirm.onPaid();
                        return;
                    }
                    withholdingWarningConfirm = null;
                    quotationWithholdingSubmit = null;
                    onConfirm.submit();
                };
                modal.classList.remove('hidden');
                modal.classList.add('visible');
                document.body.style.overflow = 'hidden';
            }

            document.querySelectorAll('[data-withholding-answer]').forEach((button) => {
                button.addEventListener('click', () => withholdingWarningConfirm?.(button.dataset.withholdingAnswer));
            });
            document.querySelectorAll('[data-close-withholding-warning]').forEach((button) => {
                button.addEventListener('click', () => {
                    const modal = document.getElementById('withholding-warning-modal');
                    modal?.classList.add('hidden');
                    modal?.classList.remove('visible');
                    document.body.style.overflow = '';
                    withholdingWarningConfirm = null;
                    quotationWithholdingSubmit = null;
                });
            });

            function openQuotationWithholdingProof() {
                const warningModal = document.getElementById('withholding-warning-modal');
                const decisionInput = document.getElementById('erp-gestion-withholding-decision');
                const dateInput = document.getElementById('quotation-withholding-date');
                const amountDisplay = document.getElementById('quotation-withholding-amount');
                const typeDisplay = document.getElementById('quotation-withholding-type');
                const titleDisplay = document.getElementById('quotation-withholding-proof-title');
                const dateLabel = document.getElementById('quotation-withholding-date-label');
                const amountLabel = document.getElementById('quotation-withholding-amount-label');
                const uploadTitle = quotationWithholdingProofBox?.querySelector('[data-quotation-withholding-upload-title]');
                const paymentDate = document.getElementById('erp-gestion-date');
                if (!quotationWithholdingProofModal || !quotationWithholdingProofInput || !decisionInput) return;

                quotationWithholdingProofModal.dataset.targetForm = 'quotation';
                quotationWithholdingProofInput.name = 'comprobante_detraccion';
                quotationWithholdingProofInput.setAttribute('form', 'erp-gestion-form');
                dateInput?.setAttribute('form', 'erp-gestion-form');
                if (dateInput) dateInput.name = 'fecha_detraccion';
                document.getElementById('quotation-withholding-description')?.setAttribute('form', 'erp-gestion-form');
                const withholdingDescription = document.getElementById('quotation-withholding-description');
                if (withholdingDescription) withholdingDescription.name = 'descripcion_detraccion';
                decisionInput.value = '';
                if (dateInput) dateInput.value = paymentDate?.value || new Date().toISOString().slice(0, 10);
                const withholdingType = gestionWithholdingType === 'retención' ? 'retención' : 'detracción';
                if (amountDisplay) {
                    const symbol = document.getElementById('erp-gestion-amount-currency')?.textContent
                        || document.getElementById('erp-gestion-payment-amount-currency')?.textContent
                        || 'S/';
                    amountDisplay.textContent = `${symbol} ${Number(gestionDeductionAmount || 0).toFixed(2)}`;
                }
                if (typeDisplay) {
                    typeDisplay.textContent = `Comprobante de ${withholdingType}`;
                }
                if (titleDisplay) {
                    titleDisplay.textContent = `Cargar comprobante de ${withholdingType}`;
                }
                if (dateLabel) dateLabel.textContent = `Fecha de pago de ${withholdingType}`;
                if (amountLabel) amountLabel.textContent = `Monto ${withholdingType}`;
                if (uploadTitle) uploadTitle.textContent = `Cargar el archivo de ${withholdingType}`;
                quotationWithholdingProofInput.value = '';
                if (quotationWithholdingProofName) quotationWithholdingProofName.textContent = '';
                quotationWithholdingProofClear?.classList.add('hidden');
                const descriptionInput = document.getElementById('quotation-withholding-description');
                if (descriptionInput) {
                    descriptionInput.value = '';
                    descriptionInput.placeholder = `Describe sobre el depósito de ${withholdingType}...`;
                }
                quotationWithholdingProofBox?.classList.remove('is-invalid');
                warningModal?.classList.add('hidden');
                warningModal?.classList.remove('visible');
                quotationWithholdingProofModal.classList.remove('hidden');
                quotationWithholdingProofModal.classList.add('visible');
                document.body.style.overflow = 'hidden';
            }

            function openServiceWithholdingProof() {
                if (!quotationWithholdingProofModal || !quotationWithholdingProofInput || !servicePaymentForm) return;

                const grossAmount = currentVencidosState.baseAmount + currentVencidosState.selectedIds.reduce((total, id) => {
                    const cxc = currentVencidosState.cxcs.find((item) => String(item.idcuentasPorCobrar) === String(id));
                    return total + Number(cxc?.montoActual || 0);
                }, 0);
                const isRetention = !!currentVencidosState.esRetencion;
                const withholdingType = isRetention ? 'retención' : 'detracción';
                const withholdingTitle = isRetention ? 'Retención' : 'Detracción';
                const symbol = currentVencidosState.currencySymbol || paymentOriginalCurrency?.textContent || 'S/';
                const dateInput = document.getElementById('quotation-withholding-date');
                const paymentDate = document.getElementById('payment-date');
                const amountDisplay = document.getElementById('quotation-withholding-amount');
                const typeDisplay = document.getElementById('quotation-withholding-type');
                const titleDisplay = document.getElementById('quotation-withholding-proof-title');
                const dateLabel = document.getElementById('quotation-withholding-date-label');
                const amountLabel = document.getElementById('quotation-withholding-amount-label');
                const uploadTitle = quotationWithholdingProofBox?.querySelector('[data-quotation-withholding-upload-title]');
                const descriptionInput = document.getElementById('quotation-withholding-description');

                quotationWithholdingProofModal.dataset.targetForm = 'service';
                quotationWithholdingProofInput.name = 'withholding_proof';
                quotationWithholdingProofInput.setAttribute('form', 'service-payment-form');
                if (dateInput) {
                    dateInput.name = 'withholding_fecha';
                    dateInput.setAttribute('form', 'service-payment-form');
                    dateInput.value = paymentDate?.value || new Date().toISOString().slice(0, 10);
                }
                if (descriptionInput) {
                    descriptionInput.name = 'withholding_description';
                    descriptionInput.setAttribute('form', 'service-payment-form');
                    descriptionInput.value = '';
                    descriptionInput.placeholder = `Describe sobre el depósito de ${withholdingType}...`;
                }
                if (amountDisplay) amountDisplay.textContent = formatMoney(calculateDeduction(grossAmount, isRetention), symbol);
                if (typeDisplay) typeDisplay.textContent = `Comprobante de ${withholdingType}`;
                if (titleDisplay) titleDisplay.textContent = `Cargar comprobante de ${withholdingType}`;
                if (dateLabel) dateLabel.textContent = `Fecha de pago de ${withholdingType}`;
                if (amountLabel) amountLabel.textContent = `Monto ${withholdingType}`;
                if (uploadTitle) uploadTitle.textContent = `Cargar el archivo de ${withholdingType}`;
                quotationWithholdingProofInput.value = '';
                if (quotationWithholdingProofName) quotationWithholdingProofName.textContent = '';
                quotationWithholdingProofClear?.classList.add('hidden');
                quotationWithholdingProofBox?.classList.remove('is-invalid');
                quotationWithholdingProofModal.classList.remove('hidden');
                quotationWithholdingProofModal.classList.add('visible');
                document.body.style.overflow = 'hidden';
                const modalTitle = document.getElementById('quotation-withholding-proof-title');
                if (modalTitle) modalTitle.textContent = `Cargar comprobante de ${withholdingTitle}`;
            }

            function openFacturaWithholdingProof() {
                if (!quotationWithholdingProofModal || !quotationWithholdingProofInput || !serviceFacturaForm) return;

                const grossAmount = Number(facturaModal?.dataset.grossAmount || 0);
                const isRetention = !!currentVencidosState.esRetencion;
                const withholdingType = isRetention ? 'retención' : 'detracción';
                const withholdingTitle = isRetention ? 'Retención' : 'Detracción';
                const symbol = currentVencidosState.currencySymbol || facturaModal?.dataset.currencySymbol || 'S/';
                const dateInput = document.getElementById('quotation-withholding-date');
                const paymentDate = document.getElementById('factura-payment-date');
                const amountDisplay = document.getElementById('quotation-withholding-amount');
                const typeDisplay = document.getElementById('quotation-withholding-type');
                const titleDisplay = document.getElementById('quotation-withholding-proof-title');
                const dateLabel = document.getElementById('quotation-withholding-date-label');
                const amountLabel = document.getElementById('quotation-withholding-amount-label');
                const uploadTitle = quotationWithholdingProofBox?.querySelector('[data-quotation-withholding-upload-title]');
                const descriptionInput = document.getElementById('quotation-withholding-description');

                quotationWithholdingProofModal.dataset.targetForm = 'factura';
                quotationWithholdingProofInput.name = 'withholding_proof';
                quotationWithholdingProofInput.setAttribute('form', 'service-factura-form');
                if (dateInput) {
                    dateInput.name = 'withholding_fecha';
                    dateInput.setAttribute('form', 'service-factura-form');
                    dateInput.value = paymentDate?.value || new Date().toISOString().slice(0, 10);
                }
                if (descriptionInput) {
                    descriptionInput.name = 'withholding_description';
                    descriptionInput.setAttribute('form', 'service-factura-form');
                    descriptionInput.value = '';
                    descriptionInput.placeholder = `Describe sobre el depósito de ${withholdingType}...`;
                }
                if (amountDisplay) amountDisplay.textContent = formatMoney(calculateDeduction(grossAmount, isRetention), symbol);
                if (typeDisplay) typeDisplay.textContent = `Comprobante de ${withholdingType}`;
                if (titleDisplay) titleDisplay.textContent = `Cargar comprobante de ${withholdingType}`;
                if (dateLabel) dateLabel.textContent = `Fecha de pago de ${withholdingType}`;
                if (amountLabel) amountLabel.textContent = `Monto ${withholdingType}`;
                if (uploadTitle) uploadTitle.textContent = `Cargar el archivo de ${withholdingType}`;
                quotationWithholdingProofInput.value = '';
                if (quotationWithholdingProofName) quotationWithholdingProofName.textContent = '';
                quotationWithholdingProofClear?.classList.add('hidden');
                quotationWithholdingProofBox?.classList.remove('is-invalid');
                document.getElementById('withholding-warning-modal')?.classList.add('hidden');
                document.getElementById('withholding-warning-modal')?.classList.remove('visible');
                quotationWithholdingProofModal.classList.remove('hidden');
                quotationWithholdingProofModal.classList.add('visible');
                document.body.style.overflow = 'hidden';
                if (titleDisplay) titleDisplay.textContent = `Cargar comprobante de ${withholdingTitle}`;
            }

            function returnToWithholdingWarning() {
                quotationWithholdingProofModal?.classList.add('hidden');
                quotationWithholdingProofModal?.classList.remove('visible');
                const warningModal = document.getElementById('withholding-warning-modal');
                warningModal?.classList.remove('hidden');
                warningModal?.classList.add('visible');
                document.body.style.overflow = 'hidden';
            }

            quotationWithholdingProofInput?.addEventListener('change', function () {
                setQuotationWithholdingProof(this.files?.[0]);
            });
            quotationWithholdingProofClear?.addEventListener('click', () => {
                if (quotationWithholdingProofInput) quotationWithholdingProofInput.value = '';
                if (quotationWithholdingProofName) quotationWithholdingProofName.textContent = '';
                quotationWithholdingProofClear.classList.add('hidden');
                quotationWithholdingProofBox?.classList.remove('is-invalid', 'is-dragging');
            });
            function setQuotationWithholdingProof(file) {
                if (!file || !quotationWithholdingProofInput) return;
                const allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
                if (!allowedTypes.includes(file.type) || file.size > 5 * 1024 * 1024) {
                    quotationWithholdingProofInput.value = '';
                    if (quotationWithholdingProofName) {
                        quotationWithholdingProofName.textContent = 'Selecciona un JPG, PNG o PDF de máximo 5 MB.';
                    }
                    quotationWithholdingProofClear?.classList.add('hidden');
                    quotationWithholdingProofBox?.classList.add('is-invalid');
                    return;
                }
                const transfer = new DataTransfer();
                transfer.items.add(file);
                quotationWithholdingProofInput.files = transfer.files;
                if (quotationWithholdingProofName) quotationWithholdingProofName.textContent = file.name;
                quotationWithholdingProofClear?.classList.remove('hidden');
                quotationWithholdingProofBox?.classList.remove('is-invalid');
            }
            if (quotationWithholdingProofBox) {
                ['dragenter', 'dragover'].forEach((eventName) => {
                    quotationWithholdingProofBox.addEventListener(eventName, (event) => {
                        event.preventDefault();
                        quotationWithholdingProofBox.classList.add('is-dragging');
                    });
                });
                ['dragleave', 'drop'].forEach((eventName) => {
                    quotationWithholdingProofBox.addEventListener(eventName, (event) => {
                        event.preventDefault();
                        quotationWithholdingProofBox.classList.remove('is-dragging');
                    });
                });
                quotationWithholdingProofBox.addEventListener('drop', (event) => {
                    setQuotationWithholdingProof(event.dataTransfer?.files?.[0]);
                });
            }
            document.addEventListener('paste', function (event) {
                if (!quotationWithholdingProofModal?.classList.contains('visible')) return;
                const pastedItem = Array.from(event.clipboardData?.items || [])
                    .find((item) => item.kind === 'file');
                if (pastedItem) {
                    event.preventDefault();
                    setQuotationWithholdingProof(pastedItem.getAsFile());
                }
            });
            document.querySelectorAll('[data-cancel-quotation-withholding]').forEach((button) => {
                button.addEventListener('click', returnToWithholdingWarning);
            });
            document.getElementById('quotation-withholding-proof-save')?.addEventListener('click', function () {
                const proofFile = quotationWithholdingProofInput?.files?.[0];
                const proofDate = document.getElementById('quotation-withholding-date');
                if (!proofFile) {
                    showServiceMessage('Debe cargar el comprobante de detracción/retención.');
                    quotationWithholdingProofInput?.focus();
                    return;
                }
                if (!proofDate?.value) {
                    showServiceMessage('Debe indicar la fecha de pago de la detracción/retención.');
                    proofDate?.focus();
                    return;
                }

                const proofTarget = quotationWithholdingProofModal?.dataset.targetForm;
                const decisionInput = document.getElementById(proofTarget === 'service'
                    ? 'payment-withholding-decision'
                    : proofTarget === 'factura'
                        ? 'factura-withholding-decision'
                        : 'erp-gestion-withholding-decision');
                if (decisionInput) decisionInput.value = 'paid';
                quotationWithholdingProofModal?.classList.add('hidden');
                quotationWithholdingProofModal?.classList.remove('visible');
                document.body.style.overflow = '';
                withholdingWarningConfirm = null;
                const submit = quotationWithholdingSubmit;
                quotationWithholdingSubmit = null;
                submit?.();
            });

            servicePaymentForm?.addEventListener('submit', function (event) {
                event.preventDefault();
                syncExplicitPeriodCxcIds('pay');

                if (servicePaymentModal?.dataset.paymentViewMode === '1') {
                    if (servicePaymentModal.dataset.paymentEditing !== '1') return;

                    const submitButton = document.getElementById('payment-submit-btn');
                    if (submitButton) {
                        submitButton.disabled = true;
                        submitButton.textContent = 'Guardando cambios...';
                    }
                    const updateUrl = servicePaymentModal.dataset.paymentUpdateUrl
                        || paymentDetailsUpdateUrlTemplate.replace(
                            '__id__',
                            encodeURIComponent(servicePaymentModal.dataset.paymentCxcId || '')
                        );
                    const updateData = new FormData(servicePaymentForm);
                    updateData.set('_method', 'PATCH');
                    fetch(updateUrl, {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: updateData
                    })
                        .then(async (response) => {
                            const result = await response.json();
                            if (!response.ok || !result.success) {
                                throw new Error(result.message || 'No se pudieron actualizar los datos del pago.');
                            }
                            await loadPaymentDetails(servicePaymentModal.dataset.paymentCxcId);
                            setPaymentViewMode(true, false);
                        })
                        .catch((error) => showServiceMessage(error.message || 'No se pudieron actualizar los datos del pago.'))
                        .finally(() => {
                            if (submitButton) {
                                submitButton.disabled = false;
                                submitButton.textContent = 'Guardar cambios';
                            }
                        });
                    return;
                }

                if (servicePaymentModal?.dataset.creditPaymentMode === 'plan') {
                    const selectedInstallments = document.querySelectorAll(
                        '#payment-credit-plan-list input[type="checkbox"]:checked'
                    );
                    if (selectedInstallments.length === 0) {
                        showServiceMessage('Selecciona al menos una cuota del crédito para continuar.');
                        return;
                    }
                }

                const isPendingWithholdingCompletion = document.getElementById('payment-complete-withholding')?.value === '1';
                if (isPendingWithholdingCompletion) {
                    if (!paymentComprobanteInput?.files?.length) {
                        paymentComprobanteInput?.setCustomValidity('Adjunta el comprobante de pago al Banco de la Nación.');
                        paymentComprobanteInput?.reportValidity();
                        return;
                    }
                    paymentComprobanteInput.setCustomValidity('');
                    if (!servicePaymentForm.checkValidity()) {
                        servicePaymentForm.reportValidity();
                        return;
                    }
                    const submitButton = servicePaymentForm.querySelector('button[type="submit"]');
                    if (submitButton) {
                        submitButton.disabled = true;
                        submitButton.textContent = 'Registrando comprobante...';
                    }
                    servicePaymentForm.submit();
                    return;
                }

                const selectedRows = selectedServiceRows();
                const hasHiddenServices = selectedServiceInputs && selectedServiceInputs.children.length > 0;
                const hasCxcId = document.getElementById('payment-cxc-id')?.value;

                if (selectedRows.length === 0 && !hasHiddenServices && !hasCxcId) {
                    if (serviceSelectionError) {
                        serviceSelectionError.textContent = 'Selecciona al menos un servicio.';
                        serviceSelectionError.classList.remove('hidden');
                    }
                    return;
                }

                const paymentMonedaIdInput = document.getElementById('payment-moneda-id');
                const resolvedCurrencyId = paymentMonedaIdInput?.value || resolveSelectedCurrencyId() || '';
                if (paymentMonedaIdInput && !paymentMonedaIdInput.value) {
                    paymentMonedaIdInput.value = resolvedCurrencyId;
                }

                const isTotalPayment = document.querySelector('#service-payment-modal input[name="modo_pago"][value="total"]')?.checked;
                const usesOtherPaymentCurrency = document.querySelector('#service-payment-modal input[name="payment_currency_mode"][value="other"]')?.checked;
                if (isTotalPayment && paymentAmount) {
                    const baseTotal = Number(
                        !usesOtherPaymentCurrency && paymentAmountWasEdited
                            ? getPaymentBaseAmount()
                            : !usesOtherPaymentCurrency && servicePaymentModal?.dataset.expectedCashDue
                                ? servicePaymentModal.dataset.expectedCashDue
                                : (paymentOriginal?.value || '').replace(/[^0-9.-]+/g, '') || 0
                    );
                    paymentAmount.value = Number.isFinite(baseTotal) ? baseTotal.toFixed(2) : (paymentAmount.value || '0.00');
                }

                const paymentDecision = document.getElementById('payment-withholding-decision');
                const selectedAmount = currentVencidosState.selectedIds.reduce((total, id) => {
                    const cxc = currentVencidosState.cxcs.find((item) => String(item.idcuentasPorCobrar) === String(id));
                    return total + Number(cxc?.montoActual || 0);
                }, 0);
                const gross = currentVencidosState.baseAmount + selectedAmount;
                if (gross >= 700 && !paymentDecision?.value) {
                    const paymentTotal = Number(paymentAmount?.value || 0);
                    const isTotal = document.querySelector('#service-payment-modal input[name="modo_pago"][value="total"]')?.checked;
                    const selectedNotesAmount = selectedCreditNotesAmount('payment');
                    const bankCreditAmount = selectedBankCreditAmount('payment', currentVencidosState.saldoFavorDisponible);
                    const creditApplied = Math.min(
                        calculateNetPayable(gross, currentVencidosState.esRetencion),
                        selectedNotesAmount + bankCreditAmount
                    );
                    const cashDue = Math.max(calculateNetPayable(gross, currentVencidosState.esRetencion) - creditApplied, 0);
                    const grossDue = Math.max(gross - creditApplied, 0);
                    const cashEntered = paymentTotal;
                    if (isTotal && cashEntered + 0.01 < cashDue) {
                        showServiceMessage(`El monto pagado debe ser al menos el neto calculado de ${formatMoney(cashDue, currentVencidosState.currencySymbol)}.`);
                        return;
                    }
                    if (isTotal || cashEntered + 0.01 >= cashDue) {
                        showWithholdingWarning(gross, currentVencidosState.esRetencion, paymentDecision, {
                            getEnteredAmount: () => isTotal ? paymentTotal : Number(paymentAmount?.value || 0),
                            getNetAmount: () => cashDue,
                            getGrossAmount: () => grossDue,
                            submit: () => servicePaymentForm.requestSubmit(),
                            onPaid: openServiceWithholdingProof
                        });
                        return;
                    }
                }

                if (!servicePaymentForm.checkValidity()) {
                    servicePaymentForm.reportValidity();
                    return;
                }

                const submitButton = servicePaymentForm.querySelector('button[type="submit"]');
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.textContent = 'Guardando datos...';
                }

                const paymentPeriodEnd = document.getElementById('payment-period-end');
                const omitDefaultPeriodEnd = paymentPeriodEnd
                    && paymentPeriodEnd.value === (paymentPeriodEnd.dataset.basePeriodEnd || '');
                if (omitDefaultPeriodEnd) paymentPeriodEnd.disabled = true;
                servicePaymentForm.submit();
                if (omitDefaultPeriodEnd) paymentPeriodEnd.disabled = false;
            });
            refreshServiceSelection();

            // ── Teleport: mover todos los modales al <body> para evitar clipping por overflow/transform ──
            document.querySelectorAll('.erp-modal-backdrop').forEach(function (modal) {
                document.body.appendChild(modal);
            });

            // ── Dar de baja: habilitar botón según checkboxes de cliente ──
            const btnDarDeBaja = document.getElementById('btn-dar-de-baja');
            let selectedBajaServiceIds = [];
            let selectedBulkRevertOperations = [];

            function getSelectedDeactivationServiceIds() {
                return Array.from(document.querySelectorAll('.stair-check-item:checked'))
                    .filter(checkbox =>
                        checkbox.dataset.creditInstallment !== '1' &&
                        !checkbox.closest('tr')?.querySelector('.btn-revert-payment')
                    )
                    .map(checkbox => String(checkbox.value))
                    .filter(Boolean);
            }

            function refreshBajaBtn() {
                if (btnDarDeBaja) {
                    btnDarDeBaja.disabled = getSelectedDeactivationServiceIds().length === 0;
                }
            }

            // Cascada: cliente → marca/desmarca todos los tipos e ítems de ese cliente
            document.addEventListener('change', function (e) {
                if (e.target.matches('.stair-check-client')) {
                    const clientId = e.target.dataset.clientId;
                    const state = e.target.checked;
                    document.querySelectorAll(`.stair-check-type[data-client-id="${clientId}"]`)
                        .forEach(function (cb) {
                            if (!cb.disabled) cb.checked = state;
                        });
                    document.querySelectorAll(`.stair-check-item[data-client-id="${clientId}"]`)
                        .forEach(function (cb) {
                            if (!cb.disabled) cb.checked = state;
                        });
                    refreshBajaBtn();
                }
                // Cascada: tipo → marca/desmarca ítems de ese mismo tipo
                if (e.target.matches('.stair-check-type')) {
                    const detailId = e.target.closest('.stair-service-row')?.dataset.target?.replace(/^#/, '');
                    const detailRow = detailId ? document.getElementById(detailId) : null;
                    const state = e.target.checked;
                    detailRow?.querySelectorAll('.stair-check-item').forEach(function (checkbox) {
                        if (!checkbox.disabled) checkbox.checked = state;
                    });
                    refreshBajaBtn();
                }
                if (e.target.matches('.stair-check-item')) {
                    refreshBajaBtn();
                }
            });

            if (btnDarDeBaja) {
                btnDarDeBaja.addEventListener('click', function (e) {
                    e.preventDefault();
                    selectedBajaServiceIds = getSelectedDeactivationServiceIds();

                    if (selectedBajaServiceIds.length === 0) {
                        showServiceMessage('Selecciona al menos un servicio para dar de baja.');
                        return;
                    }

                    const bajaModal = document.getElementById('baja-option-modal');
                    if (bajaModal) {
                        bajaModal.classList.remove('hidden');
                        bajaModal.classList.add('visible');
                        document.body.style.overflow = 'hidden';
                    }
                });
            }

            // Modal 1 -> Seleccionar Modo ('ahora' / 'periodo')
            document.querySelectorAll('.btn-select-baja-modo').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const modo = this.getAttribute('data-baja-modo') || 'ahora';
                    const modoInput = document.getElementById('baja-modo-input');
                    if (modoInput) modoInput.value = modo;

                    const bajaOptionModal = document.getElementById('baja-option-modal');
                    if (bajaOptionModal) {
                        bajaOptionModal.classList.remove('visible');
                        bajaOptionModal.classList.add('hidden');
                    }

                    const bajaCommentModal = document.getElementById('baja-comment-modal');
                    const bajaComentario = document.getElementById('baja-comentario');
                    if (bajaCommentModal) {
                        if (bajaComentario) bajaComentario.value = '';
                        bajaCommentModal.classList.remove('hidden');
                        bajaCommentModal.classList.add('visible');
                        if (bajaComentario) bajaComentario.focus();
                    }
                });
            });

            // Modal 2 -> Submit form step -> abre Modal 3
            const bajaCommentForm = document.getElementById('baja-comment-form');
            if (bajaCommentForm) {
                bajaCommentForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    const bajaComentario = document.getElementById('baja-comentario');
                    if (bajaComentario && !bajaComentario.value.trim()) {
                        showServiceMessage('Por favor ingresa un comentario describiendo el motivo de la baja.');
                        bajaComentario.focus();
                        return;
                    }

                    const bajaCommentModal = document.getElementById('baja-comment-modal');
                    if (bajaCommentModal) {
                        bajaCommentModal.classList.remove('visible');
                        bajaCommentModal.classList.add('hidden');
                    }

                    const bajaSimModal = document.getElementById('baja-sim-modal');
                    if (bajaSimModal) {
                        bajaSimModal.classList.remove('hidden');
                        bajaSimModal.classList.add('visible');
                    }
                });
            }

            // Modal 3 -> Selección (Sí mantener / No mantener) -> Envío final
            function submitBajaFinalForm(mantenerSimVal) {
                const mantenerInput = document.getElementById('baja-mantener-sim-input');
                if (mantenerInput) mantenerInput.value = mantenerSimVal;

                const bajaServiceInputs = document.getElementById('baja-selected-service-inputs');
                if (bajaServiceInputs) {
                    bajaServiceInputs.innerHTML = selectedBajaServiceIds
                        .map(function (id) { return '<input type="hidden" name="servicio_ids[]" value="' + id + '">'; })
                        .join('');
                }

                const bajaSimModal = document.getElementById('baja-sim-modal');
                if (bajaSimModal) {
                    bajaSimModal.classList.remove('visible');
                    bajaSimModal.classList.add('hidden');
                }
                document.body.style.overflow = '';

                if (bajaCommentForm) {
                    bajaCommentForm.submit();
                }
            }

            const bajaSimSiBtn = document.getElementById('baja-sim-si-btn');
            const bajaSimNoBtn = document.getElementById('baja-sim-no-btn');
            if (bajaSimSiBtn) {
                bajaSimSiBtn.addEventListener('click', function () {
                    submitBajaFinalForm('si');
                });
            }
            if (bajaSimNoBtn) {
                bajaSimNoBtn.addEventListener('click', function () {
                    submitBajaFinalForm('no');
                });
            }

            refreshBajaBtn();

            // ── Interactividad de Tabla Desplegable en Escalera (Cliente -> Tipo Servicio -> Vehículos) ──
            document.addEventListener('click', function (event) {
                const row = event.target.closest('.stair-client-row');
                if (!row || event.target.closest('.btn-manage-client, .btn-pay-service-type, .btn-pay-vehicle, a, input')) return;
                const detailRow = document.getElementById(`client-detail-${row.dataset.groupId}`);
                const toggleIcon = row.querySelector('.toggle-icon');
                if (!detailRow) return;
                const isHidden = detailRow.classList.contains('hidden');
                detailRow.classList.toggle('hidden', !isHidden);
                if (toggleIcon) toggleIcon.style.transform = isHidden ? 'rotate(90deg)' : 'rotate(0deg)';
            });

            document.addEventListener('click', function (event) {
                const toggle = event.target.closest('.stair-toggle-service, .stair-service-row');
                if (!toggle || event.target.closest('.btn-pay-service-type, .btn-pay-vehicle')) return;
                const targetId = toggle.dataset.target || toggle.querySelector('.stair-toggle-service')?.dataset.target;
                if (!targetId) return;
                event.stopPropagation();
                const targetRow = document.querySelector(targetId);
                const toggleButton = toggle.classList.contains('stair-toggle-service') ? toggle : toggle.querySelector('.stair-toggle-service');
                const toggleIcon = toggleButton?.querySelector('.toggle-icon');
                if (!targetRow) return;
                const isHidden = targetRow.classList.contains('hidden');
                targetRow.classList.toggle('hidden', !isHidden);
                if (toggleIcon) toggleIcon.style.transform = isHidden ? 'rotate(90deg)' : 'rotate(0deg)';
            });

            const facturaModeSwitch = document.getElementById('factura-mode-switch');
            const facturaModeToggle = document.getElementById('factura-mode-toggle');
            const facturaModeTitle = document.getElementById('factura-mode-title');
            const facturaModeDesc = document.getElementById('factura-mode-desc');
            const facturaCobroExtraSection = document.getElementById('factura-cobro-extra-section');
            const facturaRealizarCobroInput = document.getElementById('factura-realizar-cobro-input');
            const facturaSubmitBtn = document.getElementById('factura-submit-btn');
            const facturaSubmitError = document.getElementById('factura-submit-error');
            const facturaModal = document.getElementById('service-factura-modal');
            const facturaClientField = document.getElementById('factura-client-field');
            const facturaAdvanceToggle = document.getElementById('btn-factura-advance-toggle');
            const facturaAdvanceFields = document.getElementById('factura-advance-fields');
            const facturaAdvanceMonths = document.getElementById('factura-advance-months');
            const facturaAmountDisplay = document.getElementById('factura-amount');
            const facturaAmountCurrency = document.getElementById('factura-amount-currency');
            const facturaPartialAmountCurrency = document.getElementById('factura-partial-payment-amount-currency');
            const paymentAdvanceToggle = document.getElementById('btn-payment-advance-toggle');
            const paymentAdvanceFields = document.getElementById('payment-advance-fields');
            const paymentAdvanceMonthsCount = document.getElementById('payment-advance-months-count');
            const paymentAdvanceMonths = document.getElementById('payment-advance-months');
            const paymentOriginalCurrency = document.getElementById('payment-original-currency');
            const paymentAdvanceSummary = document.getElementById('payment-advance-summary');
            let facturaAmountWasEdited = false;
            let paymentAmountWasEdited = false;

            function showServiceMessage(message) {
                if (!serviceInlineMessage) {
                    console.error(message);
                    return;
                }
                serviceInlineMessage.textContent = String(message || '');
                serviceInlineMessage.classList.toggle('hidden', !message);
            }

            function clearServiceMessage() {
                if (!serviceInlineMessage) return;
                serviceInlineMessage.textContent = '';
                serviceInlineMessage.classList.add('hidden');
            }

            function showFacturaSubmitError(message) {
                if (!facturaSubmitError) {
                    console.error(message);
                    return;
                }
                facturaSubmitError.textContent = message;
                facturaSubmitError.classList.remove('hidden');
            }

            function clearFacturaSubmitError() {
                if (!facturaSubmitError) return;
                facturaSubmitError.textContent = '';
                facturaSubmitError.classList.add('hidden');
            }

            function isCreditPayment(select) {
                const option = select?.selectedOptions?.[0];
                return /credito|crédito/i.test(`${option?.textContent || ''} ${option?.value || ''}`);
            }

            function renderInstallments(container, count, amount, namePrefix) {
                if (!container) return;
                container.replaceChildren();
                const safeCount = Math.max(0, Math.min(Number(count) || 0, 60));
                if (!safeCount) return;

                const totalCents = Math.max(0, Math.round((Number(amount) || 0) * 100));
                const baseCents = Math.floor(totalCents / safeCount);
                const remainingCents = totalCents % safeCount;
                const select = namePrefix === 'factura_cuotas' ? facturaPaymentMethod : paymentMethod;
                const creditDays = Number(select?.selectedOptions?.[0]?.dataset?.creditDays || 0);
                const firstDate = new Date();
                const formatDate = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
                for (let index = 1; index <= safeCount; index++) {
                    const dueDate = new Date(firstDate);
                    dueDate.setDate(firstDate.getDate() + (creditDays * (index - 1)));
                    const installmentAmount = ((baseCents + (index <= remainingCents ? 1 : 0)) / 100).toFixed(2);
                    const row = document.createElement('div');
                    row.className = 'mb-2 ';
                    row.innerHTML = `
                                                                <span class="mb-1 block text-xs font-bold text-slate-600">Cuota ${index}</span>
                                                                <div class="grid grid-cols-2 gap-1.5">
                                                                    <label class="text-xs font-semibold text-slate-600">Monto
                                                                        <input name="${namePrefix}_montos[]" type="number" min="0.01" step="0.01" value="${installmentAmount}" class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none">
                                                                    </label>
                                                                    <label class="text-xs font-semibold text-slate-600">Fecha a pagar
                                                                        <input name="${namePrefix}_fechas[]" type="date" value="${formatDate(dueDate)}" required class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none">
                                                                    </label>
                                                                </div>`;
                    container.appendChild(row);
                }
            }

            function rebalanceInstallments(container, changedInput, amount, namePrefix) {
                if (!container || !changedInput) return;
                const amountInputs = Array.from(container.querySelectorAll(`input[name="${namePrefix}_montos[]"]`));
                const totalCents = Math.max(0, Math.round((Number(amount) || 0) * 100));
                if (amountInputs.length <= 1) return;

                const editedAmount = Number(changedInput.value);
                const enteredCents = Math.round(editedAmount * 100);
                const maxChangedCents = totalCents - amountInputs.length + 1;
                if (
                    !Number.isFinite(editedAmount)
                    || editedAmount <= 0
                    || !Number.isFinite(enteredCents)
                    || maxChangedCents < 1
                ) return;
                const editedCents = Math.min(maxChangedCents, Math.max(1, enteredCents));
                if (editedCents !== enteredCents) {
                    changedInput.value = (editedCents / 100).toFixed(2);
                }

                const remainingCents = totalCents - editedCents;
                const otherInputs = amountInputs.filter((input) => input !== changedInput);
                const baseCents = Math.floor(remainingCents / otherInputs.length);
                const extraCents = remainingCents % otherInputs.length;
                otherInputs.forEach((input, index) => {
                    input.value = ((baseCents + (index < extraCents ? 1 : 0)) / 100).toFixed(2);
                });

                if (changedInput === amountInputs[0]) {
                    const paidAmount = namePrefix === 'factura_cuotas'
                        ? document.getElementById('factura-partial-payment-amount')
                        : document.getElementById('payment-amount');
                    if (paidAmount) paidAmount.value = changedInput.value;
                    if (namePrefix === 'cuotas') {
                        showPartialPaymentWithAmount(changedInput.value);
                    } else if (paidAmount) {
                        paidAmount.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }
            }

            function syncCreditFields(select, fields, installmentsPanel, countInput, container, amount, namePrefix) {
                const credit = isCreditPayment(select);
                fields?.classList.toggle('hidden', !credit);
                installmentsPanel?.classList.toggle('hidden', !credit);
                if (namePrefix === 'cuotas') {
                    fields?.parentElement?.parentElement?.classList.toggle('is-credit-payment', credit);
                }
                if (!credit) {
                    if (countInput) countInput.value = '';
                    if (container) container.replaceChildren();
                    return;
                }
                const paymentModeName = namePrefix === 'factura_cuotas' ? 'factura_modo_pago' : 'modo_pago';
                const modeContainer = namePrefix === 'factura_cuotas' ? facturaModal : servicePaymentModal;
                const partialRadio = modeContainer?.querySelector(`input[name="${paymentModeName}"][value="parcial"]`);
                if (namePrefix === 'cuotas') {
                    showPartialPaymentWithAmount();
                } else if (partialRadio) {
                    partialRadio.checked = true;
                    partialRadio.dispatchEvent(new Event('change', { bubbles: true }));
                }
                renderInstallments(container, countInput?.value, amount(), namePrefix);

                const firstAmount = container?.querySelector(`input[name="${namePrefix}_montos[]"]`);
                const paidAmount = namePrefix === 'factura_cuotas'
                    ? document.getElementById('factura-partial-payment-amount')
                    : document.getElementById('payment-amount');
                if (namePrefix === 'cuotas' && firstAmount) {
                    showPartialPaymentWithAmount(firstAmount.value);
                } else if (firstAmount && paidAmount) {
                    paidAmount.value = firstAmount.value;
                }
                if (namePrefix === 'factura_cuotas') {
                    syncFacturaCreditInstallmentPayment(firstAmount ? Number(firstAmount.value) : null);
                }
            }

            const facturaPaymentMethod = document.getElementById('factura-payment-method');
            const facturaCreditFields = document.getElementById('factura-credit-fields');
            const facturaCreditInstallments = document.getElementById('factura-credit-installments');
            const facturaCanCuotas = document.getElementById('factura-can-cuotas');
            const facturaInstallments = document.getElementById('factura-installments');
            const paymentMethod = document.getElementById('payment-method');
            const paymentCreditFields = document.getElementById('payment-credit-fields');
            const paymentCreditInstallments = document.getElementById('payment-credit-installments');
            const paymentCanCuotas = document.getElementById('payment-can-cuotas');
            const paymentInstallments = document.getElementById('payment-installments');

            const facturaAmountValue = () => Number(facturaModal?.dataset.payableAmount || facturaModal?.dataset.rawAmount || 0);
            const paymentAmountValue = () => Number(String(paymentOriginal?.value || '').replace(/[^0-9.-]+/g, '')) || 0;

            function syncFacturaCreditInstallmentPayment(amount = null) {
                if (!isCreditPayment(facturaPaymentMethod)) return;

                const totalRadio = facturaModal?.querySelector('input[name="factura_modo_pago"][value="total"]');
                const partialRadio = facturaModal?.querySelector('input[name="factura_modo_pago"][value="parcial"]');
                const partialField = document.getElementById('factura-partial-payment-field');
                const partialAmount = document.getElementById('factura-partial-payment-amount');
                const firstInstallment = amount ?? Number(
                    facturaInstallments?.querySelector('input[name="factura_cuotas_montos[]"]')?.value || 0
                );

                if (totalRadio) totalRadio.checked = false;
                if (partialRadio) partialRadio.checked = true;
                partialField?.classList.remove('hidden');
                if (partialAmount) {
                    partialAmount.value = firstInstallment > 0 ? firstInstallment.toFixed(2) : '';
                }

                const total = Number(facturaModal?.dataset.payableAmount || facturaModal?.dataset.rawAmount || 0);
                if (total > 0 && firstInstallment >= 0) {
                    const gross = Number(facturaModal?.dataset.grossAmount || total);
                    const cashDue = Number(facturaModal?.dataset.expectedCashDue || total);
                    updateBalanceAndCreditDisplay(
                        gross,
                        firstInstallment,
                        'factura-payment-balance',
                        'factura-payment-credit-summary',
                        facturaModal?.dataset.currencySymbol || 'S/',
                        cashDue,
                        Number(facturaModal?.dataset.deductionAmount || 0)
                    );
                }
            }

            facturaPaymentMethod?.addEventListener('change', () => syncCreditFields(
                facturaPaymentMethod, facturaCreditFields, facturaCreditInstallments, facturaCanCuotas, facturaInstallments, facturaAmountValue, 'factura_cuotas'
            ));
            facturaCanCuotas?.addEventListener('input', () => {
                if (isCreditPayment(facturaPaymentMethod)) {
                    renderInstallments(facturaInstallments, facturaCanCuotas.value, facturaAmountValue(), 'factura_cuotas');
                    syncFacturaCreditInstallmentPayment();
                }
            });
            facturaInstallments?.addEventListener('change', (event) => {
                if (event.target.matches('input[name="factura_cuotas_montos[]"]')) {
                    rebalanceInstallments(facturaInstallments, event.target, facturaAmountValue(), 'factura_cuotas');
                    if (event.target === facturaInstallments.querySelector('input[name="factura_cuotas_montos[]"]')) {
                        syncFacturaCreditInstallmentPayment(Number(event.target.value) || 0);
                    }
                }
            });
            paymentMethod?.addEventListener('change', () => syncCreditFields(
                paymentMethod, paymentCreditFields, paymentCreditInstallments, paymentCanCuotas, paymentInstallments, paymentAmountValue, 'cuotas'
            ));
            paymentCanCuotas?.addEventListener('input', () => {
                if (isCreditPayment(paymentMethod)) {
                    renderInstallments(paymentInstallments, paymentCanCuotas.value, paymentAmountValue(), 'cuotas');
                    const firstAmount = paymentInstallments?.querySelector('input[name="cuotas_montos[]"]');
                    if (firstAmount) showPartialPaymentWithAmount(firstAmount.value);
                }
            });
            paymentInstallments?.addEventListener('change', (event) => {
                if (event.target.matches('input[name="cuotas_montos[]"]')) {
                    rebalanceInstallments(paymentInstallments, event.target, paymentAmountValue(), 'cuotas');
                }
            });

            function applyFacturaModeState(isFacturarYCancelar) {
                if (facturaModeToggle) {
                    facturaModeToggle.dataset.mode = isFacturarYCancelar ? 'cancelar' : 'facturar';
                    facturaModeToggle.setAttribute('aria-checked', isFacturarYCancelar ? 'true' : 'false');
                    const options = facturaModeToggle.querySelectorAll('.erp-factura-mode-option');
                    options.forEach(function (option) {
                        const isActive = option.dataset.mode === (isFacturarYCancelar ? 'cancelar' : 'facturar');
                        option.classList.toggle('is-active', isActive);
                    });
                }

                if (facturaModeTitle) facturaModeTitle.textContent = isFacturarYCancelar ? 'Facturar y Cancelar' : 'Solo Facturar';
                if (facturaModeDesc) facturaModeDesc.textContent = isFacturarYCancelar
                    ? 'Factura y procesa el pago en un solo paso (Estado cambiará a CANCELADO)'
                    : 'Estado cambiará a FACTURADO. Activa el switch si deseas Facturar y Cancelar a la vez.';
                facturaClientField?.classList.toggle('sm:col-span-2', !isFacturarYCancelar);
                if (facturaAmountDisplay) facturaAmountDisplay.readOnly = !isFacturarYCancelar;
                if (facturaAmountDisplay) facturaAmountDisplay.classList.toggle('bg-slate-50', !isFacturarYCancelar);
                if (facturaAdvanceToggle) facturaAdvanceToggle.classList.toggle('hidden', !isFacturarYCancelar);
                const facturaPeriodsButton = document.getElementById('btn-factura-periodos-vencidos');
                facturaPeriodsButton?.classList.toggle('hidden', !isFacturarYCancelar);
                const facturaCreditNoteButton = document.getElementById('factura-select-credit-note');
                facturaCreditNoteButton?.classList.toggle('hidden', !isFacturarYCancelar);
                document.getElementById('factura-select-credit-amount')
                    ?.classList.toggle('hidden', !isFacturarYCancelar);
                if (!isFacturarYCancelar) {
                    facturaAdvanceFields?.classList.add('hidden');
                    if (facturaAdvanceToggle) {
                        facturaAdvanceToggle.textContent = 'Pagar adelantado';
                        facturaAdvanceToggle.setAttribute('aria-expanded', 'false');
                    }
                    if (facturaAdvanceMonths) {
                        facturaAdvanceMonths.value = '';
                        facturaAdvanceMonths.disabled = true;
                    }
                    facturaAmountWasEdited = false;
                } else {
                    applyDeductionLogic();
                }
                if (facturaCobroExtraSection) {
                    facturaCobroExtraSection.setAttribute('aria-hidden', isFacturarYCancelar ? 'false' : 'true');
                }
                if (facturaRealizarCobroInput) facturaRealizarCobroInput.value = isFacturarYCancelar ? '1' : '0';
                if (facturaModeSwitch) facturaModeSwitch.value = isFacturarYCancelar ? '1' : '0';
                renderCreditNoteChoice('factura');
                renderCreditChoice('factura', currentVencidosState.saldoFavorDisponible, currentVencidosState.currencySymbol);
                if (facturaModal) facturaModal.classList.toggle('factura-mode-expanded', isFacturarYCancelar);
                if (facturaSubmitBtn) {
                    facturaSubmitBtn.textContent = isFacturarYCancelar ? 'Guardar y Cancelar' : 'Guardar Factura';
                }

                const paymentTypeSelect = document.getElementById('factura-payment-type');
                const paymentMethodSelect = document.getElementById('factura-payment-method');
                const paymentBankSelect = document.getElementById('factura-payment-bank');
                const paymentDescTextarea = document.getElementById('payment-description');
                if (paymentTypeSelect) paymentTypeSelect.required = isFacturarYCancelar;
                if (paymentMethodSelect) paymentMethodSelect.required = isFacturarYCancelar;
                if (paymentBankSelect) paymentBankSelect.required = isFacturarYCancelar;
                if (paymentDescTextarea) paymentDescTextarea.required = false;
                document.getElementById('factura-payment-date-field')?.classList.toggle('hidden', !isFacturarYCancelar);
                const facturaPeriodEnd = document.getElementById('factura-period-end');
                if (facturaPeriodEnd) facturaPeriodEnd.required = isFacturarYCancelar;
                if (!isFacturarYCancelar && facturaPeriodEnd) {
                    facturaPeriodEnd.value = facturaPeriodEnd.dataset.basePeriodEnd || '';
                }
            }

            facturaModeToggle?.addEventListener('click', function () {
                const isFacturarYCancelar = this.dataset.mode === 'cancelar';
                applyFacturaModeState(!isFacturarYCancelar);
            });

            facturaModeToggle?.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    const isFacturarYCancelar = this.dataset.mode === 'cancelar';
                    applyFacturaModeState(!isFacturarYCancelar);
                }
            });

            const facturaFileInput = document.getElementById('factura-file');
            const facturaFileName = document.getElementById('factura-file-name');
            const facturaUploadBox = document.getElementById('factura-upload-box');

            const facturaPaymentInput = document.getElementById('factura-comprobante');
            const facturaPaymentFileName = document.getElementById('factura-payment-file-name');
            const facturaPaymentUploadBox = document.getElementById('factura-payment-upload-box');

            let currentVencidosState = {
                cxcs: [],
                baseCxcId: '',
                baseAmount: 0,
                currencySymbol: 'S/',
                clientId: '',
                serviceIds: [],
                targetModal: 'pay',
                selectedIds: [],
                esRetencion: false,
                saldoFavorDisponible: 0,
                saldoFavorCargado: false,
                errorSaldoFavor: false,
                saldoFavorMonedaId: '',
                monedaId: '',
                notasCredito: [],
                notaCreditoSeleccionadaId: '',
                notasCreditoCargadas: false,
                notasCreditoMonedaId: '',
                errorNotasCredito: false
            };
            applyFacturaModeState(false);
            let creditNotesRequestToken = 0;
            let vencidosAvailabilityRequestToken = 0;
            let pendingCreditNoteId = '';
            let creditNoteModalPrefix = 'payment';
            let saldoFavorModalPrefix = 'payment';
            const creditNotesUrlTemplate = @json(route('modules.cuentasporcobrar.cliente.notas-credito', ['id' => '__CLIENT__']));

            function resetVencidosState() {
                const btnPay = document.getElementById('btn-periodos-vencidos');
                const btnFactura = document.getElementById('btn-factura-periodos-vencidos');
                const notePay = document.getElementById('payment-select-credit-note');
                const noteFactura = document.getElementById('factura-select-credit-note');
                const saldoPay = document.getElementById('payment-select-credit-amount');
                const saldoFactura = document.getElementById('factura-select-credit-amount');
                const saldoGestion = document.getElementById('gestion-select-credit-amount');
                const noteGestion = document.getElementById('gestion-select-credit-note');
                [btnPay, btnFactura].forEach((button) => {
                    if (!button) return;
                    button.disabled = false;
                    button.textContent = 'P. vencidos';
                });
                [notePay, noteFactura, noteGestion].forEach((button) => {
                    if (!button) return;
                    button.disabled = false;
                    button.textContent = 'N. crédito';
                    button.classList.remove('hidden');
                });
                [saldoPay, saldoFactura, saldoGestion].forEach((button) => {
                    if (!button) return;
                    button.disabled = false;
                    button.textContent = 'Saldo a favor';
                    button.classList.remove('hidden');
                });
                currentVencidosState.cxcs = [];
                currentVencidosState.baseCxcId = '';
                currentVencidosState.baseAmount = 0;
                currentVencidosState.clientId = '';
                currentVencidosState.serviceIds = [];
                currentVencidosState.selectedIds = [];
                currentVencidosState.saldoFavorDisponible = 0;
                currentVencidosState.saldoFavorCargado = false;
                currentVencidosState.errorSaldoFavor = false;
                currentVencidosState.saldoFavorMonedaId = '';
                currentVencidosState.monedaId = '';
                currentVencidosState.notasCredito = [];
                currentVencidosState.notaCreditoSeleccionadaId = '';
                currentVencidosState.notasCreditoCargadas = false;
                currentVencidosState.notasCreditoMonedaId = '';
                currentVencidosState.errorNotasCredito = false;
                vencidosAvailabilityRequestToken++;
                const gestionCreditCheckbox = document.getElementById('gestion-use-credit');
                if (gestionCreditCheckbox) gestionCreditCheckbox.checked = false;
                const gestionCreditAmountInput = document.getElementById('gestion-credit-amount');
                if (gestionCreditAmountInput) gestionCreditAmountInput.value = '';
                ['factura', 'payment', 'gestion'].forEach((prefix) => {
                    const selectedInput = document.getElementById(`${prefix}-credit-note-id`);
                    if (selectedInput) {
                        selectedInput.value = '';
                        selectedInput.disabled = true;
                    }
                });
            }

            function selectedCreditNotesAmount(prefix) {
                const selectedInput = document.getElementById(`${prefix}-credit-note-id`);
                const selectedId = selectedInput?.value || '';
                const note = currentVencidosState.notasCredito.find(
                    (item) => String(item.idnotacredito) === String(selectedId)
                );
                return note ? Number(note.monto_disponible || 0) : 0;
            }

            async function loadCreditNotes(clientId, currencyId, prefix) {
                const button = document.getElementById(`${prefix}-select-credit-note`);
                const requestToken = ++creditNotesRequestToken;
                currentVencidosState.notasCredito = [];
                currentVencidosState.notaCreditoSeleccionadaId = '';
                currentVencidosState.notasCreditoCargadas = false;
                currentVencidosState.notasCreditoMonedaId = '';
                currentVencidosState.errorNotasCredito = false;
                const selectedInput = document.getElementById(`${prefix}-credit-note-id`);
                if (selectedInput) {
                    selectedInput.value = '';
                    selectedInput.disabled = true;
                }
                renderCreditNoteChoice(prefix);
                if (!button) {
                    return;
                }

                button.disabled = false;
                if (!clientId || !currencyId) {
                    currentVencidosState.notasCreditoCargadas = true;
                    renderCreditNoteChoice(prefix);
                    return;
                }
                try {
                    const endpoint = creditNotesUrlTemplate.replace('__CLIENT__', encodeURIComponent(clientId));
                    const url = new URL(endpoint, window.location.origin);
                    url.searchParams.set('moneda_idmoneda', currencyId);
                    const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);
                    const data = await response.json();
                    if (!data.success) throw new Error(data.message || 'No se pudieron consultar las notas de crédito.');
                    if (requestToken !== creditNotesRequestToken) return;

                    const notes = Array.isArray(data.notas_credito) ? data.notas_credito : [];
                    currentVencidosState.notasCredito = notes;
                    currentVencidosState.notasCreditoCargadas = true;
                    currentVencidosState.notasCreditoMonedaId = String(currencyId);
                    button.textContent = 'N. crédito';
                    renderCreditNoteChoice(prefix);
                    if (
                        creditNoteModalPrefix === prefix
                        && document.getElementById('credit-note-selector-modal')?.classList.contains('visible')
                    ) openCreditNoteModal(prefix);
                    if (prefix === 'gestion') {
                        updateGestionCreditNoteApplication();
                    } else {
                        applyDeductionLogic();
                    }
                } catch (error) {
                    if (requestToken !== creditNotesRequestToken) return;
                    currentVencidosState.errorNotasCredito = true;
                    button.disabled = false;
                    button.textContent = 'N. crédito';
                    renderCreditNoteChoice(prefix);
                    console.error('No se pudieron cargar las notas de crédito:', error);
                    if (
                        creditNoteModalPrefix === prefix
                        && document.getElementById('credit-note-selector-modal')?.classList.contains('visible')
                    ) openCreditNoteModal(prefix);
                }
            }

            function renderCreditNoteChoice(prefix) {
                const button = document.getElementById(`${prefix}-select-credit-note`);
                if (!button) return;

                const currencyMatches = Boolean(currentVencidosState.monedaId)
                    && String(currentVencidosState.notasCreditoMonedaId) === String(currentVencidosState.monedaId);
                const isAvailable = currentVencidosState.notasCreditoCargadas
                    && !currentVencidosState.errorNotasCredito
                    && currencyMatches
                    && currentVencidosState.notasCredito.length > 0;
                const modeAllows = prefix === 'factura'
                    ? facturaRealizarCobroInput?.value === '1'
                    : prefix !== 'gestion' || !gestionCompleteWithholding;
                button.classList.toggle('hidden', !modeAllows);
                if (!isAvailable) {
                    const selectedInput = document.getElementById(`${prefix}-credit-note-id`);
                    if (selectedInput) {
                        selectedInput.value = '';
                        selectedInput.disabled = true;
                    }
                    currentVencidosState.notaCreditoSeleccionadaId = '';
                }
            }

            function openCreditNoteModal(prefix) {
                const modal = document.getElementById('credit-note-selector-modal');
                const list = document.getElementById('credit-note-selector-list');
                const confirmButton = document.getElementById('credit-note-selector-confirm');
                if (!modal || !list) return;

                creditNoteModalPrefix = prefix;
                modal.classList.toggle('quotation-credit-note-modal', prefix === 'gestion');
                pendingCreditNoteId = document.getElementById(`${prefix}-credit-note-id`)?.value || '';
                const clearButton = document.getElementById('credit-note-selector-clear');
                if (clearButton) clearButton.disabled = !pendingCreditNoteId;
                list.replaceChildren();

                const addOption = (id, label, detail = '') => {
                    const wrapper = document.createElement('label');
                    wrapper.className = 'flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 text-sm hover:border-red-300 hover:bg-red-50/40 mb-2';
                    const radio = document.createElement('input');
                    radio.type = 'radio';
                    radio.name = 'credit-note-selector-choice';
                    radio.value = id;
                    radio.checked = String(pendingCreditNoteId) === String(id);
                    radio.className = 'mt-0.5 accent-[#b41B29]';
                    radio.addEventListener('change', () => {
                        pendingCreditNoteId = radio.value;
                        if (confirmButton) confirmButton.disabled = false;
                    });
                    const text = document.createElement('span');
                    text.className = 'flex min-w-0 flex-col gap-0.5';
                    const title = document.createElement('strong');
                    title.className = 'text-slate-800';
                    title.textContent = label;
                    text.appendChild(title);
                    if (detail) {
                        const description = document.createElement('span');
                        description.className = 'text-xs text-slate-500';
                        description.textContent = detail;
                        text.appendChild(description);
                    }
                    wrapper.append(radio, text);
                    list.appendChild(wrapper);
                };

                if (!currentVencidosState.notasCreditoCargadas) {
                    const message = document.createElement('p');
                    message.className = 'rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600';
                    message.textContent = currentVencidosState.errorNotasCredito
                        ? 'No se pudieron cargar las notas de crédito. Cierra y vuelve a abrir el cobro para intentar nuevamente.'
                        : 'Cargando notas de crédito...';
                    list.appendChild(message);
                } else if (currentVencidosState.notasCredito.length === 0) {
                    const message = document.createElement('p');
                    message.className = 'rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600';
                    message.textContent = 'Este cliente no tiene notas de crédito disponibles.';
                    list.appendChild(message);
                } else {
                    addOption('', 'No aplicar nota de crédito');
                    currentVencidosState.notasCredito.forEach((note) => {
                        const reference = note.docReferencia || `Nota #${note.idnotacredito}`;
                        const details = [
                            `Disponible: ${formatMoney(Number(note.monto_disponible || 0), currentVencidosState.currencySymbol)}`,
                            note.descripcion ? `Comentario: ${note.descripcion}` : ''
                        ].filter(Boolean).join(' · ');
                        addOption(String(note.idnotacredito), reference, details);
                    });
                }

                if (confirmButton) {
                    confirmButton.disabled = !currentVencidosState.notasCreditoCargadas
                        || currentVencidosState.notasCredito.length === 0;
                }
                modal.classList.remove('hidden');
                modal.classList.add('visible');
            }

            function closeCreditNoteModal() {
                const modal = document.getElementById('credit-note-selector-modal');
                modal?.classList.remove('visible');
                modal?.classList.add('hidden');
                modal?.classList.remove('quotation-credit-note-modal');
            }

            function selectedBankCreditAmount(prefix, available) {
                const checkbox = document.getElementById(`${prefix}-use-credit`);
                if (!checkbox?.checked) return 0;
                const amount = Number(document.getElementById(`${prefix}-credit-amount`)?.value || available);
                return Math.min(Math.max(amount, 0), Math.max(Number(available) || 0, 0));
            }

            function openSaldoFavorModal(prefix) {
                const modal = document.getElementById('saldo-favor-selector-modal');
                const amountInput = document.getElementById('saldo-favor-selector-amount');
                const availableLabel = document.getElementById('saldo-favor-selector-available');
                const noBalanceMessage = document.getElementById('saldo-favor-selector-empty');
                const amountField = document.getElementById('saldo-favor-selector-amount-field');
                const applyButton = document.getElementById('saldo-favor-selector-apply');
                const clearButton = document.getElementById('saldo-favor-selector-clear');
                if (!modal || !amountInput) return;

                saldoFavorModalPrefix = prefix;
                const available = Math.max(Number(currentVencidosState.saldoFavorDisponible) || 0, 0);
                const isFactura = prefix === 'factura';
                const modalElement = isFactura
                    ? facturaModal
                    : (prefix === 'gestion' ? gestionModal : servicePaymentModal);
                const gross = Number(modalElement?.dataset.grossAmount || currentVencidosState.baseAmount || 0);
                const noteAmount = selectedCreditNotesAmount(prefix);
                const afterDeduction = prefix === 'gestion'
                    ? Math.max(0, gestionOriginalBalance - gestionDeductionAmount - noteAmount)
                    : calculateNetPayable(gross, currentVencidosState.esRetencion);
                const existingBankCredit = selectedBankCreditAmount(prefix, available);
                const maxAmount = Math.min(
                    available,
                    Math.max(
                        afterDeduction
                        - (prefix === 'gestion' ? 0 : noteAmount)
                        + (prefix === 'gestion' ? existingBankCredit : 0),
                        0
                    )
                );
                const existingAmount = existingBankCredit;
                const hasApplicableBalance = currentVencidosState.saldoFavorCargado
                    && !currentVencidosState.errorSaldoFavor
                    && maxAmount > 0;

                if (availableLabel) {
                    availableLabel.textContent = currentVencidosState.errorSaldoFavor
                        ? 'No se pudo consultar el saldo a favor. Cierra y vuelve a abrir el cobro para intentar nuevamente.'
                        : currentVencidosState.saldoFavorCargado
                            ? `Disponible: ${formatMoney(available, currentVencidosState.currencySymbol)}`
                            : 'Consultando saldo a favor...';
                }
                if (noBalanceMessage) {
                    noBalanceMessage.textContent = !currentVencidosState.saldoFavorCargado
                        ? ''
                        : available <= 0
                            ? 'Este cliente no tiene saldo a favor disponibles.'
                            : 'No hay saldo a favor disponible para aplicar a este cobro.';
                    noBalanceMessage.classList.toggle('hidden', !currentVencidosState.saldoFavorCargado || hasApplicableBalance);
                }
                amountField?.classList.toggle('hidden', !hasApplicableBalance);
                amountInput.max = maxAmount.toFixed(2);
                amountInput.value = hasApplicableBalance
                    ? (existingAmount > 0 ? Math.min(existingAmount, maxAmount) : 0).toFixed(2)
                    : '0.00';
                amountInput.dataset.currencySymbol = currentVencidosState.currencySymbol || 'S/';
                amountInput.disabled = !hasApplicableBalance;
                if (applyButton) applyButton.disabled = !hasApplicableBalance;
                if (clearButton) clearButton.disabled = existingBankCredit <= 0;
                modal.classList.remove('hidden');
                modal.classList.add('visible');
            }

            function closeSaldoFavorModal() {
                const modal = document.getElementById('saldo-favor-selector-modal');
                modal?.classList.remove('visible');
                modal?.classList.add('hidden');
            }

            function confirmSaldoFavorSelection() {
                const prefix = saldoFavorModalPrefix;
                const amountInput = document.getElementById('saldo-favor-selector-amount');
                const checkbox = document.getElementById(`${prefix}-use-credit`);
                const amountField = document.getElementById(`${prefix}-credit-amount`);
                if (!amountInput || !checkbox || !amountField) return;

                const amount = Math.max(Number(amountInput.value || 0), 0);
                const maxAmount = Math.max(Number(amountInput.max || 0), 0);
                if (!Number.isFinite(amount) || amount > maxAmount + 0.001) {
                    amountInput.setCustomValidity('El monto no puede superar el saldo disponible para aplicar.');
                    amountInput.reportValidity();
                    return;
                }
                amountInput.setCustomValidity('');
                checkbox.checked = amount > 0;
                amountField.value = amount > 0 ? amount.toFixed(2) : '';
                const button = document.getElementById(`${prefix}-select-credit-amount`);
                if (button) {
                    button.textContent = amount > 0
                        ? `Saldo a favor: ${formatMoney(amount, currentVencidosState.currencySymbol)}`
                        : 'Saldo a favor';
                }
                closeSaldoFavorModal();
                if (prefix === 'gestion') {
                    setGestionAmountToBalance();
                } else {
                    applyDeductionLogic();
                }
            }

            function clearSaldoFavorSelection() {
                const prefix = saldoFavorModalPrefix;
                const checkbox = document.getElementById(`${prefix}-use-credit`);
                const amountField = document.getElementById(`${prefix}-credit-amount`);
                const amountInput = document.getElementById('saldo-favor-selector-amount');
                if (!checkbox || !amountField) return;

                checkbox.checked = false;
                amountField.value = '';
                if (amountInput) amountInput.value = '0.00';
                const button = document.getElementById(`${prefix}-select-credit-amount`);
                if (button) button.textContent = 'Saldo a favor';
                closeSaldoFavorModal();
                if (prefix === 'gestion') {
                    setGestionAmountToBalance();
                } else {
                    applyDeductionLogic();
                }
            }

            function confirmCreditNoteSelection() {
                const prefix = creditNoteModalPrefix;
                const selectedInput = document.getElementById(`${prefix}-credit-note-id`);
                if (!selectedInput) return;
                const selectedRadio = document.querySelector('input[name="credit-note-selector-choice"]:checked');
                const selectedId = selectedRadio?.value || '';
                selectedInput.value = selectedId;
                selectedInput.disabled = !selectedId;
                currentVencidosState.notaCreditoSeleccionadaId = selectedId;
                const button = document.getElementById(`${prefix}-select-credit-note`);
                const selectedNote = currentVencidosState.notasCredito.find(
                    (note) => String(note.idnotacredito) === String(selectedId)
                );
                if (button) {
                    button.textContent = selectedNote
                        ? `N. crédito: ${selectedNote.docReferencia || `#${selectedNote.idnotacredito}`}`
                        : 'N. crédito';
                }
                closeCreditNoteModal();
                if (prefix === 'gestion') {
                    updateGestionCreditNoteApplication();
                } else {
                    applyDeductionLogic();
                }
            }

            function clearCreditNoteSelection() {
                const prefix = creditNoteModalPrefix;
                const selectedInput = document.getElementById(`${prefix}-credit-note-id`);
                if (!selectedInput) return;
                selectedInput.value = '';
                selectedInput.disabled = true;
                currentVencidosState.notaCreditoSeleccionadaId = '';
                const button = document.getElementById(`${prefix}-select-credit-note`);
                if (button) button.textContent = 'N. crédito';
                closeCreditNoteModal();
                if (prefix === 'gestion') {
                    updateGestionCreditNoteApplication();
                } else {
                    applyDeductionLogic();
                }
            }

            function calculateNetPayable(grossTotal, isRetencion, allowRetentionBelowThreshold = false) {
                grossTotal = Number(grossTotal) || 0;
                return Math.round((grossTotal - calculateDeduction(grossTotal, isRetencion, allowRetentionBelowThreshold) + Number.EPSILON) * 100) / 100;
            }

            function calculateDeduction(grossTotal, isRetencion, allowRetentionBelowThreshold = false) {
                grossTotal = Number(grossTotal) || 0;
                if (grossTotal < 700 && !(isRetencion && allowRetentionBelowThreshold)) return 0;
                const deduction = grossTotal * (isRetencion ? 0.03 : 0.12);
                return isRetencion && allowRetentionBelowThreshold
                    ? Math.round((deduction + Number.EPSILON) * 100) / 100
                    : Math.round(deduction + Number.EPSILON);
            }

            function clientHasRetention(clientId) {
                const clientButton = Array.from(document.querySelectorAll('[data-client-id][data-client-retention]'))
                    .find((element) => String(element.dataset.clientId) === String(clientId));
                return clientButton?.dataset.clientRetention === '1';
            }

            function renderDeductionSummary(elementId, grossTotal, deductionAmount, noteCreditApplied, bankCreditApplied, isRetencion, symbol) {
                const summary = document.getElementById(elementId);
                if (!summary) return;

                if (deductionAmount <= 0 && noteCreditApplied <= 0 && bankCreditApplied <= 0) {
                    summary.textContent = '';
                    summary.classList.add('hidden');
                    return;
                }

                const parts = [`Total: ${formatMoney(grossTotal, symbol)}`];
                if (deductionAmount > 0) {
                    const deductionName = isRetencion ? 'Retención (3%)' : 'Detracción (12%)';
                    parts.push(`${deductionName}: ${formatMoney(deductionAmount, symbol)}`);
                }
                if (bankCreditApplied > 0) parts.push(`Saldo a favor aplicado: ${formatMoney(bankCreditApplied, symbol)}`);
                if (noteCreditApplied > 0) parts.push(`Nota de crédito aplicada: ${formatMoney(noteCreditApplied, symbol)}`);
                summary.textContent = parts.join(' | ');
                summary.classList.remove('hidden');
            }

            function advancePeriodEndDate(periodEnd, coveredPeriods) {
                if (!periodEnd || coveredPeriods <= 1) return periodEnd;

                const [year, month, day] = periodEnd.split('-').map(Number);
                if (!year || !month || !day) return periodEnd;

                const targetMonth = month - 1 + coveredPeriods - 1;
                const targetYear = year + Math.floor(targetMonth / 12);
                const normalizedMonth = (targetMonth % 12) + 1;
                const lastDay = new Date(Date.UTC(targetYear, normalizedMonth, 0)).getUTCDate();

                return [
                    targetYear,
                    String(normalizedMonth).padStart(2, '0'),
                    String(Math.min(day, lastDay)).padStart(2, '0')
                ].join('-');
            }

            function syncAdvancePeriodEnd(input, basePeriodEnd, coveredPeriods) {
                if (input && basePeriodEnd) {
                    input.value = advancePeriodEndDate(basePeriodEnd, coveredPeriods);
                }
            }

            function selectedMonthlyServiceAmount() {
                const serviceIds = [...new Set(currentVencidosState.serviceIds.map(String))];
                if (serviceIds.length === 0) return Number(currentVencidosState.baseAmount) || 0;

                const monthlyAmounts = new Map();
                document.querySelectorAll('.btn-pay-vehicle[data-service-id]').forEach((button) => {
                    const serviceId = String(button.dataset.serviceId || '');
                    const rawAmount = button.dataset.amount;
                    if (!serviceId || rawAmount === undefined || rawAmount.trim() === '') return;
                    const amount = Number(rawAmount);
                    if (Number.isFinite(amount) && amount >= 0) monthlyAmounts.set(serviceId, amount);
                });

                if (!serviceIds.every((serviceId) => monthlyAmounts.has(serviceId))) {
                    return Number(currentVencidosState.baseAmount) || 0;
                }

                return serviceIds.reduce((total, serviceId) => total + monthlyAmounts.get(serviceId), 0);
            }

            function applyDeductionLogic() {
                let selectedAmount = 0;
                currentVencidosState.selectedIds.forEach(id => {
                    const cxc = currentVencidosState.cxcs.find(c => String(c.idcuentasPorCobrar) === String(id));
                    if (cxc) {
                        selectedAmount += parseFloat(cxc.montoActual || 0);
                    }
                });

                const isFacturaTarget = currentVencidosState.targetModal === 'factura';
                const advancePeriods = isFacturaTarget
                    ? Number(facturaAdvanceMonths?.value || 0)
                    : Number(paymentAdvanceMonthsCount?.value || 0);
                const invoiceUnitAmount = Number(currentVencidosState.baseAmount) || 0;
                const monthlyServiceAmount = selectedMonthlyServiceAmount();
                const invoiceAdvanceTotal = invoiceUnitAmount
                    + monthlyServiceAmount * Math.max(0, advancePeriods - 1);
                const grossTotal = selectedAmount + invoiceAdvanceTotal;
                const isRetencion = !!currentVencidosState.esRetencion;
                const deductionAmount = calculateDeduction(grossTotal, isRetencion);
                const afterDeduction = calculateNetPayable(grossTotal, isRetencion);
                const symbol = currentVencidosState.currencySymbol || 'S/';
                const useCreditCheckbox = document.getElementById(currentVencidosState.targetModal === 'pay' ? 'payment-use-credit' : 'factura-use-credit');
                const prefix = currentVencidosState.targetModal === 'pay' ? 'payment' : 'factura';
                const bankCreditSelected = !!useCreditCheckbox?.checked;
                const selectedNotesAmount = selectedCreditNotesAmount(prefix);
                const availableBankCredit = bankCreditSelected
                    ? selectedBankCreditAmount(prefix, currentVencidosState.saldoFavorDisponible)
                    : 0;
                const creditApplied = Math.min(afterDeduction, availableBankCredit + selectedNotesAmount);
                const noteCreditApplied = Math.min(creditApplied, selectedNotesAmount);
                const bankCreditApplied = Math.max(0, creditApplied - noteCreditApplied);
                const cashDue = Math.max(afterDeduction - creditApplied, 0);

                if (currentVencidosState.targetModal === 'pay') {
                    if (servicePaymentModal) {
                        servicePaymentModal.dataset.grossAmount = String(grossTotal);
                        servicePaymentModal.dataset.deductionAmount = String(deductionAmount);
                    }
                    renderDeductionSummary('payment-amount-summary', grossTotal, deductionAmount, noteCreditApplied, bankCreditApplied, isRetencion, symbol);
                    renderCreditChoice('payment', currentVencidosState.saldoFavorDisponible, symbol);
                    const paymentOriginal = document.getElementById('payment-original');
                    const paymentAmount = document.getElementById('payment-amount');
                    const paymentInvoiceTotal = document.getElementById('payment-invoice-total');
                    const totalRadio = document.querySelector('#service-payment-modal input[name="modo_pago"][value="total"]');
                    const partialRadio = document.querySelector('#service-payment-modal input[name="modo_pago"][value="parcial"]');
                    const expectedBefore = Number(servicePaymentModal?.dataset.expectedCashDue ?? currentVencidosState.baseAmount);
                    const enteredAmount = Number(paymentAmount?.value || 0);
                    const shouldRefreshAmount = totalRadio?.checked || !paymentAmount?.value || Math.abs(enteredAmount - expectedBefore) < 0.01;
                    const manualAmount = paymentAmountWasEdited ? Number(paymentOriginal?.value || 0) : null;
                    const hasWithholding = deductionAmount > 0;
                    const fullPaymentThreshold = hasWithholding ? grossTotal : cashDue;
                    const isManualPartial = advancePeriods <= 1 && (
                        manualAmount !== null
                            ? manualAmount + 0.01 < fullPaymentThreshold
                            : (hasWithholding ? cashDue + 0.01 < fullPaymentThreshold : false)
                    );
                    if (advancePeriods > 1 || manualAmount !== null || hasWithholding) {
                        if (totalRadio) totalRadio.checked = !isManualPartial;
                        if (partialRadio) partialRadio.checked = isManualPartial;
                        partialPaymentField?.classList.toggle('hidden', !isManualPartial);
                        if (paymentAmount) paymentAmount.required = isManualPartial;
                    }
                    if (paymentOriginal && !paymentAmountWasEdited) paymentOriginal.value = cashDue.toFixed(2);
                    if (paymentOriginal && advancePeriods > 1) paymentOriginal.value = cashDue.toFixed(2);
                    if (paymentOriginalCurrency) paymentOriginalCurrency.textContent = symbol;
                    if (paymentAmountCurrency) paymentAmountCurrency.textContent = symbol;
                    if (paymentAmount && manualAmount !== null && advancePeriods <= 1) {
                        paymentAmount.value = manualAmount > 0 ? manualAmount.toFixed(2) : '';
                    } else if (paymentAmount && (shouldRefreshAmount || advancePeriods > 1 || hasWithholding)) {
                        paymentAmount.value = cashDue.toFixed(2);
                    }
                    if (paymentInvoiceTotal) {
                        paymentInvoiceTotal.value = Math.max(0, invoiceAdvanceTotal).toFixed(2);
                    }
                    if (paymentAdvanceMonths && !paymentAdvanceFields?.classList.contains('hidden')) {
                        paymentAdvanceMonths.value = advancePeriods > 1 ? String(advancePeriods) : '';
                    }
                    const displayedAdvancePeriods = advancePeriods > 1
                        ? advancePeriods
                        : (paymentAdvanceFields?.classList.contains('hidden') ? Number(paymentAdvanceMonths?.value || 0) : 0);
                    if (paymentAdvanceSummary) {
                        paymentAdvanceSummary.textContent = displayedAdvancePeriods > 1
                            ? `Este monto incluye ${displayedAdvancePeriods} mensualidades (periodo actual y meses adelantados).`
                            : '';
                        paymentAdvanceSummary.classList.toggle('hidden', displayedAdvancePeriods <= 1);
                    }
                    if (servicePaymentModal) {
                        servicePaymentModal.dataset.expectedCashDue = String(cashDue);
                        servicePaymentModal.dataset.grossAmount = String(grossTotal);
                        servicePaymentModal.dataset.deductionAmount = String(deductionAmount);
                    }
                    const paymentGross = grossTotal > 0 ? grossTotal : cashDue;
                    const paymentPaidVal = totalRadio?.checked ? paymentGross : (Number(paymentAmount?.value) || 0);
                    updateBalanceAndCreditDisplay(paymentGross, paymentPaidVal, 'payment-balance', 'payment-credit-summary', symbol, cashDue, deductionAmount);
                    if (document.querySelector('input[name="payment_currency_mode"]:checked')?.value === 'other') {
                        updateConvertedPaymentAmount();
                    }
                } else if (currentVencidosState.targetModal === 'factura') {
                    if (facturaModal) {
                        facturaModal.dataset.grossAmount = String(grossTotal);
                        facturaModal.dataset.deductionAmount = String(deductionAmount);
                    }
                    renderDeductionSummary('factura-amount-summary', grossTotal, deductionAmount, noteCreditApplied, bankCreditApplied, isRetencion, symbol);
                    renderCreditChoice('factura', currentVencidosState.saldoFavorDisponible, symbol);
                    const facturaAmount = document.getElementById('factura-amount');
                    const totalRadio = facturaModal?.querySelector('input[name="factura_modo_pago"][value="total"]');
                    const partialRadio = facturaModal?.querySelector('input[name="factura_modo_pago"][value="parcial"]');
                    const partialAmount = document.getElementById('factura-partial-payment-amount');
                    const hasCreditInstallmentPlan = isCreditPayment(facturaPaymentMethod)
                        && Number(facturaCanCuotas?.value || 0) > 0;
                    const firstCreditInstallment = hasCreditInstallmentPlan
                        ? Number(facturaInstallments?.querySelector('input[name="factura_cuotas_montos[]"]')?.value || 0)
                        : 0;
                    const expectedBefore = Number(facturaModal?.dataset.expectedCashDue ?? currentVencidosState.baseAmount);
                    const enteredAmount = Number(partialAmount?.value || 0);
                    const shouldRefreshAmount = totalRadio?.checked || !partialAmount?.value || Math.abs(enteredAmount - expectedBefore) < 0.01;
                    const isManualAmountBlank = facturaAmountWasEdited && facturaAmountDisplay?.value === '';

                    const manualAmount = facturaAmountWasEdited && !isManualAmountBlank
                        ? Number(facturaAmountDisplay?.value || 0)
                        : null;
                    const hasWithholding = deductionAmount > 0;
                    const fullPaymentThreshold = hasWithholding ? grossTotal : cashDue;
                    const visibleDue = advancePeriods > 1 || manualAmount === null ? cashDue : manualAmount;
                    const isPartialPayment = hasCreditInstallmentPlan || (advancePeriods <= 1 && (
                        manualAmount !== null
                            ? manualAmount + 0.01 < fullPaymentThreshold
                            : (hasWithholding ? cashDue + 0.01 < fullPaymentThreshold : false)
                    ));
                    if (totalRadio) totalRadio.checked = !isPartialPayment;
                    if (partialRadio) partialRadio.checked = isPartialPayment;
                    const partialField = document.getElementById('factura-partial-payment-field');
                    partialField?.classList.toggle('hidden', !isPartialPayment);

                    if (facturaAmount && !facturaAmountWasEdited) facturaAmount.value = visibleDue.toFixed(2);
                    const invoiceAmountInput = document.getElementById('factura-invoice-amount');
                    if (invoiceAmountInput && !isManualAmountBlank) {
                        invoiceAmountInput.value = Math.max(0, invoiceAdvanceTotal).toFixed(2);
                    }
                    if (facturaAmountCurrency) facturaAmountCurrency.textContent = symbol;
                    if (facturaPartialAmountCurrency) facturaPartialAmountCurrency.textContent = symbol;
                    if (facturaModal) facturaModal.dataset.payableAmount = isManualAmountBlank ? '' : String(visibleDue);
                    if (partialAmount && hasCreditInstallmentPlan) {
                        partialAmount.value = firstCreditInstallment > 0 ? firstCreditInstallment.toFixed(2) : '';
                    } else if (partialAmount && !isManualAmountBlank && (shouldRefreshAmount || manualAmount !== null || hasWithholding)) {
                        partialAmount.value = manualAmount !== null ? facturaAmountDisplay.value : visibleDue.toFixed(2);
                    }
                    if (facturaModal) {
                        facturaModal.dataset.expectedCashDue = String(cashDue);
                        facturaModal.dataset.grossAmount = String(grossTotal);
                        facturaModal.dataset.deductionAmount = String(deductionAmount);
                    }
                    const facturaAdvanceSummary = document.getElementById('factura-advance-summary');
                    if (facturaAdvanceSummary) {
                        facturaAdvanceSummary.textContent = advancePeriods > 1
                            ? `Este monto incluye ${advancePeriods} mensualidades (periodo actual y meses adelantados).`
                            : '';
                        facturaAdvanceSummary.classList.toggle('hidden', advancePeriods <= 1);
                    }
                    const facturaGross = grossTotal > 0 ? grossTotal : cashDue;
                    const facturaPaidVal = hasCreditInstallmentPlan
                        ? firstCreditInstallment
                        : advancePeriods > 0
                        ? Math.min(Number(facturaAmount?.value || 0), cashDue)
                        : (totalRadio?.checked
                            ? (Number(facturaAmount?.value) || facturaGross)
                            : (Number(partialAmount?.value) || 0));
                    updateBalanceAndCreditDisplay(
                        facturaGross,
                        facturaPaidVal,
                        'factura-payment-balance',
                        'factura-payment-credit-summary',
                        symbol,
                        cashDue,
                        deductionAmount
                    );
                    if (advancePeriods > 0) {
                        document.getElementById('factura-payment-credit-summary')?.classList.add('hidden');
                    }
                    if (document.querySelector('input[name="factura_payment_currency_mode"]:checked')?.value === 'other') {
                        calcFacturaCurrencyConversion();
                    }
                }
            }

            function renderCreditChoice(prefix, available, symbol) {
                const checkbox = document.getElementById(`${prefix}-use-credit`);
                const selectButton = document.getElementById(`${prefix}-select-credit-amount`);
                if (!checkbox) return;

                const currencyMatches = Boolean(currentVencidosState.monedaId)
                    && String(currentVencidosState.saldoFavorMonedaId) === String(currentVencidosState.monedaId);
                const isAvailable = currentVencidosState.saldoFavorCargado
                    && !currentVencidosState.errorSaldoFavor
                    && currencyMatches
                    && Number(available) > 0;
                const modeAllows = prefix === 'factura'
                    ? facturaRealizarCobroInput?.value === '1'
                    : prefix !== 'gestion' || !gestionCompleteWithholding;
                if (!isAvailable) {
                    checkbox.checked = false;
                    const amountField = document.getElementById(`${prefix}-credit-amount`);
                    if (amountField) amountField.value = '';
                } else if (checkbox.checked) {
                    const amountField = document.getElementById(`${prefix}-credit-amount`);
                    if (amountField) {
                        const requestedAmount = Number(amountField.value || 0);
                        amountField.value = Math.min(Math.max(requestedAmount, 0), available).toFixed(2);
                    }
                }
                if (selectButton) {
                    selectButton.disabled = false;
                    selectButton.classList.toggle('hidden', !modeAllows);
                    selectButton.textContent = checkbox.checked
                        ? `Saldo a favor: ${formatMoney(selectedBankCreditAmount(prefix, available), symbol)}`
                        : 'Saldo a favor';
                }
            }

            function syncFacturaAdvanceAmount() {
                const rawAmount = String(facturaAmountDisplay?.value ?? '');
                const isBlank = rawAmount.trim() === '';
                const amount = isBlank ? 0 : Math.max(0, Number(rawAmount) || 0);
                facturaAmountWasEdited = true;
                const advancePeriods = Number(facturaAdvanceMonths?.value || 0);
                const symbol = facturaModal?.dataset.currencySymbol || 'S/';
                if (facturaAmountCurrency) facturaAmountCurrency.textContent = symbol;
                if (facturaPartialAmountCurrency) facturaPartialAmountCurrency.textContent = symbol;
                if (facturaModal) facturaModal.dataset.payableAmount = isBlank ? '' : String(amount);
                const partialAmount = document.getElementById('factura-partial-payment-amount');
                if (partialAmount) partialAmount.value = isBlank ? '' : rawAmount;
                const totalRadio = facturaModal?.querySelector('input[name="factura_modo_pago"][value="total"]');
                const partialRadio = facturaModal?.querySelector('input[name="factura_modo_pago"][value="parcial"]');
                const partialField = document.getElementById('factura-partial-payment-field');
                if (advancePeriods > 0) {
                    if (totalRadio) totalRadio.checked = true;
                    if (partialRadio) partialRadio.checked = false;
                    partialField?.classList.add('hidden');
                } else {
                    const grossAmount = Number(facturaModal?.dataset.grossAmount || 0);
                    const cashDue = Number(facturaModal?.dataset.expectedCashDue || 0);
                    const hasWithholding = grossAmount > 0 && Math.abs(grossAmount - cashDue) > 0.01;
                    const amountToPayInFull = hasWithholding ? grossAmount : (cashDue || grossAmount);
                    const isPartialPayment = amount + 0.01 < amountToPayInFull;
                    if (partialRadio) partialRadio.checked = isPartialPayment;
                    if (totalRadio) totalRadio.checked = !isPartialPayment;
                    partialField?.classList.toggle('hidden', !isPartialPayment);
                }
                applyDeductionLogic();
            }

            function syncFacturaAdvancePaymentMode() {
                const advancePeriods = Number(facturaAdvanceMonths?.value || 0);
                const facturaPeriodEnd = document.getElementById('factura-period-end');
                syncAdvancePeriodEnd(
                    facturaPeriodEnd,
                    facturaPeriodEnd?.dataset.basePeriodEnd || '',
                    advancePeriods
                );
                const totalRadio = document.querySelector('input[name="factura_modo_pago"][value="total"]');
                const partialRadio = document.querySelector('input[name="factura_modo_pago"][value="parcial"]');
                const partialField = document.getElementById('factura-partial-payment-field');
                if (advancePeriods > 1) {
                    if (totalRadio) totalRadio.checked = true;
                    if (partialRadio) partialRadio.checked = false;
                    partialField?.classList.add('hidden');
                } else {
                    if (partialRadio) partialRadio.checked = true;
                    if (totalRadio) totalRadio.checked = false;
                    partialField?.classList.remove('hidden');
                }
                applyDeductionLogic();
            }

            facturaAdvanceToggle?.addEventListener('click', function () {
                const opening = facturaAdvanceFields?.classList.contains('hidden');
                facturaAdvanceFields?.classList.toggle('hidden', !opening);
                this.textContent = opening ? 'Quitar adelantado' : 'Pagar adelantado';
                this.setAttribute('aria-expanded', opening ? 'true' : 'false');
                if (facturaAdvanceMonths) facturaAdvanceMonths.disabled = !opening;
                if (opening) {
                    facturaAdvanceMonths?.focus();
                } else {
                    facturaAdvanceMonths.value = '';
                    syncFacturaAdvancePaymentMode();
                }
            });
            facturaAmountDisplay?.addEventListener('input', function () {
                this.setCustomValidity('');
                syncFacturaAdvanceAmount();
            });
            facturaAdvanceMonths?.addEventListener('input', function () {
                this.setCustomValidity(this.value && (Number(this.value) < 1 || Number(this.value) > 120)
                    ? 'Indica entre 1 y 120 mensualidades.'
                    : '');
                syncFacturaAdvancePaymentMode();
            });

            paymentAdvanceMonthsCount?.addEventListener('input', function () {
                this.setCustomValidity(this.value && (Number(this.value) < 1 || Number(this.value) > 120)
                    ? 'Indica entre 1 y 120 mensualidades.'
                    : '');
                const paymentPeriodEnd = document.getElementById('payment-period-end');
                syncAdvancePeriodEnd(
                    paymentPeriodEnd,
                    paymentPeriodEnd?.dataset.basePeriodEnd || '',
                    Number(this.value || 0)
                );
                if (paymentAdvanceMonths) {
                    paymentAdvanceMonths.value = Number(this.value) > 1 && Number(this.value) <= 120
                        ? String(Number(this.value))
                        : '';
                }

                if (Number(this.value) > 1) {
                    const totalRadio = document.querySelector('#service-payment-modal input[name="modo_pago"][value="total"]');
                    const partialRadio = document.querySelector('#service-payment-modal input[name="modo_pago"][value="parcial"]');
                    if (totalRadio) totalRadio.checked = true;
                    if (partialRadio) partialRadio.checked = false;
                    partialPaymentField?.classList.add('hidden');
                    if (paymentAmount) paymentAmount.required = false;
                }
                applyDeductionLogic();
            });

            paymentAdvanceToggle?.addEventListener('click', function () {
                const opening = paymentAdvanceFields?.classList.contains('hidden');
                paymentAdvanceFields?.classList.toggle('hidden', !opening);
                this.textContent = opening ? 'Quitar adelantado' : 'Pagar adelantado';
                this.setAttribute('aria-expanded', opening ? 'true' : 'false');
                if (paymentAdvanceMonthsCount) {
                    paymentAdvanceMonthsCount.disabled = !opening;
                    paymentAdvanceMonthsCount.required = opening;
                }

                if (opening) {
                    paymentAdvanceMonthsCount?.focus();
                    return;
                }

                if (paymentAdvanceMonthsCount) {
                    paymentAdvanceMonthsCount.value = '';
                    paymentAdvanceMonthsCount.setCustomValidity('');
                }
                if (paymentAdvanceMonths) paymentAdvanceMonths.value = '';
                const paymentPeriodEnd = document.getElementById('payment-period-end');
                if (paymentPeriodEnd) {
                    paymentPeriodEnd.value = paymentPeriodEnd.dataset.basePeriodEnd || '';
                }
                paymentAmountWasEdited = false;
                const totalRadio = document.querySelector('#service-payment-modal input[name="modo_pago"][value="total"]');
                const partialRadio = document.querySelector('#service-payment-modal input[name="modo_pago"][value="parcial"]');
                if (totalRadio) totalRadio.checked = true;
                if (partialRadio) partialRadio.checked = false;
                partialPaymentField?.classList.add('hidden');
                if (paymentAmount) paymentAmount.required = false;
                if (paymentOriginal) paymentOriginal.value = Number(currentVencidosState.baseAmount || 0).toFixed(2);
                applyDeductionLogic();
            });

            paymentOriginal?.addEventListener('input', function () {
                this.setCustomValidity('');
                paymentAmountWasEdited = true;
                const enteredAmount = Number(this.value || 0);
                const advancePeriods = Math.max(1, Number(paymentAdvanceMonthsCount?.value || 0));
                const isOtherCurrency = document.querySelector('input[name="payment_currency_mode"]:checked')?.value === 'other';

                if (isOtherCurrency) {
                    applyDeductionLogic();
                    return;
                }

                const grossAmount = Number(servicePaymentModal?.dataset.grossAmount || 0);
                const cashDue = Number(servicePaymentModal?.dataset.expectedCashDue || currentVencidosState.baseAmount || 0);
                const hasWithholding = grossAmount > 0 && Math.abs(grossAmount - cashDue) > 0.01;
                const fullThreshold = hasWithholding ? grossAmount : cashDue;
                const isPartial = enteredAmount + 0.01 < fullThreshold && advancePeriods <= 1;

                const totalRadio = document.querySelector('#service-payment-modal input[name="modo_pago"][value="total"]');
                const partialRadio = document.querySelector('#service-payment-modal input[name="modo_pago"][value="parcial"]');
                if (totalRadio) totalRadio.checked = !isPartial;
                if (partialRadio) partialRadio.checked = isPartial;
                partialPaymentField?.classList.toggle('hidden', !isPartial);
                if (paymentAmount) {
                    paymentAmount.value = enteredAmount > 0 ? enteredAmount.toFixed(2) : '';
                    paymentAmount.required = isPartial;
                }

                if (advancePeriods > 1) {
                    applyDeductionLogic();
                    return;
                }

                const deduction = Number(servicePaymentModal?.dataset.deductionAmount || 0);
                const baseGross = grossAmount > 0 ? grossAmount : cashDue;
                updateBalanceAndCreditDisplay(
                    baseGross,
                    enteredAmount,
                    'payment-balance',
                    'payment-credit-summary',
                    currentVencidosState.currencySymbol || 'S/',
                    cashDue,
                    deduction
                );
            });

            if (paymentAdvanceMonthsCount) paymentAdvanceMonthsCount.disabled = true;

            function checkAndRenderVencidosButton(clientId, currentCxcId, baseAmount, currencySymbol, targetModal, serviceIds = [], clientName = '', currencyId = '') {
                resetVencidosState();
                const targetForm = document.getElementById(targetModal === 'pay' ? 'service-payment-form' : 'service-factura-form');
                targetForm?.querySelectorAll('input[name="period_cxc_ids[]"]').forEach((input) => input.remove());

                currentVencidosState = {
                    cxcs: [],
                    baseCxcId: currentCxcId || '',
                    baseAmount: Number(baseAmount) || 0,
                    currencySymbol: currencySymbol || 'S/',
                    clientName: clientName || '',
                    clientId: clientId || '',
                    serviceIds: serviceIds.map(String),
                    targetModal: targetModal,
                    selectedIds: [],
                    esRetencion: clientHasRetention(clientId),
                    saldoFavorDisponible: 0,
                    saldoFavorCargado: false,
                    errorSaldoFavor: false,
                    saldoFavorMonedaId: '',
                    monedaId: currencyId || '',
                    notasCredito: [],
                    notaCreditoSeleccionadaId: '',
                    notasCreditoCargadas: false,
                    notasCreditoMonedaId: '',
                    errorNotasCredito: false
                };

                applyDeductionLogic();
                loadSaldoFavorDisponibilidad();
                loadCreditNotes(clientId, currencyId, targetModal === 'pay' ? 'payment' : 'factura');
            }

            async function loadSaldoFavorDisponibilidad() {
                const clientId = currentVencidosState.clientId;
                if (!clientId) {
                    currentVencidosState.saldoFavorCargado = true;
                    if (currentVencidosState.targetModal === 'gestion') {
                        updateGestionBalanceSummary();
                    }
                    return;
                }
                const requestToken = ++vencidosAvailabilityRequestToken;
                const query = new URLSearchParams({ current_cxc_id: currentVencidosState.baseCxcId || '' });
                currentVencidosState.serviceIds.forEach((id) => query.append('service_ids[]', id));

                try {
                    const response = await fetch(
                        `/modulos/cuentas-por-cobrar/cliente/${encodeURIComponent(clientId)}/deudas?${query.toString()}`,
                        { headers: { Accept: 'application/json' } }
                    );
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);
                    const data = await response.json();
                    if (!data.success) throw new Error(data.message || 'No se pudo consultar el saldo a favor.');
                    if (
                        requestToken !== vencidosAvailabilityRequestToken
                        || clientId !== currentVencidosState.clientId
                    ) return;

                    currentVencidosState.esRetencion = !!data.es_retencion;
                    currentVencidosState.saldoFavorDisponible = Number(data.saldo_a_favor_disponible || 0);
                    currentVencidosState.saldoFavorMonedaId = String(data.moneda_idmoneda || '');
                    currentVencidosState.saldoFavorCargado = true;
                    currentVencidosState.errorSaldoFavor = false;
                    currentVencidosState.cxcs = data.can_select_previous_debts === false ? [] : (data.cxcs || []);
                    renderCreditChoice(
                        currentVencidosState.targetModal === 'gestion' ? 'gestion' : currentVencidosState.targetModal === 'factura' ? 'factura' : 'payment',
                        currentVencidosState.saldoFavorDisponible,
                        currentVencidosState.currencySymbol
                    );
                    if (currentVencidosState.targetModal === 'gestion') {
                        updateGestionBalanceSummary();
                    } else {
                        applyDeductionLogic();
                    }
                    if (
                        saldoFavorModalPrefix === currentVencidosState.targetModal
                        && document.getElementById('saldo-favor-selector-modal')?.classList.contains('visible')
                    ) openSaldoFavorModal(saldoFavorModalPrefix);
                } catch (error) {
                    if (requestToken !== vencidosAvailabilityRequestToken) return;
                    currentVencidosState.saldoFavorCargado = true;
                    currentVencidosState.errorSaldoFavor = true;
                    currentVencidosState.saldoFavorMonedaId = '';
                    console.error('No se pudo consultar el saldo a favor:', error);
                    renderCreditChoice(
                        currentVencidosState.targetModal === 'gestion' ? 'gestion' : currentVencidosState.targetModal === 'factura' ? 'factura' : 'payment',
                        0,
                        currentVencidosState.currencySymbol
                    );
                    if (currentVencidosState.targetModal === 'gestion') {
                        updateGestionBalanceSummary();
                    }
                    if (
                        saldoFavorModalPrefix === currentVencidosState.targetModal
                        && document.getElementById('saldo-favor-selector-modal')?.classList.contains('visible')
                    ) openSaldoFavorModal(saldoFavorModalPrefix);
                }
            }

            async function openPeriodosModal() {
                const modal = document.getElementById('modal-periodos-vencidos');
                const listContainer = document.getElementById('periodos-vencidos-list');
                const totalDisplay = document.getElementById('periodos-total-display');
                if (!modal || !listContainer) return;

                listContainer.textContent = 'Cargando deudas anteriores...';
                const symbol = currentVencidosState.currencySymbol || 'S/';
                if (totalDisplay) totalDisplay.textContent = `${symbol} 0.00`;
                modal.classList.remove('hidden');
                modal.classList.add('visible');

                if (!currentVencidosState.clientId) {
                    listContainer.textContent = 'Este CXC no tiene deudas anteriores pendientes.';
                    return;
                }

                const query = new URLSearchParams({ current_cxc_id: currentVencidosState.baseCxcId || '' });
                currentVencidosState.serviceIds.forEach(id => query.append('service_ids[]', id));
                try {
                    const response = await fetch(`/modulos/cuentas-por-cobrar/cliente/${currentVencidosState.clientId}/deudas?${query.toString()}`);
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);
                    const data = await response.json();
                    if (!data.success) throw new Error(data.message || 'No se pudieron consultar las deudas.');

                    currentVencidosState.esRetencion = !!data.es_retencion;
                    currentVencidosState.saldoFavorDisponible = Number(data.saldo_a_favor_disponible || 0);
                    currentVencidosState.saldoFavorMonedaId = String(data.moneda_idmoneda || '');
                    currentVencidosState.cxcs = data.can_select_previous_debts === false ? [] : (data.cxcs || []);
                    applyDeductionLogic();

                    if (currentVencidosState.cxcs.length === 0) {
                        listContainer.textContent = 'Este CXC no tiene deudas anteriores pendientes.';
                        return;
                    }

                    listContainer.replaceChildren();
                    const debtsByMonth = new Map();
                    currentVencidosState.cxcs.forEach(c => {
                        const month = c.mes_display || c.descripcion || 'Periodo anterior';
                        if (!debtsByMonth.has(month)) debtsByMonth.set(month, []);
                        debtsByMonth.get(month).push(c);
                    });

                    const updateSubmodalTotal = () => {
                        let total = 0;
                        listContainer.querySelectorAll('.vencido-item-checkbox:checked').forEach(cb => {
                            total += parseFloat(cb.dataset.amount || 0);
                        });
                        if (totalDisplay) totalDisplay.textContent = `${symbol} ${total.toFixed(2)}`;
                    };

                    debtsByMonth.forEach((debts, month) => {
                        const group = document.createElement('section');
                        group.className = 'overflow-hidden rounded-lg border border-slate-200 bg-white mb-2';

                        const header = document.createElement('div');
                        header.className = 'flex items-center gap-3 px-3 py-2.5';

                        const monthCheckbox = document.createElement('input');
                        monthCheckbox.type = 'checkbox';
                        monthCheckbox.className = 'vencido-month-checkbox h-4 w-4 cursor-pointer rounded border-slate-500 text-primary focus:ring-primary';
                        monthCheckbox.setAttribute('aria-label', `Seleccionar todas las deudas de ${month}`);

                        const toggleButton = document.createElement('button');
                        toggleButton.type = 'button';
                        toggleButton.className = 'flex flex-1 items-center justify-between text-left text-xs font-bold text-slate-800';
                        toggleButton.setAttribute('aria-expanded', 'false');

                        const monthLabel = document.createElement('span');
                        monthLabel.textContent = `${month} (${debts.length})`;
                        const expandIcon = document.createElement('span');
                        expandIcon.className = 'ml-2 text-slate-500 transition-transform';
                        expandIcon.textContent = '⌄';
                        toggleButton.append(monthLabel, expandIcon);
                        header.append(monthCheckbox, toggleButton);

                        const itemsContainer = document.createElement('div');
                        itemsContainer.className = 'hidden space-y-2 border-t border-slate-100 bg-slate-50 p-2';

                        debts.forEach(c => {
                            const isChecked = currentVencidosState.selectedIds.includes(String(c.idcuentasPorCobrar));
                            const div = document.createElement('div');
                            div.className = 'flex items-center justify-between rounded-lg border border-slate-200 bg-white p-2.5 transition-all hover:border-slate-300 mb-2';
                            const label = document.createElement('label');
                            label.className = 'flex flex-1 cursor-pointer items-center gap-3';
                            const checkbox = document.createElement('input');
                            checkbox.type = 'checkbox';
                            checkbox.className = "vencido-item-checkbox h-4 w-4 cursor-pointer rounded border-slate-500 shadow-sm transition-all duration-100 ease-in-out focus:ring-4 focus:ring-primary focus:ring-opacity-20 [&[type='checkbox']]:checked:border-primary [&[type='checkbox']]:checked:bg-primary";
                            checkbox.value = c.idcuentasPorCobrar;
                            checkbox.dataset.amount = c.montoActual;
                            checkbox.checked = isChecked;
                            const details = document.createElement('div');
                            details.className = 'text-xs';
                            const period = document.createElement('div');
                            period.className = 'font-bold text-slate-800';
                            period.textContent = c.placa_display || `CXC #${c.idcuentasPorCobrar}`;
                            details.append(period);
                            label.append(checkbox, details);
                            const amount = document.createElement('span');
                            amount.className = 'text-xs font-bold text-slate-900';
                            amount.textContent = `${symbol} ${c.montoActualDisplay}`;
                            div.append(label, amount);
                            itemsContainer.appendChild(div);
                        });

                        const syncMonthCheckbox = () => {
                            const itemCheckboxes = itemsContainer.querySelectorAll('.vencido-item-checkbox');
                            const checkedCount = itemsContainer.querySelectorAll('.vencido-item-checkbox:checked').length;
                            monthCheckbox.checked = checkedCount === itemCheckboxes.length;
                            monthCheckbox.indeterminate = checkedCount > 0 && checkedCount < itemCheckboxes.length;
                        };

                        monthCheckbox.addEventListener('change', () => {
                            itemsContainer.querySelectorAll('.vencido-item-checkbox').forEach(checkbox => {
                                checkbox.checked = monthCheckbox.checked;
                            });
                            monthCheckbox.indeterminate = false;
                            updateSubmodalTotal();
                        });
                        itemsContainer.querySelectorAll('.vencido-item-checkbox').forEach(checkbox => {
                            checkbox.addEventListener('change', () => {
                                syncMonthCheckbox();
                                updateSubmodalTotal();
                            });
                        });
                        toggleButton.addEventListener('click', () => {
                            const isExpanded = toggleButton.getAttribute('aria-expanded') === 'true';
                            toggleButton.setAttribute('aria-expanded', String(!isExpanded));
                            itemsContainer.classList.toggle('hidden', isExpanded);
                            expandIcon.classList.toggle('rotate-180', !isExpanded);
                        });

                        syncMonthCheckbox();
                        group.append(header, itemsContainer);
                        listContainer.appendChild(group);
                    });
                    updateSubmodalTotal();
                } catch (error) {
                    listContainer.textContent = 'No se pudieron cargar las deudas anteriores. Cierra e intenta nuevamente.';
                    return;
                }
            }

            function closePeriodosModal() {
                const modal = document.getElementById('modal-periodos-vencidos');
                if (modal) {
                    modal.classList.add('hidden');
                    modal.classList.remove('visible');
                }
            }

            function confirmPeriodosSelection() {
                const listContainer = document.getElementById('periodos-vencidos-list');
                if (!listContainer) return;

                const checkedBoxes = listContainer.querySelectorAll('.vencido-item-checkbox:checked');
                const selectedIds = Array.from(checkedBoxes).map(cb => cb.value);

                currentVencidosState.selectedIds = [...new Set(selectedIds.filter(Boolean))];
                syncExplicitPeriodCxcIds(currentVencidosState.targetModal);

                applyDeductionLogic();
                closePeriodosModal();
            }

            function syncExplicitPeriodCxcIds(targetModal) {
                const targetFormId = targetModal === 'pay' ? 'service-payment-form' : 'service-factura-form';
                const form = document.getElementById(targetFormId);
                if (!form) return;

                form.querySelectorAll('input[name="period_cxc_ids[]"]').forEach((input) => input.remove());
                const explicitIds = currentVencidosState.targetModal === targetModal
                    ? [...new Set((currentVencidosState.selectedIds || []).filter(Boolean))]
                    : [];
                explicitIds.forEach((id) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'period_cxc_ids[]';
                    input.value = id;
                    form.appendChild(input);
                });
            }

            document.getElementById('btn-periodos-vencidos')?.addEventListener('click', openPeriodosModal);
            document.getElementById('btn-factura-periodos-vencidos')?.addEventListener('click', openPeriodosModal);
            document.getElementById('btn-close-periodos-modal')?.addEventListener('click', closePeriodosModal);
            document.getElementById('btn-cancel-periodos-modal')?.addEventListener('click', closePeriodosModal);
            document.getElementById('btn-confirm-periodos')?.addEventListener('click', confirmPeriodosSelection);
            ['payment', 'factura'].forEach((prefix) => {
                document.getElementById(`${prefix}-select-credit-amount`)
                    ?.addEventListener('click', () => openSaldoFavorModal(prefix));
            });
            document.getElementById('saldo-favor-selector-close')?.addEventListener('click', closeSaldoFavorModal);
            document.getElementById('saldo-favor-selector-cancel')?.addEventListener('click', closeSaldoFavorModal);
            document.getElementById('saldo-favor-selector-apply')?.addEventListener('click', confirmSaldoFavorSelection);
            document.getElementById('saldo-favor-selector-clear')?.addEventListener('click', clearSaldoFavorSelection);
            ['payment', 'factura'].forEach((prefix) => {
                document.getElementById(`${prefix}-select-credit-note`)?.addEventListener('click', () => openCreditNoteModal(prefix));
            });
            document.getElementById('credit-note-selector-close')?.addEventListener('click', closeCreditNoteModal);
            document.getElementById('credit-note-selector-cancel')?.addEventListener('click', closeCreditNoteModal);
            document.getElementById('credit-note-selector-clear')?.addEventListener('click', clearCreditNoteSelection);
            document.getElementById('credit-note-selector-confirm')?.addEventListener('click', confirmCreditNoteSelection);

            function openPartialPaymentModal(cxcId, cxcIds, serviceIds, clientName, balance, docRef, currencySymbol, monedaId, tipoCobroId = '', clientId = '', periodEnd = '') {
                const outstanding = Number(balance || 0);
                const modal = document.getElementById('partial-payment-modal');
                const form = document.getElementById('partial-payment-form');
                if (!Number.isFinite(outstanding) || outstanding <= 0) {
                    showServiceMessage('No se encontró un saldo pendiente válido para completar el pago.');
                    return;
                }
                if (!modal || !form) return;

                form.reset();
                document.getElementById('partial-payment-cxc-id').value = cxcId || cxcIds[0] || '';
                document.getElementById('partial-payment-moneda-id').value = monedaId || '';
                document.getElementById('partial-payment-client').value = clientName || '-';
                document.getElementById('partial-payment-date').value =
                    document.getElementById('payment-date')?.value || '';
                document.getElementById('partial-payment-period-end').value = periodEnd || '';
                document.getElementById('partial-payment-reference').value = docRef || '-';
                document.getElementById('partial-payment-amount').value = outstanding.toFixed(2);
                document.getElementById('partial-payment-currency').textContent = currencySymbol || 'S/';
                document.getElementById('partial-payment-description').value = '';
                document.getElementById('partial-payment-comprobante').value = '';
                document.getElementById('partial-payment-comprobante-name').textContent = '';
                document.getElementById('partial-payment-selected-cxc').replaceChildren(
                    ...[...new Set([...(cxcIds || []), cxcId].filter(Boolean))].map(id => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'cxc_ids[]';
                        input.value = id;
                        return input;
                    })
                );
                document.getElementById('partial-payment-selected-services').replaceChildren(
                    ...(serviceIds || []).map(id => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'servicio_ids[]';
                        input.value = id;
                        return input;
                    })
                );

                modal.classList.remove('hidden');
                modal.classList.add('visible');
                document.body.style.overflow = 'hidden';
            }

            function closePartialPaymentModal() {
                const modal = document.getElementById('partial-payment-modal');
                const form = document.getElementById('partial-payment-form');
                if (!modal) return;
                modal.classList.remove('visible');
                modal.classList.add('hidden');
                form?.reset();
                document.getElementById('partial-payment-dropzone')?.classList.remove('is-invalid');
                document.body.style.overflow = '';
            }

            document.addEventListener('click', event => {
                if (event.target.closest('[data-close-partial-payment]')) {
                    closePartialPaymentModal();
                }
            });

            const partialPaymentComprobanteInput = document.getElementById('partial-payment-comprobante');
            const partialPaymentDropzone = document.getElementById('partial-payment-dropzone');

            const partialPaymentClearComprobante = document.getElementById('partial-payment-clear-comprobante');
            partialPaymentClearComprobante?.classList.add('hidden');
            partialPaymentClearComprobante?.addEventListener('click', function () {
                if (partialPaymentComprobanteInput) partialPaymentComprobanteInput.value = '';
                const fileName = document.getElementById('partial-payment-comprobante-name');
                if (fileName) fileName.textContent = '';
                partialPaymentDropzone?.classList.remove('is-invalid', 'is-dragging');
                this.classList.add('hidden');
            });

            function handlePartialPaymentComprobanteFile(file) {
                const fileName = document.getElementById('partial-payment-comprobante-name');
                if (!file || !partialPaymentComprobanteInput) return;
                const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
                if (!validTypes.includes(file.type) || file.size > 5 * 1024 * 1024) {
                    partialPaymentComprobanteInput.value = '';
                    if (fileName) fileName.textContent = 'Usa JPG, PNG o PDF de máximo 5 MB.';
                    partialPaymentDropzone?.classList.add('is-invalid');
                    partialPaymentClearComprobante?.classList.add('hidden');
                    return;
                }

                const transfer = new DataTransfer();
                transfer.items.add(file);
                partialPaymentComprobanteInput.files = transfer.files;
                if (fileName) fileName.textContent = `${file.name} (${(file.size / 1024).toFixed(0)} KB)`;
                partialPaymentDropzone?.classList.remove('is-invalid');
                partialPaymentClearComprobante?.classList.remove('hidden');
            }

            partialPaymentComprobanteInput?.addEventListener('change', function () {
                handlePartialPaymentComprobanteFile(this.files?.[0]);
            });

            if (partialPaymentDropzone) {
                partialPaymentDropzone.addEventListener('drop', function (event) {
                    event.preventDefault();
                    handlePartialPaymentComprobanteFile(event.dataTransfer.files?.[0]);
                    partialPaymentDropzone.classList.remove('is-dragging');
                });

                ['dragenter', 'dragover'].forEach(eventName => {
                    partialPaymentDropzone.addEventListener(eventName, function (event) {
                        event.preventDefault();
                        partialPaymentDropzone.classList.add('is-dragging');
                    });
                });

                ['dragleave', 'dragend'].forEach(eventName => {
                    partialPaymentDropzone.addEventListener(eventName, function (event) {
                        event.preventDefault();
                        partialPaymentDropzone.classList.remove('is-dragging');
                    });
                });
            }

            document.addEventListener('paste', function (event) {
                const modal = document.getElementById('partial-payment-modal');
                if (!modal?.classList.contains('visible')) return;
                const pastedFile = Array.from(event.clipboardData?.items || [])
                    .find(item => item.kind === 'file');
                const file = pastedFile?.getAsFile();
                if (!file) return;
                event.preventDefault();
                handlePartialPaymentComprobanteFile(file);
            });

            function openFacturaModal(cxcId, clientName, amount, currentDocRef = '', currentFile = '', amountDisplay = '', monedaId = '', currencySymbol = '', serviceIds = [], tipoCobroId = '', clientId = '', periodEnd = '', cxcIds = []) {
                const serviceFacturaModal = document.getElementById('service-factura-modal');
                const facturaClient = document.getElementById('factura-client');
                const facturaAmount = document.getElementById('factura-amount');
                const facturaForm = document.getElementById('service-factura-form');
                const facturaCxcId = document.getElementById('factura-cxc-id');
                const facturaServiceId = document.getElementById('factura-service-id');
                const facturaPeriodEnd = document.getElementById('factura-period-end');
                const selectedServiceInputs = document.getElementById('factura-selected-service-inputs');
                const selectedCxcInputs = document.getElementById('factura-selected-cxc-inputs');

                if (!serviceFacturaModal) return;
                clearFacturaSubmitError();
                const amountSummary = document.getElementById('factura-amount-summary');
                amountSummary?.classList.add('hidden');
                if (amountSummary) amountSummary.textContent = '';
                const creditSummary = document.getElementById('factura-payment-credit-summary');
                creditSummary?.classList.add('hidden');
                if (creditSummary) creditSummary.textContent = '';
                const creditCheckbox = document.getElementById('factura-use-credit');
                if (creditCheckbox) creditCheckbox.checked = false;
                const bankCreditAmount = document.getElementById('factura-credit-amount');
                if (bankCreditAmount) bankCreditAmount.value = '';
                const facturaDocRef = document.getElementById('factura-doc-ref');
                const resolvedCurrencySymbol = resolveCurrencySymbol(amountDisplay || currencySymbol || '', monedaId || '');
                const resolvedAmountDisplay = `${resolvedCurrencySymbol || 'S/'} ${Number(amount).toFixed(2)}`;

                let parsedServiceIds = [];
                if (Array.isArray(serviceIds)) {
                    parsedServiceIds = serviceIds.filter(Boolean);
                } else if (typeof serviceIds === 'string' || typeof serviceIds === 'number') {
                    parsedServiceIds = String(serviceIds).split(',').filter(Boolean);
                }

                if (selectedServiceInputs) {
                    selectedServiceInputs.innerHTML = parsedServiceIds.map(id => `<input type="hidden" name="servicio_ids[]" value="${id}">`).join('');
                }
                if (selectedCxcInputs) {
                    selectedCxcInputs.replaceChildren(...cxcIds.map(id => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'cxc_ids[]';
                        input.value = id;
                        return input;
                    }));
                }

                serviceFacturaModal.dataset.rawAmount = amount || 0;
                serviceFacturaModal.dataset.grossAmount = amount || 0;
                serviceFacturaModal.dataset.payableAmount = amount || 0;
                serviceFacturaModal.dataset.expectedCashDue = amount || 0;
                serviceFacturaModal.dataset.currencySymbol = resolvedCurrencySymbol || 'S/';
                syncConvertedTotalCurrencySymbol(
                    null,
                    document.getElementById('factura-payment-converted-total-symbol'),
                    resolvedCurrencySymbol || 'S/'
                );
                const invoiceAmountInput = document.getElementById('factura-invoice-amount');
                if (invoiceAmountInput) invoiceAmountInput.value = Number(amount || 0).toFixed(2);
                facturaAdvanceFields?.classList.add('hidden');
                if (facturaAdvanceToggle) {
                    facturaAdvanceToggle.textContent = 'Pagar adelantado';
                    facturaAdvanceToggle.setAttribute('aria-expanded', 'false');
                }
                facturaAmountWasEdited = false;
                if (facturaAmountCurrency) facturaAmountCurrency.textContent = resolvedCurrencySymbol || 'S/';
                if (facturaPartialAmountCurrency) facturaPartialAmountCurrency.textContent = resolvedCurrencySymbol || 'S/';
                if (facturaAdvanceMonths) {
                    facturaAdvanceMonths.value = '';
                    facturaAdvanceMonths.setCustomValidity('');
                    facturaAdvanceMonths.disabled = true;
                }

                if (facturaCxcId) facturaCxcId.value = cxcId || '';
                if (facturaPeriodEnd) {
                    facturaPeriodEnd.value = periodEnd || '';
                    facturaPeriodEnd.dataset.basePeriodEnd = periodEnd || '';
                }
                if (facturaServiceId) facturaServiceId.value = parsedServiceIds[0] || '';
                if (facturaClient) facturaClient.value = clientName || '-';
                if (facturaAmount) facturaAmount.value = Number(amount).toFixed(2);
                const facturaWithholdingDecision = document.getElementById('factura-withholding-decision');
                if (facturaWithholdingDecision) facturaWithholdingDecision.value = '';
                if (facturaDocRef) facturaDocRef.value = (currentDocRef && currentDocRef !== '-') ? currentDocRef : '';
                if (facturaFileInput) facturaFileInput.value = '';
                document.querySelector('[data-clear-upload="factura-file"]')?.classList.add('hidden');
                if (facturaFileName) facturaFileName.textContent = currentFile
                    ? 'Documento registrado. Selecciona uno nuevo para reemplazarlo.'
                    : '';

                if (facturaPaymentInput) facturaPaymentInput.value = '';
                if (facturaPaymentFileName) facturaPaymentFileName.textContent = '';
                document.querySelector('[data-clear-upload="factura-comprobante"]')?.classList.add('hidden');
                facturaPaymentUploadBox?.classList.remove('is-invalid');

                const paymentTypeSelect = document.getElementById('factura-payment-type');
                const paymentMethodSelect = document.getElementById('factura-payment-method');
                const paymentBankSelect = document.getElementById('factura-payment-bank');
                if (paymentTypeSelect) paymentTypeSelect.value = '';
                if (paymentTypeSelect && tipoCobroId) paymentTypeSelect.value = String(tipoCobroId);
                if (paymentMethodSelect) {
                    paymentMethodSelect.value = Array.from(paymentMethodSelect.options)
                        .find((option) => /contado/i.test(option.textContent || ''))?.value || '';
                }
                if (paymentBankSelect) paymentBankSelect.value = '1';
                if (facturaCanCuotas) facturaCanCuotas.value = '';
                facturaInstallments?.replaceChildren();
                facturaCreditFields?.classList.add('hidden');
                facturaCreditInstallments?.classList.add('hidden');

                // Reset radios y divs ocultos
                const sameCurrencyRadio = document.querySelector('input[name="factura_payment_currency_mode"][value="same"]');
                if (sameCurrencyRadio) sameCurrencyRadio.checked = true;
                document.getElementById('factura-payment-currency-extra')?.classList.add('hidden');
                const facturaCurrencySelect = document.getElementById('factura-payment-currency');
                const facturaExchangeRateInput = document.getElementById('factura-payment-exchange-rate');
                const facturaForeignAmountInput = document.getElementById('factura-payment-converted-amount');
                if (facturaCurrencySelect) {
                    facturaCurrencySelect.value = '';
                    facturaCurrencySelect.required = false;
                    facturaCurrencySelect.dataset.debtCurrency = String(monedaId || '');
                    Array.from(facturaCurrencySelect.options).forEach((option) => {
                        const isDebtCurrency = Boolean(option.value) && option.value === String(monedaId || '');
                        option.disabled = isDebtCurrency;
                        option.hidden = isDebtCurrency;
                    });
                }
                ['factura-payment-exchange-rate', 'factura-payment-converted-amount', 'factura-payment-converted-total']
                    .forEach((id) => {
                        const input = document.getElementById(id);
                        if (input) input.value = '';
                    });
                if (facturaExchangeRateInput) facturaExchangeRateInput.required = false;
                if (facturaForeignAmountInput) facturaForeignAmountInput.required = false;

                const totalPaymentRadio = document.querySelector('input[name="factura_modo_pago"][value="total"]');
                if (totalPaymentRadio) totalPaymentRadio.checked = true;
                document.getElementById('factura-partial-payment-field')?.classList.add('hidden');

                const partialAmountInput = document.getElementById('factura-partial-payment-amount');
                if (partialAmountInput) partialAmountInput.value = '';
                const balanceEl = document.getElementById('factura-payment-balance');
                if (balanceEl) balanceEl.textContent = `${resolvedCurrencySymbol || 'S/'} ${Number(amount).toFixed(2)}`;
                const facturaPaymentDate = document.getElementById('factura-payment-date');
                if (facturaPaymentDate) facturaPaymentDate.value = '{{ now()->format('Y-m-d') }}';
                if (facturaPeriodEnd) facturaPeriodEnd.value = periodEnd || '';

                if (facturaModeToggle) {
                    applyFacturaModeState(false);
                }
                facturaUploadBox?.classList.remove('is-invalid');
                if (facturaForm && cxcId) {
                    facturaForm.action = `/modulos/cuentas-por-cobrar/${cxcId}/facturar`;
                }

                if (clientId) {
                    checkAndRenderVencidosButton(clientId, cxcId, amount, resolvedCurrencySymbol, 'factura', serviceIds, clientName, monedaId || resolveSelectedCurrencyId());
                } else {
                    resetVencidosState();
                }

                serviceFacturaModal.classList.remove('hidden');
                serviceFacturaModal.classList.add('visible');
                document.body.style.overflow = 'hidden';
            }

            // Manejadores para alternar moneda y pago parcial en Modal 1
            document.querySelectorAll('input[name="factura_payment_currency_mode"]').forEach(function (radio) {
                radio.addEventListener('change', function () {
                    const extraDiv = document.getElementById('factura-payment-currency-extra');
                    if (extraDiv) {
                        extraDiv.classList.toggle('hidden', this.value !== 'other');
                    }
                    const isOther = this.value === 'other';
                    const currencySelect = document.getElementById('factura-payment-currency');
                    const exchangeRateInput = document.getElementById('factura-payment-exchange-rate');
                    const foreignAmountInput = document.getElementById('factura-payment-converted-amount');
                    if (currencySelect) currencySelect.required = isOther;
                    if (exchangeRateInput) exchangeRateInput.required = isOther;
                    if (foreignAmountInput) foreignAmountInput.required = isOther;
                    if (this.value === 'other') {
                        calcFacturaCurrencyConversion();
                    } else {
                        if (currencySelect) currencySelect.value = '';
                        if (exchangeRateInput) exchangeRateInput.value = '';
                        if (foreignAmountInput) foreignAmountInput.value = '';
                        const convertedTotalInput = document.getElementById('factura-payment-converted-total');
                        if (convertedTotalInput) convertedTotalInput.value = '';
                    }
                });
            });

            facturaModal?.querySelectorAll('input[name="factura_modo_pago"]').forEach(function (radio) {
                radio.addEventListener('change', function () {
                    const hasCreditInstallmentPlan = isCreditPayment(facturaPaymentMethod)
                        && Number(facturaCanCuotas?.value || 0) > 0;
                    const partialDiv = document.getElementById('factura-partial-payment-field');
                    if (partialDiv) {
                        partialDiv.classList.toggle('hidden', this.value !== 'parcial');
                    }
                    const partialAmount = document.getElementById('factura-partial-payment-amount');
                    const grossAmount = Number(facturaModal?.dataset.grossAmount || 0);
                    const cashDue = Number(facturaModal?.dataset.expectedCashDue || facturaModal?.dataset.payableAmount || 0);
                    const usesOtherCurrency = document.querySelector('input[name="factura_payment_currency_mode"]:checked')?.value === 'other';
                    if (!usesOtherCurrency && partialAmount) {
                        if (hasCreditInstallmentPlan) {
                            const firstInstallment = facturaInstallments?.querySelector('input[name="factura_cuotas_montos[]"]');
                            partialAmount.value = firstInstallment?.value || '';
                        } else if (this.value === 'parcial') {
                            partialAmount.value = cashDue > 0 ? cashDue.toFixed(2) : '';
                        } else if (this.value === 'total') {
                            partialAmount.value = grossAmount > 0 ? grossAmount.toFixed(2) : (cashDue > 0 ? cashDue.toFixed(2) : '');
                        }
                    }
                    if (this.checked) {
                        if (document.querySelector('input[name="factura_payment_currency_mode"]:checked')?.value === 'other') {
                            calcFacturaCurrencyConversion();
                        }
                        applyDeductionLogic();
                        if (isCreditPayment(facturaPaymentMethod)) {
                            syncFacturaCreditInstallmentPayment();
                        }
                    }
                });
            });

            const facturaPartialAmountInput = document.getElementById('factura-partial-payment-amount');
            facturaPartialAmountInput?.addEventListener('input', function () {
                const serviceFacturaModal = document.getElementById('service-factura-modal');
                const enteredAmount = parseFloat(this.value || 0);
                const grossAmount = parseFloat(serviceFacturaModal?.dataset.grossAmount || 0);
                const cashDue = parseFloat(serviceFacturaModal?.dataset.expectedCashDue || serviceFacturaModal?.dataset.payableAmount || 0);
                const deduction = parseFloat(serviceFacturaModal?.dataset.deductionAmount || 0);
                const baseGross = grossAmount > 0 ? grossAmount : cashDue;
                const hasWithholding = grossAmount > 0 && Math.abs(grossAmount - cashDue) > 0.01;
                const fullThreshold = hasWithholding ? grossAmount : cashDue;
                const isPartial = enteredAmount + 0.01 < fullThreshold;

                const hasCreditInstallmentPlan = isCreditPayment(facturaPaymentMethod)
                    && Number(facturaCanCuotas?.value || 0) > 0;
                const totalRadio = facturaModal?.querySelector('input[name="factura_modo_pago"][value="total"]');
                const partialRadio = facturaModal?.querySelector('input[name="factura_modo_pago"][value="parcial"]');
                if (totalRadio) totalRadio.checked = !isPartial;
                if (partialRadio) partialRadio.checked = isPartial;

                const symbol = serviceFacturaModal?.dataset.currencySymbol || 'S/';
                if (hasCreditInstallmentPlan) {
                    syncFacturaCreditInstallmentPayment();
                    return;
                }
                updateBalanceAndCreditDisplay(
                    baseGross,
                    enteredAmount,
                    'factura-payment-balance',
                    'factura-payment-credit-summary',
                    symbol,
                    cashDue,
                    deduction
                );
            });

            function calcFacturaCurrencyConversion() {
                const exchangeRate = parseFloat(document.getElementById('factura-payment-exchange-rate')?.value || 0);
                const receivedAmountInput = document.getElementById('factura-payment-converted-amount');
                const receivedAmount = parseFloat(receivedAmountInput?.value || 0);
                const facturaCurrencySelect = document.getElementById('factura-payment-currency');
                const debtCurrencyId = currentVencidosState.monedaId || facturaCurrencySelect?.dataset.debtCurrency || '';
                const paymentCurrencyId = facturaCurrencySelect?.value || '';
                syncConvertedTotalCurrencySymbol(
                    facturaCurrencySelect,
                    document.getElementById('factura-payment-converted-total-symbol'),
                    facturaModal?.dataset.currencySymbol || 'S/'
                );
                const totalEl = document.getElementById('factura-payment-converted-total');
                const paidAmountEl = document.getElementById('factura-partial-payment-amount');
                const totalRadio = document.querySelector('input[name="factura_modo_pago"][value="total"]');
                const partialRadio = document.querySelector('input[name="factura_modo_pago"][value="parcial"]');
                const partialField = document.getElementById('factura-partial-payment-field');
                const payableAmount = facturaModal?.dataset.payableAmount;
                const totalAmount = payableAmount !== undefined
                    ? (payableAmount === '' ? NaN : parseFloat(payableAmount))
                    : parseFloat(facturaModal?.dataset.rawAmount || 0);
                const convertedTotal = convertDebtAmountToPaymentCurrency(
                    totalAmount,
                    exchangeRate,
                    debtCurrencyId,
                    paymentCurrencyId,
                    facturaCurrencySelect
                );
                const convertedReceivedAmount = convertPaymentAmountToDebtCurrency(
                    receivedAmount,
                    exchangeRate,
                    debtCurrencyId,
                    paymentCurrencyId,
                    facturaCurrencySelect
                );
                if (convertedTotal !== null && totalEl) {
                    totalEl.value = convertedTotal.toFixed(2);
                    if (convertedReceivedAmount !== null && receivedAmount > 0) {
                        const isTotalPayment = amountsMatchAtCentPrecision(receivedAmount, convertedTotal);
                        const selectedRadio = isTotalPayment ? totalRadio : partialRadio;
                        const modeChanged = selectedRadio && !selectedRadio.checked;
                        const amountApplied = isTotalPayment ? totalAmount : convertedReceivedAmount;
                        if (paidAmountEl) paidAmountEl.value = amountApplied.toFixed(2);
                        if (totalRadio) totalRadio.checked = isTotalPayment;
                        if (partialRadio) partialRadio.checked = !isTotalPayment;
                        partialField?.classList.toggle('hidden', isTotalPayment);
                        if (modeChanged) {
                            selectedRadio.dispatchEvent(new Event('change'));
                            return;
                        }
                        const grossAmount = parseFloat(facturaModal?.dataset.grossAmount || 0);
                        const deduction = parseFloat(facturaModal?.dataset.deductionAmount || 0);
                        const baseGross = grossAmount > 0 ? grossAmount : totalAmount;
                        const paymentGross = convertDebtAmountToPaymentCurrency(
                            baseGross,
                            exchangeRate,
                            debtCurrencyId,
                            paymentCurrencyId,
                            facturaCurrencySelect
                        );
                        const paymentCashDue = convertDebtAmountToPaymentCurrency(
                            totalAmount,
                            exchangeRate,
                            debtCurrencyId,
                            paymentCurrencyId,
                            facturaCurrencySelect
                        );
                        updateBalanceAndCreditDisplay(
                            paymentGross ?? baseGross,
                            receivedAmount,
                            'factura-payment-balance',
                            'factura-payment-credit-summary',
                            normalizeCurrencySymbol(facturaCurrencySelect?.selectedOptions?.[0]?.dataset.symbol)
                                || facturaModal?.dataset.currencySymbol
                                || 'S/',
                            paymentCashDue ?? totalAmount,
                            paymentGross !== null && paymentCashDue !== null
                                ? Math.max(0, paymentGross - paymentCashDue)
                                : deduction
                        );
                    } else {
                        if (paidAmountEl) paidAmountEl.value = '';
                        const modeChanged = partialRadio && !partialRadio.checked;
                        if (partialRadio) partialRadio.checked = true;
                        if (totalRadio) totalRadio.checked = false;
                        partialField?.classList.remove('hidden');
                        if (modeChanged) {
                            partialRadio.dispatchEvent(new Event('change'));
                            return;
                        }
                        const grossAmount = parseFloat(facturaModal?.dataset.grossAmount || 0);
                        const deduction = parseFloat(facturaModal?.dataset.deductionAmount || 0);
                        const baseGross = grossAmount > 0 ? grossAmount : totalAmount;
                        const paymentGross = convertDebtAmountToPaymentCurrency(
                            baseGross,
                            exchangeRate,
                            debtCurrencyId,
                            paymentCurrencyId,
                            facturaCurrencySelect
                        );
                        const paymentCashDue = convertDebtAmountToPaymentCurrency(
                            totalAmount,
                            exchangeRate,
                            debtCurrencyId,
                            paymentCurrencyId,
                            facturaCurrencySelect
                        );
                        updateBalanceAndCreditDisplay(
                            paymentGross ?? baseGross,
                            0,
                            'factura-payment-balance',
                            'factura-payment-credit-summary',
                            normalizeCurrencySymbol(facturaCurrencySelect?.selectedOptions?.[0]?.dataset.symbol)
                                || facturaModal?.dataset.currencySymbol
                                || 'S/',
                            paymentCashDue ?? totalAmount,
                            paymentGross !== null && paymentCashDue !== null
                                ? Math.max(0, paymentGross - paymentCashDue)
                                : deduction
                        );
                    }
                } else if (totalEl) {
                    totalEl.value = '';
                    if (paidAmountEl) paidAmountEl.value = '';
                    const modeChanged = partialRadio && !partialRadio.checked;
                    if (partialRadio) partialRadio.checked = true;
                    if (totalRadio) totalRadio.checked = false;
                    partialField?.classList.remove('hidden');
                    if (modeChanged) {
                        partialRadio.dispatchEvent(new Event('change'));
                        return;
                    }
                    const grossAmount = parseFloat(facturaModal?.dataset.grossAmount || 0);
                    const deduction = parseFloat(facturaModal?.dataset.deductionAmount || 0);
                    const baseGross = grossAmount > 0 ? grossAmount : totalAmount;
                    updateBalanceAndCreditDisplay(baseGross, 0, 'factura-payment-balance', 'factura-payment-credit-summary', facturaModal?.dataset.currencySymbol || 'S/', totalAmount, deduction);
                }
            }
            document.getElementById('factura-payment-exchange-rate')?.addEventListener('input', calcFacturaCurrencyConversion);
            document.getElementById('factura-payment-converted-amount')?.addEventListener('input', calcFacturaCurrencyConversion);
            document.getElementById('factura-payment-currency')?.addEventListener('change', calcFacturaCurrencyConversion);

            function handleFacturaFile(file) {
                if (!file || !facturaFileInput) return;
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
                const maxFileSize = 5 * 1024 * 1024;

                if (!allowedTypes.includes(file.type) || file.size > maxFileSize) {
                    facturaFileInput.value = '';
                    if (facturaFileName) facturaFileName.textContent = 'Selecciona JPG, PNG o PDF de máximo 5 MB.';
                    document.querySelector('[data-clear-upload="factura-file"]')?.classList.add('hidden');
                    facturaUploadBox?.classList.add('is-invalid');
                    return;
                }

                const transfer = new DataTransfer();
                transfer.items.add(file);
                facturaFileInput.files = transfer.files;
                if (facturaFileName) facturaFileName.textContent = `${file.name} (${(file.size / 1024).toFixed(0)} KB)`;
                document.querySelector('[data-clear-upload="factura-file"]')?.classList.remove('hidden');
                facturaUploadBox?.classList.remove('is-invalid');
            }

            function handleFacturaPaymentFile(file) {
                if (!file || !facturaPaymentInput) return;
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
                const maxFileSize = 5 * 1024 * 1024;

                if (!allowedTypes.includes(file.type) || file.size > maxFileSize) {
                    facturaPaymentInput.value = '';
                    if (facturaPaymentFileName) facturaPaymentFileName.textContent = 'Selecciona JPG, PNG o PDF de máximo 5 MB.';
                    document.querySelector('[data-clear-upload="factura-comprobante"]')?.classList.add('hidden');
                    facturaPaymentUploadBox?.classList.add('is-invalid');
                    return;
                }

                const transfer = new DataTransfer();
                transfer.items.add(file);
                facturaPaymentInput.files = transfer.files;
                if (facturaPaymentFileName) facturaPaymentFileName.textContent = `${file.name} (${(file.size / 1024).toFixed(0)} KB)`;
                document.querySelector('[data-clear-upload="factura-comprobante"]')?.classList.remove('hidden');
                facturaPaymentUploadBox?.classList.remove('is-invalid');
            }

            facturaFileInput?.addEventListener('change', function (event) {
                handleFacturaFile(event.target.files && event.target.files[0]);
            });

            facturaUploadBox?.addEventListener('drop', function (event) {
                event.preventDefault();
                handleFacturaFile(event.dataTransfer.files && event.dataTransfer.files[0]);
                facturaUploadBox.classList.remove('is-dragging');
            });

            ['dragenter', 'dragover'].forEach(function (eventName) {
                facturaUploadBox?.addEventListener(eventName, function (event) {
                    event.preventDefault();
                    facturaUploadBox.classList.add('is-dragging');
                });
            });

            facturaUploadBox?.addEventListener('dragleave', function (event) {
                event.preventDefault();
                facturaUploadBox.classList.remove('is-dragging');
            });

            facturaPaymentInput?.addEventListener('change', function (event) {
                handleFacturaPaymentFile(event.target.files && event.target.files[0]);
            });

            facturaPaymentUploadBox?.addEventListener('drop', function (event) {
                event.preventDefault();
                handleFacturaPaymentFile(event.dataTransfer.files && event.dataTransfer.files[0]);
                facturaPaymentUploadBox.classList.remove('is-dragging');
            });

            ['dragenter', 'dragover'].forEach(function (eventName) {
                facturaPaymentUploadBox?.addEventListener(eventName, function (event) {
                    event.preventDefault();
                    facturaPaymentUploadBox.classList.add('is-dragging');
                });
            });

            facturaPaymentUploadBox?.addEventListener('dragleave', function (event) {
                event.preventDefault();
                facturaPaymentUploadBox.classList.remove('is-dragging');
            });

            document.addEventListener('paste', function (event) {
                const serviceFacturaModal = document.getElementById('service-factura-modal');
                const servicePaymentModal = document.getElementById('service-payment-modal');

                const pastedFile = Array.from((event.clipboardData && event.clipboardData.items) || [])
                    .find(function (item) { return item.kind === 'file'; });

                if (!pastedFile) return;

                const fileObj = pastedFile.getAsFile();
                if (!fileObj) return;

                if (serviceFacturaModal && serviceFacturaModal.classList.contains('visible')) {
                    event.preventDefault();
                    const isExpanded = serviceFacturaModal.classList.contains('factura-mode-expanded');
                    const targetEl = document.activeElement;
                    const isTargetInFactura = targetEl && targetEl.closest('#factura-upload-box');
                    const isTargetInExtra = targetEl && targetEl.closest('#factura-cobro-extra-section');
                    const hasFacturaFile = facturaFileInput && facturaFileInput.files && facturaFileInput.files.length > 0;

                    if (isTargetInFactura) {
                        handleFacturaFile(fileObj);
                    } else if (isExpanded && (isTargetInExtra || hasFacturaFile)) {
                        handleFacturaPaymentFile(fileObj);
                    } else {
                        handleFacturaFile(fileObj);
                    }
                    return;
                }

                if (servicePaymentModal && servicePaymentModal.classList.contains('visible')) {
                    event.preventDefault();
                    handlePaymentComprobanteFile(fileObj);
                }
            });

            const serviceFacturaForm = document.getElementById('service-factura-form');
            if (serviceFacturaForm) {
                serviceFacturaForm.addEventListener('submit', function (event) {
                    clearFacturaSubmitError();
                    syncExplicitPeriodCxcIds('factura');
                    const isFacturarYCancelar = facturaModeToggle?.dataset.mode === 'cancelar';

                    if (isFacturarYCancelar && facturaAmountWasEdited) {
                        syncFacturaAdvanceAmount();
                        if (facturaAmountDisplay?.value === '' || Number(facturaAmountDisplay.value) <= 0) {
                            facturaAmountDisplay?.setCustomValidity('El monto debe ser mayor a cero.');
                            facturaAmountDisplay?.reportValidity();
                            event.preventDefault();
                            return;
                        }
                        if (facturaAdvanceMonths?.value && (Number(facturaAdvanceMonths.value) < 1 || Number(facturaAdvanceMonths.value) > 120)) {
                            facturaAdvanceMonths.setCustomValidity('Indica entre 1 y 120 mensualidades.');
                            facturaAdvanceMonths.reportValidity();
                            event.preventDefault();
                            return;
                        }
                    }

                    if (!isFacturarYCancelar) {
                        serviceFacturaForm.querySelectorAll('[name="usar_saldo_favor"]').forEach((input) => input.disabled = true);
                        serviceFacturaForm.querySelectorAll('[name="nota_credito_ids[]"]').forEach((input) => input.disabled = true);
                    }

                    if (isFacturarYCancelar) {
                        const invoiceAmount = Number(
                            facturaModal?.dataset.grossAmount
                            || document.getElementById('factura-invoice-amount')?.value
                            || 0
                        );
                        const facturaDecision = document.getElementById('factura-withholding-decision');
                        if (invoiceAmount >= 700 && !facturaDecision?.value) {
                            event.preventDefault();
                            const totalMode = document.querySelector('input[name="factura_modo_pago"][value="total"]')?.checked;
                            const partialAmountInput = document.getElementById('factura-partial-payment-amount');
                            const partialAmount = Number(partialAmountInput?.value || 0);
                            const hasConfiguredAmount = facturaModal?.dataset.payableAmount !== undefined
                                && facturaModal.dataset.payableAmount !== '';
                            const configuredAmount = Number(facturaModal?.dataset.payableAmount || 0);
                            const cashEntered = totalMode && hasConfiguredAmount ? configuredAmount : partialAmount;
                            const selectedNotesAmount = selectedCreditNotesAmount('factura');
                            const bankCreditAmount = selectedBankCreditAmount('factura', currentVencidosState.saldoFavorDisponible);
                            const creditApplied = Math.min(
                                calculateNetPayable(invoiceAmount, currentVencidosState.esRetencion),
                                selectedNotesAmount + bankCreditAmount
                            );
                            const netAmount = Math.max(
                                calculateNetPayable(invoiceAmount, currentVencidosState.esRetencion) - creditApplied,
                                0
                            );
                            const grossDue = Math.max(invoiceAmount - creditApplied, 0);
                            if (totalMode && partialAmountInput) {
                                partialAmountInput.value = cashEntered.toFixed(2);
                            }
                            if (totalMode && cashEntered + 0.01 < netAmount) {
                                event.preventDefault();
                                showFacturaSubmitError(`El monto pagado debe ser al menos el neto calculado de ${formatMoney(netAmount, currentVencidosState.currencySymbol)}.`);
                                return;
                            }
                            if (totalMode || cashEntered + 0.01 >= netAmount) {
                                showWithholdingWarning(
                                    invoiceAmount,
                                    currentVencidosState.esRetencion,
                                    facturaDecision,
                                    {
                                        getEnteredAmount: () => cashEntered,
                                        getNetAmount: () => netAmount,
                                        getGrossAmount: () => grossDue,
                                        submit: () => serviceFacturaForm.requestSubmit(),
                                        onPaid: openFacturaWithholdingProof
                                    }
                                );
                                return;
                            }
                        }
                    }

                    event.preventDefault();
                    if (facturaSubmitBtn) {
                        facturaSubmitBtn.disabled = true;
                        facturaSubmitBtn.textContent = isFacturarYCancelar ? 'Registrando cobro...' : 'Guardando factura...';
                    }

                    const formData = new FormData(serviceFacturaForm);
                    const facturaPeriodEnd = document.getElementById('factura-period-end');
                    if (
                        facturaPeriodEnd
                        && facturaPeriodEnd.value === (facturaPeriodEnd.dataset.basePeriodEnd || '')
                    ) {
                        formData.delete(facturaPeriodEnd.name);
                    }
                    fetch(serviceFacturaForm.action, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                        .then(async (response) => {
                            let result;
                            try {
                                result = await response.json();
                            } catch {
                                throw new Error('El servidor devolvió una respuesta que no se pudo leer.');
                            }
                            if (!response.ok || !result.success) {
                                const validationMessages = Object.values(result.errors || {})
                                    .flat()
                                    .filter(Boolean);
                                throw new Error(validationMessages.join('\n') || result.message || 'No se pudo registrar la factura y el cobro.');
                            }
                            sessionStorage.setItem('cxc-pending-success-notice', JSON.stringify({
                                message: result.message || 'Factura y cobro registrados correctamente.',
                                tab: 'servicios'
                            }));
                            const url = new URL(window.location.href);
                            url.searchParams.set('tab', 'servicios');
                            url.hash = '';
                            window.location.assign(url.toString());
                        })
                        .catch((error) => {
                            if (facturaSubmitBtn) {
                                facturaSubmitBtn.disabled = false;
                                facturaSubmitBtn.textContent = isFacturarYCancelar ? 'Guardar y Cancelar' : 'Guardar Factura';
                            }
                            showFacturaSubmitError(error.message || 'No se pudo completar el envío. Revisa tu conexión e inténtalo nuevamente.');
                        });
                });
            }

            function closeFacturaModal() {
                const serviceFacturaModal = document.getElementById('service-factura-modal');
                if (!serviceFacturaModal) return;
                clearFacturaSubmitError();
                const facturaForm = document.getElementById('service-factura-form');
                const facturaFileName = document.getElementById('factura-file-name');
                const facturaPaymentFileName = document.getElementById('factura-payment-file-name');
                const facturaUploadBox = document.getElementById('factura-upload-box');
                const facturaPaymentUploadBox = document.getElementById('factura-payment-upload-box');
                const selectedServiceInputs = document.getElementById('factura-selected-service-inputs');
                const facturaFile = document.getElementById('factura-file');
                const facturaPaymentFile = document.getElementById('factura-comprobante');

                if (facturaForm) facturaForm.reset();
                if (selectedServiceInputs) selectedServiceInputs.replaceChildren();
                if (facturaFile) facturaFile.value = '';
                if (facturaPaymentFile) facturaPaymentFile.value = '';
                if (facturaFileName) facturaFileName.textContent = '';
                if (facturaPaymentFileName) facturaPaymentFileName.textContent = '';
                document.querySelectorAll('[data-clear-upload]').forEach((button) => button.classList.add('hidden'));
                facturaUploadBox?.classList.remove('is-invalid', 'is-dragging');
                facturaPaymentUploadBox?.classList.remove('is-invalid', 'is-dragging');
                document.getElementById('factura-payment-currency-extra')?.classList.add('hidden');
                document.getElementById('factura-partial-payment-field')?.classList.add('hidden');
                facturaAdvanceFields?.classList.add('hidden');
                facturaAmountWasEdited = false;
                if (facturaAdvanceMonths) facturaAdvanceMonths.value = '';
                if (facturaAdvanceToggle) facturaAdvanceToggle.textContent = 'Pagar adelantado';
                if (facturaModeToggle) applyFacturaModeState(false);
                serviceFacturaModal.dataset.rawAmount = '';
                serviceFacturaModal.dataset.grossAmount = '';
                serviceFacturaModal.dataset.currencySymbol = '';
                if (facturaForm) facturaForm.action = '';
                serviceFacturaModal.classList.remove('visible');
                serviceFacturaModal.classList.add('hidden');
                document.body.style.overflow = '';
            }

            document.addEventListener('click', function (e) {
                if (e.target.closest('[data-close-service-factura]')) {
                    closeFacturaModal();
                }
                if (e.target.closest('[data-close-service-payment]')) {
                    closeServicePaymentModal();
                }
                if (e.target.closest('[data-close-baja-option]')) {
                    const bajaOptionModal = document.getElementById('baja-option-modal');
                    if (bajaOptionModal) {
                        bajaOptionModal.classList.remove('visible');
                        bajaOptionModal.classList.add('hidden');
                    }
                    document.body.style.overflow = '';
                }
                if (e.target.closest('[data-close-baja-comment]')) {
                    const bajaCommentModal = document.getElementById('baja-comment-modal');
                    if (bajaCommentModal) {
                        bajaCommentModal.classList.remove('visible');
                        bajaCommentModal.classList.add('hidden');
                    }
                    document.body.style.overflow = '';
                }
                if (e.target.closest('[data-close-baja-sim]')) {
                    const bajaSimModal = document.getElementById('baja-sim-modal');
                    if (bajaSimModal) {
                        bajaSimModal.classList.remove('visible');
                        bajaSimModal.classList.add('hidden');
                    }
                    document.body.style.overflow = '';
                }
            });

            function bindServiceResultActions(root) {
                root.querySelectorAll('.btn-annul-selected-vehicles').forEach(button => {
                    button.addEventListener('click', function (event) {
                        event.stopPropagation();
                        const groupId = this.dataset.clientId || '';
                        const operations = Array.from(document.querySelectorAll('.stair-check-item:checked'))
                            .filter(checkbox => checkbox.dataset.clientId === groupId)
                            .map(checkbox => {
                                const revertButton = checkbox.closest('tr')?.querySelector('.btn-revert-payment');
                                return revertButton ? {
                                    url: revertButton.dataset.revertUrl || '',
                                    serviceId: revertButton.dataset.revertServiceId || ''
                                } : null;
                            })
                            .filter(operation => operation?.url && operation.serviceId);
                        const uniqueOperations = Array.from(
                            new Map(operations.map(operation => [operation.serviceId, operation])).values()
                        );

                        if (uniqueOperations.length === 0) {
                            showServiceMessage('Selecciona vehículos que tengan disponible la acción Anular.');
                            return;
                        }

                        openRevertPaymentModal(null, uniqueOperations);
                    });
                });

                root.querySelectorAll('.btn-manage-selected-vehicles').forEach(btn => {
                    btn.addEventListener('click', function (event) {
                        event.stopPropagation();
                        const groupId = this.dataset.clientId || '';
                        const selected = Array.from(document.querySelectorAll('.stair-check-item:checked'))
                            .filter(checkbox => checkbox.dataset.clientId === groupId);
                        const vehicles = selected.map(checkbox => {
                            const action = checkbox.closest('tr')?.querySelector('.btn-pay-vehicle');
                            return action ? { checkbox, action } : null;
                        }).filter(Boolean);

                        if (vehicles.length === 0 || vehicles.length !== selected.length) {
                            return;
                        }

                        const serviceIds = vehicles
                            .filter(({ checkbox }) => checkbox.dataset.creditInstallment !== '1')
                            .map(({ checkbox }) => checkbox.value);
                        const cxcIds = [...new Set(vehicles.flatMap(({ action }) =>
                            (action.dataset.cxcIds || action.dataset.cxcId || '').split(',').filter(Boolean)
                        ))];
                        const amount = vehicles.reduce((total, { action }) =>
                            total + Number(action.dataset.paymentAmount || action.dataset.amount || 0), 0);
                        const withholdingStates = [
                            'CANCELADO DETRACCIÓN',
                            'PENDIENTE AUTODETRACCIÓN',
                            'PENDIENTE AUTORETENCIÓN',
                            'PENDIENTE A DETRACCIÓN',
                            'PENDIENTE A RETENCIÓN',
                        ];
                        const selectedStates = vehicles.map(({ action }) =>
                            (action.dataset.cxcEstado || '').toUpperCase()
                        );
                        if (selectedStates.some(state => withholdingStates.includes(state))) {
                            showServiceMessage('Los depósitos de detracción o retención deben gestionarse por separado.');
                            return;
                        }

                        const needsInvoice = vehicles.map(({ action }) => {
                            if (action.dataset.creditInstallment === '1') return false;
                            const state = (action.dataset.cxcEstado || '').toUpperCase();
                            const docRef = action.dataset.docRef || '';
                            return ['1', '4', 'PENDIENTE', 'VENCIDO'].includes(state)
                                || !docRef;
                        });
                        const partialPaymentStates = selectedStates.map(state => state === 'PENDIENTE PAGO PARCIAL');
                        if (partialPaymentStates.some(Boolean) && !partialPaymentStates.every(Boolean)) {
                            showServiceMessage('Los pagos pendientes parciales deben gestionarse por separado de otros estados.');
                            return;
                        }
                        if (partialPaymentStates.every(Boolean)) {
                            const firstAction = vehicles[0].action;
                            const partialAmount = vehicles.reduce((total, { action }) =>
                                total + Number(action.dataset.paymentAmount || 0), 0);
                            openPartialPaymentModal(
                                cxcIds[0] || '',
                                cxcIds,
                                serviceIds,
                                firstAction.dataset.clientName || '-',
                                partialAmount,
                                firstAction.dataset.docRef || '',
                                firstAction.dataset.currencySymbol || 'S/',
                                firstAction.dataset.monedaId || '',
                                firstAction.dataset.tipoCobroId || '',
                                firstAction.dataset.clientId || '',
                                firstAction.dataset.nextPeriodEnd || firstAction.dataset.periodEnd || ''
                            );
                            return;
                        }
                        if (needsInvoice.some(Boolean) && needsInvoice.some(value => !value)) {
                            showServiceMessage('Selecciona vehículos con el mismo estado de facturación para gestionarlos juntos.');
                            return;
                        }

                        const firstAction = vehicles[0].action;
                        const clientName = firstAction.dataset.clientName || '-';
                        const currency = firstAction.dataset.currencySymbol || 'S/';
                        const tipoCobroIds = [...new Set(vehicles
                            .map(({ action }) => action.dataset.tipoCobroId)
                            .filter(Boolean))];
                        const tipoCobroId = tipoCobroIds[0] || firstAction.dataset.tipoCobroId || '';
                        const clientId = firstAction.dataset.clientId || '';
                        const firstPeriodEnd = firstAction.dataset.nextPeriodEnd || firstAction.dataset.periodEnd || '';
                        if (needsInvoice.every(Boolean)) {
                            openFacturaModal(
                                cxcIds[0] || '',
                                clientName,
                                amount,
                                '',
                                '',
                                `${currency} ${amount.toFixed(2)}`,
                                firstAction.dataset.monedaId || '',
                                currency,
                                serviceIds,
                                tipoCobroId,
                                clientId,
                                firstPeriodEnd,
                                cxcIds
                            );
                        } else {
                            openCustomPaymentModal(
                                serviceIds,
                                cxcIds[0] || '',
                                clientName,
                                amount,
                                '',
                                `${currency} ${amount.toFixed(2)}`,
                                firstAction.dataset.monedaId || '',
                                false,
                                tipoCobroId,
                                clientId,
                                firstPeriodEnd,
                                cxcIds,
                                0
                            );
                        }
                    });
                });

                root.querySelectorAll('.btn-edit-selected-service-price').forEach(btn => {
                    btn.addEventListener('click', function (event) {
                        event.stopPropagation();
                        const groupId = this.dataset.clientId || '';
                        const selected = Array.from(document.querySelectorAll('.stair-check-item:checked'))
                            .filter(checkbox => checkbox.dataset.clientId === groupId);
                        const editableButtons = selected
                            .filter(checkbox => checkbox.dataset.creditInstallment !== '1')
                            .map(checkbox => checkbox.closest('tr')?.querySelector('.btn-edit-service-price'))
                            .filter(Boolean);

                        if (editableButtons.length === 0) {
                            showServiceMessage('Selecciona al menos un vehículo con precio editable.');
                            return;
                        }

                        openBulkServicePriceModal(editableButtons);
                    });
                });

                root.querySelectorAll('.btn-manage-client').forEach(btn => {
                    btn.addEventListener('click', function (e) {
                        e.stopPropagation();
                        const serviceIds = (this.dataset.serviceIds || '').split(',').filter(Boolean);
                        const clientName = this.dataset.clientName || '-';
                        const amount = Number(this.dataset.amount ?? this.dataset.paymentAmount ?? 0);
                        const paymentAmount = Number(this.dataset.paymentAmount ?? this.dataset.amount ?? 0);
                        const cxcId = this.dataset.cxcId || '';
                        const cxcIds = (this.dataset.cxcIds || '').split(',').filter(Boolean);
                        const cxcEstado = (this.dataset.cxcEstado || 'PENDIENTE').toUpperCase();
                        const docRef = this.dataset.docRef || '';
                        const isWithholdingPending = ['CANCELADO DETRACCIÓN', 'PENDIENTE AUTODETRACCIÓN', 'PENDIENTE AUTORETENCIÓN', 'PENDIENTE A DETRACCIÓN', 'PENDIENTE A RETENCIÓN'].includes(cxcEstado);
                        const withholdingType = cxcEstado.includes('AUTODETRACCIÓN') ? 'autodetracción' : (cxcEstado.includes('RETENCIÓN') ? 'retención' : 'detracción');
                        const isCancelado = cxcEstado === '3' || cxcEstado === 'CANCELADO';
                        const clientId = this.dataset.clientId || '';

                        const tipoCobroId = this.dataset.tipoCobroId || '';
                        if (openNextCreditInstallmentPayment(this, clientName)) return;
                        if (isWithholdingPending) {
                            openCustomPaymentModal(serviceIds, cxcId, clientName, paymentAmount, docRef, this.dataset.amountDisplay || '', this.dataset.monedaId || '', false, tipoCobroId, clientId, this.dataset.nextPeriodEnd || this.dataset.periodEnd || '', cxcIds, 0, true, withholdingType);
                        } else if (isCancelado) {
                            const paymentDetailCxcIds = (this.dataset.paymentDetailCxcIds || '').split(',').filter(Boolean);
                            openCustomPaymentModal(serviceIds, cxcId, clientName, amount, docRef, this.dataset.amountDisplay || '', this.dataset.monedaId || '', true, tipoCobroId, clientId, this.dataset.nextPeriodEnd || this.dataset.periodEnd || '', cxcIds, Number(this.dataset.advanceMonths || 0), false, 'detracción', paymentDetailCxcIds);
                        } else if (cxcEstado === 'PENDIENTE PAGO PARCIAL') {
                            openPartialPaymentModal(
                                cxcId,
                                cxcIds,
                                serviceIds,
                                clientName,
                                paymentAmount,
                                docRef,
                                this.dataset.currencySymbol || 'S/',
                                this.dataset.monedaId || '',
                                tipoCobroId,
                                clientId,
                                this.dataset.nextPeriodEnd || this.dataset.periodEnd || ''
                            );
                        } else if (['1', '4', 'PENDIENTE', 'VENCIDO'].includes(cxcEstado) || !docRef) {
                            openFacturaModal(cxcId, clientName, amount, docRef, '', this.dataset.amountDisplay || '', this.dataset.monedaId || '', this.dataset.currencySymbol || '', serviceIds, tipoCobroId, clientId, this.dataset.nextPeriodEnd || this.dataset.periodEnd || '', cxcIds);
                        } else {
                            openCustomPaymentModal(serviceIds, cxcId, clientName, paymentAmount, docRef, this.dataset.amountDisplay || '', this.dataset.monedaId || '', false, tipoCobroId, clientId, this.dataset.nextPeriodEnd || this.dataset.periodEnd || '', cxcIds, Number(this.dataset.advanceMonths || 0));
                        }
                    });
                });

                root.querySelectorAll('.btn-revert-payment').forEach(btn => {
                    btn.addEventListener('click', function (e) {
                        e.stopPropagation();
                        openRevertPaymentModal(this);
                    });
                });

            }

            function openRevertPaymentModal(button, bulkOperations = []) {
                const modal = document.getElementById('revert-payment-modal');
                const form = document.getElementById('revert-payment-form');
                const reason = document.getElementById('revert-payment-reason');
                if (!modal || !form || !reason) return;

                selectedBulkRevertOperations = bulkOperations;
                form.action = bulkOperations.length > 0
                    ? bulkOperations[0].url
                    : button?.dataset.revertUrl || '';
                form.querySelectorAll('[data-revert-cxc], [data-revert-service]').forEach(input => input.remove());
                (button?.dataset.revertCxcIds || '').split(',').filter(Boolean).forEach(id => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'cxc_ids[]';
                    input.value = id;
                    input.dataset.revertCxc = '1';
                    form.appendChild(input);
                });
                if (button?.dataset.revertServiceId) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'servicio_id';
                    input.value = button.dataset.revertServiceId;
                    input.dataset.revertService = '1';
                    form.appendChild(input);
                }
                const title = document.getElementById('revert-payment-title');
                const description = document.getElementById('revert-payment-description');
                if (title) title.textContent = bulkOperations.length > 0
                    ? `Revertir ${bulkOperations.length} cancelaciones`
                    : 'Revertir cancelación';
                if (description) description.textContent = bulkOperations.length > 0
                    ? 'Las cuentas seleccionadas volverán al flujo de facturación y pago.'
                    : 'La CXC volverá al flujo de facturación y pago.';
                document.getElementById('revert-payment-error')?.classList.add('hidden');
                reason.value = '';
                modal.classList.remove('hidden');
                modal.classList.add('visible');
                document.body.style.overflow = 'hidden';
                reason.focus();
            }

            function closeRevertPaymentModal() {
                const modal = document.getElementById('revert-payment-modal');
                const form = document.getElementById('revert-payment-form');
                if (form) form.reset();
                selectedBulkRevertOperations = [];
                document.getElementById('revert-payment-error')?.classList.add('hidden');
                if (modal) {
                    modal.classList.remove('visible');
                    modal.classList.add('hidden');
                }
                document.body.style.overflow = '';
            }

            document.querySelectorAll('[data-close-revert-payment]').forEach(button => {
                button.addEventListener('click', closeRevertPaymentModal);
            });

            document.getElementById('revert-payment-modal')?.addEventListener('click', function (event) {
                if (event.target === this) closeRevertPaymentModal();
            });

            document.getElementById('revert-payment-form')?.addEventListener('submit', async function (event) {
                if (selectedBulkRevertOperations.length === 0) return;
                event.preventDefault();
                if (!this.reportValidity()) return;

                const form = this;
                const submitButton = document.getElementById('revert-payment-submit');
                const errorMessage = document.getElementById('revert-payment-error');
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.textContent = 'Procesando...';
                }
                errorMessage?.classList.add('hidden');
                if (errorMessage) errorMessage.textContent = '';

                const operationCount = selectedBulkRevertOperations.length;
                let revertedCount = 0;
                try {
                    for (const operation of selectedBulkRevertOperations) {
                        const body = new FormData(form);
                        body.append('servicio_id', operation.serviceId);
                        const response = await fetch(operation.url, {
                            method: 'POST',
                            body,
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });
                        const result = await response.json();
                        if (!response.ok || !result.success) {
                            throw new Error(result.message || 'No se pudo revertir el pago.');
                        }
                        revertedCount++;
                    }

                    closeRevertPaymentModal();
                    showServiceMessage(`Se revirtieron ${revertedCount} pagos correctamente.`);
                    const params = new URLSearchParams(cxcQuery);
                    params.set('tab', 'servicios');
                    loadCxcResults(`${window.location.pathname}?${params.toString()}`, 'servicios');
                } catch (error) {
                    if (revertedCount > 0) {
                        closeRevertPaymentModal();
                        showServiceMessage(`Se revirtieron ${revertedCount} de ${operationCount} pagos. ${error.message || 'La operación se detuvo por un error.'}`);
                        const params = new URLSearchParams(cxcQuery);
                        params.set('tab', 'servicios');
                        loadCxcResults(`${window.location.pathname}?${params.toString()}`, 'servicios');
                    } else {
                        if (errorMessage) {
                            errorMessage.textContent = error.message || 'No se pudieron revertir los pagos.';
                            errorMessage.classList.remove('hidden');
                        }
                        if (submitButton) {
                            submitButton.disabled = false;
                            submitButton.textContent = 'Confirmar reversión';
                        }
                    }
                }
            });

            function openNextCreditInstallmentPayment(button, clientName) {
                const isInstallment = button.dataset.creditInstallment === '1';
                const currency = button.dataset.currencySymbol || 'S/';
                let selectedInstallments = [];
                let installmentAmount = 0;
                let targetCxcIds = [];

                if (isInstallment) {
                    targetCxcIds = [...new Set((button.dataset.cxcIds || '').split(',').filter(Boolean))];
                    if (targetCxcIds.length === 0 && button.dataset.cxcId) {
                        targetCxcIds = [button.dataset.cxcId];
                    }
                    installmentAmount = Number(button.dataset.paymentAmount || button.dataset.amount || 0);
                    selectedInstallments = [{
                        ids: targetCxcIds,
                        amount: installmentAmount,
                        number: Number(button.dataset.creditTargetNumber || 0),
                        date: button.dataset.creditInstallmentDate || '',
                    }];
                } else if (button.dataset.creditPlan === '1') {
                    try {
                        selectedInstallments = JSON.parse(button.dataset.creditPlanInstallments || '[]')
                            .filter((installment) => Number(installment.amount) > 0 && Array.isArray(installment.ids) && installment.ids.length > 0);
                    } catch (error) {
                        showServiceMessage('No se pudieron cargar las cuotas pendientes de este crédito.');
                        return true;
                    }
                    if (selectedInstallments.length === 0) return false;
                    const firstInstallment = selectedInstallments[0];
                    targetCxcIds = [...new Set(firstInstallment.ids.map(String))];
                    installmentAmount = Number(firstInstallment.amount);
                } else {
                    return false;
                }

                if (targetCxcIds.length === 0 || !Number.isFinite(installmentAmount) || installmentAmount <= 0) return false;
                const targetCxcId = targetCxcIds[0];
                openCustomPaymentModal(
                    [],
                    targetCxcId,
                    clientName,
                    installmentAmount,
                    button.dataset.docRef || '',
                    `${currency} ${installmentAmount.toFixed(2)}`,
                    button.dataset.monedaId || '',
                    false,
                    button.dataset.tipoCobroId || '',
                    '',
                    '',
                    targetCxcIds,
                    0
                );
                const modal = document.getElementById('service-payment-modal');
                const fixedMethod = document.getElementById('payment-fixed-method');
                const paymentMethodSelect = document.getElementById('payment-method');
                const paymentPeriodEnd = document.getElementById('payment-period-end');
                const paymentOptions = document.getElementById('payment-credit-plan-options');
                const paymentOptionsList = document.getElementById('payment-credit-plan-list');
                const totalPaymentRadio = modal?.querySelector('input[name="modo_pago"][value="total"]');
                const partialPaymentRadio = modal?.querySelector('input[name="modo_pago"][value="parcial"]');

                if (!modal) return true;
                modal.dataset.creditPaymentMode = isInstallment ? 'installment' : 'plan';
                modal.dataset.grossAmount = String(installmentAmount);
                modal.dataset.expectedCashDue = String(installmentAmount);
                modal.dataset.deductionAmount = '0';
                document.getElementById('payment-method-field')?.classList.add('hidden');
                document.getElementById('payment-bank-field')?.classList.add('hidden');
                document.getElementById('payment-currency-settings')?.classList.add('hidden');
                document.getElementById('payment-mode-field')?.classList.add('hidden');
                document.getElementById('payment-credit-installments')?.classList.add('hidden');
                document.getElementById('btn-payment-advance-toggle')?.classList.add('hidden');
                [
                    'btn-periodos-vencidos',
                    'payment-select-credit-note',
                    'payment-select-credit-amount',
                ].forEach((id) => {
                    const control = document.getElementById(id);
                    if (!control) return;
                    if (control.dataset.hiddenForCreditPayment !== '1') {
                        control.dataset.creditPaymentWasHidden = control.classList.contains('hidden') ? '1' : '0';
                    }
                    control.dataset.hiddenForCreditPayment = '1';
                    control.classList.add('hidden');
                });
                if (fixedMethod && paymentMethodSelect) {
                    fixedMethod.value = paymentMethodSelect.value;
                    fixedMethod.disabled = false;
                    paymentMethodSelect.disabled = true;
                    paymentMethodSelect.required = false;
                }
                if (paymentPeriodEnd) {
                    paymentPeriodEnd.value = '';
                    paymentPeriodEnd.required = false;
                }
                if (paymentOriginal) paymentOriginal.readOnly = true;
                if (paymentAmount) {
                    paymentAmount.value = installmentAmount.toFixed(2);
                    paymentAmount.required = false;
                }
                if (totalPaymentRadio) totalPaymentRadio.checked = true;
                if (partialPaymentRadio) partialPaymentRadio.checked = false;

                if (!isInstallment && paymentOptions && paymentOptionsList) {
                    paymentOptionsList.replaceChildren();
                    selectedInstallments.forEach((installment, index) => {
                        const ids = [...new Set(installment.ids.map(String))];
                        const amount = Number(installment.amount);
                        const dueDate = String(installment.date || '');
                        const row = document.createElement('label');
                        row.className = 'flex cursor-pointer items-center justify-between gap-3 rounded-md border border-amber-200 bg-white px-3 py-2 text-sm';
                        const checkbox = document.createElement('input');
                        checkbox.type = 'checkbox';
                        checkbox.name = 'credit_installment_selection';
                        checkbox.value = String(index);
                        checkbox.checked = index === 0;
                        checkbox.className = `duration-100 ease-in-out shadow-sm border-slate-200 cursor-pointer rounded focus:ring-4 focus:ring-offset-0 focus:ring-primary focus:ring-opacity-20 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&[type="radio"]]:checked:bg-primary [&[type="radio"]]:checked:border-primary [&[type="radio"]]:checked:border-opacity-10 [&[type="checkbox"]]:checked:bg-primary [&[type="checkbox"]]:checked:border-primary [&[type="checkbox"]]:checked:border-opacity-10 [&:disabled:not(:checked)]:bg-slate-100 [&:disabled:not(:checked)]:cursor-not-allowed [&:disabled:not(:checked)]:dark:bg-darkmode-800/50 [&:disabled:checked]:opacity-70 [&:disabled:checked]:cursor-not-allowed [&:disabled:checked]:dark:bg-darkmode-800/50`; 
                        checkbox.dataset.cxcIds = ids.join(',');
                        checkbox.dataset.amount = String(amount);

                        const details = document.createElement('span');
                        details.className = 'min-w-0 flex-1 text-slate-700';
                        const dateLabel = dueDate
                            ? new Date(`${dueDate}T00:00:00`).toLocaleDateString('es-PE')
                            : 'sin fecha';
                        details.textContent = `Cuota ${installment.number || index + 1} · vence ${dateLabel}`;

                        const amountLabel = document.createElement('strong');
                        amountLabel.className = 'whitespace-nowrap text-slate-900';
                        amountLabel.textContent = `${currency} ${amount.toFixed(2)}`;
                        row.append(checkbox, details, amountLabel);
                        paymentOptionsList.appendChild(row);
                    });
                    paymentOptions.classList.remove('hidden');

                    paymentOptionsList.onchange = function (event) {
                        if (event.target?.type !== 'checkbox') return;
                        const checkedInstallments = Array.from(paymentOptionsList.querySelectorAll('input[type="checkbox"]:checked'));
                        const selectedIds = [...new Set(checkedInstallments.flatMap((checkbox) => checkbox.dataset.cxcIds.split(',').filter(Boolean)))];
                        const selectedAmount = checkedInstallments.reduce((total, checkbox) => total + Number(checkbox.dataset.amount || 0), 0);
                        const cxcIdInput = document.getElementById('payment-cxc-id');
                        const invoiceTotal = document.getElementById('payment-invoice-total');
                        const selectedCxcInputs = document.getElementById('payment-selected-cxc-inputs');
                        if (cxcIdInput) cxcIdInput.value = selectedIds[0] || '';
                        if (invoiceTotal) invoiceTotal.value = selectedAmount.toFixed(2);
                        if (selectedCxcInputs) {
                            selectedCxcInputs.replaceChildren(...selectedIds.map((id) => {
                                const input = document.createElement('input');
                                input.type = 'hidden';
                                input.name = 'cxc_ids[]';
                                input.value = id;
                                return input;
                            }));
                        }
                        if (paymentOriginal) paymentOriginal.value = selectedAmount.toFixed(2);
                        if (paymentAmount) paymentAmount.value = selectedAmount.toFixed(2);
                        modal.dataset.grossAmount = String(selectedAmount);
                        modal.dataset.expectedCashDue = String(selectedAmount);
                        syncPaymentModeUI();
                    };
                } else {
                    paymentOptions?.classList.add('hidden');
                }
                syncPaymentModeUI();
                return true;
            }

            function bindServiceResultPaymentActions(root) {
                root.querySelectorAll('.btn-revert-payment[data-revert-service-id]').forEach(button => {
                    const checkbox = button.closest('tr')?.querySelector('.stair-check-item');
                    if (checkbox) checkbox.disabled = false;
                });

                root.querySelectorAll('.btn-pay-service-type').forEach(btn => {
                    btn.addEventListener('click', function (e) {
                        e.stopPropagation();
                        const serviceIds = (this.dataset.serviceIds || '').split(',').filter(Boolean);
                        const clientName = this.dataset.clientName || '-';
                        const amount = Number(this.dataset.amount ?? this.dataset.paymentAmount ?? 0);
                        const paymentAmount = Number(this.dataset.paymentAmount ?? this.dataset.amount ?? 0);
                        const cxcId = this.dataset.cxcId || '';
                        const cxcIds = (this.dataset.cxcIds || '').split(',').filter(Boolean);
                        const cxcEstado = (this.dataset.cxcEstado || 'PENDIENTE').toUpperCase();
                        const docRef = this.dataset.docRef || '';
                        const isWithholdingPending = ['CANCELADO DETRACCIÓN', 'PENDIENTE AUTODETRACCIÓN', 'PENDIENTE AUTORETENCIÓN', 'PENDIENTE A DETRACCIÓN', 'PENDIENTE A RETENCIÓN'].includes(cxcEstado);
                        const withholdingType = cxcEstado.includes('AUTODETRACCIÓN') ? 'autodetracción' : (cxcEstado.includes('RETENCIÓN') ? 'retención' : 'detracción');
                        const isCancelado = cxcEstado === '3' || cxcEstado === 'CANCELADO';
                        const clientId = this.dataset.clientId || '';

                        const tipoCobroId = this.dataset.tipoCobroId || '';
                        if (openNextCreditInstallmentPayment(this, clientName)) return;
                        if (isWithholdingPending) {
                            openCustomPaymentModal(serviceIds, cxcId, clientName, paymentAmount, docRef, this.dataset.amountDisplay || '', this.dataset.monedaId || '', false, tipoCobroId, clientId, this.dataset.nextPeriodEnd || this.dataset.periodEnd || '', cxcIds, 0, true, withholdingType);
                        } else if (isCancelado) {
                            openCustomPaymentModal(serviceIds, cxcId, clientName, amount, docRef, this.dataset.amountDisplay || '', this.dataset.monedaId || '', true, tipoCobroId, clientId, this.dataset.nextPeriodEnd || this.dataset.periodEnd || '', cxcIds, Number(this.dataset.advanceMonths || 0));
                        } else if (cxcEstado === 'PENDIENTE PAGO PARCIAL') {
                            openPartialPaymentModal(
                                cxcId,
                                cxcIds,
                                serviceIds,
                                clientName,
                                paymentAmount,
                                docRef,
                                this.dataset.currencySymbol || 'S/',
                                this.dataset.monedaId || '',
                                tipoCobroId,
                                clientId,
                                this.dataset.nextPeriodEnd || this.dataset.periodEnd || ''
                            );
                        } else if (['1', '4', 'PENDIENTE', 'VENCIDO'].includes(cxcEstado) || !docRef) {
                            openFacturaModal(cxcId, clientName, amount, docRef, '', this.dataset.amountDisplay || '', this.dataset.monedaId || '', this.dataset.currencySymbol || '', serviceIds, tipoCobroId, clientId, this.dataset.nextPeriodEnd || this.dataset.periodEnd || '', cxcIds);
                        } else {
                            openCustomPaymentModal(serviceIds, cxcId, clientName, paymentAmount, docRef, this.dataset.amountDisplay || '', this.dataset.monedaId || '', false, tipoCobroId, clientId, this.dataset.nextPeriodEnd || this.dataset.periodEnd || '', cxcIds, Number(this.dataset.advanceMonths || 0));
                        }
                    });
                });

                root.querySelectorAll('.btn-pay-vehicle').forEach(btn => {
                    btn.addEventListener('click', function (e) {
                        e.stopPropagation();
                        const serviceId = this.dataset.serviceId;
                        const clientName = this.dataset.clientName || '-';
                        const amount = Number(this.dataset.amount ?? this.dataset.paymentAmount ?? 0);
                        const paymentAmount = Number(this.dataset.paymentAmount ?? this.dataset.amount ?? 0);
                        const cxcId = this.dataset.cxcId || '';
                        const cxcIds = (this.dataset.cxcIds || '').split(',').filter(Boolean);
                        const cxcEstado = (this.dataset.cxcEstado || 'PENDIENTE').toUpperCase();
                        const docRef = this.dataset.docRef || '';
                        const isWithholdingPending = ['CANCELADO DETRACCIÓN', 'PENDIENTE AUTODETRACCIÓN', 'PENDIENTE AUTORETENCIÓN', 'PENDIENTE A DETRACCIÓN', 'PENDIENTE A RETENCIÓN'].includes(cxcEstado);
                        const withholdingType = cxcEstado.includes('AUTODETRACCIÓN') ? 'autodetracción' : (cxcEstado.includes('RETENCIÓN') ? 'retención' : 'detracción');
                        const isCancelado = cxcEstado === '3' || cxcEstado === 'CANCELADO';
                        const clientId = this.dataset.clientId || '';

                        const tipoCobroId = this.dataset.tipoCobroId || '';
                        if (openNextCreditInstallmentPayment(this, clientName)) return;
                        if (isWithholdingPending) {
                            openCustomPaymentModal(serviceId ? [serviceId] : [], cxcId, clientName, paymentAmount, docRef, this.dataset.amountDisplay || '', this.dataset.monedaId || '', false, tipoCobroId, clientId, this.dataset.nextPeriodEnd || this.dataset.periodEnd || '', cxcIds, 0, true, withholdingType);
                        } else if (isCancelado) {
                            openCustomPaymentModal(serviceId ? [serviceId] : [], cxcId, clientName, amount, docRef, this.dataset.amountDisplay || '', this.dataset.monedaId || '', true, tipoCobroId, clientId, this.dataset.nextPeriodEnd || this.dataset.periodEnd || '', cxcIds, Number(this.dataset.advanceMonths || 0));
                        } else if (cxcEstado === 'PENDIENTE PAGO PARCIAL') {
                            openPartialPaymentModal(
                                cxcId,
                                cxcIds,
                                serviceId ? [serviceId] : [],
                                clientName,
                                paymentAmount,
                                docRef,
                                this.dataset.currencySymbol || 'S/',
                                this.dataset.monedaId || '',
                                tipoCobroId,
                                clientId,
                                this.dataset.nextPeriodEnd || this.dataset.periodEnd || ''
                            );
                        } else if (['1', '4', 'PENDIENTE', 'VENCIDO', 'PENDIENTE A CREDITO'].includes(cxcEstado) || !docRef) {
                            openFacturaModal(cxcId, clientName, amount, docRef, '', this.dataset.amountDisplay || '', this.dataset.monedaId || '', this.dataset.currencySymbol || '', serviceId ? [serviceId] : [], tipoCobroId, clientId, this.dataset.nextPeriodEnd || this.dataset.periodEnd || '', cxcIds);
                        } else if (serviceId) {
                            openCustomPaymentModal([serviceId], cxcId, clientName, amount, docRef, this.dataset.amountDisplay || '', this.dataset.monedaId || '', false, tipoCobroId, clientId, this.dataset.nextPeriodEnd || this.dataset.periodEnd || '', cxcIds, Number(this.dataset.advanceMonths || 0));
                        }
                    });
                });
            }

            bindServiceResultActions(document);
            bindServiceResultPaymentActions(document);

            function refreshSelectedVehiclePaymentButtons() {
                document.querySelectorAll('.btn-manage-selected-vehicles').forEach(button => {
                    const selected = Array.from(document.querySelectorAll('.stair-check-item:checked'))
                        .filter(checkbox => checkbox.dataset.clientId === button.dataset.clientId);
                    const manageable = selected.filter(checkbox =>
                        !checkbox.closest('tr')?.querySelector('.btn-revert-payment')
                    );
                    const selectedCount = manageable.length;
                    button.disabled = selectedCount === 0 || selectedCount !== selected.length;
                    button.textContent = `Gestionar seleccionados (${selectedCount})`;
                });
                document.querySelectorAll('.btn-annul-selected-vehicles').forEach(button => {
                    const groupCheckboxes = Array.from(document.querySelectorAll('.stair-check-item'))
                        .filter(checkbox =>
                            checkbox.dataset.clientId === button.dataset.clientId &&
                            checkbox.dataset.creditInstallment !== '1'
                        );
                    const hasAvailableAction = groupCheckboxes.some(checkbox =>
                        checkbox.closest('tr')?.querySelector('.btn-revert-payment[data-revert-service-id]')
                    );
                    const selectedCount = groupCheckboxes.filter(checkbox =>
                        checkbox.checked &&
                        checkbox.closest('tr')?.querySelector('.btn-revert-payment[data-revert-service-id]')
                    ).length;
                    button.disabled = selectedCount === 0;
                    button.classList.toggle('hidden', !hasAvailableAction);
                    button.style.display = hasAvailableAction ? '' : 'none';
                    button.textContent = `Anular seleccionados (${selectedCount})`;
                });
                document.querySelectorAll('.btn-edit-selected-service-price').forEach(button => {
                    const groupCheckboxes = Array.from(document.querySelectorAll('.stair-check-item'))
                        .filter(checkbox =>
                            checkbox.dataset.clientId === button.dataset.clientId &&
                            checkbox.dataset.creditInstallment !== '1'
                        );
                    const hasAvailableAction = groupCheckboxes.some(checkbox =>
                        checkbox.closest('tr')?.querySelector('.btn-edit-service-price')
                    );
                    const selectedCount = groupCheckboxes.filter(checkbox =>
                        checkbox.checked &&
                        checkbox.closest('tr')?.querySelector('.btn-edit-service-price')
                    ).length;
                    button.disabled = selectedCount === 0;
                    button.classList.toggle('hidden', !hasAvailableAction);
                    button.style.display = hasAvailableAction ? '' : 'none';
                    button.textContent = `Editar precio seleccionados (${selectedCount})`;
                });
            }
            refreshSelectedVehiclePaymentButtons();
            document.addEventListener('change', function (event) {
                if (event.target.matches('.stair-check-client, .stair-check-type, .stair-check-item')) {
                    refreshSelectedVehiclePaymentButtons();
                }
            });

            const servicePriceModal = document.getElementById('service-price-modal');
            const servicePriceForm = document.getElementById('service-price-form');
            const servicePriceId = document.getElementById('service-price-id');
            const servicePriceCxcId = document.getElementById('service-price-cxc-id');
            const servicePriceAmount = document.getElementById('service-price-amount');
            const servicePriceContext = document.getElementById('service-price-context');
            const servicePriceError = document.getElementById('service-price-error');
            const servicePriceSave = document.getElementById('service-price-save');
            const servicePriceBulkIds = document.getElementById('service-price-bulk-ids');
            const servicePriceTitle = document.getElementById('service-price-title');
            const servicePriceKeepFuture = document.getElementById('service-price-keep-future');
            const servicePriceConfirmModal = document.getElementById('service-price-confirm-modal');
            const servicePriceConfirmYes = document.getElementById('service-price-confirm-yes');
            const servicePriceConfirmNo = document.getElementById('service-price-confirm-no');
            let servicePriceSaveInProgress = false;

            function closeServicePriceModal() {
                servicePriceConfirmModal?.classList.remove('visible');
                servicePriceConfirmModal?.classList.add('hidden');
                servicePriceForm?.reset();
                servicePriceBulkIds?.replaceChildren();
                if (servicePriceCxcId) servicePriceCxcId.value = '';
                if (servicePriceTitle) servicePriceTitle.textContent = 'Editar precio del vehículo';
                servicePriceError?.classList.add('hidden');
                if (servicePriceError) servicePriceError.textContent = '';
                servicePriceSave?.removeAttribute('disabled');
                if (servicePriceSave) servicePriceSave.innerHTML = '<i data-lucide="save" aria-hidden="true"></i> Guardar precio';
                servicePriceModal?.classList.remove('visible');
                servicePriceModal?.classList.add('hidden');
                document.body.style.overflow = '';
                window.lucide?.createIcons?.();
            }

            function openBulkServicePriceModal(editButtons) {
                if (servicePriceId) servicePriceId.value = '';
                if (servicePriceCxcId) servicePriceCxcId.value = '';
                if (servicePriceAmount) servicePriceAmount.value = '';
                servicePriceBulkIds?.replaceChildren(...editButtons.map(button => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'bulk_service_ids[]';
                    input.value = button.dataset.serviceId || '';
                    input.dataset.cxcId = button.dataset.currentCxcId || '';
                    return input;
                }));
                if (servicePriceTitle) servicePriceTitle.textContent = 'Editar precio a seleccionados';
                if (servicePriceContext) {
                    servicePriceContext.textContent = `${editButtons.length} vehículos seleccionados`;
                }
                servicePriceError?.classList.add('hidden');
                if (servicePriceError) servicePriceError.textContent = '';
                servicePriceModal?.classList.remove('hidden');
                servicePriceModal?.classList.add('visible');
                document.body.style.overflow = 'hidden';
                servicePriceAmount?.focus();
            }

            function closeServicePriceConfirmation() {
                if (servicePriceSaveInProgress) return;
                servicePriceConfirmModal?.classList.remove('visible');
                servicePriceConfirmModal?.classList.add('hidden');
                servicePriceModal?.classList.remove('hidden');
                servicePriceModal?.classList.add('visible');
                document.body.style.overflow = 'hidden';
                servicePriceAmount?.focus();
            }

            document.addEventListener('click', function (event) {
                const editButton = event.target.closest('.btn-edit-service-price');
                if (editButton) {
                    event.preventDefault();
                    event.stopPropagation();
                    servicePriceBulkIds?.replaceChildren();
                    if (servicePriceTitle) servicePriceTitle.textContent = 'Editar precio del vehículo';
                    if (servicePriceId) servicePriceId.value = editButton.dataset.serviceId || '';
                    if (servicePriceCxcId) servicePriceCxcId.value = editButton.dataset.currentCxcId || '';
                    if (servicePriceAmount) servicePriceAmount.value = Number(editButton.dataset.servicePrice || 0).toFixed(2);
                    if (servicePriceContext) {
                        servicePriceContext.textContent = `${editButton.dataset.serviceName || 'Servicio'} · ${editButton.dataset.servicePlate || ''}`;
                    }
                    servicePriceError?.classList.add('hidden');
                    if (servicePriceError) servicePriceError.textContent = '';
                    servicePriceModal?.classList.remove('hidden');
                    servicePriceModal?.classList.add('visible');
                    document.body.style.overflow = 'hidden';
                    servicePriceAmount?.focus();
                    return;
                }
                if (event.target.closest('[data-close-service-price]')) closeServicePriceModal();
                if (event.target.closest('[data-close-service-price-confirm]')) closeServicePriceConfirmation();
            });

            servicePriceForm?.addEventListener('submit', function (event) {
                event.preventDefault();
                if (!servicePriceForm.reportValidity()) return;
                servicePriceModal?.classList.remove('visible');
                servicePriceModal?.classList.add('hidden');
                servicePriceConfirmModal?.classList.remove('hidden');
                servicePriceConfirmModal?.classList.add('visible');
            });

            async function saveServicePrices(keepPriceForNextPeriods) {
                if (servicePriceSaveInProgress) return;
                const serviceEntries = Array.from(
                    servicePriceBulkIds?.querySelectorAll('input[name="bulk_service_ids[]"]') || []
                ).map(input => ({
                    id: input.value,
                    cxcId: input.dataset.cxcId || ''
                })).filter(entry => entry.id);
                if (serviceEntries.length === 0 && servicePriceId?.value) {
                    serviceEntries.push({
                        id: servicePriceId.value,
                        cxcId: servicePriceCxcId?.value || ''
                    });
                }
                if (serviceEntries.length === 0) return;

                servicePriceSaveInProgress = true;
                if (servicePriceKeepFuture) {
                    servicePriceKeepFuture.value = keepPriceForNextPeriods ? '1' : '0';
                }
                servicePriceError?.classList.add('hidden');
                [servicePriceConfirmYes, servicePriceConfirmNo].forEach(button => {
                    if (button) button.disabled = true;
                });
                if (servicePriceConfirmYes) servicePriceConfirmYes.textContent = 'Guardando...';

                let updatedCount = 0;
                try {
                    for (const serviceEntry of serviceEntries) {
                        const url = (servicePriceForm.dataset.urlTemplate || '')
                            .replace('__ID__', encodeURIComponent(serviceEntry.id));
                        if (!url) throw new Error('No se encontró la ruta para actualizar el precio.');

                        const formData = new FormData(servicePriceForm);
                        if (serviceEntry.cxcId) {
                            formData.set('cuentasPorCobrar_idcuentasPorCobrar', serviceEntry.cxcId);
                        } else {
                            formData.delete('cuentasPorCobrar_idcuentasPorCobrar');
                        }
                        const response = await fetch(url, {
                            method: 'POST',
                            body: formData,
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });
                        const result = await response.json();
                        if (!response.ok || !result.success) {
                            const message = result.message || 'No se pudo actualizar el precio.';
                            throw new Error(updatedCount > 0
                                ? `Se actualizaron ${updatedCount} de ${serviceEntries.length} vehículos. ${message}`
                                : message);
                        }
                        updatedCount++;
                    }

                    closeServicePriceModal();
                    const params = new URLSearchParams(cxcQuery);
                    params.set('tab', 'servicios');
                    loadCxcResults(`${window.location.pathname}?${params.toString()}`, 'servicios');
                } catch (error) {
                    if (servicePriceError) {
                        servicePriceError.textContent = error.message || 'No se pudo actualizar el precio.';
                        servicePriceError.classList.remove('hidden');
                    }
                    servicePriceConfirmModal?.classList.remove('visible');
                    servicePriceConfirmModal?.classList.add('hidden');
                    servicePriceModal?.classList.remove('hidden');
                    servicePriceModal?.classList.add('visible');
                    document.body.style.overflow = 'hidden';
                    if (servicePriceSave) {
                        servicePriceSave.disabled = false;
                        servicePriceSave.innerHTML = '<i data-lucide="save" aria-hidden="true"></i> Guardar precio';
                    }
                    window.lucide?.createIcons?.();
                    if (updatedCount > 0) {
                        const params = new URLSearchParams(cxcQuery);
                        params.set('tab', 'servicios');
                        loadCxcResults(`${window.location.pathname}?${params.toString()}`, 'servicios');
                    }
                } finally {
                    servicePriceSaveInProgress = false;
                    [servicePriceConfirmYes, servicePriceConfirmNo].forEach(button => {
                        if (button) button.disabled = false;
                    });
                    if (servicePriceConfirmYes) servicePriceConfirmYes.textContent = 'Sí';
                }
            }

            servicePriceConfirmYes?.addEventListener('click', () => saveServicePrices(true));
            servicePriceConfirmNo?.addEventListener('click', () => saveServicePrices(false));

            function setWithholdingCompletionModalMode(isPending, amount = 0, symbol = 'S/', withholdingType = 'detracción', docRef = '') {
                const modal = document.getElementById('service-payment-modal');
                const grid = document.getElementById('payment-modal-fields-grid');
                const dateFields = document.getElementById('payment-date-fields');
                const proofField = document.getElementById('payment-proof-field');
                const clientField = document.getElementById('payment-client-field');
                const referenceField = document.getElementById('payment-reference-field');
                const amountField = document.getElementById('payment-amount-field');
                const descriptionField = document.getElementById('payment-description-field');
                const creditAmountButton = document.getElementById('payment-select-credit-amount');
                const paymentDateLabel = document.getElementById('payment-date-label');
                const paymentOriginalLabel = document.getElementById('payment-original-label');
                const proofLabel = document.getElementById('payment-proof-label');
                const dropzoneTitle = document.getElementById('payment-dropzone-title');
                const paymentOriginal = document.getElementById('payment-original');
                const submitButton = document.getElementById('payment-submit-btn');
                const modalTitle = document.getElementById('service-payment-title');
                const normalizedWithholdingType = String(withholdingType || 'detracción')
                    .normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
                const deductionLabel = normalizedWithholdingType.includes('retencion')
                    ? 'retención'
                    : (normalizedWithholdingType.includes('autodetraccion') || normalizedWithholdingType.includes('autodetraer'))
                        ? 'autodetracción'
                        : 'detracción';
                const deductionTitle = deductionLabel.charAt(0).toUpperCase() + deductionLabel.slice(1);
                const isCompletion = isPending;

                modal?.classList.toggle('is-withholding-completion', isPending);
                creditAmountButton?.classList.toggle('hidden', isCompletion);
                Array.from(grid?.children || []).forEach((field) => {
                    const visibleFields = [proofField, clientField, referenceField, amountField, descriptionField];
                    if (isCompletion && !visibleFields.includes(field)) {
                        field.dataset.hiddenForWithholding = '1';
                        field.classList.add('hidden');
                    } else if (field.dataset.hiddenForWithholding === '1') {
                        delete field.dataset.hiddenForWithholding;
                        field.classList.remove('hidden');
                    }
                });
                if (dateFields) {
                    dateFields.classList.toggle('is-withholding-date-row', isCompletion);
                }
                if (paymentDateLabel) {
                    paymentDateLabel.textContent = isPending
                        ? `Fecha de pago de ${deductionLabel}`
                        : 'Fecha de Pago';
                }
                if (paymentOriginalLabel) {
                    paymentOriginalLabel.textContent = isPending
                        ? `Monto ${deductionLabel}`
                        : 'Monto a cancelar';
                }
                if (proofLabel) {
                    proofLabel.textContent = isPending
                        ? 'Comprobante del depósito(opcional)'
                        : 'Comprobante de pago (opcional)';
                }
                if (dropzoneTitle) {
                    dropzoneTitle.textContent = isPending
                        ? 'Cargar comprobante del depósito(opcional)'
                        : 'Cargar comprobante de pago(opcional)';
                }
                if (paymentOriginal) {
                    paymentOriginal.readOnly = isCompletion;
                    paymentOriginal.value = Number(amount || 0).toFixed(2);
                }
                if (modalTitle && isPending) {
                    modalTitle.textContent = `Registrar comprobante de ${deductionTitle} pendiente`;
                }
                if (submitButton && isCompletion) {
                    submitButton.textContent = 'Registrar depósito';
                }

                const paymentFields = servicePaymentForm?.querySelectorAll('input, select, textarea') || [];
                paymentFields.forEach((field) => {
                    if (field.type === 'hidden' || field.name === '_token' || field.id === 'payment-comprobante' || field.id === 'payment-date' || field.id === 'payment-original' || field.id === 'payment-description') return;
                    if (isCompletion) {
                        if (field.dataset.disabledForWithholding !== '1') {
                            field.dataset.disabledBeforeWithholding = field.disabled ? '1' : '0';
                        }
                        field.dataset.disabledForWithholding = '1';
                        field.disabled = true;
                    } else if (field.dataset.disabledForWithholding === '1') {
                        field.disabled = field.dataset.disabledBeforeWithholding === '1';
                        delete field.dataset.disabledForWithholding;
                        delete field.dataset.disabledBeforeWithholding;
                    }
                });
            }

            async function openCustomPaymentModal(serviceIds, cxcId, clientName, amount, docRef = '', amountDisplay = '', monedaId = '', isViewOnly = false, tipoCobroId = '', clientId = '', periodEnd = '', cxcIds = [], advanceMonths = 0, isPendingWithholding = false, withholdingType = 'detracción', paymentDetailCxcIds = []) {
                const servicePaymentModal = document.getElementById('service-payment-modal');
                if (!servicePaymentModal) return;
                const requestToken = ++paymentDetailsRequestToken;
                setPaymentViewMode(false);
                servicePaymentModal.dataset.paymentCxcId = cxcId || '';
                servicePaymentModal.dataset.paymentDetailCxcIds = paymentDetailCxcIds.join(',');
                const pendingFlag = document.getElementById('payment-complete-withholding');
                if (pendingFlag) pendingFlag.value = isPendingWithholding ? '1' : '0';
                const withholdingDecision = document.getElementById('payment-withholding-decision');
                if (withholdingDecision) withholdingDecision.value = '';
                setWithholdingCompletionModalMode(isPendingWithholding, amount, resolveCurrencySymbol(amountDisplay, monedaId), withholdingType, docRef);
                const paymentComprobante = document.getElementById('payment-comprobante');
                if (paymentComprobante) paymentComprobante.required = isPendingWithholding;
                const paymentAdvanceMonths = document.getElementById('payment-advance-months');
                const paymentAdvanceSummary = document.getElementById('payment-advance-summary');
                const hasAdvance = Number(advanceMonths) > 1;
                if (paymentAdvanceMonths) paymentAdvanceMonths.value = hasAdvance ? String(advanceMonths) : '';
                if (paymentAdvanceMonthsCount) {
                    paymentAdvanceMonthsCount.value = '';
                    paymentAdvanceMonthsCount.disabled = true;
                    paymentAdvanceMonthsCount.required = false;
                }
                paymentAdvanceFields?.classList.add('hidden');
                if (paymentAdvanceToggle) {
                    paymentAdvanceToggle.textContent = 'Pagar adelantado';
                    paymentAdvanceToggle.setAttribute('aria-expanded', 'false');
                    paymentAdvanceToggle.disabled = isViewOnly || hasAdvance;
                }
                paymentAmountWasEdited = false;
                if (paymentAdvanceSummary) {
                    paymentAdvanceSummary.textContent = hasAdvance
                        ? `Este monto incluye ${advanceMonths} mensualidades (periodo actual y meses adelantados).`
                        : '';
                    paymentAdvanceSummary.classList.toggle('hidden', !hasAdvance);
                }
                const amountSummary = document.getElementById('payment-amount-summary');
                amountSummary?.classList.add('hidden');
                if (amountSummary) amountSummary.textContent = '';
                const creditSummary = document.getElementById('payment-credit-summary');
                creditSummary?.classList.add('hidden');
                if (creditSummary) creditSummary.textContent = '';
                const creditCheckbox = document.getElementById('payment-use-credit');
                if (creditCheckbox) creditCheckbox.checked = false;
                const bankCreditAmount = document.getElementById('payment-credit-amount');
                if (bankCreditAmount) bankCreditAmount.value = '';
                const resolvedCurrencySymbol = resolveCurrencySymbol(amountDisplay || '', monedaId || '');
                if (paymentClient) paymentClient.value = clientName;
                if (paymentOriginal) paymentOriginal.value = Number(amount || 0).toFixed(2);
                if (paymentOriginalCurrency) paymentOriginalCurrency.textContent = resolvedCurrencySymbol || 'S/';
                if (paymentAmountCurrency) paymentAmountCurrency.textContent = resolvedCurrencySymbol || 'S/';
                servicePaymentModal.dataset.paymentDisplayAmount = amountDisplay || formatMoney(amount, resolvedCurrencySymbol);
                servicePaymentModal.dataset.expectedCashDue = String(amount || 0);
                setPaymentType(tipoCobroId);
                if (paymentBalance) paymentBalance.textContent = formatMoney(amount, resolvedCurrencySymbol);
                const sameCurrencyMode = document.querySelector('#service-payment-modal input[name="payment_currency_mode"][value="same"]');
                if (sameCurrencyMode) sameCurrencyMode.checked = true;
                paymentCurrencyExtra?.classList.add('hidden');
                syncPaymentModeUI();

                const paymentCxcIdInput = document.getElementById('payment-cxc-id');
                if (paymentCxcIdInput) paymentCxcIdInput.value = cxcId || '';
                const paymentInvoiceTotal = document.getElementById('payment-invoice-total');
                if (paymentInvoiceTotal) paymentInvoiceTotal.value = Number(amount || 0).toFixed(2);
                const selectedCxcInputs = document.getElementById('payment-selected-cxc-inputs');
                if (selectedCxcInputs) {
                    selectedCxcInputs.replaceChildren(...cxcIds.map(id => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'cxc_ids[]';
                        input.value = id;
                        return input;
                    }));
                }

                const paymentMonedaIdInput = document.getElementById('payment-moneda-id');
                const resolvedMonedaId = monedaId || resolveSelectedCurrencyId() || '';
                if (paymentMonedaIdInput) paymentMonedaIdInput.value = resolvedMonedaId;
                if (paymentCurrencySelect) {
                    paymentCurrencySelect.value = '';
                    paymentCurrencySelect.dataset.defaultCurrency = resolvedMonedaId;
                    syncPaymentCurrencyHidden();
                }

                const paymentReference = document.getElementById('payment-reference');
                if (paymentReference) paymentReference.value = docRef || '-';
                if (paymentMethod) {
                    paymentMethod.value = Array.from(paymentMethod.options)
                        .find((option) => /contado/i.test(option.textContent || ''))?.value || '';
                }
                const paymentBankSelect = document.getElementById('payment-bank');
                const paymentDate = document.getElementById('payment-date');
                const paymentPeriodEnd = document.getElementById('payment-period-end');
                if (paymentBankSelect) paymentBankSelect.value = '1';
                if (paymentDate) paymentDate.value = '{{ now()->format('Y-m-d') }}';
                if (paymentPeriodEnd) {
                    paymentPeriodEnd.value = periodEnd || '';
                    paymentPeriodEnd.dataset.basePeriodEnd = periodEnd || '';
                    paymentPeriodEnd.readOnly = isViewOnly || isPendingWithholding;
                    paymentPeriodEnd.required = Boolean(periodEnd) && !isViewOnly && !isPendingWithholding;
                }
                if (paymentCanCuotas) paymentCanCuotas.value = '';
                paymentInstallments?.replaceChildren();
                paymentCreditFields?.classList.add('hidden');
                paymentCreditFields?.parentElement?.parentElement?.classList.remove('is-credit-payment');
                paymentCreditInstallments?.classList.add('hidden');

                const totalPaymentRadio = document.querySelector('#service-payment-modal input[name="modo_pago"][value="total"]');
                if (totalPaymentRadio) totalPaymentRadio.checked = true;
                partialPaymentField?.classList.add('hidden');

                const hiddenMonedaField = document.getElementById('payment-moneda-id');
                if (hiddenMonedaField && !hiddenMonedaField.value) {
                    hiddenMonedaField.value = monedaId || resolveSelectedCurrencyId() || '';
                }

                if (paymentAmount) {
                    const totalPaid = Number(amount || 0);
                    paymentAmount.value = totalPaid.toFixed(2);
                    paymentAmount.required = false;
                    paymentAmount.removeAttribute('max');
                }
                if (paymentDate) {
                    paymentDate.required = false;
                }

                if (paymentComprobanteInput) paymentComprobanteInput.value = '';
                if (paymentComprobanteName) paymentComprobanteName.textContent = '';
                paymentDropzone?.classList.remove('is-invalid');
                document.getElementById('payment-clear-comprobante')?.classList.add('hidden');
                document.getElementById('payment-existing-proof')?.classList.add('hidden');
                const removeProofInput = document.getElementById('payment-remove-comprobante');
                if (removeProofInput) removeProofInput.value = '0';

                if (selectedServiceInputs) {
                    selectedServiceInputs.replaceChildren(...serviceIds.map(id => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'servicio_ids[]';
                        input.value = id;
                        return input;
                    }));
                }

                const submitBtn = servicePaymentModal.querySelector('button[type="submit"]');
                const modalTitle = document.getElementById('service-payment-title');

                if (!isViewOnly && !isPendingWithholding && clientId) {
                    checkAndRenderVencidosButton(clientId, cxcId, amount, resolvedCurrencySymbol, 'pay', serviceIds, clientName, resolvedMonedaId);
                } else {
                    resetVencidosState();
                }

                if (isViewOnly) {
                    if (modalTitle) modalTitle.textContent = 'Detalle del Cobro Realizado';
                } else {
                    if (modalTitle) {
                        if (isPendingWithholding) {
                            const normalizedWithholdingType = String(withholdingType || 'detracción')
                                .normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
                            const withholdingName = normalizedWithholdingType.includes('retencion')
                                ? 'Retención'
                                : (normalizedWithholdingType.includes('autodetraccion') || normalizedWithholdingType.includes('autodetraer'))
                                    ? 'Autodetracción'
                                    : 'Detracción';
                            modalTitle.textContent = `Registrar comprobante de ${withholdingName} pendiente`;
                        } else {
                            modalTitle.textContent = 'Registrar cobro de servicios';
                        }
                    }
                    if (submitBtn) {
                        submitBtn.style.display = '';
                        submitBtn.textContent = isPendingWithholding
                            ? 'Registrar depósito'
                            : 'Registrar cobro';
                    }
                }

                if (isViewOnly) {
                    servicePaymentModal.classList.remove('visible');
                    servicePaymentModal.classList.add('hidden');
                    servicePaymentModal.dataset.paymentUpdateUrl = paymentDetailsUpdateUrlTemplate.replace(
                        '__id__',
                        encodeURIComponent(cxcId)
                    );
                    setPaymentViewMode(true, false);
                    try {
                        const loaded = await loadPaymentDetails(cxcId, requestToken, paymentDetailCxcIds);
                        if (!loaded || requestToken !== paymentDetailsRequestToken) return;
                    } catch (error) {
                        if (requestToken === paymentDetailsRequestToken) {
                            showServiceMessage(error.message || 'No se pudieron cargar los datos del pago.');
                        }
                        return;
                    }
                }
                if (requestToken !== paymentDetailsRequestToken) return;
                servicePaymentModal.classList.remove('hidden');
                servicePaymentModal.classList.add('visible');
                document.body.style.overflow = 'hidden';
            }

            document.getElementById('payment-view-edit-btn')?.addEventListener('click', function () {
                setPaymentViewMode(true, true);
            });

            document.getElementById('payment-view-cancel-edit-btn')?.addEventListener('click', function () {
                setPaymentViewMode(true, false);
                const groupedCxcIds = (servicePaymentModal?.dataset.paymentDetailCxcIds || '').split(',').filter(Boolean);
                loadPaymentDetails(servicePaymentModal?.dataset.paymentCxcId || '', undefined, groupedCxcIds).catch((error) => {
                    showServiceMessage(error.message || 'No se pudieron cargar los datos del pago.');
                });
            });

            const paymentComprobanteInput = document.getElementById('payment-comprobante');
            const paymentComprobanteName = document.getElementById('payment-comprobante-name');
            const paymentDropzone = document.getElementById('payment-dropzone');

            function handlePaymentComprobanteFile(file) {
                if (!file || !paymentComprobanteInput) return;
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
                const maxFileSize = 5 * 1024 * 1024;

                if (!allowedTypes.includes(file.type) || file.size > maxFileSize) {
                    paymentComprobanteInput.value = '';
                    if (paymentComprobanteName) {
                        paymentComprobanteName.textContent = 'Selecciona JPG, PNG o PDF de máximo 5 MB.';
                    }
                    document.getElementById('payment-clear-comprobante')?.classList.add('hidden');
                    paymentDropzone?.classList.add('is-invalid');
                    return;
                }

                const transfer = new DataTransfer();
                transfer.items.add(file);
                paymentComprobanteInput.files = transfer.files;
                const removeProofInput = document.getElementById('payment-remove-comprobante');
                if (removeProofInput) removeProofInput.value = '0';
                document.getElementById('payment-clear-comprobante')?.classList.remove('hidden');

                if (paymentComprobanteName) {
                    paymentComprobanteName.textContent = `${file.name} (${(file.size / 1024).toFixed(0)} KB)`;
                }
                paymentDropzone?.classList.remove('is-invalid');
            }

            document.querySelectorAll('[data-clear-upload]').forEach((button) => {
                button.addEventListener('click', function () {
                    const input = document.getElementById(this.dataset.clearUpload);
                    const fileName = document.getElementById(this.dataset.clearName);
                    if (input) input.value = '';
                    if (fileName) fileName.textContent = '';
                    this.classList.add('hidden');
                    input?.closest('.erp-upload-box')?.classList.remove('is-invalid', 'is-dragging');
                });
            });

            document.getElementById('payment-clear-comprobante')?.addEventListener('click', function () {
                if (paymentComprobanteInput) paymentComprobanteInput.value = '';
                if (paymentComprobanteName) paymentComprobanteName.textContent = '';
                const removeProofInput = document.getElementById('payment-remove-comprobante');
                if (removeProofInput) removeProofInput.value = '1';
                const existingProof = document.getElementById('payment-existing-proof');
                if (existingProof) {
                    existingProof.classList.remove('hidden');
                }
                this.classList.add('hidden');
                paymentDropzone?.classList.remove('is-invalid', 'is-dragging');
            });

            if (paymentComprobanteInput) {
                paymentComprobanteInput.addEventListener('change', function (e) {
                    handlePaymentComprobanteFile(e.target.files && e.target.files[0]);
                });
            }

            if (paymentDropzone) {
                paymentDropzone.addEventListener('drop', function (e) {
                    e.preventDefault();
                    handlePaymentComprobanteFile(e.dataTransfer.files && e.dataTransfer.files[0]);
                    paymentDropzone.classList.remove('is-dragging');
                });

                ['dragenter', 'dragover'].forEach(function (eventName) {
                    paymentDropzone.addEventListener(eventName, function (e) {
                        e.preventDefault();
                        paymentDropzone.classList.add('is-dragging');
                    });
                });

                paymentDropzone.addEventListener('dragleave', function (e) {
                    e.preventDefault();
                    paymentDropzone.classList.remove('is-dragging');
                });
            }

            function applyServicioSearch() {
                const clientFilter = (document.getElementById('service-filter-client')?.value || '').toLowerCase().trim();
                const selectedMonths = Array.from(serviceMonthInputs)
                    .filter((input) => input.checked)
                    .map((input) => input.value);
                const yearFilter = document.getElementById('service-filter-year')?.value || 'all';
                const documentFilter = (serviceDocumentInput?.value || '').toLowerCase().trim();
                const dateFilter = serviceDateInput?.value || '';
                const expirationDateFilter = serviceExpirationDateInput?.value || '';
                const statusFilter = (document.getElementById('service-filter-status')?.value || '').toLowerCase().trim();

                if (serviceSubtitle) {
                    serviceSubtitle.textContent = 'Servicios por vencer y vencidos';
                }

                syncServiceMonthSelection();

                const clientRows = document.querySelectorAll('.stair-client-row');
                let visibleCount = 0;

                clientRows.forEach(row => {
                    const clientId = (row.dataset.clientId || '').toLowerCase();
                    const clientName = (row.dataset.clientName || '').toLowerCase();
                    const clientStatus = (row.dataset.clientStatus || '').toLowerCase();
                    const clientMonth = (row.dataset.month || '').toLowerCase();
                    const clientYear = row.dataset.year || '';
                    const clientDocument = (row.dataset.document || '').toLowerCase();

                    const detailRow = document.getElementById(`client-detail-${row.dataset.groupId}`);

                    let matchesClient = clientFilter === '' || clientName.includes(clientFilter) || clientId.includes(clientFilter);
                    let matchesMonth = selectedMonths.length === 0 || selectedMonths.includes(clientMonth);
                    let matchesYear = yearFilter === 'all' || clientYear === yearFilter;
                    let matchesDocument = documentFilter === '' || clientDocument.includes(documentFilter);
                    let matchesDate = dateFilter === '' || row.dataset.registrationDate === dateFilter;
                    let matchesExpirationDate = expirationDateFilter === '' || row.dataset.endDate === expirationDateFilter;
                    let matchesStatus = statusFilter === '' || clientStatus === statusFilter;

                    const isVisible = matchesClient && matchesMonth && matchesYear && matchesDocument && matchesDate && matchesExpirationDate && matchesStatus;
                    row.style.display = isVisible ? '' : 'none';
                    if (detailRow) {
                        if (!isVisible) {
                            detailRow.style.display = 'none';
                        } else {
                            detailRow.style.display = '';
                        }
                    }

                    if (isVisible) visibleCount++;
                });

                const noResults = document.getElementById('service-no-results');
                if (noResults) {
                    noResults.style.display = visibleCount === 0 ? 'block' : 'none';
                }
            }

            function syncServiceMonthSelection() {
                const monthInputs = Array.from(serviceMonthInputs).filter((input) => input !== serviceMonthSelectAll);
                const selectedInputs = monthInputs.filter((input) => input.checked);
                const selectedCount = selectedInputs.length;
                if (serviceMonthSelectAll) {
                    serviceMonthSelectAll.checked = selectedCount === monthInputs.length;
                    serviceMonthSelectAll.indeterminate = selectedCount > 0 && selectedCount < monthInputs.length;
                }

                if (serviceMonthSummary) {
                    const selectedLabels = selectedInputs.map((input) => input.nextElementSibling?.textContent || '');
                    serviceMonthSummary.textContent = selectedLabels.length === 0
                        ? 'Todos los meses'
                        : selectedLabels.length === 1
                            ? selectedLabels[0]
                            : selectedLabels.length === monthInputs.length
                                ? 'Todos los meses'
                                : `${selectedLabels.length} meses seleccionados`;
                }
            }

            serviceMonthSelectAll?.addEventListener('change', function () {
                const monthInputs = Array.from(serviceMonthInputs).filter((input) => input !== serviceMonthSelectAll);
                monthInputs.forEach((input) => { input.checked = serviceMonthSelectAll.checked; });
                syncServiceMonthSelection();
            });
            serviceMonthInputs.forEach((input) => {
                if (input !== serviceMonthSelectAll) input.addEventListener('change', syncServiceMonthSelection);
            });

            document.addEventListener('click', function (event) {
                const monthPicker = document.getElementById('service-month-picker');
                if (monthPicker?.open && !monthPicker.contains(event.target)) {
                    monthPicker.open = false;
                }
            });

            function submitServiceFilters() {
                navigateToFilteredPage('servicios', 'services_page', [
                    { name: 'service_q', value: '' },
                    { name: 'service_client', value: document.getElementById('service-filter-client')?.value },
                    { name: 'service_month', value: Array.from(serviceMonthInputs).filter((input) => input.checked).map((input) => input.value).join(','), emptyValue: 'all' },
                    { name: 'service_year', value: document.getElementById('service-filter-year')?.value || 'all' },
                    { name: 'service_document', value: serviceDocumentInput?.value },
                    { name: 'service_date', value: serviceDateInput?.value },
                    { name: 'service_expiration_date', value: document.getElementById('service-filter-expiration-date')?.value },
                    { name: 'service_status', value: document.getElementById('service-filter-status')?.value },
                ]);
            }
            refreshServiceExportUrl();

            document.getElementById('service-filter-apply-btn')?.addEventListener('click', function () {
                submitServiceFilters();
            });

            document.getElementById('service-filter-clear-btn')?.addEventListener('click', function () {
                if (cxcListLoading) return;
                setCxcListControlsLoading(true);

                const currentMonth = new Intl.DateTimeFormat('es', { month: 'long' }).format(new Date()).toLowerCase();
                const currentYear = String(new Date().getFullYear());
                if (serviceSearchInput) serviceSearchInput.value = '';
                if (serviceDocumentInput) serviceDocumentInput.value = '';
                if (serviceDateInput) serviceDateInput.value = '';
                const expirationDateInput = document.getElementById('service-filter-expiration-date');
                if (expirationDateInput) expirationDateInput.value = '';
                serviceMonthInputs.forEach((input) => { input.checked = input.value === currentMonth; });
                const yearInput = document.getElementById('service-filter-year');
                const statusInput = document.getElementById('service-filter-status');
                if (yearInput) yearInput.value = currentYear;
                if (statusInput) statusInput.value = '';
                submitServiceFilters();
            });

            document.getElementById('service-filter-form')?.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    submitServiceFilters();
                }
            });

            applyServicioSearch();

            function applyCotizacionFilters() {
                refreshQuoteAnnulButton();
                const statusVal = document.getElementById('quote-filter-status')?.value || 'all';
                const subtitleStatus = document.getElementById('quote-subtitle-status');
                if (subtitleStatus) {
                    if (statusVal === 'approved') {
                        subtitleStatus.textContent = 'Aprobado';
                    } else if (statusVal === 'all') {
                        subtitleStatus.textContent = 'Todos los estados';
                    } else if (statusVal === 'pending_credit') {
                        subtitleStatus.textContent = 'Pendiente Credito';
                    } else if (statusVal === 'pending_autodetraccion') {
                        subtitleStatus.textContent = 'Pendiente Autodetracción';
                    } else if (statusVal === 'pending_detraccion') {
                        subtitleStatus.textContent = 'Pendiente Detracción';
                    } else if (statusVal === 'pending_retencion') {
                        subtitleStatus.textContent = 'Pendiente Retención';
                    } else {
                        subtitleStatus.textContent = 'Pendiente';
                    }
                }
            }

            const applyFilterBtn = document.getElementById('quote-filter-apply-btn');
            if (applyFilterBtn) {
                applyFilterBtn.addEventListener('click', function () {
                    applyCotizacionFilters();
                    submitQuoteFilters({ updateQuoteStats: true });
                });
            }

            document.getElementById('quote-filter-form')?.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    applyCotizacionFilters();
                    submitQuoteFilters({ updateQuoteStats: true });
                }
            });

            const clearFilterBtn = document.getElementById('quote-filter-clear-btn');
            if (clearFilterBtn) {
                clearFilterBtn.addEventListener('click', function () {
                    if (document.getElementById('quote-filter-client')) document.getElementById('quote-filter-client').value = '';
                    if (document.getElementById('quote-filter-number')) document.getElementById('quote-filter-number').value = '';
                    if (document.getElementById('quote-filter-payment-format')) document.getElementById('quote-filter-payment-format').value = '';
                    if (document.getElementById('quote-filter-validity')) document.getElementById('quote-filter-validity').value = '';
                    if (document.getElementById('quote-filter-service')) document.getElementById('quote-filter-service').value = '';
                    if (document.getElementById('quote-filter-status')) document.getElementById('quote-filter-status').value = 'all';
                    if (document.getElementById('quote-filter-date')) document.getElementById('quote-filter-date').value = '';
                    applyCotizacionFilters();
                    submitQuoteFilters({ updateQuoteStats: true });
                });
            }

            applyCotizacionFilters();

            // Manejador limpio y directo de los desplegables (Exportar y Filtro)
            document.addEventListener('click', function (e) {
                const btn = e.target.closest('.erp-dropdown-btn');
                const container = e.target.closest('.erp-dropdown-container');
                const allPanels = document.querySelectorAll('.erp-dropdown-panel');

                if (btn && container) {
                    e.preventDefault();
                    e.stopPropagation();
                    const panel = container.querySelector('.erp-dropdown-panel');

                    allPanels.forEach(p => {
                        if (p !== panel) p.classList.add('hidden');
                    });

                    if (panel) {
                        panel.classList.toggle('hidden');
                    }
                } else if (!e.target.closest('.erp-dropdown-panel')) {
                    allPanels.forEach(p => p.classList.add('hidden'));
                }
            });

            const buttons = document.querySelectorAll('.tab-button');
            const panels = document.querySelectorAll('.tab-panel');

            function getTabFromLocation() {
                return new URLSearchParams(window.location.search).get('tab') === 'servicios'
                    ? 'servicios'
                    : 'cotizaciones';
            }

            function activateTab(tab, updateUrl = false) {
                const activeTab = tab === 'servicios' ? 'servicios' : 'cotizaciones';
                const currentTab = document.querySelector('.tab-button.active')?.dataset.tab;
                if (currentTab && currentTab !== activeTab) {
                    document.querySelectorAll('[data-cxc-session-notice]').forEach((notice) => {
                        notice.classList.add('hidden');
                    });
                    sessionStorage.removeItem('cxc-pending-success-notice');
                }

                buttons.forEach((button) => {
                    button.classList.toggle('active', button.dataset.tab === activeTab);
                });
                panels.forEach((panel) => {
                    panel.classList.toggle('hidden', panel.id !== 'tab-' + activeTab);
                });

                if (updateUrl) {
                    const url = new URL(window.location.href);
                    url.searchParams.set('tab', activeTab);
                    url.hash = '';
                    window.history.replaceState(null, '', url);
                }
            }

            buttons.forEach((button) => {
                button.addEventListener('click', () => {
                    activateTab(button.dataset.tab, true);
                    if (!loadedCxcTabs.has(button.dataset.tab)) {
                        const params = new URLSearchParams(cxcQuery);
                        params.set('tab', button.dataset.tab);
                        loadCxcResults(`${window.location.pathname}?${params.toString()}`, button.dataset.tab);
                    }
                });
            });

            window.addEventListener('popstate', () => activateTab(getTabFromLocation()));
            activateTab(getTabFromLocation(), true);
            const initialTab = getTabFromLocation();
            if (!loadedCxcTabs.has(initialTab)) {
                const params = new URLSearchParams(cxcQuery);
                params.set('tab', initialTab);
                loadCxcResults(`${window.location.pathname}?${params.toString()}`, initialTab);
            }

            const quotePreviewModal = document.getElementById('erp-cotizacion-preview-modal');
            const quotePreviewFrame = document.getElementById('erp-cotizacion-preview-frame');
            const quotePreviewNumber = document.getElementById('erp-cotizacion-preview-number');
            const previewLoading = document.getElementById('preview-loading');
            const documentPreviewModal = document.getElementById('cxc-document-preview-modal');
            const documentPreviewFrame = document.getElementById('cxc-document-preview-frame');
            const documentPreviewImage = document.getElementById('cxc-document-preview-image');
            const documentPreviewTitle = document.getElementById('cxc-document-preview-title');
            let documentPreviewPreviousOverflow = '';
            const quotationReceiptsModal = document.getElementById('quotation-receipts-modal');
            const quotationReceiptsTitle = document.getElementById('quotation-receipts-title');
            const paymentReceiptLink = document.getElementById('quotation-payment-receipt-link');
            const paymentReceiptMissing = document.getElementById('quotation-payment-receipt-missing');
            const withholdingReceiptSection = document.getElementById('quotation-withholding-receipt-section');
            const withholdingReceiptLink = document.getElementById('quotation-withholding-receipt-link');
            const withholdingReceiptMissing = document.getElementById('quotation-withholding-receipt-missing');
            const receiptInvoiceNumber = document.getElementById('quotation-receipt-invoice-number');
            const receiptPaymentDate = document.getElementById('quotation-receipt-payment-date');
            const receiptPaymentAmount = document.getElementById('quotation-receipt-payment-amount');
            const receiptPaymentComment = document.getElementById('quotation-receipt-payment-comment');
            const receiptWithholdingLabel = document.getElementById('quotation-receipt-withholding-label');
            const receiptWithholdingDate = document.getElementById('quotation-receipt-withholding-date');
            const receiptWithholdingAmount = document.getElementById('quotation-receipt-withholding-amount');
            const receiptWithholdingAmountLabel = document.getElementById('quotation-receipt-withholding-amount-label');
            const receiptWithholdingTitle = document.getElementById('quotation-receipt-withholding-title');
            const quotationReceiptEditButton = document.getElementById('quotation-receipt-edit-payment');
            const quotationReceiptCancelButton = document.getElementById('quotation-receipt-cancel-edit');
            const quotationReceiptSaveButton = document.getElementById('quotation-receipt-save-edit');
            const quotationReceiptEditFields = document.getElementById('quotation-receipt-edit-fields');
            const quotationReceiptProofInput = document.getElementById('quotation-receipt-proof');
            const quotationReceiptProofBox = document.getElementById('quotation-receipt-proof-box');
            const quotationReceiptProofName = document.getElementById('quotation-receipt-proof-name');
            const quotationReceiptProofClear = document.getElementById('quotation-receipt-proof-clear');
            const quotationReceiptCommentError = document.getElementById('quotation-receipt-edit-error');

            function setQuotationReceiptEditMode(isEditing) {
                if (!quotationReceiptsModal) return;
                quotationReceiptsModal.dataset.editing = isEditing ? '1' : '0';
                if (receiptPaymentComment) {
                    receiptPaymentComment.readOnly = !isEditing;
                    receiptPaymentComment.classList.toggle('bg-slate-50', !isEditing);
                    receiptPaymentComment.classList.toggle('bg-white', isEditing);
                    if (isEditing && receiptPaymentComment.value === '—') {
                        receiptPaymentComment.value = '';
                    }
                }
                quotationReceiptEditFields?.classList.toggle('hidden', !isEditing);
                quotationReceiptEditButton?.classList.toggle('hidden', isEditing);
                quotationReceiptCancelButton?.classList.toggle('hidden', !isEditing);
                quotationReceiptSaveButton?.classList.toggle('hidden', !isEditing);
                if (!isEditing && quotationReceiptCommentError) {
                    quotationReceiptCommentError.textContent = '';
                    quotationReceiptCommentError.classList.add('hidden');
                }
            }

            quotationReceiptEditButton?.addEventListener('click', () => setQuotationReceiptEditMode(true));
            quotationReceiptCancelButton?.addEventListener('click', () => {
                if (receiptPaymentComment) {
                    receiptPaymentComment.value = quotationReceiptsModal?.dataset.paymentComment || '—';
                }
                if (quotationReceiptProofInput) quotationReceiptProofInput.value = '';
                if (quotationReceiptProofName) quotationReceiptProofName.textContent = '';
                quotationReceiptProofClear?.classList.add('hidden');
                quotationReceiptProofBox?.classList.remove('is-invalid', 'is-dragging');
                setQuotationReceiptEditMode(false);
            });
            quotationReceiptProofClear?.addEventListener('click', () => {
                if (quotationReceiptProofInput) quotationReceiptProofInput.value = '';
                if (quotationReceiptProofName) quotationReceiptProofName.textContent = '';
                quotationReceiptProofClear.classList.add('hidden');
                quotationReceiptProofBox?.classList.remove('is-invalid', 'is-dragging');
            });
            quotationReceiptProofInput?.addEventListener('change', () => {
                setQuotationReceiptProof(quotationReceiptProofInput.files?.[0]);
            });
            function setQuotationReceiptProof(file) {
                if (!file || !quotationReceiptProofInput) return;
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
                if (!allowedTypes.includes(file.type) || file.size > 5 * 1024 * 1024) {
                    quotationReceiptProofInput.value = '';
                    if (quotationReceiptProofName) {
                        quotationReceiptProofName.textContent = 'Selecciona JPG, PNG o PDF de máximo 5 MB.';
                    }
                    quotationReceiptProofClear?.classList.add('hidden');
                    quotationReceiptProofBox?.classList.add('is-invalid');
                    return;
                }
                const transfer = new DataTransfer();
                transfer.items.add(file);
                quotationReceiptProofInput.files = transfer.files;
                if (quotationReceiptProofName) {
                    quotationReceiptProofName.textContent = `${file.name} (${(file.size / 1024).toFixed(0)} KB)`;
                }
                quotationReceiptProofClear?.classList.remove('hidden');
                quotationReceiptProofBox?.classList.remove('is-invalid');
            }
            if (quotationReceiptProofBox) {
                ['dragenter', 'dragover'].forEach((eventName) => {
                    quotationReceiptProofBox.addEventListener(eventName, (event) => {
                        event.preventDefault();
                        quotationReceiptProofBox.classList.add('is-dragging');
                    });
                });
                ['dragleave', 'drop'].forEach((eventName) => {
                    quotationReceiptProofBox.addEventListener(eventName, (event) => {
                        event.preventDefault();
                        quotationReceiptProofBox.classList.remove('is-dragging');
                    });
                });
                quotationReceiptProofBox.addEventListener('drop', (event) => {
                    setQuotationReceiptProof(event.dataTransfer?.files?.[0]);
                });
            }
            document.addEventListener('paste', (event) => {
                if (!quotationReceiptsModal?.classList.contains('visible')
                    || quotationReceiptsModal.dataset.editing !== '1') return;
                const pastedItem = Array.from(event.clipboardData?.items || [])
                    .find((item) => item.kind === 'file');
                if (pastedItem) {
                    event.preventDefault();
                    setQuotationReceiptProof(pastedItem.getAsFile());
                }
            });
            quotationReceiptSaveButton?.addEventListener('click', async () => {
                const cxcId = quotationReceiptsModal?.dataset.cxcId || '';
                if (!cxcId) {
                    if (quotationReceiptCommentError) {
                        quotationReceiptCommentError.textContent = 'No se encontró la cuenta por cobrar de esta cotización.';
                        quotationReceiptCommentError.classList.remove('hidden');
                    }
                    return;
                }

                const formData = new FormData();
                formData.append('descripcion', receiptPaymentComment?.value || '');
                formData.append('eliminar_comprobante', '0');
                if (quotationReceiptProofInput?.files?.[0]) {
                    formData.append('comprobante', quotationReceiptProofInput.files[0]);
                }
                formData.append('_method', 'PATCH');
                const csrfToken = servicePaymentForm?.querySelector('input[name="_token"]')?.value
                    || document.querySelector('meta[name="csrf-token"]')?.content
                    || '';
                if (csrfToken) formData.append('_token', csrfToken);

                quotationReceiptSaveButton.disabled = true;
                quotationReceiptSaveButton.textContent = 'Guardando...';
                if (quotationReceiptCommentError) quotationReceiptCommentError.classList.add('hidden');
                try {
                    const updateUrl = paymentDetailsUpdateUrlTemplate.replace('__id__', encodeURIComponent(cxcId));
                    const response = await fetch(updateUrl, {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: formData
                    });
                    const result = await response.json();
                    if (!response.ok || !result.success) {
                        throw new Error(result.message || 'No se pudieron actualizar los datos del recibo.');
                    }

                    const detailsUrl = paymentDetailsUrlTemplate.replace('__id__', encodeURIComponent(cxcId));
                    const detailsResponse = await fetch(detailsUrl, { headers: { Accept: 'application/json' } });
                    const detailsResult = await detailsResponse.json();
                    if (!detailsResponse.ok || !detailsResult.success || !detailsResult.data) {
                        throw new Error(detailsResult.message || 'Se guardó el recibo, pero no se pudo actualizar su vista.');
                    }
                    const details = detailsResult.data;
                    const updatedComment = details.detalle_descripcion || '';
                    quotationReceiptsModal.dataset.paymentComment = updatedComment;
                    if (receiptPaymentComment) receiptPaymentComment.value = updatedComment || '—';
                    if (paymentReceiptLink) paymentReceiptLink.dataset.receiptUrl = details.comprobante_url || '';
                    if (paymentReceiptMissing) paymentReceiptMissing.classList.add('hidden');
                    const quoteReceiptButton = Array.from(document.querySelectorAll('[data-open-quotation-receipts]'))
                        .find((button) => button.dataset.cxcId === cxcId);
                    if (quoteReceiptButton) {
                        quoteReceiptButton.dataset.paymentComment = updatedComment;
                        quoteReceiptButton.dataset.paymentReceiptUrl = details.comprobante_url || '';
                    }
                    if (quotationReceiptProofInput) quotationReceiptProofInput.value = '';
                    if (quotationReceiptProofName) quotationReceiptProofName.textContent = '';
                    quotationReceiptProofClear?.classList.add('hidden');
                    setQuotationReceiptEditMode(false);
                } catch (error) {
                    if (quotationReceiptCommentError) {
                        quotationReceiptCommentError.textContent = error.message || 'No se pudieron actualizar los datos del recibo.';
                        quotationReceiptCommentError.classList.remove('hidden');
                    }
                } finally {
                    quotationReceiptSaveButton.disabled = false;
                    quotationReceiptSaveButton.textContent = 'Guardar cambios';
                }
            });

            const openDocumentPreview = function (url, title = 'Vista previa del comprobante') {
                if (!documentPreviewModal || !documentPreviewFrame || !url) {
                    return false;
                }
                documentPreviewPreviousOverflow = document.body.style.overflow;
                if (documentPreviewTitle) {
                    documentPreviewTitle.textContent = title;
                }
                const imageUrl = String(url).split(/[?#]/, 1)[0];
                const isImage = /\.(?:avif|bmp|gif|jpe?g|png|webp)$/i.test(imageUrl);
                if (isImage && documentPreviewImage) {
                    documentPreviewImage.src = url;
                    documentPreviewImage.classList.remove('hidden');
                    documentPreviewFrame.classList.add('hidden');
                    documentPreviewFrame.src = 'about:blank';
                } else {
                    if (documentPreviewImage) {
                        documentPreviewImage.src = '';
                        documentPreviewImage.classList.add('hidden');
                    }
                    documentPreviewFrame.classList.remove('hidden');
                    documentPreviewFrame.src = url;
                }
                documentPreviewModal.classList.remove('hidden');
                documentPreviewModal.classList.add('visible');
                document.body.style.overflow = 'hidden';
                return true;
            };

            const closeDocumentPreview = function () {
                if (!documentPreviewModal) {
                    return;
                }
                documentPreviewModal.classList.add('hidden');
                documentPreviewModal.classList.remove('visible');
                if (documentPreviewFrame) {
                    documentPreviewFrame.src = 'about:blank';
                }
                if (documentPreviewImage) {
                    documentPreviewImage.src = '';
                    documentPreviewImage.classList.add('hidden');
                }
                documentPreviewFrame?.classList.remove('hidden');
                document.body.style.overflow = documentPreviewPreviousOverflow;
            };

            const closeQuotationReceipts = function () {
                setQuotationReceiptEditMode(false);
                quotationReceiptsModal?.classList.add('hidden');
                quotationReceiptsModal?.classList.remove('visible');
                document.body.style.overflow = '';
            };

            const closeQuotePreview = function () {
                if (!quotePreviewModal) {
                    return;
                }
                quotePreviewModal.classList.remove('visible');
                if (quotePreviewFrame) {
                    quotePreviewFrame.src = 'about:blank';
                }
                if (previewLoading) {
                    previewLoading.classList.add('hidden');
                    previewLoading.classList.remove('flex');
                }
                if (quotePreviewNumber) {
                    quotePreviewNumber.textContent = '';
                }
                document.body.style.overflow = '';
            };

            document.addEventListener('click', function (event) {
                const documentPreviewAction = event.target.closest('[data-open-document-preview]');
                if (documentPreviewAction) {
                    const receiptUrl = documentPreviewAction.dataset.receiptUrl || '';
                    const missingMessage = documentPreviewAction.dataset.missingMessage || 'No hay un archivo adjunto.';
                    const missingElement = document.getElementById(documentPreviewAction.dataset.missingElement || '');
                    if (!openDocumentPreview(receiptUrl, documentPreviewAction.dataset.previewTitle || 'Vista previa del comprobante')
                        && missingElement) {
                        missingElement.textContent = missingMessage;
                        missingElement.classList.remove('hidden');
                    }
                    return;
                }
                if (event.target.closest('[data-close-document-preview]') || event.target === documentPreviewModal) {
                    closeDocumentPreview();
                    return;
                }

                const receiptsButton = event.target.closest('[data-open-quotation-receipts]');
                if (receiptsButton && quotationReceiptsModal) {
                    const paymentReceiptUrl = receiptsButton.dataset.paymentReceiptUrl || '';
                    const withholdingReceiptUrl = receiptsButton.dataset.withholdingReceiptUrl || '';
                    const withholdingApplies = receiptsButton.dataset.withholdingApplies === '1';
                    const withholdingType = receiptsButton.dataset.withholdingType || 'detracción';
                    const emptyValue = '—';

                    if (quotationReceiptsTitle) {
                        quotationReceiptsTitle.textContent = `Recibos de la cotización ${receiptsButton.dataset.quotationNumber || ''}`;
                    }
                    quotationReceiptsModal.dataset.cxcId = receiptsButton.dataset.cxcId || '';
                    quotationReceiptsModal.dataset.paymentComment = receiptsButton.dataset.paymentComment || '';
                    setQuotationReceiptEditMode(false);
                    if (quotationReceiptProofInput) quotationReceiptProofInput.value = '';
                    if (quotationReceiptProofName) quotationReceiptProofName.textContent = '';
                    quotationReceiptProofClear?.classList.add('hidden');
                    quotationReceiptProofBox?.classList.remove('is-invalid', 'is-dragging');
                    document.getElementById('quotation-receipt-edit-payment')?.classList.toggle(
                        'hidden',
                        !quotationReceiptsModal.dataset.cxcId
                    );
                    if (receiptInvoiceNumber) {
                        receiptInvoiceNumber.value = receiptsButton.dataset.invoiceNumber || emptyValue;
                    }
                    if (receiptPaymentDate) {
                        receiptPaymentDate.value = receiptsButton.dataset.paymentDate || emptyValue;
                    }
                    if (receiptPaymentAmount) {
                        receiptPaymentAmount.value = receiptsButton.dataset.paymentAmount || emptyValue;
                    }
                    if (receiptPaymentComment) {
                        receiptPaymentComment.value = receiptsButton.dataset.paymentComment || emptyValue;
                    }
                    if (receiptWithholdingLabel) {
                        const withholdingName = withholdingType.charAt(0).toUpperCase() + withholdingType.slice(1);
                        receiptWithholdingLabel.textContent = `Fecha de pago de ${withholdingName}`;
                    }
                    if (receiptWithholdingDate) {
                        receiptWithholdingDate.value = receiptsButton.dataset.withholdingDate || emptyValue;
                    }
                    if (receiptWithholdingAmount) {
                        receiptWithholdingAmount.value = receiptsButton.dataset.withholdingAmount || emptyValue;
                    }
                    if (receiptWithholdingAmountLabel) {
                        const withholdingName = withholdingType.charAt(0).toUpperCase() + withholdingType.slice(1);
                        receiptWithholdingAmountLabel.textContent = `Monto de ${withholdingName}`;
                    }
                    if (receiptWithholdingTitle) {
                        const withholdingName = withholdingType.charAt(0).toUpperCase() + withholdingType.slice(1);
                        receiptWithholdingTitle.textContent = `Detalle de ${withholdingName}:`;
                    }
                    if (paymentReceiptLink) {
                        paymentReceiptLink.dataset.receiptUrl = paymentReceiptUrl;
                        paymentReceiptLink.dataset.missingMessage = 'Sin recibo de pago';
                        paymentReceiptLink.dataset.previewTitle = 'Recibo de pago';
                    }
                    if (paymentReceiptMissing) {
                        paymentReceiptMissing.textContent = 'Sin recibo de pago';
                        paymentReceiptMissing.classList.add('hidden');
                    }
                    if (withholdingReceiptSection) {
                        withholdingReceiptSection.classList.toggle('hidden', !withholdingApplies);
                    }
                    if (withholdingReceiptLink) {
                        withholdingReceiptLink.dataset.receiptUrl = withholdingReceiptUrl;
                        withholdingReceiptLink.dataset.missingMessage = `Sin recibo de ${withholdingType}`;
                        withholdingReceiptLink.dataset.previewTitle = `Recibo de ${withholdingType}`;
                        withholdingReceiptLink.textContent = `Ver recibo de ${withholdingType}`;
                    }
                    if (withholdingReceiptMissing) {
                        withholdingReceiptMissing.textContent = `Sin recibo de ${withholdingType}`;
                        withholdingReceiptMissing.classList.add('hidden');
                    }
                    quotationReceiptsModal.classList.remove('hidden');
                    quotationReceiptsModal.classList.add('visible');
                    document.body.style.overflow = 'hidden';
                    return;
                }
                if (event.target.closest('[data-close-quotation-receipts]')) {
                    closeQuotationReceipts();
                    return;
                }
                const previewButton = event.target.closest('[data-cotizacion-pdf-preview]');
                if (previewButton && quotePreviewModal && quotePreviewFrame) {
                    event.preventDefault();
                    if (previewLoading) {
                        previewLoading.classList.remove('hidden');
                        previewLoading.classList.add('flex');
                    }
                    quotePreviewFrame.src = previewButton.dataset.cotizacionPdfPreview || 'about:blank';
                    if (quotePreviewNumber) {
                        quotePreviewNumber.textContent = previewButton.dataset.cotizacionNumber
                            ? 'Cotización ' + previewButton.dataset.cotizacionNumber
                            : '';
                    }
                    quotePreviewModal.classList.add('visible');
                    document.body.style.overflow = 'hidden';
                    return;
                }

                if (event.target.closest('[data-erp-close-preview]')) {
                    closeQuotePreview();
                }
            });

            if (quotePreviewFrame) {
                quotePreviewFrame.addEventListener('load', function () {
                    if (previewLoading) {
                        previewLoading.classList.add('hidden');
                        previewLoading.classList.remove('flex');
                    }
                });
            }

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && documentPreviewModal && documentPreviewModal.classList.contains('visible')) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    closeDocumentPreview();
                    return;
                }
                if (event.key === 'Escape' && quotePreviewModal && quotePreviewModal.classList.contains('visible')) {
                    closeQuotePreview();
                }
            });

            const gestionModal = document.getElementById('erp-gestion-modal');
            const gestionFileInput = document.getElementById('erp-gestion-file');
            const gestionPreview = document.getElementById('erp-gestion-preview');
            const gestionFileClear = document.getElementById('erp-gestion-file-clear');
            const gestionTitle = document.getElementById('erp-gestion-title');
            const gestionUploadBox = document.getElementById('erp-gestion-upload-box');
            const gestionFileName = document.getElementById('erp-gestion-file-name');
            const gestionSaveButton = document.getElementById('erp-gestion-save');
            const gestionSavingStatus = document.getElementById('erp-gestion-saving-status');

            const gestionForm = document.getElementById('erp-gestion-form');
            const gestionPaymentFormat = document.getElementById('erp-gestion-payment-format');
            const gestionCreditFields = document.getElementById('erp-gestion-credit-fields');
            const gestionInstallmentsPanel = document.getElementById('erp-gestion-credit-installments');
            const gestionInstallments = document.getElementById('erp-gestion-installments');
            const gestionCurrencyMode = document.getElementById('erp-gestion-currency-mode');
            const gestionCurrencyExtra = document.getElementById('erp-gestion-currency-extra');
            const gestionCurrencyButton = document.getElementById('erp-gestion-currency-toggle');
            const gestionOtherCurrency = document.getElementById('erp-gestion-currency');
            const gestionExchangeRate = document.getElementById('erp-gestion-exchange-rate');
            const gestionForeignAmount = document.getElementById('erp-gestion-foreign-amount');
            const gestionConvertedAmount = document.getElementById('erp-gestion-converted-amount');
            const gestionPaymentAmount = document.getElementById('erp-gestion-payment-amount');
            const gestionPaymentAmountField = document.getElementById('erp-gestion-payment-amount-field');
            const gestionPaymentAmountCurrency = document.getElementById('erp-gestion-payment-amount-currency');
            const gestionCancelAmountField = document.getElementById('erp-gestion-cancel-amount-field');
            const gestionAmountInput = document.getElementById('erp-gestion-amount');
            const gestionCreditNoteButton = document.getElementById('gestion-select-credit-note');
            const gestionCreditNoteInput = document.getElementById('gestion-credit-note-id');
            const gestionCreditNoteSummary = document.getElementById('erp-gestion-credit-note-summary');
            const gestionCreditAmountButton = document.getElementById('gestion-select-credit-amount');
            const gestionUseCredit = document.getElementById('gestion-use-credit');
            const gestionCreditAmount = document.getElementById('gestion-credit-amount');
            const gestionApplyRetention = document.getElementById('erp-gestion-apply-retention');
            const gestionRetentionField = document.getElementById('erp-gestion-retention-field');
            const gestionBalanceSummary = document.getElementById('erp-gestion-balance-summary');
            const gestionAmountCurrency = document.getElementById('erp-gestion-amount-currency');
            const gestionForeignAmountCurrency = document.getElementById('erp-gestion-foreign-amount-currency');
            const gestionConvertedAmountCurrency = document.getElementById('erp-gestion-converted-amount-currency');
            const gestionPayMode = document.getElementById('erp-gestion-pay-mode');
            const gestionWithholdingSummary = document.getElementById('erp-gestion-withholding-summary');
            let gestionBalance = 0;
            let gestionOriginalBalance = 0;
            let gestionGrossBalance = 0;
            let gestionDeductionAmount = 0;
            let gestionOriginalCurrencyId = '';
            let gestionIsCreditContinuation = false;
            let gestionWithholdingEligible = false;
            let gestionWithholdingRetention = false;
            let gestionWithholdingType = 'detracción';
            let gestionAmountManuallyEdited = false;
            let gestionCompleteWithholding = false;
            let gestionIsEquipmentQuotation = false;
            let gestionCreditTotal = null;
            let gestionPaidInstallments = [];
            let gestionCreditCurrencySymbol = '';
            let gestionNextInstallmentNumber = null;
            let gestionNextInstallmentAmount = null;
            let gestionNextInstallmentDate = '';

            const gestionIsCredit = function () {
                return /credito|crédito/i.test(gestionPaymentFormat?.selectedOptions?.[0]?.textContent || '');
            };

            const gestionIsDollarCurrency = function (option) {
                return Boolean(option) && (
                    option.dataset.symbol?.trim() === '$'
                    || /dolar|dollar|usd/i.test(option.textContent || '')
                );
            };

            const normalizeGestionCurrencySymbol = function (symbol) {
                const normalizedSymbol = String(symbol || '').trim();
                return ['s', 's/', 's/.', 'sol', 'soles', 'pen'].includes(normalizedSymbol.toLowerCase())
                    ? 'S/'
                    : normalizedSymbol;
            };

            const gestionCurrencyOption = function (currencyId) {
                return Array.from(gestionOtherCurrency?.options || [])
                    .find((option) => option.value === String(currencyId || ''));
            };

            const syncGestionPaymentAmountCurrency = function () {
                const originalCurrencySymbol = normalizeGestionCurrencySymbol(
                    gestionCurrencyOption(gestionOriginalCurrencyId)?.dataset.symbol
                );
                const selectedCurrencySymbol = normalizeGestionCurrencySymbol(
                    gestionOtherCurrency?.selectedOptions?.[0]?.dataset.symbol
                );
                if (gestionPaymentAmountCurrency) gestionPaymentAmountCurrency.textContent = originalCurrencySymbol;
                if (gestionAmountCurrency) gestionAmountCurrency.textContent = originalCurrencySymbol;
                if (gestionForeignAmountCurrency) gestionForeignAmountCurrency.textContent = selectedCurrencySymbol;
                if (gestionConvertedAmountCurrency) {
                    gestionConvertedAmountCurrency.textContent = gestionCurrencyMode?.value === 'other'
                        ? selectedCurrencySymbol
                        : '';
                }
            };

            const gestionConvertAmount = function (amount, destinationCurrency, rate) {
                if (!destinationCurrency || rate <= 0) return null;
                const converted = gestionIsDollarCurrency(destinationCurrency)
                    ? amount / rate
                    : amount * rate;
                return Number(converted.toFixed(2));
            };

            const updateGestionWithholdingSummary = function () {
                if (!gestionWithholdingSummary) return;
                const noteAmount = Math.min(
                    selectedCreditNotesAmount('gestion'),
                    Math.max(0, gestionOriginalBalance - gestionDeductionAmount)
                );
                const bankCredit = Math.min(
                    selectedBankCreditAmount('gestion', currentVencidosState.saldoFavorDisponible),
                    Math.max(0, gestionOriginalBalance - gestionDeductionAmount - noteAmount)
                );
                const isOtherCurrency = gestionCurrencyMode?.value === 'other';
                const paymentCurrency = gestionOtherCurrency?.selectedOptions?.[0];
                const exchangeRate = Number(gestionExchangeRate?.value || 0);
                const deductionForPayment = isOtherCurrency
                    ? (gestionConvertAmount(gestionDeductionAmount, paymentCurrency, exchangeRate) ?? gestionDeductionAmount)
                    : gestionDeductionAmount;
                const noteCreditForPayment = isOtherCurrency
                    ? (gestionConvertAmount(noteAmount, paymentCurrency, exchangeRate) ?? noteAmount)
                    : noteAmount;
                const bankCreditForPayment = isOtherCurrency
                    ? (gestionConvertAmount(bankCredit, paymentCurrency, exchangeRate) ?? bankCredit)
                    : bankCredit;
                const symbol = normalizeGestionCurrencySymbol(
                    isOtherCurrency
                        ? paymentCurrency?.dataset.symbol
                        : gestionCurrencyOption(gestionOriginalCurrencyId)?.dataset.symbol
                ) || 'S/';
                const parts = [`Total: ${formatMoney(gestionGrossBalance, symbol)}`];
                if (gestionDeductionAmount > 0) {
                    const deductionLabel = gestionWithholdingRetention ? 'Retención (3%)' : 'Detracción (12%)';
                    parts.push(`${deductionLabel}: ${formatMoney(deductionForPayment, symbol)}`);
                }
                if (bankCredit > 0) parts.push(`Saldo a favor aplicado: ${formatMoney(bankCreditForPayment, symbol)}`);
                if (noteAmount > 0) parts.push(`Nota de crédito aplicada: ${formatMoney(noteCreditForPayment, symbol)}`);
                const shouldShow = !gestionCompleteWithholding
                    && (gestionDeductionAmount > 0 || noteAmount + bankCredit > 0);
                gestionWithholdingSummary.textContent = shouldShow ? parts.join(' | ') : '';
                gestionWithholdingSummary.classList.toggle('hidden', !shouldShow);
            };

            const updateGestionWithholding = function (grossAmount, currencySymbol = '') {
                const usesOtherCurrency = gestionCurrencyMode?.value === 'other';
                const originalGross = Number(gestionOriginalBalance || grossAmount || 0);
                const paymentCurrency = gestionOtherCurrency?.selectedOptions?.[0];
                const exchangeRate = Number(gestionExchangeRate?.value || 0);
                const convertedGross = usesOtherCurrency
                    ? gestionConvertAmount(originalGross, paymentCurrency, exchangeRate)
                    : originalGross;
                const grossForPayment = convertedGross ?? Number(grossAmount || originalGross);
                gestionGrossBalance = grossForPayment;
                gestionWithholdingRetention = gestionIsEquipmentQuotation && !!gestionApplyRetention?.checked;
                gestionDeductionAmount = gestionWithholdingEligible
                    && !gestionIsCreditContinuation
                    && (gestionIsEquipmentQuotation || !gestionIsCredit())
                    && (gestionWithholdingRetention || !gestionIsEquipmentQuotation)
                    ? Math.round(
                        originalGross * (gestionWithholdingRetention ? 0.03 : 0.12)
                        * (gestionWithholdingRetention ? 100 : 1)
                    ) / (gestionWithholdingRetention ? 100 : 1)
                    : 0;
                const deductionForPayment = usesOtherCurrency
                    ? (gestionConvertAmount(gestionDeductionAmount, paymentCurrency, exchangeRate) ?? gestionDeductionAmount)
                    : gestionDeductionAmount;
                gestionBalance = Number(Math.max(0, grossForPayment - deductionForPayment).toFixed(2));

                updateGestionWithholdingSummary();
                updateGestionBalanceSummary();
            };

            const updateGestionCreditNoteApplication = function (syncPaymentAmount = true) {
                if (syncPaymentAmount) gestionAmountManuallyEdited = false;
                const isOtherCurrency = gestionCurrencyMode?.value === 'other';
                const selectedNote = currentVencidosState.notasCredito.find(
                    (note) => String(note.idnotacredito) === String(gestionCreditNoteInput?.value || '')
                );
                const sourceDeduction = gestionDeductionAmount;
                const sourceBalanceAfterDeduction = Math.max(0, gestionOriginalBalance - sourceDeduction);
                const noteAmount = Math.min(
                    Number(selectedNote?.monto_disponible || 0),
                    sourceBalanceAfterDeduction
                );
                const sourceCashDue = Math.max(0, sourceBalanceAfterDeduction - noteAmount);
                const paymentCurrency = gestionOtherCurrency?.selectedOptions?.[0];
                const exchangeRate = Number(gestionExchangeRate?.value || 0);
                const cashDue = isOtherCurrency
                    ? gestionConvertAmount(sourceCashDue, paymentCurrency, exchangeRate)
                    : sourceCashDue;
                gestionBalance = Number((cashDue ?? sourceCashDue).toFixed(2));

                if (gestionCreditNoteSummary) {
                    const symbol = normalizeGestionCurrencySymbol(
                        gestionCurrencyOption(gestionOriginalCurrencyId)?.dataset.symbol
                    ) || 'S/';
                    gestionCreditNoteSummary.textContent = noteAmount > 0
                        ? `Nota aplicada: ${formatMoney(noteAmount, symbol)}`
                        : '';
                    gestionCreditNoteSummary.classList.toggle('hidden', noteAmount <= 0);
                }
                if (!gestionCompleteWithholding) {
                    const selectedBankCredit = selectedBankCreditAmount(
                        'gestion',
                        currentVencidosState.saldoFavorDisponible
                    );
                    const bankCreditForPayment = isOtherCurrency
                        ? (gestionConvertAmount(selectedBankCredit, paymentCurrency, exchangeRate) ?? selectedBankCredit)
                        : selectedBankCredit;
                    const amountDue = Math.max(0, gestionBalance - bankCreditForPayment);
                    if (syncPaymentAmount && isOtherCurrency && gestionForeignAmount && !gestionIsCreditContinuation) {
                        gestionForeignAmount.value = amountDue.toFixed(2);
                    } else if (syncPaymentAmount && gestionAmountInput && !gestionIsCreditContinuation) {
                        gestionAmountInput.value = amountDue.toFixed(2);
                    }
                    if (syncPaymentAmount && gestionIsCreditContinuation && gestionPaymentAmount) {
                        const installmentCashDue = Math.max(
                            0,
                            (gestionNextInstallmentAmount || gestionOriginalBalance) - noteAmount
                        );
                        gestionPaymentAmount.value = isOtherCurrency
                            ? (gestionConvertAmount(installmentCashDue, paymentCurrency, exchangeRate) ?? installmentCashDue).toFixed(2)
                            : installmentCashDue.toFixed(2);
                    }
                    renderGestionInstallments();
                }
                updateGestionWithholdingSummary();
                updateGestionBalanceSummary();
            };

            const updateGestionBalanceSummary = function () {
                if (!gestionBalanceSummary) return;
                if (!gestionAmountManuallyEdited) {
                    gestionBalanceSummary.replaceChildren();
                    gestionBalanceSummary.classList.add('hidden');
                    return;
                }
                const isOtherCurrency = gestionCurrencyMode?.value === 'other';
                const currencyOption = gestionCurrencyOption(gestionOriginalCurrencyId);
                const paymentCurrencyOption = isOtherCurrency
                    ? gestionOtherCurrency?.selectedOptions?.[0]
                    : currencyOption;
                const currencySymbol = normalizeGestionCurrencySymbol(paymentCurrencyOption?.dataset.symbol) || 'S/';
                const noteAmount = Math.min(
                    selectedCreditNotesAmount('gestion'),
                    Math.max(0, gestionOriginalBalance - gestionDeductionAmount)
                );
                const bankCredit = selectedBankCreditAmount(
                    'gestion',
                    currentVencidosState.saldoFavorDisponible
                );
                const netDueAfterCredits = Math.max(
                    0,
                    gestionOriginalBalance - gestionDeductionAmount - noteAmount - bankCredit
                );
                const grossDueAfterCredits = Math.max(
                    0,
                    gestionOriginalBalance - noteAmount - bankCredit
                );
                const exchangeRate = Number(gestionExchangeRate?.value || 0);
                const netDueInPaymentCurrency = isOtherCurrency
                    ? gestionConvertAmount(netDueAfterCredits, paymentCurrencyOption, exchangeRate)
                    : netDueAfterCredits;
                const grossDueInPaymentCurrency = isOtherCurrency
                    ? gestionConvertAmount(grossDueAfterCredits, paymentCurrencyOption, exchangeRate)
                    : grossDueAfterCredits;
                if (netDueInPaymentCurrency === null || grossDueInPaymentCurrency === null) {
                    gestionBalanceSummary.replaceChildren();
                    gestionBalanceSummary.classList.add('hidden');
                    return;
                }
                const enteredAmount = Number(
                    (isOtherCurrency ? gestionForeignAmount : gestionAmountInput)?.value || 0
                );
                const remaining = Math.max(0, netDueInPaymentCurrency - enteredAmount);
                const newCredit = Math.max(0, enteredAmount - grossDueInPaymentCurrency);
                if (remaining <= 0.005 && newCredit <= 0.005) {
                    gestionBalanceSummary.replaceChildren();
                    gestionBalanceSummary.classList.add('hidden');
                    if (
                        gestionPayMode
                        && !gestionCompleteWithholding
                        && !gestionIsCreditContinuation
                        && !gestionIsCredit()
                    ) {
                        gestionPayMode.value = 'total';
                    }
                    return;
                }
                gestionBalanceSummary.replaceChildren();
                const remainingLabel = document.createElement('span');
                remainingLabel.textContent = `Saldo restante: ${formatMoney(remaining, currencySymbol)}`;
                gestionBalanceSummary.append(remainingLabel);
                if (remaining <= 0.005 && newCredit > 0.005) {
                    const separator = document.createElement('span');
                    separator.textContent = ' | ';
                    const creditLabel = document.createElement('span');
                    creditLabel.className = 'text-danger';
                    creditLabel.textContent = `Saldo a favor: ${formatMoney(newCredit, currencySymbol)}`;
                    gestionBalanceSummary.append(separator, creditLabel);
                }
                gestionBalanceSummary.classList.remove('hidden');
                if (
                    gestionPayMode
                    && !gestionCompleteWithholding
                    && !gestionIsCreditContinuation
                    && !gestionIsCredit()
                ) {
                    gestionPayMode.value = enteredAmount + 0.01 < netDueInPaymentCurrency ? 'parcial' : 'total';
                }
            };

            const setGestionAmountToBalance = function () {
                gestionAmountManuallyEdited = false;
                const isOtherCurrency = gestionCurrencyMode?.value === 'other';
                const noteAmount = Math.min(
                    selectedCreditNotesAmount('gestion'),
                    Math.max(0, gestionOriginalBalance - gestionDeductionAmount)
                );
                const bankCredit = selectedBankCreditAmount(
                    'gestion',
                    currentVencidosState.saldoFavorDisponible
                );
                const debtCurrencyAmount = Math.max(
                    0,
                    gestionOriginalBalance - gestionDeductionAmount - noteAmount - bankCredit
                );
                if (isOtherCurrency && gestionForeignAmount) {
                    const paymentCurrency = gestionOtherCurrency?.selectedOptions?.[0];
                    const converted = gestionConvertAmount(
                        debtCurrencyAmount,
                        paymentCurrency,
                        Number(gestionExchangeRate?.value || 0)
                    );
                    if (converted !== null) gestionForeignAmount.value = converted.toFixed(2);
                } else if (gestionAmountInput && !gestionIsCreditContinuation) {
                    gestionAmountInput.value = debtCurrencyAmount.toFixed(2);
                }
                updateGestionWithholdingSummary();
                updateGestionBalanceSummary();
            };

            const syncGestionCurrencyOptions = function () {
                const debtCurrencyId = gestionOriginalCurrencyId;
                let selectedCurrencyIsDebtCurrency = false;
                Array.from(gestionOtherCurrency?.options || []).forEach((option) => {
                    const isDebtCurrency = Boolean(option.value) && option.value === debtCurrencyId;
                    option.disabled = isDebtCurrency;
                    option.hidden = isDebtCurrency;
                    selectedCurrencyIsDebtCurrency = selectedCurrencyIsDebtCurrency
                        || (isDebtCurrency && option.selected);
                });
                if (selectedCurrencyIsDebtCurrency && gestionOtherCurrency) {
                    gestionOtherCurrency.value = '';
                }
            };

            const syncGestionConvertedAmount = function () {
                const rate = Number(gestionExchangeRate?.value || 0);
                const paymentCurrencyId = String(gestionOtherCurrency?.value || '');
                const sourceCurrencyOption = Array.from(gestionOtherCurrency?.options || [])
                    .find((option) => option.value === gestionOriginalCurrencyId);
                const paymentCurrencyOption = gestionOtherCurrency?.selectedOptions?.[0];
                const changesCurrency = Boolean(paymentCurrencyId) && paymentCurrencyId !== gestionOriginalCurrencyId;
                const convertedBalance = changesCurrency
                    ? gestionConvertAmount(gestionOriginalBalance, paymentCurrencyOption, rate)
                    : null;
                const grossBalance = convertedBalance ?? gestionOriginalBalance;
                const currencySymbol = normalizeGestionCurrencySymbol(
                    (convertedBalance === null ? sourceCurrencyOption : paymentCurrencyOption)?.dataset.symbol
                );
                updateGestionWithholding(grossBalance, currencySymbol);
                updateGestionCreditNoteApplication(false);
                const foreignAmount = Number(gestionForeignAmount?.value || 0);
                if (gestionAmountInput) {
                    const selectedBankCredit = selectedBankCreditAmount(
                        'gestion',
                        currentVencidosState.saldoFavorDisponible
                    );
                    const bankCreditForPayment = gestionCurrencyMode?.value === 'other'
                        ? (gestionConvertAmount(selectedBankCredit, paymentCurrencyOption, rate) ?? selectedBankCredit)
                        : selectedBankCredit;
                    gestionAmountInput.value = gestionCurrencyMode?.value === 'other'
                        ? gestionOriginalBalance.toFixed(2)
                        : Math.max(0, gestionBalance - bankCreditForPayment).toFixed(2);
                    gestionAmountInput.removeAttribute('max');
                    gestionAmountInput.readOnly = gestionIsCreditContinuation
                        || gestionCurrencyMode?.value === 'other';
                    gestionAmountInput.name = gestionIsCreditContinuation || gestionCurrencyMode?.value === 'other'
                        ? ''
                        : 'montoPago';
                }
                if (gestionConvertedAmount) {
                    gestionConvertedAmount.value = convertedBalance === null
                        ? ''
                        : convertedBalance.toFixed(2);
                }
                if (gestionForeignAmount) {
                    gestionForeignAmount.removeAttribute('max');
                }
                syncGestionPaymentAmountCurrency();
                if (gestionPaymentAmount && gestionCurrencyMode?.value === 'other') {
                    gestionPaymentAmount.value = foreignAmount > 0 ? foreignAmount.toFixed(2) : '';
                }
                const exchangeRateLabel = document.querySelector('label[for="erp-gestion-exchange-rate"]');
                if (exchangeRateLabel) {
                    const isDollarSolPair = (
                        gestionIsDollarCurrency(sourceCurrencyOption)
                        && /sol|pen/i.test(paymentCurrencyOption?.textContent || '')
                    ) || (
                            /sol|pen/i.test(sourceCurrencyOption?.textContent || '')
                            && gestionIsDollarCurrency(paymentCurrencyOption)
                        );
                    exchangeRateLabel.firstChild.textContent = isDollarSolPair
                        ? 'Tipo de cambio (S/ por $)'
                        : 'Tipo de cambio';
                }
                if (gestionPaymentAmount && gestionCurrencyMode?.value !== 'other') {
                    gestionPaymentAmount.value = gestionIsCreditContinuation
                        ? gestionPaymentAmount.value
                        : gestionIsCredit()
                            ? (gestionInstallments?.querySelector('input[name="cuotas_montos[]"]')?.value || gestionBalance.toFixed(2))
                            : gestionBalance.toFixed(2);
                }
                renderGestionInstallments();
            };

            const renderGestionInstallments = function () {
                if (!gestionInstallments || !gestionCreditFields || !gestionInstallmentsPanel) return;
                if (gestionCompleteWithholding) return;
                const isCredit = gestionIsCredit();
                const isContinuation = gestionIsCreditContinuation;
                const hasInstallmentBalance = gestionBalance > 0.009;
                gestionCreditFields.classList.toggle('hidden', !isCredit || isContinuation || !hasInstallmentBalance);
                gestionCancelAmountField?.classList.toggle('hidden', isContinuation);
                const isOtherCurrency = gestionCurrencyMode?.value === 'other';
                gestionPaymentAmountField?.classList.toggle(
                    'hidden',
                    isOtherCurrency || !isContinuation
                );
                if (gestionAmountInput) {
                    gestionAmountInput.readOnly = isContinuation || isOtherCurrency;
                    gestionAmountInput.name = isContinuation || isOtherCurrency ? '' : 'montoPago';
                }
                if (gestionPaymentAmount) {
                    gestionPaymentAmount.name = isContinuation || (isOtherCurrency && isCredit)
                        ? 'montoPago'
                        : '';
                }
                syncGestionPaymentAmountCurrency();
                const paidInstallmentSummary = document.getElementById('erp-gestion-paid-installment-summary');
                if (paidInstallmentSummary) {
                    const hasPaidInstallments = isContinuation
                        && gestionPaidInstallments.length > 0
                        && gestionCreditTotal !== null;
                    paidInstallmentSummary.classList.toggle('hidden', !hasPaidInstallments);
                    if (hasPaidInstallments) {
                        const currencyPrefix = gestionCreditCurrencySymbol
                            ? `${gestionCreditCurrencySymbol} `
                            : '';
                        const paidInstallmentLabels = gestionPaidInstallments
                            .map((installment) => `Cuota ${installment.numero} pagada: ${currencyPrefix}${installment.monto.toFixed(2)}`);
                        paidInstallmentLabels.push(`Total de la cotización: ${currencyPrefix}${gestionCreditTotal.toFixed(2)}`);
                        paidInstallmentSummary.textContent = paidInstallmentLabels.join(' · ');
                    }
                }
                const nextInstallmentSummary = document.getElementById('erp-gestion-next-installment-summary');
                if (nextInstallmentSummary) {
                    const hasNextInstallment = isContinuation
                        && gestionNextInstallmentNumber
                        && gestionNextInstallmentAmount !== null;
                    nextInstallmentSummary.classList.toggle('hidden', !hasNextInstallment);
                    if (hasNextInstallment) {
                        const dueDate = gestionNextInstallmentDate.split(/[ T]/)[0] || 'sin fecha';
                        const currencyPrefix = gestionCreditCurrencySymbol
                            ? `${gestionCreditCurrencySymbol} `
                            : '';
                        nextInstallmentSummary.textContent = `Cuota ${gestionNextInstallmentNumber} pendiente · Monto: ${currencyPrefix}${gestionNextInstallmentAmount.toFixed(2)} · Fecha: ${dueDate}`;
                    }
                }
                document.getElementById('erp-gestion-converted-total-field')?.classList.toggle(
                    'hidden',
                    !isOtherCurrency || gestionConvertedAmount?.value === ''
                );
                if (gestionPaymentAmount) {
                    gestionPaymentAmount.required = isContinuation && !isOtherCurrency;
                }
                const paymentAmountLabel = document.getElementById('erp-gestion-payment-amount-label');
                if (paymentAmountLabel) {
                    paymentAmountLabel.textContent = isCredit ? 'Monto de cuota a pagar' : 'Monto a cancelar';
                }
                const countInput = document.getElementById('erp-gestion-installment-count');
                const intervalInput = document.getElementById('erp-gestion-installment-interval');
                if (countInput) countInput.required = isCredit && !isContinuation && hasInstallmentBalance;
                if (intervalInput) intervalInput.required = isCredit && !isContinuation && hasInstallmentBalance;
                if (!isCredit) {
                    gestionInstallmentsPanel.classList.add('hidden');
                    gestionInstallments.replaceChildren();
                    if (gestionPayMode) gestionPayMode.value = 'total';
                    if (gestionPaymentAmount) {
                        gestionPaymentAmount.readOnly = !gestionWithholdingEligible;
                        if (!isOtherCurrency) {
                            gestionPaymentAmount.value = gestionPaymentAmount.value || gestionBalance.toFixed(2);
                        }
                    }
                    return;
                }
                if (isContinuation) {
                    gestionInstallmentsPanel.classList.add('hidden');
                    gestionInstallments.replaceChildren();
                    if (gestionPayMode) gestionPayMode.value = 'parcial';
                    if (gestionPaymentAmount) {
                        gestionPaymentAmount.readOnly = true;
                        if (!isOtherCurrency) {
                            const noteAmount = Math.min(
                                selectedCreditNotesAmount('gestion'),
                                gestionNextInstallmentAmount ?? gestionBalance
                            );
                            gestionPaymentAmount.value = Math.max(
                                0,
                                (gestionNextInstallmentAmount ?? gestionBalance) - noteAmount
                            ).toFixed(2);
                        }
                    }
                    return;
                }

                if (!hasInstallmentBalance) {
                    gestionInstallmentsPanel.classList.add('hidden');
                    gestionInstallments.replaceChildren();
                    if (gestionPayMode) gestionPayMode.value = 'total';
                    if (gestionPaymentAmount) gestionPaymentAmount.value = '0.00';
                    return;
                }

                const count = Math.max(0, Math.min(Number(countInput?.value || 0), 60));
                const intervalDays = Number(intervalInput?.value || 0);
                const existingAmounts = Array.from(gestionInstallments.querySelectorAll('input[name="cuotas_montos[]"]'))
                    .map((input) => Math.round(Number(input.value || 0) * 100));
                const totalCents = Math.max(0, Math.round(gestionBalance * 100));
                const preserveAmounts = existingAmounts.length === count
                    && existingAmounts.reduce((sum, amount) => sum + amount, 0) === totalCents;
                gestionInstallments.replaceChildren();
                if (!count || !intervalDays) {
                    gestionInstallmentsPanel.classList.add('hidden');
                    if (gestionPaymentAmount && !isOtherCurrency) {
                        gestionPaymentAmount.readOnly = true;
                        gestionPaymentAmount.value = gestionBalance.toFixed(2);
                    }
                    return;
                }
                gestionInstallmentsPanel.classList.remove('hidden');
                if (gestionPayMode) gestionPayMode.value = count > 1 ? 'parcial' : 'total';
                const dateParts = (document.getElementById('erp-gestion-date')?.value || '').split('-').map(Number);
                if (dateParts.length !== 3 || dateParts.some((part) => !part)) return;
                const firstDate = new Date(dateParts[0], dateParts[1] - 1, dateParts[2]);
                const defaultAmountCents = Math.floor(totalCents / count);
                const remainingDefaultCents = totalCents % count;
                for (let index = 1; index <= count; index++) {
                    const dueDate = new Date(firstDate.getFullYear(), firstDate.getMonth(), firstDate.getDate() + intervalDays * (index - 1));
                    const dateValue = `${dueDate.getFullYear()}-${String(dueDate.getMonth() + 1).padStart(2, '0')}-${String(dueDate.getDate()).padStart(2, '0')}`;
                    const amountCents = preserveAmounts
                        ? existingAmounts[index - 1]
                        : defaultAmountCents + (index <= remainingDefaultCents ? 1 : 0);
                    const row = document.createElement('div');
                    row.className = 'grid grid-cols-2 gap-2 mb-1.5';
                    row.innerHTML = `<label class="text-xs font-semibold text-slate-600">Cuota ${index} · Monto
                                <input name="cuotas_montos[]" type="number" min="0.01" step="0.01" value="${(amountCents / 100).toFixed(2)}" required class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800"></label>
                                <label class="text-xs font-semibold text-slate-600">Fecha a pagar
                                <input name="cuotas_fechas[]" type="date" value="${dateValue}" required class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800"></label>`;
                    gestionInstallments.appendChild(row);
                }
                const firstInstallment = gestionInstallments.querySelector('input[name="cuotas_montos[]"]');
                if (firstInstallment && gestionPaymentAmount && !isOtherCurrency) {
                    if (gestionAmountInput) {
                        gestionAmountInput.value = count === 1 ? gestionBalance.toFixed(2) : firstInstallment.value;
                        gestionAmountInput.readOnly = isContinuation || isOtherCurrency;
                    }
                }
                if (firstInstallment && isOtherCurrency && Number(gestionForeignAmount?.value || 0) > 0) {
                    firstInstallment.value = Number(gestionForeignAmount.value).toFixed(2);
                    rebalanceGestionInstallments(firstInstallment, false);
                }
            };

            const rebalanceGestionInstallments = function (changedInput, syncPaymentAmount = true) {
                if (!gestionInstallments || !changedInput) return;
                const amountInputs = Array.from(gestionInstallments.querySelectorAll('input[name="cuotas_montos[]"]'));
                const totalCents = Math.max(0, Math.round(gestionBalance * 100));
                if (amountInputs.length <= 1) {
                    if (amountInputs[0]) amountInputs[0].value = (totalCents / 100).toFixed(2);
                    return;
                }

                const minimumPerInstallment = 1;
                const maximumEditedCents = Math.max(minimumPerInstallment, totalCents - (amountInputs.length - 1) * minimumPerInstallment);
                const enteredAmount = Number(changedInput.value);
                const editedCents = Number.isFinite(enteredAmount)
                    ? Math.min(maximumEditedCents, Math.max(minimumPerInstallment, Math.round(enteredAmount * 100)))
                    : minimumPerInstallment;
                changedInput.value = (editedCents / 100).toFixed(2);

                const remainingCents = totalCents - editedCents;
                const otherInputs = amountInputs.filter((input) => input !== changedInput);
                const baseCents = Math.floor(remainingCents / otherInputs.length);
                const extraCents = remainingCents % otherInputs.length;
                otherInputs.forEach((input, index) => {
                    input.value = ((baseCents + (index < extraCents ? 1 : 0)) / 100).toFixed(2);
                });

                const firstInstallment = amountInputs[0];
                if (firstInstallment && gestionAmountInput && syncPaymentAmount) {
                    if (gestionCurrencyMode?.value === 'other') {
                        gestionForeignAmount.value = firstInstallment.value;
                    } else {
                        gestionAmountInput.value = firstInstallment.value;
                    }
                }
            };

            gestionAmountInput?.addEventListener('input', function () {
                if (gestionIsCredit() && !gestionIsCreditContinuation) {
                    const firstInstallment = gestionInstallments?.querySelector('input[name="cuotas_montos[]"]');
                    if (firstInstallment) {
                        firstInstallment.value = gestionAmountInput.value;
                        rebalanceGestionInstallments(firstInstallment, false);
                        gestionAmountInput.value = firstInstallment.value;
                    }
                }
            });

            gestionPaymentFormat?.addEventListener('change', renderGestionInstallments);
            document.getElementById('erp-gestion-installment-count')?.addEventListener('input', renderGestionInstallments);
            document.getElementById('erp-gestion-installment-interval')?.addEventListener('change', renderGestionInstallments);
            gestionInstallments?.addEventListener('change', function (event) {
                if (event.target.matches('input[name="cuotas_montos[]"]')) {
                    rebalanceGestionInstallments(event.target);
                }
            });
            gestionCurrencyButton?.addEventListener('click', function () {
                if (gestionIsCreditContinuation) return;
                gestionAmountManuallyEdited = false;
                const useOtherCurrency = gestionCurrencyMode?.value !== 'other';
                gestionCurrencyMode.value = useOtherCurrency ? 'other' : 'same';
                gestionCurrencyExtra?.classList.toggle('hidden', !useOtherCurrency);
                if (gestionCurrencyExtra) {
                    gestionCurrencyExtra.style.display = useOtherCurrency ? 'block' : 'none';
                }
                gestionPaymentAmountField?.classList.add('hidden');
                document.getElementById('erp-gestion-converted-total-field')?.classList.toggle('hidden', !useOtherCurrency);
                gestionCurrencyButton.textContent = useOtherCurrency ? 'Misma moneda' : 'Otra moneda';
                if (gestionOtherCurrency) gestionOtherCurrency.required = useOtherCurrency;
                if (gestionExchangeRate) gestionExchangeRate.required = useOtherCurrency;
                if (gestionForeignAmount) gestionForeignAmount.required = useOtherCurrency;
                if (gestionPaymentAmount) {
                    gestionPaymentAmount.required = false;
                    gestionPaymentAmount.readOnly = true;
                }
                if (useOtherCurrency) {
                    syncGestionCurrencyOptions();
                    if (gestionOtherCurrency) gestionOtherCurrency.value = '';
                    if (gestionExchangeRate) gestionExchangeRate.value = '';
                    if (gestionForeignAmount) gestionForeignAmount.value = '';
                    if (gestionPaymentAmount) gestionPaymentAmount.value = '';
                } else if (gestionPaymentAmount) {
                    if (gestionOtherCurrency) gestionOtherCurrency.value = '';
                    if (gestionExchangeRate) gestionExchangeRate.value = '';
                    if (gestionForeignAmount) gestionForeignAmount.value = '';
                    gestionPaymentAmount.value = gestionIsCredit()
                        ? (gestionInstallments?.querySelector('input[name="cuotas_montos[]"]')?.value || gestionBalance.toFixed(2))
                        : gestionBalance.toFixed(2);
                }
                syncGestionConvertedAmount();
            });
            gestionExchangeRate?.addEventListener('input', function () {
                syncGestionConvertedAmount();
            });
            gestionForeignAmount?.addEventListener('input', function () {
                gestionAmountManuallyEdited = true;
                syncGestionConvertedAmount();
                updateGestionBalanceSummary();
            });
            gestionOtherCurrency?.addEventListener('change', function () {
                syncGestionCurrencyOptions();
                syncGestionConvertedAmount();
            });
            document.getElementById('erp-gestion-date')?.addEventListener('change', renderGestionInstallments);

            const setGestionWithholdingCompletionMode = function (enabled) {
                gestionCompleteWithholding = enabled;
                const hiddenField = document.getElementById('erp-gestion-complete-withholding');
                if (hiddenField) hiddenField.value = enabled ? '1' : '0';
                const hiddenIds = [
                    'erp-gestion-format-field',
                    'erp-gestion-bank-field',
                    'erp-gestion-cancel-amount-field',
                    'erp-gestion-payment-amount-field',
                    'erp-gestion-currency-extra',
                    'erp-gestion-converted-total-field',
                    'erp-gestion-credit-fields',
                    'erp-gestion-credit-installments',
                    'erp-gestion-paid-installment-summary',
                    'erp-gestion-next-installment-summary',
                    'gestion-select-credit-note',
                    'erp-gestion-credit-note-summary',
                ];
                hiddenIds.forEach((fieldId) => {
                    document.getElementById(fieldId)?.classList.toggle('hidden', enabled);
                });
                renderCreditNoteChoice('gestion');
                renderCreditChoice('gestion', currentVencidosState.saldoFavorDisponible, currentVencidosState.currencySymbol);
                document.getElementById('erp-gestion-withholding-summary')
                    ?.classList.toggle('hidden', enabled || gestionDeductionAmount <= 0);
                document.getElementById('erp-gestion-completion-amount-field')
                    ?.classList.toggle('hidden', !enabled);
                const referenceInput = document.getElementById('erp-gestion-reference');
                const referenceLabel = document.getElementById('erp-gestion-reference-label');
                if (referenceInput) {
                    referenceInput.readOnly = enabled;
                    referenceInput.style.backgroundColor = enabled ? '#f8fafc' : '#fff';
                }
                if (referenceLabel) {
                    referenceLabel.textContent = enabled ? 'Factura' : 'Factura *';
                }
                gestionForm?.querySelectorAll('input,select,textarea').forEach((field) => {
                    if (field === gestionFileInput
                        || field.name === '_token'
                        || field.id === 'erp-gestion-complete-withholding'
                        || field.id === 'erp-gestion-withholding-decision'
                        || field.id === 'erp-gestion-date'
                        || field.id === 'erp-gestion-description') {
                        field.disabled = false;
                        return;
                    }
                    if (field === gestionCreditNoteInput) {
                        field.disabled = enabled || !field.value;
                        return;
                    }
                    field.disabled = enabled;
                });
                if (gestionFileInput) gestionFileInput.required = false;
                const paymentDateLabel = document.querySelector('label[for="erp-gestion-date"]');
                if (paymentDateLabel) {
                    paymentDateLabel.textContent = enabled
                        ? `Fecha de pago de ${gestionWithholdingType}`
                        : 'Fecha de pago';
                }
                const fileLabel = document.getElementById('erp-gestion-file-label');
                if (fileLabel) {
                    fileLabel.textContent = enabled
                        ? 'Comprobante del depósito (opcional)'
                        : 'Archivo de Pago (opcional)';
                }
                if (gestionFileClear) {
                    gestionFileClear.textContent = enabled ? 'Quitar recibo' : 'Quitar pago';
                }
                const descriptionInput = document.getElementById('erp-gestion-description');
                if (descriptionInput) {
                    descriptionInput.placeholder = enabled
                        ? `Describe sobre el depósito de ${gestionWithholdingType}...`
                        : 'Describe sobre el pago...';
                }
                if (gestionSaveButton) {
                    gestionSaveButton.textContent = enabled ? 'Registrar depósito' : 'Guardar Pago';
                }
                if (gestionTitle) {
                    gestionTitle.textContent = enabled
                        ? `Completar ${gestionWithholdingType} de cotización`
                        : 'Registrar pago de cotización';
                }
            };

            const openGestionModal = function (number, approveUrl, clientName, clientId, amountDisplay, paymentFormat, paymentId, currencyId, balance, reference, cxcState, creditTotal, paidInstallments, currencySymbol, nextInstallmentNumber, nextInstallmentPaymentId, nextInstallmentAmount, nextInstallmentDate, serviceType, completeWithholding, cxcId) {
                if (!gestionModal) {
                    return;
                }
                if (gestionSaveButton) {
                    gestionSaveButton.disabled = false;
                }
                if (gestionSavingStatus) gestionSavingStatus.classList.remove('is-visible');
                resetVencidosState();
                if (gestionCreditNoteButton) {
                    gestionCreditNoteButton.disabled = false;
                    gestionCreditNoteButton.textContent = 'N. crédito';
                }
                if (gestionCreditNoteInput) {
                    gestionCreditNoteInput.value = '';
                    gestionCreditNoteInput.disabled = true;
                }
                if (gestionCreditNoteSummary) {
                    gestionCreditNoteSummary.textContent = '';
                    gestionCreditNoteSummary.classList.add('hidden');
                }
                if (gestionForm) {
                    gestionForm.action = approveUrl || '';
                }
                const withholdingDecisionInput = document.getElementById('erp-gestion-withholding-decision');
                if (withholdingDecisionInput) withholdingDecisionInput.value = '';
                gestionDeductionAmount = 0;
                if (gestionApplyRetention) {
                    gestionApplyRetention.checked = false;
                    gestionApplyRetention.disabled = false;
                }
                const withholdingSummary = document.getElementById('erp-gestion-withholding-summary');
                if (withholdingSummary) {
                    withholdingSummary.textContent = '';
                    withholdingSummary.classList.add('hidden');
                }
                setGestionWithholdingCompletionMode(false);

                const sectionTitle = document.getElementById('erp-gestion-section-title');
                if (sectionTitle) {
                    sectionTitle.textContent = 'DATOS DE COTIZACIÓN' + (number ? ': ' + number : '');
                }

                const clientInput = document.getElementById('erp-gestion-client');
                const amountInput = document.getElementById('erp-gestion-amount');
                const dateInput = document.getElementById('erp-gestion-date');
                const descInput = document.getElementById('erp-gestion-description');

                if (clientInput) clientInput.value = clientName || '-';
                currentVencidosState = {
                    ...currentVencidosState,
                    baseCxcId: cxcId || '',
                    baseAmount: Number(balance) || 0,
                    clientId: clientId || '',
                    saldoFavorMonedaId: '',
                    monedaId: currencyId || '',
                    currencySymbol: normalizeGestionCurrencySymbol(currencySymbol) || 'S/',
                    targetModal: 'gestion',
                    serviceIds: [],
                    saldoFavorDisponible: 0,
                    saldoFavorCargado: false,
                    errorSaldoFavor: false,
                    notasCredito: [],
                    notaCreditoSeleccionadaId: '',
                    notasCreditoCargadas: false,
                    notasCreditoMonedaId: '',
                    errorNotasCredito: false
                };
                gestionBalance = Number(balance || 0);
                gestionOriginalBalance = gestionBalance;
                gestionGrossBalance = gestionBalance;
                gestionAmountManuallyEdited = false;
                gestionBalanceSummary?.replaceChildren();
                gestionBalanceSummary?.classList.add('hidden');
                if (gestionModal) gestionModal.dataset.grossAmount = String(gestionBalance);
                if (amountInput) amountInput.value = gestionBalance.toFixed(2);
                amountInput?.removeAttribute('max');
                const cxcIdInput = document.getElementById('gestion-cxc-id');
                if (cxcIdInput) cxcIdInput.value = cxcId || '';
                gestionOriginalCurrencyId = String(currencyId || '');
                if (gestionConvertedAmount) gestionConvertedAmount.value = '';
                gestionIsCreditContinuation = Number(nextInstallmentNumber) > 0
                    || /pagocredito|pendiente cuota crédito|pendiente a credito/i.test(cxcState);
                const isPartialPaymentContinuation = /pendiente pago parcial/i.test(cxcState);
                const normalizedServiceType = String(serviceType || '').trim().toUpperCase();
                gestionIsEquipmentQuotation = normalizedServiceType === 'EQUIPAMIENTO';
                const normalizedCxcState = String(cxcState || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
                const existingRetentionPending = /retención/i.test(String(cxcState || ''));
                gestionWithholdingType = /autodetraccion|autodetraer/.test(normalizedCxcState)
                    ? 'autodetracción'
                    : existingRetentionPending
                        ? 'retención'
                        : (/detracción/i.test(String(cxcState || ''))
                            ? 'detracción'
                            : (gestionIsEquipmentQuotation ? 'retención' : 'detracción'));
                gestionWithholdingRetention = completeWithholding
                    ? existingRetentionPending
                    : false;
                gestionWithholdingEligible = !gestionIsCreditContinuation
                    && !isPartialPaymentContinuation
                    && !completeWithholding
                    && Number(balance) >= 700;
                const canOfferRetention = gestionWithholdingEligible && gestionIsEquipmentQuotation;
                gestionRetentionField?.classList.toggle('hidden', !canOfferRetention);
                if (gestionApplyRetention) {
                    gestionApplyRetention.checked = false;
                    gestionApplyRetention.disabled = !canOfferRetention;
                }
                const parsedCreditTotal = Number(creditTotal);
                gestionCreditTotal = creditTotal !== '' && Number.isFinite(parsedCreditTotal) ? parsedCreditTotal : null;
                gestionPaidInstallments = JSON.parse(paidInstallments || '[]')
                    .filter((installment) => Number(installment.numero) > 0 && Number.isFinite(Number(installment.monto)))
                    .map((installment) => ({
                        numero: Number(installment.numero),
                        monto: Number(installment.monto),
                    }));
                gestionCreditCurrencySymbol = normalizeGestionCurrencySymbol(currencySymbol);
                gestionNextInstallmentNumber = Number(nextInstallmentNumber) > 0 ? Number(nextInstallmentNumber) : null;
                gestionNextInstallmentAmount = Number(nextInstallmentAmount) > 0 ? Number(nextInstallmentAmount) : null;
                gestionNextInstallmentDate = nextInstallmentDate || '';
                if (gestionPaymentFormat) gestionPaymentFormat.value = nextInstallmentPaymentId || paymentId || '';
                if (!gestionPaymentFormat?.value) {
                    const normalizedPayment = String(paymentFormat || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
                    const match = Array.from(gestionPaymentFormat?.options || []).find((option) => normalizedPayment.includes('credito')
                        ? /credito/i.test(option.textContent)
                        : /contado/i.test(option.textContent));
                    if (match) gestionPaymentFormat.value = match.value;
                }
                document.getElementById('erp-gestion-currency-id').value = currencyId || '';
                syncGestionCurrencyOptions();
                gestionCurrencyButton?.classList.toggle('hidden', gestionIsCreditContinuation);
                document.getElementById('erp-gestion-reference').value = reference || '';
                document.getElementById('erp-gestion-file').value = '';
                document.getElementById('erp-gestion-file-name').textContent = '';
                gestionFileClear?.classList.add('hidden');
                const defaultBankOption = document.querySelector('#erp-gestion-bank option[data-default="true"]');
                document.getElementById('erp-gestion-bank').value = defaultBankOption?.value || '';
                document.getElementById('erp-gestion-installment-count').value = '';
                gestionCurrencyMode.value = 'same';
                gestionCurrencyExtra?.classList.add('hidden');
                if (gestionCurrencyExtra) gestionCurrencyExtra.style.display = 'none';
                gestionPaymentAmountField?.classList.add('hidden');
                if (gestionOtherCurrency) gestionOtherCurrency.required = false;
                if (gestionExchangeRate) gestionExchangeRate.required = false;
                if (gestionForeignAmount) gestionForeignAmount.required = false;
                if (gestionPaymentAmount) {
                    gestionPaymentAmount.required = false;
                    gestionPaymentAmount.readOnly = true;
                    gestionPaymentAmount.name = '';
                }
                if (gestionCurrencyButton) gestionCurrencyButton.textContent = 'Otra moneda';
                if (gestionOtherCurrency) gestionOtherCurrency.value = '';
                if (gestionExchangeRate) gestionExchangeRate.value = '';
                if (gestionForeignAmount) gestionForeignAmount.value = '';
                const intervalInput = document.getElementById('erp-gestion-installment-interval');
                if (intervalInput) intervalInput.value = '';
                if (gestionPaymentAmount) {
                    gestionPaymentAmount.value = gestionIsCreditContinuation
                        ? (gestionNextInstallmentAmount ?? gestionBalance).toFixed(2)
                        : gestionOriginalBalance.toFixed(2);
                }
                syncGestionPaymentAmountCurrency();
                updateGestionWithholding(
                    gestionOriginalBalance,
                    gestionCurrencyOption(gestionOriginalCurrencyId)?.dataset.symbol || currencySymbol
                );
                if (gestionAmountInput) {
                    gestionAmountInput.value = gestionWithholdingEligible
                        ? gestionBalance.toFixed(2)
                        : gestionOriginalBalance.toFixed(2);
                }
                if (gestionPaymentAmount && !gestionIsCreditContinuation) {
                    gestionPaymentAmount.value = gestionBalance.toFixed(2);
                }

                const now = new Date();
                if (dateInput) {
                    const yyyy = now.getFullYear();
                    const mm = String(now.getMonth() + 1).padStart(2, '0');
                    const dd = String(now.getDate()).padStart(2, '0');
                    const scheduledPaymentDate = gestionIsCreditContinuation
                        ? gestionNextInstallmentDate.split(/[ T]/)[0]
                        : '';
                    dateInput.value = /^\d{4}-\d{2}-\d{2}$/.test(scheduledPaymentDate)
                        ? scheduledPaymentDate
                        : `${yyyy}-${mm}-${dd}`;
                }
                if (descInput) descInput.value = '';
                const completionAmount = document.getElementById('erp-gestion-completion-amount');
                if (completionAmount) completionAmount.value = Number(balance || 0).toFixed(2);
                const completionAmountLabel = document.getElementById('erp-gestion-completion-amount-label');
                if (completionAmountLabel) {
                    completionAmountLabel.textContent = `Monto ${gestionWithholdingType}`;
                }
                const completionAmountCurrency = document.getElementById('erp-gestion-completion-amount-currency');
                if (completionAmountCurrency) {
                    completionAmountCurrency.textContent = gestionCreditCurrencySymbol
                        ? `${gestionCreditCurrencySymbol} `
                        : '';
                }
                setGestionWithholdingCompletionMode(Boolean(completeWithholding));
                if (!completeWithholding) {
                    renderGestionInstallments();
                }

                gestionModal.classList.add('visible');
                document.body.style.overflow = 'hidden';
                loadSaldoFavorDisponibilidad();
                loadCreditNotes(clientId, currencyId, 'gestion');
                updateGestionBalanceSummary();
            };

            let gestionPaymentRequestInProgress = false;

            const closeGestionModal = function () {
                if (!gestionModal || gestionPaymentRequestInProgress) {
                    return;
                }
                gestionModal.classList.remove('visible');
                if (gestionFileInput) {
                    gestionFileInput.value = '';
                }
                gestionFileClear?.classList.add('hidden');
                if (gestionSaveButton) {
                    gestionSaveButton.disabled = false;
                }
                if (gestionFileName) {
                    gestionFileName.textContent = '';
                }
                if (gestionUploadBox) {
                    gestionUploadBox.classList.remove('is-invalid');
                }
                if (gestionPreview) {
                    gestionPreview.src = '';
                    gestionPreview.style.display = 'none';
                    gestionPreview.classList.remove('visible');
                }
                const descInput = document.getElementById('erp-gestion-description');
                if (descInput) descInput.value = '';

                if (gestionSavingStatus) {
                    gestionSavingStatus.innerHTML = '<span class="erp-gestion-spinner" aria-hidden="true"></span>Guardando datos...';
                    gestionSavingStatus.classList.remove('is-visible');
                }
                gestionAmountManuallyEdited = false;
                gestionBalanceSummary?.replaceChildren();
                gestionBalanceSummary?.classList.add('hidden');
                document.body.style.overflow = '';
            };

            document.addEventListener('click', function (event) {
                const gestionButton = event.target.closest('.erp-btn-gestion');
                if (gestionButton) {
                    openGestionModal(
                        gestionButton.dataset.cotizacionNumber || '',
                        gestionButton.dataset.cotizacionApproveUrl || '',
                        gestionButton.dataset.cotizacionClient || '',
                        gestionButton.dataset.cotizacionClientId || '',
                        gestionButton.dataset.cotizacionAmount || '',
                        gestionButton.dataset.cotizacionPayment || '',
                        gestionButton.dataset.cotizacionPaymentId || '',
                        gestionButton.dataset.cotizacionCurrencyId || '',
                        gestionButton.dataset.cotizacionBalance || '',
                        gestionButton.dataset.cotizacionReference || '',
                        gestionButton.dataset.cotizacionCxcState || '',
                        gestionButton.dataset.cotizacionCreditTotal || '',
                        gestionButton.dataset.cotizacionPaidInstallments || '[]',
                        gestionButton.dataset.cotizacionCurrencySymbol || '',
                        gestionButton.dataset.cotizacionNextInstallment || '',
                        gestionButton.dataset.cotizacionNextInstallmentPaymentId || '',
                        gestionButton.dataset.cotizacionNextInstallmentAmount || '',
                        gestionButton.dataset.cotizacionNextInstallmentDate || '',
                        gestionButton.dataset.cotizacionService || '',
                        gestionButton.dataset.cotizacionCompleteWithholding === '1',
                        gestionButton.dataset.cotizacionCxcId || ''
                    );
                    return;
                }

                const creditNoteButton = event.target.closest('#gestion-select-credit-note');
                if (creditNoteButton) {
                    openCreditNoteModal('gestion');
                    return;
                }

                if (event.target.closest('#gestion-select-credit-amount')) {
                    openSaldoFavorModal('gestion');
                    return;
                }

                if (event.target.closest('[data-erp-close-gestion]')) {
                    closeGestionModal();
                }
            });

            gestionApplyRetention?.addEventListener('change', function () {
                updateGestionWithholding(
                    gestionOriginalBalance,
                    gestionCurrencyOption(gestionOriginalCurrencyId)?.dataset.symbol || currentVencidosState.currencySymbol
                );
                updateGestionCreditNoteApplication();
                setGestionAmountToBalance();
            });
            gestionAmountInput?.addEventListener('input', function () {
                gestionAmountManuallyEdited = true;
                updateGestionBalanceSummary();
            });
            gestionForeignAmount?.addEventListener('input', function () {
                gestionAmountManuallyEdited = true;
                updateGestionBalanceSummary();
            });

            const handleGestionFile = function (file) {
                if (!file || !gestionFileInput || !gestionPreview) {
                    return;
                }

                const allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
                const maxFileSize = 5 * 1024 * 1024;

                if (!allowedTypes.includes(file.type) || file.size > maxFileSize) {
                    gestionFileInput.value = '';
                    if (gestionSaveButton) {
                        gestionSaveButton.disabled = false;
                    }
                    gestionPreview.classList.remove('visible');
                    gestionPreview.src = '';
                    if (gestionFileName) {
                        gestionFileName.textContent = 'Selecciona un JPG, PNG o PDF de máximo 5 MB.';
                    }
                    gestionFileClear?.classList.add('hidden');
                    if (gestionUploadBox) {
                        gestionUploadBox.classList.add('is-invalid');
                    }
                    return;
                }

                const transfer = new DataTransfer();
                transfer.items.add(file);
                gestionFileInput.files = transfer.files;
                if (gestionSaveButton) {
                    gestionSaveButton.disabled = false;
                }
                if (gestionUploadBox) {
                    gestionUploadBox.classList.remove('is-invalid');
                }
                if (gestionFileName) {
                    gestionFileName.textContent = file.name;
                }
                gestionFileClear?.classList.remove('hidden');
                gestionPreview.classList.remove('visible');
                gestionPreview.src = '';

                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function (event) {
                        gestionPreview.src = event.target.result;
                        gestionPreview.classList.add('visible');
                    };
                    reader.readAsDataURL(file);
                }
            };

            if (gestionFileInput) {
                gestionFileInput.addEventListener('change', function (event) {
                    handleGestionFile(event.target.files && event.target.files[0]);
                });
            }
            gestionFileClear?.addEventListener('click', () => {
                if (gestionFileInput) gestionFileInput.value = '';
                if (gestionFileName) gestionFileName.textContent = '';
                if (gestionPreview) {
                    gestionPreview.src = '';
                    gestionPreview.style.display = 'none';
                    gestionPreview.classList.remove('visible');
                }
                gestionFileClear.classList.add('hidden');
                gestionUploadBox?.classList.remove('is-invalid', 'is-dragging');
            });

            if (gestionUploadBox) {
                ['dragenter', 'dragover'].forEach(function (eventName) {
                    gestionUploadBox.addEventListener(eventName, function (event) {
                        event.preventDefault();
                        gestionUploadBox.classList.add('is-dragging');
                    });
                });
                ['dragleave', 'drop'].forEach(function (eventName) {
                    gestionUploadBox.addEventListener(eventName, function (event) {
                        event.preventDefault();
                        gestionUploadBox.classList.remove('is-dragging');
                    });
                });
                gestionUploadBox.addEventListener('drop', function (event) {
                    handleGestionFile(event.dataTransfer.files && event.dataTransfer.files[0]);
                });
            }

            document.addEventListener('paste', function (event) {
                if (quotationWithholdingProofModal?.classList.contains('visible')) return;
                if (!gestionModal || !gestionModal.classList.contains('visible')) {
                    return;
                }
                const pastedItem = Array.from((event.clipboardData && event.clipboardData.items) || [])
                    .find(function (item) { return item.kind === 'file'; });
                if (pastedItem) {
                    event.preventDefault();
                    handleGestionFile(pastedItem.getAsFile());
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key !== 'Escape') return;
                if (quotationWithholdingProofModal?.classList.contains('visible')) {
                    returnToWithholdingWarning();
                    return;
                }
                const warningModal = document.getElementById('withholding-warning-modal');
                if (warningModal?.classList.contains('visible')) {
                    warningModal.classList.add('hidden');
                    warningModal.classList.remove('visible');
                    withholdingWarningConfirm = null;
                    quotationWithholdingSubmit = null;
                    document.body.style.overflow = '';
                    return;
                }
                if (gestionModal && gestionModal.classList.contains('visible')) {
                    closeGestionModal();
                }
            });

            if (gestionForm) {
                gestionForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    if (gestionPaymentRequestInProgress) return;

                    const withholdingDecisionInput = document.getElementById('erp-gestion-withholding-decision');
                    const isOtherCurrency = gestionCurrencyMode?.value === 'other';
                    const submittedAmount = Number((isOtherCurrency ? gestionForeignAmount : gestionAmountInput)?.value || 0);
                    const sourceNoteCredit = Math.min(
                        selectedCreditNotesAmount('gestion'),
                        Math.max(0, gestionOriginalBalance - gestionDeductionAmount)
                    );
                    const sourceBankCredit = Math.min(
                        selectedBankCreditAmount('gestion', currentVencidosState.saldoFavorDisponible),
                        Math.max(0, gestionOriginalBalance - gestionDeductionAmount - sourceNoteCredit)
                    );
                    const sourceNetCashDue = Math.max(
                        0,
                        gestionOriginalBalance - gestionDeductionAmount - sourceNoteCredit - sourceBankCredit
                    );
                    const sourceGrossCashDue = Math.max(
                        0,
                        gestionOriginalBalance - sourceNoteCredit - sourceBankCredit
                    );
                    const paymentCurrency = gestionOtherCurrency?.selectedOptions?.[0];
                    const exchangeRate = Number(gestionExchangeRate?.value || 0);
                    const convertToPaymentCurrency = (amount) => isOtherCurrency
                        ? (gestionConvertAmount(amount, paymentCurrency, exchangeRate) ?? amount)
                        : amount;
                    const netCashDue = convertToPaymentCurrency(sourceNetCashDue);
                    const grossCashDue = convertToPaymentCurrency(sourceGrossCashDue);
                    const isWithholdingDecisionPayment = gestionWithholdingEligible
                        && !gestionCompleteWithholding
                        && gestionDeductionAmount > 0
                        && withholdingDecisionInput?.value === ''
                        && submittedAmount + 0.01 >= netCashDue;

                    if (isWithholdingDecisionPayment) {
                        event.preventDefault();
                        const paymentCurrencySymbol = isOtherCurrency
                            ? normalizeGestionCurrencySymbol(gestionOtherCurrency?.selectedOptions?.[0]?.dataset.symbol)
                            : normalizeGestionCurrencySymbol(gestionCurrencyOption(gestionOriginalCurrencyId)?.dataset.symbol);
                        showWithholdingWarning(gestionGrossBalance, gestionWithholdingRetention, withholdingDecisionInput, {
                            getEnteredAmount: () => submittedAmount,
                            getDeductionAmount: () => convertToPaymentCurrency(gestionDeductionAmount),
                            getNetAmount: () => netCashDue,
                            getGrossAmount: () => grossCashDue,
                            submit: () => {
                                if (gestionSaveButton) gestionSaveButton.disabled = true;
                                if (gestionSavingStatus) gestionSavingStatus.classList.add('is-visible');
                                gestionForm.requestSubmit();
                            },
                            onPaid: openQuotationWithholdingProof,
                        }, paymentCurrencySymbol, 'cotización');
                        return;
                    }

                    if (gestionSaveButton) {
                        gestionSaveButton.disabled = true;
                    }
                    if (gestionSavingStatus) {
                        gestionSavingStatus.innerHTML = '<span class="erp-gestion-spinner" aria-hidden="true"></span>Guardando datos...';
                        gestionSavingStatus.classList.add('is-visible');
                    }
                    gestionPaymentRequestInProgress = true;
                    HTMLFormElement.prototype.submit.call(gestionForm);
                });
            }
        });
    </script>

    <!-- MODAL 1: REGISTRAR FACTURA / DOCUMENTO DE REFERENCIA (ESTADO PENDIENTE) -->
    <div id="service-factura-modal" class="erp-modal-backdrop hidden" role="dialog" aria-modal="true"
        aria-labelledby="service-factura-title">
        <div class="erp-modal-card" style="border-radius: 16px;">
            <div class="erp-modal-header">
                <h3 id="service-factura-title" class="font-bold text-slate-900 text-lg">Registrar factura / documento</h3>
                <button type="button" class="erp-modal-close" data-close-service-factura aria-label="Cerrar">×</button>
            </div>
            <form method="POST" id="service-factura-form" action="" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="cxc_id" id="factura-cxc-id">
                <input type="hidden" name="servicioCliente_idservicioCliente" id="factura-service-id">
                <input type="hidden" name="realizar_cobro" id="factura-realizar-cobro-input" value="0">
                <input type="hidden" name="monto_factura" id="factura-invoice-amount">
                <input type="hidden" name="factura_withholding_decision" id="factura-withholding-decision">
                <div id="factura-selected-service-inputs"></div>
                <div id="factura-selected-cxc-inputs"></div>
                <div class="erp-modal-body p-6">

                    <!-- SWITCH DE MODO: SOLO FACTURAR VS FACTURAR Y CANCELAR -->
                    <div class="erp-factura-mode-shell mb-4">
                        <div class="erp-factura-mode-text">
                            <span class="erp-factura-mode-title" id="factura-mode-title">Solo Facturar</span>
                            <span class="erp-factura-mode-desc" id="factura-mode-desc">Estado cambiará a FACTURADO. Activa
                                el switch si deseas Facturar y Cancelar a la vez.</span>
                        </div>
                        <div id="factura-payment-date-field" class="hidden min-w-[150px]">
                            <label for="factura-payment-date" class="block text-xs font-semibold text-slate-600">
                                Fecha de Pago
                                <input id="factura-payment-date" name="fechaPago" type="date"
                                    value="{{ now()->format('Y-m-d') }}"
                                    class="mt-1 w-full rounded-lg border border-slate-200 bg-white p-1.5 text-xs text-slate-700 outline-none">
                            </label>
                        </div>
                        <div id="factura-mode-toggle" class="erp-factura-mode-switch" data-mode="facturar" role="switch"
                            aria-checked="false" tabindex="0"
                            aria-label="Alternar entre solo facturar y facturar y cancelar">
                            <span class="erp-factura-mode-thumb"></span>
                            <span class="erp-factura-mode-option is-active" data-mode="facturar"
                                style="font-size: 0.6rem;">Factura</span>
                            <span class="erp-factura-mode-option" data-mode="cancelar"
                                style="font-size: 0.6rem;">Cancelar</span>
                        </div>
                        <input type="hidden" id="factura-mode-switch" value="0">
                    </div>

                    <!-- CONTENEDOR GRID DE 2 COLUMNAS PARA FACTURACIÓN Y REGISTRO DE COBRO -->
                    <div class="factura-grid-container">

                        <!-- COLUMNA 1: DATOS DE FACTURACIÓN -->
                        <div class="space-y-4">
                            <div class="flex items-center gap-2 pb-1 border-b border-slate-100">
                                <h4 class="text-xs font-bold text-slate-800 uppercase tracking-wider">Datos de Facturación
                                </h4>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 mb-1.5">
                                <div id="factura-client-field">
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">Cliente</label>
                                    <input id="factura-client"
                                        class="w-full rounded-lg border border-slate-200 bg-slate-50 p-2.5 text-sm font-medium text-slate-800 outline-none"
                                        readonly>
                                </div>
                                <div id="factura-payment-options">
                                    <div
                                        style="display: flex; justify-content: space-between; align-items: center; width: 100%; margin-bottom: 4px;">
                                        <label class="block text-xs font-semibold text-slate-600">Monto a cancelar</label>
                                        <div class="flex items-center gap-1.5">
                                            <button type="button" id="btn-factura-periodos-vencidos"
                                                class="hidden px-2 py-0.5 text-xs font-bold text-white rounded transition-all hover:opacity-90 shadow-sm"
                                                style="background-color: #b41B29;">
                                                P. vencidos
                                            </button>
                                            <button type="button" id="factura-select-credit-note"
                                                class="hidden px-2 py-0.5 text-xs font-bold text-white rounded transition-all hover:opacity-90 shadow-sm disabled:cursor-not-allowed disabled:opacity-60"
                                                style="background-color: #334155;">
                                                N. crédito
                                            </button>
                                            <button type="button" id="factura-select-credit-amount"
                                                class="hidden rounded px-2 py-0.5 text-xs font-bold text-white transition-all hover:opacity-90 shadow-sm"
                                                style="background-color: #334155;">
                                                Saldo a favor
                                            </button>
                                        </div>
                                    </div>
                                    <div class="flex min-w-0 items-stretch gap-2">
                                        <div
                                            class="flex min-w-0 flex-1 items-center rounded-lg border border-slate-300 bg-white px-2.5 focus-within:border-red-600 focus-within:ring-2 focus-within:ring-red-600/20">
                                            <span id="factura-amount-currency"
                                                class="mr-1.5 shrink-0 text-sm font-bold text-slate-700">S/</span>
                                            <input id="factura-amount" type="number" min="0.01" step="0.01" readonly
                                                class="w-full min-w-0 border-0 bg-transparent p-2 text-sm font-bold text-slate-900 outline-none focus:border-0 focus:ring-0">
                                        </div>
                                        <button type="button" id="btn-factura-advance-toggle"
                                            class="hidden shrink-0 rounded-md px-2.5 py-1 text-xs font-bold text-white transition-all hover:opacity-90 shadow-sm"
                                            style="background-color: #334155;" aria-expanded="false">
                                            Pagar adelantado
                                        </button>
                                    </div>
                                    <p id="factura-amount-summary" class="hidden mt-1 text-xs font-medium text-danger"
                                        aria-live="polite"></p>
                                    <div id="factura-advance-fields" class="hidden mt-2">
                                        <label for="factura-advance-months"
                                            class="text-xs font-semibold text-slate-600">Mensualidades cubiertas
                                            <input id="factura-advance-months" name="adelanto_meses" type="number" min="1"
                                                max="120" step="1"
                                                class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-sm text-slate-900 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                                        </label>
                                    </div>
                                    <p id="factura-advance-summary"
                                        class="hidden mt-1 text-xs font-semibold text-emerald-700" aria-live="polite"></p>
                                </div>
                            </div>
                            <div class="mb-1.5">
                                <label for="factura-doc-ref" class="block text-xs font-bold text-slate-700 mb-1">
                                    N° Documento de Referencia (Factura / Boleta) <span>*</span>
                                </label>
                                <input id="factura-doc-ref" name="docReferencia" type="text" maxlength="15" required
                                    placeholder="Ej: F001-000123"
                                    class="w-full rounded-lg border border-slate-300 p-2.5 text-sm font-medium text-slate-800 focus:border-red-600 focus:ring-2 focus:ring-red-600/20 outline-none transition-all">
                            </div>
                            <div class="mb-1.5">
                                <div class="mb-1.5 flex items-center justify-between gap-2">
                                    <label class="text-xs font-bold text-slate-800">
                                        Archivo de Factura (opcional)
                                    </label>
                                    <button type="button" data-clear-upload="factura-file"
                                        data-clear-name="factura-file-name"
                                        class="hidden px-2 py-0.5 text-xs font-bold text-white rounded transition-all hover:opacity-90 shadow-sm"
                                        style="background-color: #b41B29;">
                                        Quitar archivo
                                    </button>
                                </div>
                                <label id="factura-upload-box" for="factura-file" class="erp-upload-box factura-upload-box">
                                    <svg class="erp-upload-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                                        stroke-linejoin="round" aria-hidden="true">
                                        <path d="M16 16l-4-4-4 4"></path>
                                        <path d="M12 12v9"></path>
                                        <path d="M20.39 17.39A5 5 0 0 0 18 8h-1.26A8 8 0 1 0 3 16.3"></path>
                                    </svg>
                                    <span class="erp-upload-title">Cargar factura o boleta</span>
                                    <span class="erp-upload-help">Haz clic para buscar, arrastra o pega (Ctrl + V / Cmd +
                                        V)</span>
                                    <span class="erp-upload-help">Archivos permitidos: JPG, PNG o PDF (máx. 5 MB)</span>
                                    <span id="factura-file-name" class="erp-upload-file-name" aria-live="polite"></span>
                                    <input id="factura-file" name="archivoFactura" type="file"
                                        accept="image/jpeg,image/png,application/pdf">
                                </label>
                            </div>
                            <div>
                                <label for="factura-description"
                                    class="block text-xs font-semibold text-slate-600 mb-1">Descripción / Observación (max.
                                    100 caracteres)
                                </label>
                                <textarea id="factura-description" name="descripcionFactura" rows="2" maxlength="100"
                                    placeholder="Describe la factura o boleta..."
                                    class="w-full rounded-lg border border-slate-300 p-2.5 text-sm text-slate-800 focus:border-red-600 focus:ring-2 focus:ring-red-600/20 outline-none transition-all"></textarea>
                            </div>
                        </div>

                        <!-- COLUMNA 2: REGISTRO DE COBRO / PAGO (ESTILO COMPLETO COMO MODAL 2) -->
                        <div id="factura-cobro-extra-section" class="erp-factura-mode-extra space-y-3 lg:pl-6">
                            <div class="flex items-center gap-2 pb-1 border-b border-slate-100">
                                <h4 class="text-xs font-bold uppercase tracking-wider text-[#b41B29]">Información del Cobro
                                    / Pago</h4>
                            </div>

                            <div class="grid grid-cols-2 sm:grid-cols-2 gap-2.5">
                                <input type="hidden" id="factura-payment-type" name="tipoCobro_idtipoCobros">
                                <div>
                                    <label for="factura-payment-method"
                                        class="block text-xs font-semibold text-slate-700 mb-1">Forma de pago <span
                                            class="text-red-500">*</span></label>
                                    <select id="factura-payment-method" name="formaPago_idformaPago"
                                        class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20 bg-white">
                                        <option value="">Selecciona</option>
                                        @foreach($formaPagoOptions as $option)
                                            <option value="{{ $option['value'] }}"
                                                data-credit-days="{{ $option['credit_days'] ?? 0 }}"
                                                @selected(str_contains(mb_strtolower($option['label'], 'UTF-8'), 'contado'))>
                                                {{ $option['label'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div id="factura-credit-fields" class="hidden">
                                    <label for="factura-can-cuotas"
                                        class="block text-xs font-semibold text-slate-700 mb-1">Cantidad de cuotas</label>
                                    <input id="factura-can-cuotas" name="canCuotas" type="number" min="1" step="1" value=""
                                        class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                                </div>
                                <div id="factura-credit-installments"
                                    class="hidden col-span-2 rounded-lg border border-red-100 bg-red-50/40 p-2.5">
                                    <div id="factura-installments" class="space-y-2">
                                        <div class="grid grid-cols-1 gap-2 items-center mb-1.5"></div>
                                    </div>
                                </div>
                                <div>
                                    <label for="factura-payment-bank"
                                        class="block text-xs font-semibold text-slate-700 mb-1">Entidad bancaria <span
                                            class="text-red-500">*</span></label>
                                    <select id="factura-payment-bank" name="entidadBancaria_identidadBancaria"
                                        class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20 bg-white">
                                        <option value="">Selecciona</option>
                                        @foreach($entidadBancariaOptions as $option)
                                            <option value="{{ $option['value'] }}" @selected((string) $option['value'] === '1')>
                                                {{ $option['label'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="factura-period-end"
                                        class="block text-xs font-semibold text-slate-600 mb-1">Fecha Fin del
                                        periodo</label>
                                    <input id="factura-period-end" name="fechaFin" type="date"
                                        class="w-full rounded-lg border border-slate-200 bg-white p-2 text-xs text-slate-700 outline-none">
                                    <span class="mt-1 block text-[10px] font-normal text-slate-500">Si cambias la fecha, se
                                        aplica el día elegido y cada vehículo conserva su periodo.</span>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 sm:grid-cols-2 gap-2.5 mb-1.5">
                                <div class="space-y-2">
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-700 mb-2">Tipo de moneda <span
                                                class="text-red-500">*</span></label>
                                        <div class="flex items-center gap-4 bg-white mb-1.5">
                                            <label
                                                class="inline-flex items-center gap-2 cursor-pointer text-xs font-medium text-slate-700">
                                                <input type="radio" name="factura_payment_currency_mode" value="same"
                                                    checked class="accent-[#b41B29]">
                                                Misma moneda
                                            </label>
                                            <label
                                                class="inline-flex items-center gap-2 cursor-pointer text-xs font-medium text-slate-700">
                                                <input type="radio" name="factura_payment_currency_mode" value="other"
                                                    class="accent-[#b41B29]">
                                                Otra moneda
                                            </label>
                                        </div>
                                    </div>
                                    <div id="factura-payment-currency-extra" class="hidden space-y-2">
                                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                            <div>
                                                <label for="factura-payment-currency"
                                                    class="block text-xs font-semibold text-slate-700 mb-1"
                                                    style="font-size: 0.65rem;">Moneda</label>
                                                <select id="factura-payment-currency" name="currency_selector"
                                                    class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20"
                                                    style="font-size: 0.70rem;">
                                                    <option value="">Selecciona</option>
                                                    @foreach($monedasOptions as $option)
                                                        <option value="{{ $option['value'] }}"
                                                            data-symbol="{{ $option['simbolo'] }}">
                                                            {{ $option['label'] }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label for="factura-payment-exchange-rate"
                                                    class="block text-xs font-semibold text-slate-700 mb-1"
                                                    style="font-size: 0.65rem;">Tipo de cambio</label>
                                                <input id="factura-payment-exchange-rate" name="tipo_cambio" type="number"
                                                    min="0.0001" step="0.0001"
                                                    class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                                            </div>
                                            <div>
                                                <label for="factura-payment-converted-amount"
                                                    class="block text-xs font-semibold text-slate-700 mb-1"
                                                    style="font-size: 0.65rem;">Monto cancelado</label>
                                                <input id="factura-payment-converted-amount" name="monto_cancelado"
                                                    type="number" min="0.01" step="0.01"
                                                    class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                                            </div>
                                        </div>
                                        <div class="mt-1">
                                            <label for="factura-payment-converted-total"
                                                class="block text-xs font-semibold text-slate-700 mb-1"
                                                style="font-size: 0.65rem;">Total en la moneda seleccionada</label>
                                            <div
                                                class="flex items-center rounded-lg border border-slate-200 bg-slate-50 px-2 focus-within:border-red-600">
                                                <span id="factura-payment-converted-total-symbol"
                                                    class="mr-1.5 min-w-[1.5rem] shrink-0 text-xs font-bold text-slate-700">S/</span>
                                                <input id="factura-payment-converted-total" name="precio_convertido"
                                                    type="number" readonly
                                                    class="w-full min-w-0 border-0 bg-transparent p-2 text-xs font-bold text-slate-900 outline-none focus:ring-0"
                                                    style="border: 0;">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <div>
                                        <span class="block text-xs font-semibold text-slate-700 mb-2">Tipo de pago</span>
                                        <div class="flex items-start gap-6 mb-1.5">
                                            <div>
                                                <label
                                                    class="inline-flex items-center gap-2 cursor-pointer text-xs font-medium text-slate-700">
                                                    <input type="radio" name="factura_modo_pago" value="total"
                                                        class="accent-[#b41B29]">
                                                    Pago total
                                                </label>
                                            </div>
                                            <label
                                                class="inline-flex items-center gap-2 cursor-pointer text-xs font-medium text-slate-700">
                                                <input type="radio" name="factura_modo_pago" value="parcial" checked
                                                    class="accent-[#b41B29]">
                                                Pago parcial
                                            </label>
                                        </div>
                                    </div>
                                    <p id="factura-payment-total-credit-summary"
                                                    class="hidden mt-1 text-xs font-semibold text-danger"
                                                    aria-live="polite"></p>
                                    <div id="factura-partial-payment-field" class="hidden">
                                        <label for="factura-partial-payment-amount"
                                            class="block text-xs font-semibold text-slate-700 mb-1"
                                            style="font-size: 0.65rem;">Monto actual pagado</label>
                                        <div
                                            class="flex items-center rounded-lg border border-slate-300 bg-white px-2 focus-within:border-red-600 focus-within:ring-2 focus-within:ring-red-600/20">
                                            <span id="factura-partial-payment-amount-currency"
                                                class="mr-1.5 shrink-0 text-xs font-bold text-slate-700">S/</span>
                                            <input id="factura-partial-payment-amount" name="montoPago" type="number"
                                                step="0.01"
                                                class="w-full min-w-0 border-0 bg-transparent p-2 text-xs text-slate-800 outline-none focus:ring-0"
                                                style="border: 0;">
                                        </div>
                                        <div class="flex flex-wrap items-start gap-x-2 mt-1">
                                            <p class="text-xs text-slate-800 mt-1">Saldo restante: <strong
                                                    id="factura-payment-balance">S/ 0.00</strong></p>
                                            <p id="factura-payment-credit-summary"
                                                class="hidden text-xs font-semibold text-danger mt-1" aria-live="polite"></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <input id="factura-use-credit" name="usar_saldo_favor" type="checkbox" value="1"
                                class="hidden">
                            <input id="factura-credit-amount" name="monto_saldo_favor" type="hidden">
                            <input id="factura-credit-note-id" type="hidden" name="nota_credito_ids[]" disabled>
                            <!-- COMPROBANTE DE PAGO (DROPZONE CON EL MISMO ESTILO Y COLOR DE MODAL 2) -->
                            <div class="mb-1.5">
                                <div class="mb-1.5 flex items-center justify-between gap-2">
                                    <label class="text-xs font-bold text-slate-800">Comprobante de pago (opcional)</label>
                                    <button type="button" data-clear-upload="factura-comprobante"
                                        data-clear-name="factura-payment-file-name"
                                        class="hidden px-2 py-0.5 text-xs font-bold text-white rounded transition-all hover:opacity-90 shadow-sm"
                                        style="background-color: #b41B29;">
                                        Quitar pago
                                    </button>
                                </div>
                                <label id="factura-payment-upload-box" for="factura-comprobante"
                                    class="erp-upload-box factura-upload-box">
                                    <svg class="erp-upload-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                                        stroke-linejoin="round" aria-hidden="true">
                                        <path d="M16 16l-4-4-4 4"></path>
                                        <path d="M12 12v9"></path>
                                        <path d="M20.39 17.39A5 5 0 0 0 18 8h-1.26A8 8 0 1 0 3 16.3"></path>
                                    </svg>
                                    <span id="payment-dropzone-title" class="erp-upload-title">Cargar comprobante de
                                        pago</span>
                                    <span class="erp-upload-help">Haz clic para buscar, arrastra o pega (Ctrl + V / Cmd +
                                        V)</span>
                                    <span class="erp-upload-help">Archivos permitidos: JPG, PNG o PDF (máx. 5 MB)</span>
                                    <span id="factura-payment-file-name" class="erp-upload-file-name"
                                        aria-live="polite"></span>
                                    <input id="factura-comprobante" name="comprobante" type="file"
                                        accept="image/jpeg,image/png,application/pdf">
                                </label>
                            </div>
                            <div>
                                <label for="payment-description"
                                    class="block text-xs font-semibold text-slate-600 mb-1">Descripción / Observación (max.
                                    100 caracteres)</label>
                                <textarea id="payment-description" name="descripcionPago" maxlength="100" rows="2"
                                    placeholder="Observaciones del pago..."
                                    class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20"></textarea>
                            </div>

                        </div>

                    </div>

                    <div id="factura-submit-error"
                        class="hidden mb-2 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm font-semibold text-red-800"
                        role="alert" aria-live="assertive"></div>
                    <div class="flex items-center justify-end gap-3 mt-1 pt-2 border-t border-slate-200">
                        <button type="button"
                            class="px-4 py-2 text-sm font-semibold rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-100 transition-colors"
                            data-close-service-factura>Cancelar</button>
                        <button type="submit" id="factura-submit-btn"
                            class="px-5 py-2 text-sm font-bold text-white rounded-lg shadow-sm transition-all"
                            style="background-color: #b41B29;">Guardar Factura</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <style>
        #service-payment-modal.is-payment-view-mode #payment-modal-fields-grid> :not(#payment-client-field):not(#payment-method-field):not(#payment-bank-field):not(#payment-reference-field):not(#payment-amount-field):not(#payment-currency-settings):not(#payment-description-field):not(#payment-proof-field) {
            display: none !important;
        }

        #service-payment-modal.is-payment-view-mode #payment-currency-settings {
            display: none !important;
        }

        #service-payment-modal.is-payment-view-mode #payment-amount-field {
            grid-column: 1 / -1;
        }

        #service-payment-modal.is-payment-view-mode #btn-periodos-vencidos,
        #service-payment-modal.is-payment-view-mode #payment-select-credit-note,
        #service-payment-modal.is-withholding-completion #payment-select-credit-amount,
        #service-payment-modal.is-payment-view-mode #payment-select-credit-amount,
        #service-payment-modal.is-payment-view-mode #btn-payment-advance-toggle,
        #service-payment-modal.is-payment-view-mode #payment-advance-summary,
        #service-payment-modal.is-payment-view-mode #payment-amount-summary {
            display: none !important;
        }

        #service-payment-modal.is-payment-view-mode:not(.is-payment-editing) #payment-dropzone,
        #service-payment-modal.is-payment-view-mode:not(.is-payment-editing) #payment-clear-comprobante {
            display: none !important;
        }

        #service-payment-modal.is-withholding-completion .erp-modal-card {
            max-width: 620px !important;
        }

        #service-payment-modal.is-withholding-completion .erp-modal-body {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.75rem;
        }

        #service-payment-modal.is-withholding-completion .erp-modal-body>#payment-date-fields,
        #service-payment-modal.is-withholding-completion #payment-modal-fields-grid {
            display: contents !important;
        }

        #service-payment-modal.is-withholding-completion #payment-client-field {
            grid-column: 1 / -1;
            order: 0;
        }

        #service-payment-modal.is-withholding-completion label[for="payment-period-end"] {
            order: 1;
        }

        #service-payment-modal.is-withholding-completion #payment-period-end-help {
            display: none !important;
        }

        #service-payment-modal.is-withholding-completion label[for="payment-date"] {
            order: 2;
        }

        #service-payment-modal.is-withholding-completion #payment-reference-field {
            order: 3;
        }

        #service-payment-modal.is-withholding-completion #payment-amount-field {
            order: 4;
        }

        #service-payment-modal.is-withholding-completion #payment-description-field {
            order: 5;
        }

        #service-payment-modal.is-withholding-completion #payment-proof-field {
            order: 6;
        }

        #service-payment-modal.is-withholding-completion #service-payment-form>.erp-modal-body>.flex.items-center.justify-end {
            grid-column: 1 / -1;
            order: 7;
        }

        #service-payment-modal.is-withholding-completion #btn-periodos-vencidos,
        #service-payment-modal.is-withholding-completion #payment-select-credit-note,
        #service-payment-modal.is-withholding-completion #btn-payment-advance-toggle {
            display: none !important;
        }

        #service-payment-modal.is-withholding-completion #payment-modal-fields-grid {
            gap: 0.75rem;
        }

        #service-payment-modal.is-withholding-completion #payment-original-input-shell {
            display: flex;
        }

        #service-payment-modal.is-withholding-completion #payment-dropzone {
            min-height: 112px;
            padding: 0.75rem;
        }

        #service-payment-modal.is-withholding-completion #payment-description {
            height: 112px !important;
        }

        #partial-payment-modal .erp-modal-card {
            width: min(600px, calc(100vw - 32px));
            max-width: 620px;
        }

        #partial-payment-modal .erp-modal-body {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.75rem;
        }

        #partial-payment-modal .partial-payment-full {
            grid-column: 1 / -1;
        }

        #partial-payment-modal .partial-payment-upload {
            min-height: 112px;
            padding: 0.75rem;
            border-color: #fca5a5;
            background: #fff7f7;
        }

        #partial-payment-modal .partial-payment-upload:hover,
        #partial-payment-modal .partial-payment-upload.is-dragging,
        #partial-payment-modal .partial-payment-upload:focus-within {
            border-color: #b41b29;
            background: #fff1f2;
            box-shadow: 0 0 0 3px rgba(180, 27, 41, 0.1);
        }

        #partial-payment-modal .partial-payment-upload.is-invalid {
            border-color: #b91c1c;
            background: #fef2f2;
        }

        #partial-payment-modal .partial-payment-actions {
            grid-column: 1 / -1;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            border-top: 1px solid #edf2f7;
            padding-top: 0.75rem;
        }

        @media (max-width: 560px) {
            #partial-payment-modal .erp-modal-body {
                grid-template-columns: 1fr;
            }

            #partial-payment-modal .partial-payment-full,
            #partial-payment-modal .partial-payment-actions {
                grid-column: auto;
            }
        }

        #payment-method-controls {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 0.5rem;
        }

        #payment-method-field.is-credit-payment #payment-method-controls {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        #service-payment-modal.is-payment-view-mode #payment-credit-fields {
            display: none !important;
        }
    </style>

    <!-- MODAL 2: REGISTRAR COBRO DE SERVICIOS (ESTADO FACTURADO / VENCIDO CON DROPZONE IMAGEN 3) -->
    <div id="service-payment-modal" class="erp-modal-backdrop hidden" role="dialog" aria-modal="true"
        aria-labelledby="service-payment-title">
        <div class="erp-modal-card" style="width: min(750px, calc(100vw - 32px)); max-width: 850px; border-radius: 16px;">
            <div class="erp-modal-header">
                <h3 id="service-payment-title" class="font-bold text-slate-900 text-lg">Registrar cobro de servicios</h3>
                <button type="button" class="erp-modal-close" data-close-service-payment aria-label="Cerrar">×</button>
            </div>
            <form method="POST" action="{{ route('modules.cuentasporcobrar.cobrar-servicios') }}" id="service-payment-form"
                enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="idcuentasPorCobrar" id="payment-cxc-id">
                <input type="hidden" name="moneda_idmoneda" id="payment-moneda-id">
                <input type="hidden" name="monto_factura" id="payment-invoice-total">
                <input type="hidden" name="adelanto_meses" id="payment-advance-months">
                <input type="hidden" name="withholding_decision" id="payment-withholding-decision">
                <input type="hidden" name="completar_detraccion_pendiente" id="payment-complete-withholding" value="0">
                <input type="hidden" name="eliminar_comprobante" id="payment-remove-comprobante" value="0">
                <div id="selected-service-inputs"></div>
                <div id="payment-selected-cxc-inputs"></div>
                <div class="erp-modal-body p-6">
                    <div id="payment-date-fields" class="grid grid-cols-1 gap-2 border-b border-slate-100 pb-3 md:grid-cols-2">
                        <label for="payment-date" class="block text-xs font-semibold text-slate-600"><span
                                id="payment-date-label">Fecha de Pago</span>
                            <input id="payment-date" name="fechaPago" type="date" value="{{ now()->format('Y-m-d') }}"
                                class="mt-1 w-full rounded-lg border border-slate-200 bg-white p-2 text-sm text-slate-700 outline-none">
                        </label>
                        <label for="payment-period-end" class="block text-xs font-semibold text-slate-600">Fecha Fin del
                            periodo
                            <input id="payment-period-end" name="fechaFin" type="date"
                                class="mt-1 w-full rounded-lg border border-slate-200 bg-white p-2 text-sm text-slate-700 outline-none">
                            <span id="payment-period-end-help" class="mt-1 block text-[10px] font-normal text-slate-500">Si cambias la fecha, se aplica
                                el día elegido y cada vehículo conserva su periodo.</span>
                        </label>
                    </div>
                    <div id="payment-modal-fields-grid" class="grid grid-cols-1 md:grid-cols-2 gap-2">
                        <div id="payment-client-field">
                            <label for="payment-client"
                                class="block text-xs font-semibold text-slate-600 mb-1">Cliente</label>
                            <input id="payment-client"
                                class="w-full rounded-lg border border-slate-200 bg-slate-50 p-2 text-sm font-medium text-slate-800 outline-none"
                                readonly>
                        </div>
                        <input type="hidden" id="payment-type" name="tipoCobro_idtipoCobros">
                        <div id="payment-method-field">
                            <div id="payment-method-controls">
                                <label for="payment-method" class="block text-xs font-semibold text-slate-700">Forma de
                                    pago <span class="text-red-500">*</span>
                                    <select id="payment-method" name="formaPago_idformaPago" required
                                        class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                                        <option value="">Selecciona</option>
                                        @foreach($formaPagoOptions as $option)
                                            <option value="{{ $option['value'] }}"
                                                data-credit-days="{{ $option['credit_days'] ?? 0 }}"
                                                @selected(str_contains(mb_strtolower($option['label'], 'UTF-8'), 'contado'))>
                                                {{ $option['label'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </label>
                                <div id="payment-credit-fields" class="hidden">
                                    <label for="payment-can-cuotas"
                                        class="block text-xs font-semibold text-slate-700">Cantidad
                                        de cuotas</label>
                                    <input id="payment-can-cuotas" name="canCuotas" type="number" min="1" step="1" value=""
                                        class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                                </div>
                            </div>
                        </div>
                        <input id="payment-fixed-method" type="hidden" name="formaPago_idformaPago" disabled>
                        <div id="payment-credit-installments"
                            class="hidden md:col-span-2 rounded-lg border border-red-100 bg-red-50/40 p-2.5">
                            <div id="payment-installments" class="space-y-2"></div>
                        </div>
                        <div id="payment-bank-field">
                            <label for="payment-bank" class="block text-xs font-semibold text-slate-700 mb-1">Entidad
                                bancaria
                                cuenta <span class="text-red-500">*</span></label>
                            <select id="payment-bank" name="entidadBancaria_identidadBancaria" required
                                class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                                <option value="">Selecciona</option>
                                @foreach($entidadBancariaOptions as $option)
                                    <option value="{{ $option['value'] }}" @selected((string) $option['value'] === '1')>
                                        {{ $option['label'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div id="payment-reference-field">
                            <label for="payment-reference" class="block text-xs font-bold text-slate-700 mb-1">Documento de
                                referencia (Factura / Boleta)</label>
                            <input id="payment-reference" name="docReferencia"
                                class="w-full rounded-lg border border-slate-200 bg-slate-100 p-2 text-sm font-bold text-slate-800 outline-none"
                                readonly>
                        </div>
                        <div id="payment-amount-field" class="md:col-span-2">
                            <div class="mb-1 flex items-center justify-between gap-2">
                                <label for="payment-original" id="payment-original-label"
                                    class="block text-xs font-semibold text-slate-600">Monto a cancelar</label>
                                <button type="button" id="btn-periodos-vencidos"
                                    class="sm:ml-auto rounded px-2 py-0.5 text-xs font-bold text-white transition-all hover:opacity-90 shadow-sm"
                                    style="background-color: #b41B29;">
                                    P. vencidos
                                </button>
                                <button type="button" id="payment-select-credit-note"
                                    class="rounded px-2 py-0.5 text-xs font-bold text-white transition-all hover:opacity-90 shadow-sm disabled:cursor-not-allowed disabled:opacity-60"
                                    style="background-color: #334155;">
                                    N. crédito
                                </button>
                                <button type="button" id="payment-select-credit-amount"
                                    class="rounded px-2 py-0.5 text-xs font-bold text-white transition-all hover:opacity-90 shadow-sm"
                                    style="background-color: #334155;">
                                    Saldo a favor
                                </button>
                            </div>
                            <div id="payment-original-input-shell" class="flex items-center gap-2">
                                <div
                                    class="flex min-w-0 flex-1 items-center rounded-lg border border-slate-300 bg-white px-2.5 focus-within:border-red-600 focus-within:ring-2 focus-within:ring-red-600/20">
                                    <span id="payment-original-currency"
                                        class="mr-1.5 shrink-0 text-sm font-bold text-slate-700">S/</span>
                                    <input id="payment-original" type="number" min="0.01" step="0.01" required
                                        class="w-full min-w-0 border-0 bg-transparent p-2 text-sm font-bold text-slate-900 outline-none focus:border-0 focus:ring-0">
                                </div>
                                <button type="button" id="btn-payment-advance-toggle"
                                    class="shrink-0 rounded-md px-2.5 py-1 text-xs font-bold text-white transition-all hover:opacity-90 shadow-sm"
                                    style="background-color: #334155;" aria-expanded="false">
                                    Pagar adelantado
                                </button>
                            </div>
                            <p id="payment-amount-summary" class="hidden mt-1 text-xs font-medium text-danger"
                                aria-live="polite"></p>
                            <div class="hidden grid grid-cols-2 gap-2" id="payment-amount-view-displays">
                                <label class="min-w-0">
                                    <span class="mb-1 block text-xs font-semibold text-slate-600">Monto a cancelar</span>
                                    <input id="payment-original-display" type="text" readonly tabindex="-1"
                                        class="w-full rounded-lg border border-slate-200 bg-slate-50 p-2 text-sm font-bold text-slate-800 outline-none">
                                </label>
                                <label id="payment-foreign-amount-display" class="hidden min-w-0">
                                    <span class="mb-1 block text-xs font-semibold text-slate-600">Monto cancelado</span>
                                    <input id="payment-foreign-amount" type="text" readonly tabindex="-1"
                                        class="w-full rounded-lg border border-slate-200 bg-slate-50 p-2 text-sm font-bold text-slate-800 outline-none">
                                </label>
                            </div>
                            <div id="payment-advance-fields" class="hidden mt-2">
                                <label for="payment-advance-months-count"
                                    class="text-xs font-semibold text-slate-600">Mensualidades cubiertas
                                    <input id="payment-advance-months-count" type="number" min="1" max="120" step="1"
                                        class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-sm text-slate-900 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                                </label>
                            </div>
                            <p id="payment-advance-summary" class="hidden mt-1 text-xs font-semibold text-emerald-700"
                                aria-live="polite"></p>
                            <input id="payment-use-credit" name="usar_saldo_favor" type="checkbox" value="1"
                                class="hidden">
                            <input id="payment-credit-amount" name="monto_saldo_favor" type="hidden">
                            <input id="payment-credit-note-id" type="hidden" name="nota_credito_ids[]" disabled>
                        </div>
                        <div id="payment-credit-plan-options"
                            class="hidden md:col-span-2 rounded-lg border border-slate-200 p-3">
                            <h4 class="text-sm font-bold text-slate-800">Cuotas pendientes del crédito</h4>
                            <p class="mt-1 text-xs text-slate-600">Selecciona una o varias cuotas para pagar. El monto se actualizará automáticamente.</p>
                            <div id="payment-credit-plan-list" class="mt-2 grid grid-cols-1 md:grid-cols-2 gap-2"></div>
                        </div>
                        <div id="payment-currency-settings" class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-2">
                            <div class="space-y-2">
                                <div id="payment-currency-mode-field">
                                    <label class="block text-xs font-semibold text-slate-700 mb-2">Tipo de moneda</label>
                                    <div class="flex items-center gap-4 bg-white mb-1.5">
                                        <label
                                            class="inline-flex items-center gap-2 cursor-pointer text-xs font-medium text-slate-700">
                                            <input type="radio" name="payment_currency_mode" value="same" checked
                                                class="accent-[#b41B29]">
                                            Misma moneda
                                        </label>
                                        <label
                                            class="inline-flex items-center gap-2 cursor-pointer text-xs font-medium text-slate-700">
                                            <input type="radio" name="payment_currency_mode" value="other"
                                                class="accent-[#b41B29]">
                                            Otra moneda
                                        </label>
                                    </div>
                                </div>
                                <div id="payment-currency-extra" class="hidden">
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                                        <div>
                                            <label for="payment-currency"
                                                class="block text-xs font-semibold text-slate-700 mb-1">Tipo de
                                                moneda</label>
                                            <select id="payment-currency" name="currency_selector"
                                                class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                                                <option value="">Selecciona</option>
                                                @foreach($monedasOptions as $option)
                                                    <option value="{{ $option['value'] }}"
                                                        data-symbol="{{ $option['simbolo'] }}">
                                                        {{ $option['label'] }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label for="payment-exchange-rate"
                                                class="block text-xs font-semibold text-slate-700 mb-1">Tipo de
                                                cambio</label>
                                            <input id="payment-exchange-rate" name="tipo_cambio" type="number" min="0.0001"
                                                step="0.0001"
                                                class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                                        </div>
                                        <div>
                                            <label for="payment-converted-amount"
                                                class="block text-xs font-semibold text-slate-700 mb-1">Monto
                                                cancelado</label>
                                            <input id="payment-converted-amount" name="monto_cancelado" type="number"
                                                min="0.01" step="0.01"
                                                class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                                        </div>
                                    </div>
                                    <div class="mt-2">
                                        <label for="payment-converted-total"
                                            class="block text-xs font-semibold text-slate-700 mb-1">Total en la moneda
                                            seleccionada</label>
                                        <div
                                            class="flex items-center rounded-lg border border-slate-200 bg-slate-50 px-2 focus-within:border-red-600">
                                            <span id="payment-converted-total-symbol"
                                                class="mr-1.5 min-w-[1.5rem] shrink-0 text-xs font-bold text-slate-700">S/</span>
                                            <input id="payment-converted-total" name="precio_convertido" type="number"
                                                readonly
                                                class="w-full min-w-0 border-0 bg-transparent p-2 text-xs font-bold text-slate-900 outline-none focus:ring-0"
                                                style="border: 0;">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="space-y-2">
                                <div id="payment-mode-field">
                                    <span class="block text-xs font-semibold text-slate-700 mb-2">Tipo de pago</span>
                                    <div class="flex items-start gap-6 mb-1.5">
                                        <div>
                                            <label
                                                class="inline-flex items-center gap-2 cursor-pointer text-xs font-medium text-slate-700">
                                                <input type="radio" name="modo_pago" value="total"
                                                    class="accent-[#b41B29]">
                                                Pago total
                                            </label>
                                            <p id="payment-total-credit-summary"
                                                class="hidden mt-1 text-xs font-semibold text-danger"
                                                aria-live="polite"></p>
                                        </div>
                                        <label
                                            class="inline-flex items-center gap-2 cursor-pointer text-xs font-medium text-slate-700">
                                            <input type="radio" name="modo_pago" value="parcial" checked
                                                class="accent-[#b41B29]">
                                            Pago parcial
                                        </label>
                                    </div>
                                </div>
                                <div id="partial-payment-field" class="hidden">
                                    <label for="payment-amount"
                                        class="block text-xs font-semibold text-slate-700 mb-1">Monto actual pagado</label>
                                    <div
                                        class="flex items-center rounded-lg border border-slate-300 bg-white px-2 focus-within:border-red-600 focus-within:ring-2 focus-within:ring-red-600/20">
                                        <span id="payment-amount-currency"
                                            class="mr-1.5 shrink-0 text-xs font-bold text-slate-700">S/</span>
                                        <input id="payment-amount" name="montoPago" type="number" step="0.01"
                                            class="w-full min-w-0 border-0 bg-transparent p-2 text-xs text-slate-800 outline-none focus:ring-0"
                                            style="border: 0;">
                                    </div>
                                    <div class="flex items-center gap-2 mt-1">
                                        <p class="text-xs text-slate-800 mt-1">Saldo restante:
                                            <strong id="payment-balance">S/0.00</strong>
                                        </p>
                                        <p id="payment-credit-summary" class="hidden text-xs font-semibold text-danger mt-1"
                                            aria-live="polite"></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div id="payment-description-field">
                            <label for="payment-description"
                                class="block text-xs font-semibold text-slate-700 mb-1">Descripción / Observación (max. 100
                                caracteres)</label>
                            <textarea id="payment-description" name="descripcion" maxlength="100" rows="2"
                                placeholder="Observaciones opcionales del pago..."
                                class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20"
                                style="height: 120px;"></textarea>
                        </div>

                        <!-- COMPROBANTE DE PAGO (DROPZONE CON ESTILO UNIFICADO) -->
                        <div id="payment-proof-field">
                            <div class="mb-1.5 flex items-center justify-between gap-2">
                                <label id="payment-proof-label" class="text-xs font-bold text-slate-800">Comprobante de pago
                                    (opcional)</label>
                                <button type="button" id="payment-clear-comprobante" data-clear-payment-comprobante
                                    class="px-2 py-0.5 text-xs font-bold text-white rounded transition-all hover:opacity-90 shadow-sm"
                                    style="background-color: #b41B29;">
                                    Quitar pago
                                </button>
                            </div>
                            <label id="payment-dropzone" for="payment-comprobante"
                                class="erp-upload-box payment-upload-box">
                                <svg class="erp-upload-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                                    stroke-linejoin="round" aria-hidden="true">
                                    <path d="M16 16l-4-4-4 4"></path>
                                    <path d="M12 12v9"></path>
                                    <path d="M20.39 17.39A5 5 0 0 0 18 8h-1.26A8 8 0 1 0 3 16.3"></path>
                                </svg>
                                <span class="erp-upload-title">Cargar comprobante de pago</span>
                                <span class="erp-upload-help">Haz clic para buscar, arrastra o pega (Ctrl + V / Cmd +
                                    V)</span>
                                <span class="erp-upload-help">Archivos permitidos: JPG, PNG o PDF (máx. 5 MB)</span>
                                <span id="payment-comprobante-name" class="erp-upload-file-name" aria-live="polite"></span>
                                <input id="payment-comprobante" name="comprobante" type="file"
                                    accept="image/jpeg,image/png,application/pdf">
                            </label>
                            <div id="payment-existing-proof" class="hidden mt-2 text-xs"></div>
                        </div>
                    </div>
                    <div id="payment-withholding-receipt-view" class="hidden mt-3 border-t border-slate-100 pt-3">
                        <h4 id="payment-withholding-receipt-title" class="mb-2 text-sm font-bold text-slate-800">
                            Detalle de detracción:
                        </h4>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="flex flex-col gap-1 text-xs font-semibold text-slate-600">
                                <span id="payment-withholding-date-label">Fecha de pago de detracción</span>
                                <input id="payment-withholding-date" type="text" readonly
                                    class="w-full rounded-lg border border-slate-200 bg-slate-50 p-2 text-sm font-normal text-slate-700">
                            </label>
                            <label class="flex flex-col gap-1 text-xs font-semibold text-slate-600">
                                <span id="payment-withholding-amount-label">Monto de detracción</span>
                                <input id="payment-withholding-amount" type="text" readonly
                                    class="w-full rounded-lg border border-slate-200 bg-slate-50 p-2 text-sm font-normal text-slate-700">
                            </label>
                            <label class="flex flex-col gap-1 text-xs font-semibold text-slate-600">
                                Comentario
                                <input id="payment-withholding-comment" type="text" readonly
                                    class="w-full rounded-lg border border-slate-200 bg-slate-50 p-2 text-sm font-normal text-slate-700">
                            </label>
                            <div class="flex min-w-0 flex-col mt-3">
                                <button id="payment-withholding-receipt-link" type="button" data-open-document-preview
                                    data-missing-element="payment-withholding-receipt-missing"
                                    class="block w-full rounded-lg bg-primary px-4 py-2 text-center text-sm font-bold text-white hover:opacity-90">
                                    Ver recibo de detracción
                                </button>
                                <p id="payment-withholding-receipt-missing"
                                    class="hidden rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-center text-sm font-semibold text-slate-500">
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center justify-end gap-3 mt-3 border-t border-slate-100">
                        <button type="button"
                            class="px-4 py-2 text-sm font-semibold rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-100 transition-colors"
                            data-close-service-payment>Cancelar</button>
                        <button type="button" id="payment-view-edit-btn"
                            class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed bg-primary border-primary text-white dark:border-primary"
                            style="background-color: #b41B29;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                data-lucide="edit-2" class="lucide lucide-edit-2 mr-2 h-4 w-4 stroke-[1.3]">
                                <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"></path>
                            </svg>
                            Editar</button>
                        <button type="button" id="payment-view-cancel-edit-btn"
                            class="hidden px-4 py-2 text-sm font-semibold rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-100 transition-colors">
                            Descartar cambios
                        </button>
                        <button type="submit" id="payment-submit-btn"
                            class="px-5 py-2 text-sm font-bold text-white rounded-lg shadow-sm transition-all"
                            style="background-color: #b41B29;">Registrar cobro</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div id="partial-payment-modal" class="erp-modal-backdrop hidden" role="dialog" aria-modal="true"
        aria-labelledby="partial-payment-title">
        <div class="erp-modal-card" style="width: min(650px, calc(100vw - 32px)); max-width: 700px; border-radius: 16px;">
            <div class="erp-modal-header">
                <h3 id="partial-payment-title" class="font-bold text-slate-900 text-lg">Registrar pago pendiente</h3>
                <button type="button" class="erp-modal-close" data-close-partial-payment aria-label="Cerrar">×</button>
            </div>
            <form method="POST" action="{{ route('modules.cuentasporcobrar.cobrar-servicios') }}"
                id="partial-payment-form" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="idcuentasPorCobrar" id="partial-payment-cxc-id">
                <input type="hidden" name="moneda_idmoneda" id="partial-payment-moneda-id">
                <input type="hidden" name="completar_pago_parcial" value="1">
                <input type="hidden" name="modo_pago" value="parcial">
                <input type="hidden" name="payment_currency_mode" value="same">
                <select name="formaPago_idformaPago" class="hidden" tabindex="-1" aria-hidden="true">
                    <option value="" selected></option>
                    @foreach($formaPagoOptions as $option)
                        <option value="{{ $option['value'] }}"
                            @selected(str_contains(mb_strtolower($option['label'], 'UTF-8'), 'contado'))>
                            {{ $option['label'] }}
                        </option>
                    @endforeach
                </select>
                <select name="entidadBancaria_identidadBancaria" class="hidden" tabindex="-1" aria-hidden="true">
                    <option value="" selected></option>
                    @foreach($entidadBancariaOptions as $option)
                        <option value="{{ $option['value'] }}" @selected((string) $option['value'] === '1')>
                            {{ $option['label'] }}
                        </option>
                    @endforeach
                </select>
                <div id="partial-payment-selected-cxc"></div>
                <div id="partial-payment-selected-services"></div>
                <div class="erp-modal-body p-6">
                    <label class="block text-xs font-semibold text-slate-600 partial-payment-full">
                        Cliente
                        <input id="partial-payment-client" readonly
                            class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 p-2 text-sm font-medium text-slate-800">
                    </label>
                    <label for="partial-payment-period-end" class="block text-xs font-semibold text-slate-600">
                        Fecha fin del periodo
                        <input id="partial-payment-period-end" name="fechaFin" type="date" readonly
                            class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 p-2 text-sm text-slate-700">
                    </label>
                    <label for="partial-payment-date" class="block text-xs font-semibold text-slate-600">
                        Fecha de pago <span class="text-red-500">*</span>
                        <input id="partial-payment-date" name="fechaPago" type="date" required
                            value="{{ now()->format('Y-m-d') }}"
                            class="mt-1 w-full rounded-lg border border-slate-200 bg-white p-2 text-sm text-slate-700">
                    </label>
                    <label for="partial-payment-reference" class="block text-xs font-semibold text-slate-600">
                        Documento de referencia
                        <input id="partial-payment-reference" name="docReferencia" readonly
                            class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 p-2 text-sm font-bold text-slate-800">
                    </label>
                    <label for="partial-payment-amount" class="block text-xs font-semibold text-slate-600">
                        Monto pendiente a pagar
                        <div class="mt-1 flex items-center rounded-lg border border-slate-200 bg-slate-50 px-2">
                            <span id="partial-payment-currency" class="mr-1.5 shrink-0 text-sm font-bold text-slate-700">S/</span>
                            <input id="partial-payment-amount" name="montoPago" type="number" step="0.01" readonly
                                class="w-full border-0 bg-transparent p-2 text-sm font-bold text-slate-800 outline-none">
                        </div>
                    </label>
                    <label for="partial-payment-description" class="block text-xs font-semibold text-slate-700">
                        Descripción / Observación (máx. 100 caracteres)
                        <textarea id="partial-payment-description" name="descripcion" maxlength="100" rows="4"
                            placeholder="Observaciones opcionales del pago..."
                            class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20"></textarea>
                    </label>
                    <div class="block text-xs font-semibold text-slate-700">
                        <div class="mb-1.5 flex items-center justify-between gap-2">
                            <span>Comprobante de pago (opcional)</span>
                            <button type="button" id="partial-payment-clear-comprobante"
                                class="hidden rounded px-2 py-0.5 text-xs font-bold text-white transition-all hover:opacity-90 shadow-sm"
                                style="background-color: #b41B29;">
                                Quitar pago
                            </button>
                        </div>
                        <label id="partial-payment-dropzone" for="partial-payment-comprobante"
                            class="erp-upload-box payment-upload-box partial-payment-upload">
                            <svg class="erp-upload-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                                stroke-linejoin="round" aria-hidden="true">
                                <path d="M16 16l-4-4-4 4"></path>
                                <path d="M12 12v9"></path>
                                <path d="M20.39 17.39A5 5 0 0 0 18 8h-1.26A8 8 0 1 0 3 16.3"></path>
                            </svg>
                            <span class="erp-upload-title">Cargar comprobante de pago</span>
                            <span class="erp-upload-help">Haz clic para buscar el comprobante</span>
                            <span class="erp-upload-help">JPG, PNG o PDF (máx. 5 MB)</span>
                            <span id="partial-payment-comprobante-name" class="erp-upload-file-name" aria-live="polite"></span>
                            <input id="partial-payment-comprobante" name="comprobante" type="file"
                                accept="image/jpeg,image/png,application/pdf">
                        </label>
                    </div>
                    <div class="partial-payment-actions">
                        <button type="button" data-close-partial-payment
                            class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                            Cancelar
                        </button>
                        <button type="submit"
                            class="rounded-lg px-5 py-2 text-sm font-bold text-white shadow-sm"
                            style="background-color: #b41B29;">
                            Registrar pago
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div id="withholding-warning-modal" class="erp-modal-backdrop hidden" style="background-color: rgba(15, 23, 42, 0.58);"
        role="alertdialog" aria-modal="true" aria-labelledby="withholding-warning-title">
        <div class="erp-modal-card">
            <div class="erp-modal-header">
                <h3 id="withholding-warning-title">Confirmación de detracción</h3>
                <button type="button" data-close-withholding-warning class="erp-modal-close" aria-label="Cerrar">×</button>
            </div>
            <div class="erp-modal-body">
                <p id="withholding-warning-message" class="text-base leading-6 text-slate-700"></p>
                <p id="withholding-warning-payment" class="mt-3 text-sm leading-5 font-semibold text-slate-600"></p>
                <div class="mt-5 flex flex-wrap justify-end gap-2">
                    <button type="button" data-close-withholding-warning
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Cancelar
                    </button>
                    <button type="button" data-withholding-answer="no"
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        No
                    </button>
                    <button type="button" data-withholding-answer="yes"
                        class="rounded-lg bg-danger px-4 py-2 text-sm font-bold text-white hover:opacity-90">
                        Sí
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="quotation-withholding-proof-modal" class="erp-modal-backdrop hidden"
        style="background-color: rgba(15, 23, 42, 0.68);" role="dialog" aria-modal="true"
        aria-labelledby="quotation-withholding-proof-title">
        <div class="erp-modal-card" style="max-width: 560px; width: 100%;">
            <div class="erp-modal-header">
                <h3 id="quotation-withholding-proof-title">Cargar comprobante de detracción/retención</h3>
                <button type="button" class="erp-modal-close" data-cancel-quotation-withholding
                    aria-label="Volver a la confirmación">×</button>
            </div>
            <div class="erp-modal-body">
                <p id="quotation-withholding-type" class="mb-3 text-sm font-semibold text-slate-700">Comprobante de
                    detracción</p>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-2">
                    <label for="quotation-withholding-date" class="block text-xs font-semibold text-slate-600">
                        <span id="quotation-withholding-date-label">Fecha de pago de detracción</span>
                        <input id="quotation-withholding-date" name="fecha_detraccion" type="date" form="erp-gestion-form"
                            value="{{ now()->format('Y-m-d') }}"
                            class="mt-1 w-full rounded-lg border border-slate-200 bg-white p-2 text-sm text-slate-700 outline-none">
                    </label>
                    <div class="block text-xs font-semibold text-slate-600">
                        <span id="quotation-withholding-amount-label">Monto detracción</span>
                        <div id="quotation-withholding-amount"
                            class="mt-1 rounded-lg border border-slate-200 bg-slate-50 p-2 text-sm font-bold text-slate-800">
                            S/ 0.00</div>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <span class="text-xs font-semibold text-slate-700">Comprobante del depósito (obligatorio)</span>
                        <button type="button" id="quotation-withholding-proof-clear"
                            class="hidden rounded px-2 py-0.5 text-xs font-bold text-white hover:opacity-90"
                            style="background-color: #b41B29;">
                            Quitar recibo
                        </button>
                    </div>
                    <label id="quotation-withholding-proof-box" class="erp-upload-box" for="quotation-withholding-proof"
                        style="border: 2px dashed #fca5a5; background-color: #fef2f2; border-radius: 0.625rem; padding: 1.25rem 1rem; text-align: center; cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.1rem; transition: all 0.2s ease;">
                        <svg class="erp-upload-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                            aria-hidden="true">
                            <path d="M16 16l-4-4-4 4"></path>
                            <path d="M12 12v9"></path>
                            <path d="M20.39 17.39A5 5 0 0 0 18 8h-1.26A8 8 0 1 0 3 16.3"></path>
                        </svg>
                        <span class="erp-upload-title" data-quotation-withholding-upload-title>Cargar el archivo de
                            detracción</span>
                        <span class="erp-upload-help">Haz clic para buscar, arrastra o pega (Ctrl + V / Cmd + V)</span>
                        <span class="erp-upload-help">Archivos permitidos: JPG, PNG o PDF (máx. 5 MB)</span>
                        <span id="quotation-withholding-proof-name" class="erp-upload-file-name" aria-live="polite"></span>
                        <input id="quotation-withholding-proof" name="comprobante_detraccion" type="file"
                            form="erp-gestion-form" accept="image/jpeg,image/png,application/pdf">
                    </label>
                </div>
                <label for="quotation-withholding-description" class="mt-4 block text-xs font-semibold text-slate-600">
                    Descripción / Observación (opcional, máximo 100 caracteres)
                    <textarea id="quotation-withholding-description" name="descripcion_detraccion" form="erp-gestion-form"
                        maxlength="100" rows="3" placeholder="Describe sobre el depósito de detracción/retención..."
                        class="mt-1 w-full rounded-lg border border-slate-200 p-2 text-sm text-slate-700 outline-none"></textarea>
                </label>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" data-cancel-quotation-withholding
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Cancelar
                    </button>
                    <button type="button" id="quotation-withholding-proof-save"
                        class="rounded-lg bg-danger px-4 py-2 text-sm font-bold text-white hover:opacity-90">
                        Guardar Pago
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Selección de Períodos Vencidos -->
    <div id="modal-periodos-vencidos" class="erp-modal-backdrop hidden items-center justify-center p-4 z-[9999]"
        style="background-color: rgba(0, 0, 0, 0.6);" role="dialog" aria-modal="true">
        <div class="erp-modal w-full bg-white rounded-xl shadow-2xl overflow-hidden border border-slate-200"
            style="width: min(640px, calc(100vw - 32px)); max-width: 640px; margin: 0 auto;">
            <div
                class="erp-modal-header border-b border-slate-200 px-5 py-3.5 flex justify-between items-center bg-slate-50">
                <h3 class="font-bold text-slate-800 text-base">Deudas de periodos anteriores</h3>
                <button type="button" id="btn-close-periodos-modal"
                    class="text-slate-400 hover:text-slate-600 font-bold text-xl leading-none">&times;</button>
            </div>
            <div class="erp-modal-body p-5">
                <p class="text-xs text-slate-600 mb-3 font-medium">Selecciona los períodos anteriores del cliente que deseas
                    incluir en este cobro:</p>
                <div id="periodos-vencidos-list"
                    class="space-y-2 max-h-60 overflow-y-auto border border-slate-200 rounded-lg p-2.5 bg-slate-50">
                    <!-- Populated dynamically -->
                </div>
                <div class="flex items-center justify-between mt-4 pt-3 border-t border-slate-100 gap-2">
                    <span class="text-xs font-semibold text-slate-700">Adicional seleccionado: <strong
                            id="periodos-total-display" class="text-slate-900 font-bold">S/ 0.00</strong></span>
                    <div class="flex gap-2">
                        <button type="button" id="btn-cancel-periodos-modal"
                            class="px-3.5 py-1.5 text-xs font-semibold rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-100 transition-colors">
                            Cancelar
                        </button>
                        <button type="button" id="btn-confirm-periodos"
                            class="px-4 py-1.5 text-xs font-bold text-white rounded-lg shadow-sm transition-all hover:opacity-90"
                            style="background-color: #b41B29;">
                            Confirmar Selección
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="credit-note-selector-modal" class="erp-modal-backdrop hidden items-center justify-center p-4 z-[10000]"
        style="background-color: rgba(0, 0, 0, 0.6);" role="dialog" aria-modal="true"
        aria-labelledby="credit-note-selector-title">
        <div class="erp-modal w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl"
            style="width: min(560px, calc(100vw - 32px)); max-width: 560px; margin: 0 auto;">
            <div
                class="erp-modal-header flex items-center justify-between border-b border-slate-200 bg-slate-50 px-5 py-3.5">
                <h3 id="credit-note-selector-title" class="text-base font-bold text-slate-800">Seleccionar nota de crédito
                </h3>
                <button type="button" id="credit-note-selector-close"
                    class="text-xl font-bold leading-none text-slate-400 hover:text-slate-600"
                    aria-label="Cerrar">&times;</button>
            </div>
            <div class="erp-modal-body p-5">
                <p class="mb-3 text-xs font-medium text-slate-600">
                    Selecciona una nota disponible. Su saldo se descontará del total del cobro.
                </p>
                <div id="credit-note-selector-list" class="max-h-72 space-y-2 overflow-y-auto"></div>
                <div class="mt-4 flex justify-end gap-2 border-t border-slate-100 pt-3">
                    <button type="button" id="credit-note-selector-clear" disabled
                        class="rounded-lg border border-red-500 px-3.5 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50">
                        Quitar N. crédito
                    </button>
                    <button type="button" id="credit-note-selector-cancel"
                        class="rounded-lg border border-slate-300 px-3.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                        Cancelar
                    </button>
                    <button type="button" id="credit-note-selector-confirm"
                        class="rounded-lg px-4 py-1.5 text-xs font-bold text-white hover:opacity-90"
                        style="background-color: #b41B29;">
                        Aplicar selección
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="saldo-favor-selector-modal" class="erp-modal-backdrop hidden items-center justify-center p-4 z-[10000]"
        style="background-color: rgba(0, 0, 0, 0.6);" role="dialog" aria-modal="true"
        aria-labelledby="saldo-favor-selector-title">
        <div class="erp-modal w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl"
            style="width: min(440px, calc(100vw - 32px)); max-width: 440px; margin: 0 auto;">
            <div
                class="erp-modal-header flex items-center justify-between border-b border-slate-200 bg-slate-50 px-5 py-3.5">
                <h3 id="saldo-favor-selector-title" class="text-base font-bold text-slate-800">Aplicar saldo a favor</h3>
                <button type="button" id="saldo-favor-selector-close"
                    class="text-xl font-bold leading-none text-slate-400 hover:text-slate-600"
                    aria-label="Cerrar">&times;</button>
            </div>
            <div class="erp-modal-body p-5">
                <p id="saldo-favor-selector-available" class="mb-3 text-xs font-semibold text-emerald-700"></p>
                <p id="saldo-favor-selector-empty"
                    class="hidden rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600" role="status"
                    aria-live="polite"></p>
                <div id="saldo-favor-selector-amount-field">
                    <label for="saldo-favor-selector-amount" class="block text-xs font-semibold text-slate-700">
                        Monto de saldo a favor que deseas aplicar
                        <input id="saldo-favor-selector-amount" type="number" min="0" step="0.01" value="0"
                            class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-sm text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                    </label>
                </div>
                <div class="mt-4 flex justify-end gap-2 border-t border-slate-100 pt-3">
                    <button type="button" id="saldo-favor-selector-clear" disabled
                        class="rounded-lg border border-red-500 px-3.5 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50">
                        Quitar saldo a favor
                    </button>
                    <button type="button" id="saldo-favor-selector-cancel"
                        class="rounded-lg border border-slate-300 px-3.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                        Cancelar
                    </button>
                    <button type="button" id="saldo-favor-selector-apply"
                        class="rounded-lg px-4 py-1.5 text-xs font-bold text-white hover:opacity-90"
                        style="background-color: #b41B29;">
                        Aplicar monto
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="quotation-receipts-modal" class="erp-modal-backdrop hidden" role="dialog" aria-modal="true"
        aria-labelledby="quotation-receipts-title">
        <div class="erp-modal-card" style="width: min(500px, calc(100vw - 32px));">
            <div class="erp-modal-header">
                <h3 id="quotation-receipts-title">Recibos de la cotización</h3>
                <button type="button" class="erp-modal-close" data-close-quotation-receipts
                    aria-label="Cerrar recibos">×</button>
            </div>
            <div class="erp-modal-body">
                <div class="flex flex-col gap-3">
                    <h3>Detalles del recibo:</h3>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-2">
                        <label class="flex flex-col gap-1 text-sm font-semibold text-slate-700">
                            Factura
                            <input id="quotation-receipt-invoice-number" type="text" readonly
                                class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-normal text-slate-700">
                        </label>
                        <label class="flex flex-col gap-1 text-sm font-semibold text-slate-700">
                            Fecha de pago
                            <input id="quotation-receipt-payment-date" type="text" readonly
                                class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-normal text-slate-700">
                        </label>
                        <label class="flex flex-col gap-1 text-sm font-semibold text-slate-700">
                            Monto cancelado
                            <input id="quotation-receipt-payment-amount" type="text" readonly
                                class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-normal text-slate-700">
                        </label>
                        <label class="flex flex-col gap-1 text-sm font-semibold text-slate-700 sm:col-span-3">
                            Comentario
                            <input id="quotation-receipt-payment-comment" type="text" readonly
                                maxlength="100"
                                class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-normal text-slate-700">
                        </label>
                    </div>
                    <button id="quotation-payment-receipt-link" type="button" data-open-document-preview
                        data-missing-element="quotation-payment-receipt-missing" data-missing-message="Sin recibo de pago"
                        class="block w-full rounded-lg bg-primary px-4 py-3 text-center text-sm font-bold text-white hover:opacity-90"
                        style="box-sizing: border-box; min-height: 48px;">
                        Ver recibo de pago
                    </button>
                    <p id="quotation-payment-receipt-missing"
                        class="hidden rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-center text-sm font-semibold text-slate-500">
                    </p>
                    <div id="quotation-receipt-edit-fields" class="hidden rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <span class="text-xs font-semibold text-slate-700">Comprobante de pago (opcional)</span>
                            <button type="button" id="quotation-receipt-proof-clear"
                                class="hidden rounded px-2 py-0.5 text-xs font-bold text-white hover:opacity-90"
                                style="background-color: #b41B29;">
                                Quitar recibo
                            </button>
                        </div>
                        <label id="quotation-receipt-proof-box" class="erp-upload-box" for="quotation-receipt-proof"
                            style="border: 2px dashed #fca5a5; background-color: #fef2f2; border-radius: 0.625rem; padding: 1rem; text-align: center; cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.1rem; transition: all 0.2s ease;">
                            <svg class="erp-upload-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                                aria-hidden="true">
                                <path d="M16 16l-4-4-4 4"></path>
                                <path d="M12 12v9"></path>
                                <path d="M20.39 17.39A5 5 0 0 0 18 8h-1.26A8 8 0 1 0 3 16.3"></path>
                            </svg>
                            <span class="erp-upload-title">Cargar comprobante de pago</span>
                            <span class="erp-upload-help">Haz clic para buscar, arrastra o pega (Ctrl + V / Cmd + V)</span>
                            <span class="erp-upload-help">Archivos permitidos: JPG, PNG o PDF (máx. 5 MB)</span>
                            <span id="quotation-receipt-proof-name" class="erp-upload-file-name" aria-live="polite"></span>
                            <input id="quotation-receipt-proof" type="file" accept="image/jpeg,image/png,application/pdf">
                        </label>
                        <p id="quotation-receipt-edit-error" class="hidden mt-2 text-sm font-semibold text-danger"
                            role="alert"></p>
                    </div>
                    <div id="quotation-withholding-receipt-section" class="hidden">
                        <h3 id="quotation-receipt-withholding-title" class="mb-3">Detalle de detracción:</h3>
                        <div class="mb-3 grid grid-cols-2 gap-3 sm:grid-cols-2">
                            <label class="flex flex-col gap-1 text-sm font-semibold text-slate-700">
                                <span id="quotation-receipt-withholding-label">Fecha de pago de detracción</span>
                                <input id="quotation-receipt-withholding-date" type="text" readonly
                                    class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-normal text-slate-700">
                            </label>
                            <label class="flex flex-col gap-1 text-sm font-semibold text-slate-700">
                                <span id="quotation-receipt-withholding-amount-label">Monto de detracción</span>
                                <input id="quotation-receipt-withholding-amount" type="text" readonly
                                    class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-normal text-slate-700">
                            </label>
                        </div>
                        <button id="quotation-withholding-receipt-link" type="button" data-open-document-preview
                            data-missing-element="quotation-withholding-receipt-missing"
                            class="block w-full rounded-lg bg-primary px-4 py-3 text-center text-sm font-bold text-white hover:opacity-90"
                            style="box-sizing: border-box; min-height: 48px;">
                            Ver recibo de detracción
                        </button>
                        <p id="quotation-withholding-receipt-missing"
                            class="hidden rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-center text-sm font-semibold text-slate-500">
                        </p>
                    </div>
                    @if($canEditCxc)
                        <div class="flex justify-end border-t border-slate-100 pt-3 gap-3">
                            <button id="quotation-receipt-edit-payment" type="button"
                                class="rounded-lg px-4 py-2 text-sm font-bold text-white hover:opacity-90"
                                style="background-color: #b41B29;">
                                <span class="inline-flex items-center gap-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round" aria-hidden="true">
                                        <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"></path>
                                    </svg>
                                    Editar
                                </span>
                            </button>
                            <button id="quotation-receipt-cancel-edit" type="button"
                                class="hidden rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                Cancelar
                            </button>
                            <button id="quotation-receipt-save-edit" type="button"
                                class="hidden rounded-lg px-4 py-2 text-sm font-bold text-white hover:opacity-90"
                                style="background-color: #b41B29;">
                                Guardar cambios
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div id="cxc-document-preview-modal" class="erp-modal-backdrop hidden items-center justify-center p-4"
        style="z-index:2147483647 !important;background:rgba(0, 0, 0, 0.33);" role="dialog" aria-modal="true"
        aria-labelledby="cxc-document-preview-title">
        <div class="erp-modal-card flex flex-col overflow-hidden"
            style="width:min(900px, calc(100vw - 32px));height:min(700px, calc(100vh - 32px));">
            <div class="erp-modal-header shrink-0">
                <h3 id="cxc-document-preview-title">Vista previa del comprobante</h3>
                <button type="button" class="erp-modal-close" data-close-document-preview
                    aria-label="Cerrar vista previa">×</button>
            </div>
            <div class="flex min-h-0 flex-1 items-center justify-center overflow-hidden bg-slate-100">
                <img id="cxc-document-preview-image" alt="Comprobante de pago" class="hidden h-full w-full object-contain">
                <iframe id="cxc-document-preview-frame" title="Vista previa del comprobante"
                    class="min-h-0 w-full flex-1 bg-slate-100" src="about:blank"></iframe>
            </div>
        </div>
    </div>

    <div id="erp-cotizacion-preview-modal" class="erp-modal-backdrop" role="dialog" aria-modal="true"
        aria-labelledby="erp-cotizacion-preview-title">
        <div class="flex h-[86vh] w-full max-w-4xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl"
            style="max-width: 1100px; width: 100%; height: calc(100vh - 3rem); min-height: calc(90vh - 3rem); max-height: calc(95vh - 3rem);">
            <div class="erp-modal-header">
                <h3 id="erp-cotizacion-preview-title">Ver cotización</h3>
                <button type="button" class="erp-modal-close" data-erp-close-preview
                    aria-label="Cerrar vista previa">×</button>
            </div>
            <div class="relative min-h-0 flex-1 bg-slate-800">
                <div
                    style="display: flex; align-items: center; justify-content: space-between; padding: 5px 18px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                    <span id="erp-cotizacion-preview-number"
                        style="font-size: 0.85rem; color: #475569; font-weight: 600;"></span>
                </div>
                <div id="preview-loading"
                    class="absolute inset-0 z-10 hidden flex-col items-center justify-center bg-white/80">
                    <svg class="mb-3 h-10 w-10 animate-spin text-primary" xmlns="http://www.w3.org/2000/svg" fill="none"
                        viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                        </path>
                    </svg>
                    <span class="text-sm font-medium text-slate-600">Cargando PDF de cotización...</span>
                </div>
                <iframe id="erp-cotizacion-preview-frame" title="Vista previa de la cotización"
                    class="h-full w-full border-0"></iframe>
            </div>
        </div>
    </div>

    <div id="erp-gestion-modal" class="erp-modal-backdrop" role="dialog" aria-modal="true"
        aria-labelledby="erp-gestion-title">
        <div class="erp-modal-card" style="max-width: 560px; width: 100%;">
            <div class="erp-modal-header" style="border-bottom: 1px solid #e2e8f0; padding: 1rem 1.25rem;">
                <h3 id="erp-gestion-title" style="font-size: 1.125rem; font-weight: 700; color: #0f172a; margin: 0;">
                    Registrar pago de cotización</h3>
                <button type="button" class="erp-modal-close" data-erp-close-gestion aria-label="Cerrar gestión"
                    style="font-size: 1.25rem; color: #64748b; background: none; border: none; cursor: pointer;">✕</button>
            </div>
            <form id="erp-gestion-form" method="POST" action="" enctype="multipart/form-data">
                @csrf
                <input type="hidden" id="erp-gestion-currency-id" name="moneda_idmoneda">
                <input type="hidden" id="erp-gestion-currency-mode" name="payment_currency_mode" value="same">
                <input type="hidden" id="erp-gestion-pay-mode" name="modo_pago" value="total">
                <input type="hidden" id="erp-gestion-complete-withholding" name="completar_deduccion_pendiente" value="0">
                <input type="hidden" id="erp-gestion-withholding-decision" name="withholding_decision" value="">
                <input type="hidden" id="gestion-credit-note-id" name="nota_credito_ids[]" disabled>
                <div class="erp-modal-body" style="padding: 1.25rem; display: flex; flex-direction: column; gap: 5px;">
                    <div>
                        <span id="erp-gestion-section-title"
                            style="font-size: 0.75rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 0.5rem;">DATOS
                            DE COTIZACIÓN</span>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2" style="margin-bottom: 0.30rem;">
                            <label style="font-size: 0.8125rem; font-weight: 600; color: #334155;">Cliente
                                <input type="text" id="erp-gestion-client" readonly
                                    style="display: block; width: 100%; margin-top: 0.25rem; padding: 0.625rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; background-color: #f8fafc; font-size: 0.875rem; font-weight: 600; color: #1e293b;"
                                    value="-">
                            </label>
                            <label id="erp-gestion-reference-field"
                                style="font-size: 0.8125rem; font-weight: 600; color: #334155;"><span
                                    id="erp-gestion-reference-label">Factura *</span>
                                <input type="text" id="erp-gestion-reference" name="docReferencia" minlength="2"
                                    maxlength="15" required
                                    style="display: block; width: 100%; margin-top: 0.25rem; padding: 0.625rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; background-color: #fff; font-size: 0.875rem; color: #1e293b;">
                            </label>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mb-1.5">
                        <div id="erp-gestion-format-field">
                            <label for="erp-gestion-payment-format"
                                class="block text-xs font-semibold text-slate-700 mb-1">Formato de pago</label>
                            <select id="erp-gestion-payment-format" name="formaPago_idformaPago" required
                                class="w-full rounded-lg border border-slate-300 p-2 text-sm text-slate-800">
                                <option value="">Selecciona</option>
                                @foreach($formaPagoOptions as $option)
                                    <option value="{{ $option['value'] }}" data-credit-days="{{ $option['credit_days'] ?? 0 }}">
                                        {{ $option['label'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div id="erp-gestion-bank-field">
                            <label for="erp-gestion-bank" class="block text-xs font-semibold text-slate-700 mb-1">Entidad
                                bancaria cuenta</label>
                            <select id="erp-gestion-bank" name="entidadBancaria_identidadBancaria" required
                                class="w-full rounded-lg border border-slate-300 p-2 text-sm text-slate-800">
                                <option value="">Selecciona</option>
                                @foreach($entidadBancariaOptions as $option)
                                    <option value="{{ $option['value'] }}"
                                        data-default="{{ $option['is_bcp'] ? 'true' : 'false' }}" @selected((string) $option['value'] === (string) $defaultBankId)>
                                        {{ $option['label'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div id="erp-gestion-date-field">
                            <label for="erp-gestion-date" class="block text-xs font-semibold text-slate-700 mb-1">Fecha de
                                pago</label>
                            <input type="date" id="erp-gestion-date" name="fecha_pago" required value="{{ date('Y-m-d') }}"
                                class="w-full rounded-lg border border-slate-300 p-2 text-sm text-slate-800">
                        </div>
                        <div id="erp-gestion-cancel-amount-field" class="md:col-span-3">
                            <div class="mb-1"
                                style="display: flex; justify-content: space-between; align-items: center; gap: 0.5rem;">
                                <label for="erp-gestion-amount" class="block text-xs font-semibold text-slate-700">Monto a
                                    cancelar</label>
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    <label id="erp-gestion-retention-field"
                                        class="hidden inline-flex items-center gap-1 text-xs font-semibold text-slate-700">
                                        <input type="checkbox" id="erp-gestion-apply-retention" name="aplicar_retencion"
                                            value="1"
                                            class="transition-all duration-100 ease-in-out shadow-sm border-slate-500 cursor-pointer rounded focus:ring-4 focus:ring-offset-0 focus:ring-primary focus:ring-opacity-20 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&[type='radio']]:checked:bg-primary [&[type='radio']]:checked:border-primary [&[type='radio']]:checked:border-opacity-10 [&[type='checkbox']]:checked:bg-primary [&[type='checkbox']]:checked:border-primary [&[type='checkbox']]:checked:border-opacity-10 [&:disabled:not(:checked)]:bg-slate-100 [&:disabled:not(:checked)]:cursor-not-allowed [&:disabled:not(:checked)]:dark:bg-darkmode-800/50 [&:disabled:checked]:opacity-70 [&:disabled:checked]:cursor-not-allowed [&:disabled:checked]:dark:bg-darkmode-800/50">
                                        Aplicar retención (3%)
                                    </label>
                                    <button type="button" id="gestion-select-credit-amount"
                                        class="shrink-0 rounded px-2 py-0.5 text-xs font-bold text-white shadow-sm transition-all hover:opacity-90"
                                        style="background-color: #334155;">
                                        Saldo a favor
                                    </button>
                                    <button type="button" id="gestion-select-credit-note"
                                        class="shrink-0 rounded px-2 py-0.5 text-xs font-bold text-white shadow-sm transition-all hover:opacity-90"
                                        style="background-color: #b41b29;">
                                        N. crédito
                                    </button>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <div
                                    class="flex min-w-0 flex-1 items-center overflow-hidden rounded-lg border border-slate-300 bg-white">
                                    <span id="erp-gestion-amount-currency" class="px-2 text-sm font-semibold text-slate-600"
                                        aria-hidden="true"></span>
                                    <input type="number" id="erp-gestion-amount" name="montoPago" min="0" step="0.01"
                                        value="0"
                                        class="min-w-0 w-full border-0 p-2 text-sm font-bold text-slate-900 focus:ring-0">
                                </div>
                                <button type="button" id="erp-gestion-currency-toggle"
                                    class="shrink-0 rounded-lg border border-slate-300 px-3 text-xs font-semibold text-slate-700">Otra
                                    moneda</button>
                            </div>
                            <p id="erp-gestion-withholding-summary" class="mt-1 hidden rounded-lg text-xs text-danger"
                                aria-live="polite"></p>
                            <p id="erp-gestion-balance-summary" class="mt-1 text-xs font-semibold text-slate-600"
                                aria-live="polite"></p>
                        </div>
                        <input type="checkbox" id="gestion-use-credit" name="usar_saldo_favor" value="1" class="hidden">
                        <input type="hidden" id="gestion-credit-amount" name="monto_saldo_favor" value="">
                        <input type="hidden" id="gestion-cxc-id" value="">
                        <div id="erp-gestion-payment-amount-field" class="hidden">
                            <label id="erp-gestion-payment-amount-label" for="erp-gestion-payment-amount"
                                class="block text-xs font-semibold text-slate-700 mb-1">Monto a cancelar</label>
                            <div class="flex items-center overflow-hidden rounded-lg border border-slate-300 bg-white">
                                <span id="erp-gestion-payment-amount-currency"
                                    class="px-2 text-sm font-semibold text-slate-600" aria-hidden="true"></span>
                                <input id="erp-gestion-payment-amount" type="number" min="0" step="0.01"
                                    class="min-w-0 w-full border-0 p-2 text-sm font-bold text-slate-800 focus:ring-0">
                            </div>
                        </div>
                        <div id="erp-gestion-completion-amount-field" class="hidden">
                            <label id="erp-gestion-completion-amount-label" for="erp-gestion-completion-amount"
                                class="block text-xs font-semibold text-slate-700 mb-1">Monto detracción</label>
                            <div class="flex items-center overflow-hidden rounded-lg border border-slate-300 bg-slate-50">
                                <span id="erp-gestion-completion-amount-currency"
                                    class="px-2 text-sm font-semibold text-slate-600" aria-hidden="true"></span>
                                <input id="erp-gestion-completion-amount" type="text" readonly
                                    class="min-w-0 w-full border-0 bg-transparent p-2 text-sm font-bold text-slate-900 focus:ring-0"
                                    value="0.00">
                            </div>
                        </div>
                    </div>

                    <div id="erp-gestion-currency-extra" class="hidden rounded-lg bg-slate-200 p-2 mb-1.5">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                            <label for="erp-gestion-currency" class="block text-xs font-semibold text-slate-700">Tipo de
                                moneda
                                <select id="erp-gestion-currency" name="currency_selector"
                                    class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-sm">
                                    <option value="">Selecciona</option>
                                    @foreach($monedasOptions as $option)
                                        <option value="{{ $option['value'] }}" data-symbol="{{ $option['simbolo'] }}">
                                            {{ $option['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>
                            <label for="erp-gestion-exchange-rate" class="block text-xs font-semibold text-slate-700">Tipo
                                de cambio
                                <input id="erp-gestion-exchange-rate" name="tipo_cambio" type="number" step="0.0001"
                                    min="0.0001" class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-sm">
                            </label>
                            <label for="erp-gestion-foreign-amount" class="block text-xs font-semibold text-slate-700">Monto
                                cancelado
                                <span
                                    class="mt-1 flex items-center overflow-hidden rounded-lg border border-slate-300 bg-white">
                                    <span id="erp-gestion-foreign-amount-currency"
                                        class="px-2 text-sm font-semibold text-slate-600" aria-hidden="true"></span>
                                    <input id="erp-gestion-foreign-amount" name="monto_cancelado" type="number" step="0.01"
                                        min="0" class="min-w-0 w-full border-0 p-2 text-sm focus:ring-0">
                                </span>
                            </label>
                        </div>
                    </div>
                    <div id="erp-gestion-converted-total-field" class="hidden mb-1.5">
                        <label for="erp-gestion-converted-amount" class="block text-xs font-semibold text-slate-700 mb-1">
                            Total de la cotización en la moneda seleccionada
                        </label>
                        <div class="flex items-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
                            <span id="erp-gestion-converted-amount-currency"
                                class="px-2 text-sm font-semibold text-slate-600" aria-hidden="true"></span>
                            <input id="erp-gestion-converted-amount" type="text" readonly
                                class="min-w-0 w-full border-0 bg-transparent p-2 text-sm font-bold text-slate-900 focus:ring-0">
                        </div>
                    </div>
                    <div id="erp-gestion-credit-fields" class="hidden grid grid-cols-2 sm:grid-cols-2 gap-2">
                        <label for="erp-gestion-installment-count"
                            class="block text-xs font-semibold text-slate-700">Cantidad de cuotas
                            <input id="erp-gestion-installment-count" name="canCuotas" type="number" min="1" max="60"
                                step="1" value=""
                                class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-sm text-slate-800">
                        </label>
                        <label for="erp-gestion-installment-interval"
                            class="block text-xs font-semibold text-slate-700">Intervalo de días
                            <select id="erp-gestion-installment-interval"
                                class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-sm text-slate-800">
                                <option value="">Selecciona</option>
                                <option value="1">Cada 1 día</option>
                                <option value="7">Cada 7 días</option>
                                <option value="15">Cada 15 días</option>
                                <option value="30">Cada 30 días</option>
                                <option value="45">Cada 45 días</option>
                                <option value="60">Cada 60 días</option>
                                <option value="90">Cada 90 días</option>
                            </select>
                        </label>
                    </div>
                    <div id="erp-gestion-credit-installments" class="hidden rounded-lg border border-slate-200 p-2">
                        <div id="erp-gestion-installments" class="space-y-2"></div>
                    </div>
                    <p id="erp-gestion-paid-installment-summary"
                        class="hidden rounded-lg bg-emerald-50 p-2 text-xs font-semibold text-white"></p>
                    <p id="erp-gestion-next-installment-summary" class="hidden rounded-lg p-2 text-xs font-semibold"
                        style="background: #fef3c7; color: #92400e;"></p>

                    <div id="erp-gestion-file-field">
                        <div class="mb-1.5 flex items-center justify-between gap-2">
                            <label id="erp-gestion-file-label"
                                style="font-size: 0.8125rem; font-weight: 600; color: #334155; display: block;">Archivo
                                de Pago (opcional)</label>
                            <button type="button" id="erp-gestion-file-clear"
                                class="hidden rounded px-2 py-0.5 text-xs font-bold text-white hover:opacity-90"
                                style="background-color: #b41B29;">
                                Quitar pago
                            </button>
                        </div>
                        <label class="erp-upload-box" id="erp-gestion-upload-box" for="erp-gestion-file" tabindex="0"
                            style="border: 2px dashed #fca5a5; background-color: #fef2f2; border-radius: 0.625rem; padding: 1.25rem 1rem; text-align: center; cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.375rem; transition: all 0.2s ease;">
                            <svg class="erp-upload-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="#475569" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                                style="width: 2rem; height: 2rem; margin-bottom: 0.25rem;">
                                <path d="M16 16l-4-4-4 4"></path>
                                <path d="M12 12v9"></path>
                                <path d="M20.39 17.39A5 5 0 0 0 18 8h-1.26A8 8 0 1 0 3 16.3"></path>
                            </svg>
                            <span class="erp-upload-title"
                                style="font-size: 0.875rem; font-weight: 700; color: #1e293b;">Cargar el archivo de
                                Pago</span>
                            <span class="erp-upload-help" style="font-size: 0.75rem; color: #64748b;">Haz clic para buscar,
                                arrastra o pega (Ctrl + V / Cmd + V)</span>
                            <span class="erp-upload-help" style="font-size: 0.75rem; color: #94a3b8;">Archivos permitidos:
                                JPG, PNG o PDF (máx. 5 MB)</span>
                            <span id="erp-gestion-file-name" class="erp-upload-file-name" aria-live="polite"
                                style="font-size: 0.8125rem; font-weight: 600; color: #b41b29;"></span>
                            <input id="erp-gestion-file" name="archivoPago" type="file"
                                accept="image/jpeg,image/png,application/pdf" style="display: none;">
                            <img id="erp-gestion-preview" class="erp-upload-preview" alt="Previsualización del comprobante"
                                style="max-height: 100px; display: none; margin-top: 0.5rem; border-radius: 0.375rem;" />
                        </label>
                    </div>

                    <div id="erp-gestion-description-field">
                        <label for="erp-gestion-description"
                            style="font-size: 0.8125rem; font-weight: 600; color: #334155; display: block; margin-bottom: 0.25rem;">Descripción
                            / Observación (opcional, máximo 100 caracteres)</label>
                        <textarea id="erp-gestion-description" name="descripcion" maxlength="100" rows="2"
                            placeholder="Describe sobre el pago..."
                            style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; font-size: 0.90rem; color: #1e293b; outline: none; resize: none; min-height: 60px;"></textarea>
                    </div>

                </div>
            </form>
            <div class="erp-modal-actions"
                style="padding: 0.875rem 1.25rem; border-top: 1px solid #edf2f7; background: #ffffff; display: flex; align-items: center; justify-content: flex-end; gap: 0.75rem; flex-shrink: 0;">
                <span id="erp-gestion-saving-status" class="erp-gestion-saving-status" role="status" aria-live="polite">
                    <span class="erp-gestion-spinner" aria-hidden="true"></span>
                    Guardando datos...
                </span>
                <button type="button" class="erp-btn-secondary" data-erp-close-gestion
                    style="padding: 0.5rem 1.25rem; border-radius: 0.5rem; border: 1px solid #cbd5e1; background: #ffffff; color: #334155; font-size: 0.875rem; font-weight: 600; cursor: pointer;">Cancelar</button>
                <button type="submit" form="erp-gestion-form" id="erp-gestion-save" class="erp-btn-primary erp-gestion-save"
                    style="padding: 0.5rem 1.5rem; border-radius: 0.5rem; border: none; background: #b41b29; color: #ffffff; font-size: 0.875rem; font-weight: 700; cursor: pointer;"
                    disabled>Guardar Pago</button>
            </div>
        </div>
    </div>

    <!-- Botón FAB -->
    <button id="fab-states-btn"
        class="flex items-center justify-center rounded-full bg-primary text-white hover:bg-primary/90 focus:outline-none cursor-pointer"
        style="position: fixed; bottom: 24px; right: 24px; z-index: 50; width: 56px; height: 56px; box-shadow: 0 4px 14px 0 rgba(0,0,0,0.39); transition: all 0.3s ease;"
        title="Ver significados de estados">
        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor"
            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"></circle>
            <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
            <path d="M12 17h.01"></path>
        </svg>
    </button>

    <!-- Panel de información de estados -->
    <div id="fab-states-panel" class="bg-white border overflow-hidden flex flex-col rounded-lg"
        style="position: fixed; bottom: 96px; right: 24px; z-index: 100; width: 320px; box-shadow: 0 10px 40px -10px rgba(0,0,0,0.3); opacity: 0; pointer-events: none; transform: translateY(16px); transition: all 0.3s ease;">
        <div class="bg-primary px-5 py-4 text-white">
            <h3 class="font-medium text-lg flex items-center gap-2" style="margin: 0; color: white;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                    <polyline points="10 9 9 9 8 9"></polyline>
                </svg>
                Significados de Terminos
            </h3>
        </div>
        <div class="p-5 flex flex-col gap-4 overflow-y-auto" style="max-height: 60vh;">
            <div class="flex items-start gap-3">
                <div class="mt-0.5 flex-shrink-0 flex items-center justify-center rounded-full bg-primary text-white text-xs font-bold"
                    style="width:25px; height: 25px;">
                    1</div>
                <div>
                    <div class="font-semibold" style="color: #92400e; font-weight: 700;">Pendiente</div>
                    <div class="text-sm text-slate-500 mt-0.5">Falta Aprobar la Cotización .</div>
                </div>
            </div>

            <div class="flex items-start gap-3">
                <div class="mt-0.5 flex-shrink-0 flex items-center justify-center rounded-full bg-primary text-white text-xs font-bold"
                    style="width:25px; height: 25px;">
                    2</div>
                <div>
                    <div class="font-semibold" style="color: #166534; font-weight: 700;">Adjunto</div>
                    <div class="text-sm text-slate-500 mt-0.5">Se adjuntó el comprobante de la cotización.</div>
                </div>
            </div>
            <div class="flex items-start gap-3">
                <div class="mt-0.5 flex-shrink-0 flex items-center justify-center rounded-full bg-primary text-white text-xs font-bold"
                    style="width:25px; height: 25px;">
                    3</div>
                <div>
                    <div class="font-semibold" style="color: #B41B29; font-weight: 700;">Aprobar</div>
                    <div class="text-sm text-slate-500 mt-0.5">Cargar el comprobante de pago para la aprobación.</div>
                </div>
            </div>
            <div class="flex items-start gap-3">
                <div class="mt-0.5 flex-shrink-0 flex items-center justify-center rounded-full bg-primary text-white text-xs font-bold"
                    style="width:25px; height: 25px;">
                    4</div>
                <div>
                    <div class="font-semibold" style="color: #1d4ed8; font-weight: 700;">Prefijo: <span
                            class="text-danger">IN</span></div>
                    <div class="text-sm text-slate-500 mt-0.5">El cliente es Integrador</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal 1: Opción de dar de baja -->
    <div id="baja-option-modal" class="erp-modal-backdrop hidden" role="dialog" aria-modal="true"
        aria-labelledby="baja-option-title">
        <div class="erp-modal-card" style="max-width: 480px; border-radius: 16px;">
            <div class="erp-modal-header">
                <h3 id="baja-option-title" class="font-bold text-slate-900 text-lg">Dar de baja servicio(s)</h3>
                <button type="button" class="erp-modal-close" data-close-baja-option aria-label="Cerrar">×</button>
            </div>
            <div class="erp-modal-body p-6">
                <p class="text-sm text-slate-600 mb-5 font-medium">¿Cuándo deseas dar de baja los servicios seleccionados?
                </p>
                <div class="flex flex-col gap-3">
                    <button type="button"
                        class="btn-select-baja-modo flex items-center justify-between p-4 rounded-xl border border-slate-200 bg-white hover:border-red-500 hover:bg-red-50/50 transition-all text-left group"
                        data-baja-modo="ahora">
                        <div>
                            <div class="font-bold text-slate-900 group-hover:text-red-700 text-sm">Dar de baja ahora</div>
                            <div class="text-xs text-slate-500 mt-0.5">Inactiva el servicio inmediatamente y libera los
                                equipos/SIM.</div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-400 group-hover:text-red-600"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="9 18 15 12 9 6" />
                        </svg>
                    </button>
                    <button type="button"
                        class="btn-select-baja-modo flex items-center justify-between p-4 rounded-xl border border-slate-200 bg-white hover:border-red-500 hover:bg-red-50/50 transition-all text-left group"
                        data-baja-modo="periodo">
                        <div>
                            <div class="font-bold text-slate-900 group-hover:text-red-700 text-sm">Terminar periodo</div>
                            <div class="text-xs text-slate-500 mt-0.5">Se dará de baja automáticamente cuando cumpla su
                                fecha fin.</div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-400 group-hover:text-red-600"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="9 18 15 12 9 6" />
                        </svg>
                    </button>
                </div>
                <div class="mt-5 flex justify-end">
                    <button type="button"
                        class="px-4 py-2 text-sm font-semibold rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-100 transition-colors"
                        data-close-baja-option>Cancelar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal 2: Comentario de cambio de estado a Inactivo -->
    <div id="baja-comment-modal" class="erp-modal-backdrop hidden" role="dialog" aria-modal="true"
        aria-labelledby="baja-comment-title">
        <div class="erp-modal-card" style="max-width: 460px; border-radius: 16px;">
            <form method="POST" action="{{ route('modules.cuentasporcobrar.dar-de-baja-servicios') }}"
                id="baja-comment-form">
                @csrf
                <input type="hidden" name="baja_modo" id="baja-modo-input" value="ahora">
                <input type="hidden" name="mantener_sim" id="baja-mantener-sim-input" value="si">
                <div id="baja-selected-service-inputs"></div>
                <div class="erp-modal-body p-6">
                    <h3 id="baja-comment-title" class="text-xl font-bold text-slate-900 mb-1">Cambio de estado a Inactivo
                    </h3>
                    <p class="text-sm text-slate-600">
                        ¿Por qué estás cambiando de estado <strong class="text-slate-800">Activo</strong> a <strong
                            class="text-slate-800">Inactivo</strong>?
                    </p>
                    <p class="text-sm text-slate-600 mb-4">Ingresa un comentario describiendo el motivo.</p>

                    <div class="mb-5">
                        <label for="baja-comentario" class="block text-sm font-bold text-slate-800 mb-1.5">
                            Comentario <span class="text-red-500">*</span>
                        </label>
                        <textarea id="baja-comentario" name="comentario" rows="3" required
                            style="width:100%;border:1px solid #cbd5e1;border-radius:0.5rem;padding:0.65rem 0.8rem;font-size:0.9rem;color:#0f172a;resize:vertical;outline:none;transition:border-color 0.15s ease;box-sizing:border-box;"
                            placeholder="Describe el motivo de la baja (obligatorio)..."></textarea>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button type="button"
                            class="px-5 py-2.5 rounded-lg border border-slate-200 bg-slate-100 text-slate-700 text-sm font-bold hover:bg-slate-200 transition-colors"
                            data-close-baja-comment>
                            Cancelar
                        </button>
                        <button type="submit" id="baja-submit-btn"
                            class="px-6 py-2.5 rounded-lg text-white text-sm font-bold transition-colors"
                            style="background-color: #b41B29;">
                            Siguiente
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal 3: Mantener o no la relación del número con su SIM card -->
    <div id="baja-sim-modal" class="erp-modal-backdrop hidden" role="dialog" aria-modal="true"
        aria-labelledby="baja-sim-title">
        <div class="erp-modal-card"
            style="max-width: 520px; border-radius: 20px; background: #ffffff; padding: 28px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
            <div class="mb-5">
                <h3 id="baja-sim-title"
                    style="font-size: 1.25rem; font-weight: 800; color: #0f172a; margin-bottom: 6px; line-height: 1.3;">
                    ¿Deseas mantener la relación del número con su SIM card?
                </h3>
                <p style="font-size: 0.85rem; color: #64748b; font-weight: 500; margin: 0;">
                    Selecciona cómo debe quedar la relación al dar de baja este servicio.
                </p>
            </div>
            <div class="space-y-3.5 mb-2">
                <!-- Opción 1: Sí mantener -->
                <div class="mb-2"
                    style="background-color: #eff6ff; border: 1.5px solid #bfdbfe; border-left: 5px solid #2563eb; border-radius: 12px; padding: 14px 16px;">
                    <strong
                        style="color: #1d4ed8; font-weight: 700; font-size: 0.92rem; display: block; margin-bottom: 3px;">Sí
                        mantener</strong>
                    <span style="color: #475569; font-size: 0.82rem; line-height: 1.45; display: block;">El número y su SIM
                        siguen emparejados, disponibles para otros dispositivos.</span>
                </div>
                <!-- Opción 2: No mantener -->
                <div
                    style="background-color: #fefce8; border: 1.5px solid #fef08a; border-left: 5px solid #eab308; border-radius: 12px; padding: 14px 16px;">
                    <strong
                        style="color: #a16207; font-weight: 700; font-size: 0.92rem; display: block; margin-bottom: 3px;">No
                        mantener</strong>
                    <span style="color: #475569; font-size: 0.82rem; line-height: 1.45; display: block;">El número y su SIM
                        se desvinculan y quedan libres de forma independiente.</span>
                </div>
            </div>
            <div
                style="display: flex; align-items: center; justify-content: flex-end; gap: 12px; padding-top: 16px; border-top: 1px solid #f1f5f9;">
                <button type="button" data-close-baja-sim
                    style="background-color: #ffffff; border: 1px solid #cbd5e1; color: #475569; font-weight: 700; font-size: 0.85rem; padding: 10px 20px; border-radius: 10px; cursor: pointer; transition: all 0.15s ease;">
                    Cancelar
                </button>
                <button type="button" id="baja-sim-no-btn"
                    style="background-color: #eab308; border: 1px solid #ca8a04; color: #ffffff; font-weight: 700; font-size: 0.85rem; padding: 10px 22px; border-radius: 10px; cursor: pointer; transition: all 0.15s ease;">
                    No mantener
                </button>
                <button type="button" id="baja-sim-si-btn"
                    style="background-color: #2563eb; border: 1px solid #1d4ed8; color: #ffffff; font-weight: 700; font-size: 0.85rem; padding: 10px 24px; border-radius: 10px; cursor: pointer; transition: all 0.15s ease;">
                    Sí mantener
                </button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const pdfModal = document.getElementById('cotizacion-pdf-preview-modal');
            const pdfFrame = document.getElementById('cotizacion-pdf-preview-frame');
            const pdfNumber = document.getElementById('cotizacion-pdf-preview-number');
            const closePdfModal = function () {
                if (!pdfModal) {
                    return;
                }
                pdfModal.classList.add('hidden');
                pdfModal.classList.remove('flex');
                if (pdfFrame) {
                    pdfFrame.src = 'about:blank';
                }
                document.body.style.overflow = '';
            };

            document.addEventListener('click', function (event) {
                const previewButton = event.target.closest('[data-cotizacion-pdf-preview]');
                if (previewButton && pdfModal && pdfFrame) {
                    event.preventDefault();
                    pdfFrame.src = previewButton.dataset.cotizacionPdfPreview || 'about:blank';
                    if (pdfNumber) {
                        pdfNumber.textContent = previewButton.dataset.cotizacionNumber
                            ? 'Cotización ' + previewButton.dataset.cotizacionNumber
                            : '';
                    }
                    pdfModal.classList.remove('hidden');
                    pdfModal.classList.add('flex');
                    document.body.style.overflow = 'hidden';
                    return;
                }

                if (event.target.closest('[data-cotizacion-pdf-preview-close]')) {
                    closePdfModal();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && pdfModal && !pdfModal.classList.contains('hidden')) {
                    closePdfModal();
                }
            });

            const fabBtn = document.getElementById('fab-states-btn');
            const fabPanel = document.getElementById('fab-states-panel');

            if (fabBtn && fabPanel) {
                // Movemos los elementos directamente al body para evitar problemas de posicionamiento
                // document.body.appendChild(fabBtn);
                // document.body.appendChild(fabPanel);

                let isOpen = false;

                fabBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    isOpen = !isOpen;
                    togglePanel();
                });

                // Cerrar al hacer clic fuera del panel
                document.addEventListener('click', function (e) {
                    if (isOpen && !fabPanel.contains(e.target)) {
                        isOpen = false;
                        togglePanel();
                    }
                });

                // Evitar que el clic dentro del panel lo cierre
                fabPanel.addEventListener('click', function (e) {
                    e.stopPropagation();
                });

                function togglePanel() {
                    if (isOpen) {
                        fabPanel.style.opacity = '1';
                        fabPanel.style.pointerEvents = 'auto';
                        fabPanel.style.transform = 'translateY(0)';
                        fabBtn.style.transform = 'rotate(0deg) scale(1.05)';
                    } else {
                        fabPanel.style.opacity = '0';
                        fabPanel.style.pointerEvents = 'none';
                        fabPanel.style.transform = 'translateY(16px)';
                        fabBtn.style.transform = 'rotate(0deg) scale(1)';
                    }
                }
            }
        });
    </script>
@endsection