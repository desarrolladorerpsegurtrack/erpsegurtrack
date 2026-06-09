<!-- CONTROLADOR SCRIPT GLOBAL ACORDEÓN DE RELACIONES -->
<script>
    if (!window._relationPanelAccordionInitialized) {
        window._relationPanelAccordionInitialized = true;

        function getPanelContext(el) {
            const panel = el.closest('.relation-panel-accordion');
            if (!panel) return null;
            return {
                panel,
                lvl1Container: panel.querySelector('.level-1-container'),
                lvl2Container: panel.querySelector('.level-2-container'),
                lvl3Container: panel.querySelector('.level-3-container'),
                bc1: panel.querySelector('.bc-1'),
                bc2: panel.querySelector('.bc-2'),
                bc3: panel.querySelector('.bc-3'),
                sep1: panel.querySelector('.sep-1'),
                sep2: panel.querySelector('.sep-2'),
                lvl2TitleLabel: panel.querySelector('.lvl2-title-label'),
                lvl3TitleLabel: panel.querySelector('.lvl3-title-label'),
                hasServices: panel.getAttribute('data-has-services') === 'true',
                hasVehicles: panel.getAttribute('data-has-vehicles') === 'true',
                hasDevices: panel.getAttribute('data-has-devices') === 'true'
            };
        }

        function showLevel(level, ctx, contextData = {}) {
            if (ctx.lvl1Container) ctx.lvl1Container.classList.add('hidden');
            if (ctx.lvl2Container) ctx.lvl2Container.classList.add('hidden');
            if (ctx.lvl3Container) ctx.lvl3Container.classList.add('hidden');

            if (level === 1 && ctx.lvl1Container) {
                ctx.lvl1Container.classList.remove('hidden');
                if (ctx.bc1) {
                    ctx.bc1.classList.remove('text-primary', 'cursor-pointer', 'hover:underline');
                    ctx.bc1.classList.add('text-slate-700');
                }
                if (ctx.sep1) ctx.sep1.classList.add('hidden');
                if (ctx.bc2) ctx.bc2.classList.add('hidden');
                if (ctx.sep2) ctx.sep2.classList.add('hidden');
                if (ctx.bc3) ctx.bc3.classList.add('hidden');
            }
            else if (level === 2 && ctx.lvl2Container) {
                ctx.lvl2Container.classList.remove('hidden');
                const rows = ctx.lvl2Container.querySelectorAll('.vehicle-row-clickable');
                const noRecordsRow = ctx.lvl2Container.querySelector('.no-records-row');
                let visibleCount = 0;

                if (contextData.placa) {
                    rows.forEach(row => {
                        if (row.getAttribute('data-placa') === contextData.placa) {
                            row.classList.remove('hidden');
                            visibleCount++;
                        } else {
                            row.classList.add('hidden');
                        }
                    });
                    if (ctx.lvl2TitleLabel) ctx.lvl2TitleLabel.textContent = `Vehículos (Servicio: ${contextData.serviceId})`;
                } else {
                    rows.forEach(row => {
                        row.classList.remove('hidden');
                        visibleCount++;
                    });
                    if (ctx.lvl2TitleLabel) ctx.lvl2TitleLabel.textContent = "Vehículos del Cliente";
                }

                if (noRecordsRow) {
                    if (visibleCount === 0) noRecordsRow.classList.remove('hidden');
                    else noRecordsRow.classList.add('hidden');
                }

                ctx.panel.dataset.currentFilter = JSON.stringify(contextData);

                if (ctx.hasServices && ctx.bc1) {
                    ctx.bc1.classList.remove('text-slate-700');
                    ctx.bc1.classList.add('text-primary', 'cursor-pointer', 'hover:underline');
                    if (ctx.sep1) ctx.sep1.classList.remove('hidden');
                }

                if (ctx.bc2) {
                    ctx.bc2.classList.remove('hidden', 'text-primary', 'cursor-pointer', 'hover:underline');
                    ctx.bc2.classList.add('text-slate-700');
                    if (contextData.serviceId) {
                        ctx.bc2.textContent = `Vehículos (Servicio: ${contextData.serviceId})`;
                    } else {
                        ctx.bc2.textContent = "Vehículos";
                    }
                }
                if (ctx.sep2) ctx.sep2.classList.add('hidden');
                if (ctx.bc3) ctx.bc3.classList.add('hidden');
            }
            else if (level === 3 && ctx.lvl3Container) {
                ctx.lvl3Container.classList.remove('hidden');
                const rows = ctx.lvl3Container.querySelectorAll('.device-row');
                const noDevicesRow = ctx.lvl3Container.querySelector('.no-devices-row');
                let visibleDevices = 0;

                if (contextData.placa) {
                    rows.forEach(row => {
                        if (row.getAttribute('data-vehicle-placa') === contextData.placa) {
                            row.classList.remove('hidden');
                            visibleDevices++;
                        } else {
                            row.classList.add('hidden');
                        }
                    });
                    if (ctx.lvl3TitleLabel) ctx.lvl3TitleLabel.textContent = `Dispositivos del Vehículo: ${contextData.placa}`;
                } else {
                    rows.forEach(row => {
                        row.classList.remove('hidden');
                        visibleDevices++;
                    });
                    if (ctx.lvl3TitleLabel) ctx.lvl3TitleLabel.textContent = "Dispositivos del Vehículo";
                }

                if (noDevicesRow) {
                    if (visibleDevices === 0) noDevicesRow.classList.remove('hidden');
                    else noDevicesRow.classList.add('hidden');
                }

                if (ctx.hasServices && ctx.bc1) {
                    ctx.bc1.classList.remove('text-slate-700');
                    ctx.bc1.classList.add('text-primary', 'cursor-pointer', 'hover:underline');
                    if (ctx.sep1) ctx.sep1.classList.remove('hidden');
                }

                if ((ctx.hasVehicles || ctx.hasServices) && ctx.bc2) {
                    ctx.bc2.classList.remove('text-slate-700');
                    ctx.bc2.classList.add('text-primary', 'cursor-pointer', 'hover:underline');
                    if (ctx.sep2) ctx.sep2.classList.remove('hidden');
                }

                if (ctx.bc3) {
                    ctx.bc3.classList.remove('hidden');
                    ctx.bc3.classList.add('text-slate-700');
                    if (contextData.placa) {
                        ctx.bc3.textContent = `Dispositivos (${contextData.placa})`;
                    } else {
                        ctx.bc3.textContent = "Dispositivos";
                    }
                }
            }
        }

        document.body.addEventListener('click', function (e) {
            const bc1 = e.target.closest('.bc-1');
            if (bc1 && bc1.classList.contains('cursor-pointer')) {
                const ctx = getPanelContext(bc1);
                if (ctx) showLevel(1, ctx);
                return;
            }

            const bc2 = e.target.closest('.bc-2');
            if (bc2 && bc2.classList.contains('cursor-pointer')) {
                const ctx = getPanelContext(bc2);
                if (ctx) {
                    let filterData = {};
                    try { filterData = JSON.parse(ctx.panel.dataset.currentFilter || '{}'); } catch (err) { }
                    showLevel(2, ctx, filterData);
                }
                return;
            }

            if (e.target.closest('a') || e.target.closest('button')) return;

            const serviceRow = e.target.closest('.service-row-clickable');
            if (serviceRow) {
                const ctx = getPanelContext(serviceRow);
                if (ctx) {
                    const placa = serviceRow.getAttribute('data-vehicle-placa');
                    const serviceId = serviceRow.getAttribute('data-service-id');
                    showLevel(2, ctx, { placa, serviceId });
                }
                return;
            }

            const vehicleRow = e.target.closest('.vehicle-row-clickable');
            if (vehicleRow) {
                const ctx = getPanelContext(vehicleRow);
                if (ctx) {
                    const placa = vehicleRow.getAttribute('data-placa');
                    showLevel(3, ctx, { placa });
                }
                return;
            }
        });

        const observer = new MutationObserver((mutations) => {
            mutations.forEach(mutation => {
                if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                    const target = mutation.target;
                    if ((target.classList.contains('expansion-row') || target.hasAttribute('data-history-row')) && !target.classList.contains('hidden')) {
                        const panel = target.querySelector('.relation-panel-accordion');
                        if (panel && !panel.dataset.initialized) {
                            panel.dataset.initialized = 'true';
                            const ctx = getPanelContext(panel);
                            const startLevel = parseInt(panel.dataset.startLevel || 1, 10);
                            if (ctx) showLevel(startLevel, ctx);
                        }
                    }
                }
            });
        });
        observer.observe(document.body, { attributes: true, subtree: true, attributeFilter: ['class'] });

        // Function to run initialization for already visible panels
        function initVisiblePanels() {
            document.querySelectorAll('.relation-panel-accordion').forEach(panel => {
                const expansionRow = panel.closest('.expansion-row') || panel.closest('[data-history-row]');
                if (expansionRow && expansionRow.classList.contains('hidden')) {
                    return;
                }
                
                if (!panel.dataset.initialized) {
                    panel.dataset.initialized = 'true';
                    const ctx = getPanelContext(panel);
                    const startLevel = parseInt(panel.dataset.startLevel || 1, 10);
                    if (ctx) showLevel(startLevel, ctx);
                }
            });
        }

        // Initialize immediately
        initVisiblePanels();

        // Also expose it globally so AJAX calls can trigger it if necessary
        window.initRelationPanelsIfVisible = initVisiblePanels;
    }
</script>
