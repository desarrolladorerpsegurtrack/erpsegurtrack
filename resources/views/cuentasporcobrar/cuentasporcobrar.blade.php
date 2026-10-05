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
    @endphp
    <div class="erp-cuentas-layout">
        <div id="cxc-list-wrapper" class="erp-cuentas-card" data-loaded-tab="{{ $activeTab }}">
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
                {{-- ALERTAS DE SESIÓN --}}
                @if(session('success'))
                    <div data-cxc-session-notice class="mb-4 rounded-lg border px-4 py-3 text-base font-semibold relative"
                        style="border-color:#16a34a;background-color:#dcfce7;color:#14532d;">
                        ✓ {{ session('success') }}
                        <button type="button"
                            class="absolute top-0 right-0 mt-2 mr-2 text-lg font-bold text-gray-600 hover:text-gray-800"
                            onclick="this.parentElement.style.display='none';">&times;</button>
                    </div>
                @endif
                @if(session('error'))
                    <div data-cxc-session-notice class="mb-4 rounded-lg border px-4 py-3 text-base font-semibold relative"
                        style="border-color:#a31616;background-color:#fcdcdc;color:#531414;">
                        ✕ {{ session('error') }}
                        <button type="button"
                            class="absolute top-0 right-0 mt-2 mr-2 text-lg font-bold text-gray-600 hover:text-gray-800"
                            onclick="this.parentElement.style.display='none';">&times;</button>
                    </div>
                @endif

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
                    </div>

                    <div class="flex flex-col gap-y-2 py-3 px-1 sm:flex-row sm:items-center">
                        <div class="flex w-full flex-col gap-y-2 sm:flex-row sm:items-center">
                            <!-- BUSCADOR EN TIEMPO REAL -->
                            <div class="flex flex-col gap-y-2 sm:flex-row sm:items-center">
                                <div class="relative">
                                    <i data-tw-merge="" data-lucide="search"
                                        class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-500"></i>
                                    <input id="quote-search-input" name="quote_q" type="text" autocomplete="off"
                                        value="{{ $quoteFilters['quote_q'] ?? '' }}" placeholder="Buscar..."
                                        class="disabled:bg-slate-100 disabled:cursor-not-allowed transition duration-200 ease-in-out w-full pr-10 text-sm border-slate-200 shadow-sm placeholder:text-slate-400/90 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 rounded-[0.5rem] pl-9 sm:w-64">
                                    <button type="button" id="quote-search-clear" style="display: none;"
                                        class="absolute inset-y-0 right-0 z-10 mr-2 flex items-center justify-center rounded-full bg-transparent px-2 text-slate-500 transition hover:bg-transparent hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-opacity-40">
                                        <i data-tw-merge="" data-lucide="x" class="h-4 w-4 stroke-[1.3]"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="flex flex-col gap-x-3 gap-y-2 sm:ml-auto sm:flex-row">
                                <!-- EXPORTAR DROPDOWN -->
                                <div class="relative inline-block text-left erp-dropdown-container">
                                    <button type="button"
                                        class="erp-dropdown-btn transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none border-secondary text-slate-500 dark:border-darkmode-100/40 dark:text-slate-300 [&:hover:not(:disabled)]:bg-secondary/20 w-full sm:w-auto">
                                        <i data-tw-merge="" data-lucide="download" class="mr-2 h-4 w-4 stroke-[1.3]"></i>
                                        Exportar
                                        <i data-tw-merge="" data-lucide="chevron-down"
                                            class="ml-2 h-4 w-4 stroke-[1.3]"></i>
                                    </button>
                                    <div class="erp-dropdown-panel hidden absolute right-0 top-full mt-2 w-36 rounded-md border border-slate-200 bg-white p-2 shadow-xl"
                                        style="position: absolute; right: 0; top: 100%; z-index: 99999; min-width: 140px; background: #ffffff;">
                                        <a href="{{ route('modules.cuentasporcobrar.quotations.export', array_merge(['format' => 'pdf'], $quoteFilters ?? [])) }}"
                                            id="export-pdf-link"
                                            class="cursor-pointer flex items-center p-2 transition duration-200 ease-in-out rounded-md hover:bg-slate-100 text-slate-700 text-sm font-medium">
                                            <i data-tw-merge="" data-lucide="file-bar-chart"
                                                class="stroke-[1.3] mr-2 h-4 w-4 text-slate-500"></i>
                                            PDF
                                        </a>
                                        <a href="{{ route('modules.cuentasporcobrar.quotations.export', array_merge(['format' => 'xlsx'], $quoteFilters ?? [])) }}"
                                            id="export-xlsx-link"
                                            class="cursor-pointer flex items-center p-2 transition duration-300 ease-in-out rounded-md hover:bg-slate-100 text-slate-700 text-sm font-medium">
                                            <i data-tw-merge="" data-lucide="file-bar-chart"
                                                class="stroke-[1.3] mr-2 h-4 w-4 text-slate-500"></i>
                                            XLSX
                                        </a>
                                    </div>
                                </div>

                                <!-- FILTRO DROPDOWN -->
                                <div class="relative inline-block text-left erp-dropdown-container">
                                    <button type="button"
                                        class="erp-dropdown-btn transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none border-secondary text-slate-500 dark:border-darkmode-100/40 dark:text-slate-300 [&:hover:not(:disabled)]:bg-secondary/20 w-full sm:w-auto">
                                        <i data-tw-merge="" data-lucide="arrow-down-wide-narrow"
                                            class="mr-2 h-4 w-4 stroke-[1.3]"></i>
                                        Filtro
                                        <span id="quote-filter-badge"
                                            class="ml-2 hidden h-5 items-center justify-center rounded-full border bg-slate-100 px-1.5 text-xs font-medium">0</span>
                                    </button>
                                    <div class="erp-dropdown-panel hidden absolute right-0 top-full mt-2 w-64 rounded-xl border border-slate-200 bg-white p-4 shadow-xl"
                                        style="position: absolute; right: 0; top: 100%; z-index: 99999; min-width: 260px; background: #ffffff;">
                                        <form id="quote-filter-dropdown-form" onsubmit="return false;">
                                            <div class="mb-3">
                                                <div class="text-xs font-medium uppercase text-slate-500">Grupo</div>
                                                <input type="text" id="quote-filter-group" name="quote_group"
                                                    value="{{ $quoteFilters['quote_group'] ?? '' }}"
                                                    placeholder="GRP-62 o IND-34"
                                                    class="mt-2 w-full rounded-[0.5rem] border-slate-200 text-sm shadow-sm transition duration-200 ease-in-out focus:border-primary focus:ring-4 focus:ring-primary focus:ring-opacity-20">
                                            </div>
                                            <div class="mb-3">
                                                <div class="text-xs font-medium uppercase text-slate-500">Cliente</div>
                                                <input type="text" id="quote-filter-client" name="quote_client"
                                                    value="{{ $quoteFilters['quote_client'] ?? '' }}"
                                                    placeholder="Buscar cliente"
                                                    class="mt-2 w-full rounded-[0.5rem] border-slate-200 text-sm shadow-sm transition duration-200 ease-in-out focus:border-primary focus:ring-4 focus:ring-primary focus:ring-opacity-20">
                                            </div>
                                            <div class="mb-3">
                                                <div class="text-xs font-medium uppercase text-slate-500">Moneda</div>
                                                <select id="quote-filter-currency" name="quote_currency"
                                                    class="mt-2 w-full rounded-[0.5rem] border-slate-200 text-sm shadow-sm transition duration-200 ease-in-out focus:border-primary focus:ring-4 focus:ring-primary focus:ring-opacity-20">
                                                    <option value="">Todas</option>
                                                    <option value="sol" @selected(($quoteFilters['quote_currency'] ?? '') === 'sol')>Soles</option>
                                                    <option value="dolar" @selected(($quoteFilters['quote_currency'] ?? '') === 'dolar')>Dólares</option>
                                                    <option value="euro" @selected(($quoteFilters['quote_currency'] ?? '') === 'euro')>Euros</option>
                                                </select>
                                            </div>
                                            <div class="mb-3">
                                                <div class="text-xs font-medium uppercase text-slate-500">Servicio</div>
                                                <select id="quote-filter-service" name="quote_service"
                                                    class="mt-2 w-full rounded-[0.5rem] border-slate-200 text-sm shadow-sm transition duration-200 ease-in-out focus:border-primary focus:ring-4 focus:ring-primary focus:ring-opacity-20">
                                                    <option value="">Todos</option>
                                                    <option value="EQUIPAMIENTO" @selected(($quoteFilters['quote_service'] ?? '') === 'EQUIPAMIENTO')>EQUIPAMIENTO</option>
                                                    <option value="PLANES" @selected(($quoteFilters['quote_service'] ?? '') === 'PLANES')>PLANES</option>
                                                    <option value="SERVICIOS TÉCNICOS" @selected(($quoteFilters['quote_service'] ?? '') === 'SERVICIOS TÉCNICOS')>SERVICIOS TÉCNICOS</option>
                                                </select>
                                            </div>
                                            <div class="mb-3">
                                                <div class="text-xs font-medium uppercase text-slate-500">Estado</div>
                                                <select id="quote-filter-status" name="quote_status"
                                                    class="mt-2 w-full rounded-[0.5rem] border-slate-200 text-sm shadow-sm transition duration-200 ease-in-out focus:border-primary focus:ring-4 focus:ring-primary focus:ring-opacity-20">
                                                    <option value="1" @selected(($quoteFilters['quote_status'] ?? '1') === '1')>Aprobado(SP) - Pendiente</option>
                                                    <option value="2" @selected(($quoteFilters['quote_status'] ?? '') === '2')>Aprobado - Con pago</option>
                                                </select>
                                            </div>
                                            <div class="mb-3">
                                                <div class="text-xs font-medium uppercase text-slate-500">Fecha de emisión
                                                </div>
                                                <input type="date" id="quote-filter-date" name="quote_date"
                                                    value="{{ $quoteFilters['quote_date'] ?? '' }}"
                                                    class="mt-2 w-full rounded-[0.5rem] border-slate-200 text-sm shadow-sm transition duration-200 ease-in-out focus:border-primary focus:ring-4 focus:ring-primary focus:ring-opacity-20">
                                            </div>
                                            <div class="mt-4 flex items-center gap-2 border-t border-slate-100 pt-3">
                                                <button type="button" id="quote-filter-apply-btn"
                                                    class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none bg-primary border-primary text-white">
                                                    Aplicar
                                                </button>
                                                <button type="button" id="quote-filter-clear-btn"
                                                    class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none border-secondary text-slate-500">
                                                    Limpiar
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="quote-results">
                        @if($activeTab !== 'cotizaciones')
                            <div class="erp-empty-state">Cargando cotizaciones al seleccionar esta sección...</div>
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
                                            <th class="bg-slate-50">Archivo</th>
                                            <th class="bg-slate-50">Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($cotizacionesPendientes as $cotizacion)
                                            <tr data-estado="{{ (string) ($cotizacion->estado ?? '1') }}">
                                                <td class="text-center">
                                                    <input type="checkbox" class="quote-row-select"
                                                        value="{{ $cotizacion->nroCotizacion ?? '' }}"
                                                        aria-label="Seleccionar cotización {{ $cotizacion->nroCotizacion ?? '-' }}">
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
                                                    <span class="erp-service-badge">{{ $cotizacion->servicio ?? 'EQUIPAMIENTO' }}</span>
                                                </td>
                                                <td>
                                                    @if((string) ($cotizacion->estado ?? '') === '2' || !empty($cotizacion->archivoPago))
                                                        <span class="erp-badge success">Adjunto</span>
                                                    @else
                                                        <span class="erp-badge warning">Pendiente</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if(!empty($cotizacion->archivoPago))
                                                        <a href="{{ asset('storage/' . $cotizacion->archivoPago) }}" target="_blank"
                                                            class="erp-btn-secondary"
                                                            style="display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.35rem 0.35rem; text-decoration: none; font-size: 0.8125rem; font-weight: 600; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 0.375rem; color: #334155; transition: all 0.2s ease;">
                                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14"
                                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                                stroke-linecap="round" stroke-linejoin="round">
                                                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                                                <circle cx="12" cy="12" r="3"></circle>
                                                            </svg>
                                                            Comprobante
                                                        </a>
                                                    @else
                                                        <button type="button" class="erp-btn-primary erp-btn-gestion"
                                                            data-cotizacion-id="{{ $cotizacion->nroCotizacion ?? '' }}"
                                                            data-cotizacion-number="{{ $cotizacion->nroCotizacion ?? '' }}"
                                                            data-cotizacion-client="{{ $cotizacion->cliente_nombre ?? '-' }}"
                                                            data-cotizacion-amount="{{ $cotizacion->monto_display ?? '-' }}"
                                                            data-cotizacion-payment="{{ $cotizacion->forma_pago_detalle ?? '' }}"
                                                            data-cotizacion-group="{{ $cotizacion->batch_id ?? '' }}"
                                                            data-cotizacion-is-group="{{ !empty($cotizacion->es_grupo) ? '1' : '0' }}"
                                                            data-cotizacion-group-count="{{ $cotizacion->grupo_count ?? 1 }}"
                                                            data-cotizacion-approve-url="{{ route('modules.cuentasporcobrar.quotations.approve', ['id' => $cotizacion->nroCotizacion]) }}">
                                                            Aprobar
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
                            <button type="button" id="btn-dar-de-baja"
                                class="erp-btn-primary bg-red-600 hover:bg-red-700 text-white font-bold px-4 py-1.5 rounded-md text-xs transition duration-200"
                                disabled style="background-color: #b41B29;">
                                Dar de baja
                            </button>
                            <button type="button" id="open-service-payment" class="erp-btn-primary" disabled
                                style="display: none;">
                                <i data-lucide="wallet-cards" class="mr-2 h-4 w-4"></i>
                                Cobrar seleccionados
                            </button>
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
                                <option value="pendiente" @selected(($serviceFilters['service_status'] ?? '') === 'pendiente')>Pendiente</option>
                                <option value="pendiente pago parcial" @selected(($serviceFilters['service_status'] ?? '') === 'pendiente pago parcial')>Pendiente Pago parcial</option>
                                <option value="pendiente a credito" @selected(($serviceFilters['service_status'] ?? '') === 'pendiente a credito')>Pendiente a credito</option>
                                <option value="facturado" @selected(($serviceFilters['service_status'] ?? '') === 'facturado')>Facturado</option>
                                <option value="vencido" @selected(($serviceFilters['service_status'] ?? '') === 'vencido')>
                                    Vencido</option>
                                <option value="cancelado" @selected(($serviceFilters['service_status'] ?? '') === 'cancelado')>Cancelado</option>
                                <option value="cancelado detracción" @selected(($serviceFilters['service_status'] ?? '') === 'cancelado detracción')>Cancelado Detracción</option>
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
                        @if($activeTab !== 'servicios')
                            <div class="erp-empty-state">Cargando Cuentas por cobrar en esta sección...</div>
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
                                                $isVencido = in_array((string) ($clientGroup['cxc_estado'] ?? ''), ['4', 'VENCIDO'], true);
                                                $isCanceladoGroup = in_array((string) ($clientGroup['cxc_estado'] ?? ''), ['3', 'CANCELADO', 'Cancelado Detracción'], true) || str_starts_with(strtolower($clientGroup['estado_pago'] ?? ''), 'cancelado');
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
                                                data-end-date="{{ $clientGroup['fecha_fin_iso'] ?? '' }}"
                                                data-all-service-ids="{{ $allServiceIdsStr }}"
                                                data-monto-total="{{ $clientGroup['monto_total'] }}">
                                                <td class="text-center py-2 px-1">
                                                    <input type="checkbox"
                                                        class="stair-check-client duration-100 ease-in-out shadow-sm border-slate-200 cursor-pointer rounded focus:ring-4 focus:ring-offset-0 focus:ring-primary focus:ring-opacity-20 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&[type='radio']]:checked:bg-primary [&[type='radio']]:checked:border-primary [&[type='radio']]:checked:border-opacity-10 [&[type='checkbox']]:checked:bg-primary [&[type='checkbox']]:checked:border-primary [&[type='checkbox']]:checked:border-opacity-10 [&:disabled:not(:checked)]:bg-slate-100 [&:disabled:not(:checked)]:cursor-not-allowed [&:disabled:not(:checked)]:dark:bg-darkmode-800/50 [&:disabled:checked]:opacity-70 [&:disabled:checked]:cursor-not-allowed [&:disabled:checked]:dark:bg-darkmode-800/50"
                                                        data-client-id="{{ $groupId }}" data-service-ids="{{ $allServiceIdsStr }}">
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
                                                        $statusClass = match (strtolower($statusLabel)) {
                                                            'cancelado' => 'service-days-green',
                                                            'cancelado detracción' => 'service-days-green',
                                                            'facturado' => 'service-days-yellow',
                                                            'pendiente pago parcial' => 'service-days-yellow',
                                                            'pendiente a credito' => 'service-days-yellow',
                                                            'vencido' => 'service-days-gray',
                                                            default => 'service-days-red',
                                                        };
                                                    @endphp
                                                    <span class="erp-badge {{ $statusClass }}">{{ $statusLabel }}</span>
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
                                                            style="background-color: #b41B29;"
                                                            data-service-ids="{{ $allServiceIdsStr }}"
                                                            data-client-id="{{ $clientId }}"
                                                            data-client-name="{{ $clientGroup['cliente_nombre'] }}"
                                                            data-amount="{{ $clientGroup['monto_total'] }}"
                                                            data-payment-amount="{{ $clientGroup['monto_saldo'] }}"
                                                            data-amount-display="{{ $clientGroup['monto_total_display'] }}"
                                                            data-tipo-cobro-id="{{ $clientGroup['tipo_cobro_id'] ?? '' }}"
                                                            data-moneda-id="{{ $clientGroup['moneda_idmoneda'] ?? 1 }}"
                                                            data-currency-symbol="{{ $clientGroup['moneda_simbolo'] ?? '$' }}"
                                                            data-cxc-id="{{ $clientGroup['cxc_id'] ?? '' }}"
                                                            data-cxc-ids="{{ implode(',', $clientGroup['cxc_ids'] ?? []) }}"
                                                            data-cxc-estado="{{ $clientGroup['cxc_estado'] ?? 'PENDIENTE' }}"
                                                            data-advance-months="{{ $clientGroup['adelanto_meses'] ?? 0 }}"
                                                            data-period-end="{{ $clientGroup['fecha_fin_iso'] ?? '' }}"
                                                            data-next-period-end="{{ $clientGroup['fecha_fin_siguiente_iso'] ?? '' }}"
                                                            data-doc-ref="{{ $clientGroup['cxc_doc'] ?? '' }}">
                                                            {{ $isCanceladoGroup ? 'Ver' : 'Gestionar'  }}
                                                        </button>
                                                        @if($isCanceladoGroup && !empty($clientGroup['cxc_id']) && !empty($clientGroup['puede_revertir']))
                                                            <button type="button"
                                                                class="btn-revert-payment inline-flex items-center justify-center rounded-md border border-amber-500 px-2 py-1 text-[11px] font-bold text-amber-700 transition duration-200 hover:bg-amber-50"
                                                                data-revert-url="{{ route('modules.cuentasporcobrar.revertir-pago', ['id' => $clientGroup['cxc_id']]) }}"
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
                                                            class="flex items-center justify-between mb-2 pb-1 border-b border-slate-100">
                                                            <span
                                                                class="text-xs font-bold uppercase tracking-wider text-slate-500 flex items-center gap-1.5">
                                                                <svg xmlns="http://www.w3.org/2000/svg"
                                                                    class="h-3.5 w-3.5 text-primary" viewBox="0 0 24 24" fill="none"
                                                                    stroke="currentColor" stroke-width="2">
                                                                    <path d="M22 12h-4l-3 9L9 3l-3 9H2" />
                                                                </svg>
                                                                Tipos de Servicios de {{ $clientGroup['cliente_nombre'] }}
                                                            </span>
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
                                                                    @endphp
                                                                    <!-- NIVEL 2: FILA DE TIPO DE SERVICIO -->
                                                                    <tr class="stair-service-row border-b border-slate-100 hover:bg-slate-50 transition-colors cursor-pointer"
                                                                        data-target="#{{ $typeDetailId }}">
                                                                        <td class="py-2 px-3 font-semibold text-slate-800">
                                                                            <div class="stair-service-name">
                                                                                <input type="checkbox"
                                                                                    class="stair-check-type duration-100 ease-in-out shadow-sm border-slate-200 cursor-pointer rounded focus:ring-4 focus:ring-offset-0 focus:ring-primary focus:ring-opacity-20 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&[type='radio']]:checked:bg-primary [&[type='radio']]:checked:border-primary [&[type='radio']]:checked:border-opacity-10 [&[type='checkbox']]:checked:bg-primary [&[type='checkbox']]:checked:border-primary [&[type='checkbox']]:checked:border-opacity-10 [&:disabled:not(:checked)]:bg-slate-100 [&:disabled:not(:checked)]:cursor-not-allowed [&:disabled:not(:checked)]:dark:bg-darkmode-800/50 [&:disabled:checked]:opacity-70 [&:disabled:checked]:cursor-not-allowed [&:disabled:checked]:dark:bg-darkmode-800/50"
                                                                                    data-client-id="{{ $groupId }}"
                                                                                    data-service-ids="{{ $serviceIdsStr }}">
                                                                                {{ $typeGroup['servicio_nombre'] }}
                                                                            </div>
                                                                        </td>
                                                                        <td class="py-2.5 px-3 text-slate-600">
                                                                            {{ $typeGroup['plataforma'] }}
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
                                                                                    data-client-name="{{ $clientGroup['cliente_nombre'] }}"
                                                                                    data-amount="{{ $typeGroup['monto_subtotal'] }}"
                                                                                    data-payment-amount="{{ $typeGroup['monto_saldo'] }}"
                                                                                    data-amount-display="{{ $typeGroup['monto_subtotal_display'] }}"
                                                                                    data-tipo-cobro-id="{{ $typeGroup['tipo_cobro_id'] ?? ($clientGroup['tipo_cobro_id'] ?? '') }}"
                                                                                    data-moneda-id="{{ $clientGroup['moneda_idmoneda'] ?? 1 }}"
                                                                                    data-currency-symbol="{{ $clientGroup['moneda_simbolo'] ?? '$' }}"
                                                                                    data-cxc-id="{{ $typeGroup['cxc_id'] ?: $clientGroup['cxc_id'] }}"
                                                                                    data-cxc-ids="{{ $typeCxcIdsStr }}"
                                                                                    data-cxc-estado="{{ $typeGroup['cxc_estado'] }}"
                                                                                    data-advance-months="{{ $clientGroup['adelanto_meses'] ?? 0 }}"
                                                                                    data-period-end="{{ $clientGroup['fecha_fin_iso'] ?? '' }}"
                                                                                    data-next-period-end="{{ $clientGroup['fecha_fin_siguiente_iso'] ?? '' }}"
                                                                                    data-doc-ref="{{ $typeGroup['cxc_doc'] }}">
                                                                                    {{ $isCanceladoGroup ? 'Ver' : 'Gestionar' }}
                                                                                </button>
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
                                                                                                                value="{{ $veh['idservicioCliente'] }}">
                                                                                                        @endif
                                                                                                        {{ $veh['placa'] }}
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
                                                                                                    @if($canEditServicePrice && (int) $veh['idservicioCliente'] > 0)
                                                                                                        <button type="button"
                                                                                                            class="btn-edit-service-price inline-flex h-7 w-7 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-600 hover:border-red-500 hover:text-red-700"
                                                                                                            data-service-id="{{ $veh['idservicioCliente'] }}"
                                                                                                            data-service-price="{{ $veh['monto'] }}"
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
                                                                                                        data-period-end="{{ $clientGroup['fecha_fin_iso'] ?? '' }}"
                                                                                                        data-next-period-end="{{ $clientGroup['fecha_fin_siguiente_iso'] ?? '' }}"
                                                                                                        data-doc-ref="{{ $veh['doc_referencia'] === '-' ? '' : $veh['doc_referencia'] }}">
                                                                                                        {{ $isCanceladoGroup ? 'Ver' : 'Gestionar' }}
                                                                                                    </button>

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
                    <p class="mt-1 text-xs font-medium text-slate-500">La CXC volverá al flujo de facturación y pago.</p>
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
                </div>
                <div class="erp-modal-actions border-t border-slate-100 px-5 py-3">
                    <button type="button" class="erp-btn-secondary" data-close-revert-payment>Cancelar</button>
                    <button type="submit" class="erp-btn-primary">Confirmar reversión</button>
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
            background: #cbd5e1;
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
            width: 12%;
        }

        .erp-vehicles-nested-table th:nth-child(2),
        .erp-vehicles-nested-table td:nth-child(2) {
            width: 15%;
        }

        .erp-vehicles-nested-table th:nth-child(3),
        .erp-vehicles-nested-table td:nth-child(3) {
            width: 10%;
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
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
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

        .erp-quotations-table .erp-btn-primary {
            min-width: 101px;
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
            border-radius: 9999px;
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
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1.25rem;
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

        #service-payment-modal .erp-modal-card {
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
            const searchInput = document.getElementById('quote-search-input');
            let quoteSearchTimer;
            let cxcRequestController = null;
            let cxcRequestId = 0;
            let cxcQuery = new URLSearchParams(window.location.search);
            let loadedCxcTab = document.getElementById('cxc-list-wrapper')?.dataset.loadedTab || 'cotizaciones';
            let appliedQuoteStatus = document.getElementById('quote-filter-status')?.value || '1';

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

            async function loadCxcResults(url, tab, options = {}) {
                const requestId = ++cxcRequestId;
                cxcRequestController?.abort();
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

                    const resultId = tab === 'servicios' ? 'service-results' : 'quote-results';
                    const currentResults = document.getElementById(resultId);
                    const nextResults = documentHtml.getElementById(resultId);
                    if (!currentResults || !nextResults) throw new Error('No se encontró el bloque de resultados.');
                    currentResults.replaceWith(nextResults);
                    loadedCxcTab = tab;
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
                    refreshBajaBtn();
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        console.error('Error actualizando Cuentas por Cobrar:', error);
                    }
                }
            }

            document.addEventListener('change', function (event) {
                const quoteSelectAll = event.target.closest('#quote-select-all');
                if (quoteSelectAll) {
                    document.querySelectorAll('#quote-results .quote-row-select').forEach((checkbox) => {
                        checkbox.checked = quoteSelectAll.checked;
                    });
                    quoteSelectAll.indeterminate = false;
                    return;
                }

                const quoteRowSelect = event.target.closest('.quote-row-select');
                if (quoteRowSelect) {
                    const quoteCheckboxes = Array.from(document.querySelectorAll('#quote-results .quote-row-select'));
                    const quoteSelectAllInput = document.getElementById('quote-select-all');
                    if (quoteSelectAllInput) {
                        const selectedCount = quoteCheckboxes.filter((checkbox) => checkbox.checked).length;
                        quoteSelectAllInput.checked = quoteCheckboxes.length > 0 && selectedCount === quoteCheckboxes.length;
                        quoteSelectAllInput.indeterminate = selectedCount > 0 && selectedCount < quoteCheckboxes.length;
                    }
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

            document.addEventListener('click', function (event) {
                const link = event.target.closest('[data-cxc-pagination] nav a[href]');
                if (!link || link.getAttribute('href') === 'javascript:;') return;
                event.preventDefault();
                event.stopImmediatePropagation();
                const tab = link.closest('#quote-results') ? 'cotizaciones' : 'servicios';
                loadCxcResults(link.href, tab);
            }, true);

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
                const filters = [
                    { name: 'quote_q', value: searchInput?.value },
                    { name: 'quote_group', value: document.getElementById('quote-filter-group')?.value },
                    { name: 'quote_client', value: document.getElementById('quote-filter-client')?.value },
                    { name: 'quote_currency', value: document.getElementById('quote-filter-currency')?.value },
                    { name: 'quote_service', value: document.getElementById('quote-filter-service')?.value },
                    { name: 'quote_status', value: appliedQuoteStatus },
                    { name: 'quote_date', value: document.getElementById('quote-filter-date')?.value }
                ];
                const params = new URLSearchParams();
                filters.forEach(({ name, value }) => {
                    const normalizedValue = String(value ?? '').trim();
                    if (normalizedValue !== '') params.set(name, normalizedValue);
                });
                document.querySelectorAll('#export-pdf-link, #export-xlsx-link').forEach((link) => {
                    const exportUrl = new URL(link.href, window.location.origin);
                    ['quote_q', 'quote_group', 'quote_client', 'quote_currency', 'quote_service', 'quote_status', 'quote_date'].forEach((name) => {
                        exportUrl.searchParams.delete(name);
                    });
                    params.forEach((value, name) => exportUrl.searchParams.set(name, value));
                    link.href = exportUrl.toString();
                });
                navigateToFilteredPage('cotizaciones', 'quotes_page', filters, options);
            }

            const searchClearBtn = document.getElementById('quote-search-clear');
            const filterBadge = document.getElementById('quote-filter-badge');
            const serviceSearchInput = document.getElementById('service-filter-client');
            const serviceDocumentInput = document.getElementById('service-filter-document');
            const serviceDateInput = document.getElementById('service-filter-date');
            const serviceMonthSummary = document.querySelector('#service-month-summary span');
            const serviceMonthInputs = document.querySelectorAll('#service-month-picker input[type="checkbox"]');
            const serviceMonthSelectAll = document.getElementById('service-month-select-all');
            const serviceSubtitle = document.getElementById('service-subtitle');
            const servicePaymentModal = document.getElementById('service-payment-modal');
            const servicePaymentForm = document.getElementById('service-payment-form');
            const openServicePayment = document.getElementById('open-service-payment');
            const serviceSelectionError = document.getElementById('service-selection-error');
            const selectAllServices = document.getElementById('select-all-services');
            const paymentClient = document.getElementById('payment-client');
            const paymentOriginal = document.getElementById('payment-original');
            const paymentAmount = document.getElementById('payment-amount');
            const paymentBalance = document.getElementById('payment-balance');
            const paymentCurrencyExtra = document.getElementById('payment-currency-extra');
            const paymentCurrencyModeInputs = document.querySelectorAll('input[name="payment_currency_mode"]');
            const paymentCurrencySelect = document.getElementById('payment-currency');
            const paymentExchangeRateInput = document.getElementById('payment-exchange-rate');
            const paymentConvertedAmountInput = document.getElementById('payment-converted-amount');
            const paymentConvertedTotalInput = document.getElementById('payment-converted-total');
            const partialPaymentField = document.getElementById('partial-payment-field');
            const selectedServiceInputs = document.getElementById('selected-service-inputs');

            function getCurrencySymbolFromText(valueText = '') {
                const text = String(valueText || '').trim();
                if (text.includes('US$') || text.includes('$')) return '$';
                if (text.includes('€')) return '€';
                if (text.includes('S/') || text.includes('soles') || text.includes('sol')) return 'S/';
                return '';
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

            function formatMoney(value, symbol = getCurrencySymbolFromText(paymentOriginal?.value || '')) {
                const numericValue = Number(value || 0);
                if (!symbol) {
                    return numericValue.toFixed(2);
                }
                return `${symbol} ${numericValue.toFixed(2)}`;
            }

            function updateBalanceAndCreditDisplay(totalAmount, paidAmount, balanceId, creditId, symbol) {
                const total = Math.max(Number(totalAmount) || 0, 0);
                const paid = Math.max(Number(paidAmount) || 0, 0);
                const balance = document.getElementById(balanceId);
                const credit = document.getElementById(creditId);
                const remaining = Math.max(total - paid, 0);
                const creditAmount = Math.max(paid - total, 0);

                if (balance) balance.textContent = formatMoney(remaining, symbol);
                if (credit) {
                    credit.textContent = `Nuevo saldo a favor: ${formatMoney(creditAmount, symbol)}`;
                    credit.classList.toggle('hidden', creditAmount <= 0);
                }
            }

            function getPaymentBaseAmount() {
                const raw = paymentOriginal?.value || 'S/ 0.00';
                const numericValue = Number(String(raw).replace(/[^0-9.-]+/g, '')) || 0;
                return Number.isFinite(numericValue) ? numericValue : 0;
            }

            function syncPaymentModeUI() {
                const selectedMode = document.querySelector('input[name="modo_pago"]:checked')?.value || 'total';
                const isPartial = selectedMode === 'parcial';
                partialPaymentField?.classList.toggle('hidden', !isPartial);
                if (paymentAmount) paymentAmount.required = isPartial;
                if (paymentBalance) {
                    const currentSymbol = getCurrencySymbolFromText(paymentOriginal?.value || '');
                    if (isPartial) {
                        const currentTotal = Number(paymentConvertedTotalInput?.value || paymentAmount?.value || 0);
                        const paidValue = Number(paymentAmount?.value || currentTotal || 0);
                        updateBalanceAndCreditDisplay(getPaymentBaseAmount(), paidValue, 'payment-balance', 'payment-credit-summary', currentSymbol);
                    } else {
                        updateBalanceAndCreditDisplay(getPaymentBaseAmount(), 0, 'payment-balance', 'payment-credit-summary', currentSymbol);
                    }
                }
                syncWithholdingDecisionUI();
            }

            function syncWithholdingDecisionUI() {
                const grossValue = Number(String(paymentOriginal?.value || '').replace(/[^0-9.-]+/g, '') || 0);
                const decisionGroup = document.getElementById('payment-withholding-decision-group');
                const radios = document.querySelectorAll('input[name="withholding_decision"]');
                if (!decisionGroup || radios.length === 0) return;

                const shouldShow = grossValue > 700;
                decisionGroup.classList.toggle('hidden', !shouldShow);
                if (!shouldShow) {
                    radios.forEach((radio) => { radio.checked = false; });
                    return;
                }

                const enteredAmount = Number(paymentAmount?.value || 0);
                const selectedDecision = document.querySelector('input[name="withholding_decision"]:checked');
                if (!selectedDecision) {
                    const defaultChoice = enteredAmount >= grossValue ? 'full_pending' : 'pending';
                    const fallback = document.querySelector(`input[name="withholding_decision"][value="${defaultChoice}"]`);
                    if (fallback) fallback.checked = true;
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

            function updateConvertedPaymentAmount() {
                const isOtherCurrency = document.querySelector('input[name="payment_currency_mode"]:checked')?.value === 'other';
                const exchangeRate = Number(paymentExchangeRateInput?.value || 0);
                const convertedAmount = Number(paymentConvertedAmountInput?.value || 0);
                const baseAmount = getPaymentBaseAmount();
                const totalRadio = document.querySelector('#service-payment-modal input[name="modo_pago"][value="total"]');
                const partialRadio = document.querySelector('#service-payment-modal input[name="modo_pago"][value="parcial"]');

                if (!isOtherCurrency) {
                    paymentConvertedTotalInput.value = baseAmount.toFixed(2);
                    if (paymentAmount) paymentAmount.value = baseAmount.toFixed(2);
                    if (paymentBalance) paymentBalance.textContent = formatMoney(0, getCurrencySymbolFromText(paymentOriginal?.value || ''));
                    if (totalRadio) totalRadio.checked = true;
                    if (partialRadio) partialRadio.checked = false;
                    syncPaymentModeUI();
                    return;
                }

                const convertedTotal = Number((exchangeRate * convertedAmount).toFixed(2));
                paymentConvertedTotalInput.value = convertedTotal.toFixed(2);

                const paymentValue = Math.max(convertedTotal, 0);
                if (isOtherCurrency) {
                    showPartialPaymentWithAmount(paymentValue);
                    return;
                }

                if (totalRadio) totalRadio.checked = true;
                if (partialRadio) partialRadio.checked = false;
                paymentAmount.value = paymentValue.toFixed(2);
                paymentBalance.textContent = formatMoney(Math.max(baseAmount - paymentValue, 0), getCurrencySymbolFromText(paymentOriginal?.value || ''));
                syncPaymentModeUI();
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
                const normalizedAmountDisplay = firstAmountDisplay || `${detectedSymbol} ${total.toFixed(2)}`;
                const creditCheckbox = document.getElementById('payment-use-credit');
                if (creditCheckbox) creditCheckbox.checked = false;
                const creditSummary = document.getElementById('payment-credit-summary');
                creditSummary?.classList.add('hidden');
                if (creditSummary) creditSummary.textContent = '';
                const selectedTypes = [...new Set(selected.map((row) => row.dataset.tipoCobroId).filter(Boolean))];
                setPaymentType(selectedTypes.length === 1 ? selectedTypes[0] : '');
                if (paymentClient) paymentClient.value = selected[0].dataset.serviceClientName || '-';
                if (paymentOriginal) paymentOriginal.value = normalizedAmountDisplay;
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
                document.querySelectorAll('input[name="withholding_decision"]').forEach((radio) => { radio.checked = false; });
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
                    selected[0]?.dataset.serviceClientName || ''
                );
                servicePaymentModal.classList.add('visible');
                document.body.style.overflow = 'hidden';
            }

            function syncPaymentCurrencyHidden() {
                const hiddenField = document.getElementById('payment-moneda-id');
                const uiSelect = document.getElementById('payment-currency');
                const isOtherCurrency = document.querySelector('input[name="payment_currency_mode"]:checked')?.value === 'other';
                if (!hiddenField) return;

                if (isOtherCurrency && uiSelect && uiSelect.value) {
                    hiddenField.value = String(uiSelect.value);
                    return;
                }

                const fallbackCurrencyId = hiddenField.value || uiSelect?.dataset?.defaultCurrency || '';
                hiddenField.value = fallbackCurrencyId || '';
                if (uiSelect && !isOtherCurrency) {
                    uiSelect.value = '';
                }
            }

            function resetServicePaymentForm() {
                if (servicePaymentForm) servicePaymentForm.reset();
                const paymentSubmitButton = servicePaymentForm?.querySelector('button[type="submit"]');
                if (paymentSubmitButton) {
                    paymentSubmitButton.disabled = false;
                    paymentSubmitButton.textContent = 'Registrar cobro';
                }
                if (paymentClient) paymentClient.value = '';
                if (paymentOriginal) paymentOriginal.value = '';
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
                const paymentMonedaIdInput = document.getElementById('payment-moneda-id');
                if (paymentMonedaIdInput) paymentMonedaIdInput.value = '';
                if (partialPaymentField) partialPaymentField.classList.add('hidden');
                document.querySelectorAll('input[name="withholding_decision"]').forEach((radio) => { radio.checked = false; });
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
                const paymentDropzone = document.getElementById('payment-dropzone');
                if (paymentDropzone) paymentDropzone.classList.remove('is-invalid');
                const paymentDescription = document.getElementById('payment-description');
                if (paymentDescription) paymentDescription.value = '';
            }

            function closeServicePaymentModal() {
                if (!servicePaymentModal) return;
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
                if (event.target.matches('input[name="modo_pago"]')) {
                    const isPartial = event.target.value === 'parcial' && event.target.checked;
                    partialPaymentField?.classList.toggle('hidden', !isPartial);
                    if (paymentAmount) paymentAmount.required = isPartial;
                    if (event.target.value === 'total') {
                        if (paymentAmount) paymentAmount.value = getPaymentBaseAmount().toFixed(2);
                        updateBalanceAndCreditDisplay(getPaymentBaseAmount(), 0, 'payment-balance', 'payment-credit-summary', getCurrencySymbolFromText(paymentOriginal?.value || ''));
                    }
                }
                if (event.target.matches('input[name="payment_currency_mode"]')) {
                    const isOther = event.target.value === 'other' && event.target.checked;
                    paymentCurrencyExtra?.classList.toggle('hidden', !isOther);
                    if (!isOther) {
                        paymentConvertedTotalInput.value = getPaymentBaseAmount().toFixed(2);
                        paymentAmount.value = getPaymentBaseAmount().toFixed(2);
                        paymentBalance.textContent = formatMoney(0, getCurrencySymbolFromText(paymentOriginal?.value || ''));
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
                const total = Number(paymentOriginal?.value.replace(/[^0-9.-]+/g, '') || 0);
                const paid = Math.max(Number(paymentAmount.value || 0), 0);
                const currentSymbol = getCurrencySymbolFromText(paymentOriginal?.value || '');
                updateBalanceAndCreditDisplay(total, paid, 'payment-balance', 'payment-credit-summary', currentSymbol);
                syncWithholdingDecisionUI();
            });
            document.querySelectorAll('input[name="withholding_decision"]').forEach((radio) => {
                radio.addEventListener('change', function () {
                    syncWithholdingDecisionUI();
                });
            });
            paymentCurrencyModeInputs?.forEach(function (input) {
                input.addEventListener('change', function () {
                    const isOther = this.value === 'other' && this.checked;
                    paymentCurrencyExtra?.classList.toggle('hidden', !isOther);
                    if (isOther) {
                        syncPaymentCurrencyHidden();
                        updateConvertedPaymentAmount();
                    } else {
                        if (paymentMonedaIdInput) paymentMonedaIdInput.value = paymentMonedaIdInput.dataset.default || paymentMonedaIdInput.value || '';
                        if (paymentConvertedTotalInput) paymentConvertedTotalInput.value = getPaymentBaseAmount().toFixed(2);
                        if (paymentAmount) paymentAmount.value = getPaymentBaseAmount().toFixed(2);
                        if (paymentBalance) paymentBalance.textContent = formatMoney(0, 'S/');
                    }
                });
            });
            paymentCurrencySelect?.addEventListener('change', function () {
                syncPaymentCurrencyHidden();
            });
            paymentExchangeRateInput?.addEventListener('input', updateConvertedPaymentAmount);
            paymentConvertedAmountInput?.addEventListener('input', updateConvertedPaymentAmount);
            servicePaymentForm?.addEventListener('submit', function (event) {
                event.preventDefault();

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
                if (isTotalPayment && paymentAmount) {
                    const baseTotal = Number((paymentOriginal?.value || '').replace(/[^0-9.-]+/g, '') || 0);
                    paymentAmount.value = Number.isFinite(baseTotal) ? baseTotal.toFixed(2) : (paymentAmount.value || '0.00');
                }

                const grossValue = Number(String(paymentOriginal?.value || '').replace(/[^0-9.-]+/g, '') || 0);
                if (grossValue > 700) {
                    const selectedDecision = document.querySelector('input[name="withholding_decision"]:checked');
                    if (!selectedDecision) {
                        const decisionGroup = document.getElementById('payment-withholding-decision-group');
                        decisionGroup?.classList.remove('hidden');
                        alert('Confirma si la detracción/retención ya fue pagada antes de guardar.');
                        return;
                    }
                }

                const fileInput = document.getElementById('payment-comprobante');
                if (fileInput && !fileInput.files?.length) {
                    fileInput.setCustomValidity('Debes adjuntar el comprobante de pago.');
                    fileInput.reportValidity();
                    return;
                }
                if (fileInput) fileInput.setCustomValidity('');

                if (!servicePaymentForm.checkValidity()) {
                    servicePaymentForm.reportValidity();
                    return;
                }

                const submitButton = servicePaymentForm.querySelector('button[type="submit"]');
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.textContent = 'Guardando datos...';
                }

                servicePaymentForm.submit();
            });
            refreshServiceSelection();

            // ── Teleport: mover todos los modales al <body> para evitar clipping por overflow/transform ──
            document.querySelectorAll('.erp-modal-backdrop').forEach(function (modal) {
                document.body.appendChild(modal);
            });

            // ── Dar de baja: habilitar botón según checkboxes de cliente ──
            const btnDarDeBaja = document.getElementById('btn-dar-de-baja');
            let selectedBajaServiceIds = [];

            function refreshBajaBtn() {
                if (btnDarDeBaja) {
                    const count = document.querySelectorAll('.stair-check-client:checked, .stair-check-type:checked, .stair-check-item:checked').length;
                    btnDarDeBaja.disabled = count === 0;
                }
            }

            // Cascada: cliente → marca/desmarca todos los tipos e ítems de ese cliente
            document.addEventListener('change', function (e) {
                if (e.target.matches('.stair-check-client')) {
                    const clientId = e.target.dataset.clientId;
                    const state = e.target.checked;
                    document.querySelectorAll(`.stair-check-type[data-client-id="${clientId}"]`)
                        .forEach(function (cb) { cb.checked = state; });
                    document.querySelectorAll(`.stair-check-item[data-client-id="${clientId}"]`)
                        .forEach(function (cb) { cb.checked = state; });
                    refreshBajaBtn();
                }
                // Cascada: tipo → marca/desmarca ítems de ese mismo tipo
                if (e.target.matches('.stair-check-type')) {
                    const clientId = e.target.dataset.clientId;
                    const serviceIds = (e.target.dataset.serviceIds || '').split(',').filter(Boolean);
                    const state = e.target.checked;
                    document.querySelectorAll(`.stair-check-item[data-client-id="${clientId}"]`)
                        .forEach(function (cb) {
                            if (serviceIds.includes(String(cb.value))) cb.checked = state;
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
                    selectedBajaServiceIds = [];

                    document.querySelectorAll('.stair-check-client:checked').forEach(function (cb) {
                        const ids = (cb.dataset.serviceIds || '').split(',').filter(Boolean);
                        ids.forEach(function (id) {
                            if (!selectedBajaServiceIds.includes(id)) selectedBajaServiceIds.push(id);
                        });
                    });

                    document.querySelectorAll('.stair-check-type:checked').forEach(function (cb) {
                        const ids = (cb.dataset.serviceIds || '').split(',').filter(Boolean);
                        ids.forEach(function (id) {
                            if (!selectedBajaServiceIds.includes(id)) selectedBajaServiceIds.push(id);
                        });
                    });

                    document.querySelectorAll('.stair-check-item:checked').forEach(function (cb) {
                        const val = String(cb.value);
                        if (val && !selectedBajaServiceIds.includes(val)) {
                            selectedBajaServiceIds.push(val);
                        }
                    });

                    if (selectedBajaServiceIds.length === 0) {
                        alert('Selecciona al menos un servicio para dar de baja.');
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
                        alert('Por favor ingresa un comentario describiendo el motivo de la baja.');
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
            const facturaModal = document.getElementById('service-factura-modal');
            const facturaAdvanceToggle = document.getElementById('btn-factura-advance-toggle');
            const facturaAdvanceFields = document.getElementById('factura-advance-fields');
            const facturaAdvanceMonths = document.getElementById('factura-advance-months');
            const facturaAmountDisplay = document.getElementById('factura-amount');
            const facturaAmountCurrency = document.getElementById('factura-amount-currency');
            let facturaAmountWasEdited = false;
            let facturaManualUnitAmount = null;

            function isCreditPayment(select) {
                const option = select?.selectedOptions?.[0];
                return /credito|crédito/i.test(`${option?.textContent || ''} ${option?.value || ''}`);
            }

            function renderInstallments(container, count, amount, namePrefix) {
                if (!container) return;
                container.replaceChildren();
                const safeCount = Math.max(0, Math.min(Number(count) || 0, 60));
                if (!safeCount) return;

                const installmentAmount = amount > 0 ? (amount / safeCount).toFixed(2) : '0.00';
                const select = namePrefix === 'factura_cuotas' ? facturaPaymentMethod : paymentMethod;
                const creditDays = Number(select?.selectedOptions?.[0]?.dataset?.creditDays || 0);
                const firstDate = new Date();
                const formatDate = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
                for (let index = 1; index <= safeCount; index++) {
                    const dueDate = new Date(firstDate);
                    dueDate.setDate(firstDate.getDate() + (creditDays * (index - 1)));
                    const row = document.createElement('div');
                    row.className = 'mb-2';
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

            function syncCreditFields(select, fields, installmentsPanel, countInput, container, amount, namePrefix) {
                const credit = isCreditPayment(select);
                fields?.classList.toggle('hidden', !credit);
                installmentsPanel?.classList.toggle('hidden', !credit);
                if (!credit) {
                    if (countInput) countInput.value = '';
                    if (container) container.replaceChildren();
                    return;
                }
                const paymentModeName = namePrefix === 'factura_cuotas' ? 'factura_modo_pago' : 'modo_pago';
                const partialRadio = document.querySelector(`input[name="${paymentModeName}"][value="parcial"]`);
                if (partialRadio) {
                    partialRadio.checked = true;
                    partialRadio.dispatchEvent(new Event('change', { bubbles: true }));
                }
                renderInstallments(container, countInput?.value, amount(), namePrefix);

                const firstAmount = container?.querySelector(`input[name="${namePrefix}_montos[]"]`);
                const paidAmount = namePrefix === 'factura_cuotas'
                    ? document.getElementById('factura-partial-payment-amount')
                    : document.getElementById('payment-amount');
                if (firstAmount && paidAmount) {
                    paidAmount.value = firstAmount.value;
                }
                if (namePrefix === 'cuotas' && firstAmount) {
                    showPartialPaymentWithAmount(firstAmount.value);
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

            facturaPaymentMethod?.addEventListener('change', () => syncCreditFields(
                facturaPaymentMethod, facturaCreditFields, facturaCreditInstallments, facturaCanCuotas, facturaInstallments, facturaAmountValue, 'factura_cuotas'
            ));
            facturaCanCuotas?.addEventListener('input', () => {
                if (isCreditPayment(facturaPaymentMethod)) {
                    renderInstallments(facturaInstallments, facturaCanCuotas.value, facturaAmountValue(), 'factura_cuotas');
                    const firstAmount = facturaInstallments?.querySelector('input[name="factura_cuotas_montos[]"]');
                    const paidAmount = document.getElementById('factura-partial-payment-amount');
                    if (firstAmount && paidAmount) paidAmount.value = firstAmount.value;
                }
            });
            facturaInstallments?.addEventListener('input', (event) => {
                if (event.target.matches('input[name="factura_cuotas_montos[]"]') && event.target === facturaInstallments.querySelector('input[name="factura_cuotas_montos[]"]')) {
                    const paidAmount = document.getElementById('factura-partial-payment-amount');
                    if (paidAmount) paidAmount.value = event.target.value;
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
            paymentInstallments?.addEventListener('input', (event) => {
                if (event.target.matches('input[name="cuotas_montos[]"]') && event.target === paymentInstallments.querySelector('input[name="cuotas_montos[]"]')) {
                    const paidAmount = document.getElementById('payment-amount');
                    if (paidAmount) paidAmount.value = event.target.value;
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
                if (facturaCobroExtraSection) {
                    facturaCobroExtraSection.setAttribute('aria-hidden', isFacturarYCancelar ? 'false' : 'true');
                }
                if (facturaRealizarCobroInput) facturaRealizarCobroInput.value = isFacturarYCancelar ? '1' : '0';
                if (facturaModeSwitch) facturaModeSwitch.value = isFacturarYCancelar ? '1' : '0';
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
                if (paymentDescTextarea) paymentDescTextarea.required = isFacturarYCancelar;
                document.getElementById('factura-payment-date-field')?.classList.toggle('hidden', !isFacturarYCancelar);
                const facturaPeriodEnd = document.getElementById('factura-period-end');
                if (facturaPeriodEnd) facturaPeriodEnd.required = isFacturarYCancelar;
                updateFacturaWithholdingDecisionUI(isFacturarYCancelar);
            }

            function updateFacturaWithholdingDecisionUI(
                isFacturarYCancelar = facturaModeToggle?.dataset.mode === 'cancelar',
                grossAmount = null
            ) {
                const group = document.getElementById('factura-withholding-decision-group');
                const invoiceAmount = Number(
                    grossAmount ?? facturaModal?.dataset.grossAmount
                    ?? document.getElementById('factura-invoice-amount')?.value
                    ?? 0
                );
                const shouldShow = isFacturarYCancelar && invoiceAmount > 700;
                group?.classList.toggle('hidden', !shouldShow);
                if (!shouldShow) {
                    document.querySelectorAll('input[name="factura_withholding_decision"]')
                        .forEach((radio) => { radio.checked = false; });
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

            applyFacturaModeState(false);

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
                saldoFavorDisponible: 0
            };

            function resetVencidosState() {
                const btnPay = document.getElementById('btn-periodos-vencidos');
                const btnFactura = document.getElementById('btn-factura-periodos-vencidos');
                [btnPay, btnFactura].forEach((button) => {
                    if (!button) return;
                    button.disabled = false;
                    button.textContent = 'P. vencidos';
                });
                currentVencidosState.cxcs = [];
                currentVencidosState.baseCxcId = '';
                currentVencidosState.baseAmount = 0;
                currentVencidosState.clientId = '';
                currentVencidosState.serviceIds = [];
                currentVencidosState.selectedIds = [];
            }

            function calculateNetPayable(grossTotal, isRetencion) {
                grossTotal = Number(grossTotal) || 0;
                return Math.round((grossTotal - calculateDeduction(grossTotal, isRetencion) + Number.EPSILON) * 100) / 100;
            }

            function calculateDeduction(grossTotal, isRetencion) {
                grossTotal = Number(grossTotal) || 0;
                if (grossTotal <= 700) return 0;
                return Math.round((grossTotal * (isRetencion ? 0.03 : 0.12) + Number.EPSILON) * 100) / 100;
            }

            function renderDeductionSummary(elementId, grossTotal, deductionAmount, creditApplied, isRetencion, symbol) {
                const summary = document.getElementById(elementId);
                if (!summary) return;

                if (deductionAmount <= 0 && creditApplied <= 0) {
                    summary.textContent = '';
                    summary.classList.add('hidden');
                    return;
                }

                const parts = [`Total de la fila: ${formatMoney(grossTotal, symbol)}`];
                if (deductionAmount > 0) {
                    const deductionName = isRetencion ? 'Retención (3%)' : 'Detracción (12%)';
                    parts.push(`${deductionName}: ${formatMoney(deductionAmount, symbol)}`);
                }
                if (creditApplied > 0) parts.push(`Saldo a favor aplicado: ${formatMoney(creditApplied, symbol)}`);
                summary.textContent = parts.join(' | ');
                summary.classList.remove('hidden');
            }

            function applyDeductionLogic() {
                let selectedAmount = 0;
                currentVencidosState.selectedIds.forEach(id => {
                    const cxc = currentVencidosState.cxcs.find(c => String(c.idcuentasPorCobrar) === String(id));
                    if (cxc) {
                        selectedAmount += parseFloat(cxc.montoActual || 0);
                    }
                });

                let grossTotal = currentVencidosState.baseAmount + selectedAmount;
                const advancePeriods = currentVencidosState.targetModal === 'factura'
                    ? Number(facturaAdvanceMonths?.value || 0)
                    : 0;
                const invoiceUnitAmount = currentVencidosState.targetModal === 'factura'
                    && facturaAmountWasEdited
                    && facturaManualUnitAmount !== null
                    ? facturaManualUnitAmount
                    : currentVencidosState.baseAmount;
                grossTotal = selectedAmount + invoiceUnitAmount * Math.max(1, advancePeriods);
                const isRetencion = !!currentVencidosState.esRetencion;
                const deductionAmount = calculateDeduction(grossTotal, isRetencion);
                const afterDeduction = calculateNetPayable(grossTotal, isRetencion);
                const symbol = currentVencidosState.currencySymbol || 'S/';
                const useCreditCheckbox = document.getElementById(currentVencidosState.targetModal === 'pay' ? 'payment-use-credit' : 'factura-use-credit');
                const useCredit = !!useCreditCheckbox?.checked;
                const creditApplied = useCredit ? Math.min(afterDeduction, currentVencidosState.saldoFavorDisponible) : 0;
                const cashDue = Math.max(afterDeduction - creditApplied, 0);

                const formattedNet = `${symbol} ${cashDue.toFixed(2)}`;

                if (currentVencidosState.targetModal === 'pay') {
                    renderDeductionSummary('payment-amount-summary', grossTotal, deductionAmount, creditApplied, isRetencion, symbol);
                    renderCreditChoice('payment', currentVencidosState.saldoFavorDisponible, creditApplied, afterDeduction, symbol);
                    const paymentOriginal = document.getElementById('payment-original');
                    const paymentAmount = document.getElementById('payment-amount');
                    const totalRadio = document.querySelector('#service-payment-modal input[name="modo_pago"][value="total"]');
                    const expectedBefore = Number(servicePaymentModal?.dataset.expectedCashDue ?? currentVencidosState.baseAmount);
                    const enteredAmount = Number(paymentAmount?.value || 0);
                    const shouldRefreshAmount = totalRadio?.checked || !paymentAmount?.value || Math.abs(enteredAmount - expectedBefore) < 0.01;

                    if (paymentOriginal) paymentOriginal.value = formattedNet;
                    if (paymentAmount && shouldRefreshAmount) paymentAmount.value = cashDue.toFixed(2);
                    if (servicePaymentModal) servicePaymentModal.dataset.expectedCashDue = String(cashDue);
                    updateBalanceAndCreditDisplay(cashDue, paymentAmount?.value || 0, 'payment-balance', 'payment-credit-summary', symbol);
                } else if (currentVencidosState.targetModal === 'factura') {
                    if (facturaModal) facturaModal.dataset.grossAmount = String(grossTotal);
                    renderDeductionSummary('factura-amount-summary', grossTotal, deductionAmount, creditApplied, isRetencion, symbol);
                    renderCreditChoice('factura', currentVencidosState.saldoFavorDisponible, creditApplied, afterDeduction, symbol);
                    const facturaAmount = document.getElementById('factura-amount');
                    const totalRadio = document.querySelector('input[name="factura_modo_pago"][value="total"]');
                    const partialAmount = document.getElementById('factura-partial-payment-amount');
                    const expectedBefore = Number(facturaModal?.dataset.expectedCashDue ?? currentVencidosState.baseAmount);
                    const enteredAmount = Number(partialAmount?.value || 0);
                    const shouldRefreshAmount = totalRadio?.checked || !partialAmount?.value || Math.abs(enteredAmount - expectedBefore) < 0.01;

                    const manualAmount = facturaAmountWasEdited
                        ? Number(facturaAmountDisplay?.value || 0)
                        : null;
                    const visibleDue = advancePeriods > 1 || manualAmount === null ? cashDue : manualAmount;
                    if (facturaAmount) facturaAmount.value = visibleDue.toFixed(2);
                    const invoiceAmountInput = document.getElementById('factura-invoice-amount');
                    if (invoiceAmountInput) {
                        invoiceAmountInput.value = Math.max(0, invoiceUnitAmount * Math.max(1, advancePeriods)).toFixed(2);
                    }
                    if (facturaAmountCurrency) facturaAmountCurrency.textContent = symbol;
                    if (facturaModal) facturaModal.dataset.payableAmount = String(visibleDue);
                    if (partialAmount && (shouldRefreshAmount || manualAmount !== null)) partialAmount.value = visibleDue.toFixed(2);
                    if (facturaModal) facturaModal.dataset.expectedCashDue = String(cashDue);
                    updateBalanceAndCreditDisplay(
                        cashDue,
                        advancePeriods > 0 ? Math.min(Number(facturaAmount?.value || 0), cashDue) : (partialAmount?.value || 0),
                        'factura-payment-balance',
                        'factura-payment-credit-summary',
                        symbol
                    );
                    if (advancePeriods > 0) {
                        document.getElementById('factura-payment-credit-summary')?.classList.add('hidden');
                    }
                    updateFacturaWithholdingDecisionUI(undefined, grossTotal);
                }
            }

            function renderCreditChoice(prefix, available, applied, totalAfterDeduction, symbol) {
                const choice = document.getElementById(`${prefix}-credit-choice`);
                const checkbox = document.getElementById(`${prefix}-use-credit`);
                const availableLabel = document.getElementById(`${prefix}-credit-available`);
                const applicationLabel = document.getElementById(`${prefix}-credit-application`);
                if (!choice || !checkbox) return;

                choice.classList.toggle('hidden', available <= 0);
                checkbox.disabled = available <= 0;
                if (availableLabel) availableLabel.textContent = formatMoney(available, symbol);
                if (applicationLabel) {
                    applicationLabel.textContent = applied > 0
                        ? `Se aplicarán ${formatMoney(applied, symbol)}; quedarán ${formatMoney(Math.max(available - applied, 0), symbol)} disponibles.`
                        : `El saldo de ${formatMoney(available, symbol)} se conserva si no marcas esta opción.`;
                }
            }

            function syncFacturaAdvanceAmount() {
                const amount = Math.max(0, Number(facturaAmountDisplay?.value || 0));
                facturaAmountWasEdited = true;
                const selectedAmount = currentVencidosState.selectedIds.reduce((total, id) => {
                    const cxc = currentVencidosState.cxcs.find(item => String(item.idcuentasPorCobrar) === String(id));
                    return total + Number(cxc?.montoActual || 0);
                }, 0);
                const advancePeriods = Number(facturaAdvanceMonths?.value || 0);
                facturaManualUnitAmount = Math.max(0, amount - selectedAmount) / Math.max(1, advancePeriods);
                const invoiceAmountInput = document.getElementById('factura-invoice-amount');
                if (invoiceAmountInput) {
                    invoiceAmountInput.value = Math.max(0, amount - selectedAmount).toFixed(2);
                }
                const symbol = facturaModal?.dataset.currencySymbol || 'S/';
                if (facturaAmountCurrency) facturaAmountCurrency.textContent = symbol;
                if (facturaModal) facturaModal.dataset.payableAmount = String(amount);
                const partialAmount = document.getElementById('factura-partial-payment-amount');
                if (partialAmount) partialAmount.value = amount.toFixed(2);
                const totalRadio = document.querySelector('input[name="factura_modo_pago"][value="total"]');
                const partialRadio = document.querySelector('input[name="factura_modo_pago"][value="parcial"]');
                const partialField = document.getElementById('factura-partial-payment-field');
                if (advancePeriods > 0) {
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

            function syncFacturaAdvancePaymentMode() {
                const advancePeriods = Number(facturaAdvanceMonths?.value || 0);
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
                this.textContent = opening ? 'Ocultar meses' : 'Pagar adelantado';
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

            function checkAndRenderVencidosButton(clientId, currentCxcId, baseAmount, currencySymbol, targetModal, serviceIds = [], clientName = '') {
                resetVencidosState();
                const targetForm = document.getElementById(targetModal === 'pay' ? 'service-payment-form' : 'service-factura-form');
                targetForm?.querySelectorAll('input[name="cxc_ids[]"]').forEach((input) => input.remove());

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
                    esRetencion: false,
                    saldoFavorDisponible: 0
                };

                applyDeductionLogic();
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
                    currentVencidosState.cxcs = data.can_select_previous_debts === false ? [] : (data.cxcs || []);
                    applyDeductionLogic();

                    if (currentVencidosState.cxcs.length === 0) {
                        listContainer.textContent = 'Este CXC no tiene deudas anteriores pendientes.';
                        return;
                    }

                    listContainer.replaceChildren();
                    currentVencidosState.cxcs.forEach(c => {
                        const isChecked = currentVencidosState.selectedIds.includes(String(c.idcuentasPorCobrar));
                        const div = document.createElement('div');
                        div.className = 'flex items-center justify-between p-2.5 bg-white border border-slate-200 rounded-lg hover:border-slate-300 transition-all';
                        const label = document.createElement('label');
                        label.className = 'flex items-center gap-3 cursor-pointer flex-1';
                        const checkbox = document.createElement('input');
                        checkbox.type = 'checkbox';
                        checkbox.className = 'vencido-item-checkbox accent-[#b41B29] w-4 h-4 rounded';
                        checkbox.value = c.idcuentasPorCobrar;
                        checkbox.dataset.amount = c.montoActual;
                        checkbox.checked = isChecked;
                        const details = document.createElement('div');
                        details.className = 'text-xs';
                        const period = document.createElement('div');
                        period.className = 'font-bold text-slate-800';
                        period.textContent = `CXC #${c.idcuentasPorCobrar} - ${c.mes_display || c.descripcion || 'Periodo anterior'}`;
                        const paymentDate = document.createElement('div');
                        paymentDate.className = 'text-[11px] text-slate-500';
                        paymentDate.textContent = `Fecha de pago: ${c.fechaDisplay}`;
                        details.append(period, paymentDate);
                        label.append(checkbox, details);
                        const amount = document.createElement('span');
                        amount.className = 'text-xs font-bold text-slate-900';
                        amount.textContent = `${symbol} ${c.montoActualDisplay}`;
                        div.append(label, amount);
                        listContainer.appendChild(div);
                    });
                } catch (error) {
                    listContainer.textContent = 'No se pudieron cargar las deudas anteriores. Cierra e intenta nuevamente.';
                    return;
                }

                const updateSubmodalTotal = () => {
                    let total = 0;
                    const checked = listContainer.querySelectorAll('.vencido-item-checkbox:checked');
                    checked.forEach(cb => {
                        total += parseFloat(cb.dataset.amount || 0);
                    });
                    if (totalDisplay) totalDisplay.textContent = `${symbol} ${total.toFixed(2)}`;
                };

                listContainer.querySelectorAll('.vencido-item-checkbox').forEach(cb => {
                    cb.addEventListener('change', updateSubmodalTotal);
                });

                updateSubmodalTotal();
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

                currentVencidosState.selectedIds = selectedIds;

                const targetFormId = currentVencidosState.targetModal === 'pay' ? 'service-payment-form' : 'service-factura-form';
                const form = document.getElementById(targetFormId);
                if (form) {
                    form.querySelectorAll('input[name="cxc_ids[]"]').forEach(el => el.remove());
                    const allCxcIds = [currentVencidosState.baseCxcId, ...selectedIds].filter(Boolean);
                    allCxcIds.forEach(id => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'cxc_ids[]';
                        input.value = id;
                        form.appendChild(input);
                    });
                }

                applyDeductionLogic();
                closePeriodosModal();
            }

            document.getElementById('btn-periodos-vencidos')?.addEventListener('click', openPeriodosModal);
            document.getElementById('btn-factura-periodos-vencidos')?.addEventListener('click', openPeriodosModal);
            document.getElementById('btn-close-periodos-modal')?.addEventListener('click', closePeriodosModal);
            document.getElementById('btn-cancel-periodos-modal')?.addEventListener('click', closePeriodosModal);
            document.getElementById('btn-confirm-periodos')?.addEventListener('click', confirmPeriodosSelection);
            document.getElementById('payment-use-credit')?.addEventListener('change', applyDeductionLogic);
            document.getElementById('factura-use-credit')?.addEventListener('change', applyDeductionLogic);

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
                const amountSummary = document.getElementById('factura-amount-summary');
                amountSummary?.classList.add('hidden');
                if (amountSummary) amountSummary.textContent = '';
                const creditSummary = document.getElementById('factura-payment-credit-summary');
                creditSummary?.classList.add('hidden');
                if (creditSummary) creditSummary.textContent = '';
                const creditCheckbox = document.getElementById('factura-use-credit');
                if (creditCheckbox) creditCheckbox.checked = false;
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
                const invoiceAmountInput = document.getElementById('factura-invoice-amount');
                if (invoiceAmountInput) invoiceAmountInput.value = Number(amount || 0).toFixed(2);
                facturaAdvanceFields?.classList.add('hidden');
                if (facturaAdvanceToggle) {
                    facturaAdvanceToggle.textContent = 'Pagar adelantado';
                    facturaAdvanceToggle.setAttribute('aria-expanded', 'false');
                }
                facturaAmountWasEdited = false;
                facturaManualUnitAmount = null;
                if (facturaAmountCurrency) facturaAmountCurrency.textContent = resolvedCurrencySymbol || 'S/';
                if (facturaAdvanceMonths) {
                    facturaAdvanceMonths.value = '';
                    facturaAdvanceMonths.setCustomValidity('');
                    facturaAdvanceMonths.disabled = true;
                }

                if (facturaCxcId) facturaCxcId.value = cxcId || '';
                if (facturaServiceId) facturaServiceId.value = parsedServiceIds[0] || '';
                if (facturaClient) facturaClient.value = clientName || '-';
                if (facturaAmount) facturaAmount.value = Number(amount).toFixed(2);
                document.querySelectorAll('input[name="factura_withholding_decision"]')
                    .forEach((radio) => { radio.checked = false; });
                if (facturaDocRef) facturaDocRef.value = (currentDocRef && currentDocRef !== '-') ? currentDocRef : '';
                if (facturaFileInput) facturaFileInput.value = '';
                if (facturaFileName) facturaFileName.textContent = currentFile
                    ? 'Documento registrado. Selecciona uno nuevo para reemplazarlo.'
                    : '';

                if (facturaPaymentInput) facturaPaymentInput.value = '';
                if (facturaPaymentFileName) facturaPaymentFileName.textContent = '';
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
                    checkAndRenderVencidosButton(clientId, cxcId, amount, resolvedCurrencySymbol, 'factura', serviceIds, clientName);
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
                    if (this.value === 'other') {
                        const parcialRadio = document.querySelector('input[name="factura_modo_pago"][value="parcial"]');
                        if (parcialRadio) {
                            parcialRadio.checked = true;
                            parcialRadio.dispatchEvent(new Event('change'));
                        }
                    }
                });
            });

            document.querySelectorAll('input[name="factura_modo_pago"]').forEach(function (radio) {
                radio.addEventListener('change', function () {
                    const partialDiv = document.getElementById('factura-partial-payment-field');
                    if (partialDiv) {
                        partialDiv.classList.toggle('hidden', this.value !== 'parcial');
                    }
                    const partialAmount = document.getElementById('factura-partial-payment-amount');
                    if (this.value === 'parcial' && partialAmount && !partialAmount.value) {
                        partialAmount.value = Number(facturaModal?.dataset.payableAmount || 0).toFixed(2);
                    }
                    if (this.checked) applyDeductionLogic();
                });
            });

            const facturaPartialAmountInput = document.getElementById('factura-partial-payment-amount');
            facturaPartialAmountInput?.addEventListener('input', function () {
                const serviceFacturaModal = document.getElementById('service-factura-modal');
                const totalAmount = parseFloat(serviceFacturaModal?.dataset.payableAmount || serviceFacturaModal?.dataset.rawAmount || 0);
                const partialAmount = parseFloat(this.value || 0);
                const symbol = serviceFacturaModal?.dataset.currencySymbol || 'S/';
                updateBalanceAndCreditDisplay(totalAmount, partialAmount, 'factura-payment-balance', 'factura-payment-credit-summary', symbol);
            });

            function calcFacturaCurrencyConversion() {
                const exchangeRate = parseFloat(document.getElementById('factura-payment-exchange-rate')?.value || 0);
                const convertedAmount = parseFloat(document.getElementById('factura-payment-converted-amount')?.value || 0);
                const totalEl = document.getElementById('factura-payment-converted-total');
                const paidAmountEl = document.getElementById('factura-partial-payment-amount');
                const balanceEl = document.getElementById('factura-payment-balance');
                const totalAmount = parseFloat(facturaModal?.dataset.payableAmount || facturaModal?.dataset.rawAmount || 0);
                if (exchangeRate > 0 && convertedAmount > 0 && totalEl) {
                    const convertedTotal = (convertedAmount * exchangeRate).toFixed(2);
                    totalEl.value = convertedTotal;
                    if (paidAmountEl) paidAmountEl.value = convertedTotal;
                    updateBalanceAndCreditDisplay(totalAmount, convertedTotal, 'factura-payment-balance', 'factura-payment-credit-summary', facturaModal?.dataset.currencySymbol || 'S/');
                } else if (totalEl) {
                    totalEl.value = '';
                    if (paidAmountEl) paidAmountEl.value = '';
                    updateBalanceAndCreditDisplay(totalAmount, 0, 'factura-payment-balance', 'factura-payment-credit-summary', facturaModal?.dataset.currencySymbol || 'S/');
                }
            }
            document.getElementById('factura-payment-exchange-rate')?.addEventListener('input', calcFacturaCurrencyConversion);
            document.getElementById('factura-payment-converted-amount')?.addEventListener('input', calcFacturaCurrencyConversion);

            function handleFacturaFile(file) {
                if (!file || !facturaFileInput) return;
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
                const maxFileSize = 5 * 1024 * 1024;

                if (!allowedTypes.includes(file.type) || file.size > maxFileSize) {
                    facturaFileInput.value = '';
                    if (facturaFileName) facturaFileName.textContent = 'Selecciona JPG, PNG o PDF de máximo 5 MB.';
                    facturaUploadBox?.classList.add('is-invalid');
                    return;
                }

                const transfer = new DataTransfer();
                transfer.items.add(file);
                facturaFileInput.files = transfer.files;
                if (facturaFileName) facturaFileName.textContent = `${file.name} (${(file.size / 1024).toFixed(0)} KB)`;
                facturaUploadBox?.classList.remove('is-invalid');
            }

            function handleFacturaPaymentFile(file) {
                if (!file || !facturaPaymentInput) return;
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
                const maxFileSize = 5 * 1024 * 1024;

                if (!allowedTypes.includes(file.type) || file.size > maxFileSize) {
                    facturaPaymentInput.value = '';
                    if (facturaPaymentFileName) facturaPaymentFileName.textContent = 'Selecciona JPG, PNG o PDF de máximo 5 MB.';
                    facturaPaymentUploadBox?.classList.add('is-invalid');
                    return;
                }

                const transfer = new DataTransfer();
                transfer.items.add(file);
                facturaPaymentInput.files = transfer.files;
                if (facturaPaymentFileName) facturaPaymentFileName.textContent = `${file.name} (${(file.size / 1024).toFixed(0)} KB)`;
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
                    const isTargetInExtra = targetEl && targetEl.closest('#factura-cobro-extra-section');
                    const hasFacturaFile = facturaFileInput && facturaFileInput.files && facturaFileInput.files.length > 0;

                    if (isExpanded && (isTargetInExtra || hasFacturaFile)) {
                        handleFacturaPaymentFile(fileObj);
                    } else {
                        handleFacturaFile(fileObj);
                    }
                    return;
                }

                if (servicePaymentModal && servicePaymentModal.classList.contains('visible')) {
                    event.preventDefault();
                    const paymentFileInput = document.getElementById('payment-comprobante');
                    const paymentFileName = document.getElementById('payment-comprobante-name');
                    const paymentUploadBox = document.getElementById('payment-dropzone');
                    if (paymentFileInput) {
                        const transfer = new DataTransfer();
                        transfer.items.add(fileObj);
                        paymentFileInput.files = transfer.files;
                        if (paymentFileName) paymentFileName.textContent = `${fileObj.name} (${(fileObj.size / 1024).toFixed(0)} KB)`;
                        paymentUploadBox?.classList.remove('is-invalid');
                    }
                }
            });

            const serviceFacturaForm = document.getElementById('service-factura-form');
            if (serviceFacturaForm) {
                serviceFacturaForm.addEventListener('submit', function (event) {
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
                    }

                    if (isFacturarYCancelar) {
                        const invoiceAmount = Number(
                            facturaModal?.dataset.grossAmount
                            || document.getElementById('factura-invoice-amount')?.value
                            || 0
                        );
                        if (invoiceAmount > 700 && !document.querySelector('input[name="factura_withholding_decision"]:checked')) {
                            updateFacturaWithholdingDecisionUI(true);
                            alert('Confirma si la detracción/retención ya fue pagada antes de guardar.');
                            event.preventDefault();
                            return;
                        }

                        const paymentFile = document.getElementById('factura-comprobante');
                        if (paymentFile && !paymentFile.files?.length) {
                            paymentFile.setCustomValidity('Debes adjuntar el comprobante de pago.');
                            paymentFile.reportValidity();
                            event.preventDefault();
                            return;
                        }
                        if (paymentFile) paymentFile.setCustomValidity('');
                    }

                    if (facturaSubmitBtn) {
                        facturaSubmitBtn.disabled = true;
                        facturaSubmitBtn.textContent = isFacturarYCancelar ? 'Registrando cobro...' : 'Guardando factura...';
                    }
                });
            }

            function closeFacturaModal() {
                const serviceFacturaModal = document.getElementById('service-factura-modal');
                if (!serviceFacturaModal) return;
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
                facturaUploadBox?.classList.remove('is-invalid', 'is-dragging');
                facturaPaymentUploadBox?.classList.remove('is-invalid', 'is-dragging');
                document.getElementById('factura-payment-currency-extra')?.classList.add('hidden');
                document.getElementById('factura-partial-payment-field')?.classList.add('hidden');
                facturaAdvanceFields?.classList.add('hidden');
                facturaAmountWasEdited = false;
                facturaManualUnitAmount = null;
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
                    const servicePaymentModal = document.getElementById('service-payment-modal');
                    if (servicePaymentModal) {
                        servicePaymentModal.classList.remove('visible');
                        servicePaymentModal.classList.add('hidden');
                    }
                    document.body.style.overflow = '';
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
                        const isCancelado = cxcEstado === '3' || cxcEstado === 'CANCELADO' || cxcEstado === 'CANCELADO DETRACCIÓN';
                        const clientId = this.dataset.clientId || '';

                        const tipoCobroId = this.dataset.tipoCobroId || '';
                        if (isCancelado) {
                            openCustomPaymentModal(serviceIds, cxcId, clientName, amount, docRef, this.dataset.amountDisplay || '', this.dataset.monedaId || '', true, tipoCobroId, clientId, this.dataset.nextPeriodEnd || this.dataset.periodEnd || '', cxcIds, Number(this.dataset.advanceMonths || 0));
                        } else if (['1', '4', 'PENDIENTE', 'VENCIDO', 'PENDIENTE PAGO PARCIAL', 'PENDIENTE A CREDITO'].includes(cxcEstado) || !docRef) {
                            openFacturaModal(cxcId, clientName, amount, docRef, '', this.dataset.amountDisplay || '', this.dataset.monedaId || '', this.dataset.currencySymbol || '', serviceIds, tipoCobroId, clientId, this.dataset.nextPeriodEnd || this.dataset.periodEnd || '', cxcIds);
                        } else {
                            openCustomPaymentModal(serviceIds, cxcId, clientName, paymentAmount, docRef, this.dataset.amountDisplay || '', this.dataset.monedaId || '', false, tipoCobroId, clientId, this.dataset.nextPeriodEnd || this.dataset.periodEnd || '', cxcIds, Number(this.dataset.advanceMonths || 0));
                        }
                    });
                });

                root.querySelectorAll('.btn-revert-payment').forEach(btn => {
                    btn.addEventListener('click', function (e) {
                        e.stopPropagation();
                        const modal = document.getElementById('revert-payment-modal');
                        const form = document.getElementById('revert-payment-form');
                        const reason = document.getElementById('revert-payment-reason');
                        if (!modal || !form || !reason) return;

                        form.action = this.dataset.revertUrl || '';
                        reason.value = '';
                        modal.classList.remove('hidden');
                        modal.classList.add('visible');
                        document.body.style.overflow = 'hidden';
                        reason.focus();
                    });
                });

            }

            function closeRevertPaymentModal() {
                const modal = document.getElementById('revert-payment-modal');
                const form = document.getElementById('revert-payment-form');
                if (form) form.reset();
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

            function bindServiceResultPaymentActions(root) {
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
                        const isCancelado = cxcEstado === '3' || cxcEstado === 'CANCELADO' || cxcEstado === 'CANCELADO DETRACCIÓN';
                        const clientId = this.dataset.clientId || '';

                        const tipoCobroId = this.dataset.tipoCobroId || '';
                        if (isCancelado) {
                            openCustomPaymentModal(serviceIds, cxcId, clientName, amount, docRef, this.dataset.amountDisplay || '', this.dataset.monedaId || '', true, tipoCobroId, clientId, this.dataset.nextPeriodEnd || this.dataset.periodEnd || '', cxcIds, Number(this.dataset.advanceMonths || 0));
                        } else if (['1', '4', 'PENDIENTE', 'VENCIDO', 'PENDIENTE PAGO PARCIAL', 'PENDIENTE A CREDITO'].includes(cxcEstado) || !docRef) {
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
                        const cxcId = this.dataset.cxcId || '';
                        const cxcIds = (this.dataset.cxcIds || '').split(',').filter(Boolean);
                        const cxcEstado = (this.dataset.cxcEstado || 'PENDIENTE').toUpperCase();
                        const docRef = this.dataset.docRef || '';
                        const isCancelado = cxcEstado === '3' || cxcEstado === 'CANCELADO' || cxcEstado === 'CANCELADO DETRACCIÓN';
                        const clientId = this.dataset.clientId || '';

                        const tipoCobroId = this.dataset.tipoCobroId || '';
                        if (isCancelado) {
                            openCustomPaymentModal(serviceId ? [serviceId] : [], cxcId, clientName, amount, docRef, this.dataset.amountDisplay || '', this.dataset.monedaId || '', true, tipoCobroId, clientId, this.dataset.nextPeriodEnd || this.dataset.periodEnd || '', cxcIds, Number(this.dataset.advanceMonths || 0));
                        } else if (['1', '4', 'PENDIENTE', 'VENCIDO', 'PENDIENTE PAGO PARCIAL', 'PENDIENTE A CREDITO'].includes(cxcEstado) || !docRef) {
                            openFacturaModal(cxcId, clientName, amount, docRef, '', this.dataset.amountDisplay || '', this.dataset.monedaId || '', this.dataset.currencySymbol || '', serviceId ? [serviceId] : [], tipoCobroId, clientId, this.dataset.nextPeriodEnd || this.dataset.periodEnd || '', cxcIds);
                        } else if (serviceId) {
                            openCustomPaymentModal([serviceId], cxcId, clientName, amount, docRef, this.dataset.amountDisplay || '', this.dataset.monedaId || '', false, tipoCobroId, clientId, this.dataset.nextPeriodEnd || this.dataset.periodEnd || '', cxcIds, Number(this.dataset.advanceMonths || 0));
                        }
                    });
                });
            }

            bindServiceResultActions(document);
            bindServiceResultPaymentActions(document);

            const servicePriceModal = document.getElementById('service-price-modal');
            const servicePriceForm = document.getElementById('service-price-form');
            const servicePriceId = document.getElementById('service-price-id');
            const servicePriceAmount = document.getElementById('service-price-amount');
            const servicePriceContext = document.getElementById('service-price-context');
            const servicePriceError = document.getElementById('service-price-error');
            const servicePriceSave = document.getElementById('service-price-save');

            function closeServicePriceModal() {
                servicePriceForm?.reset();
                servicePriceError?.classList.add('hidden');
                if (servicePriceError) servicePriceError.textContent = '';
                servicePriceSave?.removeAttribute('disabled');
                if (servicePriceSave) servicePriceSave.innerHTML = '<i data-lucide="save" aria-hidden="true"></i> Guardar precio';
                servicePriceModal?.classList.remove('visible');
                servicePriceModal?.classList.add('hidden');
                document.body.style.overflow = '';
                window.lucide?.createIcons?.();
            }

            document.addEventListener('click', function (event) {
                const editButton = event.target.closest('.btn-edit-service-price');
                if (editButton) {
                    event.preventDefault();
                    event.stopPropagation();
                    if (servicePriceId) servicePriceId.value = editButton.dataset.serviceId || '';
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
            });

            servicePriceForm?.addEventListener('submit', async function (event) {
                event.preventDefault();
                if (!servicePriceForm.reportValidity()) return;

                const serviceId = servicePriceId?.value || '';
                const url = (servicePriceForm.dataset.urlTemplate || '').replace('__ID__', encodeURIComponent(serviceId));
                if (!serviceId || !url) return;

                servicePriceError?.classList.add('hidden');
                if (servicePriceSave) {
                    servicePriceSave.disabled = true;
                    servicePriceSave.textContent = 'Guardando...';
                }

                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        body: new FormData(servicePriceForm),
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const result = await response.json();
                    if (!response.ok || !result.success) {
                        throw new Error(result.message || 'No se pudo actualizar el precio.');
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
                    if (servicePriceSave) {
                        servicePriceSave.disabled = false;
                        servicePriceSave.innerHTML = '<i data-lucide="save" aria-hidden="true"></i> Guardar precio';
                    }
                    window.lucide?.createIcons?.();
                }
            });

            function openCustomPaymentModal(serviceIds, cxcId, clientName, amount, docRef = '', amountDisplay = '', monedaId = '', isViewOnly = false, tipoCobroId = '', clientId = '', periodEnd = '', cxcIds = [], advanceMonths = 0) {
                const servicePaymentModal = document.getElementById('service-payment-modal');
                if (!servicePaymentModal) return;
                const paymentAdvanceMonths = document.getElementById('payment-advance-months');
                const paymentAdvanceSummary = document.getElementById('payment-advance-summary');
                const hasAdvance = Number(advanceMonths) > 1;
                if (paymentAdvanceMonths) paymentAdvanceMonths.value = hasAdvance ? String(advanceMonths) : '';
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
                const resolvedCurrencySymbol = resolveCurrencySymbol(amountDisplay || '', monedaId || '');
                const normalizedAmountDisplay = `${resolvedCurrencySymbol || '$'} ${amount.toFixed(2)}`;
                if (paymentClient) paymentClient.value = clientName;
                if (paymentOriginal) paymentOriginal.value = normalizedAmountDisplay;
                servicePaymentModal.dataset.expectedCashDue = String(amount || 0);
                setPaymentType(tipoCobroId);
                if (paymentBalance) paymentBalance.textContent = formatMoney(amount, resolvedCurrencySymbol);
                const sameCurrencyMode = document.querySelector('#service-payment-modal input[name="payment_currency_mode"][value="same"]');
                if (sameCurrencyMode) sameCurrencyMode.checked = true;
                paymentCurrencyExtra?.classList.add('hidden');
                syncPaymentModeUI();

                const paymentCxcIdInput = document.getElementById('payment-cxc-id');
                if (paymentCxcIdInput) paymentCxcIdInput.value = cxcId || '';
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
                    paymentPeriodEnd.readOnly = isViewOnly;
                    paymentPeriodEnd.required = Boolean(periodEnd) && !isViewOnly;
                }
                if (paymentCanCuotas) paymentCanCuotas.value = '';
                paymentInstallments?.replaceChildren();
                paymentCreditFields?.classList.add('hidden');
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
                }

                if (paymentComprobanteInput) paymentComprobanteInput.value = '';
                if (paymentComprobanteName) paymentComprobanteName.textContent = '';
                paymentDropzone?.classList.remove('is-invalid');

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
                const modalTitle = servicePaymentModal.querySelector('h2, .modal-title, .font-medium');

                if (!isViewOnly && clientId) {
                    checkAndRenderVencidosButton(clientId, cxcId, amount, resolvedCurrencySymbol, 'pay', serviceIds, clientName);
                } else {
                    resetVencidosState();
                }

                if (isViewOnly) {
                    if (modalTitle) modalTitle.textContent = `Detalle del Cobro Realizado (${cxcEstado})`;
                    if (submitBtn) submitBtn.style.display = 'none';

                    if (cxcId) {
                        fetch('/modulos/cuentas-por-cobrar/' + cxcId + '/detalle')
                            .then(res => res.json())
                            .then(res => {
                                if (res.success && res.data) {
                                    const d = res.data;
                                    if (paymentClient) paymentClient.value = d.cliente_nombre || clientName;
                                    if (paymentReference) paymentReference.value = d.docReferencia || docRef || '-';
                                    const bankSelect = document.getElementById('payment-bank');
                                    if (bankSelect && d.bancos_idbancos) bankSelect.value = d.bancos_idbancos;
                                    const typeSelect = document.getElementById('payment-type');
                                    if (typeSelect && d.tipoCobro_idtipoCobros) typeSelect.value = d.tipoCobro_idtipoCobros;
                                    const methodSelect = document.getElementById('payment-method');
                                    if (methodSelect && d.formaPago_idformaPago) methodSelect.value = d.formaPago_idformaPago;
                                    const descTextarea = document.getElementById('payment-description');
                                    if (descTextarea) descTextarea.value = d.detalle_descripcion || d.cxc_descripcion || '';

                                    if (d.comprobante_archivo && paymentComprobanteName) {
                                        paymentComprobanteName.innerHTML = `<a href="/storage/${d.comprobante_archivo}" target="_blank" class="inline-flex items-center gap-1.5 font-bold text-red-700 bg-red-50 hover:bg-red-100 border border-red-200 rounded-md px-3 py-1.5 text-xs transition duration-200 mt-2"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg> Ver Comprobante de Pago Adjunto</a>`;
                                    }
                                }
                            })
                            .catch(() => { });
                    }
                } else {
                    if (modalTitle) modalTitle.textContent = 'Registrar cobro de servicios';
                    if (submitBtn) submitBtn.style.display = '';
                }

                servicePaymentModal.classList.remove('hidden');
                servicePaymentModal.classList.add('visible');
                document.body.style.overflow = 'hidden';
            }

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
                    paymentDropzone?.classList.add('is-invalid');
                    return;
                }

                const transfer = new DataTransfer();
                transfer.items.add(file);
                paymentComprobanteInput.files = transfer.files;

                if (paymentComprobanteName) {
                    paymentComprobanteName.textContent = `${file.name} (${(file.size / 1024).toFixed(0)} KB)`;
                }
                paymentDropzone?.classList.remove('is-invalid');
            }

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

            document.addEventListener('paste', function (e) {
                const servicePaymentModal = document.getElementById('service-payment-modal');
                if (!servicePaymentModal || !servicePaymentModal.classList.contains('visible')) return;
                const pastedItem = Array.from((e.clipboardData && e.clipboardData.items) || [])
                    .find(item => item.kind === 'file');
                if (pastedItem) {
                    e.preventDefault();
                    handlePaymentComprobanteFile(pastedItem.getAsFile());
                }
            });


            document.addEventListener('paste', function (e) {
                const serviceFacturaModal = document.getElementById('service-factura-modal');
                if (serviceFacturaModal && serviceFacturaModal.classList.contains('visible')) {
                    const pastedItem = Array.from((e.clipboardData && e.clipboardData.items) || [])
                        .find(item => item.kind === 'file');
                    if (pastedItem) {
                        e.preventDefault();
                        handleFacturaFile(pastedItem.getAsFile());
                    }
                }
            });

            function applyServicioSearch() {
                const clientFilter = (document.getElementById('service-filter-client')?.value || '').toLowerCase().trim();
                const selectedMonths = Array.from(serviceMonthInputs)
                    .filter((input) => input.checked)
                    .map((input) => input.value);
                const yearFilter = document.getElementById('service-filter-year')?.value || 'all';
                const documentFilter = (serviceDocumentInput?.value || '').toLowerCase().trim();
                const dateFilter = serviceDateInput?.value || '';
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
                    let matchesDate = dateFilter === '' || row.dataset.endDate === dateFilter;
                    let matchesStatus = statusFilter === '' || clientStatus === statusFilter;

                    const isVisible = matchesClient && matchesMonth && matchesYear && matchesDocument && matchesDate && matchesStatus;
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
                    { name: 'service_status', value: document.getElementById('service-filter-status')?.value },
                ]);
            }

            document.getElementById('service-filter-apply-btn')?.addEventListener('click', function () {
                applyServicioSearch();
                submitServiceFilters();
            });

            document.getElementById('service-filter-clear-btn')?.addEventListener('click', function () {
                const currentMonth = new Intl.DateTimeFormat('es', { month: 'long' }).format(new Date()).toLowerCase();
                const currentYear = String(new Date().getFullYear());
                if (serviceSearchInput) serviceSearchInput.value = '';
                if (serviceDocumentInput) serviceDocumentInput.value = '';
                if (serviceDateInput) serviceDateInput.value = '';
                serviceMonthInputs.forEach((input) => { input.checked = input.value === currentMonth; });
                const yearInput = document.getElementById('service-filter-year');
                const statusInput = document.getElementById('service-filter-status');
                if (yearInput) yearInput.value = currentYear;
                if (statusInput) statusInput.value = '';
                applyServicioSearch();
                submitServiceFilters();
            });

            document.getElementById('service-filter-form')?.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    applyServicioSearch();
                    submitServiceFilters();
                }
            });

            applyServicioSearch();

            function applyCotizacionFilters() {
                const q = (searchInput ? searchInput.value : '').toLowerCase().trim();
                const groupVal = (document.getElementById('quote-filter-group')?.value || '').toLowerCase().trim();
                const clientVal = (document.getElementById('quote-filter-client')?.value || '').toLowerCase().trim();
                const currencyVal = (document.getElementById('quote-filter-currency')?.value || '').toLowerCase().trim();
                const serviceVal = (document.getElementById('quote-filter-service')?.value || '').toLowerCase().trim();
                const statusVal = String(appliedQuoteStatus).toLowerCase().trim();
                const dateVal = document.getElementById('quote-filter-date')?.value || '';

                if (searchClearBtn) {
                    searchClearBtn.style.display = q !== '' ? 'flex' : 'none';
                }

                let activeFilterCount = 0;
                if (groupVal) activeFilterCount++;
                if (clientVal) activeFilterCount++;
                if (currencyVal) activeFilterCount++;
                if (serviceVal) activeFilterCount++;
                if (statusVal && statusVal !== '1') activeFilterCount++;
                if (dateVal) activeFilterCount++;

                if (filterBadge) {
                    filterBadge.textContent = String(activeFilterCount);
                    filterBadge.classList.toggle('hidden', activeFilterCount === 0);
                    filterBadge.classList.toggle('flex', activeFilterCount > 0);
                }

                const subtitleStatus = document.getElementById('quote-subtitle-status');
                if (subtitleStatus) {
                    if (statusVal === '2') {
                        subtitleStatus.textContent = 'Aprobado (Con pago)';
                    } else if (statusVal === 'todos') {
                        subtitleStatus.textContent = 'Todos los estados';
                    } else {
                        subtitleStatus.textContent = 'Aprobado(SP) - Pendiente';
                    }
                }

                let visibleRows = 0;
                document.querySelectorAll('.erp-quotations-table tbody tr').forEach(row => {
                    const text = row.textContent.toLowerCase();
                    const matchesQuery = q === '' || text.includes(q);

                    const groupCell = row.cells[7]?.textContent.toLowerCase().trim() || '';
                    const matchesGroup = groupVal === '' || groupCell.includes(groupVal);

                    const clientCell = row.cells[2]?.textContent.toLowerCase().trim() || '';
                    const matchesClient = clientVal === '' || clientCell.includes(clientVal);

                    const montoCell = row.cells[3]?.textContent.toLowerCase().trim() || '';
                    let matchesCurrency = true;
                    if (currencyVal === 'sol') matchesCurrency = montoCell.includes('s/') || montoCell.includes('sol');
                    else if (currencyVal === 'dolar') matchesCurrency = montoCell.includes('$') || montoCell.includes('usd') || montoCell.includes('dolar');
                    else if (currencyVal === 'euro') matchesCurrency = montoCell.includes('€') || montoCell.includes('eur');

                    const serviceCell = row.cells[8]?.textContent.toLowerCase().trim() || '';
                    const matchesService = serviceVal === '' || serviceCell === serviceVal;

                    const rowStatus = (row.dataset.estado || '1').trim();
                    let matchesStatus = true;
                    if (statusVal === '1') {
                        matchesStatus = (rowStatus === '1');
                    } else if (statusVal === '2') {
                        matchesStatus = (rowStatus === '2');
                    } else if (statusVal === 'todos') {
                        matchesStatus = true;
                    }

                    const fechaCell = row.cells[6]?.textContent.trim() || '';
                    const dateParts = fechaCell.split('/');
                    const rowDate = dateParts.length === 3
                        ? dateParts[2] + '-' + dateParts[1] + '-' + dateParts[0]
                        : '';
                    const matchesDate = dateVal === '' || rowDate === dateVal;

                    const isVisible = matchesQuery && matchesGroup && matchesClient && matchesCurrency && matchesService && matchesStatus && matchesDate;
                    row.style.display = isVisible ? '' : 'none';
                    if (isVisible) visibleRows++;
                });

                const noResults = document.getElementById('quote-no-results');
                if (noResults) {
                    noResults.style.display = visibleRows === 0 ? 'block' : 'none';
                }
            }

            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    applyCotizacionFilters();
                    clearTimeout(quoteSearchTimer);
                    quoteSearchTimer = setTimeout(submitQuoteFilters, 200);
                });
            }

            if (searchClearBtn) {
                searchClearBtn.addEventListener('click', function () {
                    if (searchInput) {
                        searchInput.value = '';
                    }
                    applyCotizacionFilters();
                    submitQuoteFilters();
                });
            }

            const statusSelect = document.getElementById('quote-filter-status');
            const applyFilterBtn = document.getElementById('quote-filter-apply-btn');
            if (applyFilterBtn) {
                applyFilterBtn.addEventListener('click', function () {
                    appliedQuoteStatus = statusSelect?.value || '1';
                    applyCotizacionFilters();
                    submitQuoteFilters({ updateQuoteStats: true });
                    const dropdownPanel = applyFilterBtn.closest('.erp-dropdown-panel');
                    if (dropdownPanel) {
                        dropdownPanel.classList.add('hidden');
                    }
                });
            }

            const clearFilterBtn = document.getElementById('quote-filter-clear-btn');
            if (clearFilterBtn) {
                clearFilterBtn.addEventListener('click', function () {
                    if (document.getElementById('quote-filter-group')) document.getElementById('quote-filter-group').value = '';
                    if (document.getElementById('quote-filter-client')) document.getElementById('quote-filter-client').value = '';
                    if (document.getElementById('quote-filter-currency')) document.getElementById('quote-filter-currency').value = '';
                    if (document.getElementById('quote-filter-service')) document.getElementById('quote-filter-service').value = '';
                    if (document.getElementById('quote-filter-status')) document.getElementById('quote-filter-status').value = '1';
                    if (document.getElementById('quote-filter-date')) document.getElementById('quote-filter-date').value = '';
                    appliedQuoteStatus = '1';
                    applyCotizacionFilters();
                    submitQuoteFilters({ updateQuoteStats: true });
                    const dropdownPanel = clearFilterBtn.closest('.erp-dropdown-panel');
                    if (dropdownPanel) {
                        dropdownPanel.classList.add('hidden');
                    }
                });
            }

            // Aplicar filtrado inicial (por defecto muestra estado 1 / pendiente)
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
                    if (loadedCxcTab !== button.dataset.tab) {
                        const params = new URLSearchParams(cxcQuery);
                        params.set('tab', button.dataset.tab);
                        loadCxcResults(`${window.location.pathname}?${params.toString()}`, button.dataset.tab);
                    }
                });
            });

            window.addEventListener('popstate', () => activateTab(getTabFromLocation()));
            activateTab(getTabFromLocation(), true);

            const quotePreviewModal = document.getElementById('erp-cotizacion-preview-modal');
            const quotePreviewFrame = document.getElementById('erp-cotizacion-preview-frame');
            const quotePreviewNumber = document.getElementById('erp-cotizacion-preview-number');
            const previewLoading = document.getElementById('preview-loading');

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
                if (event.key === 'Escape' && quotePreviewModal && quotePreviewModal.classList.contains('visible')) {
                    closeQuotePreview();
                }
            });

            const gestionModal = document.getElementById('erp-gestion-modal');
            const gestionFileInput = document.getElementById('erp-gestion-file');
            const gestionPreview = document.getElementById('erp-gestion-preview');
            const gestionTitle = document.getElementById('erp-gestion-title');
            const gestionUploadBox = document.getElementById('erp-gestion-upload-box');
            const gestionFileName = document.getElementById('erp-gestion-file-name');
            const gestionSaveButton = document.getElementById('erp-gestion-save');
            const gestionSavingStatus = document.getElementById('erp-gestion-saving-status');

            const gestionForm = document.getElementById('erp-gestion-form');

            const openGestionModal = function (number, approveUrl, clientName, amountDisplay, paymentFormat) {
                if (!gestionModal) {
                    return;
                }
                if (gestionSaveButton) {
                    gestionSaveButton.disabled = true;
                }
                if (gestionSavingStatus) gestionSavingStatus.classList.remove('is-visible');
                if (gestionForm) {
                    gestionForm.action = approveUrl || '';
                }

                const sectionTitle = document.getElementById('erp-gestion-section-title');
                if (sectionTitle) {
                    sectionTitle.textContent = 'DATOS DE COTIZACIÓN' + (number ? ': ' + number : '');
                }

                const clientInput = document.getElementById('erp-gestion-client');
                const amountInput = document.getElementById('erp-gestion-amount');
                const paymentSelect = document.getElementById('erp-gestion-payment-format');
                const dateInput = document.getElementById('erp-gestion-date');
                const descInput = document.getElementById('erp-gestion-description');

                if (clientInput) clientInput.value = clientName || '-';
                if (amountInput) amountInput.value = amountDisplay || '-';
                if (paymentSelect) {
                    const normalizedPayment = String(paymentFormat || '')
                        .toLowerCase()
                        .normalize('NFD')
                        .replace(/[\u0300-\u036f]/g, '');
                    paymentSelect.value = normalizedPayment.includes('contado')
                        ? 'contado'
                        : (normalizedPayment.includes('credito') ? 'credito' : '');
                }

                const now = new Date();
                if (dateInput) {
                    const yyyy = now.getFullYear();
                    const mm = String(now.getMonth() + 1).padStart(2, '0');
                    const dd = String(now.getDate()).padStart(2, '0');
                    dateInput.value = `${yyyy}-${mm}-${dd}`;
                }
                if (descInput) descInput.value = '';

                gestionModal.classList.add('visible');
                document.body.style.overflow = 'hidden';
            };

            const closeGestionModal = function () {
                if (!gestionModal) {
                    return;
                }
                gestionModal.classList.remove('visible');
                if (gestionFileInput) {
                    gestionFileInput.value = '';
                }
                if (gestionSaveButton) {
                    gestionSaveButton.disabled = true;
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

                if (gestionSavingStatus) gestionSavingStatus.classList.remove('is-visible');
                document.body.style.overflow = '';
            };

            document.addEventListener('click', function (event) {
                const gestionButton = event.target.closest('.erp-btn-gestion');
                if (gestionButton) {
                    openGestionModal(
                        gestionButton.dataset.cotizacionNumber || '',
                        gestionButton.dataset.cotizacionApproveUrl || '',
                        gestionButton.dataset.cotizacionClient || '',
                        gestionButton.dataset.cotizacionAmount || '',
                        gestionButton.dataset.cotizacionPayment || ''
                    );
                    return;
                }

                if (event.target.closest('[data-erp-close-gestion]')) {
                    closeGestionModal();
                }
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
                        gestionSaveButton.disabled = true;
                    }
                    gestionPreview.classList.remove('visible');
                    gestionPreview.src = '';
                    if (gestionFileName) {
                        gestionFileName.textContent = 'Selecciona un JPG, PNG o PDF de máximo 5 MB.';
                    }
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
                if (event.key === 'Escape' && gestionModal && gestionModal.classList.contains('visible')) {
                    closeGestionModal();
                }
            });

            if (gestionForm) {
                gestionForm.addEventListener('submit', function () {
                    if (gestionSaveButton) {
                        gestionSaveButton.disabled = true;
                    }
                    if (gestionSavingStatus) gestionSavingStatus.classList.add('is-visible');
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
                            <span class="erp-factura-mode-option is-active" data-mode="facturar">Factura</span>
                            <span class="erp-factura-mode-option" data-mode="cancelar">Cancelar</span>
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
                                <div>
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">Cliente</label>
                                    <input id="factura-client"
                                        class="w-full rounded-lg border border-slate-200 bg-slate-50 p-2.5 text-sm font-medium text-slate-800 outline-none"
                                        readonly>
                                </div>
                                <div>
                                    <div
                                        style="display: flex; justify-content: space-between; align-items: center; width: 100%; margin-bottom: 4px;">
                                        <label class="block text-xs font-semibold text-slate-600">Monto a cancelar</label>
                                        <button type="button" id="btn-factura-periodos-vencidos"
                                            class="px-2 py-0.5 text-xs font-bold text-white rounded transition-all hover:opacity-90 shadow-sm"
                                            style="background-color: #b41B29;">
                                            P. vencidos
                                        </button>
                                    </div>
                                    <div class="flex min-w-0 items-stretch gap-2">
                                        <div
                                            class="flex min-w-0 flex-1 items-center rounded-lg border border-slate-300 bg-white px-2.5 focus-within:border-red-600 focus-within:ring-2 focus-within:ring-red-600/20">
                                            <span id="factura-amount-currency"
                                                class="mr-1.5 shrink-0 text-sm font-bold text-slate-700">S/</span>
                                            <input id="factura-amount" type="number" min="0.01" step="0.01"
                                                class="w-full min-w-0 border-0 bg-transparent p-2 text-sm font-bold text-slate-900 outline-none focus:border-0 focus:ring-0">
                                        </div>
                                        <button type="button" id="btn-factura-advance-toggle"
                                            class="shrink-0 rounded-md px-2.5 py-1 text-xs font-bold text-white transition-all hover:opacity-90 shadow-sm"
                                            style="background-color: #334155;" aria-expanded="false">
                                            Pagar adelantado
                                        </button>
                                    </div>
                                    <div id="factura-advance-fields" class="hidden mt-2">
                                        <label for="factura-advance-months"
                                            class="text-xs font-semibold text-slate-600">Mensualidades cubiertas
                                            <input id="factura-advance-months" name="adelanto_meses" type="number" min="1"
                                                max="120" step="1"
                                                class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-sm text-slate-900 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                                        </label>
                                        <p class="mt-1 text-[10px] text-slate-500">El número incluye el periodo actual.
                                            El total quedará asociado a la factura y se reconocerá al registrar el cobro.</p>
                                    </div>
                                    <p id="factura-amount-summary" class="hidden mt-1 text-xs font-medium text-slate-600"
                                        aria-live="polite"></p>
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
                                <label class="block text-xs font-bold text-slate-800 mb-1.5">
                                    Archivo de Factura <span>*</span>
                                </label>
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
                                    <input id="factura-file" name="archivoFactura" type="file" required
                                        accept="image/jpeg,image/png,application/pdf">
                                </label>
                            </div>
                            <div>
                                <label for="factura-description"
                                    class="block text-xs font-semibold text-slate-600 mb-1">Descripción / Observación (max.
                                    50 caracteres)
                                    <span class="text-red-500">*</span></label>
                                <textarea id="factura-description" name="descripcionFactura" rows="2" maxlength="50"
                                    required placeholder="Describe la factura o boleta..."
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
                                </div>
                            </div>

                            <!-- OPCIÓN TIPO DE MONEDA -->
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1.5">Tipo de moneda <span
                                        class="text-red-500">*</span></label>
                                <div class="flex items-center gap-4 bg-white mb-1.5">
                                    <label
                                        class="inline-flex items-center gap-2 cursor-pointer text-xs font-medium text-slate-700">
                                        <input type="radio" name="factura_payment_currency_mode" value="same" checked
                                            class="accent-[#b41B29]">
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

                            <!-- CAMPOS EXTRA PARA OTRA MONEDA -->
                            <div id="factura-payment-currency-extra" class="hidden space-y-2">
                                <div class="grid grid-cols-3 gap-2">
                                    <div>
                                        <label for="factura-payment-currency"
                                            class="block text-xs font-semibold text-slate-700 mb-1">Moneda</label>
                                        <select id="factura-payment-currency" name="moneda_idmoneda"
                                            class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                                            <option value="">Selecciona</option>
                                            @foreach($monedasOptions as $option)
                                                <option value="{{ $option['value'] }}" data-symbol="{{ $option['simbolo'] }}">
                                                    {{ $option['label'] }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label for="factura-payment-exchange-rate"
                                            class="block text-xs font-semibold text-slate-700 mb-1">Tipo de Cambio</label>
                                        <input id="factura-payment-exchange-rate" name="tipo_cambio" type="number"
                                            min="0.0001" step="0.0001"
                                            class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                                    </div>
                                    <div>
                                        <label for="factura-payment-converted-amount"
                                            class="block text-xs font-semibold text-slate-700 mb-1">Monto Cancelado</label>
                                        <input id="factura-payment-converted-amount" name="monto_cancelado" type="number"
                                            min="0.01" step="0.01"
                                            class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                                    </div>
                                </div>
                                <div>
                                    <label for="factura-payment-converted-total"
                                        class="block text-xs font-semibold text-slate-700 mb-1">Monto convertido</label>
                                    <input id="factura-payment-converted-total" name="precio_convertido" type="number"
                                        readonly
                                        class="w-full rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs font-bold text-slate-900 outline-none">
                                </div>
                            </div>

                            <!-- OPCIÓN TIPO DE PAGO (TOTAL / PARCIAL) -->
                            <div>
                                <span class="block text-xs font-semibold text-slate-700 mb-1.5">Tipo de pago</span>
                                <div class="flex items-center gap-6 mb-1.5">
                                    <label
                                        class="inline-flex items-center gap-2 cursor-pointer text-xs font-medium text-slate-700">
                                        <input type="radio" name="factura_modo_pago" value="total" class="accent-[#b41B29]">
                                        Pago total
                                    </label>
                                    <label
                                        class="inline-flex items-center gap-2 cursor-pointer text-xs font-medium text-slate-700">
                                        <input type="radio" name="factura_modo_pago" value="parcial" checked
                                            class="accent-[#b41B29]">
                                        Pago parcial
                                    </label>
                                </div>
                            </div>

                            <!-- MONTO DE PAGO PARCIAL -->
                            <div id="factura-partial-payment-field" class="hidden">
                                <label for="factura-partial-payment-amount"
                                    class="block text-xs font-semibold text-slate-700 mb-1">Monto actual pagado</label>
                                <input id="factura-partial-payment-amount" name="montoPago" type="number" step="0.01"
                                    class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                                <div class="flex items-center gap-2 mt-1">
                                    <p class="text-xs text-slate-500 mt-1">Saldo restante: <strong
                                            id="factura-payment-balance">S/ 0.00</strong></p>
                                    <p id="factura-payment-credit-summary"
                                        class="hidden text-xs font-semibold text-emerald-700 mt-1" aria-live="polite"></p>
                                </div>
                            </div>
                            <div id="factura-credit-choice"
                                class="hidden rounded-md border border-emerald-100 bg-emerald-50/60 p-2">
                                <label for="factura-use-credit"
                                    class="flex cursor-pointer items-center gap-2 text-xs font-semibold text-slate-700">
                                    <input id="factura-use-credit" name="usar_saldo_favor" type="checkbox" value="1">
                                    <span>Usar saldo a favor disponible</span>
                                    <strong id="factura-credit-available" class="ml-auto text-emerald-700"></strong>
                                </label>
                                <p id="factura-credit-application" class="mt-1 text-xs text-slate-500"></p>
                            </div>
                            <div id="factura-withholding-decision-group"
                                class="hidden rounded-lg border border-amber-200 bg-amber-50/70 p-3">
                                <p class="mb-2 text-xs font-bold uppercase tracking-wide text-amber-800">
                                    Confirma la detracción/retención
                                </p>
                                <p class="mb-2 text-xs text-slate-600">
                                    Si el cliente pagó el bruto completo y la detracción ya está pagada, el excedente quedará como saldo a favor.
                                    Si aún no la pagó pero entregó el bruto, elige la tercera opción.
                                </p>
                                <div class="flex flex-col gap-2">
                                    <label class="inline-flex items-center gap-2 cursor-pointer text-xs font-medium text-slate-700">
                                        <input type="radio" name="factura_withholding_decision" value="paid"
                                            class="accent-[#b41B29]">
                                        Sí, ya pagó la detracción/retención.
                                    </label>
                                    <label class="inline-flex items-center gap-2 cursor-pointer text-xs font-medium text-slate-700">
                                        <input type="radio" name="factura_withholding_decision" value="pending"
                                            class="accent-[#b41B29]">
                                        No, aún falta ese monto.
                                    </label>
                                    <label class="inline-flex items-center gap-2 cursor-pointer text-xs font-medium text-slate-700">
                                        <input type="radio" name="factura_withholding_decision" value="full_pending"
                                            class="accent-[#b41B29]">
                                        No, pero el cliente pagó el total y lo gestionará luego.
                                    </label>
                                </div>
                            </div>

                            <!-- COMPROBANTE DE PAGO (DROPZONE CON EL MISMO ESTILO Y COLOR DE MODAL 2) -->
                            <div class="mb-1.5">
                                <label class="block text-xs font-bold text-slate-800 mb-1.5">Comprobante de pago <span
                                        class="text-red-500">*</span></label>
                                <label id="factura-payment-upload-box" for="factura-comprobante"
                                    class="erp-upload-box factura-upload-box">
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
                                    <span id="factura-payment-file-name" class="erp-upload-file-name"
                                        aria-live="polite"></span>
                                    <input id="factura-comprobante" name="comprobante" type="file"
                                        accept="image/jpeg,image/png,application/pdf">
                                </label>
                            </div>
                            <div>
                                <label for="payment-description"
                                    class="block text-xs font-semibold text-slate-600 mb-1">Descripción / Observación (max.
                                    50 caracteres)<span class="text-red-500">*</span></label>
                                <textarea id="payment-description" name="descripcionPago" maxlength="50" rows="2" required
                                    placeholder="Observaciones del pago..."
                                    class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20"></textarea>
                            </div>

                        </div>

                    </div>

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

    <!-- MODAL 2: REGISTRAR COBRO DE SERVICIOS (ESTADO FACTURADO / VENCIDO CON DROPZONE IMAGEN 3) -->
    <div id="service-payment-modal" class="erp-modal-backdrop hidden" role="dialog" aria-modal="true"
        aria-labelledby="service-payment-title">
        <div class="erp-modal-card" style="max-width: 600px; border-radius: 16px;">
            <div class="erp-modal-header">
                <h3 id="service-payment-title" class="font-bold text-slate-900 text-lg">Registrar cobro de servicios</h3>
                <button type="button" class="erp-modal-close" data-close-service-payment aria-label="Cerrar">×</button>
            </div>
            <form method="POST" action="{{ route('modules.cuentasporcobrar.cobrar-servicios') }}" id="service-payment-form"
                enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="idcuentasPorCobrar" id="payment-cxc-id">
                <input type="hidden" name="moneda_idmoneda" id="payment-moneda-id">
                <input type="hidden" name="adelanto_meses" id="payment-advance-months">
                <div id="selected-service-inputs"></div>
                <div id="payment-selected-cxc-inputs"></div>
                <div class="erp-modal-body p-6">
                    <div class="mb-3 grid grid-cols-1 gap-2 border-b border-slate-100 pb-3 md:grid-cols-2">
                        <label for="payment-date" class="block text-xs font-semibold text-slate-600">Fecha de Pago
                            <input id="payment-date" name="fechaPago" type="date" value="{{ now()->format('Y-m-d') }}"
                                class="mt-1 w-full rounded-lg border border-slate-200 bg-white p-2 text-sm text-slate-700 outline-none">
                        </label>
                        <label for="payment-period-end" class="block text-xs font-semibold text-slate-600">Fecha Fin del
                            periodo
                            <input id="payment-period-end" name="fechaFin" type="date"
                                class="mt-1 w-full rounded-lg border border-slate-200 bg-white p-2 text-sm text-slate-700 outline-none">
                        </label>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                        <div>
                            <label for="payment-client"
                                class="block text-xs font-semibold text-slate-600 mb-1">Cliente</label>
                            <input id="payment-client"
                                class="w-full rounded-lg border border-slate-200 bg-slate-50 p-2 text-sm font-medium text-slate-800 outline-none"
                                readonly>
                        </div>
                        <input type="hidden" id="payment-type" name="tipoCobro_idtipoCobros">
                        <div>
                            <label for="payment-method" class="block text-xs font-semibold text-slate-700 mb-1">Forma de
                                pago <span class="text-red-500">*</span></label>
                            <select id="payment-method" name="formaPago_idformaPago" required
                                class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                                <option value="">Selecciona</option>
                                @foreach($formaPagoOptions as $option)
                                    <option value="{{ $option['value'] }}" data-credit-days="{{ $option['credit_days'] ?? 0 }}"
                                        @selected(str_contains(mb_strtolower($option['label'], 'UTF-8'), 'contado'))>
                                        {{ $option['label'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div id="payment-credit-fields" class="hidden">
                            <label for="payment-can-cuotas" class="block text-xs font-semibold text-slate-700 mb-1">Cantidad
                                de cuotas</label>
                            <input id="payment-can-cuotas" name="canCuotas" type="number" min="1" step="1" value=""
                                class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                        </div>
                        <div id="payment-credit-installments"
                            class="hidden md:col-span-2 rounded-lg border border-red-100 bg-red-50/40 p-2.5">
                            <div id="payment-installments" class="space-y-2"></div>
                        </div>
                        <div>
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
                        <div>
                            <label for="payment-reference" class="block text-xs font-bold text-slate-700 mb-1">Documento de
                                referencia (Factura / Boleta)</label>
                            <input id="payment-reference" name="docReferencia"
                                class="w-full rounded-lg border border-slate-200 bg-slate-100 p-2 text-sm font-bold text-slate-800 outline-none"
                                readonly>
                        </div>
                        <div>
                            <div
                                style="display: flex; justify-content: space-between; align-items: center; width: 100%; margin-bottom: 4px;">
                                <label for="payment-original" class="block text-xs font-semibold text-slate-600">Monto a
                                    cancelar</label>
                                <button type="button" id="btn-periodos-vencidos"
                                    class="px-2 py-0.5 text-xs font-bold text-white rounded transition-all hover:opacity-90 shadow-sm"
                                    style="background-color: #b41B29;">
                                    P. vencidos
                                </button>
                            </div>
                            <input id="payment-original"
                                class="w-full rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs font-bold text-slate-900 outline-none"
                                readonly>
                            <p id="payment-advance-summary" class="hidden mt-1 text-xs font-semibold text-emerald-700"
                                aria-live="polite"></p>
                            <p id="payment-amount-summary" class="hidden mt-1 text-xs font-medium text-slate-600"
                                aria-live="polite"></p>
                            <div id="payment-credit-choice"
                                class="hidden mt-2 rounded-md border border-emerald-100 bg-emerald-50/60 p-2">
                                <label for="payment-use-credit"
                                    class="flex cursor-pointer items-center gap-2 text-xs font-semibold text-slate-700">
                                    <input id="payment-use-credit" name="usar_saldo_favor" type="checkbox" value="1">
                                    <span>Usar saldo a favor disponible</span>
                                    <strong id="payment-credit-available" class="ml-auto text-emerald-700"></strong>
                                </label>
                                <p id="payment-credit-application" class="mt-1 text-xs text-slate-500"></p>
                            </div>
                        </div>
                        <div id="payment-withholding-decision-group"
                            class="md:col-span-2 hidden rounded-lg border border-amber-200 bg-amber-50/70 p-3">
                            <p class="mb-2 text-xs font-bold uppercase tracking-wide text-amber-800">Confirma la
                                detracción/retención</p>
                            <p class="mb-2 text-xs text-slate-600">
                                Si el cliente pagó el bruto completo y la detracción ya está pagada, el excedente quedará como saldo a favor.
                                Si aún no la pagó pero entregó el bruto, elige la tercera opción.
                            </p>
                            <div class="flex flex-col gap-2 md:flex-row md:items-center">
                                <label
                                    class="inline-flex items-center gap-2 cursor-pointer text-xs font-medium text-slate-700">
                                    <input type="radio" name="withholding_decision" value="paid" class="accent-[#b41B29]">
                                    Sí, ya pagó la detracción/retención.
                                </label>
                                <label
                                    class="inline-flex items-center gap-2 cursor-pointer text-xs font-medium text-slate-700">
                                    <input type="radio" name="withholding_decision" value="pending"
                                        class="accent-[#b41B29]">
                                    No, aún falta ese monto.
                                </label>
                                <label
                                    class="inline-flex items-center gap-2 cursor-pointer text-xs font-medium text-slate-700">
                                    <input type="radio" name="withholding_decision" value="full_pending"
                                        class="accent-[#b41B29]">
                                    No, pero el cliente pagó el total y lo gestionará luego.
                                </label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-2">Tipo de moneda</label>
                            <div class="flex items-center gap-4 bg-white ">
                                <label
                                    class="inline-flex items-center gap-2 cursor-pointer text-xs font-medium text-slate-700">
                                    <input type="radio" name="payment_currency_mode" value="same" checked
                                        class="accent-[#b41B29]">
                                    Misma moneda
                                </label>
                                <label
                                    class="inline-flex items-center gap-2 cursor-pointer text-xs font-medium text-slate-700">
                                    <input type="radio" name="payment_currency_mode" value="other" class="accent-[#b41B29]">
                                    Otra moneda
                                </label>
                            </div>
                        </div>
                        <div id="payment-currency-extra" class="md:col-span-2 hidden">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                                <div>
                                    <label for="payment-currency"
                                        class="block text-xs font-semibold text-slate-700 mb-1">Tipo de moneda</label>
                                    <select id="payment-currency" name="currency_selector"
                                        class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                                        <option value="">Selecciona</option>
                                        @foreach($monedasOptions as $option)
                                            <option value="{{ $option['value'] }}" data-symbol="{{ $option['simbolo'] }}">
                                                {{ $option['label'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="payment-exchange-rate"
                                        class="block text-xs font-semibold text-slate-700 mb-1">Tipo de cambio</label>
                                    <input id="payment-exchange-rate" name="tipo_cambio" type="number" step="0.0001"
                                        class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                                </div>
                                <div>
                                    <label for="payment-converted-amount"
                                        class="block text-xs font-semibold text-slate-700 mb-1">Monto cancelado</label>
                                    <input id="payment-converted-amount" name="monto_cancelado" type="number" step="0.01"
                                        class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                                </div>
                            </div>
                            <div class="mt-2">
                                <label for="payment-converted-total"
                                    class="block text-xs font-semibold text-slate-700 mb-1">Monto convertido</label>
                                <input id="payment-converted-total" name="precio_convertido" type="number" readonly
                                    class="w-full rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs font-bold text-slate-900 outline-none">
                            </div>
                        </div>
                        <div class="md:col-span-2">
                            <span class="block text-xs font-semibold text-slate-700 mb-1">Tipo de pago</span>
                            <div class="flex items-center gap-6">
                                <label
                                    class="inline-flex items-center gap-2 cursor-pointer text-xs font-medium text-slate-700">
                                    <input type="radio" name="modo_pago" value="total" class="accent-[#b41B29]">
                                    Pago total
                                </label>
                                <label
                                    class="inline-flex items-center gap-2 cursor-pointer text-xs font-medium text-slate-700">
                                    <input type="radio" name="modo_pago" value="parcial" checked class="accent-[#b41B29]">
                                    Pago
                                    parcial
                                </label>
                            </div>
                        </div>
                        <div id="partial-payment-field" class="md:col-span-2 hidden">
                            <label for="payment-amount" class="block text-xs font-semibold text-slate-700 mb-1">Monto actual
                                pagado</label>
                            <input id="payment-amount" name="montoPago" type="number" step="0.01"
                                class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20">
                            <div class="flex items-center gap-2 mt-1">
                                <p class="text-xs text-slate-500 mt-1">Saldo restante: 
                                    <strong id="payment-balance">S/0.00</strong>
                                </p>
                                <p id="payment-credit-summary" class="hidden text-xs font-semibold text-emerald-700 mt-1" aria-live="polite">
                                </p>
                            </div>
                        </div>
                        <div>
                            <label for="payment-description"
                                class="block text-xs font-semibold text-slate-700 mb-1">Descripción / Observación (max. 50
                                caracteres)<span class="text-red-500">*</span></label>
                            <textarea id="payment-description" name="descripcion" maxlength="50" rows="2" required
                                placeholder="Observaciones opcionales del pago..."
                                class="w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 outline-none focus:border-red-600 focus:ring-2 focus:ring-red-600/20"
                                style="height: 120px;"></textarea>
                        </div>

                        <!-- COMPROBANTE DE PAGO (DROPZONE CON ESTILO UNIFICADO A IMAGEN 2) -->
                        <div>
                            <label class="block text-xs font-bold text-slate-800 mb-1.5">Comprobante de pago</label>
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
                                <input id="payment-comprobante" name="comprobante" type="file" required
                                    accept="image/jpeg,image/png,application/pdf">
                            </label>
                        </div>
                    </div>
                    <div class="flex items-center justify-end gap-3 mt-3 border-t border-slate-100">
                        <button type="button"
                            class="px-4 py-2 text-sm font-semibold rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-100 transition-colors"
                            data-close-service-payment>Cancelar</button>
                        <button type="submit"
                            class="px-5 py-2 text-sm font-bold text-white rounded-lg shadow-sm transition-all"
                            style="background-color: #b41B29;">Registrar cobro</button>
                    </div>
                </div>
            </form>
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
        <div class="erp-modal-card" style="max-width: 480px; width: 100%;">
            <div class="erp-modal-header" style="border-bottom: 1px solid #e2e8f0; padding: 1rem 1.25rem;">
                <h3 id="erp-gestion-title" style="font-size: 1.125rem; font-weight: 700; color: #0f172a; margin: 0;">
                    Registrar el archivo de Pago</h3>
                <button type="button" class="erp-modal-close" data-erp-close-gestion aria-label="Cerrar gestión"
                    style="font-size: 1.25rem; color: #64748b; background: none; border: none; cursor: pointer;">✕</button>
            </div>
            <form id="erp-gestion-form" method="POST" action="" enctype="multipart/form-data">
                @csrf
                <div class="erp-modal-body" style="padding: 1.25rem; display: flex; flex-direction: column; gap: 5px;">
                    <div>
                        <span id="erp-gestion-section-title"
                            style="font-size: 0.75rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 0.5rem;">DATOS
                            DE COTIZACIÓN</span>

                        <div style="margin-bottom: 0.30rem;">
                            <label
                                style="font-size: 0.8125rem; font-weight: 600; color: #334155; display: block; margin-bottom: 0.25rem;">Cliente</label>
                            <input type="text" id="erp-gestion-client" readonly
                                style="width: 100%; padding: 0.625rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; background-color: #f8fafc; font-size: 0.875rem; font-weight: 600; color: #1e293b;"
                                value="-">
                        </div>

                        <div>
                            <label
                                style="font-size: 0.8125rem; font-weight: 600; color: #334155; display: block; margin-bottom: 0.25rem;">Monto
                                a cancelar</label>
                            <input type="text" id="erp-gestion-amount" readonly
                                style="width: 100%; padding: 0.625rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; background-color: #f8fafc; font-size: 0.875rem; font-weight: 700; color: #0f172a;"
                                value="-">
                        </div>
                    </div>

                    <div>
                        <div>
                            <label for="erp-gestion-payment-format"
                                style="font-size: 0.8125rem; font-weight: 600; color: #334155; display: block; margin-bottom: 0.25rem;">Formato de pago</label>
                            <select id="erp-gestion-payment-format" disabled
                                style="width: 100%; padding: 0.625rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; background-color: #f8fafc; font-size: 0.875rem; font-weight: 600; color: #1e293b;">
                                <option value="">No especificado</option>
                                <option value="contado">Contado</option>
                                <option value="credito">Crédito</option>
                            </select>
                        </div>
                        <div style="margin-top: 0.30rem;">
                            <label for="erp-gestion-date"
                                style="font-size: 0.8125rem; font-weight: 600; color: #334155; display: block; margin-bottom: 0.25rem;">Fecha
                                de pago<span style="color: #ef4444;">*</span></label>
                            <input type="date" id="erp-gestion-date" name="fecha_pago" required value="{{ date('Y-m-d') }}"
                                style="width: 100%; padding: 0.5rem 0.625rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; font-size: 0.8125rem; color: #1e293b; outline: none;">
                        </div>
                    </div>

                    <div>
                        <label
                            style="font-size: 0.8125rem; font-weight: 600; color: #334155; display: block; margin-bottom: 0.375rem;">Archivo
                            de Pago<span style="color: #ef4444;">*</span></label>
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
                            <input id="erp-gestion-file" name="archivoPago" type="file" required
                                accept="image/jpeg,image/png,application/pdf" style="display: none;">
                            <img id="erp-gestion-preview" class="erp-upload-preview" alt="Previsualización del comprobante"
                                style="max-height: 100px; display: none; margin-top: 0.5rem; border-radius: 0.375rem;" />
                        </label>
                    </div>

                    <div>
                        <label for="erp-gestion-description"
                            style="font-size: 0.8125rem; font-weight: 600; color: #334155; display: block; margin-bottom: 0.25rem;">Descripción
                            / Observación (max. 50 caracteres)<span style="color: #ef4444;">*</span></label>
                        <textarea id="erp-gestion-description" name="descripcion" maxlength="50" rows="2" required
                            placeholder="Describe sobre el pago..."
                            style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; font-size: 0.8125rem; color: #1e293b; outline: none; resize: none; min-height: 60px;"></textarea>
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