@extends('dashboard.overview-1')

@section('title', $title ?? 'Dar de baja elementos')
@section('header', $title ?? 'Dar de baja elementos')

@section('breadcrumb')
    <nav aria-label="breadcrumb" class="flex hidden flex-1 xl:block">
        <ol class="flex items-center text-theme-1">
            <li><a href="{{ route('home') }}">Inicio</a></li>
            <li class="relative ml-5 pl-0.5 before:content-[''] before:w-[14px] before:h-[14px] before:bg-chevron-black before:transform before:rotate-[-90deg] before:bg-[length:100%] before:-ml-[1.125rem] before:absolute before:my-auto before:inset-y-0 text-slate-600 cursor-text">
                <a href="{{ $backRoute ?? route('modules.almacen.nota-salida.index') }}">Nota de salida</a>
            </li>
            <li class="relative ml-5 pl-0.5 before:content-[''] before:w-[14px] before:h-[14px] before:bg-chevron-black before:transform before:rotate-[-90deg] before:bg-[length:100%] before:-ml-[1.125rem] before:absolute before:my-auto before:inset-y-0 text-slate-600 cursor-text">
                <span>{{ $title ?? 'Dar de baja elementos' }}</span>
            </li>
        </ol>
    </nav>
@endsection

@section('content')
    @php
        $reportSummary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $reportRows = is_array($report['rows'] ?? null) ? $report['rows'] : [];
    @endphp

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
    </style>

    <div class="grid w-full grid-cols-12 gap-x-6 gap-y-10">
        <div class="col-span-12">
            <div class="flex flex-col gap-y-3 md:h-10 md:flex-row md:items-center">
                <div>
                    <div class="text-base font-medium group-[.mode--light]:text-white">{{ $title ?? 'Dar de baja elementos' }}</div>
                    <div class="mt-1 text-sm text-slate-500">Selecciona elementos activos, confirma la baja y descarga el informe final al terminar.</div>
                </div>
                <a href="{{ $backRoute ?? route('modules.almacen.nota-salida.index') }}" class="md:ml-auto">
                    <button type="button" class="shrink-0 transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed border-secondary text-slate-500 dark:border-darkmode-100/40 dark:text-slate-300 [&:hover:not(:disabled)]:bg-secondary/20 [&:hover:not(:disabled)]:dark:bg-darkmode-100/10" style="border-color:#000000;color:#000000;">
                        <span class="mr-2">←</span>
                        Volver
                    </button>
                </a>
            </div>

            @if(session('success'))
                <div class="mt-4 rounded-lg border px-4 py-3 text-base font-semibold relative" style="border-color:#16a34a;background-color:#dcfce7;color:#14532d;">
                    ✓ {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="mt-4 rounded-lg border px-4 py-3 text-base font-semibold relative" style="border-color:#a31616;background-color:#fcdcdc;color:#531414;">
                    ✕ {{ session('error') }}
                </div>
            @endif

            @if($errors->any())
                <div class="mt-4 rounded-lg border border-red-700 bg-red-600 px-4 py-3 text-sm font-semibold text-white">
                    <ul class="list-disc pl-5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if(!empty($reportRows))
                <div class="mt-4 box box--stacked flex flex-col p-5">
                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                        <div>
                            <div class="text-lg font-semibold text-slate-800">Informe final de ejecución</div>
                            <div class="mt-1 text-sm text-slate-500">Generado el {{ $report['generatedAt'] ?? '-' }}</div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ $reportExportPdfRoute ?? '#' }}" class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50">
                                Descargar PDF
                            </a>
                            <a href="{{ $reportExportRoute ?? '#' }}" class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50">
                                Descargar XLSX
                            </a>
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-4">
                        <div class="rounded-[0.6rem] border border-dashed border-slate-300/80 bg-white p-4 shadow-sm">
                            <div class="text-sm text-slate-500">Seleccionados</div>
                            <div class="mt-1 text-2xl font-semibold text-slate-800">{{ (int) ($reportSummary['seleccionados'] ?? 0) }}</div>
                        </div>
                        <div class="rounded-[0.6rem] border border-dashed border-slate-300/80 bg-white p-4 shadow-sm">
                            <div class="text-sm text-slate-500">Dados de baja</div>
                            <div class="mt-1 text-2xl font-semibold text-emerald-700">{{ (int) ($reportSummary['bajados'] ?? 0) }}</div>
                        </div>
                        <div class="rounded-[0.6rem] border border-dashed border-slate-300/80 bg-white p-4 shadow-sm">
                            <div class="text-sm text-slate-500">Omitidos</div>
                            <div class="mt-1 text-2xl font-semibold text-amber-700">{{ (int) ($reportSummary['omitidos'] ?? 0) }}</div>
                        </div>
                        <div class="rounded-[0.6rem] border border-dashed border-slate-300/80 bg-white p-4 shadow-sm">
                            <div class="text-sm text-slate-500">Errores</div>
                            <div class="mt-1 text-2xl font-semibold text-red-700">{{ (int) ($reportSummary['errores'] ?? 0) }}</div>
                        </div>
                    </div>
                </div>
            @endif
            <form id="nota-salida-operacion-form" method="POST" action="{{ $formAction }}" class="mt-8">
                @csrf
                <div id="selected-imeis-hidden-inputs"></div>

                <div class="box box--stacked flex flex-col p-5">
                    <div class="flex flex-col gap-10 md:flex-row md:items-center md:justify-start w-full">
                    <div class="shrink-0 md:mr-4">
                        <div class="text-lg font-semibold text-slate-800">Elementos activos disponibles</div>
                        <div class="mt-1 text-sm text-slate-500">Solo se muestran elementos activos.</div>
                    </div>
                    
                    <div class="inline-flex flex-wrap items-center gap-3">
                        <div class="w-[260px] shrink-0">
                            <div class="relative">
                                <input id="nota-salida-imei-input" type="text" name="imei" value="" autocomplete="off" placeholder="Buscar IMEI" class="disabled:bg-slate-100 disabled:cursor-not-allowed dark:disabled:bg-darkmode-800/50 dark:disabled:border-transparent [&amp;[readonly]]:bg-slate-100 [&amp;[readonly]]:cursor-not-allowed [&amp;[readonly]]:dark:bg-darkmode-800/50 [&amp;[readonly]]:dark:border-transparent transition duration-200 ease-in-out w-full pr-10 text-sm border-slate-200 shadow-sm placeholder:text-slate-400/90 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 dark:placeholder:text-slate-500/80 rounded-[0.5rem] pl-9">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                                    <lucide-search class="w-4 h-4"></lucide-search>
                                </span>
                            </div>
                        </div>
                        
                        <label class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm whitespace-nowrap shrink-0">
                            <input type="checkbox" id="nota-salida-select-visible" class="h-4 w-4 rounded border-slate-300 text-danger focus:ring-danger/30">
                            Seleccionar visibles
                        </label>
                        
                        <button type="button" id="nota-salida-clear-selection" class="inline-flex items-center justify-center rounded-lg border border-danger bg-white px-4 py-2 text-sm font-semibold text-danger shadow-sm transition hover:bg-red-50 whitespace-nowrap shrink-0">Limpiar selección</button>
                        
                    </div>
                </div>

                    <div id="nota-salida-selection-error" class="mt-4 hidden rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"></div>

                    <div class="mt-4 overflow-hidden rounded-[0.85rem] border border-slate-200">
                        <div id="nota-salida-table-scroll" class="max-h-[22rem] overflow-auto" style="max-height:21rem;">
                            <table class="w-full text-left text-sm">
                                <thead class="sticky top-0 z-10 bg-slate-100 text-slate-600">
                                    <tr>
                                        <th class="px-3 py-3 w-12"></th>
                                        @foreach($columns as $column)
                                            <th class="px-3 py-3">{{ $column['label'] ?? '' }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($items as $item)
                                        <tr class="border-t border-slate-100 bg-white hover:bg-slate-50" data-ns-row data-nota-salida-row data-imei-value="{{ strtolower($item->imei ?? '') }}" data-device-id="{{ $item->dispositivo_iddispositivo ?? '' }}">
                                            <td class="px-3 py-3 align-top">
                                                <input type="checkbox" name="selectedImeis[]" value="{{ $item->imei }}" data-nota-salida-imei class="h-4 w-4 rounded border-slate-300 text-danger focus:ring-danger/30">
                                            </td>
                                            <td class="px-3 py-3 font-medium text-slate-800">{{ $item->imei }}</td>
                                            <td class="px-3 py-3 text-slate-700">{{ $item->almacen_detalle ?? '-' }}</td>
                                            <td class="px-3 py-3 text-slate-700">{{ $item->fecha_ingreso_label ?? '-' }}</td>
                                                <td class="px-3 py-3">
                                                    <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-danger">
                                                        <i data-tw-merge="" data-lucide="database" class="mr-1 h-3.5 w-3.5 stroke-[1.7] text-danger"></i>
                                                        Activo
                                                    </span>
                                                </td>
                                            <td class="px-3 py-3 text-slate-700">{{ $item->idAuxiliar ?? '-' }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="px-3 py-8 text-center text-slate-500">No hay elementos activos para dar de baja.</td>
                                        </tr>
                                    @endforelse
                                    <tr id="nota-salida-no-results-row" class="hidden">
                                        <td colspan="6" class="px-3 py-6 text-center text-slate-500">No hay resultados que coincidan con la búsqueda.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div class="text-sm text-slate-600">
                            <span class="font-semibold text-slate-800" id="nota-salida-selected-count">0</span> elemento(s) seleccionado(s).
                            <span class="ml-2 text-slate-500" id="nota-salida-selected-summary">Ninguno</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <a href="{{ $backRoute ?? route('modules.almacen.nota-salida.index') }}" class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">Cancelar</a>
                            <button type="button" id="nota-salida-open-confirm" class="inline-flex items-center justify-center rounded-lg border border-danger px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90" style="background-color:#c71010;border-color:#c71010;">Dar de baja seleccionados</button>
                        </div>
                </div>
            </form>
        </div>
    </div>

    <div id="nota-salida-confirm-modal" class="fixed inset-0 hidden items-center justify-center px-4 py-6" style="z-index: 9999; background-color: rgba(0, 0, 0, 0.72);" role="dialog" aria-modal="true" aria-labelledby="nota-salida-confirm-title">
        <div class="w-full max-w-2xl overflow-hidden rounded-[1.25rem] bg-white shadow-[0_24px_80px_rgba(15,23,42,0.16)]">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-6 py-4">
                <div>
                    <h3 id="nota-salida-confirm-title" class="text-lg font-semibold text-slate-800">Confirmar baja final</h3>
                    <p class="mt-1 text-sm text-slate-500">Esta acción cambiará el estado de los elementos seleccionados a inactivo.</p>
                </div>
                <button type="button" data-close-nota-salida-confirm class="ml-auto rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700">×</button>
            </div>
            <div class="px-6 py-5">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div class="rounded-[0.75rem] border border-slate-200 bg-slate-50 p-4">
                        <div class="text-sm text-slate-500">Seleccionados</div>
                        <div class="mt-1 text-2xl font-semibold text-slate-800" id="nota-salida-confirm-count">0</div>
                    </div>
                    <div class="rounded-[0.75rem] border border-slate-200 bg-slate-50 p-4">
                        <div class="text-sm text-slate-500">Acción</div>
                        <div class="mt-1 text-base font-semibold text-emerald-700">Activo → Inactivo</div>
                    </div>
                    <div class="rounded-[0.75rem] border border-slate-200 bg-slate-50 p-4">
                        <div class="text-sm text-slate-500">Informe</div>
                        <div class="mt-1 text-base font-semibold text-slate-800">Disponible al finalizar</div>
                    </div>
                </div>

                <div class="mt-4 rounded-[0.85rem] border border-dashed border-slate-200 bg-white p-4 text-sm text-slate-600">
                    Revisa la selección y confirma solo si estás seguro. Después de ejecutar, podrás descargar el informe final desde esta misma pantalla.
                </div>

                <div class="mt-5 flex flex-wrap items-center justify-end gap-2">
                    <button type="button" data-close-nota-salida-confirm class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">Cancelar</button>
                    <button type="button" id="nota-salida-confirm-submit" class="rounded-lg border border-danger px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90" style="background-color:#c71010;border-color:#c71010;">Confirmar baja</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const storageKey = 'almacen_nota_salida_selected_imeis';
            const form = document.getElementById('nota-salida-operacion-form');
            const hiddenInputs = document.getElementById('selected-imeis-hidden-inputs');
            const selectionError = document.getElementById('nota-salida-selection-error');
            const selectedCount = document.getElementById('nota-salida-selected-count');
            const selectedSummary = document.getElementById('nota-salida-selected-summary');
            const confirmCount = document.getElementById('nota-salida-confirm-count');
            const confirmModal = document.getElementById('nota-salida-confirm-modal');
            const openConfirmButton = document.getElementById('nota-salida-open-confirm');
            const confirmSubmitButton = document.getElementById('nota-salida-confirm-submit');
            const clearSelectionButton = document.getElementById('nota-salida-clear-selection');
            const selectVisible = document.getElementById('nota-salida-select-visible');
            const imeiClearButton = document.querySelector('[data-nota-salida-clear-imei]');
            const checkboxes = Array.from(document.querySelectorAll('[data-nota-salida-imei]'));
            const tomSelectElement = document.getElementById('nota-salida-dispositivo-select');
            const searchInput = document.getElementById('nota-salida-imei-input');
            const tableScrollWrapper = document.getElementById('nota-salida-table-scroll');
            const dataRows = Array.from(document.querySelectorAll('[data-nota-salida-row]'));
            const noResultsRow = document.getElementById('nota-salida-no-results-row');
            let tomSelectInstance = null;

            const initTomSelect = () => {
                if (!tomSelectElement || typeof window.TomSelect !== 'function') {
                    return;
                }

                const existing = tomSelectElement.tomselect || tomSelectElement.tomSelect || tomSelectElement._tomselect;
                if (existing && typeof existing.destroy === 'function') {
                    existing.destroy();
                }

                tomSelectInstance = new TomSelect(tomSelectElement, {
                    width: '100%',
                    allowEmptyOption: true,
                    create: false,
                    maxOptions: 100,
                    placeholder: tomSelectElement.getAttribute('data-placeholder') || 'Selecciona un dispositivo de almacén',
                    dropdownParent: document.body,
                    closeAfterSelect: true,
                    hidePlaceholder: true,
                    openOnFocus: true,
                    plugins: { dropdown_input: {} },
                });
            };

            const readSelection = () => {
                try {
                    const parsed = JSON.parse(localStorage.getItem(storageKey) || '[]');
                    if (!Array.isArray(parsed)) {
                        return [];
                    }
                    return Array.from(new Set(parsed.map((value) => String(value || '').trim()).filter(Boolean)));
                } catch (error) {
                    return [];
                }
            };

            const saveSelection = (values) => {
                localStorage.setItem(storageKey, JSON.stringify(values));
            };

            const setError = (message) => {
                if (!selectionError) {
                    return;
                }

                if (!message) {
                    selectionError.classList.add('hidden');
                    selectionError.textContent = '';
                    return;
                }

                selectionError.textContent = message;
                selectionError.classList.remove('hidden');
            };

            const updateSelectionUI = () => {
                const selected = readSelection();
                const selectedSet = new Set(selected);

                checkboxes.forEach((checkbox) => {
                    checkbox.checked = selectedSet.has(checkbox.value);
                });

                if (selectedCount) {
                    selectedCount.textContent = String(selected.length);
                }

                if (selectedSummary) {
                    selectedSummary.textContent = selected.length > 0
                        ? selected.slice(0, 5).join(', ') + (selected.length > 5 ? '…' : '')
                        : 'Ninguno';
                }

                if (confirmCount) {
                    confirmCount.textContent = String(selected.length);
                }

                if (selectVisible instanceof HTMLInputElement) {
                    const visibleCheckboxes = checkboxes.filter((checkbox) => checkbox.offsetParent !== null);
                    selectVisible.checked = visibleCheckboxes.length > 0 && visibleCheckboxes.every((checkbox) => checkbox.checked);
                }
            };

            const getFilterValues = () => {
                const term = searchInput instanceof HTMLInputElement ? searchInput.value.trim().toLowerCase() : '';
                const deviceValue = tomSelectInstance?.getValue?.() || (tomSelectElement?.value || '');
                return { term, deviceValue };
            };

            const adjustTableHeight = () => {
                if (!tableScrollWrapper || dataRows.length === 0) {
                    return;
                }

                const visibleCount = Math.max(dataRows.filter((row) => row.style.display !== 'none').length, 1);
                const sampleRow = dataRows[0];
                const rowHeight = sampleRow.getBoundingClientRect().height || 52;
                const headerHeight = tableScrollWrapper.querySelector('thead')?.getBoundingClientRect().height || 48;
                const maxVisible = Math.min(visibleCount, 5);
                tableScrollWrapper.style.maxHeight = `${headerHeight + rowHeight * maxVisible + 16}px`;
            };

            const filterTableRows = () => {
                const { term, deviceValue } = getFilterValues();
                let visibleCount = 0;

                dataRows.forEach((row) => {
                    const imeiValue = String(row.dataset.imeiValue || '').toLowerCase();
                    const rowDevice = String(row.dataset.deviceId || '');
                    const matchesSearch = term === '' || imeiValue.includes(term);
                    const matchesDevice = deviceValue === '' || rowDevice === deviceValue;
                    const visible = matchesSearch && matchesDevice;

                    row.style.display = visible ? '' : 'none';
                    row.hidden = !visible;
                    if (visible) visibleCount += 1;
                });

                if (noResultsRow) {
                    noResultsRow.classList.toggle('hidden', visibleCount > 0);
                }

                updateSelectionUI();
                adjustTableHeight();
            };

            const syncHiddenInputs = () => {
                if (!hiddenInputs) {
                    return;
                }

                hiddenInputs.innerHTML = '';
                readSelection().forEach((imei) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'selectedImeis[]';
                    input.value = imei;
                    hiddenInputs.appendChild(input);
                });
            };

            checkboxes.forEach((checkbox) => {
                checkbox.addEventListener('change', () => {
                    const selected = readSelection();
                    const selectedSet = new Set(selected);

                    if (checkbox.checked) {
                        selectedSet.add(checkbox.value);
                    } else {
                        selectedSet.delete(checkbox.value);
                    }

                    saveSelection(Array.from(selectedSet));
                    setError('');
                    updateSelectionUI();
                });
            });

            if (selectVisible instanceof HTMLInputElement) {
                selectVisible.addEventListener('change', () => {
                    const selected = new Set(readSelection());
                    checkboxes.forEach((checkbox) => {
                        if (checkbox.offsetParent === null) {
                            return;
                        }

                        checkbox.checked = selectVisible.checked;
                        if (selectVisible.checked) {
                            selected.add(checkbox.value);
                        } else {
                            selected.delete(checkbox.value);
                        }
                    });

                    saveSelection(Array.from(selected));
                    setError('');
                    updateSelectionUI();
                });
            }

            if (clearSelectionButton) {
                clearSelectionButton.addEventListener('click', () => {
                    saveSelection([]);
                    setError('');
                    updateSelectionUI();
                });
            }

            if (imeiClearButton) {
                imeiClearButton.addEventListener('click', () => {
                    const imeiInput = document.querySelector('input[name="imei"]');
                    if (imeiInput instanceof HTMLInputElement) {
                        imeiInput.value = '';
                    }
                    imeiClearButton.style.display = 'none';
                });
            }

            const openConfirmModal = () => {
                const selected = readSelection();

                if (selected.length === 0) {
                    setError('Selecciona al menos un elemento antes de confirmar la baja.');
                    return;
                }

                setError('');
                if (confirmModal) {
                    confirmModal.classList.remove('hidden');
                    confirmModal.classList.add('flex');
                }
            };

            const closeConfirmModal = () => {
                if (confirmModal) {
                    confirmModal.classList.add('hidden');
                    confirmModal.classList.remove('flex');
                }
            };

            if (openConfirmButton) {
                openConfirmButton.addEventListener('click', openConfirmModal);
            }

            if (confirmSubmitButton && form) {
                confirmSubmitButton.addEventListener('click', () => {
                    syncHiddenInputs();
                    form.submit();
                });
            }

            document.querySelectorAll('[data-close-nota-salida-confirm]').forEach((button) => {
                button.addEventListener('click', closeConfirmModal);
            });

            if (form) {
                form.addEventListener('submit', (event) => {
                    event.preventDefault();
                    openConfirmModal();
                });
            }

            if (searchInput instanceof HTMLInputElement) {
                searchInput.addEventListener('input', filterTableRows);
            }

            const imeiInput = document.querySelector('input[name="imei"]');
            if (imeiInput instanceof HTMLInputElement && imeiClearButton instanceof HTMLElement) {
                const toggleClear = () => {
                    imeiClearButton.style.display = imeiInput.value.trim() !== '' ? 'flex' : 'none';
                };

                imeiInput.addEventListener('input', toggleClear);
                toggleClear();
            }

            initTomSelect();
            if (tomSelectElement instanceof HTMLSelectElement) {
                tomSelectElement.addEventListener('change', filterTableRows);
            }
            filterTableRows();
            updateSelectionUI();
        });
    </script>
@endsection