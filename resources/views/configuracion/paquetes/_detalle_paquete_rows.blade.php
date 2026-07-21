<style>
    [data-paquete-inline] .ts-control .item {
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        max-width: calc(100% - 16px) !important;
    }

    .paquete-table-header {
        display: block;
    }

    .paquete-row-container {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 150px 110px;
        gap: 16px;
        align-items: center;
        padding: 8px 5px;
        margin: 4px 0;
        border: 1px solid #e2e8f0;
        border-radius: 5px;
        background: #fff;
        box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
    }

    .paquete-row-col {
        min-width: 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .paquete-row-col-start {
        justify-content: flex-start;
        width: 100%;
    }

    .paquete-row-col-center {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .paquete-mobile-label {
        display: none;
        font-size: 0.75rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .paquete-mobile-label-mb {
        margin-bottom: 0.25rem;
    }

    .modal-select-price.selected {
        background-color: #B91c1c;
        color: #ffffff ;
        border-color: #B91c1c ;
    }

    @media (max-width: 767px) {
        .paquete-table-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .paquete-table-inner {
            min-width: 500px;
        }

        .paquete-row-container {
            gap: 12px;
            padding: 8px 16px;
            border-radius: 8px;
        }
    }

</style>


<div class="rounded-md border border-slate-200 bg-white p-5 shadow-sm" data-paquete-inline style="position:relative;">
    @if(($readOnly ?? false))
        <div class="devices-readonly-overlay" aria-hidden="true" title="Solo lectura" style="position:absolute;inset:0;z-index:50;cursor:not-allowed;background:transparent"></div>
    @endif
    <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 md:flex-row md:items-center md:justify-between">
        <div class="w-full">
            <h2 class="text-lg font-semibold text-black">Detalle del paquete</h2>
            <p class="mt-1 text-sm text-slate-600">
                Agrega los almacenes y precios que componen este paquete.
            </p>
        </div>
        <button type="button" id="btn-add-detalle"
            class="inline-flex justify-center shrink-0 items-center gap-2 rounded-md border px-3 py-2 text-sm focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none transition-all disabled:opacity-50 disabled:cursor-not-allowed"
            style="background-color:#B91c1c; color:#fff;" {{ ($readOnly ?? false) ? 'disabled' : '' }}>
            <span class="text-base leading-none">+</span> Agregar
        </button>
    </div>

    <div class="mt-4">
        <div class="border border-slate-200 bg-white shadow-sm rounded-md">
            <div class="paquete-table-scroll">
                <div class="paquete-table-inner">
                    <div class="paquete-table-header border-b border-slate-200 bg-slate-50" style="padding: 12px 20px;">
                        <div style="display:grid; grid-template-columns: minmax(0,1fr) 150px 110px; gap:12px; align-items:center;"
                            class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500">
                            <div style="text-align:left;">Almacén</div>
                            <div style="text-align:center;">Precio</div>
                            <div style="text-align:center;">Acciones</div>
                        </div>
                    </div>

                    <div class="p-3">
                        <div id="detalle-paquete-list" class="flex flex-col gap-2"></div>

                        <div id="detalle-paquete-empty"
                            class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-500">
                            No hay filas. Pulsa "Agregar".
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Template for row -->
<template id="detalle-paquete-row-template">
    <div class="paquete-row-container" data-row>
        <div class="paquete-row-col paquete-row-col-start">
            <label class="paquete-mobile-label paquete-mobile-label-mb">Almacén</label>
            <select name="detalle[][almacen_idalmacen]"
                class="tom-select tom-select--compact w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-primary focus:ring-1 focus:ring-primary detalle-almacen {{ ($readOnly ?? false) ? 'bg-slate-50 cursor-not-allowed' : '' }}"
                data-almacen-select style="width:100%;" {{ ($readOnly ?? false) ? 'disabled' : '' }}>
                <option value="">Selecciona un almacén</option>
                @foreach(($almacenOptions ?? []) as $option)
                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
        </div>

        <div class="paquete-row-col gap-3">
            <label class="paquete-mobile-label">Precio</label>
            <input type="text" name="detalle[][precio]" placeholder="Precio"
                class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-center focus:border-primary focus:ring-1 focus:ring-primary detalle-precio {{ ($readOnly ?? false) ? 'bg-slate-50 cursor-not-allowed' : '' }}"
                {{ ($readOnly ?? false) ? 'disabled' : '' }} />
        </div>

        <div class="paquete-row-col paquete-row-col-center gap-2">
            <button type="button" class="btn-ver-precios inline-flex items-center rounded-md border px-3 py-1 text-sm" title="Ver precios" style="background-color:#B91c1c; color:#fff;">Precios</button>
            <button type="button" class="btn-remove flex items-center hover:scale-110 transition-transform disabled:opacity-50 disabled:cursor-not-allowed" style="color: #B91c1c" title="Quitar fila" {{ ($readOnly ?? false) ? 'disabled' : '' }}>
                <span class="text-lg leading-none font-bold">X</span>
            </button>
        </div>
    </div>
</template>

<!-- Modal ver precios -->
<div id="detalle-precios-modal" class="fixed inset-0 hidden items-center justify-center p-4"
    style="z-index: 10000; background-color: rgba(0, 0, 0, 0.78);" role="dialog" aria-modal="true">
    <div class="w-full overflow-hidden rounded-[1.25rem] bg-white shadow-[0_24px_80px_rgba(15,23,42,0.16)] flex flex-col max-h-[85vh] modal-dialog"
        style="max-width: 650px;">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-6 py-4">
            <h3 id="detalle-precios-title" class="text-lg font-semibold text-slate-800">Precios</h3>
            <button type="button" id="detalle-precios-close"
                class="ml-auto rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700">
                <span class="text-2xl leading-none"
                    style="display:flex; align-items:center; justify-content:center; width:20px; height:20px;">&times;</span>
            </button>
        </div>

        <div class="px-6 py-5 overflow-y-auto flex-1" style="min-height: 0;">
            <div id="detalle-precios-body" class="text-sm text-slate-700">Cargando...</div>
        </div>

        <div class="border-t border-slate-200 px-6 py-4 flex flex-col gap-3 sm:flex-row sm:justify-end">
            <button type="button" id="detalle-precios-close-btn"
                class="rounded-md border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-100"
                style="border-color: #000000; color: #000000;">Cerrar</button>
        </div>
    </div>
</div>

<script>
    (function(){
        const initialItems = {!! $detallePaquetePayload ?? '[]' !!};
        const isReadOnly = {{ ($readOnly ?? false) ? 'true' : 'false' }};
        const listContainer = document.getElementById('detalle-paquete-list');
        const emptyState = document.getElementById('detalle-paquete-empty');
        const addBtn = document.getElementById('btn-add-detalle');
        const template = document.getElementById('detalle-paquete-row-template');
        const paqueteInline = document.querySelector('[data-paquete-inline]');
        const paqueteReadonlyOverlay = paqueteInline ? paqueteInline.querySelector('.devices-readonly-overlay') : null;

        const rowTomSelects = new WeakMap();
        const selectToTom = new Map();

        const TOMSELECT_OPTIONS = {
            allowEmptyOption: true,
            maxItems: 1,
            placeholder: 'Selecciona un almacén',
            hidePlaceholder: true,
            plugins: { dropdown_input: {} },
            create: false,
            closeAfterSelect: true
        };

        // helper removed: modal buttons will manage selection directly

        function syncEmpty() {
            if (emptyState) {
                emptyState.style.display = listContainer.children.length > 0 ? 'none' : 'block';
            }
        }

        // (se manejan duplicados actualizando y deshabilitando opciones compartidas)

        function updateDisabledOptions() {
            const selects = Array.from(listContainer.querySelectorAll('select.detalle-almacen'));
            const selected = selects.map(s => s.value).filter(v => v && v !== '');
            selects.forEach(s => {
                Array.from(s.options).forEach(opt => {
                    if (!opt.value) return;
                    opt.disabled = selected.includes(opt.value) && opt.value !== s.value;
                });
                const ts = selectToTom.get(s);
                if (!ts) return;

                // Actualizar estado interno de TomSelect (version-agnostic)
                try {
                    Object.values(ts.options || {}).forEach(opt => {
                        if (!opt || !opt.value) return;
                        const isDis = (opt.value !== s.value && selected.includes(opt.value));
                        opt.disabled = isDis;
                        const optEl = ts.getOption && ts.getOption(opt.value);
                        if (optEl) {
                            if (isDis) optEl.classList.add('disabled'); else optEl.classList.remove('disabled');
                        }
                    });
                } catch (e) { /**/ }

                // Limpiar cache de render si existe para forzar redibujo
                try { if (ts.renderCache) ts.renderCache = {}; } catch (e) {}
            });
        }

        function fetchPriceForAlmacen(almacenId, precioInput) {
            if (!almacenId) return;
            // Obtener exclusivamente el precio registrado en el recurso almacén
            fetch('/api/almacen/' + almacenId)
                .then(r => r.json())
                .then(data => {
                    if (data && (data.precio || data.precio === 0)) {
                        precioInput.value = data.precio;
                    } else {
                        // Si no hay precio explícito en el almacén, dejar 0.00
                        precioInput.value = '0.00';
                    }
                })
                .catch(() => {
                    console.warn('No se pudo obtener el precio del almacén.');
                    precioInput.value = '0.00';
                });
        }

        function initTomSelect(select) {
            if (!select || typeof window.TomSelect !== 'function') return null;
            if (select.disabled) return null;

            try {
                const existing = select.tomselect || select.tomSelect || select._tomselect;
                if (existing && typeof existing.destroy === 'function') {
                    existing.destroy();
                }
            } catch (err) { /* ignore */ }

            try {
                return new TomSelect(select, TOMSELECT_OPTIONS);
            } catch (err) {
                console.warn('TomSelect init failed:', err);
                return null;
            }
        }

        function createRow(item = {}) {
            const fragment = template.content.cloneNode(true);
            const row = fragment.querySelector('[data-row]');
            const select = row.querySelector('select.detalle-almacen');
            const precioInput = row.querySelector('input.detalle-precio');
            const removeBtn = row.querySelector('button.btn-remove');
            const verBtn = row.querySelector('button.btn-ver-precios');

            const shouldBeReadOnly = isReadOnly && !window.crudFormEditUnlocked;
            function applyRowReadonlyState() {
                if (!row) return;
                if (select) {
                    if (shouldBeReadOnly) {
                        select.disabled = true;
                    } else {
                        select.removeAttribute('disabled');
                        select.classList.remove('bg-slate-50', 'cursor-not-allowed');
                    }
                }
                if (precioInput) {
                    if (shouldBeReadOnly) {
                        precioInput.disabled = true;
                    } else {
                        precioInput.removeAttribute('disabled');
                        precioInput.classList.remove('bg-slate-50', 'cursor-not-allowed');
                    }
                }
                if (removeBtn) {
                    removeBtn.disabled = shouldBeReadOnly;
                }
                if (verBtn) {
                    verBtn.disabled = shouldBeReadOnly;
                }
            }

            applyRowReadonlyState();
            if (!shouldBeReadOnly) {
                if (select) {
                    select.removeAttribute('disabled');
                    select.disabled = false;
                }
                if (precioInput) {
                    precioInput.removeAttribute('disabled');
                    precioInput.disabled = false;
                }
                if (removeBtn) {
                    removeBtn.removeAttribute('disabled');
                }
                if (verBtn) {
                    verBtn.removeAttribute('disabled');
                }
            }

            const almacenVal = String(item.almacen_idalmacen ?? '');
            function openPreciosModal(almacenId) {
                // args: almacenId, precioInput (optional)
                const args = arguments;
                const precioInput = args.length > 1 ? args[1] : null;

                modalBody.innerHTML = 'Cargando...';
                modalTitle.textContent = 'Listado de precios - Almacén ' + almacenId;
                modal.classList.remove('hidden');
                modal.classList.add('flex');

                fetch('/api/almacen/' + almacenId + '/precios')
                    .then(r => r.json())
                    .then(data => {
                        if (!data || (Array.isArray(data) && data.length === 0)) {
                            modalBody.innerHTML = '<div class="text-slate-500">No hay precios disponibles para este almacén.</div>';
                            return;
                        }

                        // Si se pasa un precioInput, mostrar botones de Seleccionar que copian el precio y cierran el modal
                        if (precioInput) {
                            let html = '<div class="overflow-x-auto"><table class="w-full text-left text-sm text-slate-700">';
                            html += '<thead class="bg-slate-50 text-slate-500"><tr>';
                            html += '<th class="px-3 py-3 font-semibold">Lista de Precio</th>';
                            html += '<th class="px-3 py-3 font-semibold text-right">Precio</th>';
                            html += '<th class="px-3 py-3 font-semibold text-center">Acción</th>';
                            html += '</tr></thead><tbody>';

                            data.forEach(function(item, idx) {
                                const precioStr = (item.precio || '0.00');
                                const isSelected = String(precioInput.value).trim() === String(precioStr).trim();
                                html += '<tr class="border-t border-slate-200 hover:bg-slate-50">';
                                html += '<td class="px-3 py-3">' + (item.lista || 'Sin lista') + '</td>';
                                html += '<td class="px-3 py-3 text-right font-medium">' + precioStr + '</td>';
                                html += '<td class="px-3 py-3 text-center">';
                                html += '<button data-idx="' + idx + '" class="modal-select-price inline-flex items-center rounded-md border px-3 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100' + (isSelected ? ' selected' : '') + '">' + (isSelected ? 'Seleccionado' : 'Seleccionar') + '</button>';
                                html += '</td>';
                                html += '</tr>';
                            });

                            html += '</tbody></table></div>';
                            modalBody.innerHTML = html;

                            // attach handlers
                            const buttons = modalBody.querySelectorAll('button.modal-select-price');
                            buttons.forEach(function(btn) {
                                btn.addEventListener('click', function() {
                                    const idx = parseInt(this.dataset.idx, 10);
                                    const item = data[idx];
                                    if (!item) return;
                                    // marcar deseleccion en los otros botones
                                    buttons.forEach(function(b) { b.classList.remove('selected'); b.textContent = 'Seleccionar'; });
                                    // marcar este como seleccionado
                                    this.classList.add('selected');
                                    this.textContent = 'Seleccionado';
                                    // copiar precio al input y cerrar modal
                                    precioInput.value = item.precio;
                                    closeModal();
                                });
                            });
                            return;
                        }

                        // Modo solo visual: mostrar lista simple
                        let html = '<div class="overflow-x-auto"><table class="w-full text-left text-sm text-slate-700">';
                        html += '<thead class="bg-slate-50 text-slate-500"><tr>';
                        html += '<th class="px-3 py-3 font-semibold">Lista de Precio</th>';
                        html += '<th class="px-3 py-3 font-semibold text-right">Precio</th>';
                        html += '</tr></thead><tbody>';
                        data.forEach(function(item) {
                            html += '<tr class="border-t border-slate-200 hover:bg-slate-50">';
                            html += '<td class="px-3 py-3">' + (item.lista || 'Sin lista') + '</td>';
                            html += '<td class="px-3 py-3 text-right font-medium">' + (item.precio || '0.00') + '</td>';
                            html += '</tr>';
                        });
                        html += '</tbody></table></div>';
                        modalBody.innerHTML = html;
                    })
                    .catch(() => {
                        modalBody.innerHTML = '<div class="text-slate-500">No hay precios disponibles para este almacén.</div>';
                    });
                }
                // Conectar botón Ver precios: abre modal y le pasamos el input precio para permitir selección
            if (verBtn) {
                verBtn.addEventListener('click', function() {
                    const almacenId = select.value || (selectToTom.get(select) && selectToTom.get(select).getValue && selectToTom.get(select).getValue());
                    if (!almacenId) return;
                    openPreciosModal(almacenId, precioInput);
                });
            }

            // Insertar fila en el DOM
            try {
                // establecer valor inicial si viene en item
                if (almacenVal) {
                    try { select.value = almacenVal; } catch (e) {}
                }

                // Si el item trae precio, rellenarlo
                try {
                    if (item && (item.precio !== undefined && item.precio !== null)) {
                        precioInput.value = String(item.precio);
                    }
                } catch (e) { /**/ }


                // Insertar la fila en el DOM antes de inicializar y aplicar valor
                listContainer.appendChild(row);
                syncEmpty();

                // Inicializar TomSelect y guardar referencia
                const tsInstance = initTomSelect(select);
                if (tsInstance) selectToTom.set(select, tsInstance);

                // Cuando cambie el select, actualizar opciones deshabilitadas y traer precio
                select.addEventListener('change', function() {
                    try {
                        updateDisabledOptions();
                        // limpiar precio manual al cambiar de almacén
                        try { precioInput.value = ''; } catch (e) {}
                        if (this.value) fetchPriceForAlmacen(this.value, precioInput);
                    } catch (e) { console.warn('detalle-paquete select change error', e); }
                });

                // Eliminar fila
                if (removeBtn) {
                    removeBtn.addEventListener('click', function() {
                        try {
                            const ts = selectToTom.get(select);
                            if (ts && typeof ts.destroy === 'function') ts.destroy();
                        } catch (e) {}
                        try { row.remove(); } catch (e) { listContainer.removeChild(row); }
                        updateDisabledOptions();
                        syncEmpty();
                    });
                    // Asegurar que después de insertar e inicializar la fila
                    // se actualicen las opciones deshabilitadas en todos los selects
                    try { updateDisabledOptions(); } catch (e) { console.warn('updateDisabledOptions after init failed', e); }
                }
            } catch (e) {
                console.error('[detalle-paquete] append/init row error', e);
            }
        }

        function updateReadonlyState() {
            const shouldBeReadOnly = isReadOnly && !window.crudFormEditUnlocked;
            if (paqueteReadonlyOverlay) {
                paqueteReadonlyOverlay.style.display = shouldBeReadOnly ? 'block' : 'none';
            }
            if (addBtn) {
                addBtn.style.display = shouldBeReadOnly ? 'none' : '';
                addBtn.disabled = shouldBeReadOnly;
            }
            listContainer.querySelectorAll('select.detalle-almacen, input.detalle-precio, button.btn-remove, button.btn-ver-precios').forEach(el => {
                if (el.tagName === 'SELECT' || el.tagName === 'INPUT' || el.tagName === 'BUTTON') {
                    el.disabled = shouldBeReadOnly;
                }
            });
        }

        if (addBtn) {
            try { addBtn.style.display = !isReadOnly ? '' : (isReadOnly && !window.crudFormEditUnlocked ? 'none' : ''); addBtn.disabled = isReadOnly && !window.crudFormEditUnlocked; } catch (e) {}
            addBtn.addEventListener('click', function(event) {
                event.preventDefault();
                const shouldBeReadOnly = isReadOnly && !window.crudFormEditUnlocked;
                if (shouldBeReadOnly) {
                    return;
                }
                createRow({});
            });
        }

        window.addEventListener('crudFormEditUnlockedChange', updateReadonlyState);

        // Init with existing items (or create one empty row on create)
        try {
            if (Array.isArray(initialItems) && initialItems.length) {
                initialItems.forEach(it => createRow(it));
            } else {
                createRow({});
            }
        } catch (err) {
            console.error('[detalle-paquete] createRow init error', err);
        } finally {
            syncEmpty();
            try { updateDisabledOptions(); } catch (e) { /* noop */ }
            updateReadonlyState();
        }

        // Modal logic
        const modal = document.getElementById('detalle-precios-modal');
        if (modal && document.body) {
            document.body.appendChild(modal);
        }
        const modalBody = document.getElementById('detalle-precios-body');
        const modalTitle = document.getElementById('detalle-precios-title');
        const modalClose = document.getElementById('detalle-precios-close');
        const modalCloseBtn = document.getElementById('detalle-precios-close-btn');

        

        function closeModal() {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        if(modalClose) modalClose.addEventListener('click', closeModal);
        if(modalCloseBtn) modalCloseBtn.addEventListener('click', closeModal);

        // Antes de enviar el formulario, serializar filas visibles a JSON
        (function installSerializeBeforeSubmit(){
            const form = listContainer.closest('form');
            if (!form) return;

            form.addEventListener('submit', function (ev) {
                try {
                    const rows = Array.from(listContainer.querySelectorAll('[data-row]'));
                    const payload = [];
                    rows.forEach(row => {
                        const select = row.querySelector('select.detalle-almacen');
                        const precioInput = row.querySelector('input.detalle-precio');

                        let almacenVal = '';
                        if (select) {
                            almacenVal = select.value || (selectToTom.get(select) && typeof selectToTom.get(select).getValue === 'function' ? selectToTom.get(select).getValue() : '');
                        }

                        const precioVal = precioInput ? String(precioInput.value).trim() : '';

                        // Omitir filas totalmente vacías
                        if (!almacenVal && precioVal === '') return;

                        payload.push({
                            almacen_idalmacen: almacenVal ? parseInt(almacenVal, 10) : null,
                            precio: precioVal !== '' ? precioVal : null,
                        });
                    });

                    // Crear/actualizar hidden input con el payload JSON
                    let hidden = form.querySelector('input[name="detalle_paquete_payload"]');
                    if (!hidden) {
                        hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'detalle_paquete_payload';
                        form.appendChild(hidden);
                    }
                    hidden.value = JSON.stringify(payload);

                    // Evitar que los inputs individuales `detalle[]` dupliquen datos en el POST
                    Array.from(form.querySelectorAll('select[name^="detalle["] , input[name^="detalle["]')).forEach(el => el.removeAttribute('name'));
                } catch (err) {
                    console.warn('serialize detalle_paquete_payload failed', err);
                }
            }, { passive: true });
        })();

    })();
</script>
