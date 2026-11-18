@extends('dashboard.overview-1')
@section('title', $title ?? 'Despacho de mercadería')
@section('header', $title ?? 'Despacho de mercadería')
@section('breadcrumb')
    <nav aria-label="breadcrumb" class="flex hidden flex-1 xl:block">
        <ol class="flex items-center text-theme-1">
            <li><a href="{{ route('home') }}">Inicio</a></li>
            <li
                class="relative ml-5 pl-0.5 before:content-[''] before:w-[14px] before:h-[14px] before:bg-chevron-black before:transform before:rotate-[-90deg] before:bg-[length:100%] before:-ml-[1.125rem] before:absolute before:my-auto before:inset-y-0 text-slate-600 cursor-text">
                <span>{{ $title ?? 'Despacho de mercadería' }}</span>
            </li>
        </ol>
    </nav>
@endsection
@section('content')
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <h1 class="inline-flex min-w-0 flex-1 items-center gap-2 rounded-sm pr-2 text-xl font-semibold text-slate-900">
            <span class="h-5 w-1.5 shrink-0 rounded-full bg-danger" aria-hidden="true"></span>
            <span class="truncate">{{ $title ?? 'Despacho de mercadería' }}</span>
        </h1>
        <span class="inline-flex shrink-0 items-center rounded-full border border-danger bg-danger px-2.5 py-1 text-xs font-medium text-white"
            aria-label="Paso {{ $currentStep }} de {{ $totalSteps }}">
            Fase {{ $currentStep }} de {{ $totalSteps }}
        </span>
        @if(($vista->idvista ?? 0) >= 2 && ($vista->idvista ?? 0) <= 7)
            <form method="POST" action="{{ $advanceUrl ?? '#' }}" class="shrink-0" id="rechazar-form" data-lock-action="ticket.vista">
                @csrf
                <input type="hidden" name="action" value="back_to_previous">
                <input type="hidden" name="current_vista_id" value="{{ $vista->idvista ?? '' }}">
                <button type="button" id="rechazar-btn"
                    class="inline-flex items-center justify-center rounded-md border border-slate-600 px-4 py-2 text-sm font-semibold text-slate-600 transition duration-200 hover:bg-slate-100" style="background-color: white;"
                    onclick="abrirModalRechazar()">
                    <i data-lucide="x-circle" class="mr-2 h-4 w-4"></i>
                    Rechazar
                </button>
            </form>
        @endif
    </div>
    <div class="box box--stacked grid w-full min-w-0 max-w-full grid-cols-12" style="overflow-x: clip; overflow-y: visible;">
        <div class="col-span-12 min-w-0">
            <!-- Bloque 1: Información de Cotizaciones -->
            @if(isset($cotizaciones) && count($cotizaciones) > 0)
                @php
                    $cotizacionesToShow = collect($cotizaciones)->take(1);
                @endphp
                <div class="box min-w-0 max-w-full p-5">
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
                            <div><strong>N° Pedido:</strong> {{ $cot->nroPedido ?? '-' }}</div>
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

                    <h2 class="mb-3 flex items-center gap-2 border-b pb-2 text-base font-semibold text-slate-800">
                        <i data-lucide="package" class="h-4 w-4 shrink-0 text-slate-500" aria-hidden="true"></i>
                        Asignación de Equipo
                    </h2>
                    <form id="imei-form" class="w-full min-w-0" method="POST" action="{{ $advanceUrl ?? '#' }}" data-lock-action="ticket.vista">
                        @csrf
                        @if(isset($equipamiento) && count($equipamiento) > 0)
                        @php
                            $sensorAssignments = $componentAssignments['sensors'] ?? [];
                            $accessoryAssignments = $componentAssignments['accessories'] ?? [];
                            $sensorSlots = collect($sensorAssignments)
                                ->groupBy(fn($component) => $component['equipmentDetailId'] . '_' . $component['equipmentIndex'])
                                ->map(fn($components) => $components->count())
                                ->max() ?? 0;
                            $accessorySlots = collect($accessoryAssignments)
                                ->groupBy(fn($component) => $component['equipmentDetailId'] . '_' . $component['equipmentIndex'])
                                ->map(fn($components) => $components->count())
                                ->max() ?? 0;
                            $hasComponentColumns = $sensorSlots + $accessorySlots > 0;
                            $tableMinWidth = 790 + (550 * ($sensorSlots + $accessorySlots));
                        @endphp
                            <div class="w-full min-w-0 max-w-full overflow-hidden rounded-3xl border border-slate-200 bg-slate-50 shadow-sm">
                            <div class="assignment-table-scroll w-full min-w-0 max-w-full overflow-x-auto overscroll-x-contain">
                            <table class="min-w-full table-fixed text-left text-sm text-slate-600" style="width: {{ $hasComponentColumns ? $tableMinWidth . 'px' : '100%' }};">
                                    <thead class="bg-slate-200 text-xs uppercase tracking-[0.16em] text-slate-700">
                                        <tr>
                                            <th class="whitespace-nowrap px-4 py-3" style="width: {{ $hasComponentColumns ? '320px' : '40%' }};">Producto</th>
                                            <th class="whitespace-nowrap px-4 py-3" style="width: {{ $hasComponentColumns ? '210px' : '30%' }};">Vehículo</th>
                                            <th class="whitespace-nowrap px-4 py-3" style="width: {{ $hasComponentColumns ? '260px' : '30%' }};">IMEI</th>
                                        @for($sensorSlot = 1; $sensorSlot <= $sensorSlots; $sensorSlot++)
                                            <th class="whitespace-nowrap px-4 py-3" style="width: 270px;">SENSOR {{ $sensorSlot }}</th>
                                            <th class="whitespace-nowrap px-4 py-3" style="width: 280px;">IMEI SENSOR {{ $sensorSlot }}</th>
                                        @endfor
                                        @for($accessorySlot = 1; $accessorySlot <= $accessorySlots; $accessorySlot++)
                                            <th class="whitespace-nowrap px-4 py-3" style="width: 270px;">ACCESORIO {{ $accessorySlot }}</th>
                                            <th class="whitespace-nowrap px-4 py-3" style="width: 280px;">IMEI ACCESORIO {{ $accessorySlot }}</th>
                                        @endfor
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($equipamiento as $item)
                                            @php
                                                $availableImeis = is_array($item->availableImeis ?? null)
                                                    ? $item->availableImeis
                                                    : [];
                                                $availableImeis = array_values(array_unique(array_filter($availableImeis, fn($value) => trim((string) $value) !== '')));
                                            @endphp
                                            @for($i = 0; $i < (int) $item->cantidad; $i++)
                                                @php
                                                    $equipmentKey = (string) $item->iddetalleCotizacion . '_' . $i;
                                                    $sensorsForRow = collect($sensorAssignments)
                                                        ->filter(fn($component) => $component['equipmentDetailId'] . '_' . $component['equipmentIndex'] === $equipmentKey)
                                                        ->values();
                                                    $accessoriesForRow = collect($accessoryAssignments)
                                                        ->filter(fn($component) => $component['equipmentDetailId'] . '_' . $component['equipmentIndex'] === $equipmentKey)
                                                        ->values();
                                                @endphp
                                                <tr class="border-t border-slate-300 bg-white">
                                                    <td class="break-words px-4 py-4 text-slate-900">{{ $item->producto }}</td>
                                                    <td class="px-4 py-3 text-slate-900">
                                                        @php
                                                            $placaAsignada = old(
                                                                "placas.{$item->iddetalleCotizacion}.{$i}",
                                                                $tempData['placas'][$item->iddetalleCotizacion][$i] ?? ''
                                                            );
                                                        @endphp
                                                        {{ trim((string) $placaAsignada) !== '' ? $placaAsignada : 'Sin vehículo asignado' }}
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        @php
                                                            $currentImei = old("imeis.{$item->iddetalleCotizacion}.{$i}", $tempData['imeis'][$item->iddetalleCotizacion][$i] ?? '');
                                                            $isInvalid = in_array($currentImei, session('invalidImeis', [])) && trim($currentImei) !== '';
                                                        @endphp
                                                        <select name="imeis[{{ $item->iddetalleCotizacion }}][]"
                                                            data-valid-imeis='@json($availableImeis)'
                                                            class="imei-input tom-select tom-select--compact form-control w-full rounded-lg border {{ $isInvalid ? 'border-danger  text-danger' : 'border-slate-300' }} px-3 py-2 text-sm text-slate-900 transition duration-200 ease-in-out focus:border-primary focus:ring-1 focus:ring-primary">
                                                            <option value="">{{ 'Selecciona IMEI ' . ($i + 1) }}</option>
                                                            @foreach($availableImeis as $avImei)
                                                                <option value="{{ $avImei }}" {{ $currentImei == $avImei ? 'selected' : '' }}>{{ $avImei }}</option>
                                                            @endforeach
                                                        </select>
                                                        @if($isInvalid)
                                                            <div class="text-danger text-xs mt-1 font-semibold">IMEI incorrecto escribe otro IMEI</div>
                                                        @endif
                                                        <div class="imei-error text-danger text-xs mt-1 hidden">El IMEI ingresado no existe
                                                            o no corresponde a este producto.</div>
                                                    </td>
                                                    @for($sensorIndex = 0; $sensorIndex < $sensorSlots; $sensorIndex++)
                                                        @php $sensor = $sensorsForRow[$sensorIndex] ?? null; @endphp
                                                        @if($sensor)
                                                            @php
                                                                $componentImei = old(
                                                                    "component_imeis.{$sensor['detailId']}.{$sensor['unitIndex']}",
                                                                    $sensor['selectedImei'] ?? ''
                                                                );
                                                                $componentInvalid = in_array($componentImei, session('invalidImeis', [])) && trim((string) $componentImei) !== '';
                                                            @endphp
                                                            <td class="break-words px-4 py-4 text-slate-900">{{ $sensor['producto'] }}</td>
                                                            <td class="px-4 py-3">
                                                                <select name="component_imeis[{{ $sensor['detailId'] }}][{{ $sensor['unitIndex'] }}]"
                                                                    data-valid-imeis='@json($sensor['availableImeis'])'
                                                                    class="imei-input component-imei-input tom-select tom-select--compact form-control w-full rounded-lg border {{ $componentInvalid ? 'border-danger text-danger' : 'border-slate-300' }} px-3 py-2 text-sm text-slate-900 transition duration-200 ease-in-out focus:border-primary focus:ring-1 focus:ring-primary"
                                                                    style="min-width: 240px; width: 100%;">
                                                                    <option value="">Selecciona IMEI</option>
                                                                    @foreach($sensor['availableImeis'] as $availableImei)
                                                                        <option value="{{ $availableImei }}" {{ $componentImei === $availableImei ? 'selected' : '' }}>{{ $availableImei }}</option>
                                                                    @endforeach
                                                                </select>
                                                                <div class="imei-error text-danger text-xs mt-1 hidden">El IMEI no existe o no corresponde a este sensor.</div>
                                                            </td>
                                                        @else
                                                            <td class="px-4 py-4 text-slate-500">Sin sensores</td>
                                                            <td class="px-4 py-3 text-slate-400">—</td>
                                                        @endif
                                                    @endfor
                                                    @for($accessoryIndex = 0; $accessoryIndex < $accessorySlots; $accessoryIndex++)
                                                        @php $accessory = $accessoriesForRow[$accessoryIndex] ?? null; @endphp
                                                        @if($accessory)
                                                            @php
                                                                $componentImei = old(
                                                                    "component_imeis.{$accessory['detailId']}.{$accessory['unitIndex']}",
                                                                    $accessory['selectedImei'] ?? ''
                                                                );
                                                                $componentInvalid = in_array($componentImei, session('invalidImeis', [])) && trim((string) $componentImei) !== '';
                                                            @endphp
                                                            <td class="break-words px-4 py-4 text-slate-900">{{ $accessory['producto'] }}</td>
                                                            <td class="px-4 py-3">
                                                                <select name="component_imeis[{{ $accessory['detailId'] }}][{{ $accessory['unitIndex'] }}]"
                                                                    data-valid-imeis='@json($accessory['availableImeis'])'
                                                                    class="imei-input component-imei-input tom-select tom-select--compact form-control w-full rounded-lg border {{ $componentInvalid ? 'border-danger text-danger' : 'border-slate-300' }} px-3 py-2 text-sm text-slate-900 transition duration-200 ease-in-out focus:border-primary focus:ring-1 focus:ring-primary"
                                                                    style="min-width: 240px; width: 100%;">
                                                                    <option value="">Selecciona IMEI</option>
                                                                    @foreach($accessory['availableImeis'] as $availableImei)
                                                                        <option value="{{ $availableImei }}" {{ $componentImei === $availableImei ? 'selected' : '' }}>{{ $availableImei }}</option>
                                                                    @endforeach
                                                                </select>
                                                                <div class="imei-error text-danger text-xs mt-1 hidden">El IMEI no existe o no corresponde a este accesorio.</div>
                                                            </td>
                                                        @else
                                                            <td class="px-4 py-4 text-slate-500">Sin accesorios</td>
                                                            <td class="px-4 py-3 text-slate-400">—</td>
                                                        @endif
                                                    @endfor
                                                </tr>
                                            @endfor
                                        @endforeach
                                    </tbody>
                                </table>
                                </div>
                            </div>
                        @else
                            <div class="rounded-3xl border border-slate-200 bg-slate-50 p-6 text-slate-600">
                                No hay equipo para despachar en esta gestión.
                            </div>
                        @endif

                        <div class="mt-6">
                            <label for="comentario" class="inline-block mb-2 text-slate-600 font-medium">Comentario / Observaciones</label>
                            <textarea id="comentario" required name="comentario" rows="3"
                                class="form-control w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-primary focus:ring-1 focus:ring-primary"
                                placeholder="Añade un comentario sobre este pedido...">{{ old('comentario', $tempData['comentario'] ?? '') }}</textarea>
                        </div>

                        <div class="mt-6 flex flex-wrap items-center justify-end gap-3">
                            @include('vistas._actions')
                        </div>
                    </form>
                </div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        function initTomSelects() {
            if (typeof window.TomSelect !== 'function') return;

            document.querySelectorAll('select.tom-select').forEach(select => {
                const isAssignmentTableSelect = Boolean(select.closest('.assignment-table-scroll'));
                const existingInstance = select.tomselect || select.tomSelect || select._tomselect;

                if (isAssignmentTableSelect && select.tomselect) {
                    const previousCloseHandler = select._assignmentTableDropdownCloseHandler;
                    if (previousCloseHandler) {
                        window.removeEventListener('scroll', previousCloseHandler, true);
                        window.removeEventListener('resize', previousCloseHandler);
                    }

                    select.tomselect.destroy();
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

                        const closeDropdown = () => instance.close();
                        window.addEventListener('scroll', closeDropdown, true);
                        window.addEventListener('resize', closeDropdown);
                        select._assignmentTableDropdownCloseHandler = closeDropdown;
                    }

                    if (select.id && instance.control_input) {
                        instance.control_input.id = `${select.id}-ts-control`;
                    }

                    if (instance.wrapper) instance.wrapper.style.width = '100%';
                } catch (error) {
                    console.warn('TomSelect initialization failed:', error);
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            initTomSelects();

            const form = document.getElementById('imei-form');
            if (!form) return;

            form.addEventListener('submit', function (e) {
                const actionInput = document.getElementById('form-action-input');
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
                let isComplete = true; // asume que todos los IMEIs están llenos
                const imeiInputs = document.querySelectorAll('select.imei-input');
                const imeiValues = [];

                imeiInputs.forEach(input => {
                    const val = (input.value || '').trim();
                    const errorDiv = input.closest('td')?.querySelector('.imei-error') || input.nextElementSibling;
                    if (errorDiv) {
                        errorDiv.classList.add('hidden');
                        errorDiv.textContent = 'El IMEI ingresado no existe o no corresponde a este producto.';
                    }

                    if (val !== '') {
                        imeiValues.push(val);
                        try {
                            const validImeis = JSON.parse(input.dataset.validImeis || '[]');
                            if (!validImeis.includes(val)) {
                                hasErrors = true;
                                if (errorDiv) {
                                    errorDiv.classList.remove('hidden');
                                }
                                input.classList.add('border-danger');
                            } else {
                                input.classList.remove('border-danger');
                            }
                        } catch (err) {
                            console.error("Error parseando IMEIs", err);
                        }
                    } else {
                        // Si el campo está vacío, entonces está incompleto
                        isComplete = false;
                    }
                });

                const duplicateImeis = imeiValues.filter((value, index, array) => value !== '' && array.indexOf(value) !== index);
                const uniqueDuplicateImeis = [...new Set(duplicateImeis)];
                if (uniqueDuplicateImeis.length > 0) {
                    hasErrors = true;
                    imeiInputs.forEach(input => {
                        const val = (input.value || '').trim();
                        if (uniqueDuplicateImeis.includes(val)) {
                            const errorDiv = input.closest('td')?.querySelector('.imei-error') || input.nextElementSibling;
                            if (errorDiv) {
                                errorDiv.textContent = 'IMEI duplicado. Selecciona un IMEI único.';
                                errorDiv.classList.remove('hidden');
                            }
                            input.classList.add('border-danger');
                        }
                    });
                }

                // 1. VALIDACION ESTRICTA: Si hay errores, se bloquea todo inmediatamente
                if (hasErrors) {
                    e.preventDefault();
                    return;
                }

                // 2. MOSTRAR MODAL SEGÚN ESTADO (Completo o Incompleto)
                e.preventDefault(); // Detenemos el submit real para mostrar modal

                const submitter = e.submitter; // Botón que disparó el evento
                const actionUrl = submitter ? submitter.getAttribute('formaction') : '';

                function onModalConfirm() {
                    form.dataset.confirmed = 'true';
                    if (actionUrl) form.action = actionUrl;
                    form.submit();
                }

                const actionLabel = submitter ? submitter.textContent.trim() : 'Confirmar';

                if (!isComplete) {
                    // Modal Tipo 1 (Incompleto)
                    showConfirmModal(
                        'Advertencia: IMEIs Incompletos',
                        'Se están enviando IMEIs incompletos. ¿Estás seguro que deseas continuar?',
                        actionLabel,
                        onModalConfirm
                    );
                } else {
                    // Modal Tipo 2 (Completo)
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
        // Función para abrir el modal de Rechazar usando el mismo estilo del modal de confirmación general
        function abrirModalRechazar() {
            const form = document.getElementById('rechazar-form');
            showConfirmModal(
                'Confirmación',
                '¿Estás seguro que deseas rechazar y regresar a la vista anterior?',
                'Rechazar',
                () => {
                    if (form) {
                        form.submit();
                    } else if (window.history && typeof window.history.back === 'function') {
                        window.history.back();
                    } else {
                        window.location.href = document.referrer || '/';
                    }
                }
            );
        }
    </script>
@endpush
@push('styles')
    <style>
        .assignment-table-scroll {
            overflow-x: auto !important;
            overflow-y: hidden !important;
            -webkit-overflow-scrolling: touch;
        }

        .tom-select.tom-select--compact.ts-wrapper {
            min-height: 2.2rem !important;
            height: 2.2rem !important;
        }

        .tom-select.tom-select--compact.ts-wrapper .ts-control {
            min-height: 2.2rem !important;
            height: 2.2rem !important;
            padding: 1px 10px 0  !important;
            line-height: 1.2rem !important;
            align-items: flex-start !important;
        }

        .tom-select.tom-select--compact.ts-wrapper .ts-control .items,
        .tom-select.tom-select--compact.ts-wrapper .ts-control .item {
            min-height: 2rem !important;
            height: auto !important;
            line-height: 1.2rem !important;
            margin: 0 !important;
        }

        .tom-select.tom-select--compact.ts-wrapper .ts-control .item {
            padding: 0 .35rem !important;
        }

        .tom-select.tom-select--compact.ts-wrapper .ts-control,
        .tom-select.tom-select--compact.ts-wrapper .ts-control .items,
        .tom-select.tom-select--compact.ts-wrapper .ts-control .item {
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            white-space: nowrap !important;
            max-width: 100%;
        }

        .assignment-table-dropdown {
            position: fixed !important;
            z-index: 2147483647 !important;
            max-height: none !important;
            overflow: visible !important;
            margin: 0 !important;
            border-radius: 0.35rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        .assignment-table-dropdown .ts-dropdown-content {
            max-height: 145px !important;
            overflow-y: auto !important;
        }

        .assignment-table-dropdown .option:hover {
            background-color: #f1f5f9 !important;
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

        .assignment-table-dropdown .option.active {
            background-color: #b91c1c !important;
            color: #ffffff !important;
        }

    </style>
@endpush
