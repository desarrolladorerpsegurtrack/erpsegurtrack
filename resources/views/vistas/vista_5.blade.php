@extends('dashboard.overview-1')
@section('title', $title ?? 'Resumen y Validación')
@section('header', $title ?? 'Resumen y Validación')
@section('breadcrumb')
    <nav aria-label="breadcrumb" class="flex hidden flex-1 xl:block">
        <ol class="flex items-center text-theme-1">
            <li><a href="{{ route('home') }}">Inicio</a></li>
            <li
                class="relative ml-5 pl-0.5 before:content-[''] before:w-[14px] before:h-[14px] before:bg-chevron-black before:transform before:rotate-[-90deg] before:bg-[length:100%] before:-ml-[1.125rem] before:absolute before:my-auto before:inset-y-0 text-slate-600 cursor-text">
                <span>{{ $title ?? 'Resumen y Validación' }}</span>
            </li>
        </ol>
    </nav>
@endsection
@section('content')
    @php $hasMissingImeis = false; @endphp
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-medium">{{ $title ?? 'Resumen General del Ticket' }}</h2>
        @if(($vista->idvista ?? 0) >= 2 && ($vista->idvista ?? 0) <= 6)
            <form method="POST" action="{{ $advanceUrl ?? '#' }}" class="ml-auto" id="rechazar-form">
                @csrf
                <input type="hidden" name="action" value="back_to_previous">
                <input type="hidden" name="current_vista_id" value="{{ $vista->idvista ?? '' }}">
                <button type="button" onclick="abrirModalRechazar()"
                    class="ml-auto inline-flex items-center justify-center rounded-md shrink-0 transition duration-200 border shadow-sm  py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed border-secondary text-slate-500 dark:border-darkmode-100/40 dark:text-slate-300 [&:hover:not(:disabled)]:bg-secondary/20 [&:hover:not(:disabled)]:dark:bg-darkmode-100/10 border border-slate-600 px-4 py-2 text-sm font-semibold text-slate-600 transition duration-200 hover:bg-slate-100">
                    <i data-lucide="x-circle" class="mr-2 h-4 w-4"></i>
                    Rechazar
                </button>
            </form>
        @endif
    </div>
    <div class="box box--stacked grid grid-cols-12 gap-6">
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
                            <div class="md:col-span-3"><strong>Dirección:</strong>{{ $cot->direccion ?? $cot->cliente_direccion ?? 'No tiene dirección' }}</div>
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

                    <h2 class="text-lg font-medium mr-auto border-b pb-2 mb-4">Validación de Equipamiento</h2>
                    @if(isset($equipamiento) && count($equipamiento) > 0)
                        <div class="overflow-visible rounded-3xl border border-slate-200 bg-slate-50 shadow-sm mb-6">
                            <div class="overflow-x-auto table-scroll-wrapper">
                                <table class="min-w-[920px] w-full text-left text-sm text-slate-600">
                                    <thead class="bg-slate-100 text-xs uppercase tracking-[0.16em] text-slate-500">
                                        <tr>
                                            <th class="px-4 py-3">Producto</th>
                                            <th class="px-4 py-3">IMEI</th>
                                            <th class="px-4 py-3">Placa Asignada</th>
                                            <th class="px-4 py-3">Numero Telefónico</th>
                                            <th class="px-4 py-3">Plan / Servicio / Sensor</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($equipamiento as $item)
                                            @for($i = 0; $i < (int) $item->cantidad; $i++)
                                                @php
                                                    $numeroSeleccionado = $item->numeros_seleccionados[$i] ?? 'Sin número';
                                                    $imeiRaw = $item->imeis_seleccionados[$i] ?? '';
                                                    $imeiSeleccionado = $imeiRaw ?: 'No asignado';
                                                    if (empty(trim((string) $imeiRaw))) {
                                                        $hasMissingImeis = true;
                                                    }

                                                    $placaSeleccionada = $item->placas_seleccionadas[$i] ?? 'Falta placa';
                                                    $planIds = $item->planes_seleccionados[$i] ?? [];

                                                    $nombresPlanes = [];
                                                    if (is_array($planIds) && !empty($planIds) && isset($servicios)) {
                                                        foreach ($planIds as $pid) {
                                                            $baseId = explode('_', $pid)[0] ?? '';
                                                            $index = explode('_', $pid)[1] ?? '';
                                                            $srvMatch = collect($servicios)->firstWhere('iddetalleCotizacion', $baseId);
                                                            if ($srvMatch) {
                                                                // Formato visual: "Servicio Instalacion (1)" si es necesario, o solo el nombre
                                                                $nombresPlanes[] = $srvMatch->producto . ((int) $srvMatch->cantidad > 1 ? " ($index)" : "");
                                                            }
                                                        }
                                                    }
                                                    $planNombre = empty($nombresPlanes) ? 'Sin plan asignado' : implode(', ', $nombresPlanes);
                                                @endphp
                                                <tr class="border-t border-slate-200 bg-white">
                                                    <td class="px-4 py-4 text-slate-900 font-medium">
                                                        {{ $item->producto }}
                                                    </td>
                                                    <td class="px-4 py-4">
                                                        @if(empty($imeiSeleccionado))
                                                            <span class="text-danger font-bold"><i data-lucide="alert-triangle"
                                                                    class="w-4 h-4 inline mr-1"></i> Falta IMEI</span>
                                                        @else
                                                            {{ $imeiSeleccionado }}
                                                        @endif
                                                    </td>
                                                    <td class="px-4 py-4">{{ $placaSeleccionada }}</td>
                                                    <td class="px-4 py-4">{{ $numeroSeleccionado }}</td>
                                                    <td class="px-4 py-4" style="max-width: 35rem;">{{ $planNombre }}</td>
                                                </tr>
                                            @endfor
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                    <!-- Comentario -->
                    @if(isset($comentarioTemporal) && $comentarioTemporal)
                        <div class="mt-4 mb-6 p-4 bg-slate-50 border border-slate-200 rounded-md">
                            <h4 class="font-medium text-slate-700 mb-1">Comentario del Pedido:</h4>
                            <p class="text-slate-600">{{ $comentarioTemporal }}</p>
                        </div>
                    @endif

                    <form id="ticket-flow-form" method="POST" action="{{ $advanceUrl ?? '#' }}" data-lock-action="ticket">
                        @csrf
                        <input type="hidden" name="current_vista_id" value="{{ $vista->idvista ?? '' }}">
                        <input type="hidden" id="erp-lock-resource" value="{{ $lockResource ?? 'ticket' }}">
                        <input type="hidden" id="erp-lock-id" value="{{ $lockId ?? ($ticket->idticket ?? '') }}">

                        @if($hasMissingImeis)
                            <div class="alert alert-danger mb-5 p-4 rounded-md flex items-center">
                                <i data-lucide="alert-circle" class="w-6 h-6 mr-3"></i>
                                <div>
                                    <strong>¡Atención!</strong> Faltan IMEIs por asignar. No puedes avanzar a la finalización del
                                    ticket hasta que todos los equipos tengan su IMEI.
                                </div>
                            </div>

                            <!-- Botón para regresar a la vista 3 -->
                            <input type="hidden" name="action" value="back_to_3">
                            <div class="mt-6 flex justify-end">
                                <button type="submit"
                                    class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 bg-primary border-primary text-white">
                                    <i data-lucide="corner-up-left" class="w-4 h-4 mr-2"></i>
                                    Regresar a Completar IMEIs
                                </button>
                            </div>
                        @else
                            <div class="alert alert-success mb-5 p-4 rounded-md flex items-center">
                                <i data-lucide="check-circle" class="w-6 h-6 mr-3"></i>
                                <div>Todos los equipos tienen su IMEI asignado correctamente. Puedes proceder.</div>
                            </div>

                            <input type="hidden" name="action" value="{{ $actionValue ?? 'next' }}">
                            <div class="mt-6 flex flex-wrap items-center justify-end gap-3">
                                @include('vistas._actions')
                            </div>
                        @endif
                    </form>
                </div>
            @endif
        </div>
    </div>
@endsection
@push('scripts')
    <script>
        function submitRejectAction(button) {
            const formId = button.getAttribute('data-form-id') || 'ticket-flow-form';
            const form = document.getElementById(formId);
            if (!form) return;

            form.querySelectorAll('input[name="action"]').forEach((input) => {
                input.value = 'reject';
                input.setAttribute('value', 'reject');
            });

            form.submit();
        }

        document.addEventListener('DOMContentLoaded', function () {
            const form = document.querySelector('form[data-lock-action="ticket"]');
            if (!form) return;

            form.addEventListener('submit', function (e) {
                const actionInput = document.getElementById('form-action-input');
                const isRejectAction = actionInput && actionInput.value === 'reject';
                if (isRejectAction) {
                    return;
                }

                const isActionAdvance = (actionInput && actionInput.name !== '');

                if (!isActionAdvance) return;

                if (form.dataset.confirmed === 'true') return;

                e.preventDefault();

                const submitter = e.submitter;
                const actionUrl = submitter ? submitter.getAttribute('formaction') : '';
                const actionLabel = submitter ? submitter.textContent.trim() : 'Confirmar';

                function onModalConfirm() {
                    form.dataset.confirmed = 'true';
                    if (actionUrl) form.action = actionUrl;
                    form.submit();
                }

                showConfirmModal(
                    'Confirmación',
                    '¿Estás seguro que deseas guardar y continuar con el proceso?',
                    actionLabel,
                    onModalConfirm
                );
            });
        });

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