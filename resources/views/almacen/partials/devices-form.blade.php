<style>
    [data-devices-inline] .ts-control .item {
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        max-width: calc(100% - 16px) !important;
    }
</style>
<div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" data-devices-inline>
    <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 md:flex-row md:items-center md:justify-between">
        <div class="w-full">
            <h2 class="text-lg font-semibold text-black">Dispositivos por agregar</h2>
            <p class="mt-1 text-sm text-slate-600">
                Marca "Manual IMEIs" para introducir IMEIs; si no se marca, los IMEIs se generarán automáticamente.
            </p>
        </div>
        <button type="button" data-add-device
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

<template data-device-row-template>
    <div data-device-row
        style="display:grid; grid-template-columns: minmax(0,1fr) 120px 110px minmax(0,1fr) 44px; gap:16px; align-items:center; padding:8px 20px; margin:4px 0; border:1px solid #e2e8f0; border-radius:8px; background:#fff; box-shadow:0 1px 2px rgba(15,23,42,.04);">
        <div style="min-width:0;">
            <select
                class="tom-select tom-select--compact w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-primary focus:ring-1 focus:ring-primary"
                data-device-select style="width:100%;">
                <option value="">Selecciona un dispositivo</option>
                @foreach(($almacenOptions ?? []) as $option)
                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
            <input type="hidden" data-input-device value="">
        </div>

        <div style="display:flex; align-items:center; justify-content:center;">
            <input data-device-qty type="number" min="1" step="1" value="1"
                class="w-16 rounded-md border border-slate-300 px-3 py-1.5 text-sm text-center focus:border-primary focus:ring-1 focus:ring-primary" />
        </div>

        <div style="display:flex; align-items:center; justify-content:center;">
            <input type="hidden" data-device-manual-hidden value="0">
            <input data-device-manual type="checkbox" value="1"
                class="w-4 h-4 text-primary border-slate-300 rounded focus:ring-primary cursor-pointer" />
        </div>

        <div class="imeis-col" style="min-width:0; visibility: hidden; opacity: 0; transition: opacity 0.15s ease;">
            <textarea data-device-imeis rows="1"
                class="w-full rounded-lg border border-slate-300 px-3 py-1 text-sm focus:border-primary focus:ring-1 focus:ring-primary py-1.5"
                placeholder="EJM: IMEI 1, IMEI 2" style="min-height:2.4rem; max-height:4rem; resize: none;"></textarea>
        </div>

        <div style="display:flex; align-items:center; justify-content:end;">
            <button type="button" data-remove-device title="Quitar fila" style="color: #B91c1c"
                class="hover:scale-110 transition-transform disabled:opacity-50 disabled:cursor-not-allowed"
                {{ ($readOnly ?? false) ? 'disabled' : '' }}>
                <span class="text-lg leading-none">X</span>
            </button>
        </div>
    </div>
</template>

@php
    $devicesInitial = $devices ?? [];
@endphp

<script>
    (function () {
        const container = document.querySelector('[data-devices-inline]');
        if (!container) return;

        const list = container.querySelector('[data-devices-list]');
        const empty = container.querySelector('[data-devices-empty]');
        const template = document.querySelector('template[data-device-row-template]');
        const addBtn = container.querySelector('[data-add-device]');

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
            dropdownParent: 'body',
            hidePlaceholder: true,
            plugins: { dropdown_input: {} },
            create: false,
            closeAfterSelect: true
        };

        function initTomSelect(select) {
            if (!select || typeof window.TomSelect !== 'function') return;

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
            const selects = container.querySelectorAll('select[data-device-select]');
            selects.forEach((s) => initTomSelect(s));
        }

        function toggleImeiVisibility(row, isChecked) {
            const imeisCol = row.querySelector('.imeis-col');
            const imeis = row.querySelector('[data-device-imeis]');

            if (isChecked) {
                imeisCol.style.visibility = 'visible';
                imeisCol.style.opacity = '1';
            } else {
                imeisCol.style.visibility = 'hidden';
                imeisCol.style.opacity = '0';
                if (imeis) imeis.value = '';
            }
        }

        function wireRow(row) {
            const select = row.querySelector('[data-device-select]');
            const hidden = row.querySelector('[data-input-device]');
            const manual = row.querySelector('[data-device-manual]');
            const remove = row.querySelector('[data-remove-device]');

            // No inicializamos aquí: centralizaremos inicialización para evitar duplicados.

            if (select && hidden) {
                select.addEventListener('change', () => {
                    hidden.value = select.value;
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
                };
                qtyInput.addEventListener('input', enforceMin);
                qtyInput.addEventListener('change', enforceMin);
                // apply once on init
                enforceMin();
            }

            if (manual) {
                manual.addEventListener('change', () => {
                    toggleImeiVisibility(row, manual.checked);
                });
            }

            if (remove) {
                remove.addEventListener('click', () => {
                    row.remove();
                    updateRows();
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
                manual.checked = !!data.manual;
                if (isLocked) manual.disabled = true;
            }
            if (imeis) {
                imeis.value = data.imeis ? (Array.isArray(data.imeis) ? data.imeis.join('\n') : data.imeis) : '';
                if (isLocked) imeis.disabled = true;
            }

            const removeBtnInRow = row.querySelector('[data-remove-device]');
            if (removeBtnInRow && isLocked) {
                removeBtnInRow.disabled = true;
            }

            // Agregamos la fila al contenedor
            list.appendChild(fragment);

            // Inicializar TomSelect solo para este select y luego enganchar listeners
            if (select) initTomSelect(select);
            wireRow(row);

            // Sincronizamos la visibilidad del campo IMEI
            toggleImeiVisibility(row, manual ? manual.checked : false);

            updateRows();
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