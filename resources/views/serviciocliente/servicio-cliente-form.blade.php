@extends('layouts.crud-form')

@push('scripts')
	<script>
		document.addEventListener('DOMContentLoaded', () => {
			const cliente = document.getElementById('select-cliente_idcliente');
			const vehiculo = document.getElementById('select-vehiculo_placa');
			const servicio = document.getElementById('select-almacen_idalmacen');
			const inicio = document.querySelector('[name="fechaInicio"]');
			const vencimiento = document.querySelector('[name="fecheVencimiento"]');
			const monto = document.querySelector('[name="monto"]');
			const deviceSelect = document.getElementById('select-dispositivoCliente_iddispositivoCliente');
			const numeroSelect = document.getElementById('select-numeroTelefonico_numeroTelefonico');
			const estadoSelect = document.getElementById('select-estado');

			const clienteMeta = @json($clienteOptionMeta ?? []);
			const servicioMeta = @json($servicioOptionMeta ?? []);
			const vehiculosUrl = @json($vehiculosUrl ?? '');
			const dispositivosUrl = @json($dispositivosUrl ?? '');
			const serviciosUrl = @json($serviciosUrl ?? '');

			const mode = @json($mode ?? 'create');
			const isEdit = (mode === 'edit');
			const isInactive = @json(($record->estado ?? '') === 'inactivo');

			const readValue = (element) => element?.tomselect?.getValue?.() || element?.value || '';
			const getTomValue = (el) => el?.tomselect ? el.tomselect.getValue() : (el?.value || '');
			const setTomValue = (el, val, silent = false) => {
				if (el?.tomselect) {
					el.tomselect.setValue(val, silent);
				} else if (el) {
					el.value = val;
				}
			};

			const monthNames = { ene: 1, enero: 1, feb: 2, febrero: 2, mar: 3, marzo: 3, abr: 4, abril: 4, may: 5, mayo: 5, jun: 6, junio: 6, jul: 7, julio: 7, ago: 8, agosto: 8, sep: 9, septiembre: 9, oct: 10, octubre: 10, nov: 11, noviembre: 11, dic: 12, diciembre: 12 };
			const toIsoDate = (value) => {
				const raw = String(value || '').trim();
				if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw;
				const match = raw.match(/^(\d{1,2})\s+([A-Za-zÀ-ÿ]+),?\s*(\d{4})$/);
				if (!match) return '';
				const month = monthNames[match[2].toLowerCase()];
				return month ? `${match[3]}-${String(month).padStart(2, '0')}-${String(Number(match[1])).padStart(2, '0')}` : '';
			};
			const displayDate = (iso) => {
				const match = String(iso || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
				const labels = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
				if (!match || !labels[Number(match[2])]) return '';
				return `${Number(match[3])} ${labels[Number(match[2])]}, ${match[1]}`;
			};

			// Create and append the hidden inputs to the form
			const formEl = document.getElementById('main-crud-form');
			let comentarioHidden = null;
			let mantenerSimInput = null;
			if (formEl) {
				comentarioHidden = document.createElement('input');
				comentarioHidden.type = 'hidden';
				comentarioHidden.name = 'comentario_baja';
				comentarioHidden.id = 'comentario_baja';
				comentarioHidden.value = '';
				formEl.appendChild(comentarioHidden);

				mantenerSimInput = document.createElement('input');
				mantenerSimInput.type = 'hidden';
				mantenerSimInput.name = 'mantener_sim';
				mantenerSimInput.id = 'mantener_sim';
				mantenerSimInput.value = 'si';
				formEl.appendChild(mantenerSimInput);
			}

			// Capture initial values for change detection
			let previousVehiculo = getTomValue(vehiculo);
			let previousDevice = getTomValue(deviceSelect);
			let previousNumero = getTomValue(numeroSelect);
			let previousEstado = getTomValue(estadoSelect);

			if (cliente) {
				Array.from(cliente.options).forEach((option) => {
					if (option.value && clienteMeta[option.value]) option.textContent += ' (Integrador)';
				});
				cliente.addEventListener('change', async () => {
					const value = readValue(cliente);
					const instance = vehiculo?.tomselect;
					const deviceInstance = deviceSelect?.tomselect;
					if (!vehiculo || !instance) return;
					instance.clear(true);
					instance.clearOptions();
					if (deviceInstance) {
						deviceInstance.clear(true);
						deviceInstance.clearOptions();
					}
					if (!value) return;
					const [vehiclesResponse, devicesResponse] = await Promise.all([
						fetch(`${vehiculosUrl}?cliente_idcliente=${encodeURIComponent(value)}`, { headers: { Accept: 'application/json' } }),
						dispositivosUrl ? fetch(`${dispositivosUrl}?cliente_idcliente=${encodeURIComponent(value)}`, { headers: { Accept: 'application/json' } }) : Promise.resolve(null),
					]);
					const items = await vehiclesResponse.json();
					instance.addOptions(items.map((item) => ({ value: item.placa, text: item.vehiculo_label })));
					instance.refreshOptions(false);
					if (deviceInstance && devicesResponse) {
						const devices = await devicesResponse.json();
						deviceInstance.addOptions(Object.entries(devices).map(([value, text]) => ({ value, text })));
						deviceInstance.refreshOptions(false);
					}
				});
			}

			const reloadServices = async () => {
				const instance = servicio?.tomselect;
				if (!instance) return;
				instance.clear(true);
				instance.clearOptions();
				const vehicle = readValue(vehiculo);
				if (!vehicle) return;
				if (!serviciosUrl) return;
				const response = await fetch(`${serviciosUrl}?vehiculo_placa=${encodeURIComponent(vehicle)}`, { headers: { Accept: 'application/json' } });
				const items = await response.json();
				instance.addOptions(items.map((item) => ({ value: item.value, text: item.text, disabled: item.disabled === true })));
				instance.refreshOptions(false);
			};
			vehiculo?.addEventListener('change', reloadServices);

			const calculate = () => {
				const data = servicioMeta[readValue(servicio)] || {};
				if (monto && data.precio !== null && data.precio !== undefined) monto.value = data.precio;
				const startIso = toIsoDate(inicio?.value);
				if (!inicio || !vencimiento || !startIso || !data.periodo || Number(data.periodo) <= 0) return;
				const date = new Date(`${startIso}T00:00:00`);
				const periodInMonths = { 30: 1, 90: 3, 180: 6, 365: 12, 730: 24, 1095: 36, 1460: 48 }[Number(data.periodo)];
				if (periodInMonths) {
					date.setMonth(date.getMonth() + periodInMonths);
				} else {
					date.setDate(date.getDate() + Number(data.periodo));
				}
				const iso = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
				const formatted = displayDate(iso);
				vencimiento.value = formatted;
				vencimiento.dataset.isoValue = iso;
				const hiddenDate = vencimiento.nextElementSibling;
				if (hiddenDate?.type === 'hidden') hiddenDate.value = iso;
				if (vencimiento.__litepicker?.setDate && formatted) vencimiento.__litepicker.setDate(formatted);
			};
			servicio?.addEventListener('change', calculate);
			inicio?.addEventListener('change', calculate);
			inicio?.addEventListener('input', calculate);
			vencimiento?.addEventListener('input', () => {
				const iso = toIsoDate(vencimiento.value);
				if (iso) vencimiento.dataset.isoValue = iso;
			});
			document.querySelector('form')?.addEventListener('submit', () => {
				if (vencimiento) vencimiento.value = vencimiento.dataset.isoValue || toIsoDate(vencimiento.value) || '';
			});
			calculate();

			/* ── Show previous number link if Inactive ── */
			if (isEdit && isInactive) {
				const prevNumber = @json($record->numeroTelefonico_numeroTelefonico ?? '');
				if (prevNumber) {
					const selectContainer = document.getElementById('select-numeroTelefonico_numeroTelefonico')?.closest('.crud-field-wrapper');
					if (selectContainer) {
						const infoDiv = document.createElement('div');
						infoDiv.style.marginTop = '0.35rem';
						infoDiv.style.fontSize = '0.82rem';

						const editUrl = @json(route('modules.lineas-chips.numeros-telefonico.edit', 'NUMBER_PLACEHOLDER')).replace('NUMBER_PLACEHOLDER', prevNumber);

						infoDiv.innerHTML = `Número anterior: <a href="${editUrl}" target="_blank" style="color:#dc2626; font-weight:600; text-decoration:underline;">${prevNumber}</a> (Haz clic para editar el número y gestionar su SIM card)`;
						selectContainer.appendChild(infoDiv);
					}
				}
			}

			/* ── General Confirmation Modal Builder ── */
			const openConfirmModal = (title, message, onAccept, onCancel) => {
				const overlay = document.createElement('div');
				const previousBodyOverflow = document.body.style.overflow;
				overlay.style.position = 'fixed';
				overlay.style.inset = '0';
				overlay.style.zIndex = '99999';
				overlay.style.display = 'flex';
				overlay.style.alignItems = 'center';
				overlay.style.justifyContent = 'center';
				overlay.style.backgroundColor = 'rgba(0, 0, 0, 0.78)';

				const modal = document.createElement('div');
				Object.assign(modal.style, {
					backgroundColor: '#ffffff', borderRadius: '0.75rem',
					padding: '1.75rem 2rem', width: '100%', maxWidth: '440px',
					boxSizing: 'border-box'
				});

				modal.innerHTML = `
																			<div style="margin-bottom:1.5rem;">
																				<h3 style="font-size:1.1rem;font-weight:700;color:#0f172a;margin:0 0 0.5rem;">${title}</h3>
																				<p style="font-size:0.88rem;color:#475569;margin:0;line-height:1.5;">${message}</p>
																			</div>
																			<div style="display:flex;justify-content:flex-end;gap:0.75rem;">
																				<button type="button" id="confirm-cancel" style="padding:0.55rem 1.2rem;font-size:0.88rem;font-weight:600;color:#475569;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:0.5rem;cursor:pointer;">Cancelar</button>
																				<button type="button" id="confirm-accept" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none bg-primary border-primary text-white">Aceptar</button>
																			</div>
																		`;
				overlay.appendChild(modal);
				document.body.appendChild(overlay);
				document.body.style.overflow = 'hidden';
				const close = (callback) => {
					overlay.remove();
					document.body.style.overflow = previousBodyOverflow;
					if (callback) callback();
				};

				modal.querySelector('#confirm-cancel').addEventListener('click', () => {
					close(onCancel);
				});
				modal.querySelector('#confirm-accept').addEventListener('click', () => {
					close(onAccept);
				});
			};

			/* ── Phone Number Confirmation Modal Builder (3 buttons) ── */
			const openNumeroConfirmModal = (onAcceptSi, onAcceptNo, onCancel) => {
				const overlay = document.createElement('div');
				const previousBodyOverflow = document.body.style.overflow;
				overlay.style.position = 'fixed';
				overlay.style.inset = '0';
				overlay.style.zIndex = '99999';
				overlay.style.display = 'flex';
				overlay.style.alignItems = 'center';
				overlay.style.justifyContent = 'center';
				overlay.style.backgroundColor = 'rgba(0, 0, 0, 0.78)';

				const modal = document.createElement('div');
				Object.assign(modal.style, {
					backgroundColor: '#ffffff', borderRadius: '0.75rem',
					padding: '1.75rem 2rem', width: '100%', maxWidth: '480px',
					boxSizing: 'border-box'
				});

				modal.innerHTML = `
																			<div style="margin-bottom:1.5rem;">
																				<h3 style="font-size:1.1rem;font-weight:700;color:#0f172a;margin:0 0 0.5rem;">¿Cambiar Número Telefónico?</h3>
																				<p style="font-size:0.88rem;color:#475569;margin:0 0 0.75rem;line-height:1.5;">¿Estás seguro de cambiar el número telefónico de este servicio?</p>
																				<p style="font-size:0.85rem;color:#64748b;margin:0;line-height:1.5;"><strong>¿Deseas mantener la relación del número anterior con su SIM card?</strong><br>
																				- <strong>Sí mantener</strong>: El número y su SIM siguen emparejados (libres para otros dispositivos).<br>
																				- <strong>No mantener</strong>: El número y su SIM se desvinculan y quedan libres pero independientes.</p>
																			</div>
																			<div style="display:flex;justify-content:flex-end;gap:0.5rem;flex-wrap:wrap;">
																				<button type="button" id="num-cancel" style="padding:0.55rem 1rem;font-size:0.88rem;font-weight:600;color:#475569;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:0.5rem;cursor:pointer;">Cancelar</button>
																				<button type="button" id="num-no" style="padding:0.55rem 1rem;font-size:0.88rem;font-weight:600;color:#ffffff;background:#eab308;border:1px solid #eab308;border-radius:0.5rem;cursor:pointer;">No mantener</button>
																				<button type="button" id="num-si" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none bg-primary border-primary text-white">Sí mantener</button>
																			</div>
																		`;
				overlay.appendChild(modal);
				document.body.appendChild(overlay);
				document.body.style.overflow = 'hidden';
				const close = (callback) => {
					overlay.remove();
					document.body.style.overflow = previousBodyOverflow;
					if (callback) callback();
				};

				modal.querySelector('#num-cancel').addEventListener('click', () => {
					close(onCancel);
				});
				modal.querySelector('#num-no').addEventListener('click', () => {
					close(onAcceptNo);
				});
				modal.querySelector('#num-si').addEventListener('click', () => {
					close(onAcceptSi);
				});
			};

			/* ── Change Listeners for Confirmations ── */
			let confirmationModalOpen = false;
			const onVehiculoChange = () => {
				if (confirmationModalOpen) return;
				const val = getTomValue(vehiculo);
				if (isEdit && !isInactive && previousVehiculo && val !== previousVehiculo) {
					confirmationModalOpen = true;
					openConfirmModal(
						'¿Cambiar Vehículo?',
						'¿Estás seguro de cambiar el vehículo asignado a este servicio?',
						() => {
							previousVehiculo = val;
							confirmationModalOpen = false;
						},
						() => {
							setTomValue(vehiculo, previousVehiculo, true);
							confirmationModalOpen = false;
						}
					);
				} else {
					previousVehiculo = val;
				}
			};

			const onDeviceChange = () => {
				if (confirmationModalOpen) return;
				const val = getTomValue(deviceSelect);
				if (isEdit && !isInactive && previousDevice && val !== previousDevice) {
					confirmationModalOpen = true;
					openConfirmModal(
						'¿Cambiar ID Dispositivo?',
						'¿Estás seguro de cambiar el ID Dispositivo asignado a este servicio?',
						() => {
							previousDevice = val;
							confirmationModalOpen = false;
						},
						() => {
							setTomValue(deviceSelect, previousDevice, true);
							confirmationModalOpen = false;
						}
					);
				} else {
					previousDevice = val;
				}
			};

			const onNumeroChange = () => {
				if (confirmationModalOpen) return;
				const val = getTomValue(numeroSelect);
				if (isEdit && !isInactive && previousNumero && val !== previousNumero) {
					confirmationModalOpen = true;
					openNumeroConfirmModal(
						() => {
							if (mantenerSimInput) mantenerSimInput.value = 'si';
							previousNumero = val;
							confirmationModalOpen = false;
						},
						() => {
							if (mantenerSimInput) mantenerSimInput.value = 'no';
							previousNumero = val;
							confirmationModalOpen = false;
						},
						() => {
							setTomValue(numeroSelect, previousNumero, true);
							confirmationModalOpen = false;
						}
					);
				} else {
					previousNumero = val;
				}
			};

			if (vehiculo) {
				if (vehiculo.tomselect) vehiculo.tomselect.on('change', onVehiculoChange);
				else vehiculo.addEventListener('change', onVehiculoChange);
			}
			if (deviceSelect) {
				if (deviceSelect.tomselect) deviceSelect.tomselect.on('change', onDeviceChange);
				else deviceSelect.addEventListener('change', onDeviceChange);
			}
			if (numeroSelect) {
				if (numeroSelect.tomselect) numeroSelect.tomselect.on('change', onNumeroChange);
				else numeroSelect.addEventListener('change', onNumeroChange);
			}

			/* ── Deactivation Modal ── */
			if (estadoSelect) {
				let modalOverlay = null;
				let previousBodyOverflow = '';

				const buildModal = () => {
					if (modalOverlay) return;
					

					modalOverlay = document.createElement('div');
					modalOverlay.id = 'modal-baja-overlay';
					Object.assign(modalOverlay.style, {
						position: 'fixed', inset: '0', zIndex: '9999',
						display: 'flex', alignItems: 'center', justifyContent: 'center',
						backgroundColor: 'rgba(0, 0, 0, 0.78)',
					});

					const modal = document.createElement('div');
					Object.assign(modal.style, {
						backgroundColor: '#ffffff', borderRadius: '0.75rem',
						boxShadow: '0 25px 50px -12px rgba(0, 0, 0, 0.78)',
						padding: '1.75rem 2rem', width: '100%', maxWidth: '480px',
					});

					modal.innerHTML = `
																				<div style="margin-bottom:1.25rem;">
																					<h3 style="font-size:1.1rem;font-weight:700;color:#0f172a;margin:0 0 0.35rem;">
																						Cambio de estado a Inactivo
																					</h3>
																					<p style="font-size:0.88rem;color:#64748b;margin:0;line-height:1.45;">
																						¿Por qué estás cambiando de estado <strong>Activo</strong> a <strong>Inactivo</strong>?
																						Ingresa un comentario describiendo el motivo.
																					</p>
																				</div>
																				<div style="margin-bottom:1rem;">
																					<label for="modal-baja-comment" style="display:block;font-size:0.82rem;font-weight:600;color:#334155;margin-bottom:0.35rem;">
																						Comentario <span style="color:#dc2626;">*</span>
																					</label>
																					<textarea id="modal-baja-comment" rows="3"
																						style="width:100%;border:1px solid #cbd5e1;border-radius:0.5rem;padding:0.65rem 0.8rem;font-size:0.9rem;color:#0f172a;resize:vertical;outline:none;transition:border-color 0.15s ease;box-sizing:border-box;"
																						placeholder="Describe el motivo de la baja (obligatorio)..."
																					></textarea>
																					<p id="modal-baja-error" style="color:#dc2626;font-size:0.78rem;margin:0.25rem 0 0;display:none;">El comentario es obligatorio.</p>
																				</div>
																				<div style="display:flex;justify-content:flex-end;gap:0.65rem;">
																					<button type="button" id="modal-baja-cancel"
																						style="padding:0.55rem 1.2rem;font-size:0.88rem;font-weight:600;color:#334155;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:0.5rem;cursor:pointer;transition:background 0.15s ease;">
																						Cancelar
																					</button>
																					<button type="button" id="modal-baja-accept"
																						class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none bg-primary border-primary text-white">
																						Aceptar
																					</button>
																				</div>
																			`;

					modalOverlay.appendChild(modal);
					document.body.appendChild(modalOverlay);
					previousBodyOverflow = document.body.style.overflow;
					document.body.style.overflow = 'hidden';

					requestAnimationFrame(() => {
						modalOverlay.style.opacity = '1';
						modal.style.transform = 'scale(1)';
					});

					const commentEl = modal.querySelector('#modal-baja-comment');
					commentEl?.focus();

					modal.querySelector('#modal-baja-cancel')?.addEventListener('click', () => {
						closeModal(false);
					});
					modal.querySelector('#modal-baja-accept')?.addEventListener('click', () => {
						const comment = (modal.querySelector('#modal-baja-comment')?.value || '').trim();
						if (!comment) {
							const errorEl = modal.querySelector('#modal-baja-error');
							if (errorEl) errorEl.style.display = 'block';
							commentEl.style.borderColor = '#B41B29';
							return;
						}
						closeModal(true);
					});
				};

				const closeModal = (accepted) => {
					if (!modalOverlay) return;

					if (accepted) {
						const comment = modalOverlay.querySelector('#modal-baja-comment')?.value.trim() || '';
						if (comentarioHidden) {
							comentarioHidden.value = comment;
						}
						previousEstado = 'inactivo';
					} else {
						setTomValue(estadoSelect, previousEstado, true);
					}
					document.body.style.overflow = previousBodyOverflow;

					modalOverlay.style.opacity = '0';
					setTimeout(() => {
						modalOverlay?.remove();
						modalOverlay = null;
					}, 200);
				};

				const onEstadoChange = () => {
					const newValue = readValue(estadoSelect);
					if (previousEstado === 'activo' && newValue === 'inactivo') {
						buildModal();
					} else {
						previousEstado = newValue;
					}
				};

				estadoSelect.addEventListener('change', onEstadoChange);
				if (estadoSelect.tomselect) {
					estadoSelect.tomselect.on('change', onEstadoChange);
				}
			}
		});
	</script>
@endpush