<style>
    [data-devices-salida-inline] .ts-control .item {
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        max-width: calc(100% - 16px) !important;
    }
</style>
<div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" data-devices-salida-inline>
    <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 md:flex-row md:items-center md:justify-between">
        <div class="w-full">
            <h2 class="text-lg font-semibold text-black">Dispositivos a retirar</h2>
            <p class="mt-1 text-sm text-slate-600">
                Si no marcas &ldquo;IMEIs Manual&rdquo;, el sistema seleccionará automáticamente los primeros IMEIs
                disponibles. Si lo marcas, debes escribir el IMEI exactos.
            </p>
        </div>
        <button type="button" data-add-device-salida
            class="inline-flex shrink-0 items-center gap-2 rounded-md border px-3 py-2 text-sm focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none transition-all disabled:opacity-50 disabled:cursor-not-allowed"
            style="background-color:#B91c1c; color:#fff;" {{ ($readOnly ?? false) ? 'disabled' : '' }}>
            <span class="text-base leading-none">+</span> Agregar
        </button>
    </div>

    <div class="mt-4">
        <div class="border border-slate-200 bg-white shadow-sm rounded-xl">
            <div class="border-b border-slate-200 bg-slate-50" style="padding: 12px 20px;">
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

<template data-device-salida-row-template>
    <div data-device-salida-row
        style="display:grid; grid-template-columns: minmax(0,1fr) 120px 110px minmax(0,1fr) 44px; gap:16px; align-items:center; padding:8px 20px; margin:4px 0; border:1px solid #e2e8f0; border-radius:8px; background:#fff; box-shadow:0 1px 2px rgba(15,23,42,.04);">
        {{-- Columna dispositivo --}}
        <div style="min-width:0;">
            <select
                class="tom-select tom-select--compact w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-primary focus:ring-1 focus:ring-primary"
                data-device-salida-select style="width:100%;">
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
        <div style="display:flex; flex-direction:column; align-items:center; gap:4px;">
            <input data-device-salida-qty type="number" min="1" step="1" value="1"
                class="w-16 rounded-md border border-slate-300 px-3 py-1.5 text-sm text-center focus:border-primary focus:ring-1 focus:ring-primary" />
            <span data-salida-qty-warning
                class="hidden text-[10px] text-red-600 font-medium text-center leading-tight">Excede stock
                disponible</span>
        </div>

        {{-- Columna IMEIs Manual --}}
        <div style="display:flex; align-items:center; justify-content:center;">
            <input type="hidden" data-device-salida-manual-hidden value="0">
            <input data-device-salida-manual type="checkbox" value="1"
                class="w-4 h-4 text-primary border-slate-300 rounded focus:ring-primary cursor-pointer" />
        </div>

        {{-- Columna IMEI manual --}}
        <div class="imeis-salida-col" style="min-width:0; visibility:hidden; opacity:0; transition:opacity 0.15s ease;">
            <textarea data-device-salida-imeis rows="1"
                class="w-full rounded-lg border border-slate-300 px-3 py-1 text-sm focus:border-primary focus:ring-1 focus:ring-primary"
                placeholder="EJM: IMEI1, IMEI2" style="min-height:2.4rem; max-height:4rem; resize:none;"></textarea>
        </div>

        {{-- Columna quitar --}}
        <div style="display:flex; align-items:center; justify-content:end; padding-top:6px;">
            <button type="button" data-remove-device-salida title="Quitar fila" style="color:#B91c1c"
                class="hover:scale-110 transition-transform disabled:opacity-50 disabled:cursor-not-allowed"
                {{ ($readOnly ?? false) ? 'disabled' : '' }}>
                <span class="text-lg leading-none">X</span>
            </button>
        </div>
    </div>
</template>

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
            dropdownParent: 'body',
            hidePlaceholder: true,
            plugins: { dropdown_input: {} },
            create: false,
            closeAfterSelect: true
        };

        function initTomSelect(select) {
            if (!select || typeof window.TomSelect !== 'function') return;
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

        function toggleImeiVisibility(row, isChecked) {
            const col = row.querySelector('.imeis-salida-col');
            const area = row.querySelector('[data-device-salida-imeis]');
            if (!col) return;
            if (isChecked) {
                col.style.visibility = 'visible';
                col.style.opacity = '1';
            } else {
                col.style.visibility = 'hidden';
                col.style.opacity = '0';
                if (area) area.value = '';
            }
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
                    const qty = parseInt(qtyInput ? qtyInput.value : '1', 10) || 1;
                    updateStockBadge(row, currentDeviceId, qty);
                });
            }

            // Enforce cantidad >= 1 y mostrar advertencia de stock
            if (qtyInput) {
                const enforce = () => {
                    try {
                        let v = parseInt(String(qtyInput.value || '').replace(/[^0-9-]/g, ''), 10);
                        if (Number.isNaN(v) || v < 1) v = 1;
                        if (String(qtyInput.value) !== String(v)) qtyInput.value = v;
                    } catch (e) { qtyInput.value = 1; }
                    updateStockBadge(row, hidden ? hidden.value : '', parseInt(qtyInput.value, 10) || 1);
                };
                qtyInput.addEventListener('input', enforce);
                qtyInput.addEventListener('change', enforce);
                enforce();
            }

            // Toggle visibilidad IMEI
            if (manual) {
                manual.addEventListener('change', () => toggleImeiVisibility(row, manual.checked));
            }

            if (remove) {
                remove.addEventListener('click', () => { row.remove(); updateRows(); });
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
            }

            const removeBtnInRow = row.querySelector('[data-remove-device-salida]');
            if (removeBtnInRow && isLocked) {
                removeBtnInRow.disabled = true;
            }

            list.appendChild(fragment);

            if (select) initTomSelect(select);
            wireRow(row);
            toggleImeiVisibility(row, manual ? manual.checked : false);

            // Mostrar badge de stock inicial si hay dispositivo preseleccionado
            if (hidden && hidden.value) {
                const q = parseInt(qty ? qty.value : '1', 10) || 1;
                updateStockBadge(row, hidden.value, q);
            }

            updateRows();
        }

        if (addBtn) addBtn.addEventListener('click', () => addRow({}));

        // Manejar habilitación al hacer clic en "Editar"
        const editBtn = document.getElementById('btnEditar');
        if (editBtn) {
            editBtn.addEventListener('click', () => {
                if (addBtn) addBtn.disabled = false;
                container.querySelectorAll('[data-remove-device-salida]').forEach(btn => {
                    btn.disabled = false;
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