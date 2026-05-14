@extends('dashboard.overview-1')

@section('title', $title ?? 'Ticket')
@section('header', $title ?? 'Ticket')

@section('breadcrumb')
    <nav aria-label="breadcrumb" class="flex hidden flex-1 xl:block">
        <ol class="flex items-center text-theme-1">
            <li><a href="{{ route('home') }}">Inicio</a></li>
            <li class="relative ml-5 pl-0.5 before:content-[''] before:w-[14px] before:h-[14px] before:bg-chevron-black before:transform before:rotate-[-90deg] before:bg-[length:100%] before:-ml-[1.125rem] before:absolute before:my-auto before:inset-y-0 text-slate-600 cursor-text">
                <span>{{ $title ?? 'Ticket' }}</span>
            </li>
        </ol>
    </nav>
@endsection

@section('content')
    {{-- Layout ticket-table. --}}
    <div class="grid w-full grid-cols-12 gap-x-6 gap-y-10">
        <div class="col-span-12">
            <!-- HEADER CON TÍTULO Y BOTÓN NUEVO -->
            <div class="flex flex-col gap-y-3 md:h-10 md:flex-row md:items-center">
                <div class="text-base font-medium ticket-board__title pl-5 group-[.mode--light]:text-white">
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
                $canPerformActions = $canEdit || $canDelete;

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
                <div class="flex flex-col gap-x-3 gap-y-2 sm:flex-row md:ml-auto ticket-board__new">
                    @if(!empty($bulkDestroyRoute) && $canDelete)
                        <button type="button" id="bulk-delete-button" class="hidden transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-danger focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed border-danger text-danger dark:border-danger/70 dark:text-danger" style="border-color:#c71010;color:#c71010;">
                            <i data-tw-merge="" data-lucide="trash-2" class="mr-2 h-4 w-4 stroke-[1.3]"></i>
                            Eliminar selección
                        </button>
                    @endif
                    @if(!empty($createRoute) && $canCreate)
                        <a href="{{ $createRoute }}">
                            <button type="button" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed dark:border-danger/70 dark:text-danger" style="background-color:#c71010;color:#ffffff;">
                                <i data-tw-merge="" data-lucide="plus" class="mr-2 h-4 w-4 stroke-[1.3]"></i>
                                Nuevo {{ $singularTitle ?? 'Registro' }}
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
                {{-- ALERTAS DE SESIÓN: se envuelven en un contenedor ancho para igualar la tabla --}}
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
                @if($stats)
                    <div class="ticket-stats-white  flex flex-col p-3">
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
                <div id="list-table-wrapper" class="ticket-table-white flex w-full flex-col">
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
                                            <td data-tw-merge="" class="px-5 text-center align-middle border-b dark:border-darkmode-300 border-t border-slate-200/60 bg-slate-50 py-4 font-medium text-slate-500 @if(!empty($column['wrap'] ?? false)) w-[38%] @endif">
                                                <div class="th-sort th-sort--center" role="button" tabindex="0" data-sort-index="{{ $loop->index + 1 }}" aria-label="Ordenar {{ $column['label'] }}">
                                                    <span class="th-sort__label">{{ $column['label'] }}</span>
                                                    <span class="th-sort__icon" aria-hidden="true"></span>
                                                </div>
                                            </td>
                                        @else
                                            <td data-tw-merge="" class="px-5 border-b dark:border-darkmode-300 border-t border-slate-200/60 bg-slate-50 py-4 font-medium text-slate-500 @if(!empty($column['wrap'] ?? false)) w-[38%] @endif">
                                                <div class="th-sort" role="button" tabindex="0" data-sort-index="{{ $loop->index + 1 }}" aria-label="Ordenar {{ $column['label'] }}">
                                                    <span class="th-sort__label">{{ $column['label'] }}</span>
                                                    <span class="th-sort__icon" aria-hidden="true"></span>
                                                </div>
                                            </td>
                                        @endif
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
                                        <td data-tw-merge="" class="px-5 border-b dark:border-darkmode-300 border-dashed py-4 dark:bg-darkmode-600">
                                            <div class="flex items-center gap-2">
                                                <input data-tw-merge="" type="checkbox" class="list-row-checkbox transition-all duration-100 ease-in-out shadow-sm border-slate-200 cursor-pointer rounded focus:ring-4 focus:ring-offset-0 focus:ring-primary focus:ring-opacity-20 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&[type='radio']]:checked:bg-primary [&[type='radio']]:checked:border-primary [&[type='radio']]:checked:border-opacity-10 [&[type='checkbox']]:checked:bg-primary [&[type='checkbox']]:checked:border-primary [&[type='checkbox']]:checked:border-opacity-10 [&:disabled:not(:checked)]:bg-slate-100 [&:disabled:not(:checked)]:cursor-not-allowed [&:disabled:not(:checked)]:dark:bg-darkmode-800/50 [&:disabled:checked]:opacity-70 [&:disabled:checked]:cursor-not-allowed [&:disabled:checked]:dark:bg-darkmode-800/50" value="{{ data_get($row, $identifierKey) }}">
                                            </div>
                                        </td>
                                        @foreach($columns as $columnIndex => $column)
                                            <td data-tw-merge="" class="px-5 border-b dark:border-darkmode-300 border-dashed py-4 dark:bg-darkmode-600 @if(($column['key'] ?? '') === 'estado') text-center align-middle @endif @if(!empty($column['wrap'] ?? false)) align-top w-[38%] @endif">
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
                                                            <a class="font-medium text-slate-700 hover:text-slate-900 hover:underline @if(!empty($column['wrap'] ?? false)) whitespace-normal break-words leading-5 @else whitespace-nowrap @endif" href="{{ route($showRoute, data_get($row, $identifierKey)) }}">
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
                                                            $maxLength = $column['maxLength'] ?? 40;
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
                                                    @case('estado')
                                                        @php
                                                            $estadoValue = data_get($row, $column['key']) ?? '';
                                                            $estadoLabel = trim((string) $estadoValue) !== '' ? trim((string) $estadoValue) : 'Sin estado';
                                                            $estadoNormalized = mb_strtolower($estadoLabel);
                                                            
                                                            $estadoColores = match($estadoNormalized) {
                                                                'nuevo'       => ['bg' => '#e0f2fe', 'text' => '#0369a1'], 
    
                                                                'asignado'    => ['bg' => '#e0e7ff', 'text' => '#4338ca'], 
                                                                
                                                                'en progreso' => ['bg' => '#ffedd5', 'text' => '#9a3412'], 
                                                                
                                                                'en espera'   => ['bg' => '#eff6fd', 'text' => '#475569'], 
                                                                
                                                                'resuelto'    => ['bg' => '#dcfce7', 'text' => '#15803d'], 
                                                                
                                                                'cerrado'     => ['bg' => '#1f2937', 'text' => '#ffffff'], 
                                                                
                                                                'cancelado'   => ['bg' => '#fee2e2', 'text' => '#991b1b'], 
                                                                
                                                                default       => ['bg' => '#f9fafb', 'text' => '#6b7280'],
                                                            };
                                                        @endphp
                                                        <span class="inline-flex items-center rounded-md border px-2.5 py-2 text-xs font-semibold" style="background-color: {{ $estadoColores['bg'] }}; color: {{ $estadoColores['text'] }};">
                                                            {{ $estadoLabel }}
                                                        </span>
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
                                                @endswitch
                                            </td>
                                        @endforeach
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
                                                            <div data-tw-merge="" class="dropdown-content rounded-xl border border-slate-200/80 bg-white p-2 shadow-xl shadow-slate-200/70 dark:border-transparent dark:bg-darkmode-600">
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
                                            </div>
                                        </td>
                                    @endif
                                    </tr>
                                    
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
            
    <div id="delete-confirmation-modal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:9999;background:rgba(0,0,0,0.8);align-items:center;justify-content:center;" role="dialog" aria-modal="true" aria-labelledby="delete-confirmation-title" aria-describedby="delete-confirmation-message">
        <div style="width:720px;max-width:92%;margin:0 auto;position:relative;border-radius:20px;background:#ffffff;box-shadow:0 20px 40px rgba(2,6,23,0.12);overflow:hidden;">
            <button type="button" data-delete-modal-close style="position:absolute;right:16px;top:16px;height:44px;width:44px;border-radius:9999px;border:1px solid #e6e9ee;background:#fff;color:#6b7280;display:inline-flex;align-items:center;justify-content:center;" aria-label="Cerrar">
                <i data-lucide="x" style="width:16px;height:16px"></i>
            </button>
            <div style="padding:40px 48px;text-align:left;">
                <div style="margin:0 auto 24px;display:flex;height:64px;width:64px;align-items:center;justify-content:center;border-radius:9999px;border:1px solid #ef4444;background:#fff7f7;color:#ef4444;">
                    <i data-lucide="x-circle" style="width:22px;height:22px"></i>
                </div>
                <h2 id="delete-confirmation-title" style="font-size:22px;font-weight:600;margin:0;color:#111827;">¿Estás seguro?</h2>
                <p id="delete-confirmation-message" style="margin-top:12px;color:#6b7280;font-size:14px;line-height:1.6;">Esta acción eliminará el registro y no se podrá deshacer.</p>
                <div id="delete-confirmation-details" class="mt-5 hidden rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900"></div>
                <div id="delete-confirmation-relations" class="mt-5 hidden rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"></div>
                <div id="delete-confirmation-hint" class="mt-3 hidden text-sm text-slate-600"></div>
                <div style="margin-top:26px;display:flex;gap:12px;justify-content:flex-end;flex-wrap:wrap;align-items:center;">
                    <div id="delete-confirmation-actions" class="hidden flex flex-wrap gap-3 mr-auto"></div>
                    <button type="button" data-delete-modal-close style="min-width:120px;padding:10px 18px;border-radius:10px;border:1px solid #e6e9ee;background:#ffffff;color:#374151;font-weight:600;">Cancelar</button>
                    <button type="button" id="delete-confirmation-submit" style="min-width:120px;padding:10px 18px;border-radius:10px;background:#ef4444;color:#ffffff;font-weight:600;border:none;">Eliminar</button>
                </div>
            </div>
        </div>
    </div>

    <style>
        .ticket-stats-white,
        .ticket-table-white {
            background: #ffffff !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            width: 100%;
        }

        .ticket-stats-white {
            border: 1px solid #d9e2ec !important;
            padding: 1rem;
        }

        .ticket-stats-white .box {
            padding: 1.25rem;
        }

        .ticket-stats-white .box .stat-value {
            font-size: 2.25rem; /* más grande */
            line-height: 1;
        }

        .ticket-table-white {
            border: 1px solid #d9e2ec !important;
        }

        /* Aumentar tamaño de fuente del título y ajustar alineado con la tabla */
        .ticket-board__title {
            font-size: 1.25rem;
            padding-left: 0.25rem;
            font-weight: 600;
        }

        /* Aumentar ligeramente fuente de la tabla (th y td) */
        .ticket-table-white table th,
        .ticket-table-white table td {
            font-size: 0.97rem;
        }

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

        /* Estilos para alertas de sesión en el layout de tickets */
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

        /* Override rápido: hacer la tabla aún más ancha en pantallas grandes */
        @media (min-width: 1200px) {
            .ticket-stats-white,
            .ticket-table-white,
            .session-alerts-container {
                width: calc(100% + 20rem) ;
                margin-left: -10rem ;
                margin-right: -12rem ;
            }
            .ticket-board__title {
                margin-left: -10rem ;
            }
            .ticket-board__new {
                margin-right: -9rem ;
            }
        }

    </style>
    <script>
        (function () {
            const listWrapperId = 'list-table-wrapper';
            const formId = 'list-filter-form';
            let form = null;
            let wrapper = null;
            let searchInput = null;
            let searchClearBtn = null;
            let debounceTimer = null;
            let hasBoundOutsideDropdownClose = false;
            let hasBoundEscapeDropdownClose = false;
            let fetchController = null;
            let fetchRequestId = 0;
            let selectAllCheckbox = null;
            let rowCheckboxes = [];
            let bulkDeleteButton = null;
            let bulkDeleteForm = null;
            let bulkDeleteInputs = null;

            const getWrapper = () => document.getElementById(listWrapperId);
            const getForm = () => document.getElementById(formId);
            const getSearchInput = () => (form ? form.querySelector('[name="q"]') : null);
            const getPageSizeElement = () => (wrapper ? wrapper.querySelector('[name="perPage"]') : null);

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
                let left = rect.left;
                const viewportMargin = 12;

                if (left + menuWidth > window.innerWidth - viewportMargin) {
                    left = Math.max(viewportMargin, window.innerWidth - menuWidth - viewportMargin);
                }
                if (left < viewportMargin) {
                    left = viewportMargin;
                }

                menu.style.left = `${Math.round(left)}px`;
                menu.style.top = `${Math.round(rect.bottom + 8)}px`;
            };

            const attachMenuReposition = (menu, toggle) => {
                const handler = () => {
                    if (menu.dataset.portal !== 'true') {
                        return;
                    }
                    if (menu._repositionRaf) {
                        cancelAnimationFrame(menu._repositionRaf);
                    }
                    menu._repositionRaf = requestAnimationFrame(() => positionMenu(menu, toggle));
                };

                menu._repositionHandler = handler;
                if (window.visualViewport) {
                    window.visualViewport.addEventListener('resize', handler);
                    window.visualViewport.addEventListener('scroll', handler);
                }
                window.addEventListener('resize', handler);
            };

            const detachMenuReposition = (menu) => {
                const handler = menu._repositionHandler;
                if (!handler) {
                    return;
                }
                if (window.visualViewport) {
                    window.visualViewport.removeEventListener('resize', handler);
                    window.visualViewport.removeEventListener('scroll', handler);
                }
                window.removeEventListener('resize', handler);
                if (menu._repositionRaf) {
                    cancelAnimationFrame(menu._repositionRaf);
                }
                delete menu._repositionHandler;
                delete menu._repositionRaf;
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
                                    let desiredWidth;
                                    const isActionDropdown = Boolean(dropdown.closest('td'));
                                    const hasFormControls = Boolean(menu.querySelector('select, input[type="text"], input[type="search"], textarea, button[type="submit"], [data-list-clear]'));

                                    if (isActionDropdown) {
                                        desiredWidth = Math.min(Math.max(buttonWidth, 100), 140);
                                    } else if (hasFormControls) {
                                        desiredWidth = Math.min(Math.max(Math.ceil(naturalWidth + 16), buttonWidth, 230), 230);
                                    } else {
                                        desiredWidth = Math.min(Math.max(buttonWidth, 110), 140);
                                    }

                                    menu.style.minWidth = `${desiredWidth}px`;
                                    menu.style.right = 'auto';
                                    const inner = menu.querySelector('.dropdown-content');
                                    if (inner) {
                                        inner.style.boxSizing = 'border-box';
                                        inner.style.width = `${desiredWidth}px`;
                                        inner.style.minWidth = `${desiredWidth}px`;
                                        inner.style.maxWidth = `${desiredWidth}px`;
                                        inner.style.padding = isActionDropdown ? '6px' : '';
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

            const getTableRows = () => {
                if (!wrapper) {
                    return [];
                }
                const tbody = wrapper.querySelector('tbody');
                if (!tbody) {
                    return [];
                }
                return Array.from(tbody.querySelectorAll('tr')).filter((row) => {
                    const firstCell = row.children[0];
                    return !(row.children.length === 1 && firstCell && firstCell.hasAttribute('colspan'));
                });
            };

            const initSortHeaders = () => {
                if (!wrapper) {
                    return;
                }

                const table = wrapper.querySelector('table');
                const tbody = wrapper.querySelector('tbody');
                if (!table || !tbody) {
                    return;
                }

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
                    return cell ? (cell.textContent || '').trim() : '';
                };

                const sortRows = (index, direction) => {
                    const rows = getTableRows();
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

            const buildUrl = () => {
                const params = new URLSearchParams(new FormData(form));
                for (const [key, value] of Array.from(params.entries())) {
                    if (value === null || String(value).trim() === '') {
                        params.delete(key);
                    }
                }
                const pageSizeElement = getPageSizeElement();
                if (pageSizeElement && pageSizeElement.value) {
                    params.set('perPage', pageSizeElement.value);
                }
                const url = new URL(form.action, window.location.origin);
                url.search = params.toString();
                return url.toString();
            };

            const clearFilterInputs = () => {
                if (!form) {
                    return;
                }

                form.querySelectorAll('input[name], select[name], textarea[name]').forEach((field) => {
                    const fieldName = (field.getAttribute('name') || '').trim();
                    if (!fieldName) {
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

            const closeOpenDropdowns = () => {
                closeLocalDropdowns();
            };

            const replaceWrapper = async (html) => {
                closeOpenDropdowns();
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const nextWrapper = doc.getElementById(listWrapperId);
                if (!nextWrapper || !wrapper) {
                    return;
                }
                wrapper.innerHTML = nextWrapper.innerHTML;
                restoreIcons();
                initDropdowns();
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
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
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

            const updateSearchClearVisibility = () => {
                if (!searchInput || !searchClearBtn) {
                    return;
                }
                const value = String(searchInput.value || '').trim();
                searchClearBtn.style.display = value === '' ? 'none' : 'flex';
            };

            const handleSubmit = (event) => {
                event.preventDefault();
                fetchList(buildUrl());
            };

            const handleSearchInput = () => {
                updateSearchClearVisibility();
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    fetchList(buildUrl(), { preserveSearchFocus: true });
                }, 350);
            };

            const handleClear = (event) => {
                event.preventDefault();
                clearFilterInputs();
                fetchList(new URL(form.action, window.location.origin).toString());
            };

            const handleSearchClear = (event) => {
                event.preventDefault();
                if (!form) {
                    return;
                }
                const q = form.querySelector('[name="q"]');
                if (q) {
                    q.value = '';
                }
                updateSearchClearVisibility();
                fetchList(buildUrl());
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
                        fetchList(event.currentTarget.href);
                    });
                });
            };

            const updateBulkActionState = () => {
                if (!selectAllCheckbox || !rowCheckboxes.length) {
                    if (bulkDeleteButton) {
                        bulkDeleteButton.classList.add('hidden');
                    }
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

            const handleBulkDeleteButtonClick = (event) => {
                if (!bulkDeleteButton || !bulkDeleteForm) {
                    return;
                }
                event.preventDefault();
                const selectedCount = rowCheckboxes.filter((checkbox) => checkbox.checked).length;
                if (selectedCount === 0) {
                    return;
                }
                bulkDeleteForm.submit();
            };

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

            let deleteConfirmationModal = null;
            let deleteConfirmationSubmit = null;
            let activeDeleteForm = null;

            const resetDeleteConfirmation = () => {
                if (!deleteConfirmationModal) {
                    return;
                }
                deleteConfirmationModal.style.display = 'none';
                document.body.style.overflow = '';
                activeDeleteForm = null;
            };

            const openDeleteConfirmation = (form) => {
                if (!deleteConfirmationModal) {
                    return;
                }
                activeDeleteForm = form;
                deleteConfirmationModal.style.display = 'flex';
                deleteConfirmationModal.style.justifyContent = 'center';
                deleteConfirmationModal.style.alignItems = 'center';
                deleteConfirmationModal.style.background = 'rgba(0,0,0,0.8)';
                deleteConfirmationModal.style.zIndex = '9999';
                document.body.style.overflow = 'hidden';
            };

            const handleDeleteButtonClick = (event) => {
                const button = event.target.closest('button[data-delete-open="true"]');
                if (!button || !wrapper || !wrapper.contains(button)) {
                    return;
                }

                event.preventDefault();
                const form = button.closest('form.delete-confirmation-form');
                if (!form) {
                    return;
                }

                closeOpenDropdowns();
                openDeleteConfirmation(form);
            };

            const initDeleteConfirmation = () => {
                deleteConfirmationModal = document.getElementById('delete-confirmation-modal');
                deleteConfirmationSubmit = deleteConfirmationModal ? deleteConfirmationModal.querySelector('#delete-confirmation-submit') : null;

                if (deleteConfirmationModal) {
                    if (deleteConfirmationModal.parentElement !== document.body) {
                        document.body.appendChild(deleteConfirmationModal);
                    }

                    deleteConfirmationModal.querySelectorAll('[data-delete-modal-close]').forEach((button) => {
                        button.addEventListener('click', resetDeleteConfirmation);
                    });
                }

                if (deleteConfirmationSubmit) {
                    deleteConfirmationSubmit.addEventListener('click', () => {
                        if (activeDeleteForm) {
                            activeDeleteForm.submit();
                        }
                    });
                }

                if (wrapper) {
                    wrapper.addEventListener('click', handleDeleteButtonClick);
                }
            };

            const init = () => {
                form = getForm();
                wrapper = getWrapper();
                if (!form || !wrapper) {
                    return;
                }

                restoreIcons();
                initDropdowns();
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

                const pageSizeElement = getPageSizeElement();
                if (pageSizeElement) {
                    pageSizeElement.removeEventListener('change', handlePageSizeChange);
                    pageSizeElement.addEventListener('change', handlePageSizeChange);
                }

                attachPaginationLinks();
                initBulkSelection();
                initDeleteConfirmation();
            };

            const handlePageSizeChange = () => {
                fetchList(buildUrl());
            };

            window.addEventListener('popstate', () => {
                if (form && wrapper) {
                    fetchList(window.location.href);
                }
            });

            document.addEventListener('change', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLInputElement)) {
                    return;
                }
                if (target.id === 'list-select-all') {
                    handleSelectAllChange();
                }
                if (target.classList.contains('list-row-checkbox')) {
                    handleRowCheckboxChange();
                }
            });

            init();
        })();
    </script>
@endsection
