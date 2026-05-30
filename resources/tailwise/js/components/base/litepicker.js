import Litepicker from 'litepicker';

const initLitepickers = (root = document) => {
	const scope = root instanceof Document ? root : (root instanceof HTMLElement ? root : document);
	const inputs = scope.querySelectorAll('.datepicker');
	inputs.forEach((inputEl) => {
		if (inputEl.__litepickerInitialized) {
			return;
		}
		inputEl.__litepickerInitialized = true;

		let e = {autoApply:!1,singleMode:!0,numberOfColumns:1,numberOfMonths:1,showWeekNumbers:!1,format:"D MMM, YYYY",lang:"es-ES",buttonText:{apply:"Aplicar",cancel:"Cancelar"},dropdowns:{minYear:1990,maxYear:null,months:!0,years:!0}};
		const $input = $(inputEl);
		const noDefault = inputEl.hasAttribute('data-no-default') || $input.data('no-default') === true;
		if (inputEl.dataset.autoApply !== undefined) {
			e.autoApply = String(inputEl.dataset.autoApply) !== 'false';
		}

		if (inputEl.dataset.format) {
			e.format = inputEl.dataset.format;
		}
		if (inputEl.dataset.singleMode || $input.data('single-mode')) {
			e.singleMode = true;
			e.numberOfColumns = 1;
			e.numberOfMonths = 1;
		}

		// Valor original (nativo)
		const originalVal = inputEl.value || '';
		const origName = inputEl.getAttribute('name') || null;
		let hiddenEl = null;

		if (origName) {
			// Do NOT rename the visible input (keep server compatibility).
			// Create a hidden input to store ISO value which we will copy to the visible input before submit.
			hiddenEl = document.createElement('input');
			hiddenEl.type = 'hidden';
			hiddenEl.name = origName + '_iso';
			if (inputEl.parentNode) {
				inputEl.parentNode.insertBefore(hiddenEl, inputEl.nextSibling);
			}

			// Attach form submit handler to copy ISO into the visible input before submission
			const form = inputEl.closest('form');
			if (form) {
				form.addEventListener('submit', function (ev) {
					try {
						if (hiddenEl && hiddenEl.value) {
							// set visible input to ISO (server expects date)
							inputEl.value = hiddenEl.value;
						} else {
							// try to parse display value into ISO
							const parsed = dayjs(inputEl.value, e.format, 'es');
							if (parsed.isValid()) {
								inputEl.value = parsed.format('YYYY-MM-DD');
							}
						}
					} catch (err) {
						// noop
					}
				});
			}
		}

		if (originalVal && /^\d{4}-\d{2}-\d{2}/.test(originalVal)) {
			try {
				const disp = dayjs(originalVal).format(e.format);
				inputEl.value = disp;
				if (hiddenEl) { hiddenEl.value = originalVal; }
			} catch (err) {
				// ignore
			}
		}

		if (!inputEl.value && !noDefault) {
			let t = dayjs().format(e.format);
			t += e.singleMode ? '' : ' - ' + dayjs().add(1, 'month').format(e.format);
			inputEl.value = t;
		} else if (!inputEl.value && noDefault) {
			inputEl.value = '';
		}

		const picker = new Litepicker(Object.assign({element: inputEl}, e));

		const syncHiddenFromDisplay = (val) => {
				if (!hiddenEl) return;
				if (!val) { hiddenEl.value = ''; return; }
			try {
				const parsed = dayjs(val, e.format, 'es');
				if (parsed.isValid()) {
					hiddenEl.value = parsed.format('YYYY-MM-DD');
					return;
				}
				const parsed2 = dayjs(val);
				if (parsed2.isValid()) { hiddenEl.value = parsed2.format('YYYY-MM-DD'); }
			} catch (err) {
				// noop
			}
		};

		inputEl.addEventListener('change', function() { syncHiddenFromDisplay(inputEl.value); });

		try {
			if (picker && typeof picker.on === 'function') {
				picker.on('selected', (startDate /*, endDate */) => {
					if (!hiddenEl) return;
					if (startDate && typeof startDate.format === 'function') {
						hiddenEl.value = startDate.format('YYYY-MM-DD');
					} else if (startDate) {
						hiddenEl.value = dayjs(startDate).format('YYYY-MM-DD');
					}
				});
			}
		} catch (err) {
			// ignore
		}
	});
};

window.initLitepickers = window.initLitepickers || initLitepickers;
initLitepickers(document);
