<style>
    [data-devices-inline] .ts-control .item {
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        max-width: calc(100% - 16px) !important;
    }

    /* Responsive Table Styles */
    .device-table-header {
        display: block;
    }

    .device-row-container {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 120px 110px minmax(0, 1fr) 44px;
        gap: 16px;
        align-items: center;
        padding: 5px 8px;
        margin: 4px 0;
        box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
    }

    .device-row-col {
        min-width: 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .device-row-col-start {
        justify-content: flex-start;
        width: 100%;
    }

    .device-row-col-end {
        justify-content: flex-end;
    }

    .device-mobile-label {
        display: none;
        font-size: 0.75rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .device-mobile-label-mb {
        margin-bottom: 0.25rem;
    }

    .btn-quitar-text {
        display: none;
    }

    .tom-select.tom-select--compact.ts-wrapper .ts-control .item {
        padding: 0 .0rem !important;
    }
    .tom-select.tom-select--compact.ts-wrapper, 
    .tom-select.tom-select--compact.ts-wrapper .ts-control, 
    .tom-select.tom-select--compact.plugin-dropdown_input.focus.dropdown-active .ts-control {
        min-height: 2.2rem !important;
        height: 2.2rem !important;
        padding: 0.2rem 0.0rem 0.1rem 0.10rem !important;
        line-height: 1.2rem !important;
    }


    @media (max-width: 767px) {
        .imeis-col {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        /* Keep table header visible on small screens and enable scrolling
           so the table behaves like the desktop layout but inside a
           horizontally scrollable container. */
        .device-table-header {
            display: block;
        }

        .device-table-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .device-table-inner {
            min-width: 920px;
            width: max-content; /* ensure table has enough width to show columns */
        }

        .device-row-container {
            display: grid;
            grid-template-columns: minmax(320px, 2fr) 120px 110px minmax(280px, 1.6fr) 44px !important;
            gap: 12px;
            align-items: center;
            padding: 5px 8px;
            margin: 4px 0;
            border-radius: 8px;
            min-width: 920px;
            width: max-content;
        }

        .device-table-header > div {
            grid-template-columns: minmax(320px, 2fr) 120px 110px minmax(280px, 1.6fr) 44px !important;
            min-width: 920px;
            width: max-content;
        }

        .device-row-col {
            min-width: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .device-row-col-start {
            justify-content: flex-start;
            width: 100%;
        }

        .device-row-col-end {
            justify-content: flex-end;
            border-top: none;
            padding-top: 0;
            margin-top: 0;
        }

        .device-mobile-label {
            display: none;
        }

        .btn-quitar-text {
            display: none;
        }
    }
</style>
<div class="rounded-md border border-slate-200 bg-white p-5 shadow-sm" data-devices-inline style="position:relative;">
    @if(($readOnly ?? false))
        <div class="devices-readonly-overlay" aria-hidden="true" title="Solo lectura" style="position:absolute;inset:0;z-index:50;cursor:not-allowed;background:transparent"></div>
    @endif
    <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 md:flex-row md:items-center md:justify-between">
        <div class="w-full">
            <h2 class="text-lg font-semibold text-black">Dispositivos por agregar</h2>
            <p class="mt-1 text-sm text-slate-600">
                Marca "Manual IMEIs" para introducir IMEIs; si no se marca, los IMEIs se generarán automáticamente.
            </p>
        </div>
        <button type="button" data-add-device
            class="inline-flex justify-center shrink-0 items-center gap-2 rounded-md border px-3 py-2 text-sm focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none transition-all disabled:opacity-50 disabled:cursor-not-allowed"
            style="background-color:#B91c1c; color:#fff;" {{ ($readOnly ?? false) ? 'disabled' : '' }}>
            <span class="text-base leading-none">+</span> Agregar
        </button>
    </div>

    <div class="mt-4">
        <div class="border border-slate-200 bg-white shadow-sm rounded-md">
            <div class="device-table-scroll">
                <div class="device-table-inner">
                    <div class="device-table-header border-b border-slate-200 bg-slate-50" style="padding: 12px 20px;">
                        <div style="display:grid; grid-template-columns: minmax(0,1fr) 120px 110px minmax(0,1fr) 44px; gap:16px; align-items:center;"
                            class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500">
                            <div style="text-align:left;">Dispositivo</div>
                            <div style="text-align:center;">Cantidad</div>
                            <div style="text-align:center;">IMEIs Manual</div>
                            <div style="text-align:left;" class="imei-header-label">IMEIs</div>
                            <div style="text-align:center;">Quitar</div>
                        </div>
                    </div>

                    <div class="p-3">
                        <div data-devices-list class="flex flex-col gap-2"></div>

                        <div data-devices-empty
                            class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-500">
                            No hay filas. Pulsa “Agregar”.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<template data-device-row-template>
    <div data-device-row class="device-row-container">

        <div class="device-row-col device-row-col-start">
            <label class="device-mobile-label device-mobile-label-mb">Dispositivo</label>
            <select
                class="tom-select tom-select--compact w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs focus:border-primary focus:ring-1 focus:ring-primary {{ ($readOnly ?? false) ? 'bg-slate-50 cursor-not-allowed' : '' }}"
                data-device-select style="width:100%;" {{ ($readOnly ?? false) ? 'disabled' : '' }}>
                <option value="">Selecciona un dispositivo</option>
                @foreach(($almacenOptions ?? []) as $option)
                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
            <input type="hidden" data-input-device value="">
        </div>

        <div class="device-row-col gap-3">
            <label class="device-mobile-label">Cantidad</label>
            <input data-device-qty type="number" min="1" step="1" value="1"
                class="w-16 rounded-md border border-slate-300 px-3 py-1.5 text-sm text-center focus:border-primary focus:ring-1 focus:ring-primary {{ ($readOnly ?? false) ? 'bg-slate-50 cursor-not-allowed' : '' }}" 
                {{ ($readOnly ?? false) ? 'disabled' : '' }} />
        </div>

        <div class="device-row-col">
            <label class="device-mobile-label">IMEIs Manual</label>
            <div class="flex items-center">
                <input type="hidden" data-device-manual-hidden value="0">
                <input data-device-manual type="checkbox" value="1"
                    class="w-4 h-4 text-primary border-slate-300 rounded focus:ring-primary {{ ($readOnly ?? false) ? 'cursor-not-allowed' : 'cursor-pointer' }}" 
                    {{ ($readOnly ?? false) ? 'disabled' : '' }} />
            </div>
        </div>

        <div class="imeis-col device-row-col device-row-col-start">
            <label class="device-mobile-label device-mobile-label-mb">IMEIs</label>
            <input type="hidden" data-device-imeis value="">
            <input type="text" data-device-imeis-preview readonly
                class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-primary focus:ring-1 focus:ring-primary {{ ($readOnly ?? false) ? 'bg-slate-50 cursor-not-allowed' : 'cursor-pointer bg-white' }}"
                placeholder="Clic para agregar IMEIs..." {{ ($readOnly ?? false) ? 'disabled' : '' }} />
        </div>

        <div class="device-row-col device-row-col-end">
            <button type="button" data-remove-device title="Quitar fila" style="color: #B91c1c"
                class="flex items-center hover:scale-110 transition-transform disabled:opacity-50 disabled:cursor-not-allowed"
                {{ ($readOnly ?? false) ? 'disabled' : '' }}>
                <span class="btn-quitar-text">Quitar dispositivo</span>
                <span class="text-lg leading-none">X</span>
            </button>
        </div>
    </div>
</template>

<!-- Modal de IMEIs -->
<div id="imeisModal" class="fixed inset-0 hidden items-center justify-center p-4"
    style="z-index: 9999; background-color: rgba(0, 0, 0, 0.78);" role="dialog" aria-modal="true">
    <div class="w-full overflow-hidden rounded-[1.25rem] bg-white shadow-[0_24px_80px_rgba(15,23,42,0.16)] flex flex-col max-h-[85vh] modal-dialog"
        style="max-width: 650px;">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-6 py-4">
            <h3 class="text-lg font-semibold text-slate-800">Ingresar IMEIs Manuales</h3>
            <button type="button" id="closeImeisModalBtn"
                class="ml-auto rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700">
                <span class="text-2xl leading-none"
                    style="display:flex; align-items:center; justify-content:center; width:20px; height:20px;">&times;</span>
            </button>
        </div>

        <div class="px-6 py-5 overflow-y-auto flex-1" style="min-height: 0;">
            <label class="mb-2 block text-sm font-medium text-slate-700">Pega IMEIs separados por coma, espacio o nueva
                línea.</label>
            <textarea id="modalImeisTextarea" rows="8"
                class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-2.5 text-sm text-slate-800 transition duration-200 ease-in-out focus:border-red-600 focus:ring-2 focus:ring-red-500/20 font-mono"
                placeholder="Ej:&#10;123456789&#10;987654321">
            </textarea>

            <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div class="text-sm text-slate-600">
                    <span class="font-medium text-slate-800">Cantidad requerida:</span> <span id="modalRequiredCount"
                        class="font-bold">0</span> <span class="mx-2">|</span>
                    <span class="font-medium text-emerald-600">Válidos:</span> <span id="modalValidCount"
                        class="font-bold text-emerald-600">0</span> <span class="mx-2">|</span>
                    <span class="font-medium text-red-600">Errores/Duplicados:</span> <span id="modalErrorCount"
                        class="font-bold text-red-600">0</span>
                </div>
                <div id="modalErrorText" class="text-xs font-semibold text-red-600 hidden">La cantidad no coincide o hay
                    errores.</div>
            </div>
        </div>

        <div class="border-t border-slate-200 px-6 py-4 flex flex-col gap-3 sm:flex-row sm:justify-end">
            <button type="button" id="cancelImeisModalBtn"
                class="rounded-md border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-100"
                style="border-color: #000000; color: #000000;">Cancelar</button>
            <button type="button" id="saveImeisModalBtn"
                class="rounded-lg items-center justify-center border px-5 py-2 text-sm font-semibold shadow-sm transition hover:bg-red-800 disabled:opacity-50 disabled:cursor-not-allowed"
                style="background-color: #b91c1c; color: #ffffff;">Guardar</button>
        </div>
    </div>
</div>

@php
    $devicesInitial = $devices ?? [];
@endphp

<script>
    (function () {
        const container = document.querySelector('[data-devices-inline]');
        const isReadOnly = <?php echo ($readOnly ?? false) ? 'true' : 'false'; ?>;
        if (!container) return;

        const list = container.querySelector('[data-devices-list]');
        const empty = container.querySelector('[data-devices-empty]');
        const template = document.querySelector('template[data-device-row-template]');
        const addBtn = container.querySelector('[data-add-device]');

        // Variables y elementos del modal
        let currentModalRow = null;
        let currentRequiredQty = 0;

        const imeisModal = document.getElementById('imeisModal');
        if (imeisModal && document.body) {
            // Mover el modal al final del body para evitar problemas de z-index y overflow con contenedores padres
            document.body.appendChild(imeisModal);
        }

        const modalImeisTextarea = document.getElementById('modalImeisTextarea');
        const modalRequiredCount = document.getElementById('modalRequiredCount');
        const modalValidCount = document.getElementById('modalValidCount');
        const modalErrorCount = document.getElementById('modalErrorCount');
        const modalErrorText = document.getElementById('modalErrorText');
        const saveImeisModalBtn = document.getElementById('saveImeisModalBtn');
        const cancelImeisModalBtn = document.getElementById('cancelImeisModalBtn');
        const closeImeisModalBtn = document.getElementById('closeImeisModalBtn');

        function updatePreviewFromInput(row) {
            const imeisInput = row.querySelector('[data-device-imeis]');
            const previewInput = row.querySelector('[data-device-imeis-preview]');
            const manualCheck = row.querySelector('[data-device-manual]');

            if (!imeisInput || !previewInput) return;

            const isAuto = manualCheck && !manualCheck.checked;
            previewInput.style.backgroundColor = isAuto ? '#f8fafc' : '#ffffff';

            const imeiVal = imeisInput.value.trim();
            if (imeiVal) {
                const arr = imeiVal.split(/[,\s\n]+/).filter(i => i.length > 0);
                if (arr.length > 3) {
                    previewInput.value = arr.slice(0, 3).join(', ') + '...';
                } else {
                    previewInput.value = arr.join(', ');
                }
            } else {
                previewInput.value = '';
            }
        }
        function updateAutoImeis(row) {
            const qtyInput = row.querySelector('[data-device-qty]');
            const imeisInput = row.querySelector('[data-device-imeis]');
            const qty = qtyInput ? (parseInt(String(qtyInput.value).replace(/[^0-9-]/g, ''), 10) || 1) : 1;
            const hidden = row.querySelector('[data-input-device]');
            const deviceId = hidden ? hidden.value : '';

            // Solicitar IMEIs aleatorios desde el servidor (preview). Si falla, rellenar con placeholders.
            fetchPreviewIngresoImeisForRow(row, deviceId, qty).then(found => {
                if (!found) {
                    const arr = [];
                    for (let i = 1; i <= qty; i++) arr.push(`GEN${String(Math.floor(Math.random() * 900000000000000) + 100000000000000)}`);
                    if (imeisInput) imeisInput.value = arr.join('\n');
                    updatePreviewFromInput(row);
                }
            }).catch(() => {
                const arr = [];
                for (let i = 1; i <= qty; i++) arr.push(`GEN${String(Math.floor(Math.random() * 900000000000000) + 100000000000000)}`);
                if (imeisInput) imeisInput.value = arr.join('\n');
                updatePreviewFromInput(row);
            });
        }
        function clearImeis(row) {
            const imeisInput = row.querySelector('[data-device-imeis]');
            if (imeisInput) imeisInput.value = '';
            updatePreviewFromInput(row);
        }

        function openImeisModal(row) {
            const isLocked = (<?php echo ($readOnly ?? false) ? 'true' : 'false'; ?>) && !window.crudFormEditUnlocked;

            const manualCheck = row.querySelector('[data-device-manual]');
            const isAuto = manualCheck && !manualCheck.checked;
            const isReadOnly = isLocked || isAuto;

            currentModalRow = row;
            const qtyInput = row.querySelector('[data-device-qty]');
            currentRequiredQty = qtyInput ? parseInt(qtyInput.value) || 0 : 0;

            const imeisInput = row.querySelector('[data-device-imeis]');
            modalImeisTextarea.value = imeisInput ? imeisInput.value : '';

            if (modalRequiredCount) modalRequiredCount.textContent = currentRequiredQty;

            if (isReadOnly) {
                modalImeisTextarea.readOnly = true;
                modalImeisTextarea.classList.add('bg-slate-100');
                if (saveImeisModalBtn) saveImeisModalBtn.style.display = 'none';
                document.querySelector('#imeisModal h3').textContent = isAuto ? 'IMEIs Automáticos (Previsualización)' : 'IMEIs Manuales';
            } else {
                modalImeisTextarea.readOnly = false;
                modalImeisTextarea.classList.remove('bg-slate-100');
                if (saveImeisModalBtn) saveImeisModalBtn.style.display = 'flex';
                document.querySelector('#imeisModal h3').textContent = 'Ingresar IMEIs Manuales';
            }

            updateModalStatus();

            imeisModal.classList.remove('hidden');
            imeisModal.classList.add('flex');

            if (!isReadOnly) modalImeisTextarea.focus();
        }

        function closeImeisModal() {
            imeisModal.classList.add('hidden');
            imeisModal.classList.remove('flex');
            currentModalRow = null;
            modalImeisTextarea.value = '';
        }

        function updateModalStatus() {
            if (!modalImeisTextarea) return { valid: [] };

            const isReadOnly = modalImeisTextarea.readOnly;
            const text = modalImeisTextarea.value;
            const raw = text.split(/[,\s\n]+/).map(i => i.trim()).filter(i => i.length > 0);

            let validCount = 0;
            let errorCount = 0;
            const seen = new Set();

            raw.forEach(imei => {
                const isGeneratedPattern = /^GEN\d+$/.test(imei);
                const isAutoPattern = /^AUTOMATICO-\d+$/.test(imei);
                const isNumeric = /^\d+$/.test(imei);
                const isCorrectLength = imei.length >= 5 && imei.length <= 20;
                const isDuplicate = seen.has(imei);

                if ((isGeneratedPattern || isAutoPattern || (isNumeric && isCorrectLength)) && !isDuplicate) {
                    validCount++;
                    seen.add(imei);
                } else {
                    errorCount++;
                }
            });

            if (modalValidCount) modalValidCount.textContent = validCount;
            if (modalErrorCount) modalErrorCount.textContent = errorCount;

            if (!isReadOnly) {
                if (validCount !== currentRequiredQty || errorCount > 0) {
                    if (saveImeisModalBtn) saveImeisModalBtn.disabled = true;
                    if (modalErrorText) modalErrorText.classList.remove('hidden');
                } else {
                    if (saveImeisModalBtn) saveImeisModalBtn.disabled = false;
                    if (modalErrorText) modalErrorText.classList.add('hidden');
                }
            } else {
                if (modalErrorText) modalErrorText.classList.add('hidden');
            }

            return { valid: Array.from(seen) };
        }

        if (modalImeisTextarea) modalImeisTextarea.addEventListener('input', updateModalStatus);
        if (cancelImeisModalBtn) cancelImeisModalBtn.addEventListener('click', closeImeisModal);
        if (closeImeisModalBtn) closeImeisModalBtn.addEventListener('click', closeImeisModal);

        if (saveImeisModalBtn) {
            saveImeisModalBtn.addEventListener('click', () => {
                if (!currentModalRow) return;

                const { valid } = updateModalStatus();

                const imeisInput = currentModalRow.querySelector('[data-device-imeis]');
                const previewInput = currentModalRow.querySelector('[data-device-imeis-preview]');

                const finalString = valid.join('\n');
                if (imeisInput) imeisInput.value = finalString;

                if (previewInput) {
                    if (valid.length > 3) {
                        previewInput.value = valid.slice(0, 3).join(', ') + '...';
                    } else {
                        previewInput.value = valid.join(', ');
                    }
                }

                closeImeisModal();
            });
        }

        function syncEmpty() {
            if (empty) {
                empty.classList.toggle('hidden', list.children.length > 0);
            }
        }

        // Centralizar opciones de TomSelect en una sola configuración
        const TOMSELECT_OPTIONS = {
            allowEmptyOption: true,
            maxItems: 1,
            placeholder: 'Selecciona un dispositivo',
            // dropdownParent: 'body', // Se remueve para evitar que el dropdown pierda los estilos del contenedor
            hidePlaceholder: true,
            plugins: { dropdown_input: {} },
            create: false,
            closeAfterSelect: true
        };

        function initTomSelect(select) {
            if (!select || typeof window.TomSelect !== 'function') return;

                // No inicializar TomSelect si el select está deshabilitado (modo sólo lectura)
                if (select.disabled) {
                    return null;
                }

            // Destruir instancia previa si existe
            try {
                const existing = select.tomselect || select.tomSelect || select._tomselect;
                if (existing && typeof existing.destroy === 'function') {
                    existing.destroy();
                }
            } catch (err) {
                console.warn('TomSelect destroy failed (ignored):', err);
            }

            // Crear nueva instancia usando la configuración centralizada
            const instance = new TomSelect(select, TOMSELECT_OPTIONS);

            // Forzar que el wrapper y el control ocupen el 100% para evitar recortes
            try {
                if (instance && instance.wrapper) {
                    instance.wrapper.style.width = '100%';
                    instance.wrapper.style.maxWidth = '100%';
                    instance.wrapper.style.boxSizing = 'border-box';
                }
                if (instance && instance.control) {
                    instance.control.style.width = '100%';
                    instance.control.style.boxSizing = 'border-box';
                }
            } catch (err) {
                // no crítico
            }
            return instance;
        }

        function initAllTomSelects() {
            if (isReadOnly) return; // evitar inicializar TomSelects en modo solo-lectura
            const selects = container.querySelectorAll('select[data-device-select]');
            selects.forEach((s) => initTomSelect(s));
        }

        // Si estamos en modo sólo lectura, forzar disabled/tabindex para evitar foco por teclado
        if (isReadOnly) {
            const focusables = container.querySelectorAll('select, input, button, textarea');
            focusables.forEach(el => {
                try {
                    el.setAttribute('disabled', 'disabled');
                    el.tabIndex = -1;
                } catch (e) {}
            });
        }

        function wireRow(row) {
            const select = row.querySelector('[data-device-select]');
            const hidden = row.querySelector('[data-input-device]');
            const manual = row.querySelector('[data-device-manual]');
            const remove = row.querySelector('[data-remove-device]');

            // Sync select -> hidden input
            if (select && hidden) {
                select.addEventListener('change', () => {
                    hidden.value = select.value;
                    updateDisabledOptions();
                    // Si no está en modo manual y la cantidad es mayor a 0,
                    // generar previsualización de IMEIs automáticamente al seleccionar dispositivo.
                    try {
                        const qtyVal = qtyInput ? (parseInt(qtyInput.value, 10) || 0) : 0;
                        if (manual && !manual.checked && qtyVal > 0) {
                            updateAutoImeis(row);
                        }
                    } catch (e) {
                        // no crítico
                    }
                });
            }

            // Enforce cantidad >= 1 en frontend
            const qtyInput = row.querySelector('[data-device-qty]');
            if (qtyInput) {
                const enforceMin = () => {
                    try {
                        let v = parseInt(String(qtyInput.value || '').replace(/[^0-9-]/g, ''), 10);
                        if (Number.isNaN(v) || v < 1) v = 1;
                        if (String(qtyInput.value) !== String(v)) qtyInput.value = v;
                    } catch (e) {
                        qtyInput.value = 1;
                    }
                    if (manual && !manual.checked) {
                        updateAutoImeis(row);
                    } else if (currentModalRow === row) {
                        currentRequiredQty = parseInt(qtyInput.value) || 0;
                        if (modalRequiredCount) modalRequiredCount.textContent = currentRequiredQty;
                        updateModalStatus();
                    }
                };
                qtyInput.addEventListener('input', enforceMin);
                qtyInput.addEventListener('change', enforceMin);

            }

            if (manual) {
                manual.addEventListener('change', () => {
                    if (manual.checked) {
                        clearImeis(row);
                    } else {
                        updateAutoImeis(row);
                    }
                });
            }

            const preview = row.querySelector('[data-device-imeis-preview]');
            if (preview) {
                preview.addEventListener('click', () => {
                    openImeisModal(row);
                });
            }

            if (remove) {
                remove.addEventListener('click', () => {
                    row.remove();
                    updateRows();
                    updateDisabledOptions();
                });
            }
        }

        function updateRows() {
            Array.from(list.children).forEach((child, idx) => {
                const hidden = child.querySelector('[data-input-device]');
                const qty = child.querySelector('[data-device-qty]');
                const manual = child.querySelector('[data-device-manual]');
                const manualHidden = child.querySelector('[data-device-manual-hidden]');
                const imeis = child.querySelector('[data-device-imeis]');

                if (hidden) hidden.name = `devices[${idx}][dispositivo_iddispositivo]`;
                if (qty) qty.name = `devices[${idx}][cantidad]`;
                if (manualHidden) manualHidden.name = `devices[${idx}][manual]`;
                if (manual) manual.name = `devices[${idx}][manual]`;
                if (imeis) imeis.name = `devices[${idx}][imeis]`;
            });

            syncEmpty();
        }

        function updateDisabledOptions() {
            const selects = Array.from(list.querySelectorAll('[data-device-select]'));
            const selectedIds = selects.map(s => s.value).filter(v => v !== '');

            selects.forEach(selectEl => {
                const ts = selectEl.tomselect || selectEl.tomSelect || selectEl._tomselect;
                if (!ts) return;
                const currentValue = selectEl.value;

                // 1. Actualizar el <select> original
                Array.from(selectEl.options).forEach(opt => {
                    if (opt.value === '') return;
                    opt.disabled = (opt.value !== currentValue && selectedIds.includes(opt.value));
                });

                // 2. Actualizar el estado interno de TomSelect
                Object.values(ts.options).forEach(opt => {
                    if (opt.value === '') return;
                    const isDis = (opt.value !== currentValue && selectedIds.includes(opt.value));
                    
                    // Actualizar propiedad
                    opt.disabled = isDis;
                    
                    // Actualizar en el DOM si ya está renderizado
                    const optEl = ts.getOption(opt.value);
                    if (optEl) {
                        if (isDis) {
                            optEl.classList.add('disabled');
                        } else {
                            optEl.classList.remove('disabled');
                        }
                    }
                });

                // 3. Limpiar caché de renderizado para forzar redibujado la próxima vez que se abra
                if (ts.renderCache) {
                    ts.renderCache = {};
                }
            });
        }

        function addRow(data = {}) {
            if (!template || !list) return;

            const fragment = template.content.cloneNode(true);
            const row = fragment.querySelector('[data-device-row]');
            if (!row) return;

            const select = row.querySelector('[data-device-select]');
            const hidden = row.querySelector('[data-input-device]');
            const qty = row.querySelector('[data-device-qty]');
            const manual = row.querySelector('[data-device-manual]');
            const imeis = row.querySelector('[data-device-imeis]');

            const isLocked = (<?php echo ($readOnly ?? false) ? 'true' : 'false'; ?>) && !window.crudFormEditUnlocked;

            // Como el select ya tiene los <option> gracias a Blade, solo asignamos el valor directo
            if (select) {
                const selectValue = String(data.dispositivo_iddispositivo ?? '');
                if (selectValue) {
                    select.value = selectValue;
                    if (hidden) hidden.value = selectValue;
                }
                if (isLocked) select.disabled = true;
            }

            if (qty) {
                qty.value = data.cantidad ?? 1;
                if (isLocked) qty.disabled = true;
            }
            if (manual) {
                manual.checked = data.manual ?? true;
                if (isLocked) manual.disabled = true;
            }
            if (imeis) {
                const imeiVal = data.imeis ? (Array.isArray(data.imeis) ? data.imeis.join('\n') : data.imeis) : '';
                imeis.value = imeiVal;

                const preview = row.querySelector('[data-device-imeis-preview]');
                if (preview) {
                    if (isLocked) preview.disabled = true;
                    if (imeiVal) {
                        const arr = imeiVal.split(/[,\s\n]+/).filter(i => i.length > 0);
                        if (arr.length > 3) {
                            preview.value = arr.slice(0, 3).join(', ') + '...';
                        } else {
                            preview.value = arr.join(', ');
                        }
                    }
                }
            }

            const removeBtnInRow = row.querySelector('[data-remove-device]');
            if (removeBtnInRow && isLocked) {
                removeBtnInRow.disabled = true;
            }

            // Agregamos la fila al contenedor
            list.appendChild(fragment);

            // Inicializar TomSelect solo para este select y luego enganchar listeners
            if (select) {
                initTomSelect(select);
            }
            wireRow(row);

            // Sincronizamos la visualización del campo IMEI según el estado auto/manual
            if (manual && !manual.checked) {
                const imeiVal = imeis ? imeis.value.trim() : '';
                if (!imeiVal) {
                    updateAutoImeis(row);
                } else {
                    updatePreviewFromInput(row);
                }
            } else {
                updatePreviewFromInput(row);
            }
            updateRows();
            updateDisabledOptions();
        }

        // Fetch preview IMEIs para nota de ingreso
        async function fetchPreviewIngresoImeisForRow(row, deviceId, qty) {
            try {
                if (!deviceId) return false;
                const url = new URL('<?php echo route('modules.almacen.nota-ingreso.imeis-preview'); ?>', window.location.origin);
                url.searchParams.set('device_id', deviceId);
                url.searchParams.set('qty', String(qty));
                const res = await fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!res.ok) return false;
                const data = await res.json();
                const imeis = Array.isArray(data.imeis) ? data.imeis : [];
                const imeisInput = row.querySelector('[data-device-imeis]');
                const previewInput = row.querySelector('[data-device-imeis-preview]');
                const manualCheck = row.querySelector('[data-device-manual]');
                const isManual = manualCheck && manualCheck.checked;
                if (imeis.length > 0 && imeisInput && !isManual) {
                    imeisInput.value = imeis.join('\n');
                    if (previewInput) previewInput.value = imeis.length > 3 ? imeis.slice(0,3).join(', ') + '...' : imeis.join(', ');
                    return true;
                }
                return false;
            } catch (e) {
                return false;
            }
        }

        if (addBtn) {
            addBtn.addEventListener('click', () => addRow({}));
        }

        // Manejar habilitación al hacer clic en "Editar"
        const editBtn = document.getElementById('btnEditar');
        if (editBtn) {
            editBtn.addEventListener('click', () => {
                if (addBtn) addBtn.disabled = false;
                container.querySelectorAll('[data-remove-device]').forEach(btn => {
                    btn.disabled = false;
                });
                container.querySelectorAll('[data-device-select],[data-device-qty],[data-device-manual],[data-device-imeis-preview]').forEach(el => {
                    try {
                        el.disabled = false;
                        el.classList.remove('bg-slate-50','cursor-not-allowed');
                        el.classList.add('cursor-pointer','bg-white');
                    } catch (e) {}
                });
                // Re-inicializar TomSelects para selects que estaban deshabilitados
                container.querySelectorAll('select[data-device-select]').forEach(s => {
                    try {
                        initTomSelect(s);
                    } catch (e) {}
                });
            });
        }

        const initial = @json($devicesInitial ?? []);
        if (Array.isArray(initial) && initial.length > 0) {
            initial.forEach(device => addRow(device));
        } else {
            addRow({});
        }

        // Inicializar TomSelects para todas las filas ya creadas (centralizado)
        initAllTomSelects();
    })();
</script>