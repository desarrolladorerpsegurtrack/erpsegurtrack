@extends('dashboard.overview-1')

@section('title', $title ?? 'CRUD')
@section('header', $title ?? 'CRUD')

@section('breadcrumb')
    <nav aria-label="breadcrumb" class="flex hidden flex-1 xl:block">
        <ol class="flex items-center text-theme-1">
            <li><a href="{{ route('home') }}">Inicio</a></li>
            <li class="relative ml-5 pl-0.5 before:content-[''] before:w-[14px] before:h-[14px] before:bg-chevron-black before:transform before:rotate-[-90deg] before:bg-[length:100%] before:-ml-[1.125rem] before:absolute before:my-auto before:inset-y-0 text-slate-600 cursor-text">
                <span>{{ $title ?? 'Módulo' }}</span>
            </li>
        </ol>
    </nav>
@endsection

@section('content')
    <div class="grid w-full grid-cols-12 gap-x-6 gap-y-10">
        <div class="col-span-12">
                    <!-- HEADER CON TÍTULO Y BOTÓN NUEVO -->
                    <div class="flex flex-col gap-y-3 md:h-10 md:flex-row md:items-center">
                        <div class="text-base font-medium group-[.mode--light]:text-white">
                            {{ $title ?? 'Listado' }}
                        </div>
                        @php
                            $authData = session('erp_auth', []);
                            $userRoles = collect($authData['roles'] ?? [])
                                ->map(fn ($role) => mb_strtolower(trim((string) $role)))
                                ->filter()
                                ->values();
                            $isAdmin = $userRoles->contains('admin');
                            $currentRouteName = optional(request()->route())->getName();
                            $currentPermissionKey = App\Support\ErpPermission::resolvePermissionKeyFromRouteName($currentRouteName);
                            if ($currentPermissionKey === null) {
                                $currentPermissionKey = App\Support\ErpPermission::normalizeRouteModule($currentRouteName);
                            }

                            $allPermissions = collect($authData['permissions'] ?? []);
                            $currentPermissions = collect($authData['permissions'][$currentPermissionKey] ?? [])
                                ->map(fn ($value) => App\Support\ErpPermission::normalizeAction((string) $value))
                                ->filter()
                                ->unique()
                                ->values();

                            if (!$isAdmin && is_string($currentPermissionKey) && str_contains($currentPermissionKey, '.') && $currentPermissions->isEmpty()) {
                                $parentModule = App\Support\ErpPermission::permissionKeyToModule($currentPermissionKey);
                                $hasGranularPermissions = $allPermissions
                                    ->keys()
                                    ->contains(fn ($key) => is_string($key) && str_starts_with(mb_strtolower(trim((string) $key)), $parentModule . '.'));

                                if (!$hasGranularPermissions) {
                                    $currentPermissions = collect($authData['permissions'][$parentModule] ?? [])
                                        ->map(fn ($value) => App\Support\ErpPermission::normalizeAction((string) $value))
                                        ->filter()
                                        ->unique()
                                        ->values();
                                }
                            }
                            
                            $canView = $isAdmin || $currentPermissions->contains('ver');
                            $canCreate = $isAdmin || $currentPermissions->contains('crear');
                            $canEdit = $isAdmin || $currentPermissions->contains('editar');
                            $canDelete = $isAdmin || $currentPermissions->contains('eliminar');
                            $canExport = $isAdmin || $currentPermissions->contains('exportar');
                            $canBulkDeactivate = $isAdmin || collect($authData['permissions']['lineas_chips.bajar_numeros'] ?? [])
                                ->map(fn ($value) => App\Support\ErpPermission::normalizeAction((string) $value))
                                ->filter()
                                ->unique()
                                ->contains('ver');
                            $canImport = $isAdmin || collect($authData['permissions']['lineas_chips.cargar_numeros'] ?? [])
                                ->map(fn ($value) => App\Support\ErpPermission::normalizeAction((string) $value))
                                ->filter()
                                ->unique()
                                ->contains('ver');
                            $canPerformActions = $canEdit || $canDelete;
                            $createButtonLabel = $createButtonLabel ?? null;

                            $listResource = null;
                            if ($currentRouteName) {
                                $listResource = preg_replace('/^modules\./', '', $currentRouteName);
                                $listResource = preg_replace('/\.(create|edit|update|destroy|store|show|export|index)$/', '', $listResource);
                            }

                            if (empty($bulkDestroyRoute)) {
                                $candidateBulkRoute = null;

                                if (!empty($currentRouteName)) {
                                    $candidateBulkRoute = preg_replace('/\.index$/', '.bulk-destroy', $currentRouteName);
                                }

                                if ((empty($candidateBulkRoute) || $candidateBulkRoute === $currentRouteName) && !empty($destroyRoute) && is_string($destroyRoute)) {
                                    $candidateBulkRoute = preg_replace('/\.destroy$/', '.bulk-destroy', $destroyRoute);
                                }

                                if (!empty($candidateBulkRoute) && \Illuminate\Support\Facades\Route::has($candidateBulkRoute)) {
                                    $bulkDestroyRoute = route($candidateBulkRoute);
                                }
                            }
                        @endphp
                        <div class="flex flex-col gap-x-3 gap-y-2 sm:flex-row md:ml-auto">
                            @if(!empty($bulkDestroyRoute) && $canDelete)
                                <button type="button" id="bulk-delete-button" class="hidden transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-danger focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed border-danger text-danger dark:border-danger/70 dark:text-danger" style="border-color:#c71010;color:#c71010;">
                                    <i data-tw-merge="" data-lucide="trash-2" class="mr-2 h-4 w-4 stroke-[1.3]"></i>
                                    Eliminar selección
                                </button>
                            @endif
                            @if(!empty($bulkDeactivateRoute) && $canBulkDeactivate)
                                <button type="button" data-open-detallesimcard-modal="bulk-deactivate" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed dark:border-danger/70 dark:text-danger" style="border-color:#c71010;color:#c71010;">
                                    <i data-tw-merge="" data-lucide="ban" class="mr-2 h-4 w-4 stroke-[1.3]"></i>
                                    Dar de baja números
                                </button>
                            @endif
                            @if(!empty($importPreviewRoute) && !empty($importProcessRoute) && $canImport)
                                <button type="button" data-open-detallesimcard-modal="import" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed dark:border-darkmode-100/40 dark:text-slate-300" style="border-color:#000000;color:#000000;">
                                    <i data-tw-merge="" data-lucide="upload" class="mr-2 h-4 w-4 stroke-[1.3]"></i>
                                    Cargar números
                                </button>
                            @endif
                            @if(!empty($createRoute) && $canCreate)
                                <a href="{{ $createRoute }}">
                                    <button type="button" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed dark:border-danger/70 dark:text-danger" style="background-color:#c71010;color:#ffffff;">
                                        <i data-tw-merge="" data-lucide="plus" class="mr-2 h-4 w-4 stroke-[1.3]"></i>
                                        {{ $createButtonLabel ?: ('Nuevo ' . ($singularTitle ?? 'Registro')) }}
                                    </button>
                                </a>                       
                            @endif
                        </div>
                    </div>

                    @if(!empty($listResource))
                        <input type="hidden" id="erp-list-resource" value="{{ $listResource }}">
                        <input type="hidden" id="erp-relation-summary-template" value="{{ route('modules.relations.summary', ['resource' => '__RESOURCE__', 'id' => '__ID__']) }}">
                    @endif

                    <div class="mt-3.5 flex flex-col gap-8">
                        {{-- ALERTAS DE SESIÓN --}}
                         @if(session('success'))
                            <div class="mb-4 rounded-lg border px-4 py-3 text-base font-semibold relative" style="border-color:#16a34a;background-color:#dcfce7;color:#14532d;">
                                ✓ {{ session('success') }}
                                <button type="button" class="absolute top-0 right-0 mt-2 mr-2 text-lg font-bold text-gray-600 hover:text-gray-800" onclick="this.parentElement.style.display='none';">&times;</button>
                            </div>
                        @endif
                        @if(session('download_pdf_url'))
                            <script>
                                document.addEventListener('DOMContentLoaded', function () {
                                    var url = {!! json_encode(session('download_pdf_url')) !!};
                                    if (!url) return;
                                    var a = document.createElement('a');
                                    a.href = url;
                                    a.download = '';
                                    a.style.display = 'none';
                                    document.body.appendChild(a);
                                    a.click();
                                    document.body.removeChild(a);
                                });
                            </script>
                        @endif
                        @if(session('error'))
                            <div class="mb-4 rounded-lg border px-4 py-3 text-lg font-semibold relative" style="border-color:#a31616;background-color:#fcdcdc;color:#531414;">
                                ✕ {{ session('error') }}
                                <button type="button" class="absolute top-0 right-0 mt-2 mr-2 text-lg font-bold text-gray-600 hover:text-gray-800" onclick="this.parentElement.style.display='none';">&times;</button>
                            </div>
                        @endif
                        @if($errors->any())
                            <div class="mb-4 rounded-lg border border-red-700 bg-red-600 px-4 py-3 text-sm font-semibold text-white">
                                <ul class="list-disc pl-5">
                                    @foreach($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        <!-- ESTADÍSTICAS -->
                        @if($stats)
                           <div class="box box--stacked flex flex-col p-5">
                                <div class="grid grid-cols-4 gap-5">
                                    @foreach($stats as $key => $stat)
                                        @php
                                            $label = is_array($stat) && array_key_exists('label', $stat) ? $stat['label'] : (is_string($key) ? $key : '');
                                            $value = is_array($stat) && array_key_exists('value', $stat) ? $stat['value'] : $stat;
                                        @endphp
                                        <div class="box col-span-4 rounded-[0.6rem] border border-dashed border-slate-300/80 bg-white p-5 shadow-sm md:col-span-2 xl:col-span-1">
                                            @if($label !== '')
                                                <div class="text-base text-slate-500">{{ $label }}</div>
                                            @endif
                                            <div class="mt-1.5 text-2xl font-medium">{{ $value }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <!-- TABLA -->
                        <div id="list-table-wrapper" class="box box--stacked flex w-full flex-col">
                            @php
                                $filters = $filters ?? [];
                                $showGroupClientsColumn = $showGroupClientsColumn ?? false;
                                $activeFilters = collect($filters)
                                    ->filter(function ($filter) {
                                        $name = $filter['name'] ?? '';
                                        return $name !== '' && request()->has($name) && request($name) !== '';
                                    })
                                    ->count();
                            @endphp
                            <div class="flex flex-col gap-y-2 p-5 sm:flex-row sm:items-center">
                                <form id="list-filter-form" method="GET" action="{{ url()->current() }}" class="flex w-full flex-col gap-y-2 sm:flex-row sm:items-center">
                                    <div>
                                        <div class="relative">
                                            <i data-tw-merge="" data-lucide="search" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-500"></i>
                                            <input data-tw-merge="" type="text" name="q" autocomplete="off" value="{{ request('q') }}" placeholder="Buscar..." class="disabled:bg-slate-100 disabled:cursor-not-allowed dark:disabled:bg-darkmode-800/50 dark:disabled:border-transparent [&[readonly]]:bg-slate-100 [&[readonly]]:cursor-not-allowed [&[readonly]]:dark:bg-darkmode-800/50 [&[readonly]]:dark:border-transparent transition duration-200 ease-in-out w-full pr-10 text-sm border-slate-200 shadow-sm placeholder:text-slate-400/90 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 dark:placeholder:text-slate-500/80 [&[type='file']]:border file:mr-4 file:py-2 file:px-4 file:rounded-l-md file:border-0 file:border-r-[1px] file:border-slate-100/10 file:text-sm file:font-semibold file:bg-slate-100 file:text-slate-500/70 hover:file:bg-200 group-[.form-inline]:flex-1 group-[.input-group]:rounded-none group-[.input-group]:[&:not(:first-child)]:border-l-transparent group-[.input-group]:first:rounded-l group-[.input-group]:last:rounded-r group-[.input-group]:z-10 rounded-[0.5rem] pl-9 sm:w-64">
                                            <button type="button" data-list-clear-search="true" style="display: none;" class="absolute inset-y-0 right-0 z-10 mr-2 flex items-center justify-center rounded-full bg-transparent px-2 text-slate-500 transition hover:bg-transparent hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-opacity-40">
                                                <i data-tw-merge="" data-lucide="x" class="h-4 w-4 stroke-[1.3]"></i>
                                            </button>
                                        </div>
                                    </div>
                                    @php $exportMode = $exportMode ?? 'dropdown'; @endphp
                                    <div class="flex flex-col gap-x-3 gap-y-2 sm:ml-auto sm:flex-row">
                                        @if(!empty($exportRoutes) && $canExport)
                                            @if($exportMode === 'buttons')
                                                <a href="{{ $exportRoutes['xlsx'] ?? '#' }}" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed border-secondary text-slate-500 dark:border-darkmode-100/40 dark:text-slate-300 [&:hover:not(:disabled)]:bg-secondary/20 [&:hover:not(:disabled)]:dark:bg-darkmode-100/10 w-full sm:w-auto">
                                                    <i data-tw-merge="" data-lucide="file-bar-chart" class="mr-2 h-4 w-4 stroke-[1.3]"></i>
                                                    XLSX
                                                </a>
                                            @else
                                                <div data-tw-merge="" data-tw-placement="bottom-end" class="dropdown relative inline-flex shrink-0">
                                                    <button type="button" data-tw-merge="" data-local-dropdown-toggle="true" aria-expanded="false" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed border-secondary text-slate-500 dark:border-darkmode-100/40 dark:text-slate-300 [&:hover:not(:disabled)]:bg-secondary/20 [&:hover:not(:disabled)]:dark:bg-darkmode-100/10 w-full sm:w-auto">
                                                        <i data-tw-merge="" data-lucide="download" class="mr-2 h-4 w-4 stroke-[1.3]"></i>
                                                        Exportar
                                                        <i data-tw-merge="" data-lucide="chevron-down" class="ml-2 h-4 w-4 stroke-[1.3]"></i>
                                                    </button>
                                                    <div class="dropdown-menu absolute right-0 top-full z-[9999] mt-2 origin-top-right invisible opacity-0 pointer-events-none hidden">
                                                        <div data-tw-merge="" class="dropdown-content rounded-md border border-slate-200/80 bg-white p-2 shadow-xl shadow-slate-200/70 dark:border-transparent dark:bg-darkmode-600">
                                                            <a href="{{ $exportRoutes['pdf'] ?? '#' }}" data-export-link="true" data-export-base="{{ $exportRoutes['pdf'] ?? '#' }}" data-export-format="pdf" class="cursor-pointer flex items-center p-2 transition duration-300 ease-in-out rounded-md hover:bg-slate-200/60 dark:bg-darkmode-600 dark:hover:bg-darkmode-400 dropdown-item">
                                                                <i data-tw-merge="" data-lucide="file-bar-chart" class="stroke-[1] mr-2 h-4 w-4"></i>
                                                                PDF
                                                            </a>
                                                            <a href="{{ $exportRoutes['xlsx'] ?? '#' }}" data-export-link="true" data-export-base="{{ $exportRoutes['xlsx'] ?? '#' }}" data-export-format="xlsx" class="cursor-pointer flex items-center p-2 transition duration-300 ease-in-out rounded-md hover:bg-slate-200/60 dark:bg-darkmode-600 dark:hover:bg-darkmode-400 dropdown-item">
                                                                <i data-tw-merge="" data-lucide="file-bar-chart" class="stroke-[1] mr-2 h-4 w-4"></i>
                                                                XLSX
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endif
                                        @endif
                                        @if($filters)
                                            <div data-tw-merge="" data-tw-placement="bottom-end" class="dropdown relative inline-flex shrink-0">
                                                <button type="button" data-tw-merge="" data-local-dropdown-toggle="true" aria-expanded="false" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed border-secondary text-slate-500 dark:border-darkmode-100/40 dark:text-slate-300 [&:hover:not(:disabled)]:bg-secondary/20 [&:hover:not(:disabled)]:dark:bg-darkmode-100/10 w-full sm:w-auto">
                                                    <i data-tw-merge="" data-lucide="arrow-down-wide-narrow" class="mr-2 h-4 w-4 stroke-[1.3]"></i>
                                                    Filtro
                                                    @if($activeFilters)
                                                        <span class="ml-2 flex h-5 items-center justify-center rounded-full border bg-slate-100 px-1.5 text-xs font-medium">
                                                            {{ $activeFilters }}
                                                        </span>
                                                    @endif
                                                </button>
                                                <div class="dropdown-menu absolute right-0 top-full z-[9999] mt-2 origin-top-right invisible opacity-0 pointer-events-none hidden">
                                                    <div data-tw-merge="" class="dropdown-content rounded-xl border border-slate-200/80 bg-white p-4 shadow-xl shadow-slate-200/70 dark:border-transparent dark:bg-darkmode-600">
                                                        @foreach($filters as $filter)
                                                            @php
                                                                $filterName = $filter['name'] ?? '';
                                                                $filterLabel = $filter['label'] ?? 'Filtro';
                                                                $filterType = $filter['type'] ?? 'select';
                                                                $filterOptions = $filter['options'] ?? [];
                                                                $filterPlaceholder = $filter['placeholder'] ?? 'Todos';
                                                            @endphp
                                                            <div class="mb-3 last:mb-0">
                                                                <div class="text-xs font-medium uppercase text-slate-500">{{ $filterLabel }}</div>
                                                                @if($filterType === 'text')
                                                                    <input
                                                                        type="text"
                                                                        name="{{ $filterName }}"
                                                                        value="{{ request($filterName) }}"
                                                                        placeholder="{{ $filterPlaceholder }}"
                                                                        class="mt-2 w-full rounded-[0.5rem] border-slate-200 text-sm shadow-sm transition duration-200 ease-in-out focus:border-primary focus:ring-4 focus:ring-primary focus:ring-opacity-20"
                                                                    >
                                                                @elseif($filterType === 'date')
                                                                    <input
                                                                        type="date"
                                                                        name="{{ $filterName }}"
                                                                        value="{{ request($filterName) }}"
                                                                        class="mt-2 w-full rounded-[0.5rem] border-slate-200 text-sm shadow-sm transition duration-200 ease-in-out focus:border-primary focus:ring-4 focus:ring-primary focus:ring-opacity-20"
                                                                    >
                                                                @else
                                                                    <select name="{{ $filterName }}" class="mt-2 w-full rounded-[0.5rem] border-slate-200 text-sm shadow-sm transition duration-200 ease-in-out focus:border-primary focus:ring-4 focus:ring-primary focus:ring-opacity-20">
                                                                        <option value="">{{ $filterPlaceholder }}</option>
                                                                        @foreach($filterOptions as $option)
                                                                            <option value="{{ $option['value'] }}" @selected((string) request($filterName) === (string) $option['value'])>
                                                                                {{ $option['label'] }}
                                                                            </option>
                                                                        @endforeach
                                                                    </select>
                                                                @endif
                                                            </div>
                                                        @endforeach
                                                        <div class="mt-4 flex items-center gap-2 border-t border-slate-100 pt-3">
                                                            <button type="submit" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 bg-primary border-primary text-white">
                                                                Aplicar
                                                            </button>
                                                            <a href="{{ url()->current() }}" data-list-clear="true" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none border-secondary text-slate-500">
                                                                Limpiar
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                </form>
                            </div>

                            <div class="overflow-auto xl:overflow-visible">
                                <table data-tw-merge="" class="w-full text-left border-b border-slate-200/60 @if(collect($columns)->contains(fn ($column) => !empty($column['wrap'] ?? false))) table-fixed @endif">
                                    <thead data-tw-merge="" class="">
                                        <tr data-tw-merge="" class="">
                                            <td data-tw-merge="" class="px-5 border-b dark:border-darkmode-300 w-5 border-t border-slate-200/60 bg-slate-50 py-4 font-medium text-slate-500">
                                                <input id="list-select-all" data-tw-merge="" type="checkbox" class="transition-all duration-100 ease-in-out shadow-sm border-slate-200 cursor-pointer rounded focus:ring-4 focus:ring-offset-0 focus:ring-primary focus:ring-opacity-20 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&[type='radio']]:checked:bg-primary [&[type='radio']]:checked:border-primary [&[type='radio']]:checked:border-opacity-10 [&[type='checkbox']]:checked:bg-primary [&[type='checkbox']]:checked:border-primary [&[type='checkbox']]:checked:border-opacity-10 [&:disabled:not(:checked)]:bg-slate-100 [&:disabled:not(:checked)]:cursor-not-allowed [&:disabled:not(:checked)]:dark:bg-darkmode-800/50 [&:disabled:checked]:opacity-70 [&:disabled:checked]:cursor-not-allowed [&:disabled:checked]:dark:bg-darkmode-800/50">
                                            </td>
                                            @foreach($columns as $column)
                                                @if(($column['key'] ?? '') === 'estado')
                                                    <td data-tw-merge="" class="px-5 text-center align-middle border-b dark:border-darkmode-300 border-t border-slate-200/60 bg-slate-50 py-4 font-medium text-slate-500 @if(!empty($column['wrap'] ?? false)) min-w-[150px] @endif">
                                                        <div class="th-sort th-sort--center" role="button" tabindex="0" data-sort-index="{{ $loop->index + 1 }}" aria-label="Ordenar {{ $column['label'] }}">
                                                            <span class="th-sort__label">{{ $column['label'] }}</span>
                                                            <span class="th-sort__icon" aria-hidden="true"></span>
                                                        </div>
                                                    </td>
                                                @else
                                                    <td data-tw-merge="" class="px-5 border-b dark:border-darkmode-300 border-t border-slate-200/60 bg-slate-50 py-4 font-medium text-slate-500 @if(!empty($column['wrap'] ?? false)) min-w-[150px] @endif">
                                                        <div class="th-sort" role="button" tabindex="0" data-sort-index="{{ $loop->index + 1 }}" aria-label="Ordenar {{ $column['label'] }}">
                                                            <span class="th-sort__label">{{ $column['label'] }}</span>
                                                            <span class="th-sort__icon" aria-hidden="true"></span>
                                                        </div>
                                                    </td>
                                                @endif
                                            @endforeach
                                            @if($showGroupClientsColumn)
                                                <td data-tw-merge="" class="px-5 border-b dark:border-darkmode-300 border-t border-slate-200/60 bg-slate-50 py-4 font-medium text-slate-500">
                                                    <div class="th-sort" role="button" tabindex="0" data-sort-index="{{ count($columns) + 1 }}" aria-label="Ordenar Clientes en este grupo">
                                                        <span class="th-sort__label">Clientes en este grupo</span>
                                                        <span class="th-sort__icon" aria-hidden="true"></span>
                                                    </div>
                                                </td>
                                            @endif
                                            @if(($showActionsColumn ?? true) && $canPerformActions)
                                                <td data-tw-merge="" class="px-5 text-center align-middle border-b dark:border-darkmode-300 border-t border-slate-200/60 bg-slate-50 py-4 font-medium text-slate-500">
                                                    Acciones
                                                </td>
                                            @endif
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @php
                                            $historyRowColspan = count($columns) + 2 + ($showGroupClientsColumn ? 1 : 0) + (($showActionsColumn ?? true) && $canPerformActions ? 1 : 0);
                                            $historyTitle = $historyTitle ?? 'Historial de relaciones';
                                            $historyColumns = $historyColumns ?? [
                                                ['key' => 'simCard_idsimCard', 'label' => 'SimCard', 'type' => 'text'],
                                                ['key' => 'numeroTelefonico_numeroTelefonico', 'label' => 'Número telefónico', 'type' => 'text'],
                                                ['key' => 'fechaAsignacion', 'label' => 'Fecha de asignación', 'type' => 'text'],
                                                ['key' => 'estado', 'label' => 'Estado', 'type' => 'status'],
                                            ];
                                        @endphp
                                        @forelse($items as $row)
                                            @php
                                                $historyItems = collect(data_get($row, 'history', []));
                                                $relationGroups = collect(data_get($row, 'relation_groups', []))
                                                    ->filter(fn ($group) => is_array($group) && !empty($group['records'] ?? []))
                                                    ->values();
                                                $hasExpandableRelations = $historyItems->isNotEmpty() || $relationGroups->isNotEmpty();
                                            @endphp
                                            <tr data-tw-merge="" class="[&_td]:last:border-b-0">
                                                <td data-tw-merge="" class="px-6 border-b dark:border-darkmode-300 border-dashed py-8 dark:bg-darkmode-600">
                                                    <div class="flex items-center gap-2">
                                                        <input data-tw-merge="" type="checkbox" class="list-row-checkbox transition-all duration-100 ease-in-out shadow-sm border-slate-200 cursor-pointer rounded focus:ring-4 focus:ring-offset-0 focus:ring-primary focus:ring-opacity-20 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&[type='radio']]:checked:bg-primary [&[type='radio']]:checked:border-primary [&[type='radio']]:checked:border-opacity-10 [&[type='checkbox']]:checked:bg-primary [&[type='checkbox']]:checked:border-primary [&[type='checkbox']]:checked:border-opacity-10 [&:disabled:not(:checked)]:bg-slate-100 [&:disabled:not(:checked)]:cursor-not-allowed [&:disabled:not(:checked)]:dark:bg-darkmode-800/50 [&:disabled:checked]:opacity-70 [&:disabled:checked]:cursor-not-allowed [&:disabled:checked]:dark:bg-darkmode-800/50" value="{{ data_get($row, $identifierKey) }}">
                                                    </div>
                                                </td>
                                                @foreach($columns as $columnIndex => $column)
                                                    <td data-tw-merge="" class="px-5 border-b dark:border-darkmode-300 border-dashed py-4 dark:bg-darkmode-600 @if(($column['key'] ?? '') === 'estado') text-center align-middle @endif @if(!empty($column['wrap'] ?? false)) align-top min-w-[150px] @endif ">
                                                        @switch($column['type'] ?? 'text')
                                                            @case('text')
                                                                @php
                                                                    $canShowLink = $columnIndex === 0 
                                                                        && isset($showRoute) 
                                                                        && !empty($showRoute)
                                                                        && isset($identifierKey) 
                                                                        && !empty($identifierKey)
                                                                        && data_get($row, $identifierKey) !== null
                                                                        && $canEdit;
                                                                @endphp
                                                                @if($canShowLink)
                                                                    <a class="font-medium text-slate-700 hover:text-primary hover:underline @if(!empty($column['wrap'] ?? false)) whitespace-normal break-words leading-5 @else whitespace-nowrap @endif" href="{{ route($showRoute, data_get($row, $identifierKey)) }}">
                                                                        {{ data_get($row, $column['key']) ?? '-' }}
                                                                    </a>
                                                                @else
                                                                    <span class="font-medium @if(!empty($column['wrap'] ?? false)) whitespace-normal break-words leading-5 @else whitespace-nowrap @endif">
                                                                        {{ data_get($row, $column['key']) ?? '-' }}
                                                                    </span>
                                                                @endif
                                                            @break
                                                            @case('date')
                                                                @php
                                                                    $rawDate = data_get($row, $column['key']);
                                                                    $formattedDate = '-';
                                                                    if (!empty($rawDate) && $rawDate !== '0000-00-00 00:00:00') {
                                                                        try {
                                                                            $carbonDate = \Illuminate\Support\Carbon::parse($rawDate);
                                                                            $monthNames = ['ene.', 'feb.', 'mar.', 'abr.', 'may.', 'jun.', 'jul.', 'ago.', 'sep.', 'oct.', 'nov.', 'dic.'];
                                                                            $formattedDate = sprintf(
                                                                                '%s %s %s, %s',
                                                                                $carbonDate->format('d'),
                                                                                $monthNames[(int) $carbonDate->format('m') - 1],
                                                                                $carbonDate->format('Y'),
                                                                                $carbonDate->format('H:i')
                                                                            );
                                                                        } catch (\Throwable $e) {
                                                                            $formattedDate = (string) $rawDate;
                                                                        }
                                                                    }
                                                                @endphp
                                                                <span class="font-medium whitespace-nowrap">{{ $formattedDate }}</span>
                                                            @break
                                                            @case('truncated_modal')
                                                                @php
                                                                    $value = data_get($row, $column['key']) ?? '-';
                                                                    $maxLength = $column['maxLength'] ?? 23;
                                                                    $isLong = mb_strlen((string) $value) > $maxLength;
                                                                    $summary = $isLong ? mb_substr((string) $value, 0, $maxLength) . '...' : (string) $value;
                                                                @endphp
                                                                @if($value === '-')
                                                                    <span class="font-medium text-slate-700">-</span>
                                                                @else
                                                                    <button type="button"
                                                                        class="font-medium text-slate-700 text-left transition duration-150 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-opacity-50"
                                                                        data-open-audit-action-detail
                                                                        data-audit-action-full="{{ e($value) }}"
                                                                        title="{{ $value }}">
                                                                        <span class="block max-w-[20rem] truncate">
                                                                            {{ $summary }}
                                                                        </span>
                                                                    </button>
                                                                @endif
                                                            @break
                                                            @case('custom')
                                                                {!! data_get($row, $column['key']) ?? '-' !!}
                                                            @break
                                                            @case('user_profile')
                                                                @php
                                                                    $photo = data_get($row, $column['photo_key'] ?? 'foto');
                                                                    $subtitleKey = $column['subtitle_key'] ?? null;
                                                                    $subtitle = $subtitleKey ? data_get($row, $subtitleKey) : null;
                                                                    $name = data_get($row, $column['key']) ?? 'Usuario';
                                                                    $nameParts = preg_split('/\s+/', trim((string) $name));
                                                                    $initials = '';
                                                                    foreach ($nameParts as $part) {
                                                                        if ($part === '') {
                                                                            continue;
                                                                        }
                                                                        $initials .= mb_substr(mb_strtoupper($part), 0, 1);
                                                                        if (mb_strlen($initials) >= 2) {
                                                                            break;
                                                                        }
                                                                    }
                                                                    $initials = $initials ?: 'US';
                                                                    $canShowLink = isset($showRoute) 
                                                                        && !empty($showRoute)
                                                                        && isset($identifierKey) 
                                                                        && !empty($identifierKey)
                                                                        && data_get($row, $identifierKey) !== null;
                                                                @endphp
                                                                <div class="flex items-center">
                                                                    <div class="w-10 h-10 flex-none overflow-hidden rounded-full bg-slate-100 text-slate-700 shadow-[0px_0px_0px_2px_#fff,_1px_1px_5px_rgba(0,0,0,0.12)] dark:bg-slate-700 dark:text-slate-200">
                                                                        @if($photo)
                                                                            <img alt="Perfil" class="h-full w-full object-cover" src="{{ asset('storage/' . $photo) }}">
                                                                        @else
                                                                            <span class="flex h-full w-full items-center justify-center text-sm font-semibold">{{ $initials }}</span>
                                                                        @endif
                                                                    </div>
                                                                    <div class="ml-4">
                                                                        @if($canShowLink)
                                                                            <a href="{{ route($showRoute, data_get($row, $identifierKey)) }}" class="font-medium whitespace-nowrap text-slate-700 hover:text-slate-900 hover:underline">{{ $name }}</a>
                                                                        @else
                                                                            <span class="font-medium whitespace-nowrap text-slate-800 dark:text-slate-100">{{ $name }}</span>
                                                                        @endif
                                                                        @if($subtitle)
                                                                            <div class="text-slate-500 text-sm whitespace-nowrap mt-0.5">{{ $subtitle }}</div>
                                                                        @endif
                                                                    </div>
                                                                </div>
                                                            @break
                                                            @case('status')
                                                                @php
                                                                    $value = data_get($row, $column['key']);
                                                                    $isActive = false;
                                                                    $label = '';
                                                                    // Permitir valores tipo texto o numérico
                                                                    if (is_numeric($value)) {
                                                                        $isActive = (string)($value ?? '1') === '1';
                                                                        $label = $isActive ? 'Activo' : 'Inactivo';
                                                                    } else {
                                                                        $label = trim((string)($value ?? ''));
                                                                        $isActive = stripos($label, 'activo') !== false && stripos($label, 'inactivo') === false;
                                                                    }
                                                                @endphp
                                                                <div class="flex items-center justify-center">
                                                                    <i data-tw-merge="" data-lucide="database" class="h-3.5 w-3.5 stroke-[1.7] {{ $isActive ? 'text-danger' : 'text-slate-400' }}"></i>
                                                                    <span class="ml-1.5 whitespace-nowrap font-medium {{ $isActive ? 'text-danger' : 'text-slate-500' }}">
                                                                        {{ $label }}
                                                                    </span>
                                                                </div>
                                                            @break
                                                            @case('badge')
                                                                <div class="flex flex-wrap gap-1.5">
                                                                    @if(is_array(data_get($row, $column['key'])))
                                                                        @foreach(data_get($row, $column['key']) as $item)
                                                                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">{{ $item }}</span>
                                                                        @endforeach
                                                                    @else
                                                                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">{{ data_get($row, $column['key']) ?? '-' }}</span>
                                                                    @endif
                                                                </div>
                                                            @break
                                                            @default
                                                                <span class="@if(!empty($column['wrap'] ?? false)) whitespace-normal break-words leading-5 @endif">
                                                                    {{ data_get($row, $column['key']) ?? '-' }}
                                                                </span>
                                                        @endswitch
                                                    </td>
                                                @endforeach
                                                @if($showGroupClientsColumn)
                                                    <!-- Columna de clientes en este grupo -->
                                                    <td data-tw-merge="" class="px-5 border-b dark:border-darkmode-300 border-dashed py-4 dark:bg-darkmode-600">
                                                        @php
                                                            // Obtener los primeros 3 clientes asociados a este grupo
                                                            $clientes = DB::table('detallegrupocliente')
                                                                ->join('cliente', 'detallegrupocliente.cliente_idcliente', '=', 'cliente.idcliente')
                                                                ->where('detallegrupocliente.grupoCliente_idgrupoCliente', $row->idgrupoCliente)
                                                                ->select('cliente.nombreComercial')
                                                                ->limit(3)
                                                                ->pluck('nombreComercial');
                                                        @endphp
                                                        @if($clientes->count() > 0)
                                                            <span>{{ $clientes->implode(', ') }}</span>
                                                        @else
                                                            <span class="text-slate-400">Sin clientes</span>
                                                        @endif
                                                    </td>
                                                @endif
                                                @if(($showActionsColumn ?? true) && $canPerformActions)
                                                    <td data-tw-merge="" class="px-5 border-b dark:border-darkmode-300 relative border-dashed py-4 dark:bg-darkmode-600">
                                                        <div class="flex items-center justify-center h-full">
                                                            @php
                                                                $canEditRoute = $canEdit && !empty($editRoute);
                                                                $canDeleteRoute = $canDelete && !empty($destroyRoute);
                                                                $rowLockBlocked = false;
                                                                $rowLockOwner = null;

                                                            if (isset($row->lockBlocked)) {
                                                                $rowLockBlocked = (bool) $row->lockBlocked;
                                                                $rowLockOwner = $row->lockOwner ?? null;
                                                            } elseif (isset($lockResource) && !empty($lockResource) && isset($row->{$identifierKey})) {
                                                                $lockInfo = \App\Support\ResourceLock::status($lockResource, (string) data_get($row, $identifierKey));
                                                                $currentUser = session('erp_auth.usuario', 'anonimo');
                                                                if ($lockInfo) {
                                                                    $rowLockBlocked = ($lockInfo['usuario'] ?? '') !== $currentUser;
                                                                    $rowLockOwner = $lockInfo['usuario'] ?? null;
                                                                }
                                                            }
                                                        @endphp
                                                        @if(($showActionsColumn ?? true) && ($canEditRoute || $canDeleteRoute))
                                                            <div data-tw-merge="" data-tw-placement="bottom-end" class="dropdown dropdown--action relative h-5">
                                                                <button type="button" data-local-dropdown-toggle="true" aria-expanded="false" class="cursor-pointer h-5 w-5 text-slate-500">
                                                                    <i data-tw-merge="" data-lucide="more-vertical" class="stroke-[1] w-5 h-5 fill-slate-400/70 stroke-slate-400/70"></i>
                                                                </button>
                                                                <div class="dropdown-menu absolute right-0 top-full z-[9999] mt-2 origin-top-right invisible opacity-0 pointer-events-none hidden">
                                                                    <div data-tw-merge="" class="dropdown-content rounded-md border border-slate-200/80 bg-white p-2 shadow-xl shadow-slate-200/70 dark:border-transparent dark:bg-darkmode-600">
                                                                        @if($canEditRoute)
                                                                            @if($rowLockBlocked)
                                                                                <button type="button" disabled class="cursor-not-allowed flex items-center p-2 transition duration-300 ease-in-out rounded-md text-slate-400 dark:text-slate-500 dropdown-item opacity-50" title="Bloqueado por {{ $rowLockOwner ?? 'otro usuario' }}">
                                                                                    <i data-tw-merge="" data-lucide="check-square" class="stroke-[1] mr-2 h-4 w-4"></i>
                                                                                    Editar
                                                                                </button>
                                                                            @else
                                                                                <a href="{{ route($editRoute, data_get($row, $identifierKey)) }}" class="cursor-pointer flex items-center p-2 transition duration-300 ease-in-out rounded-md hover:bg-slate-200/60 dark:bg-darkmode-600 dark:hover:bg-darkmode-400 dropdown-item">
                                                                                    <i data-tw-merge="" data-lucide="check-square" class="stroke-[1] mr-2 h-4 w-4"></i>
                                                                                    Editar
                                                                                </a>
                                                                            @endif
                                                                        @endif
                                                                        @if($canDeleteRoute)
                                                                            <form method="POST" action="{{ route($destroyRoute, data_get($row, $identifierKey)) }}" class="inline delete-confirmation-form" data-relation-resource="{{ $listResource ?? '' }}" data-relation-record-id="{{ data_get($row, $identifierKey) }}">
                                                                                @csrf
                                                                                @method('DELETE')
                                                                                <button type="button" data-delete-open="true" class="cursor-pointer flex items-center p-2 transition duration-300 ease-in-out rounded-md hover:bg-slate-200/60 dark:bg-darkmode-600 dark:hover:bg-darkmode-400 dropdown-item text-danger w-full text-left @if($rowLockBlocked) opacity-50 cursor-not-allowed pointer-events-none @endif" @if($rowLockBlocked) disabled title="Bloqueado por {{ $rowLockOwner ?? 'otro usuario' }}" @endif>
                                                                                    <i data-tw-merge="" data-lucide="trash2" class="stroke-[1] mr-2 h-4 w-4"></i>
                                                                                    Eliminar
                                                                                </button>
                                                                            </form>
                                                                        @endif
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        @endif
                                                        @if($hasExpandableRelations)
                                                            <button type="button" data-history-toggle="{{ data_get($row, $identifierKey) }}" class="history-toggle ml-2 inline-flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-100" aria-label="Mostrar {{ $historyTitle }}">
                                                                <i data-lucide="chevron-down" class="h-4 w-4 stroke-[1.7] transition-transform duration-200"></i>
                                                            </button>
                                                        @endif
                                                    </div>
                                                </td>
                                            @endif
                                            </tr>
                                            @if($hasExpandableRelations)
                                                <tr data-history-row="{{ data_get($row, $identifierKey) }}" class="hidden" style="background-color: #FCE8E8;">
                                                    <td colspan="{{ $historyRowColspan }}" class="px-5 py-4" >
                                                        @if(!empty($relationPanelView) && \Illuminate\Support\Facades\View::exists($relationPanelView))
                                                            @include($relationPanelView, [
                                                                'row' => $row,
                                                                'relationGroups' => $relationGroups,
                                                                'historyItems' => $historyItems,
                                                                'historyColumns' => $historyColumns,
                                                                'historyTitle' => $historyTitle,
                                                            ])
                                                        @else
                                                            <div class="overflow-hidden rounded-lg border border-black shadow-sm" style="background-color: #ffffff; ">
                                                                <div class="border-b border-black bg-slate-200 text-slate-800 px-4 py-3 text-sm font-semibold" >{{ $historyTitle }}</div>
                                                                <div class="overflow-x-auto" >
                                                                    @if($relationGroups->isNotEmpty())
                                                                        <div class="flex flex-col gap-4">
                                                                            @foreach($relationGroups as $relationGroup)
                                                                                @php
                                                                                    $groupMaxTs = null;
                                                                                    $groupTimestamps = [];
                                                                                    foreach ((array) ($relationGroup['records'] ?? []) as $rgRec) {
                                                                                        $f = data_get($rgRec, 'fechaAsignacion') ?? data_get($rgRec, 'fecha_asignacion') ?? null;
                                                                                        if (!empty($f)) {
                                                                                            $t = @strtotime($f);
                                                                                            if ($t !== false && $t !== null) $groupTimestamps[] = $t;
                                                                                        }
                                                                                    }
                                                                                    if (!empty($groupTimestamps)) $groupMaxTs = max($groupTimestamps);
                                                                                @endphp
                                                                                <div class="overflow-hidden rounded-lg border border-black shadow-sm">
                                                                                    <div class="border-b border-black bg-slate-200 text-slate-800 px-4 py-3 text-sm font-semibold">
                                                                                        {{ $relationGroup['label'] ?? 'Relación' }}
                                                                                    </div>
                                                                                    <div class="overflow-x-auto ">
                                                                                        <table class="w-full text-left text-sm border-collapse border border-black">
                                                                                            <thead class="bg-slate-300 text-slate-800">
                                                                                                <tr>
                                                                                                    @foreach(($relationGroup['columns'] ?? []) as $relationColumn)
                                                                                                        <th class="px-3 py-2 whitespace-nowrap border-b border-black">{{ $relationColumn['label'] ?? '' }}</th>
                                                                                                    @endforeach
                                                                                                </tr>
                                                                                            </thead>
                                                                                            <tbody>
                                                                                                @foreach(($relationGroup['records'] ?? []) as $relationRecord)
                                                                                                    @php
                                                                                                        $relFecha = data_get($relationRecord, 'fechaAsignacion') ?? data_get($relationRecord, 'fecha_asignacion') ?? null;
                                                                                                        $relStyle = '';
                                                                                                        if (!empty($relFecha) && !empty($groupMaxTs)) {
                                                                                                            $relTs = @strtotime($relFecha);
                                                                                                            if ($relTs !== false && $relTs === $groupMaxTs) {
                                                                                                                $relStyle = 'color: #dc2626 !important;';
                                                                                                            }
                                                                                                        }
                                                                                                    @endphp
                                                                                                    <tr style="{{ $relStyle }}" class="bg-slate-100">
                                                                                                        @foreach(($relationGroup['columns'] ?? []) as $relationColumn)
                                                                                                            @php
                                                                                                                $relationValue = data_get($relationRecord, $relationColumn['key'] ?? '') ?? '-';
                                                                                                                $relationKey = (string) ($relationColumn['key'] ?? '');
                                                                                                                $relationType = $relationColumn['type'] ?? 'text';
                                                                                                            @endphp
                                                                                                            @if($relationType === 'status' || $relationKey === 'estado')
                                                                                                                @php
                                                                                                                    $isActive = false;
                                                                                                                    $label = '';
                                                                                                                    if (is_numeric($relationValue)) {
                                                                                                                        $isActive = (string) $relationValue === '1';
                                                                                                                        $label = $isActive ? 'Activo' : 'Inactivo';
                                                                                                                    } else {
                                                                                                                        $label = trim((string) $relationValue);
                                                                                                                        $isActive = stripos($label, 'activo') !== false && stripos($label, 'inactivo') === false;
                                                                                                                    }
                                                                                                                @endphp
                                                                                                                <td class="px-3 py-2 border-b border-black">
                                                                                                                    <div class="flex items-center">
                                                                                                                        <i data-lucide="database" class="h-3.5 w-3.5 stroke-[1.7] {{ $isActive ? 'text-danger' : 'text-slate-400' }}"></i>
                                                                                                                        <span class="ml-1.5 whitespace-nowrap font-medium {{ $isActive ? 'text-danger' : 'text-slate-500' }}">{{ $label }}</span>
                                                                                                                    </div>
                                                                                                                </td>
                                                                                                            @elseif($relationType === 'date' || str_starts_with($relationKey, 'fecha'))
                                                                                                                @php
                                                                                                                    $formattedRelationDate = '-';
                                                                                                                    if (!empty($relationValue) && $relationValue !== '0000-00-00 00:00:00') {
                                                                                                                        try {
                                                                                                                            $relationDate = \Illuminate\Support\Carbon::parse($relationValue);
                                                                                                                            $relationMonths = ['ene.', 'feb.', 'mar.', 'abr.', 'may.', 'jun.', 'jul.', 'ago.', 'sep.', 'oct.', 'nov.', 'dic.'];
                                                                                                                            $formattedRelationDate = sprintf(
                                                                                                                                '%s %s %s, %s',
                                                                                                                                $relationDate->format('d'),
                                                                                                                                $relationMonths[(int) $relationDate->format('m') - 1],
                                                                                                                                $relationDate->format('Y'),
                                                                                                                                $relationDate->format('H:i')
                                                                                                                            );
                                                                                                                        } catch (\Throwable $throwable) {
                                                                                                                            $formattedRelationDate = (string) $relationValue;
                                                                                                                        }
                                                                                                                    }
                                                                                                                @endphp
                                                                                                                <td class="px-3 py-2 whitespace-nowrap border-b border-black">{{ $formattedRelationDate }}</td>
                                                                                                            @else
                                                                                                                <td class="px-3 py-2 whitespace-nowrap border-b border-black">{{ $relationValue }}</td>
                                                                                                            @endif
                                                                                                        @endforeach
                                                                                                    </tr>
                                                                                                @endforeach
                                                                                            </tbody>
                                                                                        </table>
                                                                                    </div>
                                                                                </div>
                                                                            @endforeach
                                                                        </div>
                                                                    @else
                                                                        <table class="w-full text-left text-sm border-collapse border border-black">
                                                                            <thead class="bg-slate-300 text-slate-800">
                                                                                <tr>
                                                                                    @foreach($historyColumns as $historyColumn)
                                                                                        <th class="px-3 py-2 whitespace-nowrap border-b border-black">{{ $historyColumn['label'] ?? '' }}</th>
                                                                                    @endforeach
                                                                                </tr>
                                                                            </thead>
                                                                            <tbody>
                                                                                @foreach($historyItems as $history)
                                                                                    <tr class="bg-slate-100">
                                                                                        @foreach($historyColumns as $historyColumn)
                                                                                            @php
                                                                                                $historyValue = data_get($history, $historyColumn['key'] ?? '') ?? '-';
                                                                                                $historyType = $historyColumn['type'] ?? 'text';
                                                                                            @endphp
                                                                                            @if($historyType === 'status')
                                                                                                @php
                                                                                                    $value = $historyValue;
                                                                                                    $isActive = false;
                                                                                                    $label = '';
                                                                                                    if (is_numeric($value)) {
                                                                                                        $isActive = (string) ($value ?? '1') === '1';
                                                                                                        $label = $isActive ? 'Activo' : 'Inactivo';
                                                                                                    } else {
                                                                                                        $label = trim((string) ($value ?? ''));
                                                                                                        $isActive = stripos($label, 'activo') !== false && stripos($label, 'inactivo') === false;
                                                                                                    }
                                                                                                @endphp
                                                                                                <td class="px-3 py-2 border-b border-black">
                                                                                                    <div class="flex items-center">
                                                                                                        <i data-lucide="database" class="h-3.5 w-3.5 stroke-[1.7] {{ $isActive ? 'text-danger' : 'text-slate-400' }}"></i>
                                                                                                        <span class="ml-1.5 whitespace-nowrap font-medium {{ $isActive ? 'text-danger' : 'text-slate-500' }}">
                                                                                                            {{ $label }}
                                                                                                        </span>
                                                                                                    </div>
                                                                                                </td>
                                                                                            @else
                                                                                                <td class="px-3 py-2 whitespace-nowrap border-b border-black">{{ $historyValue }}</td>
                                                                                            @endif
                                                                                        @endforeach
                                                                                    </tr>
                                                                                @endforeach
                                                                            </tbody>
                                                                        </table>
                                                                    @endif
                                                                </div>
                                                            </div>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endif
                                        @empty
                                            <tr>
                                                <td colspan="{{ count($columns) + 2 + ($showGroupClientsColumn ? 1 : 0) + (($showActionsColumn ?? true) && $canPerformActions ? 1 : 0) }}" class="px-5 py-10 text-center text-slate-500">
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
            <div id="audit-action-detail-modal" class="fixed inset-0 hidden flex items-center justify-center px-4 py-6 backdrop-blur-sm" style="z-index: 10001; background-color: rgba(0, 0, 0, 0.78);" role="dialog" aria-modal="true" aria-labelledby="audit-action-detail-title">
                <div class="w-full rounded-xl bg-white shadow-2xl ring-1 ring-slate-900/10 border-t-4 border-red-600 overflow-hidden" style="position: relative; max-height: calc(100vh - 188px); width: min(100%, 620px); max-width: 620px;">
                    <div class="border-b border-slate-200 bg-gradient-to-r from-slate-50 to-white px-6 py-5">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h2 id="audit-action-detail-title" class="text-lg font-bold text-slate-900">Detalle completo de la acción</h2>
                                <p class="mt-1 text-sm text-slate-600">Ver el detalle completo sin salir de la lista.</p>
                            </div>
                        </div>
                    </div>
                    <div class="p-7">
                        <div id="audit-action-detail-text" class="text-slate-600 text-sm leading-7 whitespace-pre-wrap break-words"></div>
                        <div class="mt-8 text-right">
                            <button type="button" data-close-audit-action-detail class="rounded-xl bg-red-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-red-700" style="color: white; background-color: red;">Cerrar</button>
                        </div>
                    </div>
                </div>
            </div>
            <div id="delete-confirmation-modal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:9999;background:rgba(0,0,0,0.8);align-items:center;justify-content:center;" role="dialog" aria-modal="true" aria-labelledby="delete-confirmation-title" aria-describedby="delete-confirmation-message">
                <div style="width:720px;max-width:92%;margin:0 auto;position:relative;border-radius:10px;background:#ffffff;box-shadow:0 20px 40px rgba(2,6,23,0.12);overflow:hidden;">
                    <button type="button" data-delete-modal-close style="position:absolute;right:16px;top:16px;height:44px;width:44px;border-radius:9999px;border:1px solid #e6e9ee;background:#fff;color:#6b7280;display:inline-flex;align-items:center;justify-content:center;" aria-label="Cerrar">
                        <i data-lucide="x" style="width:16px;height:16px"></i>
                    </button>
                    <div style="padding:40px 48px;text-align:left;">
                        <div style="margin:0 auto 24px;display:flex;height:64px;width:64px;align-items:center;justify-content:center;border-radius:9999px;border:1px solid #ef4444;background:#fff7f7;color:#ef4444;">
                            <i data-lucide="x-circle" style="width:22px;height:22px"></i>
                        </div>
                        <h2 id="delete-confirmation-title" style="font-size:22px;font-weight:600;margin:0;color:#111827;">¿Estás seguro?</h2>
                        <p id="delete-confirmation-message" style="margin-top:12px;color:#6b7280;font-size:14px;line-height:1.6;">Esta acción eliminará el registro y no se podrá deshacer.</p>
                        <div id="delete-confirmation-details" class="mt-5 hidden rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900" style="white-space: normal; overflow-wrap: anywhere; word-break: break-word; box-sizing: border-box;"></div>
                        <div id="delete-confirmation-relations" class="mt-5 hidden rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" style="background-color: #ffe7e7; white-space: normal; overflow-wrap: anywhere; word-break: break-word; box-sizing: border-box;"></div>
                        <div id="delete-confirmation-hint" class="mt-3 hidden text-sm text-slate-600"></div>
                        <div style="margin-top:26px;display:flex;gap:12px;justify-content:flex-end;flex-wrap:wrap;align-items:center;">
                            <div id="delete-confirmation-actions" class="hidden flex flex-wrap gap-3 mr-auto"></div>
                            <button type="button" data-delete-modal-close style="min-width:120px;padding:10px 18px;border-radius:10px;border:1px solid #000000;background:#ffffff;color:#374151;font-weight:600;">Cancelar</button>
                            <button type="button" id="delete-confirmation-submit" style="min-width:120px;padding:10px 18px;border-radius:10px;background:#c71010;color:#ffffff;font-weight:600;border:none;">Eliminar</button>
                        </div>
                    </div>
                </div>
                        </div>
                        <script>
                        (function () {
                            let currentDeleteForm = null;

                            document.addEventListener('click', function (ev) {
                                const opener = ev.target.closest('[data-delete-open="true"], button[data-delete-open="true"], a[data-delete-open="true"]');
                                if (!opener) return;
                                const form = opener.closest('form.delete-confirmation-form') || opener.closest('form');
                                if (form && form.classList.contains('delete-confirmation-form')) {
                                    currentDeleteForm = form;
                                } else if (form && form.querySelector('input[name="_method"][value="DELETE"]')) {
                                    currentDeleteForm = form;
                                } else {
                                    currentDeleteForm = null;
                                }
                            });

                            const submitBtn = document.getElementById('delete-confirmation-submit');
                            if (!submitBtn) return;

                            submitBtn.addEventListener('click', function () {
                                if (this.disabled) return;
                                this.disabled = true;
                                const original = this.innerHTML;
                                this.dataset._orig = original;
                                this.textContent = 'Eliminando...';
                                this.classList.add('opacity-70', 'cursor-not-allowed');

                                if (currentDeleteForm) {
                                    const innerSub = currentDeleteForm.querySelector('button[type="submit"], input[type="submit"]');
                                    if (innerSub) innerSub.disabled = true;
                                    currentDeleteForm.submit();
                                    return;
                                }

                                const fallback = document.querySelector('form.delete-confirmation-form');
                                if (fallback) {
                                    const innerSub = fallback.querySelector('button[type="submit"], input[type="submit"]');
                                    if (innerSub) innerSub.disabled = true;
                                    fallback.submit();
                                    return;
                                }

                                console.warn('No se encontró el formulario de eliminación para enviar.');
                                setTimeout(function () {
                                    submitBtn.disabled = false;
                                    submitBtn.textContent = submitBtn.dataset._orig || 'Eliminar';
                                    submitBtn.classList.remove('opacity-70', 'cursor-not-allowed');
                                }, 3000);
                            });
                        })();
                        </script>
                        @if(!empty($bulkDestroyRoute) && $canDelete)
                <form id="bulk-delete-form" method="POST" action="{{ $bulkDestroyRoute }}" class="hidden">
                    @csrf
                    <input type="hidden" name="_method" value="{{ $bulkDestroyMethod ?? 'DELETE' }}">
                    <div id="bulk-delete-inputs"></div>
                </form>
            @endif
            @if(!empty($bulkDeactivateRoute))
                <div id="detallesimcard-bulk-deactivate-modal" class="fixed inset-0 hidden items-center justify-center px-4 py-6" style="z-index: 9999; background-color: rgba(0, 0, 0, 0.78);" role="dialog" aria-modal="true" aria-labelledby="detallesimcard-bulk-title">
                    <div class="w-full rounded-lg overflow-hidden rounded-[1.25rem] bg-white shadow-[0_24px_80px_rgba(15,23,42,0.16)] flex flex-col max-h-[85vh]" style="max-width: 980px;">
                        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-6 py-4">
                            <h3 id="detallesimcard-bulk-title" class="text-lg font-semibold text-slate-800">Dar de baja números telefónicos</h3>
                            <button type="button" data-close-detallesimcard-modal="bulk-deactivate" class="ml-auto rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700">
                                <i data-lucide="x" class="h-5 w-5"></i>
                            </button>
                        </div>
                        <form id="detallesimcard-bulk-form" method="POST" action="{{ $bulkDeactivateRoute }}" data-parse-file-url="{{ route('modules.lineas-chips.detallesimcard.bulk-deactivate.parse-file') }}" class="flex flex-col overflow-hidden">
                            @csrf
                            <input type="hidden" id="deactivate-simcards-flag" name="deactivateSimCards" value="0">
                            
                            <!-- Grid 2 columnas -->
                            <div class="grid gap-5 md:grid-cols-2 px-6 py-5 overflow-y-auto flex-1" style="min-height: 0;">
                                <!-- COLUMNA IZQUIERDA: Búsqueda y lista -->
                                <div class="flex flex-col gap-4">
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Buscar y listar número</label>
                                        <textarea id="detallesimcard-bulk-manual" name="manualNumbers" rows="3" placeholder="numero1,numero2,numero3" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-2.5 text-sm text-slate-800 transition duration-200 ease-in-out focus:border-red-600 focus:ring-2 focus:ring-red-500/20">{{ old('manualNumbers') }}</textarea>
                                    </div>
                                    
                                    <div class="flex flex-col gap-2">
                                        <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                                            <input type="checkbox" id="detallesimcard-bulk-select-all" class="h-4 w-4 rounded border-slate-300 text-primary focus:ring-primary/30">
                                            <span>Seleccionar visibles</span>
                                        </label>
                                        <button type="button" id="detallesimcard-bulk-apply-list" class="inline-flex w-full items-center justify-center rounded-lg border px-4 py-2 text-sm font-semibold shadow-sm transition hover:bg-red-700" style="background-color:#b91c1c;color:#ffffff;border-color:#b91c1c;">
                                            <i data-lucide="search" class="mr-2 h-4 w-4"></i>
                                            Aplicar búsqueda
                                        </button>
                                    </div>
                                    
                                    <div id="detallesimcard-bulk-error" class="hidden rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"></div>

                                    @php
                                        $activePhoneNumbers = collect($numeroTelefonicoStateList ?? [])->filter(function ($numeroRow) {
                                            return !empty($numeroRow['isActive']);
                                        });
                                    @endphp
                                    <div id="detallesimcard-bulk-list" class="overflow-y-auto rounded-lg border border-slate-200 bg-slate-50 p-2 flex-1" style="max-height: 260px;">
                                        @if($activePhoneNumbers->isEmpty())
                                            <div class="rounded-lg border border-dashed border-slate-200 bg-white px-3 py-3 text-sm text-slate-500">No hay números.</div>
                                        @else
                                            @foreach($activePhoneNumbers as $numeroRow)
                                                @php
                                                    $numero = (string) ($numeroRow['numero'] ?? '');
                                                @endphp
                                                <label data-bulk-numero-row="{{ mb_strtolower($numero) }}" class="flex items-center justify-between gap-3 rounded-lg border border-transparent bg-white px-3 py-2 text-sm transition hover:border-slate-200 hover:shadow-sm">
                                                    <span class="font-medium text-slate-700">{{ $numero }}</span>
                                                    <span class="inline-flex items-center gap-2">
                                                        <span class="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-emerald-700">Activo</span>
                                                        <input type="checkbox" name="selectedNumbers[]" value="{{ $numero }}" class="h-4 w-4 rounded border-slate-300 text-primary focus:ring-primary/30">
                                                    </span>
                                                </label>
                                            @endforeach
                                        @endif
                                    </div>
                                </div>
                                
                                <!-- COLUMNA DERECHA: Carga de archivo -->
                                <div class="flex flex-col gap-4">
                                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                                        <label class="mb-3 block text-sm font-medium text-slate-700">Archivo (.xlsx) con solo columna: Numero</label>
                                        <input type="file" id="detallesimcard-bulk-file" accept=".xlsx" class="disabled:bg-slate-100 disabled:cursor-not-allowed transition duration-200 ease-in-out w-full text-sm border-slate-200 shadow-sm rounded-md placeholder:text-slate-400/90 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 [&[type='file']]:border file:mr-4 file:py-2 file:px-4 file:rounded-l-md file:border-0 file:border-r-[1px] file:border-slate-100/10 file:text-sm file:font-semibold file:bg-slate-100 file:text-slate-500/70 hover:file:bg-slate-200">
                                        <button type="button" id="detallesimcard-bulk-preview-btn" class="mt-3 inline-flex w-full items-center justify-center rounded-lg border px-4 py-2 text-sm font-semibold shadow-sm transition hover:bg-red-700" style="background-color:#b91c1c;color:#ffffff;border-color:#b91c1c;">
                                            <i data-lucide="eye" class="mr-2 h-4 w-4"></i>
                                            Previsualizar
                                        </button>
                                        <p class="mt-3 text-sm text-slate-500">Primero selecciona el archivo y pulsa Previsualizar.</p>
                                        <div id="detallesimcard-import-error" class="mt-3 hidden rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"></div>
                                    </div>

                                    <div id="detallesimcard-bulk-file-preview" class="rounded-[1rem] border border-slate-200 bg-white p-4 min-h-[14rem] shadow-sm">
                                        <div id="detallesimcard-bulk-preview-empty" class="flex h-full items-center justify-center rounded-[0.85rem] border border-dashed border-slate-200 bg-slate-50 px-5 text-center text-sm text-slate-500">
                                            Aqui es donde se va a visualizar los datos
                                        </div>
                                        <div id="detallesimcard-bulk-preview-content" class="hidden">
                                            <div class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                                                <div><span class="block text-slate-500">Filas validas</span><span id="detallesimcard-bulk-stat-valid" class="font-semibold">0</span></div>
                                                <div><span class="block text-slate-500">A dar de baja</span><span id="detallesimcard-bulk-stat-new" class="font-semibold text-emerald-700">0</span></div>
                                                <div><span class="block text-slate-500">Vacías / inválidas</span><span id="detallesimcard-bulk-stat-empty-invalid" class="font-semibold text-amber-700">0</span></div>
                                                <div><span class="block text-slate-500">Dados de baja</span><span id="detallesimcard-bulk-stat-inactive" class="font-semibold text-slate-700">0</span></div>
                                                <div><span class="block text-slate-500">Duplicadas archivo</span><span id="detallesimcard-bulk-stat-dup-file" class="font-semibold text-slate-700">0</span></div>
                                                <div><span class="block text-slate-500">No existente en BD</span><span id="detallesimcard-bulk-stat-missing" class="font-semibold text-slate-700">0</span></div>
                                            </div>

                                            <div class="mt-4 rounded-[0.85rem] border border-slate-200" style="max-height: 240px; overflow-y: auto;">
                                                <table class="w-full text-left text-sm">
                                                    <thead class="bg-slate-100 text-slate-600">
                                                        <tr>
                                                            <th class="px-3 py-2">Línea</th>
                                                            <th class="px-3 py-2">Número</th>
                                                            <th class="px-3 py-2">Estado</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="detallesimcard-bulk-preview-rows"></tbody>
                                                </table>
                                            </div>
                                            <br>
                                            <div class="flex items-center justify-start gap-2 pb-3" data-preview-export-wrapper="bulk">
                                                <input type="hidden" data-preview-payload="bulk" value="">
                                                <button type="button" title="Descargar" data-preview-download="bulk" data-preview-download-url="{{ route('modules.lineas-chips.detallesimcard.preview.export', ['type' => 'bulk']) }}" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50" aria-label="Descargar">
                                                    <i data-lucide="download" class="h-4 w-4"></i>
                                                    <span class="sr-only">Descargar xlsx</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Botones finales -->
                            <div class="border-t border-slate-200 px-6 py-4 flex flex-col gap-3 sm:flex-row sm:justify-end">
                                <button type="button" data-close-detallesimcard-modal="bulk-deactivate" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-100" style=" border-color: #000000; color: #000000;">Cancelar</button>
                                <button id="detallesimcard-bulk-submit" type="submit" disabled class="rounded-lg border px-5 py-2 text-sm font-semibold shadow-sm transition hover:bg-red-800 opacity-50 cursor-not-allowed" style="background-color:#b91c1c;color:#ffffff;">Dar de baja</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif

            <div id="detallesimcard-bulk-deactivate-confirmation-modal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:10000;background:rgba(0,0,0,0.8);align-items:center;justify-content:center;" role="dialog" aria-modal="true" aria-labelledby="detallesimcard-bulk-deactivate-confirmation-title" aria-describedby="detallesimcard-bulk-deactivate-confirmation-message">
                <div style="width:640px;max-width:92%;margin:0 auto;position:relative;border-radius:10px;background:#ffffff;box-shadow:0 20px 40px rgba(2,6,23,0.12);overflow:hidden;">
                    <button type="button" data-close-deactivate-confirmation-modal aria-label="Cerrar"  style="position:absolute;right:16px;top:14px;border:0;background:transparent;color:#6b7280;padding:8px;cursor:pointer;">
                        <i data-lucide="x" style="width:18px;height:18px"></i>
                    </button>
                    <div style="padding:40px 48px;text-align:center;">
                        <div style="margin:0 auto 24px;display:flex;height:64px;width:64px;align-items:center;justify-content:center;border-radius:9999px;border:1px solid #0ea5e9;background:#eff6ff;color:#0ea5e9;">
                            <i data-lucide="help-circle" style="width:22px;height:22px"></i>
                        </div>
                        <h2 id="detallesimcard-bulk-deactivate-confirmation-title" style="font-size:22px;font-weight:600;margin:0;color:#111827;">Elige una opción para continuar</h2>
                        <p id="detallesimcard-bulk-deactivate-confirmation-message" style="margin-top:12px;color:#6b7280;font-size:14px;line-height:1.4;">¿Quieres dar de baja también los simcard relacionados?</p>
                        <div style="margin-top:26px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                            <button type="button" data-close-deactivate-confirmation-modal class="rounded-md border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-100" style=" border-color: #000000; color: #000000;">Cancelar</button>
                            <button type="button" id="detallesimcard-bulk-confirm-number-only" style="min-width:160px;padding:12px 18px;border-radius:8px;font-weight:600; border: 1px solid #c71010; background-color:#ffffff;color:#c71010;">Bajar solo número</button>
                            <button type="button" id="detallesimcard-bulk-confirm-number-with-simcard" style="min-width:160px;padding:12px 18px;border-radius:8px;font-weight:600; border: 1px solid #c71010; background-color:#ffffff;color:#c71010;">Bajar con el simcard</button>
                        </div>
                    </div>
                </div>
            </div>

            <div id="detallesimcard-bulk-validation-modal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:10001;background:rgba(0,0,0,0.72);align-items:center;justify-content:center;" role="dialog" aria-modal="true" aria-labelledby="detallesimcard-bulk-validation-title" aria-describedby="detallesimcard-bulk-validation-message">
                <div style="width:760px;max-width:94%;margin:0 auto;position:relative;border-radius:10px;background:#ffffff;box-shadow:0 20px 50px rgba(2,6,23,0.18);overflow:hidden;">
                    <div style="padding:24px 28px 18px;border-bottom:1px solid #e5e7eb;display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
                        <div>
                            <h2 id="detallesimcard-bulk-validation-title" style="margin:0;font-size:18px;font-weight:700;color:#111827;">Validación previa requerida</h2>
                            <p id="detallesimcard-bulk-validation-message" style="margin:8px 0 0; color: #000000;;font-size:14px;line-height:1.45;">Estos números tienen relación con SimCard o con Números de dispositivo. Revisa antes de continuar.</p>
                        </div>
                        <button type="button" data-close-bulk-validation-modal="true" aria-label="Cerrar" class="ml-auto rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700">
                            <i data-lucide="x" class="h-5 w-5"></i>
                        </button>
                    </div>
                    <div style="padding:18px 28px 6px;max-height:52vh;overflow-y:auto;">
                        <div id="detallesimcard-bulk-validation" class="space-y-3"></div>
                    </div>
                    <div style="padding:18px 28px 24px;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:12px;flex-wrap:wrap;">
                        <button type="button" data-close-bulk-validation-modal="true" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-100" style=" border-color: #000000; color: #000000;" >Cancelar</button>
                        <button type="button" id="detallesimcard-bulk-validation-continue" style="padding:10px 16px;border-radius:8px;border:1px solid #c71010;background:#c71010;color:#ffffff;font-weight:600;">Continuar</button>
                    </div>
                </div>
            </div>

            <!-- Carga de numeros nuevos -->
            @if(!empty($importPreviewRoute) && !empty($importProcessRoute))
                <div id="detallesimcard-import-modal" class="fixed inset-0 hidden items-center justify-center px-4" style="z-index: 9999; background-color: rgba(0, 0, 0, 0.78);" role="dialog" aria-modal="true" aria-labelledby="detallesimcard-import-title">
                    <div class="w-full rounded-lg max-w-2xl max-h-[calc(100vh-4rem)] overflow-hidden rounded-[1.25rem] bg-white shadow-[0_24px_80px_rgba(15,23,42,0.16)]">
                        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-6 py-4">
                            <h3 id="detallesimcard-import-title" class="text-lg font-semibold text-slate-800">Cargar números por archivo</h3>
                            <button type="button" data-close-detallesimcard-modal="import" class="rounded-full ml-auto p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700">
                                <i data-lucide="x" class="h-5 w-5"></i>
                            </button>
                        </div>
                        <div class="px-6 py-5 max-h-[calc(100vh-10rem)] overflow-y-auto">
                            <form id="detallesimcard-import-preview-form" method="POST" action="{{ $importPreviewRoute }}" enctype="multipart/form-data" class="space-y-4">
                                @csrf
                                <div class="rounded-[1rem] border border-slate-200 bg-slate-50 p-4 shadow-sm">
                                    <div class="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-slate-700">Archivo (.xlsx) con las columnas: Numero, SimCard</label>
                                            <input type="file" name="importFile" accept=".xlsx" required class="disabled:bg-slate-100 disabled:cursor-not-allowed transition duration-200 ease-in-out w-full text-sm border-slate-200 shadow-sm rounded-md placeholder:text-slate-400/90 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 [&[type='file']]:border file:mr-4 file:py-2 file:px-4 file:rounded-l-md file:border-0 file:border-r-[1px] file:border-slate-100/10 file:text-sm file:font-semibold file:bg-slate-100 file:text-slate-500/70 hover:file:bg-slate-200">
                                        </div>
                                        <button type="submit" class="inline-flex h-12 items-center justify-center rounded-xl bg-danger px-6 py-2 text-sm font-semibold text-white transition hover:bg-danger/90">
                                            <i data-lucide="eye" class="mr-2 h-4 w-4"></i>
                                            Previsualizar
                                        </button>
                                    </div>
                                    <p class="mt-3 text-sm text-slate-500">Primero selecciona el archivo y pulsa Previsualizar.</p>
                                    <div id="detallesimcard-import-error" class="mt-3 hidden rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"></div>
                                </div>
                            </form>

                            <div id="detallesimcard-import-preview" class="mt-4 rounded-[1rem] border border-slate-200 bg-white p-4 min-h-[20rem] shadow-sm">
                                @if(is_array($detallesimcardImportPreview ?? null))
                                    <div class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                                        <div><span class="block text-slate-500">Filas válidas</span><span class="font-semibold">{{ (int) ($detallesimcardImportPreview['candidateCount'] ?? 0) }}</span></div>
                                        <div><span class="block text-slate-500">Nuevas</span><span class="font-semibold text-emerald-700">{{ (int) ($detallesimcardImportPreview['newRows'] ?? 0) }}</span></div>
                                        <div><span class="block text-slate-500">Vacías</span><span class="font-semibold text-amber-700">{{ (int) ($detallesimcardImportPreview['emptyRows'] ?? 0) }}</span></div>
                                        <div><span class="block text-slate-500">Inválidas</span><span class="font-semibold text-amber-700">{{ (int) ($detallesimcardImportPreview['invalidRows'] ?? 0) }}</span></div>
                                        <div><span class="block text-slate-500">Duplicadas archivo</span><span class="font-semibold text-slate-700">{{ (int) ($detallesimcardImportPreview['fileDuplicateRows'] ?? 0) }}</span></div>
                                        <div><span class="block text-slate-500">Existente en BD</span><span class="font-semibold text-slate-700">{{ (int) ($detallesimcardImportPreview['duplicateExistingRows'] ?? 0) }}</span></div>
                                    </div>

                                    <div class="mt-4 rounded-[0.85rem] border border-slate-200" style="max-height: 240px; overflow-y: auto;">
                                        <table class="w-full text-left text-sm">
                                            <thead class="bg-slate-100 text-slate-600">
                                                <tr>
                                                    <th class="px-3 py-2">Línea</th>
                                                    <th class="px-3 py-2">Número</th>
                                                    <th class="px-3 py-2">SimCard</th> 
                                                    <th class="px-3 py-2">Operador</th>
                                                    <th class="px-3 py-2">Estado</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach(($detallesimcardImportPreview['previewRows'] ?? []) as $previewRow)
                                                    <tr class="border-t border-slate-100">
                                                        <td class="px-3 py-2">{{ $previewRow['line'] ?? '-' }}</td>
                                                        <td class="px-3 py-2">{{ $previewRow['numero'] ?? '' }}</td>
                                                        <td class="px-3 py-2">{{ $previewRow['simcard'] ?? '' }}</td>
                                                        <td class="px-3 py-2">{{ $previewRow['operador'] ?? '' }}</td>
                                                        <td class="px-3 py-2">{{ $previewRow['status'] ?? '' }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="mt-4 flex items-center justify-start" data-preview-export-wrapper="import">
                                        <input type="hidden" data-preview-payload="import" value="{{ rawurlencode(json_encode($detallesimcardImportPreview ?? [])) }}">
                                        <button type="button" data-preview-download="import" data-preview-download-url="{{ route('modules.lineas-chips.detallesimcard.preview.export', ['type' => 'import']) }}" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50" aria-label="Descargar en xlsx">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                                <polyline points="7 10 12 15 17 10"></polyline>
                                                <line x1="12" y1="15" x2="12" y2="3"></line>
                                            </svg>
                                            <span>Descargar en xlsx</span>
                                        </button>
                                    </div>
                                    <div class="mt-4 flex items-center justify-end gap-3">
                                        <button type="button" onclick="closeDetallesimcardModal('import')" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-100" style=" border-color: #000000; color: #000000;">Cancelar</button>
                                        <button id="detallesimcard-import-save-button" type="submit" class="rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">Guardar cambios</button>
                                    </div>
                                @else
                                    <div class="flex h-full min-h-[18rem] items-center justify-center rounded-[0.85rem] border border-dashed border-slate-200 bg-slate-50 px-5 text-center text-sm text-slate-500">
                                        Aquí es donde se va a visualizar los datos
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endif
    <style>
        /* Force compact appearance for action dropdowns inside table rows */
        .dropdown--action .dropdown-content {
            min-width: 100px !important;
            max-width: 140px !important;
            width: auto !important;
            padding: 6px !important;
        }
        .dropdown--action .dropdown-content .dropdown-item {
            padding: 6px 8px !important;
            font-size: 0.95rem !important;
        }

        .th-sort {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            user-select: none;
        }

        .th-sort--center {
            justify-content: center;
            width: 100%;
        }

        .th-sort__icon {
            position: relative;
            width: 12px;
            height: 16px;
            display: inline-block;
            opacity: 0.65;
        }

        .th-sort__icon::before,
        .th-sort__icon::after {
            content: "";
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            border-left: 5px solid transparent;
            border-right: 5px solid transparent;
        }

        .th-sort__icon::before {
            top: 0;
            border-bottom: 6px solid #94a3b8;
        }

        .th-sort__icon::after {
            bottom: 0;
            border-top: 6px solid #94a3b8;
        }

        .th-sort.is-asc .th-sort__icon::before {
            border-bottom-color: #c1121f;
        }

        .th-sort.is-asc .th-sort__icon::after {
            border-top-color: #cbd5e1;
            opacity: 0.6;
        }

        .th-sort.is-desc .th-sort__icon::after {
            border-top-color: #c1121f;
        }

        .th-sort.is-desc .th-sort__icon::before {
            border-bottom-color: #cbd5e1;
            opacity: 0.6;
        }

        #detallesimcard-bulk-deactivate-modal input:focus,
        #detallesimcard-bulk-deactivate-modal textarea:focus {
            border-color: #dc2626 !important;
            box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.18) !important;
            outline: none !important;
        }

        #list-select-all:checked,
        #list-select-all:indeterminate,
        .list-row-checkbox:checked {
            accent-color: #c1121f !important;
            background-color: #c1121f !important;
            border-color: #c1121f !important;
        }

        /* Estilo para filas expandidas */
        tr.expanded {
            background-color: #ffd8d8 !important; 
            border-left: 5px solid #fb7185 !important; 
            box-shadow: inset 0 0 0 1px rgba(251, 113, 133, 0.18) !important;
        }

        #list-table-wrapper table th,
        #list-table-wrapper table td {
            font-size: 0.85rem !important;
            padding: 0.88rem 0.5rem !important;
            vertical-align: middle !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            white-space: nowrap !important;
        }
        /* límites por columna para mantener proporciones razonables */
        #list-table-wrapper table td { 
            max-width: 155px; 
        }
        #list-table-wrapper table td:first-child { 
            max-width: 48px; 
        }
        #list-table-wrapper table td:last-child { 
            max-width: 120px; 
        }
        /* evitar truncar botones/acciones si usan elementos interactivos */
        #list-table-wrapper table td .btn, 
        #list-table-wrapper table td .dropdown { 
            white-space: normal !important; overflow: visible !important; 
        }

    </style>

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
            let selectAllCheckbox = null;
            let rowCheckboxes = [];
            let bulkDeleteButton = null;
            let bulkDeleteForm = null;
            let bulkDeleteInputs = null;
            const hasBulkDestroyRoute = {{ (!empty($bulkDestroyRoute) && $canDelete) ? 'true' : 'false' }};

            const getWrapper = () => document.getElementById(listWrapperId);
            const getForm = () => document.getElementById(formId);
            const getSearchInput = () => form ? form.querySelector('[name="q"]') : null;

            const getPageSizeElement = () => wrapper ? wrapper.querySelector('[name="perPage"]') : null;

            const updateBulkActionState = () => {
                if (!selectAllCheckbox || !rowCheckboxes.length) {
                    return;
                }

                const selectedCount = rowCheckboxes.filter((checkbox) => checkbox.checked).length;
                if (bulkDeleteButton) {
                    bulkDeleteButton.classList.toggle('hidden', selectedCount === 0);
                }
                selectAllCheckbox.checked = selectedCount > 0 && selectedCount === rowCheckboxes.length;
                selectAllCheckbox.indeterminate = selectedCount > 0 && selectedCount < rowCheckboxes.length;

                if (bulkDeleteForm && bulkDeleteInputs) {
                    bulkDeleteInputs.innerHTML = '';
                    rowCheckboxes.forEach((checkbox) => {
                        if (!checkbox.checked) {
                            return;
                        }
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'selectedIds[]';
                        input.value = checkbox.value;
                        bulkDeleteInputs.appendChild(input);
                    });
                }
            };

            const handleSelectAllChange = () => {
                if (!selectAllCheckbox) {
                    return;
                }
                const checked = selectAllCheckbox.checked;
                rowCheckboxes.forEach((checkbox) => {
                    checkbox.checked = checked;
                });
                updateBulkActionState();
            };

            const handleRowCheckboxChange = () => {
                updateBulkActionState();
            };

            const handleSelectAllInputChange = (target) => {
                if (!(target instanceof HTMLInputElement)) {
                    return;
                }
                const checked = target.checked;
                rowCheckboxes.forEach((checkbox) => {
                    checkbox.checked = checked;
                });
                updateBulkActionState();
            };

            const handleBulkDeleteButtonClick = (event) => {
                if (!bulkDeleteButton || !bulkDeleteForm) {
                    return;
                }
                if (!event.target.closest('#bulk-delete-button')) {
                    return;
                }
                event.preventDefault();
                updateBulkActionState();
                const selectedCount = rowCheckboxes.filter((checkbox) => checkbox.checked).length;
                if (selectedCount === 0) {
                    return;
                }
                closeOpenDropdowns();
                openDeleteConfirmation(bulkDeleteForm, '¿Estás seguro? Esta acción eliminará los registros seleccionados y no se podrá deshacer.', 'bulk-delete');
            };

            document.addEventListener('change', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLInputElement)) {
                    return;
                }
                if (target.id === 'list-select-all') {
                    handleSelectAllInputChange(target);
                }
                if (target.classList.contains('list-row-checkbox')) {
                    handleRowCheckboxChange();
                }
            });

            const initBulkSelection = () => {
                if (!wrapper) {
                    return;
                }

                selectAllCheckbox = wrapper.querySelector('#list-select-all');
                rowCheckboxes = Array.from(wrapper.querySelectorAll('.list-row-checkbox'));
                bulkDeleteButton = document.getElementById('bulk-delete-button');
                bulkDeleteForm = document.getElementById('bulk-delete-form');
                bulkDeleteInputs = bulkDeleteForm ? bulkDeleteForm.querySelector('#bulk-delete-inputs') : null;

                if (selectAllCheckbox) {
                    selectAllCheckbox.removeEventListener('change', handleSelectAllChange);
                    selectAllCheckbox.addEventListener('change', handleSelectAllChange);
                }

                rowCheckboxes.forEach((checkbox) => {
                    checkbox.removeEventListener('change', handleRowCheckboxChange);
                    checkbox.addEventListener('change', handleRowCheckboxChange);
                });

                if (bulkDeleteButton) {
                    bulkDeleteButton.removeEventListener('click', handleBulkDeleteButtonClick);
                    bulkDeleteButton.addEventListener('click', handleBulkDeleteButtonClick);
                }

                updateBulkActionState();
            };

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

            const updateExportLinks = () => {
                if (!form) {
                    return;
                }

                const params = getRequestParams();

                form.querySelectorAll('[data-export-link]').forEach((link) => {
                    const baseHref = link.getAttribute('data-export-base') || link.href;
                    if (!baseHref) {
                        return;
                    }
                    const exportUrl = new URL(baseHref, window.location.origin);
                    exportUrl.search = params.toString();
                    link.href = exportUrl.toString();
                });
            };

            const handleExportClick = (event) => {
                const link = event.target.closest('[data-export-link]');
                if (!link) {
                    return;
                }

                event.preventDefault();

                // Obtener IDs seleccionados
                const selectedIds = rowCheckboxes
                    .filter((checkbox) => checkbox.checked)
                    .map((checkbox) => checkbox.value)
                    .filter((value) => value !== '');

                // Si hay selecciones, usar POST; si no, usar GET
                if (selectedIds.length > 0) {
                    const format = link.getAttribute('data-export-format') || 'pdf';
                    const baseHref = link.getAttribute('data-export-base') || link.href;
                    if (!baseHref) {
                        return;
                    }

                    // Extraer la ruta base sin parámetros
                    const url = new URL(baseHref, window.location.origin);
                    const exportUrl = url.pathname.replace(/\?.*$/, '');

                    // Crear formulario oculto para POST
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = exportUrl;
                    form.style.display = 'none';

                    // Agregar token CSRF
                    const token = document.querySelector('meta[name="csrf-token"]')?.content;
                    if (token) {
                        const tokenInput = document.createElement('input');
                        tokenInput.type = 'hidden';
                        tokenInput.name = '_token';
                        tokenInput.value = token;
                        form.appendChild(tokenInput);
                    }

                    // Agregar IDs seleccionados
                    selectedIds.forEach((id) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'selectedIds[]';
                        input.value = id;
                        form.appendChild(input);
                    });

                    // Agregar filtros también
                    const params = getRequestParams();
                    for (const [key, value] of params.entries()) {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = key;
                        input.value = value;
                        form.appendChild(input);
                    }

                    document.body.appendChild(form);
                    form.submit();
                    document.body.removeChild(form);
                } else {
                    // Si no hay selecciones, usar el href normal (GET)
                    window.location.href = link.href;
                }
            };

            // Agregar event listener para exportación
            if (!window.hasExportListener) {
                document.addEventListener('click', handleExportClick);
                window.hasExportListener = true;
            }

            const handlePageSizeChange = () => {
                const url = buildUrl();
                updateExportLinks();
                fetchList(url);
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

            const restoreIcons = () => {
                try {
                    if (typeof createIcons === 'function') {
                        createIcons({ icons, attrs: { 'stroke-width': 1.5 }, nameAttr: 'data-lucide' });
                        return;
                    }
                    if (window.lucide && typeof window.lucide.createIcons === 'function') {
                        window.lucide.createIcons();
                    }
                } catch (error) {
                    console.warn('restoreIcons failed:', error);
                }
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
                        // Use a simple fade-out hide to avoid transform-based reflow/behind animation
                        menu.classList.add('hidden');
                        menu.classList.add('invisible');
                        menu.classList.add('opacity-0');
                        menu.classList.add('pointer-events-none');
                        menu.classList.remove('visible');
                        menu.classList.remove('opacity-100');
                        menu.classList.remove('pointer-events-auto');
                        menu.classList.remove('show');

                        // If we portaled the menu (set fixed positioning), restore previous inline styles
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

                const viewportMargin = 12; // px
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

            const initSortHeaders = () => {
                if (!wrapper) return;

                const table = wrapper.querySelector('table');
                if (!table) return;

                const tbody = table.querySelector('tbody');
                if (!tbody) return;

                const headers = Array.from(wrapper.querySelectorAll('.th-sort[data-sort-index]'));
                const updateIndicators = (activeIndex, direction) => {
                    headers.forEach((header) => {
                        const index = Number(header.getAttribute('data-sort-index'));
                        header.classList.remove('is-asc', 'is-desc');
                        if (index === activeIndex) {
                            header.classList.add(direction === 'desc' ? 'is-desc' : 'is-asc');
                        }
                    });
                };

                const getCellValue = (row, index) => {
                    const cell = row.children[index];
                    if (!cell) return '';
                    return (cell.textContent || '').trim();
                };

                const sortRows = (index, direction) => {
                    const rows = Array.from(tbody.querySelectorAll('tr')).filter((row) => {
                        const firstCell = row.children[0];
                        return !(row.children.length === 1 && firstCell && firstCell.hasAttribute('colspan'));
                    });

                    const isNumeric = rows.every((row) => {
                        const value = getCellValue(row, index).replace(/[,\s]/g, '');
                        return value !== '' && !Number.isNaN(Number(value));
                    });

                    rows.sort((a, b) => {
                        const aVal = getCellValue(a, index);
                        const bVal = getCellValue(b, index);
                        if (isNumeric) {
                            const aNum = Number(aVal.replace(/[,\s]/g, ''));
                            const bNum = Number(bVal.replace(/[,\s]/g, ''));
                            return direction === 'desc' ? bNum - aNum : aNum - bNum;
                        }
                        const result = aVal.localeCompare(bVal, 'es', { numeric: true, sensitivity: 'base' });
                        return direction === 'desc' ? -result : result;
                    });

                    rows.forEach((row) => tbody.appendChild(row));
                };

                headers.forEach((header) => {
                    header.onclick = () => {
                        const index = Number(header.getAttribute('data-sort-index'));
                        const currentIndex = Number(table.dataset.sortIndex || '-1');
                        const currentDirection = table.dataset.sortDirection || 'asc';
                        const nextDirection = currentIndex === index && currentDirection === 'asc' ? 'desc' : 'asc';

                        table.dataset.sortIndex = String(index);
                        table.dataset.sortDirection = nextDirection;

                        sortRows(index, nextDirection);
                        updateIndicators(index, nextDirection);
                    };

                    header.onkeydown = (event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            header.click();
                        }
                    };
                });
            };

            const initDropdowns = () => {
                if (!wrapper) {
                    return;
                }

                // Dropdown fallback local to list wrapper, resilient after AJAX HTML replacement.
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
                            // Mark expanded state on the toggle
                            toggle.setAttribute('aria-expanded', 'true');

                            // Save inline styles to restore on close
                            menu.dataset.prevPosition = menu.style.position || '';
                            menu.dataset.prevLeft = menu.style.left || '';
                            menu.dataset.prevRight = menu.style.right || '';
                            menu.dataset.prevTop = menu.style.top || '';
                            menu.dataset.prevZIndex = menu.style.zIndex || '';
                            menu.dataset.prevMinWidth = menu.style.minWidth || '';
                            menu.dataset.prevWidth = menu.style.width || '';
                            menu.dataset.prevMaxWidth = menu.style.maxWidth || '';
                            menu.dataset.portal = 'true';

                            // Prepare as a fixed, offscreen element for measurement to avoid layout reflow/flicker
                            menu.style.position = 'fixed';
                            menu.style.left = '-9999px';
                            menu.style.top = '-9999px';
                            menu.style.zIndex = '9999';
                            menu.style.width = 'auto';
                            menu.style.maxWidth = '90vw';
                            menu.style.visibility = 'hidden';
                            menu.style.pointerEvents = 'none';

                            // Make sure it's renderable (not display:none) so measurements work
                            menu.classList.remove('hidden');

                            requestAnimationFrame(() => {
                                try {
                                    const rect = toggle.getBoundingClientRect();

                                    // Natural content width
                                    const naturalWidth = Math.ceil(menu.scrollWidth || menu.offsetWidth || 120);
                                    const buttonWidth = Math.ceil(rect.width || toggle.offsetWidth || 0);

                                    // Allow filter dropdowns (with form controls) to be slightly larger,
                                    // keep action dropdowns compact.
                                    let desiredWidth;
                                    const isActionDropdown = Boolean(dropdown.closest('td'));
                                    const hasFormControls = Boolean(menu.querySelector('select, input[type="text"], input[type="search"], textarea, button[type="submit"], [data-list-clear]'));
                                    if (isActionDropdown) {
                                        const minActionWidth = 100;
                                        const maxActionWidth = 140;
                                        desiredWidth = Math.max(buttonWidth, minActionWidth);
                                        desiredWidth = Math.min(desiredWidth, maxActionWidth);
                                    } else if (hasFormControls) {
                                        // Slightly larger for filter forms
                                        const minFormWidth = 230;
                                        const maxFormWidth = 230;
                                        desiredWidth = Math.max(Math.ceil(naturalWidth + 16), buttonWidth, minFormWidth);
                                        desiredWidth = Math.min(desiredWidth, maxFormWidth);
                                    } else {
                                        const minWidth = 110;
                                        const maxWidth = 140;
                                        desiredWidth = Math.max(buttonWidth, minWidth);
                                        desiredWidth = Math.min(desiredWidth, maxWidth);
                                    }
                                    menu.style.minWidth = desiredWidth + 'px';
                                    menu.style.right = 'auto';
                                    const inner = menu.querySelector('.dropdown-content');
                                    if (inner) {
                                        inner.style.boxSizing = 'border-box';
                                        inner.style.width = desiredWidth + 'px';
                                        inner.style.minWidth = desiredWidth + 'px';
                                        inner.style.maxWidth = desiredWidth + 'px';
                                        // compact padding for action-like menus only
                                        inner.style.padding = isActionDropdown ? '6px' : '';
                                    }

                                    // Place below the button, aligned to the button's left
                                    positionMenu(menu, toggle);

                                    // Now reveal without transform animations: use opacity fade only
                                    menu.style.visibility = '';
                                    menu.style.pointerEvents = '';
                                    // Ensure transitions only affect opacity to avoid 'coming from behind' feel
                                    menu.style.transition = 'opacity 150ms ease-out';
                                    // Clear any transform so we don't animate translate/scale
                                    menu.style.transform = 'none';

                                    menu.classList.remove('invisible', 'opacity-0', 'pointer-events-none');
                                    menu.classList.add('visible', 'opacity-100', 'pointer-events-auto', 'show');

                                    // Keep menu aligned to the button when zoom/viewport changes
                                    attachMenuReposition(menu, toggle);
                                } catch (e) {
                                    console.warn('Dropdown portal positioning failed', e);
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

            const closeOpenDropdowns = () => {
                closeLocalDropdowns();
            };

            const replaceWrapper = async (html) => {
                closeOpenDropdowns();
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const nextWrapper = doc.getElementById(listWrapperId);
                if (!nextWrapper) {
                    return;
                }
                wrapper.innerHTML = nextWrapper.innerHTML;
                restoreIcons();
                initDropdowns();
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

                closeOpenDropdowns();
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
                updateExportLinks();
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
                    updateExportLinks();
                    fetchList(url, { preserveSearchFocus: true });
                }, 350);
            };

            const handleClear = (event) => {
                event.preventDefault();
                clearFilterInputs();
                const url = new URL(form.action, window.location.origin).toString();
                updateExportLinks();
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
                updateExportLinks();
                fetchList(url);
            };

            const attachPaginationLinks = () => {
                if (!wrapper) return;
                wrapper.querySelectorAll('nav a[href]').forEach((link) => {
                    const href = link.getAttribute('href');
                    if (!href || href === 'javascript:;' || href.startsWith('#')) {
                        return;
                    }
                    link.addEventListener('click', (event) => {
                        const pageUrl = event.currentTarget.href;
                        event.preventDefault();
                        fetchList(pageUrl);
                    });
                });
            };

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
            let activeDeleteSummary = null;
            let activeDeleteMode = '';
            let relationSummaryCache = new Map();

            let auditActionDetailModal = null;
            let auditActionDetailText = null;

            let detallesimcardBulkModal = null;
            let detallesimcardImportModal = null;
            let detallesimcardBulkDeactivateConfirmationModal = null;
            let detallesimcardBulkValidationModal = null;
            let detallesimcardBulkValidation = null;
            let bulkFormSubmittingAfterConfirmation = false;
            let bulkValidationAcknowledged = false;
            let bulkValidationKey = '';

            const clearBulkDeactivateModal = () => {
                const bulkSearch = document.getElementById('detallesimcard-bulk-search');
                const bulkManual = document.getElementById('detallesimcard-bulk-manual');
                const bulkSelectAll = document.getElementById('detallesimcard-bulk-select-all');
                const bulkFile = document.getElementById('detallesimcard-bulk-file');
                const bulkPayload = document.querySelector('[data-preview-payload="bulk"]');
                const previewEmpty = document.getElementById('detallesimcard-bulk-preview-empty');
                const previewContent = document.getElementById('detallesimcard-bulk-preview-content');
                const previewRows = document.getElementById('detallesimcard-bulk-preview-rows');
                const previewStats = [
                    'detallesimcard-bulk-stat-valid',
                    'detallesimcard-bulk-stat-new',
                    'detallesimcard-bulk-stat-empty-invalid',
                    'detallesimcard-bulk-stat-inactive',
                    'detallesimcard-bulk-stat-dup-file',
                    'detallesimcard-bulk-stat-missing',
                ];
                const submitBtn = document.getElementById('detallesimcard-bulk-submit');
                const flag = document.getElementById('deactivate-simcards-flag');
                const listWrapper = document.getElementById('detallesimcard-bulk-list');
                if (bulkSearch) {
                    bulkSearch.value = '';
                }
                if (bulkManual) {
                    bulkManual.value = '';
                }
                if (bulkFile) {
                    bulkFile.value = '';
                }
                if (bulkPayload instanceof HTMLInputElement) {
                    bulkPayload.value = '';
                }
                if (bulkSelectAll) {
                    bulkSelectAll.checked = false;
                }
                if (listWrapper) {
                    listWrapper.querySelectorAll('[data-bulk-numero-row]').forEach((row) => {
                        if (row instanceof HTMLElement) {
                            row.style.display = '';
                        }
                    });
                    listWrapper.querySelectorAll('input[name="selectedNumbers[]"]').forEach((checkbox) => {
                        if (checkbox instanceof HTMLInputElement) {
                            checkbox.checked = false;
                        }
                    });
                }
                if (previewRows) {
                    previewRows.innerHTML = '';
                }
                if (previewEmpty) {
                    previewEmpty.classList.remove('hidden');
                }
                if (previewContent) {
                    previewContent.classList.add('hidden');
                }
                hideBulkValidation();
                previewStats.forEach((id) => {
                    const element = document.getElementById(id);
                    if (element) {
                        element.textContent = '0';
                    }
                });
                if (submitBtn) {
                    submitBtn.setAttribute('disabled', 'disabled');
                    submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                }
                if (flag) {
                    flag.value = '0';
                }
                hideBulkError();
                bulkValidationAcknowledged = false;
                bulkValidationKey = '';
            };

            const clearImportPreviewModal = () => {
                const previewForm = document.getElementById('detallesimcard-import-preview-form');
                const previewContainer = document.getElementById('detallesimcard-import-preview');
                const importError = document.getElementById('detallesimcard-import-error');
                if (previewForm) {
                    previewForm.reset();
                }
                if (previewContainer) {
                    previewContainer.innerHTML = '<div class="flex h-full min-h-[18rem] items-center justify-center rounded-[0.85rem] border border-dashed border-slate-200 bg-slate-50 px-5 text-center text-sm text-slate-500">Aquí es donde se va a visualizar los datos</div>';
                }
                if (importError) {
                    importError.textContent = '';
                    importError.classList.add('hidden');
                    importError.classList.remove('block');
                }
            };

            const closeDetallesimcardModal = (type) => {
                const target = type === 'import' ? detallesimcardImportModal : detallesimcardBulkModal;
                if (!target) {
                    return;
                }
                target.classList.add('hidden');
                target.classList.remove('flex');
                target.style.display = 'none';
                if (type === 'import') {
                    clearImportPreviewModal();
                }
                if (type === 'bulk-deactivate') {
                    clearBulkDeactivateModal();
                }
                document.body.style.overflow = '';
            };

            const openDetallesimcardModal = (type) => {
                const target = type === 'import' ? detallesimcardImportModal : detallesimcardBulkModal;
                if (!target) {
                    return;
                }
                if (type === 'bulk-deactivate') {
                    clearBulkDeactivateModal();
                }
                if (type === 'import') {
                    clearImportPreviewModal();
                }
                target.style.display = 'flex';
                target.classList.remove('hidden');
                target.classList.add('flex');
                document.body.style.overflow = 'hidden';
            };

            window.openDetallesimcardModal = openDetallesimcardModal;
            window.closeDetallesimcardModal = closeDetallesimcardModal;

            const showImportError = (message) => {
                const importError = document.getElementById('detallesimcard-import-error');
                if (!importError) {
                    return;
                }
                importError.textContent = message;
                importError.classList.remove('hidden');
                importError.classList.add('block');
            };

            const hideImportError = () => {
                const importError = document.getElementById('detallesimcard-import-error');
                if (!importError) {
                    return;
                }
                importError.textContent = '';
                importError.classList.add('hidden');
                importError.classList.remove('block');
            };

            const setButtonLoading = (button, loadingText) => {
                if (!(button instanceof HTMLButtonElement)) {
                    return;
                }
                button.disabled = true;
                button.classList.add('opacity-70', 'cursor-not-allowed');
                button.innerHTML = `<span class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white mr-2"></span>${loadingText}`;
            };

            const getPreviewTitle = (type) => {
                return type === 'bulk'
                    ? 'Previsualización: Dar de baja números'
                    : 'Previsualización: Cargar números por archivo';
            };

            const getPreviewContainer = (type) => {
                return document.getElementById(type === 'bulk' ? 'detallesimcard-bulk-file-preview' : 'detallesimcard-import-preview');
            };

            const getPreviewPayloadInput = (trigger) => {
                const wrapper = trigger instanceof Element ? trigger.closest('[data-preview-export-wrapper]') : null;
                if (!wrapper) {
                    return null;
                }
                return wrapper.querySelector('[data-preview-payload]');
            };

            const downloadPreviewXlsx = async (type, trigger) => {
                const exportUrl = trigger instanceof Element ? trigger.getAttribute('data-preview-download-url') : '';
                if (!exportUrl) {
                    throw new Error('No se encontró la URL de exportación del preview.');
                }

                const payloadInput = getPreviewPayloadInput(trigger);
                const previewPayload = payloadInput instanceof HTMLInputElement ? String(payloadInput.value || '').trim() : '';
                if (previewPayload === '') {
                    throw new Error('No hay datos de previsualización para exportar.');
                }

                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                const formData = new FormData();
                formData.append('previewPayload', previewPayload);

                const response = await fetch(exportUrl, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    },
                    body: formData,
                });

                if (!response.ok) {
                    let message = 'Error al generar el archivo Excel.';
                    try {
                        const payload = await response.json();
                        message = payload?.message || message;
                    } catch {
                        const text = await response.text();
                        if (text) {
                            message = text;
                        }
                    }
                    throw new Error(message);
                }

                const blob = await response.blob();
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = `${type === 'bulk' ? 'baja_numeros_preview' : 'carga_numeros_preview'}_${new Date().toISOString().replace(/[:.]/g, '-')}.xlsx`;
                document.body.appendChild(link);
                link.click();
                link.remove();
                URL.revokeObjectURL(url);
            };

            window.detallesimcardDownloadPreview = downloadPreviewXlsx;

            const PREVIEW_CHUNK_SIZE = 10;
            let detallesimcardImportPreviewData = null;
            let detallesimcardImportPreviewIndex = 0;
            let detallesimcardBulkPreviewData = null;
            let detallesimcardBulkPreviewIndex = 0;

            const getPreviewRowsChunk = (type) => {
                const preview = type === 'bulk' ? detallesimcardBulkPreviewData : detallesimcardImportPreviewData;
                if (!preview) {
                    return [];
                }

                const rows = Array.isArray(preview.allRows)
                    ? preview.allRows
                    : Array.isArray(preview.previewRows)
                        ? preview.previewRows
                        : [];

                const currentIndex = type === 'bulk' ? detallesimcardBulkPreviewIndex : detallesimcardImportPreviewIndex;
                const nextRows = rows.slice(currentIndex, currentIndex + PREVIEW_CHUNK_SIZE);

                if (type === 'bulk') {
                    detallesimcardBulkPreviewIndex += nextRows.length;
                } else {
                    detallesimcardImportPreviewIndex += nextRows.length;
                }

                return nextRows;
            };

            const buildPreviewRowsHtml = (rows, type) => {
                return (rows || []).map((row) => {
                    if (type === 'bulk') {
                        return `
                            <tr class="border-t border-slate-100">
                                <td class="px-3 py-2">${row.line ?? '-'}</td>
                                <td class="px-3 py-2">${row.numero ?? ''}</td>
                                <td class="px-3 py-2">${row.status ?? ''}</td>
                            </tr>
                        `;
                    }

                    return `
                        <tr class="border-t border-slate-100">
                            <td class="px-3 py-2">${row.line ?? '-'}</td>
                            <td class="px-3 py-2">${row.numero ?? ''}</td>
                            <td class="px-3 py-2">${row.simcard ?? ''}</td>
                            <td class="px-3 py-2">${row.operador ?? ''}</td>
                            <td class="px-3 py-2">${row.status ?? ''}</td>
                        </tr>
                    `;
                }).join('');
            };

            const attachPreviewScroll = (rowsBody, type) => {
                if (!(rowsBody instanceof HTMLElement)) {
                    return;
                }
                const scrollWrapper = rowsBody.closest('div[style*="overflow-y: auto"]');
                if (!(scrollWrapper instanceof HTMLElement) || scrollWrapper.dataset.previewScrollAttached === 'true') {
                    return;
                }

                scrollWrapper.dataset.previewScrollAttached = 'true';
                scrollWrapper.addEventListener('scroll', () => {
                    if (scrollWrapper.scrollTop + scrollWrapper.clientHeight < scrollWrapper.scrollHeight - 20) {
                        return;
                    }

                    const nextRows = getPreviewRowsChunk(type);
                    if (nextRows.length === 0) {
                        return;
                    }

                    rowsBody.insertAdjacentHTML('beforeend', buildPreviewRowsHtml(nextRows, type));
                });
            };

            document.addEventListener('click', async (event) => {
                const target = event.target instanceof Element ? event.target.closest('[data-preview-download]') : null;
                if (!target) {
                    return;
                }

                const downloadType = target.getAttribute('data-preview-download');

                if (downloadType) {
                    event.preventDefault();
                    try {
                        await downloadPreviewXlsx(downloadType, target);
                    } catch (error) {
                        alert(error?.message || 'Error al generar el archivo Excel.');
                    }
                }
            });

            const renderImportPreview = (preview) => {
                detallesimcardImportPreviewData = preview;
                detallesimcardImportPreviewIndex = 0;

                const previewContainer = document.getElementById('detallesimcard-import-preview');
                if (!previewContainer) {
                    return;
                }

                const summaryHtml = `
                    <div class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                        <div><span class="block text-slate-500">Filas válidas</span><span class="font-semibold">${preview.candidateCount}</span></div>
                        <div><span class="block text-slate-500">Nuevas</span><span class="font-semibold text-emerald-700">${preview.newRows}</span></div>
                        <div><span class="block text-slate-500">Vacías</span><span class="font-semibold text-amber-700">${preview.emptyRows}</span></div>
                        <div><span class="block text-slate-500">Inválidas</span><span class="font-semibold text-amber-700">${preview.invalidRows}</span></div>
                        <div><span class="block text-slate-500">Duplicadas archivo</span><span class="font-semibold text-slate-700">${preview.fileDuplicateRows}</span></div>
                        <div><span class="block text-slate-500">Existente en BD</span><span class="font-semibold text-slate-700">${preview.duplicateExistingRows}</span></div>
                    </div>
                `;

                const initialRows = getPreviewRowsChunk('import');
                previewContainer.innerHTML = `
                    ${summaryHtml}
                    <div class="mt-4 rounded-[0.85rem] border border-slate-200" style="max-height: 240px; overflow-y: auto;">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-100 text-slate-600">
                                <tr>
                                    <th class="px-3 py-2">Línea</th>
                                    <th class="px-3 py-2">Número</th>
                                    <th class="px-3 py-2">SimCard</th>
                                    <th class="px-3 py-2">Operador</th>
                                    <th class="px-3 py-2">Estado</th>
                                </tr>
                            </thead>
                            <tbody id="detallesimcard-import-preview-rows">${buildPreviewRowsHtml(initialRows, 'import')}</tbody>
                        </table>
                    </div>
                    <div class="mt-4 flex items-center justify-start" data-preview-export-wrapper="import">
                        <input type="hidden" data-preview-payload="import" value="${encodeURIComponent(JSON.stringify(preview))}">
                        <button type="button" data-preview-download="import" data-preview-download-url="{{ route('modules.lineas-chips.detallesimcard.preview.export', ['type' => 'import']) }}" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50" aria-label="Descargar en xlsx">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                <polyline points="7 10 12 15 17 10"></polyline>
                                <line x1="12" y1="15" x2="12" y2="3"></line>
                            </svg>
                            <span>Descargar en xlsx</span>
                        </button>
                    </div>
                    <form id="detallesimcard-import-save-form" method="POST" action="${preview.processRoute}" class="mt-4 flex items-center justify-end gap-3">
                        <input type="hidden" name="_token" value="${preview.csrfToken}">
                        <input type="hidden" name="importToken" value="${preview.token}">
                        <button type="button" onclick="closeDetallesimcardModal('import')" class="rounded-md border border-slate-300 bg-white px-4 py-3 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-100" style=" border-color: #000000; color: #000000;">Cancelar</button>
                        <button id="detallesimcard-import-save-button" type="submit" class="rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">Guardar cambios</button>
                    </form>
                `;

                const rowsBody = document.getElementById('detallesimcard-import-preview-rows');
                attachPreviewScroll(rowsBody, 'import');
                restoreIcons();
            };

            const submitImportPreview = async (event) => {
                event.preventDefault();
                const form = event.currentTarget;
                if (!(form instanceof HTMLFormElement)) {
                    return;
                }
                hideImportError();

                const formData = new FormData(form);
                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                        },
                        body: formData,
                    });

                    const data = await response.json();
                    if (!response.ok || !data.success) {
                        let errorMessage = data.message || 'No se pudo previsualizar el archivo.';
                        if (data.errors && data.errors.importFile) {
                            errorMessage = Array.isArray(data.errors.importFile) ? data.errors.importFile[0] : data.errors.importFile;
                        }
                        showImportError(errorMessage);
                        return;
                    }

                    renderImportPreview({
                        ...data.preview,
                        processRoute: {!! isset($importProcessRoute) ? json_encode($importProcessRoute) : 'null' !!},
                        csrfToken: document.querySelector('#detallesimcard-import-preview-form input[name="_token"]').value,
                    });
                } catch (error) {
                    showImportError('Error al previsualizar el archivo. Intenta de nuevo.');
                }
            };

            const showBulkError = (message) => {
                const errorContainer = document.getElementById('detallesimcard-bulk-error');
                if (!errorContainer) {
                    return;
                }
                errorContainer.textContent = message;
                errorContainer.classList.remove('hidden');
                errorContainer.classList.add('block');
            };

            const hideBulkValidation = () => {
                if (detallesimcardBulkValidation) {
                    detallesimcardBulkValidation.innerHTML = '';
                }
                if (detallesimcardBulkValidationModal) {
                    detallesimcardBulkValidationModal.style.display = 'none';
                }
                document.body.style.overflow = '';
            };

            const openBulkValidation = () => {
                if (!detallesimcardBulkValidationModal) {
                    return;
                }
                detallesimcardBulkValidationModal.style.display = 'flex';
                detallesimcardBulkValidationModal.style.justifyContent = 'center';
                detallesimcardBulkValidationModal.style.alignItems = 'center';
                document.body.style.overflow = 'hidden';
            };

            const closeBulkValidation = () => {
                if (!detallesimcardBulkValidationModal) {
                    return;
                }
                detallesimcardBulkValidationModal.style.display = 'none';
                document.body.style.overflow = '';
            };

            const getBulkSelectedNumbers = () => {
                const listWrapper = document.getElementById('detallesimcard-bulk-list');
                if (!listWrapper) {
                    return [];
                }

                return Array.from(listWrapper.querySelectorAll('input[name="selectedNumbers[]"]'))
                    .filter((checkbox) => checkbox instanceof HTMLInputElement && checkbox.checked)
                    .map((checkbox) => String(checkbox.value || '').trim())
                    .filter((value, index, array) => value !== '' && array.indexOf(value) === index);
            };

            const renderBulkValidation = (items) => {
                if (!detallesimcardBulkValidation) {
                    return;
                }

                if (!Array.isArray(items) || items.length === 0) {
                    hideBulkValidation();
                    return;
                }

                const blocks = items.map((item) => {
                    const relationHtml = (item.relations || []).map((relation) => {
                        const records = Array.isArray(relation.records) ? relation.records.filter(Boolean) : [];
                        const relationLabel = relation.label || 'Relación';
                        const relationText = records.length > 0 ? records.join(', ') : 'sin detalle adicional';
                        return `<div class="mt-2 rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm text-amber-800"><span class="font-semibold text-amber-900">${relationLabel}:</span> ${relationText}</div>`;
                    }).join('');

                    return `
                        <div class="rounded-xl border border-amber-200 bg-white px-4 py-3">
                            <div class="font-semibold text-amber-900">${item.number}</div>
                            <div class="mt-1 text-amber-800">Tiene relaciones activas o históricas que debes revisar antes de continuar.</div>
                            ${relationHtml}
                        </div>
                    `;
                }).join('');

                detallesimcardBulkValidation.innerHTML = `
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="font-semibold text-amber-900">Validación previa requerida</div>
                            <div class="mt-1 text-sm text-amber-800">Estos números tienen relación con SimCard o con Números de dispositivo.</div>
                        </div>
                    </div>
                    <div class="mt-3 space-y-3">${blocks}</div>
                `;
                openBulkValidation();

                const continueButton = document.getElementById('detallesimcard-bulk-validation-continue');
                if (continueButton) {
                    continueButton.onclick = () => {
                        bulkValidationAcknowledged = true;
                        closeBulkValidation();
                        openBulkDeactivateConfirmationModal();
                    };
                }
            };

            const validateBulkRelations = async (numbers) => {
                const uniqueNumbers = Array.from(new Set((numbers || []).map((number) => String(number || '').trim()).filter((number) => number !== '')));
                if (uniqueNumbers.length === 0) {
                    hideBulkValidation();
                    return [];
                }

                const relationResults = await Promise.all(uniqueNumbers.map(async (number) => {
                    try {
                        const summary = await loadRelationSummary('lineas_chips.numero_telefonico', number);
                        const relations = Array.isArray(summary?.relations) ? summary.relations : [];
                        return {
                            number,
                            relations,
                            hasRelations: relations.length > 0,
                        };
                    } catch {
                        return {
                            number,
                            relations: [],
                            hasRelations: false,
                        };
                    }
                }));

                const items = relationResults.filter((item) => item.hasRelations);
                renderBulkValidation(items);
                return items;
            };

            const hideBulkError = () => {
                const errorContainer = document.getElementById('detallesimcard-bulk-error');
                if (!errorContainer) {
                    return;
                }
                errorContainer.textContent = '';
                errorContainer.classList.add('hidden');
                errorContainer.classList.remove('block');
            };

            const getBulkSelectedCount = () => {
                const listWrapper = document.getElementById('detallesimcard-bulk-list');
                if (!listWrapper) {
                    return 0;
                }
                return Array.from(listWrapper.querySelectorAll('input[name="selectedNumbers[]"]')).filter((checkbox) => checkbox instanceof HTMLInputElement && checkbox.checked).length;
            };

            const reorderBulkSelectedRowsToTop = () => {
                const listWrapper = document.getElementById('detallesimcard-bulk-list');
                if (!listWrapper) {
                    return;
                }
                const rows = Array.from(listWrapper.querySelectorAll('[data-bulk-numero-row]'));
                const selected = [];
                const unselected = [];
                rows.forEach((row) => {
                    const checkbox = row.querySelector('input[name="selectedNumbers[]"]');
                    if (checkbox instanceof HTMLInputElement && checkbox.checked) {
                        selected.push(row);
                    } else {
                        unselected.push(row);
                    }
                });
                selected.concat(unselected).forEach((row) => listWrapper.appendChild(row));
            };

            const updateBulkSubmitState = () => {
                const submitBtn = document.getElementById('detallesimcard-bulk-submit');
                if (!submitBtn) return;
                if (getBulkSelectedCount() > 0) {
                    submitBtn.removeAttribute('disabled');
                    submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                } else {
                    submitBtn.setAttribute('disabled', 'disabled');
                    submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                }
            };

            const applyBulkListSelection = () => {
                const manual = document.getElementById('detallesimcard-bulk-manual');
                const listWrapper = document.getElementById('detallesimcard-bulk-list');
                if (!manual || !listWrapper) {
                    return;
                }

                const values = String(manual.value || '')
                    .split(/[\s,;]+/)
                    .map((item) => item.trim().toLowerCase())
                    .filter((item) => item !== '');

                if (values.length === 0) {
                    showBulkError('Pega una lista de números antes de aplicar.');
                    return;
                }

                const set = new Set(values);
                let foundAny = false;
                listWrapper.querySelectorAll('input[name="selectedNumbers[]"]').forEach((checkbox) => {
                    if (!(checkbox instanceof HTMLInputElement) || checkbox.disabled) {
                        return;
                    }
                    const value = String(checkbox.value || '').trim().toLowerCase();
                    if (set.has(value)) {
                        checkbox.checked = true;
                        foundAny = true;
                    }
                });

                if (!foundAny) {
                    showBulkError('No se encontraron números activos en la lista pegada.');
                    return;
                }

                reorderBulkSelectedRowsToTop();
                hideBulkError();
                updateBulkSubmitState();
            };

            const parseExcelFile = (file) => {
                return new Promise((resolve, reject) => {
                    const bulkForm = document.getElementById('detallesimcard-bulk-form');
                    const parseFileUrl = bulkForm?.getAttribute('data-parse-file-url');
                    
                    if (!parseFileUrl) {
                        reject(new Error('URL de procesamiento no disponible'));
                        return;
                    }

                    const formData = new FormData();
                    formData.append('file', file);

                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', parseFileUrl, true);
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                    
                    // Agregar token CSRF si está disponible
                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || 
                                     document.querySelector('[name="_token"]')?.value;
                    if (csrfToken) {
                        xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken);
                    }

                    xhr.onload = () => {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                resolve(response.preview || null);
                            } else {
                                reject(new Error(response.message || 'Error al procesar el archivo'));
                            }
                        } catch (err) {
                            reject(new Error('Respuesta inválida del servidor'));
                        }
                    };
                    xhr.onerror = () => reject(new Error('Error al conectar con el servidor'));
                    xhr.send(formData);
                });
            };

            const renderBulkPreview = (preview) => {
                detallesimcardBulkPreviewData = preview;
                detallesimcardBulkPreviewIndex = 0;
                hideBulkValidation();
                bulkValidationAcknowledged = false;
                bulkValidationKey = '';

                const emptyState = document.getElementById('detallesimcard-bulk-preview-empty');
                const content = document.getElementById('detallesimcard-bulk-preview-content');
                const rowsBody = document.getElementById('detallesimcard-bulk-preview-rows');
                const payloadInput = document.querySelector('[data-preview-payload="bulk"]');

                if (!content || !rowsBody) {
                    return;
                }

                const setText = (id, value) => {
                    const el = document.getElementById(id);
                    if (el) {
                        el.textContent = String(value ?? 0);
                    }
                };

                setText('detallesimcard-bulk-stat-valid', preview.candidateCount ?? 0);
                setText('detallesimcard-bulk-stat-new', preview.newRows ?? 0);
                setText('detallesimcard-bulk-stat-empty-invalid', preview.emptyInvalidRows ?? 0);
                setText('detallesimcard-bulk-stat-inactive', preview.inactiveRows ?? 0);
                setText('detallesimcard-bulk-stat-dup-file', preview.fileDuplicateRows ?? 0);
                setText('detallesimcard-bulk-stat-missing', preview.missingRows ?? 0);

                const initialRows = getPreviewRowsChunk('bulk');
                rowsBody.innerHTML = buildPreviewRowsHtml(initialRows, 'bulk');

                if (payloadInput instanceof HTMLInputElement) {
                    payloadInput.value = encodeURIComponent(JSON.stringify(preview));
                }

                if (emptyState) {
                    emptyState.classList.add('hidden');
                }
                content.classList.remove('hidden');
                attachPreviewScroll(rowsBody, 'bulk');
            };

            const handleBulkFilePreview = () => {
                const fileInput = document.getElementById('detallesimcard-bulk-file');
                const previewBtn = document.getElementById('detallesimcard-bulk-preview-btn');
                const previewDiv = document.getElementById('detallesimcard-bulk-file-preview');
                
                if (!fileInput || !previewBtn || !previewDiv) {
                    return;
                }

                const processBulkFile = async () => {
                    const file = fileInput.files?.[0];
                    if (!file) {
                        showBulkError('Selecciona un archivo primero.');
                        return;
                    }

                    try {
                        const preview = await parseExcelFile(file);
                        if (!preview) {
                            showBulkError('El archivo no contiene números válidos.');
                            return;
                        }

                        renderBulkPreview(preview);

                        // Automáticamente marcar estos números en la lista
                        const listWrapper = document.getElementById('detallesimcard-bulk-list');
                        if (listWrapper) {
                            const set = new Set((preview.eligibleNumbers || []).map((item) => String(item).toLowerCase()));
                            listWrapper.querySelectorAll('input[name="selectedNumbers[]"]').forEach((checkbox) => {
                                if (!(checkbox instanceof HTMLInputElement)) return;
                                const value = String(checkbox.value || '').trim().toLowerCase();
                                checkbox.checked = set.has(value);
                            });
                            reorderBulkSelectedRowsToTop();
                            updateBulkSubmitState();
                        }
                        hideBulkError();
                    } catch (error) {
                        showBulkError(error.message || 'Error al procesar el archivo.');
                    }
                };

                previewBtn.onclick = processBulkFile;
            };

            const initDetallesimcardQuickActions = () => {
                detallesimcardBulkModal = document.getElementById('detallesimcard-bulk-deactivate-modal');
                detallesimcardImportModal = document.getElementById('detallesimcard-import-modal');
                detallesimcardBulkValidationModal = document.getElementById('detallesimcard-bulk-validation-modal');
                detallesimcardBulkValidation = document.getElementById('detallesimcard-bulk-validation');

                if (detallesimcardBulkModal && detallesimcardBulkModal.parentElement !== document.body) {
                    document.body.appendChild(detallesimcardBulkModal);
                }
                if (detallesimcardImportModal && detallesimcardImportModal.parentElement !== document.body) {
                    document.body.appendChild(detallesimcardImportModal);
                }
                if (detallesimcardBulkValidationModal && detallesimcardBulkValidationModal.parentElement !== document.body) {
                    document.body.appendChild(detallesimcardBulkValidationModal);
                }

                document.querySelectorAll('[data-open-detallesimcard-modal]').forEach((button) => {
                    button.onclick = () => {
                        const type = String(button.getAttribute('data-open-detallesimcard-modal') || '');
                        openDetallesimcardModal(type);
                    };
                });

                document.querySelectorAll('[data-close-detallesimcard-modal]').forEach((button) => {
                    button.onclick = () => {
                        const type = String(button.getAttribute('data-close-detallesimcard-modal') || '');
                        closeDetallesimcardModal(type);
                    };
                });

                document.querySelectorAll('[data-close-bulk-validation-modal]').forEach((button) => {
                    button.onclick = () => closeBulkValidation();
                });

                detallesimcardBulkDeactivateConfirmationModal = document.getElementById('detallesimcard-bulk-deactivate-confirmation-modal');
                if (detallesimcardBulkDeactivateConfirmationModal && detallesimcardBulkDeactivateConfirmationModal.parentElement !== document.body) {
                    document.body.appendChild(detallesimcardBulkDeactivateConfirmationModal);
                }

                document.querySelectorAll('[data-close-deactivate-confirmation-modal]').forEach((button) => {
                    button.onclick = () => closeBulkDeactivateConfirmationModal();
                });

                const confirmNumberOnlyButton = document.getElementById('detallesimcard-bulk-confirm-number-only');
                const confirmNumberWithSimcardButton = document.getElementById('detallesimcard-bulk-confirm-number-with-simcard');

                if (confirmNumberOnlyButton) {
                    confirmNumberOnlyButton.onclick = () => {
                        const flag = document.getElementById('deactivate-simcards-flag');
                        if (flag) {
                            flag.value = '0';
                        }
                        bulkFormSubmittingAfterConfirmation = true;
                        const submitButton = document.getElementById('detallesimcard-bulk-submit');
                        setButtonLoading(submitButton, 'Bajando números...');
                        bulkForm.submit();
                    };
                }

                if (confirmNumberWithSimcardButton) {
                    confirmNumberWithSimcardButton.onclick = () => {
                        const flag = document.getElementById('deactivate-simcards-flag');
                        if (flag) {
                            flag.value = '1';
                        }
                        bulkFormSubmittingAfterConfirmation = true;
                        const submitButton = document.getElementById('detallesimcard-bulk-submit');
                        setButtonLoading(submitButton, 'Bajando números...');
                        bulkForm.submit();
                    };
                }

                const selectAll = document.getElementById('detallesimcard-bulk-select-all');
                if (selectAll) {
                    selectAll.onchange = (event) => {
                        const checked = (event.target instanceof HTMLInputElement) ? event.target.checked : false;
                        const listWrapper = document.getElementById('detallesimcard-bulk-list');
                        if (!listWrapper) {
                            return;
                        }
                        listWrapper.querySelectorAll('[data-bulk-numero-row]').forEach((row) => {
                            if (row instanceof HTMLElement && row.style.display === 'none') {
                                return;
                            }
                            const checkbox = row.querySelector('input[name="selectedNumbers[]"]');
                            if (checkbox instanceof HTMLInputElement && !checkbox.disabled) {
                                checkbox.checked = checked;
                            }
                        });
                    };
                }

                const applyListButton = document.getElementById('detallesimcard-bulk-apply-list');
                if (applyListButton) {
                    applyListButton.onclick = applyBulkListSelection;
                }

                const bulkForm = document.getElementById('detallesimcard-bulk-form');
                if (bulkForm) {
                    bulkForm.onsubmit = async (event) => {
                        if (bulkFormSubmittingAfterConfirmation) {
                            bulkFormSubmittingAfterConfirmation = false;
                            return true;
                        }

                        event.preventDefault();

                        const selectedNumbers = getBulkSelectedNumbers();
                        if (selectedNumbers.length === 0) {
                            showBulkError('Selecciona al menos un número antes de dar de baja.');
                            return false;
                        }
                        hideBulkError();

                        const currentKey = selectedNumbers.slice().sort().join('|');
                        if (!bulkValidationAcknowledged || bulkValidationKey !== currentKey) {
                            bulkValidationAcknowledged = false;
                            bulkValidationKey = currentKey;
                            const items = await validateBulkRelations(selectedNumbers);
                            if (items.length > 0) {
                                return false;
                            }
                            bulkValidationAcknowledged = true;
                        }

                        openBulkDeactivateConfirmationModal();
                        return false;
                    };
                }

                const bulkListWrapper = document.getElementById('detallesimcard-bulk-list');
                if (bulkListWrapper) {
                    bulkListWrapper.addEventListener('change', () => {
                        if (getBulkSelectedCount() > 0) {
                            hideBulkError();
                        }
                        updateBulkSubmitState();
                    });
                }

                // Inicializar manejo de archivo
                handleBulkFilePreview();
                updateBulkSubmitState();

                const importPreviewForm = document.getElementById('detallesimcard-import-preview-form');
                if (importPreviewForm) {
                    importPreviewForm.addEventListener('submit', submitImportPreview);
                }

                document.addEventListener('submit', (event) => {
                    if (!(event.target instanceof HTMLFormElement)) {
                        return;
                    }
                    if (event.target.id === 'detallesimcard-import-save-form') {
                        const submitButton = document.getElementById('detallesimcard-import-save-button');
                        setButtonLoading(submitButton, 'Guardando datos...');
                    }
                });

                @if(($openDeactivateModal ?? false) === true)
                    openDetallesimcardModal('bulk-deactivate');
                @endif

                @if(($openImportModal ?? false) === true)
                    openDetallesimcardModal('import');
                @endif
            };

            const buildRelationSummaryUrl = (resource, id) => {
                if (!relationSummaryTemplate || !resource || !id) {
                    return null;
                }

                return relationSummaryTemplate.replace('__RESOURCE__', encodeURIComponent(resource)).replace('__ID__', encodeURIComponent(id));
            };

            const resetDeleteConfirmationContent = () => {
                if (deleteConfirmationTitle) {
                    deleteConfirmationTitle.textContent = '¿Estás seguro?';
                }

                if (deleteConfirmationMessage) {
                    deleteConfirmationMessage.textContent = 'Esta acción eliminará el registro y no se podrá deshacer.';
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
                    deleteConfirmationSubmit.style.background = '#c71010';
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
                activeDeleteSummary = null;
                resetDeleteConfirmationContent();
            };

            const closeBulkDeactivateConfirmationModal = () => {
                if (!detallesimcardBulkDeactivateConfirmationModal) {
                    return;
                }
                detallesimcardBulkDeactivateConfirmationModal.style.display = 'none';
            };

            const openBulkDeactivateConfirmationModal = () => {
                if (!detallesimcardBulkDeactivateConfirmationModal) {
                    return;
                }
                detallesimcardBulkDeactivateConfirmationModal.style.display = 'flex';
                detallesimcardBulkDeactivateConfirmationModal.style.justifyContent = 'center';
                detallesimcardBulkDeactivateConfirmationModal.style.alignItems = 'center';
                detallesimcardBulkDeactivateConfirmationModal.style.background = 'rgba(0,0,0,0.8)';
                detallesimcardBulkDeactivateConfirmationModal.style.zIndex = '10000';
            };

            const renderRelationItems = (relation) => {
                const records = Array.isArray(relation.records) ? relation.records.filter(Boolean) : [];
                const extraCount = Math.max((Number(relation.count || 0) - records.length), 0);
                const relatedList = records.length > 0 ? records.join(', ') : 'sin detalle adicional';
                const suffix = extraCount > 0 ? ` y otros ${extraCount} más` : '';

                return `Este registro está relacionado con ${relation.count} ${relation.label}${relation.count === 1 ? '' : 's'}: ${relatedList}${suffix}.`;
            };

            const renderDeleteConfirmation = (summary, actionMode) => {
                if (!deleteConfirmationTitle || !deleteConfirmationMessage || !deleteConfirmationRelations || !deleteConfirmationHint || !deleteConfirmationSubmit || !deleteConfirmationDetails || !deleteConfirmationActions) {
                    return;
                }

                const recordLabel = summary?.recordLabel || summary?.recordId || 'este registro';
                const relations = Array.isArray(summary?.relations) ? summary.relations : [];
                const details = Array.isArray(summary?.details) ? summary.details : [];
                const deleteActions = Array.isArray(summary?.deleteActions) ? summary.deleteActions : [];
                const isEdit = actionMode === 'edit';

                deleteConfirmationTitle.textContent = isEdit ? 'Confirmar edición' : '¿Estás seguro?';
                deleteConfirmationMessage.textContent = isEdit
                    ? `Vas a actualizar "${recordLabel}". Si continúas, revisa las relaciones afectadas antes de guardar.`
                    : `Vas a eliminar "${recordLabel}". Si continúas, el sistema validará la integridad antes de borrar.`;

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
                    relationList.style.gap = '16px';

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

                deleteConfirmationHint.textContent = isEdit
                    ? 'Solo guarda cambios si ya verificaste que esta actualización no rompe relaciones.'
                    : 'No se puede eliminar este registro mientras tenga relaciones activas.';
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
                        button.textContent = action.label || 'Eliminar con relación';
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
                                updateBulkActionState();
                                activeDeleteForm.submit();
                            }
                        };
                        actionWrap.appendChild(button);
                    });

                    deleteConfirmationActions.appendChild(actionWrap);
                    deleteConfirmationActions.classList.remove('hidden');
                }

                deleteConfirmationSubmit.textContent = isEdit ? 'Guardar cambios' : 'Eliminar';
                deleteConfirmationSubmit.style.background = isEdit ? '#dc2626' : '#c71010';
            };

            const openDeleteConfirmation = (form, summary = null, actionMode = 'delete') => {
                if (!deleteConfirmationModal || !deleteConfirmationMessage) {
                    return;
                }
                activeDeleteForm = form;
                activeDeleteSummary = summary;
                activeDeleteMode = '';
                renderDeleteConfirmation(summary, actionMode);
                // Force inline styles to prevent global theme tinting
                deleteConfirmationModal.style.display = 'flex';
                deleteConfirmationModal.style.justifyContent = 'center';
                deleteConfirmationModal.style.alignItems = 'center';
                deleteConfirmationModal.style.background = 'rgba(0,0,0,0.8)';
                deleteConfirmationModal.style.zIndex = '9999';
                document.body.style.overflow = 'hidden';
            };

            const initHistoryToggle = () => {
                document.querySelectorAll('[data-history-toggle]').forEach((button) => {
                    button.addEventListener('click', () => {
                        const target = button.getAttribute('data-history-toggle');
                        if (!target) {
                            return;
                        }
                        const historyRow = document.querySelector(`tr[data-history-row="${CSS.escape(target)}"]`);
                        if (!historyRow) {
                            return;
                        }
                        const mainRow = historyRow.previousElementSibling;
                        const icon = button.querySelector('i');
                        historyRow.classList.toggle('hidden');
                        if (mainRow) {
                            mainRow.classList.toggle('expanded');
                        }
                        if (icon) {
                            icon.classList.toggle('rotate-180');
                        }
                    });
                });
            };

            const closeAuditActionDetailModal = () => {
                if (!auditActionDetailModal) {
                    return;
                }
                auditActionDetailModal.classList.add('hidden');
                document.body.style.overflow = '';
            };

            const openAuditActionDetailModal = (text) => {
                if (!auditActionDetailModal || !auditActionDetailText) {
                    return;
                }
                auditActionDetailText.textContent = text || '';
                auditActionDetailModal.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            };

            const handleAuditActionClick = (event) => {
                const button = event.currentTarget;
                const text = button.getAttribute('data-audit-action-full') || '';
                openAuditActionDetailModal(text);
            };

            const initAuditActionDetailModal = () => {
                auditActionDetailModal = document.getElementById('audit-action-detail-modal');
                auditActionDetailText = auditActionDetailModal ? auditActionDetailModal.querySelector('#audit-action-detail-text') : null;

                if (auditActionDetailModal) {
                    if (auditActionDetailModal.parentElement !== document.body) {
                        document.body.appendChild(auditActionDetailModal);
                    }

                    auditActionDetailModal.querySelectorAll('[data-close-audit-action-detail]').forEach((button) => {
                        button.removeEventListener('click', closeAuditActionDetailModal);
                        button.addEventListener('click', closeAuditActionDetailModal);
                    });
                }

                document.querySelectorAll('[data-open-audit-action-detail]').forEach((button) => {
                    button.removeEventListener('click', handleAuditActionClick);
                    button.addEventListener('click', handleAuditActionClick);
                });
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
                const form = button.closest('form.delete-confirmation-form');
                if (!form) {
                    return;
                }
                closeOpenDropdowns();
                const resource = form.getAttribute('data-relation-resource') || document.getElementById('erp-list-resource')?.value || '';
                const recordId = form.getAttribute('data-relation-record-id') || '';
                const summary = resource && recordId ? await loadRelationSummary(resource, recordId) : { resource, recordId, recordLabel: null, relations: [] };
                openDeleteConfirmation(form, summary, 'delete');
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

                if (deleteConfirmationModal) {
                    if (deleteConfirmationModal.parentElement !== document.body) {
                        document.body.appendChild(deleteConfirmationModal);
                    }
                }

                if (deleteConfirmationSubmit) {
                    deleteConfirmationSubmit.removeEventListener('click', closeDeleteConfirmation);
                    deleteConfirmationSubmit.addEventListener('click', () => {
                        if (activeDeleteForm) {
                            const deleteModeInput = activeDeleteForm.querySelector('input[name="deleteMode"]');
                            if (deleteModeInput instanceof HTMLInputElement && activeDeleteMode === '') {
                                deleteModeInput.remove();
                            }
                            updateBulkActionState();
                            activeDeleteForm.submit();
                        }
                    });
                }

                if (deleteConfirmationModal) {
                    deleteConfirmationModal.querySelectorAll('[data-delete-modal-close]').forEach((button) => {
                        button.removeEventListener('click', closeDeleteConfirmation);
                        button.addEventListener('click', closeDeleteConfirmation);
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
                closeLocalDropdowns();
                searchInput = getSearchInput();
                searchClearBtn = form.querySelector('[data-list-clear-search]');
                initSortHeaders();

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

                const clearSearchBtn = form.querySelector('[data-list-clear-search]');
                if (clearSearchBtn) {
                    clearSearchBtn.removeEventListener('click', handleSearchClear);
                    clearSearchBtn.addEventListener('click', handleSearchClear);
                }

                updateSearchClearVisibility();

                updateExportLinks();

                const pageSizeElement = getPageSizeElement();
                if (pageSizeElement) {
                    pageSizeElement.removeEventListener('change', handlePageSizeChange);
                    pageSizeElement.addEventListener('change', handlePageSizeChange);
                }

                attachPaginationLinks();
                initDeleteConfirmation();
                initBulkSelection();
                initDetallesimcardQuickActions();
                initHistoryToggle();
                initAuditActionDetailModal();
            };

            window.addEventListener('popstate', () => {
                const url = window.location.href;
                if (form && wrapper) {
                    fetchList(url);
                }
            });

            init();
        })();
    </script>
    @includeIf('cliente.relation-panel-script')
@endsection
