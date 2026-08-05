<style>
    [data-devices-salida-inline] .ts-control .item {
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
        grid-template-columns: minmax(320px, 1.8fr) 120px 110px minmax(280px, 1.6fr) 44px;
        gap: 16px;
        align-items: center;
        padding: 5px 8px;
        margin: 4px 0;
        width: 100%;
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
        .imeis-salida-col {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        /* Mantener la cabecera visible y permitir scroll horizontal en móvil */
        .device-table-header {
            display: block;
        }

        .device-table-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .device-table-inner {
            min-width: 920px;
            width: max-content;
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
            width: auto;
            min-width: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .device-row-col-start {
            flex-direction: row;
            align-items: center;
            justify-content: flex-start;
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
<div class="rounded-md border border-slate-200 bg-white p-5 shadow-sm" data-devices-salida-inline style="position:relative;">
    @if(($readOnly ?? false))
        <div class="devices-readonly-overlay" aria-hidden="true" title="Solo lectura" style="position:absolute;inset:0;z-index:50;cursor:not-allowed;background:transparent"></div>
    @endif
    <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 md:flex-row md:items-center md:justify-between">
        <div class="w-full">
            <h2 class="text-lg font-semibold text-black">Dispositivos a retirar</h2>
            <p class="mt-1 text-sm text-slate-600">
                Si no marcas &ldquo;IMEIs Manual&rdquo;, el sistema seleccionará automáticamente los primeros IMEIs
                disponibles. Si lo marcas, debes escribir el IMEI exactos.
            </p>
        </div>
        <button type="button" data-add-device-salida
            class="inline-flex shrink-0 items-center justify-center gap-2 rounded-md border px-3 py-2 text-sm focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none transition-all disabled:opacity-50 disabled:cursor-not-allowed"
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
                            <div style="text-align:left;">Dispositivo <span
                                    class="normal-case font-normal text-slate-400">(stock disponible)</span></div>
                            <div style="text-align:center;">Cantidad</div>
                            <div style="text-align:center;">IMEIs Manual</div>
                            <div style="text-align:left;" class="imei-header-label-salida">IMEIs a retirar</div>
                            <div style="text-align:center;">Quitar</div>
                        </div>
                    </div>

                    <div class="p-3">
                        <div data-devices-salida-list class="flex flex-col gap-2"></div>

                        <div data-devices-salida-empty
                            class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-500">
                            No hay filas. Pulsa &ldquo;Agregar&rdquo;.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<template data-device-salida-row-template>
    <div data-device-salida-row class="device-row-container">
        {{-- Columna dispositivo --}}
        <div class="device-row-col device-row-col-start">
            <label class="device-mobile-label device-mobile-label-mb">Dispositivo</label>
            <select
                class="tom-select tom-select--compact w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs focus:border-primary focus:ring-1 focus:ring-primary {{ ($readOnly ?? false) ? 'bg-slate-50 cursor-not-allowed' : '' }}"
                data-device-salida-select style="width:100%;" {{ ($readOnly ?? false) ? 'disabled' : '' }}>
                <option value="">Selecciona un dispositivo</option>
                @foreach(($almacenOptions ?? []) as $option)
                    <option value="{{ $option['value'] }}" data-stock="{{ $option['stock'] ?? 0 }}">
                        {{ $option['label'] }}{{ isset($option['stock']) ? ' — stock: ' . $option['stock'] : '' }}
                    </option>
                @endforeach
            </select>
            <input type="hidden" data-input-device-salida value="">
        </div>

        {{-- Columna cantidad --}}
        <div class="device-row-col device-row-col-start">
            <label class="device-mobile-label">Cantidad</label>
            <div style="display:flex; flex-direction:column; align-items:center; gap:4px;">
                    <input data-device-salida-qty type="number" min="1" step="1" value="1"
                        class="w-16 rounded-md border border-slate-300 px-3 py-1.5 text-sm text-center focus:border-primary focus:ring-1 focus:ring-primary {{ ($readOnly ?? false) ? 'bg-slate-50 cursor-not-allowed' : '' }}"
                        {{ ($readOnly ?? false) ? 'disabled' : '' }} />
                <span data-salida-qty-warning
                    class="hidden text-[10px] text-red-600 font-medium text-center leading-tight">Excede stock
                    disponible</span>
            </div>
        </div>

        {{-- Columna IMEIs Manual --}}
        <div class="device-row-col">
            <label class="device-mobile-label">IMEIs Manual</label>
            <div class="flex items-center">
                <input type="hidden" data-device-salida-manual-hidden value="0">
                <input data-device-salida-manual type="checkbox" value="1"
                    class="w-4 h-4 text-primary border-slate-300 rounded focus:ring-primary {{ ($readOnly ?? false) ? 'cursor-not-allowed' : 'cursor-pointer' }}" {{ ($readOnly ?? false) ? 'disabled' : '' }} />
            </div>
        </div>

        {{-- Columna IMEI manual --}}
        <div class="imeis-salida-col device-row-col device-row-col-start">
            <label class="device-mobile-label device-mobile-label-mb">IMEIs a retirar</label>
            <input type="hidden" data-device-salida-imeis value="">
            <input type="text" data-device-salida-imeis-preview readonly
                class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-primary focus:ring-1 focus:ring-primary {{ ($readOnly ?? false) ? 'bg-slate-50 cursor-not-allowed' : 'cursor-pointer bg-white' }}"
                placeholder="Clic para ver/agregar IMEIs..." {{ ($readOnly ?? false) ? 'disabled' : '' }} />
        </div>

        {{-- Columna quitar --}}
        <div class="device-row-col device-row-col-end">
            <button type="button" data-remove-device-salida title="Quitar fila" style="color:#B91c1c"
                class="flex items-center hover:scale-110 transition-transform disabled:opacity-50 disabled:cursor-not-allowed"
                {{ ($readOnly ?? false) ? 'disabled' : '' }}>
                <span class="btn-quitar-text">Quitar dispositivo</span>
                <span class="text-lg leading-none">X</span>
            </button>
        </div>
    </div>
</template>

<!-- Modal de IMEIs Salida -->
<div id="imeisSalidaModal"
    class="fixed inset-0 hidden items-center justify-center p-4"
    style="z-index: 9999; background-color: rgba(0, 0, 0, 0.78);" role="dialog" aria-modal="true">
    <div class="w-full overflow-hidden rounded-[1.25rem] bg-white shadow-[0_24px_80px_rgba(15,23,42,0.16)] flex flex-col max-h-[85vh] modal-dialog"
        style="max-width: 650px;">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-6 py-4">
            <h3 class="text-lg font-semibold text-slate-800">Ingresar IMEIs Manuales</h3>
            <button type="button" id="closeImeisSalidaModalBtn"
                class="ml-auto rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700">
                <span class="text-2xl leading-none" style="display:flex; align-items:center; justify-content:center; width:20px; height:20px;">&times;</span>
            </button>
        </div>
        
        <div class="px-6 py-5 overflow-y-auto flex-1" style="min-height: 0;">
            <label class="mb-2 block text-sm font-medium text-slate-700">Pega IMEIs separados por coma, espacio o nueva línea.</label>
            <textarea id="modalImeisSalidaTextarea" rows="8"
                class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-2.5 text-sm text-slate-800 transition duration-200 ease-in-out focus:border-red-600 focus:ring-2 focus:ring-red-500/20 font-mono"
                placeholder="Ej:&#10;123456789&#10;987654321"></textarea>

            <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div class="text-sm text-slate-600">
                    <span class="font-medium text-slate-800">Cantidad requerida:</span> <span id="modalSalidaRequiredCount"
                        class="font-bold">0</span> <span class="mx-2">|</span>
                    <span class="font-medium text-emerald-600">Válidos:</span> <span id="modalSalidaValidCount"
                        class="font-bold text-emerald-600">0</span> <span class="mx-2">|</span>
                    <span class="font-medium text-red-600">Errores/Duplicados:</span> <span id="modalSalidaErrorCount"
                        class="font-bold text-red-600">0</span>
                </div>
                <div id="modalSalidaErrorText" class="text-xs font-semibold text-red-600 hidden">La cantidad no coincide o hay
                    errores.</div>
            </div>
        </div>
        
        <div class="border-t border-slate-200 px-6 py-4 flex flex-col gap-3 sm:flex-row sm:justify-end">
            <button type="button" id="cancelImeisSalidaModalBtn"
                class="rounded-md border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-100 focus:outline-none" style="border-color:#000000;color: #000000;">
                Cancelar
            </button>
            <button type="button" id="saveImeisSalidaModalBtn"
                class="rounded-lg items-center justify-center bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-dark transition-colors disabled:opacity-50 disabled:cursor-not-allowed focus:outline-none"
                style="background-color: #B91c1c;">Guardar</button>
        </div>
    </div>
</div>

@php
    $devicesInitialSalida = $devices ?? [];
    $stockMapJson = json_encode(
        collect($almacenOptions ?? [])->keyBy('value')->map(fn($o) => (int) ($o['stock'] ?? 0))->all()
    );
@endphp

<script>
    (function () {
        const container = document.querySelector('[data-devices-salida-inline]');
        if (!container) return;
        const isReadOnly = <?php echo ($readOnly ?? false) ? 'true' : 'false'; ?>;

        const list = container.querySelector('[data-devices-salida-list]');
        const empty = container.querySelector('[data-devices-salida-empty]');
        const template = document.querySelector('template[data-device-salida-row-template]');
        const addBtn = container.querySelector('[data-add-device-salida]');

        // Mapa de stock por id de dispositivo
        const stockMap = <?php echo $stockMapJson; ?>;

        function syncEmpty() {
            if (empty) empty.classList.toggle('hidden', list.children.length > 0);
        }

        const TOMSELECT_OPTIONS = {
            allowEmptyOption: true,
            maxItems: 1,
            placeholder: 'Selecciona un dispositivo',
            // dropdownParent: 'body',
            hidePlaceholder: true,
            plugins: { dropdown_input: {} },
            create: false,
            closeAfterSelect: true
        };

        function initTomSelect(select) {
            if (!select || typeof window.TomSelect !== 'function') return;
            // No inicializar TomSelect si el select está deshabilitado (modo sólo lectura)
            if (select.disabled) return null;
            try {
                const existing = select.tomselect || select.tomSelect || select._tomselect;
                if (existing && typeof existing.destroy === 'function') existing.destroy();
            } catch (e) { }
            const instance = new TomSelect(select, TOMSELECT_OPTIONS);
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
            } catch (e) { }
            return instance;
        }

        function initAllTomSelects() {
            if (isReadOnly) return; // evitar inicializar TomSelects en modo solo-lectura
            const selects = container.querySelectorAll('select[data-device-salida-select]');
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

        // Variables del Modal
        let currentModalRow = null;
        let currentRequiredQty = 0;
        
        const imeisModal = document.getElementById('imeisSalidaModal');
        if (imeisModal && document.body) {
            document.body.appendChild(imeisModal);
        }
        const modalImeisTextarea = document.getElementById('modalImeisSalidaTextarea');
        const modalRequiredCount = document.getElementById('modalSalidaRequiredCount');
        const modalValidCount = document.getElementById('modalSalidaValidCount');
        const modalErrorCount = document.getElementById('modalSalidaErrorCount');
        const modalErrorText = document.getElementById('modalSalidaErrorText');
        const saveImeisModalBtn = document.getElementById('saveImeisSalidaModalBtn');
        const cancelImeisModalBtn = document.getElementById('cancelImeisSalidaModalBtn');
        const closeImeisModalBtn = document.getElementById('closeImeisSalidaModalBtn');

        function updatePreviewFromInput(row) {
            const imeisInput = row.querySelector('[data-device-salida-imeis]');
            const previewInput = row.querySelector('[data-device-salida-imeis-preview]');
            const manualCheck = row.querySelector('[data-device-salida-manual]');
            
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
            const qtyInput = row.querySelector('[data-device-salida-qty]');
            const imeisInput = row.querySelector('[data-device-salida-imeis]');
            const hidden = row.querySelector('[data-input-device-salida]');

            let qty = 1;
            if (qtyInput) {
                qty = parseInt(String(qtyInput.value).replace(/[^0-9-]/g, ''), 10) || 1;
            }

            const deviceId = hidden ? hidden.value : '';

            // Intentar obtener IMEIs reales desde el servidor; si falla, usar placeholder AUTO-STOCK
            fetchPreviewImeisForRow(row, deviceId, qty).then((found) => {
                if (!found) {
                    const arr = [];
                    for (let i = 1; i <= qty; i++) arr.push(`AUTOMATICO-${i}`);
                    if (imeisInput) imeisInput.value = arr.join('\n');
                    updatePreviewFromInput(row);
                }
            }).catch(() => {
                const arr = [];
                for (let i = 1; i <= qty; i++) arr.push(`AUTOMATICO-${i}`);
                if (imeisInput) imeisInput.value = arr.join('\n');
                updatePreviewFromInput(row);
            });
        }

        function clearImeis(row) {
            const imeisInput = row.querySelector('[data-device-salida-imeis]');
            if (imeisInput) imeisInput.value = '';
            updatePreviewFromInput(row);
        }

        function openImeisModal(row) {
            const isLocked = (<?php echo ($readOnly ?? false) ? 'true' : 'false'; ?>) && !window.crudFormEditUnlocked;
            
            const manualCheck = row.querySelector('[data-device-salida-manual]');
            const isAuto = manualCheck && !manualCheck.checked;
            const isReadOnly = isLocked || isAuto;

            currentModalRow = row;
            const qtyInput = row.querySelector('[data-device-salida-qty]');
            currentRequiredQty = qtyInput ? parseInt(qtyInput.value) || 0 : 0;

            const imeisInput = row.querySelector('[data-device-salida-imeis]');
            modalImeisTextarea.value = imeisInput ? imeisInput.value : '';

            if (modalRequiredCount) modalRequiredCount.textContent = currentRequiredQty;
            
            if (isReadOnly) {
                modalImeisTextarea.readOnly = true;
                modalImeisTextarea.classList.add('bg-slate-100');
                if (saveImeisModalBtn) saveImeisModalBtn.style.display = 'none';
                document.querySelector('#imeisSalidaModal h3').textContent = isAuto ? 'IMEIs de Stock (Previsualización)' : 'IMEIs Manuales';
            } else {
                modalImeisTextarea.readOnly = false;
                modalImeisTextarea.classList.remove('bg-slate-100');
                if (saveImeisModalBtn) saveImeisModalBtn.style.display = 'flex';
                document.querySelector('#imeisSalidaModal h3').textContent = 'Ingresar IMEIs Manuales';
            }

            updateModalStatus();

            imeisModal.classList.remove('hidden');
            imeisModal.classList.add('flex');
            
            if (!isReadOnly) modalImeisTextarea.focus();
        }

        function closeImeisModal() {
            if (!imeisModal) return;
            imeisModal.classList.add('hidden');
            imeisModal.classList.remove('flex');
            currentModalRow = null;
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
                const isAutoPattern = /^AUTOMATICO-\d+$/.test(imei) || /^AUTOMATICO-\d+$/.test(imei) || /^STOCK-\d+$/.test(imei);
                const isNumeric = /^\d+$/.test(imei);
                const isCorrectLength = imei.length >= 5 && imei.length <= 20;
                const isDuplicate = seen.has(imei);

                if ((isAutoPattern || (isNumeric && isCorrectLength)) && !isDuplicate) {
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

        if (modalImeisTextarea) {
            modalImeisTextarea.addEventListener('input', updateModalStatus);
        }
        if (cancelImeisModalBtn) {
            cancelImeisModalBtn.addEventListener('click', closeImeisModal);
        }
        if (closeImeisModalBtn) {
            closeImeisModalBtn.addEventListener('click', closeImeisModal);
        }
        if (saveImeisModalBtn) {
            saveImeisModalBtn.addEventListener('click', () => {
                if (!currentModalRow) return;

                const { valid } = updateModalStatus();

                const imeisInput = currentModalRow.querySelector('[data-device-salida-imeis]');
                const previewInput = currentModalRow.querySelector('[data-device-salida-imeis-preview]');

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

        function updateStockBadge(row, deviceId, qty) {
            const badge = row.querySelector('[data-salida-stock-badge]');
            const badgeInner = row.querySelector('[data-salida-stock-badge-inner]');
            const warning = row.querySelector('[data-salida-qty-warning]');
            const qtyInput = row.querySelector('[data-device-salida-qty]');

            if (!badge || !badgeInner || !deviceId) {
                if (badge) badge.classList.add('hidden');
                return;
            }

            const stock = stockMap[String(deviceId)] ?? null;

            if (stock === null) {
                badge.classList.add('hidden');
                if (warning) warning.classList.add('hidden');
                return;
            }

            badge.classList.remove('hidden');

            const excede = qty > stock;

            if (excede) {
                badgeInner.textContent = `⚠ Stock disponible: ${stock} — cantidad excede el stock`;
                badgeInner.className = 'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium bg-red-50 text-red-700 border border-red-200';
                if (warning) warning.classList.remove('hidden');
                if (qtyInput) qtyInput.style.borderColor = '#ef4444';
            } else {
                badgeInner.textContent = `Stock disponible: ${stock}`;
                badgeInner.className = stock === 0
                    ? 'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium bg-red-50 text-red-700 border border-red-200'
                    : 'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium bg-green-50 text-green-700 border border-green-200';
                if (warning) warning.classList.add('hidden');
                if (qtyInput) qtyInput.style.borderColor = '';
            }
        }

        function wireRow(row) {
            const select = row.querySelector('[data-device-salida-select]');
            const hidden = row.querySelector('[data-input-device-salida]');
            const manual = row.querySelector('[data-device-salida-manual]');
            const remove = row.querySelector('[data-remove-device-salida]');
            const qtyInput = row.querySelector('[data-device-salida-qty]');

            let currentDeviceId = hidden ? hidden.value : '';

            // Sync select -> hidden input + stock badge
            if (select && hidden) {
                select.addEventListener('change', () => {
                    hidden.value = select.value;
                    currentDeviceId = select.value;
                    
                    // Trigger cantidad enforce to clamp immediately when device changes
                    if (qtyInput) {
                        qtyInput.dispatchEvent(new Event('change'));
                    }
                    
                    const qty = parseInt(qtyInput ? qtyInput.value : '1', 10) || 1;
                    updateStockBadge(row, currentDeviceId, qty);
                    // Solicitar preview de IMEIs cuando cambia el dispositivo
                    if (!(manual && manual.checked)) fetchPreviewImeisForRow(row, currentDeviceId, qty).catch(()=>{});
                    updateDisabledOptions();
                });
            }

            // Enforce cantidad >= 1 y mostrar advertencia de stock
            if (qtyInput) {
                const enforce = () => {
                    try {
                        let v = parseInt(String(qtyInput.value || '').replace(/[^0-9-]/g, ''), 10);
                        if (Number.isNaN(v) || v < 1) v = 1;

                        const currentDeviceId = hidden ? hidden.value : '';
                        if (currentDeviceId) {
                            const stock = stockMap[String(currentDeviceId)];
                            if (stock !== undefined && stock !== null && stock > 0 && v > stock) {
                                v = stock;
                            }
                        }

                        if (String(qtyInput.value) !== String(v)) qtyInput.value = v;
                    } catch (e) { qtyInput.value = 1; }
                    updateStockBadge(row, hidden ? hidden.value : '', parseInt(qtyInput.value, 10) || 1);

                    if (manual && !manual.checked) {
                        updateAutoImeis(row);
                    } else if (currentModalRow === row) {
                        currentRequiredQty = parseInt(qtyInput.value) || 0;
                        if (modalRequiredCount) modalRequiredCount.textContent = currentRequiredQty;
                        updateModalStatus();
                    }
                };
                qtyInput.addEventListener('input', enforce);
                qtyInput.addEventListener('change', enforce);
                enforce();
            }

            // Toggle visibilidad/modal de IMEI
            if (manual) {
                manual.addEventListener('change', () => {
                    const deviceId = hidden ? hidden.value : '';
                    const qtyVal = qtyInput ? (parseInt(qtyInput.value, 10) || 1) : 1;
                    if (manual.checked) {
                        clearImeis(row);
                    } else {
                        // Cuando pasa a auto, solicitar IMEIs reales
                        fetchPreviewImeisForRow(row, deviceId, qtyVal).then(found => {
                            if (!found) updateAutoImeis(row);
                        }).catch(() => updateAutoImeis(row));
                    }
                });
            }

            const previewInput = row.querySelector('[data-device-salida-imeis-preview]');
            if (previewInput) {
                previewInput.addEventListener('click', () => openImeisModal(row));
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
                const hidden = child.querySelector('[data-input-device-salida]');
                const qty = child.querySelector('[data-device-salida-qty]');
                const manual = child.querySelector('[data-device-salida-manual]');
                const manualHidden = child.querySelector('[data-device-salida-manual-hidden]');
                const imeis = child.querySelector('[data-device-salida-imeis]');

                if (hidden) hidden.name = `devices[${idx}][dispositivo_iddispositivo]`;
                if (qty) qty.name = `devices[${idx}][cantidad]`;
                if (manualHidden) manualHidden.name = `devices[${idx}][manual]`;
                if (manual) manual.name = `devices[${idx}][manual]`;
                if (imeis) imeis.name = `devices[${idx}][imeis]`;
            });
            syncEmpty();
        }

        function updateDisabledOptions() {
            const selects = Array.from(list.querySelectorAll('[data-device-salida-select]'));
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
            const row = fragment.querySelector('[data-device-salida-row]');
            if (!row) return;

            const select = row.querySelector('[data-device-salida-select]');
            const hidden = row.querySelector('[data-input-device-salida]');
            const qty = row.querySelector('[data-device-salida-qty]');
            const manual = row.querySelector('[data-device-salida-manual]');
            const imeis = row.querySelector('[data-device-salida-imeis]');

            const isLocked = (<?php echo ($readOnly ?? false) ? 'true' : 'false'; ?>) && !window.crudFormEditUnlocked;

            if (select) {
                const val = String(data.dispositivo_iddispositivo ?? '');
                if (val) { select.value = val; if (hidden) hidden.value = val; }
                if (isLocked) select.disabled = true;
            }

            if (qty) {
                qty.value = data.cantidad ?? 1;
                if (isLocked) qty.disabled = true;
            }
            if (manual) {
                manual.checked = !!data.manual;
                if (isLocked) manual.disabled = true;
            }
            if (imeis) {
                imeis.value = data.imeis
                    ? (Array.isArray(data.imeis) ? data.imeis.join('\n') : data.imeis)
                    : '';
                if (isLocked) imeis.disabled = true;
                
                const preview = row.querySelector('[data-device-salida-imeis-preview]');
                if (preview && isLocked) preview.disabled = true;
            }

            const removeBtnInRow = row.querySelector('[data-remove-device-salida]');
            if (removeBtnInRow && isLocked) {
                removeBtnInRow.disabled = true;
            }

            list.appendChild(fragment);

            if (select) initTomSelect(select);
            wireRow(row);
            
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

            // Mostrar badge de stock inicial si hay dispositivo preseleccionado
            if (hidden && hidden.value) {
                const q = parseInt(qty ? qty.value : '1', 10) || 1;
                updateStockBadge(row, hidden.value, q);
                // Solicitar IMEIs preview desde el servidor
                fetchPreviewImeisForRow(row, hidden.value, q).catch(()=>{});
            }

            updateRows();
            updateDisabledOptions();
        }

        async function fetchPreviewImeisForRow(row, deviceId, qty) {
            try {
                if (!deviceId) return false;
                const url = new URL('<?php echo route('modules.almacen.nota-salida.imeis-preview'); ?>', window.location.origin);
                url.searchParams.set('device_id', deviceId);
                url.searchParams.set('qty', String(qty));
                const res = await fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!res.ok) return false;
                const data = await res.json();
                const imeis = Array.isArray(data.imeis) ? data.imeis : [];
                const imeisInput = row.querySelector('[data-device-salida-imeis]');
                const previewInput = row.querySelector('[data-device-salida-imeis-preview]');
                const manualCheck = row.querySelector('[data-device-salida-manual]');
                const isManual = manualCheck && manualCheck.checked;
                if (imeis.length > 0 && imeisInput && !isManual) {
                    imeisInput.value = imeis.join('\n');
                    if (previewInput) {
                        previewInput.value = imeis.length > 3 ? imeis.slice(0,3).join(', ') + '...' : imeis.join(', ');
                    }
                    return true;
                }
                return false;
            } catch (e) {
                return false;
            }
        }

        if (addBtn) addBtn.addEventListener('click', () => addRow({}));

        // Manejar habilitación al hacer clic en "Editar"
        const editBtn = document.getElementById('btnEditar');
        if (editBtn) {
            editBtn.addEventListener('click', () => {
                window.crudFormEditUnlocked = true;
                if (addBtn) addBtn.disabled = false;
                container.querySelectorAll('[data-remove-device-salida]').forEach(btn => {
                    btn.disabled = false;
                });
                // Rehabilitar selects/inputs/preview y re-inicializar TomSelects
                container.querySelectorAll('select[data-device-salida-select]').forEach(s => {
                    try { s.disabled = false; s.tabIndex = 0; initTomSelect(s); } catch (e) { }
                });
                container.querySelectorAll('input, textarea, button').forEach(el => {
                    try { el.disabled = false; el.tabIndex = 0; } catch (e) { }
                });
            });
        }

        const initial = @json($devicesInitialSalida ?? []);
        if (Array.isArray(initial) && initial.length > 0) {
            initial.forEach(device => addRow(device));
        } else {
            addRow({});
        }
    })();
</script>