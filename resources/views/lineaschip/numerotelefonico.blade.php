@extends('layouts.crud-table')

@push('modals')
    <!-- Botón FAB -->
    <button id="fab-states-btn"
        class="flex items-center justify-center rounded-full bg-primary text-white hover:bg-primary/90 focus:outline-none cursor-pointer"
        style="position: fixed; bottom: 24px; right: 24px; z-index: 50; width: 56px; height: 56px; box-shadow: 0 4px 14px 0 rgba(0,0,0,0.39); transition: all 0.3s ease;"
        title="Ver significados de estados">
        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor"
            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"></circle>
            <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
            <path d="M12 17h.01"></path>
        </svg>
    </button>

    <!-- Panel de información de estados -->
    <div id="fab-states-panel" class="bg-white border overflow-hidden flex flex-col rounded-lg"
        style="position: fixed; bottom: 96px; right: 24px; z-index: 100; width: 320px; box-shadow: 0 10px 40px -10px rgba(0,0,0,0.3); opacity: 0; pointer-events: none; transform: translateY(16px); transition: all 0.3s ease;">
        <div class="bg-primary px-5 py-4 text-white">
            <h3 class="font-medium text-lg flex items-center gap-2" style="margin: 0; color: white;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                    <polyline points="10 9 9 9 8 9"></polyline>
                </svg>
                Significados de Estados
            </h3>
        </div>
        <div class="p-5 flex flex-col gap-4 overflow-y-auto" style="max-height: 60vh;">
            <div class="flex items-start gap-3">
                <div class="mt-0.5 flex-shrink-0 flex items-center justify-center rounded-full bg-primary text-white text-xs font-bold"
                    style="width:25px; height: 25px;">
                    1</div>
                <div>
                    <div class="font-semibold" style="color: #B41B29; font-weight: 700;">En Uso</div>
                    <div class="text-sm text-slate-500 mt-0.5">El número está siendo usado por un servicio activo.</div>
                </div>
            </div>
            <div class="flex items-start gap-3">
                <div class="mt-0.5 flex-shrink-0 flex items-center justify-center rounded-full bg-primary text-white text-xs font-bold"
                    style="width:25px; height: 25px;">
                    2</div>
                <div>
                    <div class="font-semibold" style="color: #1D8F5F; font-weight: 700;">Disponible</div>
                    <div class="text-sm text-slate-500 mt-0.5">Tiene relación con su SimCard y está listo para servicio.</div>
                </div>
            </div>
            <div class="flex items-start gap-3">
                <div class="mt-0.5 flex-shrink-0 flex items-center justify-center rounded-full bg-primary text-white text-xs font-bold"
                    style="width:25px; height: 25px;">
                    3</div>
                <div>
                    <div class="font-semibold" style="color: #2563eb; font-weight: 700;">Libre</div>
                    <div class="text-sm text-slate-500 mt-0.5">No tiene ninguna relación con SimCard ni servicio.</div>
                </div>
            </div>
            <div class="flex items-start gap-3">
                <div class="mt-0.5 flex-shrink-0 flex items-center justify-center rounded-full bg-primary text-white text-xs font-bold"
                    style="width:25px; height: 25px;">
                    4</div>
                <div>
                    <div class="font-semibold" style="color: #64748b; font-weight: 700;">Inactivo</div>
                    <div class="text-sm text-slate-500 mt-0.5">No puede usarse porque fue dado de baja.</div>
                </div>
            </div>

        </div>
    </div>
@endpush

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const pdfModal = document.getElementById('cotizacion-pdf-preview-modal');
            const pdfFrame = document.getElementById('cotizacion-pdf-preview-frame');
            const pdfNumber = document.getElementById('cotizacion-pdf-preview-number');
            const closePdfModal = function () {
                if (!pdfModal) {
                    return;
                }
                pdfModal.classList.add('hidden');
                pdfModal.classList.remove('flex');
                if (pdfFrame) {
                    pdfFrame.src = 'about:blank';
                }
                document.body.style.overflow = '';
            };

            document.addEventListener('click', function (event) {
                const previewButton = event.target.closest('[data-cotizacion-pdf-preview]');
                if (previewButton && pdfModal && pdfFrame) {
                    event.preventDefault();
                    pdfFrame.src = previewButton.dataset.cotizacionPdfPreview || 'about:blank';
                    if (pdfNumber) {
                        pdfNumber.textContent = previewButton.dataset.cotizacionNumber
                            ? 'Cotización ' + previewButton.dataset.cotizacionNumber
                            : '';
                    }
                    pdfModal.classList.remove('hidden');
                    pdfModal.classList.add('flex');
                    document.body.style.overflow = 'hidden';
                    return;
                }

                if (event.target.closest('[data-cotizacion-pdf-preview-close]')) {
                    closePdfModal();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && pdfModal && !pdfModal.classList.contains('hidden')) {
                    closePdfModal();
                }
            });

            const fabBtn = document.getElementById('fab-states-btn');
            const fabPanel = document.getElementById('fab-states-panel');

            if (fabBtn && fabPanel) {
                // Movemos los elementos directamente al body para evitar problemas de posicionamiento
                // document.body.appendChild(fabBtn);
                // document.body.appendChild(fabPanel);

                let isOpen = false;

                fabBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    isOpen = !isOpen;
                    togglePanel();
                });

                // Cerrar al hacer clic fuera del panel
                document.addEventListener('click', function (e) {
                    if (isOpen && !fabPanel.contains(e.target)) {
                        isOpen = false;
                        togglePanel();
                    }
                });

                // Evitar que el clic dentro del panel lo cierre
                fabPanel.addEventListener('click', function (e) {
                    e.stopPropagation();
                });

                function togglePanel() {
                    if (isOpen) {
                        fabPanel.style.opacity = '1';
                        fabPanel.style.pointerEvents = 'auto';
                        fabPanel.style.transform = 'translateY(0)';
                        fabBtn.style.transform = 'rotate(0deg) scale(1.05)';
                    } else {
                        fabPanel.style.opacity = '0';
                        fabPanel.style.pointerEvents = 'none';
                        fabPanel.style.transform = 'translateY(16px)';
                        fabBtn.style.transform = 'rotate(0deg) scale(1)';
                    }
                }
            }
        });
    </script>
@endpush