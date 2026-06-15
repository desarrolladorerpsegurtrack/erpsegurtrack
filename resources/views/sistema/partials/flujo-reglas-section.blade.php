@php
    $sectionMode = $sectionMode ?? 'create';
    $vistaOptions = $vistaOptions ?? [];
    $rules = $rules ?? [];

    if ($sectionMode === 'create') {
        $normalizedRules = old('reglas', is_array($rules) ? $rules : []);
        if (!is_array($normalizedRules) || empty($normalizedRules)) {
            $normalizedRules = [[
                'vista_idvista' => '',
                'orden' => 1,
                'estado' => '1',
                'condicion' => '',
            ]];
        }
    } else {
        $normalizedRules = is_iterable($rules) ? $rules : [];
    }
@endphp

<div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" data-flujo-reglas-inline>
    <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 md:flex-row md:items-center md:justify-between">
        <div class="w-full">
            <h2 class="text-lg font-semibold text-black">Reglas del flujo</h2>
            <p class="mt-1 text-sm text-slate-600">
                Agrega una o varias reglas sin salir del formulario. Solo se guardan reglas activas
            </p>
        </div>
        <button type="button" data-add-flujo-regla class="inline-flex shrink-0 items-center gap-2 rounded-md border px-3 py-2 text-sm focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none" style="background-color: #B91c1c; color: #fff;">
            <span class="text-base leading-none">+</span>
            Agregar
        </button>
    </div>

    @if($sectionMode === 'create')
        @php
            $vistaOptionsForSelect = collect($vistaOptions)->values();
            $vistaOptionItems = $vistaOptionsForSelect->map(function ($vistaOption) {
                $vistaOptionValue = data_get($vistaOption, 'idvista');
                $rawLabel = data_get($vistaOption, 'label');
                $vistaOptionLabel = str_contains($rawLabel, '-') ? trim(explode('-', $rawLabel, 2)[1]) : $rawLabel;

                return [
                    'value' => (string) $vistaOptionValue,
                    'text' => (string) $vistaOptionLabel,
                ];
            })->values();
        @endphp

        <div class="mt-2">
            <style>
                @media (max-width: 767px) {
                    .flujo-scroll-wrapper { overflow-x: auto; -webkit-overflow-scrolling: touch; }
                    .flujo-scroll-inner { min-width: 720px; }
                }
            </style>
            <div class="flujo-scroll-wrapper">
                <div class="border border-slate-200 bg-white shadow-sm flujo-scroll-inner">
                <div class="border-b border-slate-200 bg-slate-50" style="padding: 12px 20px;">
                    <div style="display:grid;grid-template-columns:48px minmax(0,1fr) 120px 120px 44px;gap:16px;align-items:center;" class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500">
                        <div style="text-align:center;">Mover</div>
                        <div style="text-align:left;">Vista</div>
                        <div style="text-align:center;">Estado</div>
                        <div style="text-align:center;">Orden</div>
                        <div style="text-align:center;">Quitar</div>
                    </div>
                </div>

                <div class="p-3">
                    <div data-flujo-reglas-list class="flex flex-col gap-2"></div>
                    <div data-flujo-reglas-empty class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-500">
                        No hay reglas agregadas todavía. Pulsa “Agregar regla flujo”.
                    </div>
                </div>
            </div>
        </div>

        <template data-flujo-regla-template>
            <div data-flujo-regla-row style="display:grid;grid-template-columns:48px minmax(0,1fr) 120px 120px 44px;gap:16px;align-items:center;padding:8px 20px;margin:4px 0;border:1px solid #e2e8f0;border-radius:5px;background:#fff;box-shadow:0 1px 2px rgba(15,23,42,.04);">
                <div style="display:flex;align-items:center;justify-content:center;">
                    <button type="button" draggable="true" data-drag-handle class="inline-flex cursor-grab items-center justify-center rounded-md border border-slate-200 bg-slate-50 p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700" title="Reordenar">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M7 4a1 1 0 110-2 1 1 0 010 2zm0 6a1 1 0 110-2 1 1 0 010 2zm0 6a1 1 0 110-2 1 1 0 010 2zM13 2a1 1 0 100 2 1 1 0 000-2zm0 6a1 1 0 100 2 1 1 0 000-2zm0 6a1 1 0 100 2 1 1 0 000-2z" /></svg>
                    </button>
                </div>
                <div style="min-width:0;">
                    <select class="tom-select tom-select--compact w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-primary focus:ring-1 focus:ring-primary" data-vista-select style="width:100%;">
                        <option value="">Selecciona una vista</option>
                        @foreach($vistaOptionsForSelect as $vistaOption)
                            @php
                                $vistaOptionValue = data_get($vistaOption, 'idvista');
                                $rawLabel = data_get($vistaOption, 'label');
                                $vistaOptionLabel = str_contains($rawLabel, '-') ? trim(explode('-', $rawLabel, 2)[1]) : $rawLabel;
                            @endphp
                            <option value="{{ $vistaOptionValue }}">{{ $vistaOptionLabel }}</option>
                        @endforeach
                    </select>
                    <input type="hidden" data-input-vista value="">
                </div>
                <div class="pl-8" style="display:flex;align-items:center;justify-content:center;">
                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold" data-estado-label style="background:#dcfce7;color:#166534;">Activo</span>
                    <input type="hidden" data-input-estado value="1">
                </div>
                <div class="pl-8" style="display:flex;align-items:center;justify-content:center;gap:8px;font-size:1rem;font-weight:600;color:#334155;">
                    <span data-order-display>1</span>
                    <input type="hidden" data-input-orden value="1">
                    <input type="hidden" data-input-condicion value="ver vista 1">
                </div>
                <div class="pl-8" style="display:flex;align-items:center;justify-content:center;">
                    <button type="button" data-remove-flujo-regla title="Quitar regla" aria-label="Quitar regla" style="color: #B91c1c">
                        <span class="text-lg leading-none">X</span>
                    </button>
                </div>
            </div>
        </template>

        <script>
            (function() {
                const container = document.querySelector('[data-flujo-reglas-inline]');
                if (!container) return;

                const addButton = container.querySelector('[data-add-flujo-regla]');
                const rulesList = container.querySelector('[data-flujo-reglas-list]');
                const emptyState = container.querySelector('[data-flujo-reglas-empty]');
                const template = container.querySelector('template[data-flujo-regla-template]');
                const form = container.closest('form');
                const initialRules = @json($normalizedRules);
                const vistaOptionItems = @json($vistaOptionItems);

                let draggedRow = null;

                const initTomSelect = (select) => {
                    if (!select || typeof window.TomSelect !== 'function') return;
                    if (select.tomselect || select.tomSelect || select._tomselect) return;

                    new TomSelect(select, {
                        allowEmptyOption: true,
                        maxItems: 1,
                        placeholder: 'Selecciona una vista',
                    });
                };

                const syncEmptyState = () => {
                    if (!emptyState) return;
                    emptyState.classList.toggle('hidden', rulesList.children.length > 0);
                };

                const getSelectedVistaValues = (excludeRow = null) => {
                    return new Set(
                        Array.from(rulesList.querySelectorAll('[data-flujo-regla-row]'))
                            .filter((row) => row !== excludeRow)
                            .map((row) => String(row.querySelector('[data-vista-select]')?.value || '').trim())
                            .filter((value) => value !== '')
                    );
                };

                const refreshVistaSelect = (select, currentValue, occupiedValues) => {
                    if (!select) return;

                    const tomSelect = select.tomselect || select.tomSelect || select._tomselect;
                    const normalizedCurrentValue = String(currentValue || '');
                    const optionMarkup = [
                        '<option value="">Selecciona una vista</option>',
                        ...vistaOptionItems.map((option) => {
                            const isCurrent = option.value === normalizedCurrentValue;
                            const isBlocked = occupiedValues.has(option.value) && !isCurrent;
                            return `<option value="${option.value}" ${isBlocked ? 'disabled' : ''}>${option.text}</option>`;
                        }),
                    ].join('');

                    if (tomSelect && typeof tomSelect.clearOptions === 'function' && typeof tomSelect.addOption === 'function') {
                        tomSelect.clearOptions();
                        tomSelect.addOption({ value: '', text: 'Selecciona una vista' });
                        vistaOptionItems.forEach((option) => {
                            const isCurrent = option.value === normalizedCurrentValue;
                            const isBlocked = occupiedValues.has(option.value) && !isCurrent;
                            tomSelect.addOption({
                                value: option.value,
                                text: option.text,
                                disabled: isBlocked,
                            });
                        });
                        if (typeof tomSelect.refreshOptions === 'function') {
                            tomSelect.refreshOptions(false);
                        }
                        if (normalizedCurrentValue) {
                            tomSelect.setValue(normalizedCurrentValue, true);
                        } else {
                            tomSelect.clear(true);
                        }
                    } else {
                        select.innerHTML = optionMarkup;
                        select.value = normalizedCurrentValue;
                    }
                };

                const updateRows = () => {
                    const occupiedValues = getSelectedVistaValues();

                    Array.from(rulesList.querySelectorAll('[data-flujo-regla-row]')).forEach((row, index) => {
                        const order = index + 1;
                        const select = row.querySelector('[data-vista-select]');
                        const vistaInput = row.querySelector('[data-input-vista]');
                        const estadoInput = row.querySelector('[data-input-estado]');
                        const ordenInput = row.querySelector('[data-input-orden]');
                        const condicionInput = row.querySelector('[data-input-condicion]');
                        const orderDisplay = row.querySelector('[data-order-display]');
                        const estadoLabel = row.querySelector('[data-estado-label]');
                        const currentValue = String(select?.value || '');

                        refreshVistaSelect(select, currentValue, occupiedValues);

                        if (vistaInput) {
                            vistaInput.name = `reglas[${index}][vista_idvista]`;
                            vistaInput.value = currentValue;
                        }
                        if (estadoInput) {
                            estadoInput.name = `reglas[${index}][estado]`;
                            estadoInput.value = '1';
                        }
                        if (ordenInput) {
                            ordenInput.name = `reglas[${index}][orden]`;
                            ordenInput.value = String(order);
                        }
                        if (condicionInput) {
                            condicionInput.name = `reglas[${index}][condicion]`;
                            condicionInput.value = `ver vista ${order}`;
                        }
                        if (orderDisplay) {
                            orderDisplay.textContent = String(order);
                        }
                        if (estadoLabel) {
                            estadoLabel.textContent = 'Activo';
                            estadoLabel.setAttribute('style', 'background:#dcfce7;color:#166534;');
                        }
                    });

                    syncEmptyState();
                };

                const wireRow = (row) => {
                    const select = row.querySelector('[data-vista-select]');
                    const handle = row.querySelector('[data-drag-handle]');
                    const removeButton = row.querySelector('[data-remove-flujo-regla]');

                    initTomSelect(select);

                    if (select) {
                        select.addEventListener('change', updateRows);
                    }

                    if (handle) {
                        handle.addEventListener('dragstart', (event) => {
                            draggedRow = row;
                            row.style.opacity = '0.85';
                            row.style.transform = 'scale(1.01)';
                            row.style.boxShadow = '0 18px 38px rgba(15, 23, 42, 0.18)';
                            row.style.cursor = 'grabbing';
                            if (event.dataTransfer && typeof event.dataTransfer.setDragImage === 'function') {
                                event.dataTransfer.setDragImage(row, Math.round(row.offsetWidth / 2), Math.round(row.offsetHeight / 2));
                            }
                            event.dataTransfer.effectAllowed = 'move';
                        });

                        handle.addEventListener('dragend', () => {
                            draggedRow = null;
                            row.style.opacity = '';
                            row.style.transform = '';
                            row.style.boxShadow = '';
                            row.style.cursor = '';
                            updateRows();
                        });
                    }

                    if (removeButton) {
                        removeButton.addEventListener('click', () => {
                            const parent = row.parentElement;
                            row.remove();

                            if (parent && parent.children.length === 0 && emptyState) {
                                emptyState.classList.remove('hidden');
                            }

                            updateRows();
                        });
                    }

                    row.addEventListener('dragover', (event) => {
                        if (!draggedRow || draggedRow === row) return;
                        event.preventDefault();

                        const rect = row.getBoundingClientRect();
                        const insertAfter = (event.clientY - rect.top) > rect.height / 2;
                        const parent = row.parentElement;

                        if (parent) {
                            parent.insertBefore(draggedRow, insertAfter ? row.nextSibling : row);
                        }
                    });

                    row.addEventListener('drop', (event) => {
                        event.preventDefault();
                        updateRows();
                    });
                };

                const createRow = (rule = {}) => {
                    if (!template || !rulesList) return;

                    const fragment = template.content.cloneNode(true);
                    const row = fragment.querySelector('[data-flujo-regla-row]');
                    if (!row) return;

                    const select = row.querySelector('[data-vista-select]');
                    const hiddenVista = row.querySelector('[data-input-vista]');
                    const hiddenOrden = row.querySelector('[data-input-orden]');
                    const hiddenCondicion = row.querySelector('[data-input-condicion]');
                    const orderDisplay = row.querySelector('[data-order-display]');
                    const selectValue = String(rule.vista_idvista ?? '');

                    if (select) {
                        select.value = selectValue;
                    }
                    if (hiddenVista) {
                        hiddenVista.value = selectValue;
                    }
                    if (hiddenOrden) {
                        hiddenOrden.value = String(rulesList.children.length + 1);
                    }
                    if (hiddenCondicion) {
                        hiddenCondicion.value = `ver vista ${rulesList.children.length + 1}`;
                    }
                    if (orderDisplay) {
                        orderDisplay.textContent = String(rulesList.children.length + 1);
                    }

                    wireRow(row);
                    rulesList.appendChild(fragment);
                    updateRows();
                };

                if (addButton) {
                    addButton.addEventListener('click', () => {
                        createRow({});
                    });
                }

                initialRules
                    .filter((rule) => String(rule.vista_idvista ?? '').trim() !== '')
                    .forEach((rule) => createRow(rule));

                updateRows();

                form.addEventListener('submit', (event) => {
                    updateRows();
                    if (rulesList.children.length === 0) {
                        event.preventDefault();
                    }
                });
            })();
        </script>
    @else
        <div class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-700">
                    <thead class="bg-slate-100 text-slate-500">
                        <tr>
                            <th class="px-4 py-3 font-semibold">Vista</th>
                            <th class="px-4 py-3 font-semibold">Orden</th>
                            <th class="px-4 py-3 font-semibold">Estado</th>
                            <th class="px-4 py-3 font-semibold">Condición</th>
                        </tr>
                    </thead>
                    <tbody >
                        @forelse($normalizedRules as $rule)
                            @php
                                $estadoValue = (string) data_get($rule, 'estado', '1');
                                $estadoLabel = $estadoValue === '1' ? 'Activo' : 'Inactivo';
                                $estadoClass = $estadoValue === '1' ? ' text-white' : ' text-white';
                            @endphp
                            <tr class="border-t border-slate-200 bg-white">
                                <td class="px-4 py-3 font-medium text-slate-900">{{ data_get($rule, 'vista_label', 'Sin vista') }}</td>
                                <td class="px-4 py-3">{{ data_get($rule, 'orden', '-') }}</td>
                                <td class="px-4 py-3"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $estadoClass }}">{{ $estadoLabel }}</span></td>
                                <td class="px-4 py-3 text-slate-600">{{ data_get($rule, 'condicion') ?: '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-6 text-center text-sm text-slate-500">Este flujo aún no tiene reglas registradas.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
