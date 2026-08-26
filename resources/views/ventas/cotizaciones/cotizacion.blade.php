@extends('layouts.crud-table')

@push('modals')
    <div id="cotizacion-pdf-preview-modal" class="fixed inset-0 hidden items-center justify-center p-3 sm:p-6"
        style="z-index: 10000; background: rgba(0, 0, 0, 0.78);" role="dialog" aria-modal="true"
        aria-labelledby="cotizacion-pdf-preview-title">
        <div class="flex h-[86vh] w-full max-w-4xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl" style="max-width: 1100px; width: 100%; height: calc(100vh - 3rem); min-height: calc(90vh - 3rem); max-height: calc(95vh - 3rem);">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <div>
                    <h2 id="cotizacion-pdf-preview-title" class="text-base font-semibold text-slate-800">Ver PDF de cotización</h2>
                    <p id="cotizacion-pdf-preview-number" class="mt-0.5 text-xs text-slate-500"></p>
                </div>
                <button type="button" data-cotizacion-pdf-preview-close
                    class="ml-auto rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700"
                    aria-label="Cerrar visor PDF" title="Cerrar">
                    <i data-lucide="x" class="h-5 w-5"></i>
                </button>
            </div>
            <div class="min-h-0 flex-1 bg-slate-800">
                <iframe id="cotizacion-pdf-preview-frame" title="PDF de cotización" class="h-full w-full border-0"></iframe>
            </div>
        </div>
    </div>
@endpush

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
                    <div class="font-semibold" style="color: #1d4ed8; font-weight: 700;">Generado</div>
                    <div class="text-sm text-slate-500 mt-0.5">Cotización recién creada.</div>
                </div>
            </div>

            <div class="flex items-start gap-3">
                <div class="mt-0.5 flex-shrink-0 flex items-center justify-center rounded-full bg-primary text-white text-xs font-bold"
                    style="width:25px; height: 25px;">
                    2</div>
                <div>
                    <div class="font-semibold" style="color: #d97706; font-weight: 700;">Aprobado</div>
                    <div class="text-sm text-slate-500 mt-0.5">Se aprobó la cotización con el pago.</div>
                </div>
            </div>

            <div class="flex items-start gap-3">
                <div class="mt-0.5 flex-shrink-0 flex items-center justify-center rounded-full bg-primary text-white text-xs font-bold"
                    style="width:25px; height: 25px;">
                    3</div>
                <div>
                    <div class="font-semibold" style="color: #d97706; font-weight: 700;">Aprobado(SP)</div>
                    <div class="text-sm text-slate-500 mt-0.5">Se aprobó la cotizacion sin pagar.</div>
                </div>
            </div>

            <div class="flex items-start gap-3">
                <div class="mt-0.5 flex-shrink-0 flex items-center justify-center rounded-full bg-primary text-white text-xs font-bold"
                    style="width:25px; height: 25px;">
                    4</div>
                <div>
                    <div class="font-semibold" style="color: #16a34a; font-weight: 700;">Ejecutado(SP)</div>
                    <div class="text-sm text-slate-500 mt-0.5">Se finalizó la cotizacion sin pagar.</div>
                </div>
            </div>

            <div class="flex items-start gap-3">
                <div class="mt-0.5 flex-shrink-0 flex items-center justify-center rounded-full bg-primary text-white text-xs font-bold"
                    style="width:25px; height: 25px;">
                    5</div>
                <div>
                    <div class="font-semibold" style="color: #16a34a; font-weight: 700;">Finalizado</div>
                    <div class="text-sm text-slate-500 mt-0.5">Se finalizó la cotización con el pago.</div>
                </div>
            </div>

            <div class="flex items-start gap-3">
                <div class="mt-0.5 flex-shrink-0 flex items-center justify-center rounded-full bg-primary text-white text-xs font-bold"
                    style="width:25px; height: 25px;">
                    6</div>
                <div>
                    <div class="font-semibold" style="color: #B41B29; font-weight: 700;">Anulado</div>
                    <div class="text-sm text-slate-500 mt-0.5">Se anuló la cotización.</div>
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