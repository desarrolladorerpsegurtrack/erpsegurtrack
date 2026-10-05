@extends('layouts.crud-form')

@push('scripts')
	<script>
		document.addEventListener('DOMContentLoaded', () => {
			const cliente = document.getElementById('select-cliente_idcliente') || document.querySelector('[name="cliente_idcliente"]');
			const vehiculo = document.getElementById('select-vehiculo_placa');
			const servicio = document.getElementById('select-almacen_idalmacen');
			const servicioHidden = document.querySelector('[name="almacen_idalmacen"]');
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
			const serverIsIntegrador = @json($isIntegrador ?? false);
			const modalidadServicio = @json($modalidadServicio ?? 'VENTA');

			const readValue = (element) => element?.tomselect?.getValue?.() || element?.value || '';
			const readServicioValue = () => readValue(servicio) || readValue(servicioHidden);
			const getTomValue = (el) => el?.tomselect ? el.tomselect.getValue() : (el?.value || '');
			const setTomValue = (el, val, silent = false) => {
				if (el?.tomselect) {
					el.tomselect.setValue(val, silent);
				} else if (el) {
					el.value = val;
				}
			};

			if (isEdit) {
				const vehicleDisplay = document.querySelector('[name="vehiculo_display"]');
				const vehicleWrapper = vehicleDisplay?.closest('.crud-field-wrapper');
				const vehicleLabel = vehicleWrapper?.querySelector('label');
				const vehicleControl = vehicleDisplay?.closest('.relative');

				if (vehicleWrapper && vehicleLabel && vehicleControl) {
					const vehicleGroup = document.createElement('div');
					vehicleGroup.style.minWidth = '0';
					vehicleGroup.style.flex = '1 1 0';
					vehicleGroup.append(vehicleLabel, vehicleControl);

					const modalityGroup = document.createElement('div');
					modalityGroup.style.minWidth = '0';
					modalityGroup.style.flex = '0 0 8rem';
					const modalityLabel = document.createElement('label');
					modalityLabel.className = 'block text-sm font-medium text-slate-700';
					modalityLabel.textContent = 'Modalidad';
					const modalityBadge = document.createElement('div');
					modalityBadge.setAttribute('role', 'status');
					modalityBadge.className = 'inline-flex w-full items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold text-white';
					modalityBadge.style.minHeight = '38px';
					modalityBadge.style.boxSizing = 'border-box';
					modalityBadge.style.backgroundColor = modalidadServicio === 'COMODATO' ? '#7c3aed' : '#16a34a';
					modalityBadge.textContent = modalidadServicio;
					modalityGroup.append(modalityLabel, modalityBadge);

					vehicleWrapper.style.display = 'flex';
					vehicleWrapper.style.alignItems = 'end';
					vehicleWrapper.style.gap = '0.75rem';
					vehicleWrapper.innerHTML = '';
					vehicleWrapper.append(vehicleGroup, modalityGroup);
				}
			}

			const monthNames = { ene: 1, enero: 1, feb: 2, febrero: 2, mar: 3, marzo: 3, abr: 4, abril: 4, may: 5, mayo: 5, jun: 6, junio: 6, jul: 7, julio: 7, ago: 8, agosto: 8, sept: 9, set: 9, septiembre: 9, oct: 10, octubre: 10, nov: 11, noviembre: 11, dic: 12, diciembre: 12 };
			const toIsoDate = (value) => {
				const raw = String(value || '').trim();
				if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw;
				const match = raw.match(/^(\d{1,2})\s+([A-Za-zÀ-ÿ]+)\.?,?\s*(\d{4})$/);
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

				const comentarioActivacionHidden = document.createElement('input');
				comentarioActivacionHidden.type = 'hidden';
				comentarioActivacionHidden.name = 'comentario_activacion';
				comentarioActivacionHidden.id = 'comentario_activacion';
				comentarioActivacionHidden.value = '';
				formEl.appendChild(comentarioActivacionHidden);

				mantenerSimInput = document.createElement('input');
				mantenerSimInput.type = 'hidden';
				mantenerSimInput.name = 'mantener_sim';
				mantenerSimInput.id = 'mantener_sim';
				mantenerSimInput.value = 'si';
				formEl.appendChild(mantenerSimInput);
			}

			// Capture initial values for change detection
			let previousDevice = getTomValue(deviceSelect);
			let previousNumero = getTomValue(numeroSelect);
			let previousEstado = getTomValue(estadoSelect);

			const toggleNumeroTelefonicoField = (isIntegrador) => {
				const wrapper = numeroSelect?.closest('.crud-field-wrapper');
				if (!numeroSelect) return;

				if (isIntegrador) {
					numeroSelect.disabled = true;
					numeroSelect.removeAttribute('required');
					numeroSelect.setAttribute('aria-required', 'false');
					if (numeroSelect.tomselect) {
						numeroSelect.tomselect.clear();
						numeroSelect.tomselect.clearOptions();
					}
					numeroSelect.value = '';
					if (wrapper) wrapper.style.display = 'none';
					return;
				}

				numeroSelect.disabled = false;
				const requiresPhone = !isEdit || getTomValue(estadoSelect) === 'activo';
				if (requiresPhone) {
					numeroSelect.setAttribute('required', 'required');
					numeroSelect.setAttribute('aria-required', 'true');
				} else {
					numeroSelect.removeAttribute('required');
					numeroSelect.setAttribute('aria-required', 'false');
				}
				if (wrapper) wrapper.style.display = '';
			};

			if (cliente) {
				const syncClienteIntegradorState = () => {
					const value = readValue(cliente);
					const isIntegrador = serverIsIntegrador || (!!value && !!(clienteMeta[value] === true || clienteMeta[value] === 1 || clienteMeta[value] === '1' || clienteMeta[value] === 'true' || clienteMeta[value] === 'si' || clienteMeta[value] === 'yes'));
					toggleNumeroTelefonicoField(isIntegrador);
				};
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
					syncClienteIntegradorState();
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
				syncClienteIntegradorState();
			}

			if (cliente) {
				Array.from(cliente.options || []).forEach((option) => {
					if (option.value && clienteMeta[option.value]) option.textContent += ' (Integrador)';
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

			const normalizePeriodoDays = (value) => {
				const raw = String(value ?? '').trim();
				if (raw === '') return null;
				if (/^\d+$/.test(raw)) return Number(raw);

				const normalized = raw.toLowerCase().replace(/\s+/g, ' ').replace(/\./g, '').trim();
				const map = {
					'mensual': 30,
					'1 mes': 30,
					'3 meses': 90,
					'6 meses': 180,
					'12 meses': 365,
					'24 meses': 730,
					'36 meses': 1095,
					'48 meses': 1460,
				};
				if (map[normalized] !== undefined) return map[normalized];

				const match = raw.match(/(\d+)/);
				return match ? Number(match[1]) : null;
			};

			const calculate = () => {
				if (isEdit) return;

				const data = servicioMeta[readServicioValue()] || {};
				const startIso = toIsoDate(inicio?.value) || inicio?.value || '';
				const periodoDays = normalizePeriodoDays(data.periodo);
				if (!inicio || !vencimiento || !startIso || !Number.isFinite(periodoDays) || periodoDays <= 0) {
					return;
				}

				const date = new Date(`${startIso}T00:00:00`);
				date.setDate(date.getDate() + periodoDays);
				const iso = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
				const formatted = displayDate(iso);
				vencimiento.value = formatted;
				if (vencimiento.__litepicker?.setDate && formatted) vencimiento.__litepicker.setDate(formatted);
			};
			const updateServiceFields = () => {
				const data = servicioMeta[readServicioValue()] || {};
				if (!isEdit && monto && data.precio !== null && data.precio !== undefined) {
					monto.value = data.precio;
				}
				calculate();
			};
			const refreshServiceFields = () => {
				requestAnimationFrame(updateServiceFields);
			};
			const bindServiceCalculation = () => {
				servicio?.removeEventListener('change', refreshServiceFields);
				servicio?.addEventListener('change', refreshServiceFields);
				if (servicio?.tomselect) {
					servicio.tomselect.off('change', refreshServiceFields);
					servicio.tomselect.on('change', refreshServiceFields);
				}
				updateServiceFields();
			};
			requestAnimationFrame(bindServiceCalculation);
			setTimeout(bindServiceCalculation, 500);
			if (!isEdit) {
				inicio?.addEventListener('change', calculate);
				inicio?.addEventListener('input', calculate);
				inicio?.__litepicker?.on?.('selected', calculate);
				if (inicio?.tomselect) {
					inicio.tomselect.on('change', calculate);
				}
			}
			document.querySelector('form')?.addEventListener('submit', () => {
				[inicio, vencimiento].forEach((dateField) => {
					const iso = toIsoDate(dateField?.value);
					if (dateField && iso) dateField.value = iso;
				});
			});
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
			const openNumeroConfirmModal = (onAcceptSi, onAcceptNo, onCancel, options = {}) => {
				const title = options.title || '¿Cambiar Número Telefónico?';
				const intro = options.intro || '¿Estás seguro de cambiar el número telefónico de este servicio?';
				const relationQuestion = options.hideRelationQuestion ? '' : '¿Deseas mantener la relación del número anterior con su SIM card?';
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
																					<h3 style="font-size:1.1rem;font-weight:700;color:#0f172a;margin:0 0 0.5rem;">${title}</h3>
																					${intro ? `<p style="font-size:0.88rem;color:#475569;margin:0 0 0.75rem;line-height:1.5;">${intro}</p>` : ''}
																					<p style="font-size:0.85rem;color:#64748b;margin:0;line-height:1.5;">${relationQuestion ? `<strong>${relationQuestion}</strong><br>` : ''}
																					- <strong>Sí mantener</strong>: El número y su SIM siguen emparejados (disponibles para otros dispositivos).<br>
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

			const openBajaSimConfirmModal = (onAcceptSi, onAcceptNo, onCancel) => {
				const overlay = document.createElement('div');
				const previousBodyOverflow = document.body.style.overflow;
				Object.assign(overlay.style, {
					position: 'fixed', inset: '0', zIndex: '99999', display: 'flex',
					alignItems: 'center', justifyContent: 'center',
					backgroundColor: 'rgba(0, 0, 0, 0.78)',
				});

				const modal = document.createElement('div');
				Object.assign(modal.style, {
					backgroundColor: '#ffffff', borderRadius: '0.75rem', padding: '1.75rem 2rem',
					width: '100%', maxWidth: '480px', boxSizing: 'border-box',
					boxShadow: '0 25px 50px -12px rgba(0, 0, 0, 0.35)',
				});

				modal.innerHTML = `
						<div style="margin-bottom:1.5rem;">
							<h3 style="font-size:1.1rem;font-weight:700;color:#0f172a;margin:0 0 0.65rem;">¿Deseas mantener la relación del número con su SIM card?</h3>
							<p style="font-size:0.88rem;color:#475569;margin:0;line-height:1.55;">Selecciona cómo debe quedar la relación al dar de baja este servicio.</p>
						</div>
						<div style="display:flex;flex-direction:column;gap:0.6rem;margin-bottom:1.5rem;">
							<div style="padding:0.7rem 0.8rem;border:1px solid #dbeafe;border-left:4px solid #2563eb;border-radius:0.45rem;background:#eff6ff;color:#334155;font-size:0.84rem;line-height:1.45;"><strong style="color:#1d4ed8;">Sí mantener</strong><br>El número y su SIM siguen emparejados, disponibles para otros dispositivos.</div>
							<div style="padding:0.7rem 0.8rem;border:1px solid #fde68a;border-left:4px solid #eab308;border-radius:0.45rem;background:#fffbeb;color:#334155;font-size:0.84rem;line-height:1.45;"><strong style="color:#a16207;">No mantener</strong><br>El número y su SIM se desvinculan y quedan libres de forma independiente.</div>
						</div>
						<div style="display:flex;justify-content:flex-end;gap:0.55rem;flex-wrap:wrap;">
							<button type="button" id="baja-sim-cancel" style="padding:0.58rem 1rem;font-size:0.86rem;font-weight:600;color:#475569;background:#f8fafc;border:1px solid #cbd5e1;border-radius:0.45rem;cursor:pointer;">Cancelar</button>
							<button type="button" id="baja-sim-no" style="padding:0.58rem 1rem;font-size:0.86rem;font-weight:700;color:#92400e;background:#facc15;border:1px solid #eab308;border-radius:0.45rem;cursor:pointer;">No mantener</button>
							<button type="button" id="baja-sim-si" style="padding:0.58rem 1rem;font-size:0.86rem;font-weight:700;color:#ffffff;background:#2563eb;border:1px solid #1d4ed8;border-radius:0.45rem;cursor:pointer;">Sí mantener</button>
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

				modal.querySelector('#baja-sim-cancel').addEventListener('click', () => close(onCancel));
				modal.querySelector('#baja-sim-no').addEventListener('click', () => close(onAcceptNo));
				modal.querySelector('#baja-sim-si').addEventListener('click', () => close(onAcceptSi));
			};

			/* ── Change Listeners for Confirmations ── */
			let confirmationModalOpen = false;
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
						if (mantenerSimInput) mantenerSimInput.value = 'si';
						setTimeout(() => {
							if (previousNumero && !serverIsIntegrador) {
								openBajaSimConfirmModal(
									() => {
										if (mantenerSimInput) mantenerSimInput.value = 'si';
									},
									() => {
										if (mantenerSimInput) mantenerSimInput.value = 'no';
									},
									() => {
										setTomValue(estadoSelect, 'activo', true);
										previousEstado = 'activo';
										if (comentarioHidden) comentarioHidden.value = '';
									}
								);
							}
						}, 220);
					} else {
						setTomValue(estadoSelect, previousEstado, true);
					}
					toggleNumeroTelefonicoField(serverIsIntegrador);
					document.body.style.overflow = previousBodyOverflow;

					modalOverlay.style.opacity = '0';
					setTimeout(() => {
						modalOverlay?.remove();
						modalOverlay = null;
					}, 200);
				};

				const buildActivacionModal = () => {
					let modalActivacionOverlay = document.createElement('div');
					modalActivacionOverlay.id = 'modal-activacion-overlay';
					Object.assign(modalActivacionOverlay.style, {
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
								Cambio de estado a Activo
							</h3>
							<p style="font-size:0.88rem;color:#64748b;margin:0;line-height:1.45;">
								¿Por qué estás cambiando de estado <strong>Inactivo</strong> a <strong>Activo</strong>?
								Ingresa un comentario describiendo el motivo.
							</p>
						</div>
						<div style="margin-bottom:1rem;">
							<label for="modal-activacion-comment" style="display:block;font-size:0.82rem;font-weight:600;color:#334155;margin-bottom:0.35rem;">
								Comentario <span style="color:#dc2626;">*</span>
							</label>
							<textarea id="modal-activacion-comment" rows="3"
								style="width:100%;border:1px solid #cbd5e1;border-radius:0.5rem;padding:0.65rem 0.8rem;font-size:0.9rem;color:#0f172a;resize:vertical;outline:none;transition:border-color 0.15s ease;box-sizing:border-box;"
								placeholder="Describe el motivo de la reactivación (obligatorio)..."
							></textarea>
							<p id="modal-activacion-error" style="color:#dc2626;font-size:0.78rem;margin:0.25rem 0 0;display:none;">El comentario es obligatorio.</p>
						</div>
						<div style="display:flex;justify-content:flex-end;gap:0.65rem;">
							<button type="button" id="modal-activacion-cancel"
								style="padding:0.55rem 1.2rem;font-size:0.88rem;font-weight:600;color:#334155;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:0.5rem;cursor:pointer;transition:background 0.15s ease;">
								Cancelar
							</button>
							<button type="button" id="modal-activacion-accept"
								class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none bg-primary border-primary text-white">
								Aceptar
							</button>
						</div>
					`;

					modalActivacionOverlay.appendChild(modal);
					document.body.appendChild(modalActivacionOverlay);
					const prevOverflow = document.body.style.overflow;
					document.body.style.overflow = 'hidden';

					requestAnimationFrame(() => {
						modalActivacionOverlay.style.opacity = '1';
						modal.style.transform = 'scale(1)';
					});

					const commentEl = modal.querySelector('#modal-activacion-comment');
					commentEl?.focus();

					const closeActModal = (accepted) => {
						if (accepted) {
							const comment = commentEl?.value.trim() || '';
							const activacionInput = document.getElementById('comentario_activacion');
							if (activacionInput) {
								activacionInput.value = comment;
							}
							previousEstado = 'activo';
						} else {
							setTomValue(estadoSelect, previousEstado, true);
						}
						toggleNumeroTelefonicoField(serverIsIntegrador);
						document.body.style.overflow = prevOverflow;
						modalActivacionOverlay.style.opacity = '0';
						setTimeout(() => {
							modalActivacionOverlay?.remove();
						}, 200);
					};

					modal.querySelector('#modal-activacion-cancel')?.addEventListener('click', () => closeActModal(false));
					modal.querySelector('#modal-activacion-accept')?.addEventListener('click', () => {
						const comment = (commentEl?.value || '').trim();
						if (!comment) {
							const errorEl = modal.querySelector('#modal-activacion-error');
							if (errorEl) errorEl.style.display = 'block';
							if (commentEl) commentEl.style.borderColor = '#B41B29';
							return;
						}
						closeActModal(true);
					});
				};

				const onEstadoChange = () => {
					const newValue = readValue(estadoSelect);
					toggleNumeroTelefonicoField(serverIsIntegrador);
					if (previousEstado === 'activo' && newValue === 'inactivo') {
						buildModal();
					} else if (previousEstado === 'inactivo' && newValue === 'activo') {
						buildActivacionModal();
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