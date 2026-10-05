@extends('layouts.crud-table')

@push('styles')
	<style>
		.dispositivo-cliente-list-table {
			table-layout: fixed;
		}

		.dispositivo-cliente-list-table > thead > tr > td,
		.dispositivo-cliente-list-table > tbody > tr:not([data-history-row]) > td {
			padding: 4px 6px !important;
			font-size: 10px !important;
			line-height: 1.25 !important;
		}

		.dispositivo-cliente-list-table > tbody > tr:not([data-history-row]) > td {
			overflow: hidden;
		}

		.dispositivo-cliente-list-table > tbody > tr:not([data-history-row]) > td > span,
		.dispositivo-cliente-list-table > tbody > tr:not([data-history-row]) > td > a {
			display: block;
			max-width: 100%;
			overflow: hidden;
			text-overflow: ellipsis;
		}
	</style>
@endpush

@push('scripts')
	<script>
		(() => {
			const expectedColumns = ['ID Dispositivo', 'Vehículo', 'Número', 'Cliente', 'Servicio', 'Marca', 'Modelo', 'Fecha Inicio', 'Fecha Fin', 'Estado'];
			const table = Array.from(document.querySelectorAll('table')).find((candidate) => {
				const labels = Array.from(candidate.querySelectorAll('thead td')).map((cell) => cell.textContent.trim());
				return expectedColumns.every((label) => labels.includes(label));
			});

			table?.classList.add('dispositivo-cliente-list-table');
		})();
	</script>
@endpush
