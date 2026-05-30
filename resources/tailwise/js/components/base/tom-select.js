(()=>{
	(function(){
		"use strict";

		const resetDropdownPosition = (dropdown) => {
			if (!dropdown) {
				return;
			}

			dropdown.style.top = "";
			dropdown.style.right = "";
			dropdown.style.bottom = "";
			dropdown.style.left = "";
			dropdown.style.width = "";
			dropdown.style.marginTop = "";
			dropdown.style.marginBottom = "";
			dropdown.classList.remove("ts-dropdown-above");
		};

		const updateDropdownPosition = (instance) => {
			if (!instance || !instance.dropdown || !instance.control) {
				return;
			}

			const dropdown = instance.dropdown;
			const control = instance.control;
			const rect = control.getBoundingClientRect();
			const dropdownHeight = dropdown.offsetHeight || dropdown.scrollHeight || 0;
			const spaceBelow = window.innerHeight - rect.bottom;
			const spaceAbove = rect.top;
			const openUp = dropdownHeight > 0 && spaceBelow < dropdownHeight && spaceAbove > spaceBelow;

			resetDropdownPosition(dropdown);

			if (!openUp) {
				return;
			}

			dropdown.classList.add("ts-dropdown-above");

			if (instance.settings.dropdownParent === "body") {
				dropdown.style.top = Math.max(window.scrollY + rect.top - dropdownHeight - 4, 0) + "px";
				dropdown.style.left = (rect.left + window.scrollX) + "px";
				dropdown.style.width = rect.width + "px";
			} else {
				dropdown.style.top = "auto";
				dropdown.style.bottom = "100%";
				dropdown.style.marginTop = "0";
				dropdown.style.marginBottom = "0.25rem";
			}
		};

		$(".tom-select").each(function(){
			let settings = { plugins: { dropdown_input: {} } };

			$(this).data("placeholder") && (settings.placeholder = $(this).data("placeholder"));

			const maxOptions = $(this).data("max-options");
			if (typeof maxOptions !== "undefined" && maxOptions !== null) {
				settings.maxOptions = Number(maxOptions);
			}

			$(this).attr("multiple") !== void 0 && (settings = {
				...settings,
				plugins: { ...settings.plugins, remove_button: { title: "Remove this item" } },
				persist: !1,
				create: !0,
				onDelete: function(t){
					return confirm(t.length > 1 ? "Are you sure you want to remove these " + t.length + " items?" : 'Are you sure you want to remove "' + t[0] + '"?');
				}
			});

			$(this).data("header") && (settings = {
				...settings,
				plugins: { ...settings.plugins, dropdown_header: { title: $(this).data("header") } }
			});

			const instance = new TomSelect(this, settings);

			instance.on("dropdown_open", () => {
				updateDropdownPosition(instance);
			});

			instance.on("dropdown_close", () => {
				resetDropdownPosition(instance.dropdown);
			});
		});
	})();
})();
