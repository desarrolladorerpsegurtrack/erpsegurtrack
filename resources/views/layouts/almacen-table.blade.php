@extends('dashboard.overview-1')

@section('title', $title ?? 'Almacén')
@section('header', $title ?? 'Almacén')

@section('breadcrumb')
	<nav aria-label="breadcrumb" class="flex hidden flex-1 xl:block">
		<ol class="flex items-center text-theme-1">
			<li><a href="{{ route('home') }}">Inicio</a></li>
			<li class="relative ml-5 pl-0.5 before:content-[''] before:w-[14px] before:h-[14px] before:bg-chevron-black before:transform before:rotate-[-90deg] before:bg-[length:100%] before:-ml-[1.125rem] before:absolute before:my-auto before:inset-y-0 text-slate-600 cursor-text">
				<span>{{ $title ?? 'Almacén' }}</span>
			</li>
		</ol>
	</nav>
@endsection

@section('content')
	@php
		$authData = session('erp_auth', []);
		$userRoles = collect($authData['roles'] ?? [])
			->map(fn ($role) => mb_strtolower(trim((string) $role)))
			->filter();
		$isAdmin = $userRoles->contains('admin');
		$currentRouteName = optional(request()->route())->getName();
		$currentPermissionKey = App\Support\ErpPermission::resolvePermissionKeyFromRouteName($currentRouteName) ?: App\Support\ErpPermission::normalizeRouteModule($currentRouteName);
		$currentPermissionsSource = is_string($currentPermissionKey) ? ($authData['permissions'][$currentPermissionKey] ?? []) : [];
		$currentPermissions = collect($currentPermissionsSource)
			->map(fn ($value) => App\Support\ErpPermission::normalizeAction((string) $value))
			->filter()
			->unique()
			->values();
		$canCreate = $isAdmin || $currentPermissions->contains('crear');
		$canEdit = $isAdmin || $currentPermissions->contains('editar');
		$canDelete = $isAdmin || $currentPermissions->contains('eliminar');
		$canPerformActions = $canEdit || $canDelete;
		$listResource = null;
		if ($currentRouteName) {
			$listResource = preg_replace('/^modules\./', '', $currentRouteName);
			$listResource = preg_replace('/\.(create|edit|update|destroy|store|show|export|index)$/', '', $listResource);
		}
		$filters = $filters ?? [];
		$activeFilters = collect($filters)
			->filter(fn ($filter) => !empty($filter['name']) && request()->filled($filter['name']))
			->count();
	@endphp

	<div class="grid w-full grid-cols-12 gap-x-6 gap-y-10">
		<div class="col-span-12">
			<div class="flex flex-col gap-y-3 md:h-10 md:flex-row md:items-center">
				<div class="text-base font-medium almacen-board__title pl-5 group-[.mode--light]:text-white">
					{{ $title ?? 'Listado' }}
				</div>
				
				<div class="flex flex-col gap-x-3 gap-y-2 sm:flex-row md:ml-auto almacen-board__new">
					@php
						$almacenPermissions = collect($authData['permissions'] ?? []);
						$canNotaIngreso = $almacenPermissions->get('almacen.nota_ingreso', []) !== [];
						$canNotaSalida = $almacenPermissions->get('almacen.nota_salida', []) !== [];
					@endphp
					@if($canNotaSalida)
						<a href="{{ route('modules.almacen.nota-salida.index') }}" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed dark:border-danger/70 dark:text-danger" style="border-color:#c71010;color:#c71010;">
                            <i data-tw-merge="" data-lucide="ban" class="mr-2 h-4 w-4 stroke-[1.3]"></i>
                            Nota de Salida
						</a>
					@endif
					@if($canNotaIngreso)
						<a href="{{ route('modules.almacen.nota-ingreso.index') }}" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed dark:border-darkmode-100/40 dark:text-slate-300" style="border-color:#000000;color:#000000;">
                            <i data-tw-merge="" data-lucide="upload" class="mr-2 h-4 w-4 stroke-[1.3]"></i>
                            Nota de Ingreso
						</a>
					@endif
					@if(!empty($createRoute) && $canCreate)
						<a href="{{ $createRoute }}" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed dark:border-danger/70 dark:text-danger" style="background-color:#c71010;color:#ffffff;">
							<i data-tw-merge="" data-lucide="plus" class="mr-2 h-4 w-4 stroke-[1.3]"></i>
							Nuevo {{ $singularTitle ?? 'Registro' }}
						</a>
					@endif
				</div>
			</div>

			<div class="mt-3.5 flex flex-col gap-8">
				@if(!empty($listResource))
					<input type="hidden" id="erp-list-resource" value="{{ $listResource }}">
					<input type="hidden" id="erp-relation-summary-template" value="{{ route('modules.relations.summary', ['resource' => '__RESOURCE__', 'id' => '__ID__']) }}">
				@endif

				<div class="session-alerts-container w-full">
					@if(session('success'))
						<div class="mb-4 rounded-lg border px-4 py-3 text-base font-semibold relative session-alert session-alert--success" style="border-color:#16a34a;background-color:#dcfce7;color:#14532d;">
							<span class="session-alert__icon">✓</span>
							<span class="session-alert__message">{{ session('success') }}</span>
							<button type="button" class="session-alert__close" onclick="this.parentElement.style.display='none';">&times;</button>
						</div>
					@endif
					@if(session('error'))
						<div class="mb-4 rounded-lg border px-4 py-3 text-base font-semibold relative session-alert session-alert--error" style="border-color:#a31616;background-color:#fcdcdc;color:#531414;">
							<span class="session-alert__icon">✕</span>
							<span class="session-alert__message">{{ session('error') }}</span>
							<button type="button" class="session-alert__close" onclick="this.parentElement.style.display='none';">&times;</button>
						</div>
					@endif
					@if($errors->any())
						<div class="mb-4 rounded-lg border border-red-700 bg-red-600 px-4 py-3 text-sm font-semibold text-white session-alert session-alert--errorlist">
							<ul class="list-disc pl-5">
								@foreach($errors->all() as $error)
									<li>{{ $error }}</li>
								@endforeach
							</ul>
						</div>
					@endif
				</div>
				<!-- ESTADÍSTICAS -->
				@if(!empty($stats))
					<div class="box box--stacked almacen-stats-white flex flex-col p-3">
						<div class="grid grid-cols-4 gap-5">
							@foreach($stats as $stat)
								<div class="box col-span-4 rounded-none border border-dashed border-slate-300/80 bg-white p-5 shadow-none md:col-span-2 xl:col-span-1">
									<div class="text-base text-slate-500">{{ $stat['label'] }}</div>
									<div class="mt-1.5 text-2xl font-medium stat-value">{{ $stat['value'] }}</div>
								</div>
							@endforeach
						</div>
					</div>
				@endif
				<!-- TABLA -->
				<div id="list-table-wrapper" class="box box--stacked almacen-table-white flex w-full flex-col">
					<div class="p-5">
						<form id="list-filter-form" method="GET" action="{{ url()->current() }}" class="almacen-filters-bar">
							<div class="almacen-filters-row almacen-filters-row--top pl-1">
								<div class="almacen-filter-item almacen-filter-item--wide almacen-filter-item--search">
									<label class="almacen-filter-label">Buscar</label>
									<div class="relative">
										<input type="text" name="q" autocomplete="off" value="{{ request('q') }}" placeholder="Buscar..." class="almacen-filter-control almacen-filter-control--search">
									</div>
								</div>
								<div class="almacen-filters-actions">
									@if(!empty($exportRoutes))
										<a href="{{ $exportRoutes['pdf'] ?? '#' }}{{ request()->getQueryString() ? ('?' . request()->getQueryString()) : '' }}" class="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50 transition duration-200 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none" style="border-color:#000000;color:#000000;">
											<i data-tw-merge="" data-lucide="download" class="mr-2 h-4 w-4 stroke-[1.3]"></i>
											Descargar PDF
										</a>
										<a href="{{ $exportRoutes['xlsx'] ?? '#' }}{{ request()->getQueryString() ? ('?' . request()->getQueryString()) : '' }}" class="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50 transition duration-200 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none" style="border-color:#000000;color:#000000;">
											<i data-tw-merge="" data-lucide="download" class="mr-2 h-4 w-4 stroke-[1.3]"></i>
											Descargar XLSX
										</a>
									@endif
									<button type="submit" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 bg-primary border-primary text-white">
										Aplicar
									</button>
									<a href="{{ url()->current() }}" data-list-clear="true" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed border-secondary text-slate-500 dark:border-darkmode-100/40 dark:text-slate-300 [&:hover:not(:disabled)]:bg-secondary/20 [&:hover:not(:disabled)]:dark:bg-darkmode-100/10 w-full sm:w-auto">
										Limpiar
									</a>
								</div>
							</div>

							<div class="almacen-filters-track pl-1">

								@foreach($filters as $filter)
									@php
										$filterName = $filter['name'] ?? '';
										$filterLabel = $filter['label'] ?? 'Filtro';
										$filterType = $filter['type'] ?? 'select';
										$filterOptions = $filter['options'] ?? [];
										$filterPlaceholder = $filter['placeholder'] ?? 'Todos';
										$filterValue = (string) request($filterName, '');
									@endphp
									@continue($filterName === '')

									<div class="almacen-filter-item {{ $filterType === 'select' ? 'almacen-filter-item--tom' : '' }}">
										<label class="almacen-filter-label">{{ $filterLabel }}</label>
										@if($filterType === 'text')
											<input type="text" name="{{ $filterName }}" value="{{ $filterValue }}" placeholder="{{ $filterPlaceholder }}" class="almacen-filter-control">
										@else
											<select name="{{ $filterName }}" class="almacen-filter-control almacen-filter-control--select tom-select tom-select--compact" data-placeholder="{{ $filterPlaceholder }}">
												<option value="">{{ $filterPlaceholder }}</option>
												@foreach($filterOptions as $option)
													@php
														$optionValue = (string) ($option['value'] ?? '');
														$optionLabel = (string) ($option['label'] ?? $optionValue);
													@endphp
													<option value="{{ $optionValue }}" @selected($filterValue === $optionValue)>{{ $optionLabel }}</option>
												@endforeach
											</select>
										@endif
									</div>
								@endforeach
							</div>
						</form>
					</div>

					<div class="overflow-auto xl:overflow-visible">
						<table data-tw-merge="" class="w-full text-left border-b border-slate-200/60 table-fixed">
							<thead data-tw-merge="">
								<tr data-tw-merge="">
									@foreach($columns as $column)
										<td data-tw-merge="" class="px-5 border-b dark:border-darkmode-300 border-t border-slate-200/60 bg-slate-50 py-4 font-medium text-slate-500 @if(!empty($column['wrap'] ?? false)) w-[38%] @endif">
											<span class="block">{{ $column['label'] }}</span>
										</td>
									@endforeach
									@if(($showActionsColumn ?? true) && $canPerformActions)
										<td data-tw-merge="" class="px-5 text-center align-middle border-b dark:border-darkmode-300 border-t border-slate-200/60 bg-slate-50 py-4 font-medium text-slate-500">
											Acciones
										</td>
									@endif
								</tr>
							</thead>
							<tbody>
								@forelse($items as $row)
									<tr data-tw-merge="" class="[&_td]:last:border-b-0">
										@foreach($columns as $column)
											<td data-tw-merge="" class="px-5 border-b dark:border-darkmode-300 border-dashed py-4 dark:bg-darkmode-600 @if(!empty($column['wrap'] ?? false)) align-top w-[38%] @endif">
												@switch($column['type'] ?? 'text')
													@case('custom')
														@php
															$rawCustomValue = data_get($row, $column['key']);
															$customDisplayValue = is_string($rawCustomValue) ? preg_replace('/^\s*\d+\s*-\s*/', '', $rawCustomValue) : $rawCustomValue;
														@endphp
														{!! $customDisplayValue ?? '-' !!}
													@break
													@default
														@php
															$canLinkToEdit = $loop->first && !empty($editRoute) && $canEdit && data_get($row, $identifierKey ?? 'id') !== null;
															$rawCellValue = data_get($row, $column['key']);
															if ($loop->first) {
																$cellValue = (string) $rawCellValue;
															} else {
																$cellValue = is_string($rawCellValue) ? preg_replace('/^\s*\d+\s*-\s*/', '', $rawCellValue) : $rawCellValue;
																$cellValue = $cellValue ?? '-';
															}
															$isEmpresaCol = (($column['key'] ?? '') === 'empresa_label');
														@endphp

														@if($isEmpresaCol)
															<div class="flex min-w-0 flex-nowrap items-center gap-3 whitespace-nowrap">
																@if(!empty(data_get($row, 'imagen')))
																	<img src="{{ asset('storage/' . data_get($row, 'imagen')) }}" alt="Imagen" class="h-16 w-16 rounded-md object-cover">
																@else
																	<div class="flex flex-col items-center justify-center h-16 w-16 rounded-md bg-slate-100 text-slate-400 text-xs">
																		<i data-lucide="x-square" class="h-5 w-5"></i>
																		<span class="mt-1 text-[10px]">No Foto</span>
																	</div>
																@endif
																@if($canLinkToEdit)
																	<a href="{{ route($editRoute, [data_get($row, $identifierKey ?? 'id')]) }}" class="almacen-cell-text font-medium text-slate-700 hover:text-primary hover:underline  whitespace-normal break-words leading-5 " title="{{ $cellValue }}">
																		{{ $cellValue }}
																	</a>
																@else
																	<span class="almacen-cell-text min-w-0 flex-1 overflow-hidden text-ellipsis whitespace-nowrap block font-medium" title="{{ $cellValue }}">{{ $cellValue }}</span>
																@endif
															</div>
														@else
															@if($canLinkToEdit)
																<a href="{{ route($editRoute, [data_get($row, $identifierKey ?? 'id')]) }}" class="almacen-cell-text block w-full min-w-0 max-w-full overflow-hidden text-ellipsis whitespace-nowrap font-medium text-slate-700 hover:text-slate-900 hover:underline" title="{{ $cellValue }}">
																	{{ $cellValue }}
																</a>
															@else
															<span class="almacen-cell-text block w-full min-w-0 max-w-full overflow-hidden text-ellipsis whitespace-nowrap font-medium" title="{{ $cellValue }}">{{ $cellValue }}</span>
															@endif
														@endif
												@endswitch
											</td>
										@endforeach
										@if(($showActionsColumn ?? true) && $canPerformActions)
											<td data-tw-merge="" class="px-5 border-b dark:border-darkmode-300 relative border-dashed py-4 dark:bg-darkmode-600">
												<div class="flex items-center justify-center h-full">
													@php
														$canEditRoute = $canEdit && !empty($editRoute);
														$canDeleteRoute = $canDelete && !empty($destroyRoute);
													@endphp
													@if($canEditRoute || $canDeleteRoute)
														<div data-tw-merge="" data-tw-placement="bottom-end" class="dropdown dropdown--action relative h-5">
															<button type="button" data-local-dropdown-toggle="true" aria-expanded="false" class="almacen-action-toggle cursor-pointer h-6 w-6 text-slate-500" aria-label="Abrir acciones">
																<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" data-lucide="more-vertical" class="lucide lucide-more-vertical stroke-[1] w-5 h-5 fill-slate-400/70 stroke-slate-400/70"><circle cx="12" cy="12" r="1"></circle><circle cx="12" cy="5" r="1"></circle><circle cx="12" cy="19" r="1"></circle></svg>
															</button>
															<div class="dropdown-menu absolute right-0 top-full z-[9999] mt-2 origin-top-right invisible opacity-0 pointer-events-none hidden">
																<div data-tw-merge="" class="dropdown-content rounded-md border border-slate-200/80 bg-white p-2 shadow-xl shadow-slate-200/70 dark:border-transparent dark:bg-darkmode-600">
																	@if($canEditRoute)
																		<a href="{{ route($editRoute, [data_get($row, $identifierKey ?? 'id')]) }}" class="cursor-pointer flex items-center p-2 transition duration-300 ease-in-out rounded-md hover:bg-slate-200/60 dark:bg-darkmode-600 dark:hover:bg-darkmode-400 dropdown-item">
																			<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" data-lucide="check-square" class="lucide lucide-check-square stroke-[1] mr-2 h-4 w-4"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
																			Editar
																		</a>
																	@endif
																	@if($canDeleteRoute)
																		<form method="POST" action="{{ route($destroyRoute, [data_get($row, $identifierKey ?? 'id')]) }}" class="inline delete-confirmation-form" data-relation-resource="{{ $listResource ?? '' }}" data-relation-record-id="{{ data_get($row, $identifierKey ?? 'id') }}">
																			@csrf
																			@method('DELETE')
																			<button type="button" data-delete-open="true" class="cursor-pointer flex items-center p-2 transition duration-300 ease-in-out rounded-md hover:bg-slate-200/60 dark:bg-darkmode-600 dark:hover:bg-darkmode-400 dropdown-item text-danger w-full text-left">
																				<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" data-lucide="trash2" class="lucide lucide-trash2 stroke-[1] mr-2 h-4 w-4"><path d="M3 6h18"></path><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path><line x1="10" x2="10" y1="11" y2="17"></line><line x1="14" x2="14" y1="11" y2="17"></line></svg>
																				Eliminar
																			</button>
																		</form>
																	@endif
																</div>
															</div>
														</div>
													@endif
												</div>
											</td>
										@endif
									</tr>
								@empty
									<tr>
										<td colspan="{{ count($columns) + (($showActionsColumn ?? true) ? 1 : 0) }}" class="px-5 py-10 text-center text-slate-500">
											No hay registros.
										</td>
									</tr>
								@endforelse
							</tbody>
						</table>
					</div>

					@if($items instanceof \Illuminate\Contracts\Pagination\Paginator)
						<div class="flex-reverse flex flex-col-reverse flex-wrap items-center gap-y-2 p-5 sm:flex-row">
							<div class="mr-auto w-full flex-1 sm:w-auto">
								{{ $items->onEachSide(1)->links('layouts.pagination') }}
							</div>
							<select data-tw-merge="" name="perPage" id="list-per-page" class="transition duration-200 ease-in-out w-full text-sm border-slate-200 shadow-sm py-2 px-3 pr-8 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 group-[.form-inline]:flex-1 rounded-[0.5rem] sm:w-20">
								@foreach([10, 25, 50, 100] as $limit)
									<option value="{{ $limit }}" @if(request('perPage', 10) == $limit) selected @endif>{{ $limit }}</option>
								@endforeach
							</select>
						</div>
					@endif
				</div>
			</div>
		</div>
	</div>

	<div id="delete-confirmation-modal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:9999;background:rgba(0,0,0,0.8);align-items:center;justify-content:center;" role="dialog" aria-modal="true" aria-labelledby="delete-confirmation-title" aria-describedby="delete-confirmation-message">
		<div style="width:720px;max-width:92%;margin:0 auto;position:relative;border-radius:10px;background:#ffffff;box-shadow:0 20px 40px rgba(2,6,23,0.12);overflow:hidden;">
			<button type="button" data-delete-modal-close style="position:absolute;right:16px;top:16px;height:44px;width:44px;border-radius:9999px;border:1px solid #e6e9ee;background:#fff;color:#6b7280;display:inline-flex;align-items:center;justify-content:center;" aria-label="Cerrar">
				<span aria-hidden="true">x</span>
			</button>
			<div style="padding:40px 48px;text-align:left;">
				<div style="margin:0 auto 24px;display:flex;height:64px;width:64px;align-items:center;justify-content:center;border-radius:9999px;border:1px solid #ef4444;background:#fff7f7;color:#ef4444;">
					<span aria-hidden="true" style="font-size:20px;line-height:1;">!</span>
				</div>
				<h2 id="delete-confirmation-title" style="font-size:22px;font-weight:600;margin:0;color:#111827;">¿Estas seguro?</h2>
				<p id="delete-confirmation-message" style="margin-top:12px;color:#6b7280;font-size:14px;line-height:1.6;">Esta accion eliminara el registro y no se podra deshacer.</p>
				<div id="delete-confirmation-details" class="mt-5 hidden rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900"></div>
				<div id="delete-confirmation-relations" class="mt-5 hidden rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" style="background-color: #ffe7e7;"></div>
				<div id="delete-confirmation-hint" class="mt-3 hidden text-sm text-slate-600"></div>
				<div style="margin-top:26px;display:flex;gap:12px;justify-content:flex-end;flex-wrap:wrap;align-items:center;">
					<div id="delete-confirmation-actions" class="hidden flex flex-wrap gap-3 mr-auto"></div>
					<button type="button" data-delete-modal-close style="min-width:120px;padding:10px 18px;border-radius:10px;border:1px solid #000000;background:#ffffff;color:#374151;font-weight:600;">Cancelar</button>
					<button type="button" id="delete-confirmation-submit" style="min-width:120px;padding:10px 18px;border-radius:10px;background: #7c1010;color:#ffffff;font-weight:600;border:none;">Eliminar</button>
				</div>
			</div>
		</div>
	</div>

	<script>
		(function () {
			const listWrapperId = 'list-table-wrapper';
			const formId = 'list-filter-form';
			let form;
			let wrapper;
			let searchInput;
			let searchClearBtn;
			let debounceTimer;
			let hasBoundOutsideDropdownClose = false;
			let hasBoundEscapeDropdownClose = false;
			let fetchController = null;
			let fetchRequestId = 0;
			let deleteConfirmationModal = null;
			let deleteConfirmationTitle = null;
			let deleteConfirmationMessage = null;
			let deleteConfirmationSubmit = null;
			let deleteConfirmationDetails = null;
			let deleteConfirmationRelations = null;
			let deleteConfirmationHint = null;
			let deleteConfirmationActions = null;
			let relationSummaryTemplate = null;
			let activeDeleteForm = null;
			let activeDeleteMode = '';
			let relationSummaryCache = new Map();
			let autoRefreshTimer = null;

			const getWrapper = () => document.getElementById(listWrapperId);
			const getForm = () => document.getElementById(formId);
			const getSearchInput = () => form ? form.querySelector('[name="q"]') : null;
			const getPageSizeElement = () => (wrapper ? wrapper.querySelector('[name="perPage"]') : null);

			const getRequestParams = () => {
				const formData = new FormData(form);
				const params = new URLSearchParams();
				const dateIsoValues = {};

				for (const [key, value] of formData.entries()) {
					if (key.endsWith('_iso')) {
						const visibleKey = key.slice(0, -4);
						if (String(value).trim() !== '') {
							dateIsoValues[visibleKey] = value;
						}
						continue;
					}
					params.append(key, value);
				}

				Object.entries(dateIsoValues).forEach(([visibleKey, value]) => {
					if (String(value).trim() !== '') {
						params.set(visibleKey, value);
					}
				});

				for (const [key, value] of Array.from(params.entries())) {
					if (value === null || String(value).trim() === '') {
						params.delete(key);
					}
				}

				return params;
			};

			const buildUrl = () => {
				const params = getRequestParams();
				const pageSizeElement = getPageSizeElement();
				if (pageSizeElement && pageSizeElement.value) {
					params.set('perPage', pageSizeElement.value);
				}
				const url = new URL(form.action, window.location.origin);
				url.search = params.toString();
				return url.toString();
			};

			const restoreIcons = () => {
				try {
					if (typeof createIcons === 'function' && typeof icons !== 'undefined') {
						createIcons({ icons, attrs: { 'stroke-width': 1.5 }, nameAttr: 'data-lucide' });
						return;
					}
					if (window.lucide && typeof window.lucide.createIcons === 'function') {
						if (window.lucide.icons) {
							window.lucide.createIcons({ icons: window.lucide.icons, attrs: { 'stroke-width': 1.5 }, nameAttr: 'data-lucide' });
							return;
						}
						if (typeof icons !== 'undefined') {
							window.lucide.createIcons({ icons, attrs: { 'stroke-width': 1.5 }, nameAttr: 'data-lucide' });
						}
					}
				} catch (error) {
					console.warn('restoreIcons failed:', error);
				}
			};

			const detachMenuReposition = (menu) => {
				const handler = menu._repositionHandler;
				if (!handler) return;

				if (window.visualViewport) {
					window.visualViewport.removeEventListener('resize', handler);
					window.visualViewport.removeEventListener('scroll', handler);
				}
				window.removeEventListener('resize', handler);
				window.removeEventListener('scroll', handler, true);
				if (menu._repositionRaf) cancelAnimationFrame(menu._repositionRaf);
				delete menu._repositionHandler;
				delete menu._repositionRaf;
			};

			const closeLocalDropdowns = (exceptDropdown = null) => {
				if (!wrapper) {
					return;
				}
				wrapper.querySelectorAll('.dropdown').forEach((dropdown) => {
					if (exceptDropdown && dropdown === exceptDropdown) {
						return;
					}
					const toggle = dropdown.querySelector('[data-local-dropdown-toggle="true"]');
					const menu = dropdown.querySelector('.dropdown-menu');
					if (menu) {
						detachMenuReposition(menu);
						menu.classList.add('hidden', 'invisible', 'opacity-0', 'pointer-events-none');
						menu.classList.remove('visible', 'opacity-100', 'pointer-events-auto', 'show');

						if (menu.dataset.prevPosition !== undefined) {
							menu.style.position = menu.dataset.prevPosition || '';
							menu.style.left = menu.dataset.prevLeft || '';
							menu.style.right = menu.dataset.prevRight || '';
							menu.style.top = menu.dataset.prevTop || '';
							menu.style.zIndex = menu.dataset.prevZIndex || '';
							menu.style.minWidth = menu.dataset.prevMinWidth || '';
							menu.style.width = menu.dataset.prevWidth || '';
							menu.style.maxWidth = menu.dataset.prevMaxWidth || '';
							delete menu.dataset.prevPosition;
							delete menu.dataset.prevLeft;
							delete menu.dataset.prevRight;
							delete menu.dataset.prevTop;
							delete menu.dataset.prevZIndex;
							delete menu.dataset.prevMinWidth;
							delete menu.dataset.prevWidth;
							delete menu.dataset.prevMaxWidth;
							delete menu.dataset.portal;
						}
					}
					if (toggle) {
						toggle.setAttribute('aria-expanded', 'false');
					}
				});
			};

			const positionMenu = (menu, toggle) => {
				const rect = toggle.getBoundingClientRect();
				const menuWidth = menu.offsetWidth;
				let left = rect.right - menuWidth;

				const viewportMargin = 12;
				if (left + menuWidth > window.innerWidth - viewportMargin) {
					left = Math.max(viewportMargin, window.innerWidth - menuWidth - viewportMargin);
				}
				if (left < viewportMargin) left = viewportMargin;

				const top = rect.bottom + 8;
				menu.style.left = `${Math.round(left)}px`;
				menu.style.top = `${Math.round(top)}px`;
			};

			const attachMenuReposition = (menu, toggle) => {
				const handler = () => {
					if (menu.dataset.portal !== 'true') return;
					if (menu._repositionRaf) cancelAnimationFrame(menu._repositionRaf);
					menu._repositionRaf = requestAnimationFrame(() => {
						positionMenu(menu, toggle);
					});
				};

				menu._repositionHandler = handler;
				if (window.visualViewport) {
					window.visualViewport.addEventListener('resize', handler);
					window.visualViewport.addEventListener('scroll', handler);
				}
				window.addEventListener('resize', handler);
				window.addEventListener('scroll', handler, true);
			};

			const initDropdowns = () => {
				if (!wrapper) {
					return;
				}

				wrapper.querySelectorAll('.dropdown [data-local-dropdown-toggle="true"]').forEach((toggle) => {
					toggle.onclick = (event) => {
						event.preventDefault();
						event.stopPropagation();

						const dropdown = toggle.closest('.dropdown');
						if (!dropdown) {
							return;
						}

						const menu = dropdown.querySelector('.dropdown-menu');
						if (!menu) {
							return;
						}

						const isOpen = toggle.getAttribute('aria-expanded') === 'true'
							|| (menu.classList.contains('show') && !menu.classList.contains('hidden'));
						closeLocalDropdowns(dropdown);

						if (!isOpen) {
							toggle.setAttribute('aria-expanded', 'true');

							menu.dataset.prevPosition = menu.style.position || '';
							menu.dataset.prevLeft = menu.style.left || '';
							menu.dataset.prevRight = menu.style.right || '';
							menu.dataset.prevTop = menu.style.top || '';
							menu.dataset.prevZIndex = menu.style.zIndex || '';
							menu.dataset.prevMinWidth = menu.style.minWidth || '';
							menu.dataset.prevWidth = menu.style.width || '';
							menu.dataset.prevMaxWidth = menu.style.maxWidth || '';
							menu.dataset.portal = 'true';

							menu.style.position = 'fixed';
							menu.style.left = '-9999px';
							menu.style.top = '-9999px';
							menu.style.zIndex = '9999';
							menu.style.width = 'auto';
							menu.style.maxWidth = '90vw';
							menu.style.visibility = 'hidden';
							menu.style.pointerEvents = 'none';
							menu.classList.remove('hidden');

							requestAnimationFrame(() => {
								try {
									const rect = toggle.getBoundingClientRect();
									const naturalWidth = Math.ceil(menu.scrollWidth || menu.offsetWidth || 120);
									const buttonWidth = Math.ceil(rect.width || toggle.offsetWidth || 0);
									const minActionWidth = 100;
									const maxActionWidth = 140;
									let desiredWidth = Math.max(buttonWidth, minActionWidth);
									desiredWidth = Math.min(desiredWidth, maxActionWidth);
									desiredWidth = Math.max(desiredWidth, Math.min(naturalWidth + 8, maxActionWidth));

									menu.style.minWidth = desiredWidth + 'px';
									menu.style.right = 'auto';
									const inner = menu.querySelector('.dropdown-content');
									if (inner) {
										inner.style.boxSizing = 'border-box';
										inner.style.width = desiredWidth + 'px';
										inner.style.minWidth = desiredWidth + 'px';
										inner.style.maxWidth = desiredWidth + 'px';
										inner.style.padding = '6px';
									}

									positionMenu(menu, toggle);

									menu.style.visibility = '';
									menu.style.pointerEvents = '';
									menu.style.transition = 'opacity 150ms ease-out';
									menu.style.transform = 'none';

									menu.classList.remove('invisible', 'opacity-0', 'pointer-events-none');
									menu.classList.add('visible', 'opacity-100', 'pointer-events-auto', 'show');
									attachMenuReposition(menu, toggle);
								} catch (error) {
									console.warn('Dropdown portal positioning failed', error);
								}
							});
						}
					};
				});

				if (!hasBoundOutsideDropdownClose) {
					document.addEventListener('click', (event) => {
						if (!wrapper) {
							return;
						}
						if (event.target.closest(`#${listWrapperId} .dropdown`)) {
							return;
						}
						closeLocalDropdowns();
					});
					hasBoundOutsideDropdownClose = true;
				}

				if (!hasBoundEscapeDropdownClose) {
					document.addEventListener('keydown', (event) => {
						if (event.key === 'Escape') {
							closeLocalDropdowns();
						}
					});
					hasBoundEscapeDropdownClose = true;
				}
			};

			const syncTomSelectControlWidth = (instance) => {
				if (!instance) return;
				const stableWidth = instance?.input?.dataset?.tomselectBaseWidth;
				if (!stableWidth) return;
				if (instance.wrapper) {
					instance.wrapper.style.width = stableWidth;
					instance.wrapper.style.minWidth = stableWidth;
					instance.wrapper.style.maxWidth = stableWidth;
					instance.wrapper.style.boxSizing = 'border-box';
				}
				if (instance.control) {
					instance.control.style.width = stableWidth;
					instance.control.style.minWidth = stableWidth;
					instance.control.style.maxWidth = stableWidth;
					instance.control.style.boxSizing = 'border-box';
				}
			};

			const resetTomSelectDropdown = (dropdown) => {
				if (!dropdown) return;
				dropdown.style.position = '';
				dropdown.style.top = '';
				dropdown.style.left = '';
				dropdown.style.right = '';
				dropdown.style.bottom = '';
				dropdown.style.width = '';
				dropdown.style.minWidth = '';
				dropdown.style.maxWidth = '';
				dropdown.style.marginTop = '';
				dropdown.style.display = '';
				dropdown.classList.remove('ts-dropdown-portal');
			};

			const portalTomSelectDropdown = (instance) => {
				if (!instance || !instance.dropdown) return;
				if (instance.settings.dropdownParent !== 'body') return;
				const dropdown = instance.dropdown;
				if (dropdown.dataset.originalParent === undefined && dropdown.parentNode) {
					dropdown.dataset.originalParent = '';
				}
				if (dropdown.parentNode !== document.body) {
					dropdown.dataset.originalParent = dropdown.parentNode ? '1' : '';
					document.body.appendChild(dropdown);
				}
				dropdown.classList.add('ts-dropdown-portal');
				// Mark portal for cleanup by our code
				try { dropdown.dataset.erpTomselect = '1'; } catch (e) {}
			};

			const cleanupTomSelectPortals = () => {
				try {
					document.querySelectorAll('.ts-dropdown[data-erp-tomselect="1"]').forEach((el) => {
						if (el && el.parentNode) {
							el.parentNode.removeChild(el);
						}
					});
				} catch (e) {
					console.warn('cleanupTomSelectPortals failed', e);
				}
			};

			const positionTomSelectDropdown = (instance) => {
				if (!instance || !instance.dropdown || !instance.control) return;
				const dropdown = instance.dropdown;
				const control = instance.control;
				const rect = control.getBoundingClientRect();
				const dropdownHeight = dropdown.offsetHeight || dropdown.scrollHeight || 0;
				const spaceBelow = window.innerHeight - rect.bottom;
				const spaceAbove = rect.top;
				const expectedHeight = Math.max(dropdownHeight, 220);
				const openUp = spaceBelow < expectedHeight && spaceAbove > spaceBelow;
				resetTomSelectDropdown(dropdown);
				if (instance.settings.dropdownParent === 'body') {
					const gap = 6;
					dropdown.style.position = 'fixed';
					dropdown.style.left = `${Math.round(rect.left)}px`;
					dropdown.style.width = `${Math.round(rect.width)}px`;
					dropdown.style.maxWidth = `${Math.round(rect.width)}px`;
					dropdown.style.marginTop = '0';
					if (openUp) {
						dropdown.style.top = `${Math.max(Math.round(rect.top - dropdownHeight - gap), 6)}px`;
					} else {
						dropdown.style.top = `${Math.round(rect.bottom + gap)}px`;
					}
					return;
				}
				if (openUp) {
					dropdown.style.top = 'auto';
					dropdown.style.bottom = '100%';
					dropdown.style.marginTop = '0';
					dropdown.style.marginBottom = '0.25rem';
				}
			};

			const initTomSelectFilters = () => {
				if (typeof window.TomSelect !== 'function') return;
				// remove any leftover portaled dropdowns from previous renders
				cleanupTomSelectPortals();
				document.querySelectorAll('select.almacen-filter-control--select').forEach((select) => {
					if (select.tomselect || select.tomSelect || select._tomselect) {
						try {
							const prev = (select.tomselect || select.tomSelect || select._tomselect);
							try { prev.destroy(); } catch (e) { /* ignore */ }
							// remove prev dropdown if still in body
							try {
								if (prev && prev.dropdown && prev.dropdown.parentNode === document.body) {
									prev.dropdown.parentNode.removeChild(prev.dropdown);
								}
							} catch (er) {}
						} catch (error) {
							console.warn('TomSelect destroy failed:', error);
						}
					}

					const baseWidth = Math.ceil(select.getBoundingClientRect().width || select.offsetWidth || select.parentElement?.getBoundingClientRect().width || select.parentElement?.offsetWidth || 0);
					if (baseWidth > 0) {
						select.dataset.tomselectBaseWidth = `${baseWidth}px`;
					}

					const dropdownParent = select.closest('.almacen-filter-item') || wrapper;

					const instance = new TomSelect(select, {
						width: '100%',
						allowEmptyOption: true,
						create: false,
						maxOptions: 100,
						placeholder: select.getAttribute('data-placeholder') || 'Selecciona una opción',
						dropdownParent,
						closeAfterSelect: true,
						hidePlaceholder: true,
						openOnFocus: true,
						plugins: { dropdown_input: {} }
					});

					// mark dropdown for cleanup and stable handling
					try {
						if (instance && instance.dropdown) {
							instance.dropdown.dataset.erpTomselect = '1';
						}
					} catch (e) {}

					if (instance && instance.wrapper) {
						instance.wrapper.style.width = select.dataset.tomselectBaseWidth || '100%';
						instance.wrapper.style.maxWidth = select.dataset.tomselectBaseWidth || '100%';
						instance.wrapper.style.minWidth = select.dataset.tomselectBaseWidth || '0';
						instance.wrapper.style.boxSizing = 'border-box';
					}

					if (instance && instance.control) {
						instance.control.style.width = select.dataset.tomselectBaseWidth || '100%';
						instance.control.style.maxWidth = select.dataset.tomselectBaseWidth || '100%';
						instance.control.style.minWidth = select.dataset.tomselectBaseWidth || '0';
						instance.control.style.boxSizing = 'border-box';
					}

					syncTomSelectControlWidth(instance);

					if (instance && typeof instance.on === 'function') {
						instance.on('dropdown_open', () => {
							syncTomSelectControlWidth(instance);
							if (instance.settings.dropdownParent === 'body') {
								portalTomSelectDropdown(instance);
								requestAnimationFrame(() => positionTomSelectDropdown(instance));
							}
						});
						instance.on('dropdown_close', () => {
							resetTomSelectDropdown(instance.dropdown);
							syncTomSelectControlWidth(instance);
						});
					}
				});
			};

			const replaceWrapper = async (html) => {
				closeLocalDropdowns();
				const parser = new DOMParser();
				const doc = parser.parseFromString(html, 'text/html');
				const nextWrapper = doc.getElementById(listWrapperId);
				if (!nextWrapper) {
					return;
				}
				// remove any TomSelect portal dropdowns created by previous instances
				try { cleanupTomSelectPortals(); } catch (e) {}
				wrapper.innerHTML = nextWrapper.innerHTML;
				restoreIcons();
				requestAnimationFrame(() => restoreIcons());
				if (window.initLitepickers && typeof window.initLitepickers === 'function') {
					window.initLitepickers(wrapper);
				}
				init();
			};

			const fetchList = async (url, options = {}) => {
				const shouldRestoreSearchFocus = Boolean(options.preserveSearchFocus && searchInput && document.activeElement === searchInput);
				const caretStart = shouldRestoreSearchFocus ? searchInput.selectionStart : null;
				const caretEnd = shouldRestoreSearchFocus ? searchInput.selectionEnd : null;
				const requestId = ++fetchRequestId;

				if (fetchController) {
					fetchController.abort();
				}
				const controller = new AbortController();
				fetchController = controller;

				closeLocalDropdowns();
				try {
					const response = await fetch(url, {
						headers: {
							'X-Requested-With': 'XMLHttpRequest',
						},
						signal: controller.signal,
					});
					if (!response.ok) {
						return;
					}
					const html = await response.text();
					if (requestId !== fetchRequestId) {
						return;
					}
					await replaceWrapper(html);

					if (shouldRestoreSearchFocus && searchInput) {
						searchInput.focus({ preventScroll: true });
						if (caretStart !== null && caretEnd !== null && typeof searchInput.setSelectionRange === 'function') {
							searchInput.setSelectionRange(caretStart, caretEnd);
						}
					}
				} catch (error) {
					if (error && error.name === 'AbortError') {
						return;
					}
					console.error('Error cargando el listado:', error);
				} finally {
					if (fetchController === controller) {
						fetchController = null;
					}
				}
			};

			const handleSubmit = (event) => {
				event.preventDefault();
				const url = buildUrl();
				fetchList(url);
			};

			const updateSearchClearVisibility = () => {
				if (!searchInput || !searchClearBtn) {
					return;
				}
				const value = String(searchInput.value || '').trim();
				searchClearBtn.style.display = value === '' ? 'none' : 'flex';
			};

			const handleSearchInput = () => {
				updateSearchClearVisibility();
				clearTimeout(debounceTimer);
				debounceTimer = setTimeout(() => {
					const url = buildUrl();
					fetchList(url, { preserveSearchFocus: true });
				}, 350);
			};

			const clearFilterInputs = () => {
				if (!form) {
					return;
				}

				form.querySelectorAll('input[name], select[name], textarea[name]').forEach((field) => {
					const fieldName = (field.getAttribute('name') || '').trim();
					if (fieldName === '') {
						return;
					}

					if (fieldName === 'perPage') {
						return;
					}

					if (field.tagName === 'SELECT') {
						field.selectedIndex = 0;
						return;
					}

					if (field.type === 'checkbox' || field.type === 'radio') {
						field.checked = false;
						return;
					}

					field.value = '';
				});
			};

			const handleClear = (event) => {
				event.preventDefault();
				clearFilterInputs();
				updateSearchClearVisibility();
				const url = new URL(form.action, window.location.origin).toString();
				fetchList(url);
			};

			const handleSearchClear = (event) => {
				event.preventDefault();
				if (!form) {
					return;
				}
				const q = form.querySelector('[name="q"]');
				if (!q) {
					return;
				}
				q.value = '';
				updateSearchClearVisibility();
				const url = buildUrl();
				fetchList(url);
			};

			const attachPaginationLinks = () => {
				if (!wrapper) {
					return;
				}
				wrapper.querySelectorAll('nav a[href]').forEach((link) => {
					const href = link.getAttribute('href');
					if (!href || href === 'javascript:;' || href.startsWith('#')) {
						return;
					}
					link.addEventListener('click', (event) => {
						event.preventDefault();
						const pageUrl = event.currentTarget.href;
						fetchList(pageUrl);
					});
				});
			};

			const handlePageSizeChange = () => {
				fetchList(buildUrl());
			};

			window.ERPListRefresh = () => {
				if (!form || !wrapper) {
					init();
				}

				if (!form || !wrapper) {
					return;
				}

				fetchList(buildUrl());
			};

			const startAutoRefresh = () => {
				if (autoRefreshTimer) {
					return;
				}
				
			};

			const stopAutoRefresh = () => {
				if (!autoRefreshTimer) {
					return;
				}
				clearInterval(autoRefreshTimer);
				autoRefreshTimer = null;
			};

			const buildRelationSummaryUrl = (resource, id) => {
				if (!relationSummaryTemplate || !resource || !id) {
					return null;
				}

				return relationSummaryTemplate.replace('__RESOURCE__', encodeURIComponent(resource)).replace('__ID__', encodeURIComponent(id));
			};

			const resetDeleteConfirmationContent = () => {
				if (deleteConfirmationTitle) {
					deleteConfirmationTitle.textContent = '¿Estas seguro?';
				}

				if (deleteConfirmationMessage) {
					deleteConfirmationMessage.textContent = 'Esta accion eliminara el registro y no se podra deshacer.';
				}

				if (deleteConfirmationDetails) {
					deleteConfirmationDetails.innerHTML = '';
					deleteConfirmationDetails.classList.add('hidden');
				}

				if (deleteConfirmationRelations) {
					deleteConfirmationRelations.innerHTML = '';
					deleteConfirmationRelations.classList.add('hidden');
				}

				if (deleteConfirmationHint) {
					deleteConfirmationHint.innerHTML = '';
					deleteConfirmationHint.classList.add('hidden');
				}

				if (deleteConfirmationActions) {
					deleteConfirmationActions.innerHTML = '';
					deleteConfirmationActions.classList.add('hidden');
				}

				if (deleteConfirmationSubmit) {
					deleteConfirmationSubmit.textContent = 'Eliminar';
					deleteConfirmationSubmit.style.background = '#ef4444';
					deleteConfirmationSubmit.disabled = false;
				}

				activeDeleteMode = '';
			};

			const closeDeleteConfirmation = () => {
				if (!deleteConfirmationModal) {
					return;
				}
				deleteConfirmationModal.style.display = 'none';
				document.body.style.overflow = '';
				activeDeleteForm = null;
				resetDeleteConfirmationContent();
			};

			const renderRelationItems = (relation) => {
				const records = Array.isArray(relation.records) ? relation.records.filter(Boolean) : [];
				const extraCount = Math.max((Number(relation.count || 0) - records.length), 0);
				const relatedList = records.length > 0 ? records.join(', ') : 'sin detalle adicional';
				const suffix = extraCount > 0 ? ` y otros ${extraCount} mas` : '';

				return `Este registro esta relacionado con ${relation.count} ${relation.label}${relation.count === 1 ? '' : 'es'}: ${relatedList}${suffix}.`;
			};

			const renderDeleteConfirmation = (summary) => {
				if (!deleteConfirmationTitle || !deleteConfirmationMessage || !deleteConfirmationRelations || !deleteConfirmationHint || !deleteConfirmationSubmit || !deleteConfirmationDetails || !deleteConfirmationActions) {
					return;
				}

				const recordLabel = summary?.recordLabel || summary?.recordId || 'este registro';
				const relations = Array.isArray(summary?.relations) ? summary.relations : [];
				const details = Array.isArray(summary?.details) ? summary.details : [];
				const deleteActions = Array.isArray(summary?.deleteActions) ? summary.deleteActions : [];

				deleteConfirmationTitle.textContent = '¿Estas seguro?';
				deleteConfirmationMessage.textContent = `Vas a eliminar "${recordLabel}". Si continuas, el sistema validara la integridad antes de borrar.`;

				deleteConfirmationDetails.innerHTML = '';
				if (details.length > 0) {
					const detailsList = document.createElement('div');
					detailsList.className = 'space-y-2';
					details.forEach((detail) => {
						const row = document.createElement('div');
						row.className = 'text-sm text-slate-700';
						row.innerHTML = `<span class="font-semibold text-slate-900">${detail.label}:</span> ${detail.value}`;
						detailsList.appendChild(row);
					});
					deleteConfirmationDetails.appendChild(detailsList);
					deleteConfirmationDetails.classList.remove('hidden');
				}

				deleteConfirmationRelations.innerHTML = '';
				if (relations.length > 0) {
					const relationList = document.createElement('div');
					relationList.style.display = 'flex';
					relationList.style.flexDirection = 'column';
					relationList.style.gap = '10px';
					relations.forEach((relation) => {
						const block = document.createElement('div');
						block.className = 'rounded-md border border-amber-800 bg-white px-4 py-3';
						block.style.marginBottom = '0';

						const heading = document.createElement('div');
						heading.className = 'font-semibold text-amber-900';
						heading.textContent = `Relacionado con ${relation.label} (${relation.count})`;

						const body = document.createElement('p');
						body.className = 'mt-1 text-sm text-amber-800 leading-6';
						body.textContent = renderRelationItems(relation);

						block.appendChild(heading);
						block.appendChild(body);
						relationList.appendChild(block);
					});

					deleteConfirmationRelations.appendChild(relationList);
					deleteConfirmationRelations.classList.remove('hidden');
				}

				deleteConfirmationHint.textContent = 'No se puede eliminar este registro mientras tenga relaciones activas.';
				deleteConfirmationHint.classList.remove('hidden');

				deleteConfirmationActions.innerHTML = '';
				if (deleteActions.length > 0) {
					const actionWrap = document.createElement('div');
					actionWrap.className = 'flex flex-wrap gap-3';

					deleteActions.forEach((action) => {
						const button = document.createElement('button');
						button.type = 'button';
						button.className = 'rounded-md border px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-red-50';
						button.style.background = 'linear-gradient(90deg, #ef4444, #dc2626)';
						button.textContent = action.label || 'Eliminar con relacion';
						button.onclick = () => {
							activeDeleteMode = action.mode || '';
							if (activeDeleteForm) {
								let input = activeDeleteForm.querySelector('input[name="deleteMode"]');
								if (!(input instanceof HTMLInputElement)) {
									input = document.createElement('input');
									input.type = 'hidden';
									input.name = 'deleteMode';
									activeDeleteForm.appendChild(input);
								}
								input.value = activeDeleteMode;
								activeDeleteForm.submit();
							}
						};
						actionWrap.appendChild(button);
					});

					deleteConfirmationActions.appendChild(actionWrap);
					deleteConfirmationActions.classList.remove('hidden');
				}

				deleteConfirmationSubmit.textContent = 'Eliminar';
				deleteConfirmationSubmit.style.background = '#c71010';
				deleteConfirmationSubmit.disabled = false;
			};

			const openDeleteConfirmation = (formElement, summary = null) => {
				if (!deleteConfirmationModal || !deleteConfirmationMessage) {
					return;
				}
				activeDeleteForm = formElement;
				activeDeleteMode = '';
				renderDeleteConfirmation(summary);
				deleteConfirmationModal.style.display = 'flex';
				deleteConfirmationModal.style.justifyContent = 'center';
				deleteConfirmationModal.style.alignItems = 'center';
				deleteConfirmationModal.style.background = 'rgba(0,0,0,0.8)';
				deleteConfirmationModal.style.zIndex = '9999';
				document.body.style.overflow = 'hidden';
			};

			const loadRelationSummary = async (resource, id) => {
				const cacheKey = `${resource}:${id}`;
				if (relationSummaryCache.has(cacheKey)) {
					return relationSummaryCache.get(cacheKey);
				}

				const url = buildRelationSummaryUrl(resource, id);
				if (!url) {
					const emptySummary = { resource, recordId: id, recordLabel: null, relations: [] };
					relationSummaryCache.set(cacheKey, emptySummary);
					return emptySummary;
				}

				const response = await fetch(url, { headers: { Accept: 'application/json' } });
				const payload = await response.json();
				const summary = response.ok && payload && payload.ok && payload.data ? payload.data : { resource, recordId: id, recordLabel: null, relations: [] };
				relationSummaryCache.set(cacheKey, summary);
				return summary;
			};

			const handleDeleteButtonClick = async (event) => {
				const button = event.target.closest('button[data-delete-open]');
				if (!button || !wrapper || !wrapper.contains(button)) {
					return;
				}
				event.preventDefault();
				const formElement = button.closest('form.delete-confirmation-form');
				if (!formElement) {
					return;
				}
				closeLocalDropdowns();
				const resource = formElement.getAttribute('data-relation-resource') || document.getElementById('erp-list-resource')?.value || '';
				const recordId = formElement.getAttribute('data-relation-record-id') || '';
				const summary = resource && recordId ? await loadRelationSummary(resource, recordId) : { resource, recordId, recordLabel: null, relations: [] };
				openDeleteConfirmation(formElement, summary);
			};

			const initDeleteConfirmation = () => {
				deleteConfirmationModal = document.getElementById('delete-confirmation-modal');
				deleteConfirmationTitle = deleteConfirmationModal ? deleteConfirmationModal.querySelector('#delete-confirmation-title') : null;
				deleteConfirmationMessage = deleteConfirmationModal ? deleteConfirmationModal.querySelector('#delete-confirmation-message') : null;
				deleteConfirmationSubmit = deleteConfirmationModal ? deleteConfirmationModal.querySelector('#delete-confirmation-submit') : null;
				deleteConfirmationDetails = deleteConfirmationModal ? deleteConfirmationModal.querySelector('#delete-confirmation-details') : null;
				deleteConfirmationRelations = deleteConfirmationModal ? deleteConfirmationModal.querySelector('#delete-confirmation-relations') : null;
				deleteConfirmationHint = deleteConfirmationModal ? deleteConfirmationModal.querySelector('#delete-confirmation-hint') : null;
				deleteConfirmationActions = deleteConfirmationModal ? deleteConfirmationModal.querySelector('#delete-confirmation-actions') : null;
				relationSummaryTemplate = document.getElementById('erp-relation-summary-template')?.value || null;

				if (deleteConfirmationModal && deleteConfirmationModal.parentElement !== document.body) {
					document.body.appendChild(deleteConfirmationModal);
				}

				if (deleteConfirmationSubmit) {
					deleteConfirmationSubmit.onclick = () => {
						if (activeDeleteForm && !deleteConfirmationSubmit.disabled) {
							const deleteModeInput = activeDeleteForm.querySelector('input[name="deleteMode"]');
							if (deleteModeInput instanceof HTMLInputElement && activeDeleteMode === '') {
								deleteModeInput.remove();
							}
							activeDeleteForm.submit();
						}
					};
				}

				if (deleteConfirmationModal) {
					deleteConfirmationModal.querySelectorAll('[data-delete-modal-close]').forEach((button) => {
						button.onclick = closeDeleteConfirmation;
					});
				}

				if (wrapper) {
					wrapper.removeEventListener('click', handleDeleteButtonClick);
					wrapper.addEventListener('click', handleDeleteButtonClick);
				}

				resetDeleteConfirmationContent();
			};

			const init = () => {
				form = getForm();
				wrapper = getWrapper();
				if (!form || !wrapper) {
					return;
				}

				restoreIcons();
				initDropdowns();
				if (window.initLitepickers && typeof window.initLitepickers === 'function') {
					window.initLitepickers(document);
				}
				initTomSelectFilters();
				closeLocalDropdowns();
				searchInput = getSearchInput();
				searchClearBtn = form.querySelector('[data-list-clear-search]');

				form.removeEventListener('submit', handleSubmit);
				form.addEventListener('submit', handleSubmit);

				if (searchInput) {
					searchInput.removeEventListener('input', handleSearchInput);
					searchInput.addEventListener('input', handleSearchInput);
				}

				const clearBtn = form.querySelector('[data-list-clear]');
				if (clearBtn) {
					clearBtn.removeEventListener('click', handleClear);
					clearBtn.addEventListener('click', handleClear);
				}

				if (searchClearBtn) {
					searchClearBtn.removeEventListener('click', handleSearchClear);
					searchClearBtn.addEventListener('click', handleSearchClear);
				}

				const pageSizeElement = getPageSizeElement();
				if (pageSizeElement) {
					pageSizeElement.removeEventListener('change', handlePageSizeChange);
					pageSizeElement.addEventListener('change', handlePageSizeChange);
				}

				updateSearchClearVisibility();
				attachPaginationLinks();
				initDeleteConfirmation();
				startAutoRefresh();
			};

			window.addEventListener('beforeunload', stopAutoRefresh);
			document.addEventListener('visibilitychange', () => {
				if (document.visibilityState === 'visible') {
					window.ERPListRefresh();
				}
			});

			init();
		})();
	</script>

	<style>

		#list-table-wrapper table td { 
		max-width: 280px; 
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		}

		#list-table-wrapper .almacen-cell-text {
			display: block;
			min-width: 0;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}

		#list-table-wrapper td .flex .almacen-cell-text {
			flex: 1 1 auto;
			max-width: calc(100% - 4.5rem);
		}

		.dropdown--action .dropdown-content {
			min-width: 80px !important;
			max-width: 120px !important;
			width: auto !important;
			padding: 6px !important;
		}

		.dropdown--action .dropdown-content .dropdown-item {
			padding: 6px 8px !important;
			font-size: 0.95rem !important;
		}

		.almacen-stats-white,
		.almacen-table-white {
			background: #ffffff !important;
			border-radius: .6rem !important;
			box-shadow: none !important;
		}

		.almacen-stats-white {
			border: 1px solid #d9e2ec !important;
			padding: 1rem;
		}

		.almacen-stats-white .box {
			padding: 1.25rem;
		}

		.almacen-stats-white .box .stat-value {
			font-size: 2.25rem;
			line-height: 1;
		}

		.almacen-table-white {
			border: 1px solid #d9e2ec !important;
		}

		/* Keep outer filters bar stacked (top actions + track below) */
		.almacen-filters-bar {
			display: flex;
			flex-direction: column;
			align-items: stretch;
			gap: 1.5rem;
		}

		.almacen-filters-row {
			display: flex;
			align-items: flex-end;
			justify-content: space-between;
			gap: 0.8rem;
			flex-wrap: nowrap;
		}

		/* Ensure the search input grows and doesn't overflow */
		.almacen-filter-item--search {
			flex: 1 1 auto;
			min-width: 0; /* allow shrinking inside flex */
		}

		/* Actions (botones) aligned to the right and wrap on small screens */
		.almacen-filters-actions {
			display: flex;
			gap: 0.6rem;
			align-items: center;
			justify-content: flex-end;
			flex-wrap: wrap;
		}

		/* Responsive behaviour: order layout vertically */
		@media (max-width: 768px) {
			.almacen-filters-row {
				display: contents;
			}
			.almacen-filters-actions {
				justify-content: flex-start;
			}
			.almacen-filters-actions > * {
				width: 100%;
			}
			.almacen-filters-actions > *:not(:first-child) {
				margin-top: 0 !important;
			}
		}

		.almacen-filters-row--top {
			width: 100%;
		}

		/* Make the filters track responsive with grid auto-fill */
		.almacen-filters-track {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
			gap: 0.8rem 1.0rem;
			padding-bottom: 0.5rem;
			width: 100%;
			box-sizing: border-box;
		}

		.almacen-filter-item {
			min-width: 145px;
			display: flex;
			flex-direction: column;
			gap: 0.25rem;
			flex: 1 1 180px;
			min-width: 0;
			box-sizing: border-box;
		}

		.almacen-filter-item--tom {
			width: 100%;
			min-width: 0;
			max-width: 100%;
			position: relative;
			z-index: 30;
		}

		/* Eleva el z-index del contenedor padre por encima del resto cuando su Tom Select está activo/abierto */
		.almacen-filter-item--tom:has(.dropdown-active) {
			z-index: 99 !important;
		}

		.almacen-filter-item--wide {
			min-width: 260px;
		}

		.almacen-filter-item--search {
			flex: 1 1 360px;
			max-width: 400px;
		}

		.almacen-filter-label {
			font-size: 0.72rem;
			font-weight: 700;
			text-transform: uppercase;
			color: #64748b;
			letter-spacing: 0.04em;
		}

		.almacen-filter-control {
			width: 100%;
			border: 1px solid #d1d5db;
			border-radius: 0.5rem;
			padding: 0.48rem 0.65rem;
			font-size: 0.86rem;
			background: #fff;
			color: #0f172a;
		}

		.almacen-filter-control--search {
			padding-right: 2.2rem;
		}

		.almacen-search-clear {
			position: absolute;
			top: 50%;
			right: 0.4rem;
			transform: translateY(-50%);
			display: inline-flex;
			align-items: center;
			justify-content: center;
			width: 1.6rem;
			height: 1.6rem;
			border: 0;
			border-radius: 9999px;
			background: transparent;
			color: #64748b;
			cursor: pointer;
		}

		.almacen-search-clear:hover {
			background: #f1f5f9;
			color: #334155;
		}

		.almacen-filter-control--select {
			appearance: auto;
			-webkit-appearance: menulist;
			-moz-appearance: auto;
			min-height: 2.45rem;
			padding: 0.42rem 0.75rem;
			line-height: 1.2rem;
		}

		/* Tom Select compacto para almacen-table, alineado con los demás inputs */
		#list-filter-form .tom-select.ts-wrapper,
		#list-filter-form .tom-select,
		#list-filter-form .tom-select.plugin-dropdown_input.focus.dropdown-active,
		.almacen-filter-item--tom .tom-select.ts-wrapper,
		.almacen-filter-item--tom .tom-select,
		.almacen-filter-item--tom .tom-select.plugin-dropdown_input.focus.dropdown-active {
			min-height: 2.45rem !important;
			height: 2.45rem !important;
			border: 1px solid #d1d5db !important;
			border-radius: 0.5rem !important;
			background-color: #fff !important;
			box-shadow: none !important;
			background-image: none !important;
			width: 100%;
			max-width: 100%;
			box-sizing: border-box;
		}

		#list-filter-form .ts-wrapper,
		#list-filter-form .ts-wrapper.single,
		#list-filter-form .ts-wrapper.plugin-dropdown_input,
		#list-filter-form .ts-wrapper.plugin-dropdown_input.focus,
		#list-filter-form .ts-wrapper.plugin-dropdown_input.dropdown-active,
		.almacen-filter-item--tom .ts-wrapper,
		.almacen-filter-item--tom .ts-wrapper.single,
		.almacen-filter-item--tom .ts-wrapper.plugin-dropdown_input,
		.almacen-filter-item--tom .ts-wrapper.plugin-dropdown_input.focus,
		.almacen-filter-item--tom .ts-wrapper.plugin-dropdown_input.dropdown-active {
			width: 100% !important;
			max-width: 100% !important;
			min-width: 0 !important;
			display: block !important;
			box-sizing: border-box !important;
			flex: 1 1 auto !important;
		}

		#list-filter-form .tom-select.ts-wrapper .ts-control,
		#list-filter-form .tom-select .ts-control,
		#list-filter-form .tom-select.plugin-dropdown_input.focus.dropdown-active .ts-control,
		.almacen-filter-item--tom .tom-select.ts-wrapper .ts-control,
		.almacen-filter-item--tom .tom-select .ts-control,
		.almacen-filter-item--tom .tom-select.plugin-dropdown_input.focus.dropdown-active .ts-control {
			min-height: 2.45rem !important;
			height: 2.45rem !important;
			border: 0 !important;
			box-shadow: none !important;
			background: transparent !important;
			padding: 0.35rem 0.75rem 0.15rem 0.75rem !important;
			display: flex !important;
			align-items: flex-start !important;
			font-size: 0.8rem;
			color: #0f172a;
			width: 100% !important;
			max-width: 100% !important;
			box-sizing: border-box !important;
		}

		#list-filter-form .tom-select.ts-wrapper .ts-control .item,
		#list-filter-form .tom-select.ts-wrapper .ts-control .items {
			line-height: 1.2rem !important;
			min-height: 1.6rem !important;
			margin: 0 !important;
		}

		#list-filter-form .tom-select.ts-wrapper .ts-control .item,
		.almacen-filter-item--tom .tom-select.ts-wrapper .ts-control .item {
			white-space: nowrap !important;
			overflow: hidden !important;
			text-overflow: ellipsis !important;
			max-width: calc(100% - 1.5rem) !important;
		}

		#list-filter-form .tom-select.ts-wrapper .ts-control .item {
			padding: 0 !important;
		}

		#list-filter-form .tom-select .ts-control input.ts-input,
		#list-filter-form .tom-select.plugin-dropdown_input.focus.dropdown-active .ts-control input.ts-input {
			font-size: 0.86rem !important;
			line-height: 1.1rem !important;
			height: auto !important;
			min-height: 1.1rem !important;
			padding: 0 !important;
			margin: 0 !important;
		}

		#list-filter-form .tom-select .ts-dropdown,
		.ts-dropdown.ts-dropdown-portal {
			z-index: 9999 !important;
			border: 1px solid #d1d5db !important;
			border-radius: 0.5rem !important;
			box-shadow: 0 12px 28px rgba(15, 23, 42, 0.12) !important;
			margin-top: 0.35rem !important;
			width: auto;
			min-width: 100%;
		}

		#list-filter-form .tom-select .ts-dropdown.ts-dropdown-portal,
		.ts-dropdown.ts-dropdown-portal {
			position: fixed !important;
			margin-top: 0 !important;
		}

		#list-filter-form .tom-select .ts-dropdown .dropdown-input-wrap,
		.ts-dropdown .dropdown-input-wrap {
			padding: 0.5rem !important;
		}

		#list-filter-form .tom-select .ts-dropdown .dropdown-input-wrap .dropdown-input,
		.ts-dropdown .dropdown-input-wrap .dropdown-input {
			border: 1px solid #d1d5db !important;
			border-radius: 0.45rem !important;
			font-size: 0.86rem !important;
			padding: 0.45rem 0.65rem !important;
			outline: none !important;
			box-shadow: none !important;
			color: #0f172a !important;
		}

		#list-filter-form .tom-select .ts-dropdown .dropdown-input-wrap .dropdown-input:focus,
		#list-filter-form .tom-select .ts-dropdown .dropdown-input-wrap .dropdown-input:focus-visible,
		.ts-dropdown .dropdown-input-wrap .dropdown-input:focus,
		.ts-dropdown .dropdown-input-wrap .dropdown-input:focus-visible {
			border-color: #c71010 !important;
			box-shadow: 0 0 0 3px rgba(199, 16, 16, 0.15) !important;
			outline: none !important;
		}

		#list-filter-form .tom-select.ts-wrapper.focus,
		#list-filter-form .tom-select.ts-wrapper.dropdown-active,
		#list-filter-form .tom-select.plugin-dropdown_input.focus.dropdown-active {
			border-color: #c71010 !important;
			box-shadow: 0 0 0 3px rgba(199, 16, 16, 0.15) !important;
		}

		#list-filter-form .tom-select .ts-dropdown .ts-dropdown-content,
		.ts-dropdown .ts-dropdown-content {
			max-height: 150px;
			overflow-y: auto;
		}

		#list-filter-form .tom-select .ts-dropdown .option,
		.ts-dropdown .option {
			padding: 0.55rem 0.75rem;
			font-size: 0.86rem;
		}

		#list-filter-form .tom-select .ts-dropdown .option[data-selectable]:hover:not(.selected),
		#list-filter-form .tom-select .ts-dropdown .option[data-selectable].active:not(.selected),
		.ts-dropdown .option[data-selectable]:hover:not(.selected),
		.ts-dropdown .option[data-selectable].active:not(.selected) {
			background-color: #f8fafc;
		}

		#list-filter-form .tom-select .ts-dropdown .selected,
		.ts-dropdown .selected {
			background-color: rgb(199 16 16 / 1);
			color: #ffffff;
		}

		.almacen-filter-control:focus,
		.almacen-filter-control:focus-visible {
			outline: none;
			border-color: #c71010;
			box-shadow: 0 0 0 3px rgba(199, 16, 16, 0.15);
		}

		.almacen-filters-actions {
			display: flex;
			gap: 0.8rem;
			justify-content: flex-end;
			margin-top: 0;
			flex: 0 0 auto;
		}

		.almacen-board__title {
			font-size: 1.25rem;
			padding-left: 0.25rem;
			font-weight: 600;
		}

		.almacen-table-white table th,
		.almacen-table-white table td {
			font-size: 0.97rem;
		}

		.session-alerts-container {
			display: flex;
			flex-direction: column;
			align-items: stretch;
			width: 100%;
			padding-left: 1rem;
			padding-right: 1rem;
			box-sizing: border-box;
		}

		.session-alert {
			position: relative;
			display: flex;
			align-items: center;
			gap: 0.75rem;
			padding: 0.75rem 1rem;
			border-radius: 0.5rem;
			font-size: 1rem;
			line-height: 1.25;
			width: 100%;
		}

		.session-alert__icon {
			font-weight: 700;
			font-size: 1.05rem;
			display: inline-block;
			min-width: 1.25rem;
		}

		.session-alert__message {
			flex: 1 1 auto;
			display: inline-block;
		}

		.session-alert__close {
			position: absolute;
			right: 0.6rem;
			top: 0.4rem;
			background: transparent;
			border: none;
			font-size: 1.15rem;
			color: #374151;
			cursor: pointer;
			padding: 0.2rem 0.4rem;
		}

		@media (min-width: 1200px) {
			.almacen-stats-white,
			.almacen-table-white,
			.session-alerts-container {
				width: calc(100% + 20rem);
				margin-left: -10rem;
				margin-right: -12rem;
			}

			.almacen-board__title {
				margin-left: -10rem;
			}

			.almacen-board__new {
				margin-right: -9rem;
			}
		}

		@media (max-width: 768px) {
			.almacen-filters-bar {
				display: flex;
				flex-direction: column;
				align-items: stretch;
			}

			.almacen-filters-row {
				display: contents;
			}

			.almacen-filter-item--search {
				order: 1;
				max-width: none;
				flex: 0 0 auto;
			}

			.almacen-filters-track {
				order: 2;
			}

			.almacen-filters-actions {
				order: 3;
				justify-content: stretch;
			}

			.almacen-filter-item,
			.almacen-filter-item--wide {
				min-width: 220px;
			}

			.almacen-filter-item--tom {
				width: 100%;
				min-width: 220px;
				max-width: 100%;
			}
		}
	</style>
@endsection
