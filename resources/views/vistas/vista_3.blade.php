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
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-medium">{{ $title ?? 'Asignación de Placas y Servicios' }}</h2>
        @if(($vista->idvista ?? 0) >= 2 && ($vista->idvista ?? 0) <= 6)
            <form method="POST" action="{{ $advanceUrl ?? '#' }}" class="ml-auto" id="rechazar-form">
                @csrf
                <input type="hidden" name="action" value="back_to_previous">
                <input type="hidden" name="current_vista_id" value="{{ $vista->idvista ?? '' }}">
                <button type="button" id="rechazar-btn"
                    class="ml-auto inline-flex items-center justify-center rounded-md border border-slate-600 px-4 py-2 text-sm font-semibold text-slate-600 transition duration-200 hover:bg-slate-100"
                    onclick="abrirModalRechazar()">
                    <i data-lucide="x-circle" class="mr-2 h-4 w-4"></i>
                    Rechazar
                </button>
            </form>
        @endif
    </div>
    <div class="box box--stacked grid grid-cols-12">
        <div class="col-span-12">
            @if(isset($cotizaciones) && count($cotizaciones) > 0)
                @php
                    $cotizacionesToShow = collect($cotizaciones)->take(1);
                @endphp
                <div class="box p-5">
                    <h2 class="text-lg font-medium mr-auto border-b pb-2 mb-4">Información de la Cotización</h2>
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
                                <div class="text-sm font-semibold text-slate-700 mb-2">Comentarios de la Cotización</div>
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
                                    <div class="text-sm text-slate-600">-</div>
                                @endif
                            </div>
                        </div>
                    @endforeach


                    <!-- Resumen de Planes/Servicios Disponibles -->
                    @if(isset($servicios) && count($servicios) > 0)
                        <div class="mb-4 p-4 bg-slate-100 border bg-slate-100 rounded-md">
                            <h4 class="font-medium text-slate-700 mb-2">Planes, Servicios y Sensores Disponibles a Asignar:</h4>
                            <ul class="list-disc pl-5 text-sm text-slate-600">
                                @foreach($servicios as $srv)
                                    <li><strong>{{ (int) $srv->cantidad }}x</strong> {{ $srv->producto }} ({{ $srv->tipo_nombre }})</li>
                                @endforeach
                            </ul>
                            <p class="text-xs text-slate-500 mt-2">* Debes asignar todos estos planes, servicios y sensores entre
                                los equipos mostrados abajo.</p>
                        </div>
                    @endif

                    <div class="flex flex-col gap-3 border-b pb-2 mb-4 md:flex-row md:items-center md:justify-between">
                        <h2 class="text-lg font-medium">Asignación por Equipo</h2>
                        <button type="button"
                            class="inline-flex items-center justify-center rounded-md border px-4 py-2 text-sm font-semibold text-white transition duration-200"
                            style="background-color:#c71010;" onclick="abrirModalPlaca()">
                            <i data-lucide="plus" class="w-4 h-4"></i>
                            <span>Añadir Placa / Vehículo</span>
                        </button>
                    </div>

                    <form id="asignacion-form" method="POST" action="{{ $advanceUrl ?? '#' }}" data-lock-action="ticket">
                        @csrf
                        <input type="hidden" name="current_vista_id" value="{{ $vista->idvista ?? '' }}">
                        <input type="hidden" name="action" value="{{ $actionValue ?? 'save' }}">
                        <input type="hidden" id="erp-lock-resource" value="{{ $lockResource ?? 'ticket' }}">
                        <input type="hidden" id="erp-lock-id" value="{{ $lockId ?? ($ticket->idticket ?? '') }}">

                        @if(isset($equipamiento) && count($equipamiento) > 0)
                            <div id="form-validation-error" class="hidden mb-4 rounded-md border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger"></div>
                            <div class="overflow-visible rounded-3xl border border-slate-200 bg-slate-50 shadow-sm mb-6">
                                <div class="overflow-x-auto table-scroll-wrapper">
                                    <table class="min-w-[920px] w-full text-left text-sm text-slate-600 table-fixed">
                                    <thead class="bg-slate-100 text-xs uppercase tracking-[0.16em] text-slate-500">
                                        <tr>
                                            <th class="px-4 py-3" style="width: 210px;">Producto / IMEI</th>
                                            <th class="px-4 py-3" style="width: 250px;">Placa / Vehículo</th>
                                            <th class="px-4 py-3" style="width: 250px;">Número Telefónico</th>
                                            <th class="px-4 py-3">Plan / Servicio / Sensor</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($equipamiento as $item)
                                            @for($i = 0; $i < (int) $item->cantidad; $i++)
                                                @php
                                                    $imeiSeleccionado = $item->imeis_seleccionados[$i] ?? null;
                                                    $idxInput = $item->iddetalleCotizacion . '_' . $i;
                                                @endphp
                                                <tr class="border-t border-slate-200 bg-white">
                                                    <td data-label="Producto / IMEI" class="px-4 py-4 text-slate-900 vertical-align-middle">
                                                        <div class="w-full">
                                                            <div class="font-medium truncate" title="{{ $item->producto }}">
                                                                {{ $item->producto }}</div>
                                                            @if($imeiSeleccionado)
                                                                <div class="mt-1 text-slate-700">IMEI: {{ $imeiSeleccionado }}</div>
                                                                <input type="hidden"
                                                                    name="imeis_completados[{{ $item->iddetalleCotizacion }}][]"
                                                                    value="{{ $imeiSeleccionado }}">
                                                            @else
                                                                @php
                                                                    $v3Imei = old("imeis_completados.{$item->iddetalleCotizacion}.{$i}", '');
                                                                    $v3Invalid = $v3Imei !== '' && in_array($v3Imei, session('invalidImeis', []));
                                                                    $availableImeis = $item->availableImeis ?? [];
                                                                @endphp
                                                                <select name="imeis_completados[{{ $item->iddetalleCotizacion }}][]"
                                                                    data-valid-imeis='@json($availableImeis)'
                                                                    class="imei-input tom-select imei-select w-full rounded-lg border {{ $v3Invalid ? 'border-danger text-danger' : 'border-slate-300' }} px-3 py-2 text-sm text-slate-900 mt-2"
                                                                    data-placeholder="Selecciona IMEI">
                                                                    <option value="">Selecciona IMEI</option>
                                                                    @if($v3Imei && !in_array($v3Imei, $availableImeis))
                                                                        <option value="{{ $v3Imei }}" selected>{{ $v3Imei }}</option>
                                                                    @endif
                                                                    @foreach($availableImeis as $avImei)
                                                                        <option value="{{ $avImei }}" {{ (string)$v3Imei === (string)$avImei ? 'selected' : '' }}>{{ $avImei }}</option>
                                                                    @endforeach
                                                                </select>
                                                                @if($v3Invalid)
                                                                    <div class="text-danger text-xs mt-1 font-semibold">IMEI incorrecto, escribe
                                                                        otro IMEI</div>
                                                                @endif
                                                                <div class="imei-error text-danger text-xs mt-2 hidden">El IMEI no existe o no
                                                                    corresponde.</div>
                                                            @endif
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

                                                    <td data-label="Plan / Servicio / Sensor" class="px-4 py-4 vertical-align-middle">
                                                        @php
                                                            $planesGuardados = $item->planes_seleccionados[$i] ?? [];
                                                        @endphp
                                                        <div class="planes-container flex flex-wrap gap-2 items-center"
                                                            data-input-name="planes[{{ $item->iddetalleCotizacion }}][{{ $i }}][]"
                                                            data-row-id="{{ $item->iddetalleCotizacion }}_{{ $i }}">
                                                            @foreach($planesGuardados as $planId)
                                                                @php
                                                                    $baseId = explode('_', $planId)[0] ?? '';
                                                                    $planIdx = explode('_', $planId)[1] ?? '';
                                                                    $srvMatch = collect($servicios ?? [])->firstWhere('iddetalleCotizacion', $baseId);
                                                                    $planLabel = $srvMatch ? ($srvMatch->producto . ((int) ($srvMatch->cantidad ?? 1) > 1 ? " ($planIdx)" : '')) : $planId;
                                                                @endphp
                                                                <span class="plan-badge inline-flex items-center gap-1.5 rounded-lg pl-3 pr-2 py-1 text-xs font-semibold text-slate-700 border border-slate-300">
                                                                    {{ $planLabel }}
                                                                </span>
                                                                <input type="hidden" name="planes[{{ $item->iddetalleCotizacion }}][{{ $i }}][]"
                                                                    value="{{ $planId }}" class="plan-hidden-input">
                                                            @endforeach
                                                            <button type="button"
                                                                class="btn-add-plan inline-flex items-center justify-center rounded-md border border-slate-300 bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-500 shadow-sm">
                                                                <i data-lucide="plus" class="w-3.5 h-3.5 mr-1 text-slate-500"></i>
                                                                Agregar Plan/Servicio/Sensor
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endfor
                                        @endforeach
                                    </tbody>
                                    </table>
                                </div>
                            </div>
                        @else
                            <div class="rounded-3xl border border-slate-200 bg-slate-50 p-6 text-slate-600 mb-6">
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
            <div class="grid grid-cols-2 gap-4 bg-white p-7 md:grid-cols-2">
                <div>
                    <label for="nueva-placa-input" class="mb-2 block text-sm font-medium text-slate-700">Placa</label>
                    <input id="nueva-placa-input" name="placa" type="text"
                        class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 focus:border-red-600 focus:ring-2 focus:ring-red-500/20"
                        placeholder="Ej: ABC-123">
                </div>
                <div>
                    <label for="nuevo-cliente-select"
                        class="mb-2 block text-sm font-medium text-slate-700">Cliente</label>
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
                    <label for="nuevo-tipo-vehiculo-select" class="mb-2 block text-sm font-medium text-slate-700">Tipo de
                        Vehículo</label>
                    <select id="nuevo-tipo-vehiculo-select" name="tipoUnidad_idtable1"
                        class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 focus:border-red-600 focus:ring-2 focus:ring-red-500/20"
                        data-placeholder="Selecciona tipo de vehículo...">
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
                <div class="relative">
                    <label for="nuevo-color-input" class="mb-2 block text-sm font-medium text-slate-700">Color</label>
                    <input id="nuevo-color-input" name="color" type="text"
                        class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 focus:border-red-600 focus:ring-2 focus:ring-red-500/20"
                        placeholder="Ej: Blanco" autocomplete="off">
                    <div id="color-suggestions" class="suggestion-list hidden"></div>
                </div>
                <div>
                    <label for="nuevo-tracto-select" class="mb-2 block text-sm font-medium text-slate-700">Tracto</label>
                    <select id="nuevo-tracto-select" name="tracto"
                        class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 focus:border-red-600 focus:ring-2 focus:ring-red-500/20"
                        data-placeholder="Selecciona tracto...">
                        <option value="">Selecciona tracto...</option>
                        <option value="Si">Si</option>
                        <option value="No">No</option>
                    </select>
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4">
                <button type="button"
                    class="rounded-md border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-100"
                    onclick="cerrarModalPlaca()">Cancelar</button>
                <button type="button"
                    class="rounded-md border-0 px-4 py-2 text-xs font-semibold text-white shadow-sm transition duration-200"
                    style="background-color: #c1121f;" onclick="guardarNuevaPlaca()">Guardar</button>
            </div>
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
                    <h2 class="text-lg font-semibold text-slate-900">Asignar Planes y Servicios</h2>
                    <p class="mt-1 text-xs text-slate-600">Selecciona los planes que deseas agregar a este equipo.</p>
                </div>
                <button type="button"
                    class="ml-auto rounded-lg border-0 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-100 hover:text-red-600 transition duration-200 flex-shrink-0"
                    onclick="cerrarModalPlanes()">
                    <i data-lucide="x" class="h-4 w-4"></i>
                </button>
            </div>
            <div class="bg-white p-5">
                <input type="hidden" id="currentPlanRowId" value="">
                <div id="planesListContainer" class="flex flex-col gap-3 max-h-64 overflow-y-auto pr-2">
                    <!-- Checkboxes dinámicos -->
                </div>
            </div>
            <div class="flex items-center justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4">
                <button type="button"
                    class="rounded-md border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-100 transition duration-200"
                    onclick="cerrarModalPlanes()">Cancelar</button>
                <button type="button"
                    class="rounded-md border-0 px-4 py-2 text-xs font-semibold text-white shadow-sm transition duration-200"
                    style="background-color: #c1121f;" onclick="guardarPlanesSeleccionados()">Confirmar Selección</button>
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
            modalElement.classList.add('hidden');
            modalElement.classList.remove('flex');
            document.body.style.overflow = '';
            document.getElementById('nueva-placa-input').value = '';
        }

        async function guardarNuevaPlaca() {
            const placa = document.getElementById('nueva-placa-input').value.trim().toUpperCase();
            const ticketId = document.getElementById('erp-lock-id')?.value || '{{ $ticket->idticket ?? "" }}';
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
                    console.warn(data?.message || 'No se pudo guardar la placa. El servidor respondió con un error.');
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
                    console.warn(data?.message || 'Error al guardar la placa.');
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

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

        // Exclusividad para IMEIs: deshabilita opciones ya seleccionadas en otras filas
        function syncExclusiveImeis() {
            const selects = document.querySelectorAll('select.imei-select');
            // IMEIs currently selected in tomselects
            const selectedFromSelects = Array.from(selects).map(s => (s.value || '').trim()).filter(v => v !== '');
            // IMEIs already saved/completados (hidden inputs)
            const hiddenImeis = Array.from(document.querySelectorAll('input[type="hidden"][name^="imeis_completados"]'))
                .map(h => (h.value || '').trim())
                .filter(v => v !== '');

            const selected = [...new Set([...selectedFromSelects, ...hiddenImeis])];

            selects.forEach(select => {
                const ts = select.tomselect || select.tomSelect || select._tomselect;
                if (!ts) return;
                const current = (select.value || '').trim();

                Object.values(ts.options).forEach(opt => {
                    if (!opt.value) return;
                    const shouldDisable = opt.value && selected.includes(opt.value) && opt.value !== current;
                    ts.updateOption(opt.value, { ...opt, disabled: shouldDisable });
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
                            id: '{{ $srv->iddetalleCotizacion }}_{{ $j }}',
                            nombre: '{{ $srv->producto }} {{ (int) $srv->cantidad > 1 ? "($j)" : "" }}'
                        },
                    @endfor
                @endforeach
            @endif
        ];

        let planesSelections = {};

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
                    abrirModalPlanes(this.closest('.planes-container'));
                });
            });
        });

        function abrirModalPlanes(container) {
            const rowId = container.dataset.rowId;
            document.getElementById('currentPlanRowId').value = rowId;
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
                if (selectedInOtherRows.includes(plan.id)) return;
                optionsCount++;

                const isChecked = currentSelections.includes(plan.id) ? 'checked' : '';
                const html = `
                    <label class="flex items-center gap-4 p-3 border border-slate-200 rounded-lg cursor-pointer hover:bg-slate-50 transition duration-200">
                        <input type="checkbox" class="plan-checkbox w-4 h-4 text-primary rounded border-slate-300 focus:ring-primary" value="${plan.id}" data-name="${plan.nombre}" ${isChecked}>
                        <span class="text-sm font-medium text-slate-700 select-none">${plan.nombre}</span>
                    </label>
                `;
                listContainer.insertAdjacentHTML('beforeend', html);
            });

            if (optionsCount === 0) {
                listContainer.innerHTML = '<p class="text-sm text-slate-500 italic p-3 text-center">Todos los planes, servicios y sensores ya han sido asignados.</p>';
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
            const container = document.querySelector(`.planes-container[data-row-id="${rowId}"]`);
            const inputName = container.dataset.inputName;

            const checkboxes = document.querySelectorAll('#planesListContainer .plan-checkbox:checked');
            const newSelections = [];

            checkboxes.forEach(cb => {
                newSelections.push({ id: cb.value, nombre: cb.dataset.name });
            });

            planesSelections[rowId] = newSelections.map(s => s.id);

            // Limpiar inputs y badges previos
            container.querySelectorAll('.plan-hidden-input, .plan-badge').forEach(el => el.remove());

            const btnAdd = container.querySelector('.btn-add-plan');

            newSelections.forEach(sel => {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = inputName;
                hiddenInput.value = sel.id;
                hiddenInput.className = 'plan-hidden-input';
                container.insertBefore(hiddenInput, btnAdd);

                const badge = document.createElement('span');
                badge.className = 'plan-badge inline-flex items-center gap-1.5 rounded-lg  pl-3 pr-2 py-1 text-xs font-semibold text-slate-700 border border-slate-300';
                badge.innerHTML = ` 
                    ${sel.nombre}
                    <button type="button" class="text-slate-700 hover:text-slate-600 rounded-full p-0.5 flex items-center justify-center" onclick="removerPlan('${rowId}', '${sel.id}')">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 6 6 18"/><path d="m6 6 12 12"/>
                        </svg>
                    </button>
                `;
                container.insertBefore(badge, btnAdd);
            });

            if (typeof lucide !== 'undefined') lucide.createIcons();
            cerrarModalPlanes();
        }

        function removerPlan(rowId, planId) {
            planesSelections[rowId] = planesSelections[rowId].filter(id => id !== planId);
            const container = document.querySelector(`.planes-container[data-row-id="${rowId}"]`);

            const hiddenInput = container.querySelector(`input[type="hidden"][value="${planId}"]`);
            if (hiddenInput) hiddenInput.remove();

            const badges = container.querySelectorAll('.plan-badge');
            badges.forEach(b => {
                if (b.querySelector('button').getAttribute('onclick').includes(planId)) {
                    b.remove();
                }
            });
        }

        // Validación de IMEIs ingresados manualmente
        function initTomSelects() {
            if (typeof window.TomSelect !== 'function') {
                return;
            }

            document.querySelectorAll('select.tom-select').forEach(select => {
                if (select.tomselect || select.tomSelect || select._tomselect) {
                    return;
                }

                try {
                    // Evaluamos si el elemento HTML original tiene la propiedad multiple activa
                    const isMultiple = select.hasAttribute('multiple');

                    const instance = new TomSelect(select, {
                        allowEmptyOption: true,
                        maxItems: isMultiple ? null : 1, // Si es múltiple, permite infinitos ítems
                        placeholder: select.dataset.placeholder || '',
                        hidePlaceholder: false, // Cambiado a false para que mantenga el texto de guía limpio
                        plugins: isMultiple ? ['remove_button'] : [], // Formato correcto para declarar el plugin en TomSelect
                        create: false,
                        closeAfterSelect: !isMultiple, // No cierra el menú de inmediato si se van a elegir varios
                        dropdownParent: 'body',
                        onDelete: function (values) { return true; } // Evita alertas de confirmación
                    });

                    if (select.id && instance?.control_input) {
                        instance.control_input.id = `${select.id}-ts-control`;
                    }

                    if (instance?.wrapper) {
                        instance.wrapper.style.width = '100%';
                    }

                    instance.on('change', function (value) {
                        if (select.classList.contains('placa-select')) syncExclusiveSelects('.placa-select');
                        if (select.classList.contains('numero-select')) syncExclusiveSelects('.numero-select');
                        if (select.classList.contains('imei-select')) {
                            syncExclusiveImeis();

                            // Validación específica para IMEI: inválido o duplicado
                            const val = (value || '').trim();
                            const errorDiv = select.closest('td')?.querySelector('.imei-error') || select.nextElementSibling;
                            if (errorDiv) errorDiv.classList.add('hidden');
                            select.classList.remove('border-danger');

                            if (val !== '') {
                                try {
                                    const validImeis = JSON.parse(select.dataset.validImeis || '[]');
                                    // check invalid (not in valid list)
                                    if (!validImeis.includes(val)) {
                                        if (errorDiv) {
                                            errorDiv.textContent = 'IMEI duplicado o inválido.';
                                            errorDiv.classList.remove('hidden');
                                        }
                                        select.classList.add('border-danger');
                                    } else {
                                        // check duplicate against hidden completed imeis and other selects
                                        const hiddenImeis = Array.from(document.querySelectorAll('input[type="hidden"][name^="imeis_completados"]')).map(h => (h.value||'').trim()).filter(v=>v!=='');
                                        const otherSelected = Array.from(document.querySelectorAll('select.imei-select')).map(s => (s.value||'').trim()).filter(v=>v!=='');
                                        const occurrences = [...hiddenImeis, ...otherSelected].filter(x => x === val).length;
                                        // If more than 1 occurrence (meaning duplicate elsewhere) or present in hiddenImeis
                                        if (occurrences > 1 || hiddenImeis.includes(val)) {
                                            if (errorDiv) {
                                                errorDiv.textContent = 'IMEI duplicado o inválido.';
                                                errorDiv.classList.remove('hidden');
                                            }
                                            select.classList.add('border-danger');
                                        }
                                    }
                                } catch (err) {
                                    console.error('Error parseando IMEIs', err);
                                }
                            }
                        }
                    });
                } catch (error) {
                    console.warn('TomSelect initialization failed:', error);
                }
            });

            // Sincronizar estado inicial por si vienen con valores previos
            syncExclusiveSelects('.placa-select');
            syncExclusiveSelects('.numero-select');
            syncExclusiveImeis();
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

            initTomSelects();

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
                const formError = document.getElementById('form-validation-error');
                const imeiInputs = document.querySelectorAll('.imei-input');
                const placaSelects = document.querySelectorAll('select.placa-select');
                const numeroSelects = document.querySelectorAll('select.numero-select');
                const placaValues = [];
                const numeroValues = [];

                if (formError) {
                    formError.classList.add('hidden');
                    formError.textContent = '';
                }

                imeiInputs.forEach(input => {
                    const val = (input.value || '').trim();
                    const errorDiv = input.closest('td')?.querySelector('.imei-error') || input.nextElementSibling;
                    if (errorDiv) errorDiv.classList.add('hidden');
                    input.classList.remove('border-danger');

                    if (val !== '') {
                        try {
                            const validImeis = JSON.parse(input.dataset.validImeis || '[]');
                            if (!validImeis.includes(val)) {
                                hasErrors = true;
                                if (errorDiv) errorDiv.classList.remove('hidden');
                                input.classList.add('border-danger');
                            }
                        } catch (err) {
                            console.error("Error parseando IMEIs", err);
                        }
                    }
                });

                placaSelects.forEach(select => {
                    const value = (select.value || '').trim();
                    const errorDiv = select.closest('td')?.querySelector('.placa-error');
                    if (errorDiv) errorDiv.classList.add('hidden');
                    select.classList.remove('border-danger');
                    if (value !== '') {
                        placaValues.push(value);
                    }
                });

                numeroSelects.forEach(select => {
                    const value = (select.value || '').trim();
                    const errorDiv = select.closest('td')?.querySelector('.numero-error');
                    if (errorDiv) errorDiv.classList.add('hidden');
                    select.classList.remove('border-danger');
                    if (value !== '') {
                        numeroValues.push(value);
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

                // 2. MOSTRAR MODAL TIPO 2 (Confirmación genérica)
                e.preventDefault(); // Detenemos el submit real para mostrar modal

                const submitter = e.submitter; // Botón que disparó el evento
                const actionUrl = submitter ? submitter.getAttribute('formaction') : '';

                function onModalConfirm() {
                    form.dataset.confirmed = 'true';
                    if (actionUrl) form.action = actionUrl;
                    form.submit();
                }

                const actionLabel = submitter ? submitter.textContent.trim() : 'Confirmar';

                showConfirmModal(
                    'Confirmación',
                    '¿Estás seguro que deseas guardar y continuar con el proceso?',
                    actionLabel,
                    onModalConfirm
                );
            });
        });

        // Función utilitaria para crear el modal dinámico con diseño Tailwind
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
    // Funciones para el modal de Rechazar en vista 2 (cliente-side)

    function abrirModalRechazar() {
        const form = document.getElementById('rechazar-form');
        showConfirmModal(
            'Confirmación',
            '¿Estás seguro que deseas rechazar y regresar a la vista anterior?',
            'Rechazar',
            () => {
                if (form) {
                    form.submit();
                }
            }
        );
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

        .tom-select.ts-wrapper,
        .tom-select.ts-wrapper .ts-control,
        .tom-select.plugin-dropdown_input.focus.dropdown-active .ts-control {
            min-height: 2.2rem !important;
            height: 2.2rem !important;
            padding: 0.2rem 0.75rem !important;
            line-height: 1.2rem !important;
        }

        .tom-select.ts-wrapper .ts-control {
            min-height: 2.2rem !important;
            height: 2.2rem !important;
            padding: 0.2rem 0.75rem 0.1rem 0.75rem !important;
            line-height: 1.2rem !important;
            align-items: flex-start !important;
        }

        .tom-select.ts-wrapper .ts-control .items,
        .tom-select.ts-wrapper .ts-control .item {
            min-height: 2rem !important;
            height: auto !important;
            line-height: 1.2rem !important;
            margin: 0 !important;
        }

        .tom-select .ts-wrapper .ts-control .item {
            padding: 0 .35rem !important;
        }

        /* Truncar textos largos dentro de TomSelect con puntos suspensivos */
        .tom-select .ts-wrapper .ts-control,
        .tom-select .ts-control .items,
        .tom-select .ts-control .item {
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            white-space: nowrap !important;
            max-width: 100%;
        }

        /* Ajustes para el dropdown de TomSelect: scroll y color de seleccion */
        .tom-select.ts-wrapper .ts-dropdown {
            max-height: none !important;
            overflow-y: visible !important;
            z-index: 9999 !important;
            border-radius: 0.35rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            position: absolute !important;
        }

        .tom-select.ts-wrapper .ts-dropdown .ts-dropdown-content {
            max-height: 200px !important;
            overflow-y: auto !important;
        }

        .tom-select.ts-wrapper .ts-dropdown .option:hover {
            background-color: #f1f5f9 !important;
        }

        .tom-select.ts-wrapper .ts-dropdown .option:hover,
        .tom-select.ts-wrapper .ts-dropdown .option.active,
        .option.active {
            background-color: #b91c1c !important;
            color: #ffffff !important;
        }

        /* Ocultar opciones agotadas (deshabilitadas) para que no salgan en el listado */
        .tom-select.ts-wrapper .ts-dropdown .option[data-selectable="false"],
        .tom-select.ts-wrapper .ts-dropdown .option.disabled {
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

        /* Color rojo para el borde cuando esta enfocado */
        .tom-select.ts-wrapper .dropdown-input:focus,
        .tom-select.ts-wrapper .dropdown-inputWrap {
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