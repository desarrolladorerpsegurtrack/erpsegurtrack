@extends('dashboard.overview-1')
@section('title', $title ?? 'Indicar Elementos')
@section('header', $title ?? 'Indicar Elementos')
@section('breadcrumb')
    <nav aria-label="breadcrumb" class="flex hidden flex-1 xl:block">
        <ol class="flex items-center text-theme-1">
            <li><a href="{{ route('home') }}">Inicio</a></li>
            <li
                class="relative ml-5 pl-0.5 before:content-[''] before:w-[14px] before:h-[14px] before:bg-chevron-black before:transform before:rotate-[-90deg] before:bg-[length:100%] before:-ml-[1.125rem] before:absolute before:my-auto before:inset-y-0 text-slate-600 cursor-text">
                <span>{{ $title ?? 'Indicar Elementos' }}</span>
            </li>
        </ol>
    </nav>
@endsection
@section('content')
    <h2 class="text-lg font-medium mb-5">{{ $title ?? 'Indicar Elementos' }}</h2>
    <div class="box box--stacked grid grid-cols-12">
        <div class="col-span-12">
            <!-- Bloque 1: Información de Cotizaciones -->
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

                    <h2 class="text-lg font-medium mr-auto border-b pb-2 mb-4">Asignación de Equipo</h2>
                    <form id="imei-form" method="POST" action="{{ $advanceUrl ?? '#' }}" data-lock-action="ticket">
                        @csrf
                        @if(isset($equipamiento) && count($equipamiento) > 0)
                            <div class="overflow-visible rounded-3xl border border-slate-200 bg-slate-50 shadow-sm">
                                <table class="w-full text-left text-sm text-slate-600">
                                    <thead class="bg-slate-100 text-xs uppercase tracking-[0.16em] text-slate-500">
                                        <tr>
                                            <th class="px-4 py-3">Producto</th>
                                            <th class="px-4 py-3">IMEI</th>
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
                                                <tr class="border-t border-slate-200 bg-white">
                                                    <td class="px-4 py-4 text-slate-900">{{ $item->producto }}</td>
                                                    <td class="px-4 py-4">
                                                        @php
                                                            $currentImei = old("imeis.{$item->iddetalleCotizacion}.{$i}", $tempData['imeis'][$item->iddetalleCotizacion][$i] ?? '');
                                                            $isInvalid = in_array($currentImei, session('invalidImeis', [])) && trim($currentImei) !== '';
                                                        @endphp
                                                        <select name="imeis[{{ $item->iddetalleCotizacion }}][]"
                                                            data-valid-imeis='@json($availableImeis)'
                                                            class="imei-input tom-select form-control w-full rounded-lg border {{ $isInvalid ? 'border-danger  text-danger' : 'border-slate-300' }} px-3 py-2 text-sm text-slate-900 transition duration-200 ease-in-out focus:border-primary focus:ring-1 focus:ring-primary">
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
                                                </tr>
                                            @endfor
                                        @endforeach
                                    </tbody>
                                </table>
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
        document.addEventListener('DOMContentLoaded', function () {
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
        });
    </script>
@endpush
@push('styles')
    <style>
        .tom-select.tom-select--compact.ts-wrapper,
        .tom-select.tom-select--compact.ts-wrapper .ts-control,
        .tom-select.tom-select--compact.plugin-dropdown_input.focus.dropdown-active .ts-control {
            min-height: 2.2rem !important;
            height: 2.2rem !important;
            padding: 0.2rem 0.75rem !important;
            line-height: 1.2rem !important;
        }

        .tom-select.tom-select--compact.ts-wrapper .ts-control {
            min-height: 2.2rem !important;
            height: 2.2rem !important;
            padding: 0.3rem 0.75rem 0.1rem 0.75rem !important;
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

        /* Truncar textos largos dentro de TomSelect con puntos suspensivos */
        .tom-select.ts-wrapper .ts-control,
        .tom-select .ts-control .items,
        .tom-select .ts-control .item {
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            white-space: nowrap !important;
            max-width: 100%;
        }

        /* Ajustes para el dropdown de TomSelect: evitar doble scroll y color de selección */
        .tom-select.ts-wrapper .ts-dropdown {
            /* Permitimos que el dropdown quede fuera del flujo y no cree otro contenedor scrollable */
            max-height: none !important;
            overflow: visible !important;
            z-index: 9999 !important;
            border-radius: 0.35rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            position: absolute; /* asegurar posicionamiento absoluto cuando se anexa a body */
        }

        .tom-select.ts-wrapper .ts-dropdown .ts-dropdown-content {
            max-height: 200px !important;
            overflow-y: auto !important; /* solo el contenido interno tiene scroll */
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

        .overflow-visible.rounded-3xl table {
            table-layout: fixed;
            width: 100%;
        }

        .overflow-visible.rounded-3xl th:first-child,
        .overflow-visible.rounded-3xl td:first-child {
            width: 50%;
        }

        .overflow-visible.rounded-3xl th:last-child,
        .overflow-visible.rounded-3xl td:last-child {
            width: 50%;
        }

        /* Color rojo para el borde cuando esta enfocado */
        .tom-select.ts-wrapper .dropdown-input:focus,
        .tom-select.ts-wrapper .dropdown-inputWrap {
            border-color: #b91c1c !important;
            box-shadow: 0 0 0 1px #b91c1c !important;
        }
    </style>
@endpush
