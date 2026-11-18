@extends('dashboard.overview-1')
@section('title', $title ?? 'Asignación de Placas y Servicios')
@section('header', $title ?? 'Asignación de Placas y Servicios')
@section('breadcrumb')
    <nav aria-label="breadcrumb" class="flex hidden flex-1 xl:block">
        <ol class="flex items-center text-theme-1">
            <li><a href="{{ route('home') }}">Inicio</a></li>
            <li class="relative ml-5 pl-0.5 before:content-[''] before:w-[14px] before:h-[14px] before:bg-chevron-black before:transform before:rotate-[-90deg] before:bg-[length:100%] before:-ml-[1.125rem] before:absolute before:my-auto before:inset-y-0 text-slate-600 cursor-text">
                <span>{{ $title ?? 'Asignación de Placas y Servicios' }}</span>
            </li>
        </ol>
    </nav>
@endsection
@php
    use App\Support\VehiculoData;
@endphp

@section('content')
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <h1 class="inline-flex min-w-0 flex-1 items-center gap-2 rounded-sm pr-2 text-xl font-semibold text-slate-900">
            <span class="h-5 w-1.5 shrink-0 rounded-full bg-danger" aria-hidden="true"></span>
            <span class="truncate">{{ $title ?? 'Asignación de Placas y Servicios' }}</span>
        </h1>
        <span class="inline-flex shrink-0 items-center rounded-full border border-danger bg-danger px-2.5 py-1 text-xs font-medium text-white"
            aria-label="Paso {{ $currentStep }} de {{ $totalSteps }}">
            Fase {{ $currentStep }} de {{ $totalSteps }}
        </span>
    </div>
    <div class="box box--stacked grid grid-cols-12">
        <div class="col-span-12">
            @if(isset($cotizaciones) && count($cotizaciones) > 0)
                @php
                    $cotizacionesToShow = collect($cotizaciones)->take(1);
                @endphp
                <div class="box p-5">
                    <h2 class="mb-3 flex items-center gap-2 border-b pb-2 text-base font-semibold text-slate-800">
                        <i data-lucide="file-text" class="h-4 w-4 shrink-0 text-slate-500" aria-hidden="true"></i>
                        Información de la Cotización
                    </h2>
                    @foreach($cotizacionesToShow as $cot)
                        @php
                            $fechaEmision = data_get($cot, 'fechaEmision') ?? data_get($cot, 'fechaHoraEmision');
                        @endphp
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4 bg-slate-50 p-4 rounded-md border">
                            @php
                                $clienteDocumento = preg_replace('/\D/', '', data_get($cot, 'cliente_idcliente', ''));
                                $clienteTipo = strlen($clienteDocumento) === 8 ? 'DNI' : (strlen($clienteDocumento) > 8 ? 'RUC' : 'Documento');
                                $grupoCliente = trim((string) data_get($cot, 'grupo_cliente_label', ''));
                                $comentarioCotizacion = trim((string) data_get($cot, 'comentario', ''));
                            @endphp
                            <div><strong>Fecha Emisión:</strong>
                                {{ $fechaEmision ? \Carbon\Carbon::parse($fechaEmision)->format('d/m/Y H:i') : '-' }}</div>
                            <div><strong>Cliente:</strong>
                                {{ $cot->razonSocial ?? $cot->nombreComercial ?? $cot->cliente_label ?? '-' }}</div>
                            <div><strong>{{ $clienteTipo }}:</strong> {{ data_get($cot, 'cliente_idcliente', '-') }}</div>
                            <div><strong>Teléfono:</strong> {{ $cot->telefono ?? $cot->cliente_telefono ?? 'No tiene teléfono' }}</div>
                            <div><strong>Correo:</strong> {{ $cot->correo ?? $cot->cliente_correo ?? 'No tiene correo' }}</div>
                            <div><strong>Grupo Cliente:</strong> {{ $grupoCliente !== '' ? $grupoCliente : 'No tiene grupo' }}</div>
                            <div class="md:col-span-3"><strong>Dirección:</strong> {{ $cot->direccion ?? $cot->cliente_direccion ?? 'No tiene dirección' }}</div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-1 gap-4 mb-4">
                            <div class="rounded-md border border-slate-200 bg-slate-50 p-4">
                                <div class="text-sm font-semibold text-slate-700 mb-2">Comentarios de la Cotización:</div>
                                @php
                                    $cotizacionesComentarios = $cotizacionesComentarios ?? collect([]);
                                @endphp
                                @if($cotizacionesComentarios->isNotEmpty())
                                    <ol class="m-0 pl-4 text-sm text-slate-600 space-y-2">
                                        @foreach($cotizacionesComentarios as $idx => $comentario)
                                            @if(!empty($comentario->comentario))
                                                <li class="list-decimal list-inside break-words mb-1">{{ $comentario->comentario }}</li>
                                            @endif
                                        @endforeach
                                    </ol>
                                @else
                                    <div class="text-sm text-slate-600">No hay comentarios</div>
                                @endif
                            </div>
                        </div>
                    @endforeach


                    <!-- Resumen de Planes/Servicios Disponibles -->
                    @if(isset($servicios) && count($servicios) > 0)
                        <div class="mb-4 p-4 bg-slate-100 border bg-slate-100 rounded-md">
                            <h4 class="text-base font-medium text-slate-700 mb-2">Planes, Servicios, Sensores y Accesorios Disponibles a Asignar:</h4>
                            <ul class="list-disc pl-5 text-sm text-slate-600">
                                @foreach($servicios as $srv)
                                    @php
                                        $tipoNombre = mb_strtolower((string) ($srv->tipo_nombre ?? ''), 'UTF-8');
                                        $periodoPlan = str_contains($tipoNombre, 'plan')
                                            ? \App\Support\ServicePeriod::label($srv->periodo ?? null)
                                            : '';
                                    @endphp
                                    <li>
                                        <strong>{{ (int) $srv->cantidad }}x</strong> {{ $srv->producto }}
                                        @if($periodoPlan !== '')
                                            - {{ $periodoPlan }}
                                        @endif
                                        @php
                                            $tipoGeneral = str_contains($tipoNombre, 'plan')
                                                ? 'Plan'
                                                : (str_contains($tipoNombre, 'servicio')
                                                    ? 'Servicio'
                                                    : (str_contains($tipoNombre, 'sensor') ? 'Sensor' : 'Accesorio'));
                                        @endphp
                                        ({{ $tipoGeneral }})
                                    </li>
                                @endforeach
                            </ul>
                            <p class="text-sm text-danger mt-2">* Debes asignar todos estos elementos entre
                                los equipos mostrados abajo.</p>
                        </div>
                    @endif

                    <div class="flex flex-col gap-3 border-b pb-2 mb-4 md:flex-row md:items-center md:justify-between">
                        <h2 class="flex items-center gap-2 text-base font-semibold text-slate-800">
                            <i data-lucide="car-front" class="h-4 w-4 shrink-0 text-slate-500" aria-hidden="true"></i>
                            Asignación por Equipo
                        </h2>
                        <button type="button"
                            class="inline-flex items-center justify-center rounded-md border px-4 py-2 text-sm font-semibold text-white transition duration-200 bg-danger" onclick="abrirModalPlaca()">
                            <i data-lucide="plus" class="w-4 h-4 mr-1"></i>
                            <span>Añadir Placa / Vehículo</span>
                        </button>
                    </div>

                    <form id="asignacion-form" method="POST" action="{{ $advanceUrl ?? '#' }}" data-lock-action="ticket.vista">
                        @csrf
                        <input type="hidden" name="current_vista_id" value="{{ $vista->idvista ?? '' }}">
                        <input type="hidden" name="action" value="{{ $actionValue ?? 'save' }}">
                        <input type="hidden" name="planes_present" value="1">
                        <input type="hidden" id="erp-lock-resource" value="{{ $lockResource ?? 'ticket.vista' }}">
                        <input type="hidden" id="erp-lock-id" value="{{ $lockId ?? ($ticket->idticket ?? '') }}">

                        @if(isset($equipamiento) && count($equipamiento) > 0)
                            @php
                                $categoriasAsignacion = [
                                    'plan' => 'Planes',
                                    'servicio' => 'Servicios',
                                    'sensor' => 'Sensores',
                                    'accesorio' => 'Accesorios',
                                ];
                                $categoriasDisponibles = collect($servicios ?? [])
                                    ->map(function ($servicio) {
                                        $tipo = mb_strtolower((string) ($servicio->tipo_nombre ?? ''), 'UTF-8');
                                        if (str_contains($tipo, 'plan')) return 'plan';
                                        if (str_contains($tipo, 'servicio')) return 'servicio';
                                        if (str_contains($tipo, 'sensor')) return 'sensor';
                                        return 'accesorio';
                                    })
                                    ->unique()
                                    ->all();
                                $categoriasAsignacion = array_intersect_key($categoriasAsignacion, array_flip($categoriasDisponibles));
                                $tablaMinWidth = 950 + (count($categoriasAsignacion) * 360);
                            @endphp
                            <div id="form-validation-error" class="hidden mb-4 rounded-md border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger"></div>
                            <div class="overflow-visible rounded-3xl border border-slate-300 bg-slate-50 shadow-sm mb-6">
                                <div class="overflow-x-auto table-scroll-wrapper assignment-table-scroll">
                                    <table class="w-max min-w-full text-left text-sm text-slate-600 table-fixed"
                                        style="width: max-content; min-width: {{ $tablaMinWidth }}px;">
                                    <thead class="bg-slate-200 text-xs uppercase tracking-[0.16em] text-slate-700">
                                        <tr>
                                            <th class="px-4 py-3" style="width: 280px;">Producto</th>
                                            <th class="px-4 py-3" style="width: 180px;">Placa / Vehículo</th>
                                            <th class="px-4 py-3" style="width: 200px;">Número Telefónico</th>
                                            @foreach($categoriasAsignacion as $categoriaLabel)
                                                <th class="px-4 py-3" style="width: 320px;">{{ $categoriaLabel }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($equipamiento as $item)
                                            @for($i = 0; $i < (int) $item->cantidad; $i++)
                                                @php
                                                    $planesGuardados = old('planes_present')
                                                        ? old("planes.{$item->iddetalleCotizacion}.{$i}", [])
                                                        : ($item->planes_seleccionados[$i] ?? []);
                                                @endphp
                                                <tr class="planes-container border-t border-slate-300 bg-white"
                                                    data-input-name="planes[{{ $item->iddetalleCotizacion }}][{{ $i }}][]"
                                                    data-row-id="{{ $item->iddetalleCotizacion }}_{{ $i }}">
                                                    <td data-label="Producto" class="px-4 py-4 text-slate-900 vertical-align-middle">
                                                        <div class="w-full">
                                                            <div class="font-medium truncate" title="{{ $item->producto }}">
                                                                {{ $item->producto }}</div>
                                                        </div>
                                                    </td>

                                                    <td data-label="Placa / Vehículo" class="px-4 py-4 vertical-align-middle">
                                                        @php
                                                            $placaGuardada = $item->placas_seleccionadas[$i] ?? null;
                                                            $selectedPlaca = $placaGuardada ?: old("placas.{$item->iddetalleCotizacion}.{$i}", '');
                                                        @endphp
                                                        <div class="w-full">
                                                            <select name="placas[{{ $item->iddetalleCotizacion }}][]"
                                                                class="tom-select placa-select w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary"
                                                                data-placeholder="Selecciona una placa">
                                                                <option value="">Selecciona placa</option>
                                                                @if($selectedPlaca && !collect($vehiculos ?? [])->pluck('placa')->contains($selectedPlaca))
                                                                    <option value="{{ $selectedPlaca }}" selected>{{ $selectedPlaca }}</option>
                                                                @endif
                                                                @if(isset($vehiculos))
                                                                    @foreach($vehiculos as $veh)
                                                                        <option value="{{ $veh->placa }}" @if(trim((string) $selectedPlaca) === trim((string) $veh->placa)) selected @endif>{{ $veh->placa }}</option>
                                                                    @endforeach
                                                                @endif
                                                            </select>
                                                            <div class="placa-error text-danger text-xs mt-2 hidden">Placa duplicada o inválida.</div>
                                                        </div>
                                                    </td>

                                                    <td data-label="Número Telefónico" class="px-4 py-4 vertical-align-middle">
                                                        @php
                                                            $numeroGuardado = $item->numeros_seleccionados[$i] ?? null;
                                                            $selectedNumero = $numeroGuardado ?: old("numeros.{$item->iddetalleCotizacion}.{$i}", '');
                                                        @endphp
                                                        <div class="w-full">
                                                            <select name="numeros[{{ $item->iddetalleCotizacion }}][]"
                                                                class="tom-select numero-select w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary"
                                                                data-placeholder="Selecciona número">
                                                                <option value="">Selecciona número</option>
                                                                @if($selectedNumero && !collect($numerosTelefonicos ?? [])->pluck('numeroTelefonico')->contains($selectedNumero))
                                                                    <option value="{{ $selectedNumero }}" selected>{{ $selectedNumero }}</option>
                                                                @endif
                                                                @if(isset($numerosTelefonicos))
                                                                    @foreach($numerosTelefonicos as $num)
                                                                        <option value="{{ $num->numeroTelefonico }}" @if(trim((string) $selectedNumero) === trim((string) $num->numeroTelefonico)) selected @endif>{{ $num->numeroTelefonico }}</option>
                                                                    @endforeach
                                                                @endif
                                                            </select>
                                                            <div class="numero-error text-danger text-xs mt-2 hidden">Número duplicado o inválido.</div>
                                                        </div>
                                                    </td>

                                                    @foreach($categoriasAsignacion as $categoria => $categoriaLabel)
                                                        <td data-label="{{ $categoriaLabel }}" class="px-4 py-4 align-top" style="vertical-align: top !important;">
                                                            <div class="plan-category flex min-w-0 flex-col items-start gap-1"
                                                                data-category="{{ $categoria }}">
                                                                @if($loop->first)
                                                                    <div class="plan-hidden-input-holder hidden">
                                                                        @foreach($planesGuardados as $planId)
                                                                            <input type="hidden" name="planes[{{ $item->iddetalleCotizacion }}][{{ $i }}][]"
                                                                                value="{{ $planId }}" class="plan-hidden-input">
                                                                        @endforeach
                                                                    </div>
                                                                @endif
                                                                <ul class="plan-category-summary m-0 w-full list-none space-y-1 p-0 text-xs text-slate-700"
                                                                    aria-live="polite"></ul>
                                                                <button type="button"
                                                                    class="btn-add-plan inline-flex items-center justify-center rounded-md border border-slate-300 bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-600 shadow-sm transition hover:bg-slate-200"
                                                                    data-category="{{ $categoria }}"
                                                                    data-category-label="{{ $categoriaLabel }}">
                                                                    <i data-lucide="plus" class="w-3.5 h-3.5 mr-1 text-slate-500"></i>
                                                                    Agregar
                                                                </button>
                                                            </div>
                                                        </td>
                                                    @endforeach
                                                </tr>
                                            @endfor
                                        @endforeach
                                    </tbody>
                                    </table>
                                </div>
                            </div>
                        @else
                            <div class="text-slate-600 text-base font-semibold mb-6">
                                No hay equipamiento asignado previamente.
                            </div>
                        @endif

                        <div class="mt-6 flex flex-wrap items-center justify-end gap-3">
                            @include('vistas._actions')
                        </div>
                    </form>
                </div>
            @endif
        </div>
    </div>
     <!-- Modal para Nueva Placa -->
    <div id="modalNuevaPlaca" class="fixed inset-0 hidden items-center justify-center px-4 py-6 backdrop-blur-sm"
        role="dialog" aria-modal="true"
        style="position: fixed !important; inset: 0 !important; width: 100vw !important; height: 100vh !important; z-index: 2147483647 !important; background-color: rgba(0, 0, 0, 0.82) !important;">
        <div class="w-full rounded-lg bg-white shadow-2xl ring-1 ring-slate-900/10 border-t-4 border-red-600 overflow-hidden modal-dialog"
            style="max-width: 980px; max-height: calc(100vh - 3rem); position: relative; z-index: 2147483647 !important;">
            <div
                class="flex items-start justify-between border-b border-slate-200 bg-gradient-to-r from-slate-50 to-white px-6 py-5">
                <div>  
                    <h2 class="text-lg font-semibold text-slate-900">Añadir Nuevo Vehículo</h2>
                    <p class="mt-2 text-sm text-slate-600">Registra un vehículo nuevo y utilízalo en esta cotización.</p>
                </div>
                <button type="button"
                    class="ml-auto rounded-lg border-0 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-100 hover:text-red-600 transition duration-200 flex-shrink-0"
                    onclick="cerrarModalPlaca()">
                    <i data-lucide="x" class="h-5 w-5"></i>
                </button>
            </div>
            <form id="nueva-placa-form">
                <div class="grid grid-cols-1 gap-4 bg-white p-7 md:grid-cols-2">
                <div>
                    <div class="mb-2 flex flex-wrap items-center gap-2">
                        <label for="nueva-placa-input" class="text-sm font-medium text-slate-700">Placa <span class="text-red-600">*</span></label>
                        <span class="text-xs text-slate-500">Identificador único del vehículo.</span>
                        <button type="button" id="consultar-placa-btn"
                            class="inline-flex items-center gap-1 rounded border border-danger px-2 py-1 text-xs font-semibold text-white transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-70 bg-danger" data-consult-label="Consultar">
                            <i data-lucide="search" class="h-3 w-3"></i>
                            <span id="consultar-placa-text">Consultar</span>
                        </button>
                    </div>
                    <input id="nueva-placa-input" name="placa" type="text" maxlength="20" required
                        class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 focus:border-red-600 focus:ring-2 focus:ring-red-500/20"
                        placeholder="Ej: ABC-123">
                    <p id="consulta-placa-error" class="mt-1 hidden text-xs text-red-600" role="alert"></p>
                </div>
                <div>
                    <label for="nuevo-cliente-select"
                        class="mb-2 block text-sm font-medium text-slate-700">Cliente <span class="text-red-600">*</span></label>
                    <select id="nuevo-cliente-select" name="cliente_idcliente"
                        class="tom-select w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 focus:border-red-600 focus:ring-2 focus:ring-red-500/20"
                        data-placeholder="Selecciona cliente..." required>
                        <option value="">Selecciona cliente...</option>
                        @foreach($clientes as $cliente)
                            <option value="{{ $cliente->idcliente }}" @if(trim((string) ($cliente->idcliente ?? '')) === trim((string) ($clienteId ?? ''))) selected @endif>
                                {{ $cliente->cliente_label }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="nuevo-tipo-vehiculo-select" class="mb-2 block text-sm font-medium text-slate-700">Tipo de Vehículo <span class="text-red-600">*</span></label>
                    <select id="nuevo-tipo-vehiculo-select" name="tipoUnidad_idtable1"
                        class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 focus:border-red-600 focus:ring-2 focus:ring-red-500/20"
                        data-placeholder="Selecciona tipo de vehículo..." required>
                        <option value="">Selecciona tipo de vehículo...</option>
                        @foreach($tipoVehiculos as $tipo)
                            <option value="{{ $tipo->idtipoVehiculo }}">{{ $tipo->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="nuevo-anio-input" class="mb-2 block text-sm font-medium text-slate-700">Año</label>
                    <input id="nuevo-anio-input" name="anio" type="number" min="1900" max="2100"
                        class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 focus:border-red-600 focus:ring-2 focus:ring-red-500/20"
                        placeholder="Ej: 2024">
                </div>
                <div class="relative">
                    <label for="nuevo-color-input" class="mb-2 block text-sm font-medium text-slate-700">Color</label>
                    <input id="nuevo-color-input" name="color" type="text"
                        class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 focus:border-red-600 focus:ring-2 focus:ring-red-500/20"
                        placeholder="Ej: Blanco" autocomplete="off">
                    <div id="color-suggestions" class="suggestion-list hidden"></div>
                </div>
                <div class="relative">
                    <label for="nuevo-marca-input" class="mb-2 block text-sm font-medium text-slate-700">Marca</label>
                    <input id="nuevo-marca-input" name="marca" type="text"
                        class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 focus:border-red-600 focus:ring-2 focus:ring-red-500/20"
                        placeholder="Ej: Toyota" autocomplete="off">
                    <div id="marca-suggestions" class="suggestion-list hidden"></div>
                </div>
                <div class="relative">
                    <label for="nuevo-modelo-input" class="mb-2 block text-sm font-medium text-slate-700">Modelo</label>
                    <input id="nuevo-modelo-input" name="modelo" type="text"
                        class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 focus:border-red-600 focus:ring-2 focus:ring-red-500/20"
                        placeholder="Ej: Hilux" autocomplete="off">
                    <div id="modelo-suggestions" class="suggestion-list hidden"></div>
                </div>
                <div>
                    <label for="nuevo-tracto-select" class="mb-2 block text-sm font-medium text-slate-700">Tracto</label>
                    <select id="nuevo-tracto-select" name="tracto"
                        class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 focus:border-red-600 focus:ring-2 focus:ring-red-500/20"
                        data-placeholder="Selecciona tracto...">
                        <option value="">Selecciona tracto...</option>
                        <option value="Si">Si</option>
                        <option value="No" selected>No</option>
                    </select>
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4">
                <p id="nueva-placa-error" class="mr-auto hidden text-sm text-danger" role="alert"></p>
                <button type="button"
                    class="rounded-md border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-100"
                    onclick="cerrarModalPlaca()">Cancelar</button>
                <button type="button"
                    class="rounded-md border-0 px-4 py-2 text-xs font-semibold text-white shadow-sm transition duration-200 bg-danger" onclick="guardarNuevaPlaca()">Guardar</button>
            </div>
            </form>
        </div>
    </div>

    <!-- Modal para Seleccionar Planes -->
    <div id="modalPlanes" class="fixed inset-0 hidden items-center justify-center px-4 py-6 backdrop-blur-sm" role="dialog"
        aria-modal="true"
        style="position: fixed !important; inset: 0 !important; width: 100vw !important; height: 100vh !important; z-index: 2147483647 !important; background-color: rgba(0, 0, 0, 0.82) !important;">
        <div class="w-full rounded-lg bg-white shadow-2xl ring-1 ring-slate-900/10 border-t-4 border-red-600 overflow-hidden modal-dialog"
            style="max-width: 500px; max-height: calc(100vh - 3rem); position: relative; z-index: 2147483647 !important;">
            <div
                class="flex items-start justify-between border-b border-slate-200 bg-gradient-to-r from-slate-50 to-white px-6 py-4">
                <div>
                    <h2 id="modalPlanesTitle" class="text-lg font-semibold text-slate-900">Agregar elemento</h2>
                    <p id="modalPlanesDescription" class="mt-1 text-xs text-slate-600">Selecciona elementos para este equipo.</p>
                </div>
                <button type="button"
                    class="ml-auto rounded-lg border-0 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-100 hover:text-red-600 transition duration-200 flex-shrink-0"
                    onclick="cerrarModalPlanes()">
                    <i data-lucide="x" class="h-4 w-4"></i>
                </button>
            </div>
            <div class="bg-white p-5">
                <input type="hidden" id="currentPlanRowId" value="">
                <input type="hidden" id="currentPlanCategory" value="">
                <div id="planesListContainer" class="flex flex-col gap-3 max-h-64 overflow-y-auto pr-2">
                    <!-- Checkboxes dinámicos -->
                </div>
            </div>
            <div class="flex items-center justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4">
                <button type="button"
                    class="rounded-md border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-100 transition duration-200"
                    onclick="cerrarModalPlanes()">Cancelar</button>
                <button type="button"
                    class="rounded-md border-0 px-4 py-2 text-xs font-semibold text-white shadow-sm transition duration-200 bg-danger" onclick="guardarPlanesSeleccionados()">Confirmar Selección</button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        function submitRejectAction(button) {
            const formId = button.getAttribute('data-form-id') || 'asignacion-form';
            const form = document.getElementById(formId);
            if (!form) return;

            form.querySelectorAll('input[name="action"]').forEach((input) => {
                input.value = 'reject';
                input.setAttribute('value', 'reject');
            });

            form.submit();
        }

        // Referencias al DOM Modals
        const modalElement = document.getElementById('modalNuevaPlaca');
        const modalPlanesElement = document.getElementById('modalPlanes');
        let placaConsultaController = null;

        document.addEventListener('DOMContentLoaded', function () {
            [modalElement, modalPlanesElement].forEach(modal => {
                if (modal && modal.parentElement !== document.body) {
                    document.body.appendChild(modal);
                }
                modal?.style?.setProperty('z-index', '2147483647', 'important');
                modal?.style?.setProperty('position', 'fixed', 'important');
                modal?.style?.setProperty('top', '0', 'important');
                modal?.style?.setProperty('left', '0', 'important');
                modal?.style?.setProperty('width', '100vw', 'important');
                modal?.style?.setProperty('height', '100vh', 'important');
            });
        });

        function abrirModalPlaca() {
            modalElement.classList.remove('hidden');
            modalElement.classList.add('flex');
            document.body.style.overflow = 'hidden';
        }

        function cerrarModalPlaca() {
            placaConsultaController?.abort();
            placaConsultaController = null;
            const form = document.getElementById('nueva-placa-form');
            form?.reset();
            form?.querySelectorAll('select').forEach(select => {
                const tomSelect = select.tomselect || select.tomSelect || select._tomselect;
                if (tomSelect && typeof tomSelect.setValue === 'function') {
                    tomSelect.setValue(select.value);
                }
            });
            document.getElementById('consulta-placa-error').textContent = '';
            document.getElementById('consulta-placa-error').classList.add('hidden');
            document.getElementById('nueva-placa-error').textContent = '';
            document.getElementById('nueva-placa-error').classList.add('hidden');
            document.querySelectorAll('#modalNuevaPlaca .suggestion-list').forEach(list => list.classList.add('hidden'));
            const consultButton = document.getElementById('consultar-placa-btn');
            consultButton.disabled = false;
            document.getElementById('consultar-placa-text').textContent = consultButton.dataset.consultLabel;
            modalElement.classList.add('hidden');
            modalElement.classList.remove('flex');
            document.body.style.overflow = '';
        }

        async function consultarNuevaPlaca() {
            const button = document.getElementById('consultar-placa-btn');
            const placa = document.getElementById('nueva-placa-input').value.trim();
            const errorElement = document.getElementById('consulta-placa-error');
            errorElement.textContent = '';
            errorElement.classList.add('hidden');

            if (!placa) {
                document.getElementById('nueva-placa-input').reportValidity();
                return;
            }

            placaConsultaController?.abort();
            const controller = new AbortController();
            placaConsultaController = controller;
            button.disabled = true;
            document.getElementById('consultar-placa-text').textContent = 'Consultando...';

            try {
                const response = await fetch(`{{ route('api.consultar.placa') }}?placa=${encodeURIComponent(placa)}`, {
                    headers: { 'Accept': 'application/json' },
                    signal: controller.signal
                });
                const data = await response.json();

                if (response.ok && data?.status === 'success' && data?.data) {
                    const result = data.data;
                    ['anio', 'marca', 'modelo', 'tipoUnidad_idtable1'].forEach(fieldName => {
                        const field = document.getElementById(fieldName === 'tipoUnidad_idtable1'
                            ? 'nuevo-tipo-vehiculo-select'
                            : `nuevo-${fieldName}-input`);
                        if (!field) return;

                        const value = result[fieldName] ?? result[fieldName.toLowerCase()] ?? '';
                        const tomSelect = field.tomselect || field.tomSelect || field._tomselect;
                        if (tomSelect && typeof tomSelect.setValue === 'function') {
                            tomSelect.setValue(String(value));
                        } else {
                            field.value = value;
                        }
                        field.dispatchEvent(new Event('input', { bubbles: true }));
                        field.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                } else {
                    errorElement.textContent = data?.message || 'No se pudo obtener información de la placa.';
                    errorElement.classList.remove('hidden');
                }
            } catch (error) {
                if (error.name !== 'AbortError') {
                    console.error('Error al consultar placa:', error);
                    errorElement.textContent = 'No se pudo obtener información de la placa.';
                    errorElement.classList.remove('hidden');
                }
            } finally {
                if (placaConsultaController === controller) {
                    button.disabled = false;
                    document.getElementById('consultar-placa-text').textContent = button.dataset.consultLabel;
                    placaConsultaController = null;
                }
            }
        }

        async function guardarNuevaPlaca() {
            const form = document.getElementById('nueva-placa-form');
            const errorElement = document.getElementById('nueva-placa-error');
            errorElement.textContent = '';
            errorElement.classList.add('hidden');
            if (!form.reportValidity()) return;

            const placa = document.getElementById('nueva-placa-input').value.trim().toUpperCase();
            const ticketId = @json((int) $ticket->idticket);
            const clienteId = document.getElementById('nuevo-cliente-select')?.value || '{{ $clienteId ?? "" }}';
            const tipoUnidad = document.getElementById('nuevo-tipo-vehiculo-select')?.value || null;
            const anio = document.getElementById('nuevo-anio-input')?.value || null;
            const marca = document.getElementById('nuevo-marca-input')?.value || null;
            const modelo = document.getElementById('nuevo-modelo-input')?.value || null;
            const color = document.getElementById('nuevo-color-input')?.value || null;
            const tracto = document.getElementById('nuevo-tracto-select')?.value || null;

            try {
                const response = await fetch('{{ route('modules.tickets.vehiculos.store') }}', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        ticket_id: ticketId,
                        placa,
                        cliente_idcliente: clienteId,
                        tipoUnidad_idtable1: tipoUnidad,
                        anio,
                        marca,
                        modelo,
                        color,
                        tracto
                    })
                });

                const responseText = await response.text();
                let data = null;

                if (responseText) {
                    try {
                        data = JSON.parse(responseText);
                    } catch (parseError) {
                        if (response.ok) {
                            data = {
                                success: false,
                                message: 'No se pudo completar la acción. El servidor respondió con una respuesta inesperada.'
                            };
                        } else {
                            data = {
                                success: false,
                                message: responseText.replace(/\s+/g, ' ').trim().slice(0, 240) || 'No se pudo guardar la placa. El servidor respondió con un error.'
                            };
                        }
                    }
                }

                if (!response.ok) {
                    const validationMessage = data?.errors
                        ? Object.values(data.errors).flat().join(' ')
                        : '';
                    errorElement.textContent = validationMessage || data?.message || 'No se pudo guardar la placa. El servidor respondió con un error.';
                    errorElement.classList.remove('hidden');
                    return;
                }

                if (data?.success) {
                    const placaValue = data.vehiculo?.placa;
                    if (placaValue) {
                        document.querySelectorAll('select.placa-select').forEach(select => {
                            const tomInstance = select.tomselect || select.tomSelect || select._tomselect;

                            if (tomInstance && typeof tomInstance.addOption === 'function') {
                                if (!tomInstance.options?.[placaValue]) {
                                    tomInstance.addOption({ value: placaValue, text: placaValue });
                                }
                                tomInstance.refreshOptions(false);
                                if (typeof tomInstance.close === 'function') {
                                    tomInstance.close();
                                }
                            } else if (select && select.options) {
                                const exists = Array.from(select.options).some(opt => opt.value === placaValue);
                                if (!exists) {
                                    const option = new Option(placaValue, placaValue, false, false);
                                    select.add(option);
                                }
                            }
                        });
                    }

                    cerrarModalPlaca();
                } else {
                    errorElement.textContent = data?.message || 'Error al guardar la placa.';
                    errorElement.classList.remove('hidden');
                }
            } catch (error) {
                console.error('Error:', error);
                errorElement.textContent = 'No se pudo guardar el vehículo. Inténtalo nuevamente.';
                errorElement.classList.remove('hidden');
            }
        }

        document.getElementById('consultar-placa-btn').addEventListener('click', consultarNuevaPlaca);

        // ==========================================
        // LÓGICA PARA EXCLUSIVIDAD DE PLACAS Y NÚMEROS
        // ==========================================
        function syncExclusiveSelects(selectorClass) {
            const selects = document.querySelectorAll(selectorClass);

            selects.forEach(select => {
                const ts = select.tomselect || select.tomSelect || select._tomselect;
                if (!ts) return;

                Object.values(ts.options).forEach(opt => {
                    if (!opt.value) return;
                    if (opt.disabled) {
                        ts.updateOption(opt.value, { ...opt, disabled: false });
                    }
                });
                ts.refreshOptions(false);
            });
        }

        // ==========================================
        // LÓGICA DEL MODAL DE PLANES Y SERVICIOS
        // ==========================================
        const availablePlanesGlobal = [
            @if(isset($servicios))
                @foreach($servicios as $srv)
                    @for($j = 1; $j <= (int) $srv->cantidad; $j++)
                        {
                            id: @json($srv->iddetalleCotizacion . '_' . $j),
                            nombre: @json($srv->producto),
                            tipo: @json($srv->tipo_nombre),
                            periodo: @json(str_contains(mb_strtolower((string) ($srv->tipo_nombre ?? ''), 'UTF-8'), 'plan')
                                ? \App\Support\ServicePeriod::label($srv->periodo ?? null)
                                : '')
                        },
                    @endfor
                @endforeach
            @endif
        ];

        let planesSelections = {};

        function categoriaElemento(tipo) {
            const normalizedType = String(tipo || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es');
            if (normalizedType.includes('plan')) return 'plan';
            if (normalizedType.includes('servicio')) return 'servicio';
            if (normalizedType.includes('sensor')) return 'sensor';
            return 'accesorio';
        }

        function getAssignedPlanIds() {
            return Array.from(document.querySelectorAll('input[type="hidden"][name^="planes"]'))
                .map(input => (input.value || '').trim())
                .filter(value => value !== '');
        }

        function loadPlanesSelections() {
            document.querySelectorAll('.planes-container').forEach(container => {
                const rowId = container.dataset.rowId;
                const assigned = Array.from(container.querySelectorAll('input[type="hidden"][name^="planes"]'))
                    .map(input => (input.value || '').trim())
                    .filter(value => value !== '');
                planesSelections[rowId] = Array.from(new Set(assigned));
                actualizarResumenesPlanes(container);
            });
        }

        function actualizarResumenesPlanes(container) {
            const selectedIds = Array.from(container.querySelectorAll('.plan-hidden-input'))
                .map(input => (input.value || '').trim())
                .filter(value => value !== '');
            container.querySelectorAll('.plan-category').forEach(categoryContainer => {
                const summary = categoryContainer.querySelector('.plan-category-summary');
                if (!summary) return;
                summary.replaceChildren();

                selectedIds.forEach(id => {
                    const item = availablePlanesGlobal.find(option => option.id === id);
                    if (!item || categoriaElemento(item.tipo) !== categoryContainer.dataset.category) return;
                    const line = document.createElement('li');
                    line.className = 'break-words mb-1';
                    line.textContent = item.periodo ? `${item.nombre} - ${item.periodo}` : item.nombre;
                    summary.appendChild(line);
                });
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.planes-container').forEach(container => {
                planesSelections[container.dataset.rowId] = [];
            });

            loadPlanesSelections();

            document.querySelectorAll('.btn-add-plan').forEach(btn => {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    abrirModalPlanes(this.closest('.planes-container'), this.dataset.category, this.dataset.categoryLabel);
                });
            });
        });

        function abrirModalPlanes(container, category, categoryLabel) {
            const rowId = container.dataset.rowId;
            document.getElementById('currentPlanRowId').value = rowId;
            document.getElementById('currentPlanCategory').value = category;
            document.getElementById('modalPlanesTitle').textContent = `Agregar ${categoryLabel.toLowerCase()}`;
            document.getElementById('modalPlanesDescription').textContent = `Selecciona ${categoryLabel.toLowerCase()} que deseas agregar a este equipo.`;
            const listContainer = document.getElementById('planesListContainer');
            listContainer.innerHTML = '';

            loadPlanesSelections();
            const allAssigned = getAssignedPlanIds();
            const currentAssigned = Array.from(container.querySelectorAll('input[type="hidden"][name^="planes"]'))
                .map(input => (input.value || '').trim())
                .filter(value => value !== '');

            const selectedInOtherRows = allAssigned.filter(id => !currentAssigned.includes(id));
            const currentSelections = planesSelections[rowId] || [];
            let optionsCount = 0;

            availablePlanesGlobal.forEach(plan => {
                if (categoriaElemento(plan.tipo) !== category) return;
                if (selectedInOtherRows.includes(plan.id)) return;
                optionsCount++;

                const label = document.createElement('label');
                label.className = 'flex items-center gap-4 p-3 border border-slate-200 rounded-lg cursor-pointer hover:bg-slate-50 transition duration-200';
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.className = 'plan-checkbox w-4 h-4 text-primary rounded border-slate-300 focus:ring-primary';
                checkbox.value = plan.id;
                checkbox.checked = currentSelections.includes(plan.id);
                const name = document.createElement('span');
                name.className = 'text-sm font-medium text-slate-700 select-none';
                name.textContent = plan.periodo ? `${plan.nombre} - ${plan.periodo}` : plan.nombre;
                label.append(checkbox, name);
                listContainer.appendChild(label);
            });

            if (optionsCount === 0) {
                const emptyMessage = document.createElement('p');
                emptyMessage.className = 'text-sm text-slate-500 italic p-3 text-center';
                emptyMessage.textContent = `Todos los elementos de ${categoryLabel.toLowerCase()} ya han sido asignados.`;
                listContainer.appendChild(emptyMessage);
            }

            modalPlanesElement.classList.remove('hidden');
            modalPlanesElement.classList.add('flex');
            document.body.style.overflow = 'hidden';
        }

        function cerrarModalPlanes() {
            modalPlanesElement.classList.add('hidden');
            modalPlanesElement.classList.remove('flex');
            document.body.style.overflow = '';
        }

        function guardarPlanesSeleccionados() {
            const rowId = document.getElementById('currentPlanRowId').value;
            const category = document.getElementById('currentPlanCategory').value;
            const container = document.querySelector(`.planes-container[data-row-id="${rowId}"]`);
            const inputName = container.dataset.inputName;
            const inputHolder = container.querySelector('.plan-hidden-input-holder');

            const checkboxes = document.querySelectorAll('#planesListContainer .plan-checkbox:checked');
            const newCategorySelections = Array.from(checkboxes).map(checkbox => checkbox.value);
            const otherCategorySelections = (planesSelections[rowId] || []).filter(id => {
                const item = availablePlanesGlobal.find(option => option.id === id);
                return item && categoriaElemento(item.tipo) !== category;
            });
            const newSelections = [...otherCategorySelections, ...newCategorySelections];
            planesSelections[rowId] = newSelections;
            inputHolder.querySelectorAll('.plan-hidden-input').forEach(input => input.remove());

            newSelections.forEach(id => {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = inputName;
                hiddenInput.value = id;
                hiddenInput.className = 'plan-hidden-input';
                inputHolder.appendChild(hiddenInput);
            });

            actualizarResumenesPlanes(container);
            if (typeof lucide !== 'undefined') lucide.createIcons();
            cerrarModalPlanes();
        }

        function initTomSelects() {
            if (typeof window.TomSelect !== 'function') {
                return;
            }

            document.querySelectorAll('select.tom-select').forEach(select => {
                const isAssignmentTableSelect = Boolean(select.closest('.assignment-table-scroll'));
                const existingInstance = select.tomselect || select.tomSelect || select._tomselect;

                if (isAssignmentTableSelect && select.tomselect) {
                    [
                        select._assignmentTableDropdownPositionHandler,
                        select._assignmentTableDropdownCloseHandler
                    ].filter(Boolean).forEach(handler => {
                        window.removeEventListener('scroll', handler, true);
                        window.removeEventListener('resize', handler);
                    });
                    select.tomselect.destroy();
                    delete select._assignmentTableDropdownPositionHandler;
                    delete select._assignmentTableDropdownCloseHandler;
                } else if (existingInstance) {
                    return;
                }

                try {
                    const isMultiple = select.hasAttribute('multiple');
                    const plugins = [];
                    if (isAssignmentTableSelect) plugins.push('dropdown_input');
                    if (isMultiple) plugins.push('remove_button');

                    const settings = {
                        allowEmptyOption: true,
                        maxItems: isMultiple ? null : 1,
                        placeholder: select.dataset.placeholder || '',
                        hidePlaceholder: isAssignmentTableSelect,
                        plugins,
                        create: false,
                        closeAfterSelect: !isMultiple
                    };

                    if (isAssignmentTableSelect) settings.dropdownParent = 'body';

                    const instance = new TomSelect(select, settings);

                    if (isAssignmentTableSelect) {
                        instance.dropdown.classList.add('assignment-table-dropdown');

                        const positionDropdown = () => {
                            const rect = instance.control.getBoundingClientRect();
                            const dropdownHeight = instance.dropdown.getBoundingClientRect().height;
                            const spaceBelow = window.innerHeight - rect.bottom;
                            const spaceAbove = rect.top;
                            const openAbove = dropdownHeight > spaceBelow && spaceAbove > spaceBelow;
                            const top = openAbove
                                ? rect.top - dropdownHeight
                                : rect.bottom;

                            instance.dropdown.style.setProperty('position', 'fixed', 'important');
                            instance.dropdown.style.top = `${Math.max(top, 0)}px`;
                            instance.dropdown.style.left = `${rect.left}px`;
                            instance.dropdown.style.width = `${rect.width}px`;
                            instance.dropdown.style.setProperty('z-index', '2147483647', 'important');
                        };

                        instance.on('dropdown_open', positionDropdown);
                        select._assignmentTableDropdownPositionHandler = positionDropdown;

                        const closeDropdown = () => instance.close();
                        window.addEventListener('scroll', closeDropdown, true);
                        window.addEventListener('resize', closeDropdown);
                        select._assignmentTableDropdownCloseHandler = closeDropdown;
                    }

                    if (select.id && instance.control_input) {
                        instance.control_input.id = `${select.id}-ts-control`;
                    }

                    if (instance.wrapper) instance.wrapper.style.width = '100%';

                    instance.on('change', () => {
                        if (select.classList.contains('placa-select')) syncExclusiveSelects('.placa-select');
                        if (select.classList.contains('numero-select')) syncExclusiveSelects('.numero-select');
                    });

                } catch (error) {
                    console.warn('TomSelect initialization failed:', error);
                }
            });

            // Sincronizar estado inicial por si vienen con valores previos
            syncExclusiveSelects('.placa-select');
            syncExclusiveSelects('.numero-select');
        }

        const vehiculoBrands = @json(VehiculoData::getBrands());
        const vehiculoModels = @json(VehiculoData::getModels());
        const vehiculoColors = @json(VehiculoData::getColors());

        function initVehiculoSuggestionInputs() {
            const suggestionFields = [
                { inputId: 'nuevo-marca-input', suggestionsId: 'marca-suggestions', items: vehiculoBrands },
                { inputId: 'nuevo-modelo-input', suggestionsId: 'modelo-suggestions', items: vehiculoModels },
                { inputId: 'nuevo-color-input', suggestionsId: 'color-suggestions', items: vehiculoColors },
            ];

            suggestionFields.forEach(({ inputId, suggestionsId, items }) => {
                const input = document.getElementById(inputId);
                const suggestions = document.getElementById(suggestionsId);
                if (!input || !suggestions) return;

                if (suggestions.parentElement !== document.body) {
                    document.body.appendChild(suggestions);
                }

                suggestions.style.position = 'fixed';
                suggestions.style.zIndex = '2147483650';
                suggestions.style.width = 'auto';
                suggestions.style.display = 'none';

                let focusedIndex = -1;

                const positionSuggestions = () => {
                    const rect = input.getBoundingClientRect();
                    suggestions.style.width = `${rect.width}px`;
                    suggestions.style.left = `${rect.left}px`;
                    suggestions.style.top = `${rect.bottom + 6}px`;
                    suggestions.style.maxHeight = `${Math.min(240, window.innerHeight - rect.bottom - 20)}px`;
                };

                const updateActiveSuggestion = () => {
                    const itemsNode = suggestions.querySelectorAll('.suggestion-item');
                    itemsNode.forEach((node, index) => {
                        node.classList.toggle('active', index === focusedIndex);
                        node.setAttribute('aria-selected', index === focusedIndex ? 'true' : 'false');
                        if (index === focusedIndex) {
                            node.scrollIntoView({ block: 'nearest' });
                        }
                    });
                };

                const renderSuggestions = (query) => {
                    const normalizedQuery = query.trim().toLowerCase();
                    const filtered = normalizedQuery === ''
                        ? items.slice(0, 8)
                        : items.filter(item => item.toLowerCase().includes(normalizedQuery)).slice(0, 8);

                    focusedIndex = -1;

                    if (filtered.length === 0) {
                        suggestions.classList.add('hidden');
                        suggestions.style.display = 'none';
                        suggestions.innerHTML = '';
                        return;
                    }

                    suggestions.innerHTML = filtered.map(item => `
                        <div class="suggestion-item" role="option" tabindex="-1">${item}</div>
                    `).join('');
                    suggestions.classList.remove('hidden');
                    suggestions.style.display = 'block';
                    positionSuggestions();
                };

                const chooseSuggestion = (item) => {
                    input.value = item.textContent.trim();
                    suggestions.classList.add('hidden');
                    suggestions.style.display = 'none';
                    input.focus();
                };

                input.addEventListener('input', () => renderSuggestions(input.value));
                input.addEventListener('focus', () => renderSuggestions(input.value));
                input.addEventListener('blur', () => {
                    setTimeout(() => {
                        suggestions.classList.add('hidden');
                        suggestions.style.display = 'none';
                    }, 120);
                });

                input.addEventListener('keydown', (event) => {
                    const itemsNode = suggestions.querySelectorAll('.suggestion-item');
                    if (itemsNode.length === 0) return;

                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        focusedIndex = Math.min(focusedIndex + 1, itemsNode.length - 1);
                        updateActiveSuggestion();
                        return;
                    }

                    if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        focusedIndex = Math.max(focusedIndex - 1, 0);
                        updateActiveSuggestion();
                        return;
                    }

                    if (event.key === 'Enter' && focusedIndex >= 0) {
                        event.preventDefault();
                        chooseSuggestion(itemsNode[focusedIndex]);
                    }
                });

                window.addEventListener('resize', () => {
                    if (!suggestions.classList.contains('hidden')) {
                        positionSuggestions();
                    }
                });

                window.addEventListener('scroll', () => {
                    if (!suggestions.classList.contains('hidden')) {
                        positionSuggestions();
                    }
                }, true);

                suggestions.addEventListener('click', event => {
                    const item = event.target.closest('.suggestion-item');
                    if (!item) return;
                    chooseSuggestion(item);
                });

                suggestions.addEventListener('keydown', event => {
                    if (event.key !== 'Enter') return;
                    const item = event.target.closest('.suggestion-item');
                    if (!item) return;
                    event.preventDefault();
                    chooseSuggestion(item);
                });
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            initTomSelects();
            initVehiculoSuggestionInputs();
            const form = document.getElementById('asignacion-form');
            if (!form) return;

            form.addEventListener('submit', function (e) {
                const actionInput = document.getElementById('form-action-input');
                const isRejectAction = actionInput && actionInput.value === 'reject';
                if (isRejectAction) {
                    return;
                }

                const isActionAdvance = (actionInput && actionInput.name !== '');

                // Si no es avanzar (es cancelar), dejamos que se envíe sin validaciones ni modales
                if (!isActionAdvance) {
                    return;
                }

                // Si ya fue confirmado, permitimos el envío real
                if (form.dataset.confirmed === 'true') {
                    return;
                }

                let hasErrors = false;
                let hasIncompleteData = false;
                const formError = document.getElementById('form-validation-error');
                const placaSelects = document.querySelectorAll('select.placa-select');
                const numeroSelects = document.querySelectorAll('select.numero-select');
                const placaValues = [];
                const numeroValues = [];

                if (formError) {
                    formError.classList.add('hidden');
                    formError.textContent = '';
                }

                placaSelects.forEach(select => {
                    const value = (select.value || '').trim();
                    const errorDiv = select.closest('td')?.querySelector('.placa-error');
                    if (errorDiv) errorDiv.classList.add('hidden');
                    select.classList.remove('border-danger');
                    if (value !== '') {
                        placaValues.push(value);
                    } else {
                        hasIncompleteData = true;
                    }
                });

                numeroSelects.forEach(select => {
                    const value = (select.value || '').trim();
                    const errorDiv = select.closest('td')?.querySelector('.numero-error');
                    if (errorDiv) errorDiv.classList.add('hidden');
                    select.classList.remove('border-danger');
                    if (value !== '') {
                        numeroValues.push(value);
                    } else {
                        hasIncompleteData = true;
                    }
                });

                // include already saved hidden placa and numero values in duplicate check
                document.querySelectorAll('input[type="hidden"][name^="placas"]').forEach(input => {
                    const val = (input.value || '').trim();
                    if (val !== '') placaValues.push(val);
                });
                document.querySelectorAll('input[type="hidden"][name^="numeros"]').forEach(input => {
                    const val = (input.value || '').trim();
                    if (val !== '') numeroValues.push(val);
                });

                const duplicatePlacas = placaValues.filter((value, index, array) => value !== '' && array.indexOf(value) !== index);
                const duplicateNumeros = numeroValues.filter((value, index, array) => value !== '' && array.indexOf(value) !== index);
                const uniqueDuplicatePlacas = [...new Set(duplicatePlacas)];
                const uniqueDuplicateNumeros = [...new Set(duplicateNumeros)];

                placaSelects.forEach(select => {
                    const value = (select.value || '').trim();
                    if (uniqueDuplicatePlacas.includes(value) && value !== '') {
                        const errorDiv = select.closest('td')?.querySelector('.placa-error');
                        if (errorDiv) errorDiv.classList.remove('hidden');
                        select.classList.add('border-danger');
                        hasErrors = true;
                    }
                });

                numeroSelects.forEach(select => {
                    const value = (select.value || '').trim();
                    if (uniqueDuplicateNumeros.includes(value) && value !== '') {
                        const errorDiv = select.closest('td')?.querySelector('.numero-error');
                        if (errorDiv) errorDiv.classList.remove('hidden');
                        select.classList.add('border-danger');
                        hasErrors = true;
                    }
                });

                if (hasErrors) {
                    e.preventDefault();
                    
                    return;
                }

                e.preventDefault(); // Detenemos el submit real para mostrar modal

                const submitter = e.submitter; // Botón que disparó el evento
                const actionUrl = submitter ? submitter.getAttribute('formaction') : '';

                function onModalConfirm() {
                    form.dataset.confirmed = 'true';
                    if (actionUrl) form.action = actionUrl;
                    form.submit();
                }

                const actionLabel = submitter ? submitter.textContent.trim() : 'Confirmar';

                if (hasIncompleteData) {
                    showConfirmModal(
                        'Advertencia: Datos Incompletos',
                        'Faltan placas o números telefónicos. ¿Estás seguro que deseas continuar con los datos incompletos?',
                        actionLabel,
                        onModalConfirm
                    );
                } else {
                    showConfirmModal(
                        'Confirmación',
                        '¿Estás seguro que deseas guardar y continuar con el proceso?',
                        actionLabel,
                        onModalConfirm
                    );
                }
            });
        });
        // Función utilitaria para crear el modal dinámico
        function showConfirmModal(title, text, confirmText, onConfirm) {
            const isWarning = title.toLowerCase().includes('advertencia') || title.toLowerCase().includes('incompleto');
            const iconColor = isWarning ? '#f59e0b' : '#3b82f6';
            const iconBg = isWarning ? '#fffbeb' : '#eff6ff';
            const iconSvg = isWarning
                ? `<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>`
                : `<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>`;

            const modalHtml = `
                <div id="custom-confirm-modal" style="display:flex;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:9999;background:rgba(0,0,0,0.8);align-items:center;justify-content:center;" role="dialog" aria-modal="true">
                    <div style="width:720px;max-width:92%;margin:0 auto;position:relative;border-radius:10px;background:#ffffff;box-shadow:0 20px 40px rgba(2,6,23,0.12);overflow:hidden;">
                        <button type="button" id="btn-close-icon-modal" style="position:absolute;right:16px;top:16px;height:44px;width:44px;border-radius:9999px;border:1px solid #e6e9ee;background:#fff;color:#6b7280;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                        </button>
                        <div style="padding:40px 48px;text-align:center;">
                            <div style="margin:0 auto 24px;display:flex;height:64px;width:64px;align-items:center;justify-content:center;border-radius:9999px;border:1px solid ${iconColor};background:${iconBg};color:${iconColor};">
                                ${iconSvg}
                            </div>
                            <h2 style="font-size:22px;font-weight:600;margin:0;color:#111827;">${title}</h2>
                            <p style="margin-top:12px;color:#6b7280;font-size:15px;line-height:1.6;">${text}</p>
                            <div style="margin-top:32px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;align-items:center;">
                                <button type="button" id="btn-cancel-modal" style="min-width:120px;padding:10px 18px;border-radius:10px;border:1px solid #000000;background:#ffffff;color:#374151;font-weight:600;cursor:pointer;">Cancelar</button>
                                <button type="button" id="btn-confirm-modal" style="min-width:120px;padding:10px 18px;border-radius:10px;background:#c71010;color:#ffffff;font-weight:600;border:none;cursor:pointer;">${confirmText}</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            const modal = document.getElementById('custom-confirm-modal');
            const removeModal = () => modal.remove();
            document.getElementById('btn-close-icon-modal').addEventListener('click', removeModal);
            document.getElementById('btn-cancel-modal').addEventListener('click', removeModal);
            document.getElementById('btn-confirm-modal').addEventListener('click', () => {
                const confirmButton = document.getElementById('btn-confirm-modal');
                const cancelButton = document.getElementById('btn-cancel-modal');

                if (confirmButton) {
                    const text = confirmButton.textContent.trim().toLowerCase();
                    if (text.includes('rechazar')) {
                        confirmButton.textContent = 'Rechazando...';
                    } else if (text.includes('guardar')) {
                        confirmButton.textContent = 'Guardando...';
                    } else if (text.includes('siguiente')) {
                        confirmButton.textContent = 'Siguiente...';
                    } else {
                        confirmButton.textContent = confirmButton.textContent.trim() + '...';
                    }
                    confirmButton.disabled = true;
                }
                if (cancelButton) {
                    cancelButton.disabled = true;
                }

                onConfirm();
            });
        }
    </script>
    <style>
        /* Modal centrado y overlay fijo */
        #modalNuevaPlaca {
            min-height: 100vh;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        #modalNuevaPlaca .modal-dialog {
            max-height: min(90vh, calc(100vh - 3rem));
        }

        #modalNuevaPlaca input:focus,
        #modalNuevaPlaca select:focus,
        #modalNuevaPlaca {
            border-color: #dc2626 !important;
            box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.12) !important;
        }

        .assignment-table-scroll .tom-select.ts-wrapper {
            min-height: 38px !important;
            height: 38px !important;
        }

        .assignment-table-scroll .tom-select.ts-wrapper .ts-control {
            box-sizing: border-box !important;
            display: flex !important;
            min-height: 38px !important;
            height: 38px !important;
            padding: 1px 10px 0 !important;
            line-height: 1.2rem !important;
            align-items: flex-start !important;
        }

        .assignment-table-scroll .tom-select.ts-wrapper .ts-control .items,
        .assignment-table-scroll .tom-select.ts-wrapper .ts-control .item {
            box-sizing: border-box !important;
            display: flex !important;
            align-items: flex-start !important;
            line-height: 1.2rem !important;
            margin: 0 !important;
        }

        .assignment-table-scroll .tom-select.ts-wrapper .ts-control .items {
            min-height: 0 !important;
            height: 100% !important;
        }

        .assignment-table-scroll .tom-select.ts-wrapper .ts-control .item {
            min-height: 0 !important;
            height: 100% !important;
            padding: 0 !important;
        }

        .assignment-table-scroll .tom-select.ts-wrapper .ts-control > input {
            align-self: flex-start !important;
            margin-top: 0 !important;
        }

        .assignment-table-scroll .tom-select.ts-wrapper .ts-control,
        .assignment-table-scroll .tom-select.ts-wrapper .ts-control .items,
        .assignment-table-scroll .tom-select.ts-wrapper .ts-control .item {
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            white-space: nowrap !important;
            max-width: 100%;
        }

        .tom-select.ts-wrapper .ts-dropdown,
        .assignment-table-dropdown {
            max-height: none !important;
            overflow-y: visible !important;
            border-radius: 0.35rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        .tom-select.ts-wrapper .ts-dropdown {
            z-index: 99999 !important;
        }

        .assignment-table-dropdown {
            position: fixed !important;
            z-index: 2147483647 !important;
            margin: 0 !important;
        }

        .tom-select.ts-wrapper .ts-dropdown .ts-dropdown-content,
        .assignment-table-dropdown .ts-dropdown-content {
            max-height: 145px !important;
            overflow-y: auto !important;
        }

        .assignment-table-dropdown .dropdown-input-wrap {
            padding: 0.5rem;
        }

        .assignment-table-dropdown .dropdown-input {
            display: block;
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #e2e8f0;
            border-radius: 0.25rem;
            background: #ffffff;
            outline: none;
        }

        .assignment-table-dropdown .dropdown-input:focus {
            border-color: #b91c1c !important;
            box-shadow: 0 0 0 1px #b91c1c !important;
        }

        .tom-select.ts-wrapper .ts-dropdown .option:hover,
        .assignment-table-dropdown .option:hover {
            background-color: #f1f5f9 !important;
        }

        .tom-select.ts-wrapper .ts-dropdown .option.active,
        .assignment-table-dropdown .option.active,
        .assignment-table-dropdown .option:hover {
            background-color: #b91c1c !important;
            color: #ffffff !important;
        }

        .tom-select.ts-wrapper .ts-dropdown .option[data-selectable="false"],
        .tom-select.ts-wrapper .ts-dropdown .option.disabled,
        .assignment-table-dropdown .option[data-selectable="false"],
        .assignment-table-dropdown .option.disabled {
            display: none !important;
        }

        /* Sugerencias nativas de datalist más legibles */
        datalist,
        datalist option {
            background: #ffffff !important;
            color: #111827 !important;
        }

        .suggestion-list {
            position: absolute;
            top: calc(100% + 0.35rem);
            left: 0;
            right: 0;
            max-height: 240px;
            overflow-y: auto;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 0.75rem;
            box-shadow: 0 15px 30px rgba(15, 23, 42, 0.08);
            z-index: 9999;
        }

        .suggestion-item {
            padding: 0.75rem 1rem;
            cursor: pointer;
            font-size: 0.95rem;
            color: #111827;
            transition: background-color 0.15s ease;
        }

        .suggestion-item:hover,
        .suggestion-item.active {
            background-color: #f8fafc;
        }

        .suggestion-item + .suggestion-item {
            border-top: 1px solid #e5e7eb;
        }

        .tom-select.ts-wrapper .dropdown-input:focus,
        .tom-select.ts-wrapper .dropdown-input {
            border-color: #b91c1c !important;
            box-shadow: 0 0 0 1px #b91c1c !important;
        }
    </style>
@endpush

@push('styles')
    <style>
        /* Modal 'Asignar Planes y Servicios' - tamaño fijo y contenido scrollable */
        #modalPlanes .modal-dialog {
            max-height: 560px; /* límite razonable del modal en pantallas grandes */
        }

        /* Mostrar máximo ~7 ítems (cada ítem aprox 56px). Ajustar si el diseño cambia */
        #planesListContainer {
            max-height: calc(7 * 56px);
            overflow-y: auto;
        }

        /* Asegurar que el contenedor interno tenga espacio para padding y no crezca el modal */
        #modalPlanes .bg-white.p-5 {
            padding: 1.25rem;
            box-sizing: border-box;
        }

        /* Table scroll wrapper only on mobile */
        .table-scroll-wrapper {
            overflow-x: visible;
            width: 100%;
        }

        .table-scroll-wrapper table {
            width: 100%;
            min-width: 0;
        }

        .assignment-table-scroll {
            overflow-x: auto !important;
            overflow-y: hidden;
            scrollbar-width: thin;
            -webkit-overflow-scrolling: touch;
        }

        .assignment-table-scroll::-webkit-scrollbar {
            height: 8px;
        }

        .assignment-table-scroll::-webkit-scrollbar-thumb {
            background: rgba(15, 23, 42, 0.3);
            border-radius: 9999px;
        }

        @media (max-width: 768px) {
            .table-scroll-wrapper {
                display: block;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                width: 100%;
                position: relative;
            }

            .table-scroll-wrapper::-webkit-scrollbar {
                height: 8px;
            }

            .table-scroll-wrapper::-webkit-scrollbar-thumb {
                background: rgba(15, 23, 42, 0.18);
                border-radius: 9999px;
            }

            .table-scroll-wrapper table {
                width: auto !important;
                min-width: 920px;
                max-width: none !important;
            }
        }
    </style>
@endpush