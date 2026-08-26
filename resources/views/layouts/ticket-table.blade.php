@extends('dashboard.overview-1')

@section('title', $title ?? 'Gestiones')
@section('header', $title ?? 'Gestiones')

@section('breadcrumb')
    <nav aria-label="breadcrumb" class="flex hidden flex-1 xl:block">
        <ol class="flex items-center text-theme-1">
            <li><a href="{{ route('home') }}">Inicio</a></li>
            <li class="relative ml-5 pl-0.5 before:content-[''] before:w-[14px] before:h-[14px] before:bg-chevron-black before:transform before:rotate-[-90deg] before:bg-[length:100%] before:-ml-[1.125rem] before:absolute before:my-auto before:inset-y-0 text-slate-600 cursor-text">
                <span>{{ $title ?? 'Gestiones' }}</span>
            </li>
        </ol>
    </nav>
@endsection

@section('content')
    {{-- Layout ticket-table. --}}
    @if(!empty($listResource))
        <input type="hidden" id="erp-list-resource" value="{{ $listResource }}">
    @endif
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

                $currentPermissions = collect($authData['permissions'][$currentPermissionKey] ?? [])
                    ->map(fn ($value) => App\Support\ErpPermission::normalizeAction((string) $value))
                    ->filter()
                    ->unique()
                    ->values();

                if (!$isAdmin && is_string($currentPermissionKey) && str_contains($currentPermissionKey, '.') && $currentPermissions->isEmpty()) {
                    $parentModule = App\Support\ErpPermission::permissionKeyToModule($currentPermissionKey);
                    $currentPermissions = collect($authData['permissions'][$parentModule] ?? [])
                        ->map(fn ($value) => App\Support\ErpPermission::normalizeAction((string) $value))
                        ->filter()
                        ->unique()
                        ->values();
                }

                $canCreate = $isAdmin || $currentPermissions->contains('crear');
                $canAttend = $isAdmin || $currentPermissions->contains('ver');
                @endphp
                <div class="flex flex-col gap-x-3 gap-y-2 sm:flex-row md:ml-auto ticket-board__new">
                    @if(!empty($createRoute) && $canCreate)
                        <a href="{{ $createRoute }}" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed dark:border-danger/70 dark:text-danger" style="background-color:#c71010;color:#ffffff;">
                            <i data-tw-merge="" data-lucide="plus" class="mr-2 h-4 w-4 stroke-[1.3]"></i>
                            Nuevo {{ $singularTitle ?? 'Registro' }}
                        </a>
                    @endif
                </div>
            </div>

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
                @php
                    $resultCount = $items instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator
                        ? $items->total()
                        : collect($items ?? [])->count();
                    $resultsLabel = $resultsLabel ?? trim((string) preg_replace('/^Módulo\s+/u', '', $title ?? 'Registros'));
                @endphp
                <div id="list-table-wrapper" class="flex w-full flex-col gap-8">
                    <!-- ESTADÍSTICAS -->
                        <div class="box box--stacked ticket-stats-white  flex flex-col p-3">
                            <div class="grid grid-cols-4 gap-5">
                                @foreach($stats ?? [] as $stat)
                                    <div class="box col-span-4 rounded-none border border-dashed border-slate-300/80 bg-white p-5 shadow-none md:col-span-2 xl:col-span-1">
                                        <div class="text-base text-slate-500">{{ $stat['label'] }}</div>
                                            <div class="mt-1.5 text-2xl font-medium stat-value">{{ $stat['value'] }}</div>
                                    </div>
                                @endforeach
                                <div class="box col-span-4 rounded-none border border-dashed border-slate-300/80 bg-white p-5 shadow-none md:col-span-2 xl:col-span-1">
                                    <div class="text-base text-slate-500">{{ $resultsLabel }} encontrados</div>
                                    <div class="mt-1.5 text-2xl font-medium stat-value" data-list-result-stat>{{ number_format($resultCount, 0, ',', '.') }}</div>
                                </div>
                            </div>
                        </div>

                    <!-- TABLA -->
                    <div class="box box--stacked ticket-table-white flex w-full flex-col">
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
                    <div class="p-5">
                        <form id="list-filter-form" method="GET" action="{{ url()->current() }}" class="ticket-filters-bar">
                            <div class="ticket-filters-track pl-2">
                                <div class="ticket-filter-item ticket-filter-item--wide">
                                    <label class="ticket-filter-label">Buscar</label>
                                    <input type="text" name="q" autocomplete="off" value="{{ request('q') }}" placeholder="Buscar por ID o detalle..." class="ticket-filter-control">
                                </div>

                                @foreach($filters as $filter)
                                    @php
                                        $filterName = $filter['name'] ?? '';
                                        $filterLabel = $filter['label'] ?? 'Filtro';
                                        $filterType = $filter['type'] ?? 'select';
                                        $filterOptions = $filter['options'] ?? [];
                                        $filterPlaceholder = $filter['placeholder'] ?? 'Todos';
                                        $filterValue = (string) request($filterName, '');
                                        $isTomFilter = in_array($filterName, ['estado', 'tipo_operacion'], true);
                                    @endphp
                                    @continue($filterName === '')

                                    <div class="ticket-filter-item {{ $isTomFilter ? 'ticket-filter-item--tom' : '' }}">
                                        <label class="ticket-filter-label">{{ $filterLabel }}</label>
                                        @if($filterType === 'date')
                                            <input
                                                type="text"
                                                name="{{ $filterName }}"
                                                value="{{ $filterValue }}"
                                                placeholder="Selecciona la Fecha"
                                                autocomplete="off"
                                                class="ticket-filter-control datepicker"
                                                data-no-default="true"
                                                data-auto-apply="true"
                                            >
                                        @elseif(!empty($filterOptions))
                                            @php
                                                $selectClasses = 'ticket-filter-control ticket-filter-control--select';
                                                if ($isTomFilter) {
                                                    $selectClasses .= ' tom-select tom-select--compact';
                                                }
                                            @endphp
                                            <select
                                                name="{{ $filterName }}"
                                                class="{{ $selectClasses }}"
                                                data-placeholder="{{ $filterPlaceholder }}"
                                            >
                                                <option value="">{{ $filterPlaceholder }}</option>
                                                @foreach($filterOptions as $option)
                                                    @php
                                                        $optionValue = (string) ($option['value'] ?? '');
                                                        $optionLabel = (string) ($option['label'] ?? $optionValue);
                                                    @endphp
                                                    <option value="{{ $optionValue }}" @selected($filterValue === $optionValue)>{{ $optionLabel }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <input
                                                type="text"
                                                name="{{ $filterName }}"
                                                value="{{ $filterValue }}"
                                                placeholder="{{ $filterPlaceholder }}"
                                                class="ticket-filter-control"
                                            >
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                            <div class="ticket-filters-actions">
                                <button type="submit" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 bg-primary border-primary text-white">
                                    Aplicar
                                </button>
                                <a href="{{ url()->current() }}" data-list-clear="true" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none border-secondary text-slate-500">
                                    Limpiar
                                </a>
                            </div>
                        </form>
                    </div>

                    <div class="overflow-auto xl:overflow-visible">
                        <table data-tw-merge="" class="w-full text-left border-b border-slate-200/60 @if(collect($columns)->contains(fn ($column) => !empty($column['wrap'] ?? false))) table-fixed @endif">
                            <thead data-tw-merge="" class="">
                                <tr data-tw-merge="" class="">
                                    @foreach($columns as $column)
                                        @if(($column['key'] ?? '') === 'estado')
                                            <td data-tw-merge="" class="px-5 text-center align-middle border-b dark:border-darkmode-300 border-t border-slate-200/60 bg-slate-50 py-4 font-medium text-slate-500 @if(!empty($column['wrap'] ?? false)) w-[38%] @endif">
                                                <div class="th-sort th-sort--center" role="button" tabindex="0" data-sort-index="{{ $loop->index }}" aria-label="Ordenar {{ $column['label'] }}">
                                                    <span class="th-sort__label">{{ $column['label'] }}</span>
                                                    <span class="th-sort__icon" aria-hidden="true"></span>
                                                </div>
                                            </td>
                                        @else
                                            <td data-tw-merge="" class="px-5 border-b dark:border-darkmode-300 border-t border-slate-200/60 bg-slate-50 py-4 font-medium text-slate-500 @if(!empty($column['wrap'] ?? false)) w-[38%] @endif">
                                                <div class="th-sort" role="button" tabindex="0" data-sort-index="{{ $loop->index }}" aria-label="Ordenar {{ $column['label'] }}">
                                                    <span class="th-sort__label">{{ $column['label'] }}</span>
                                                    <span class="th-sort__icon" aria-hidden="true"></span>
                                                </div>
                                            </td>
                                        @endif
                                    @endforeach                         
                                    @if($showActionsColumn ?? true)
                                        <td data-tw-merge="" class="px-5 text-center align-middle border-b dark:border-darkmode-300 border-t border-slate-200/60 bg-slate-50 py-4 font-medium text-slate-500">
                                            Acción
                                        </td>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($items as $row)
                                    <tr data-tw-merge="" class="[&_td]:last:border-b-0">
                                        @foreach($columns as $columnIndex => $column)
                                            <td data-tw-merge="" class="px-5 border-b dark:border-darkmode-300 border-dashed py-4 dark:bg-darkmode-600 @if(($column['key'] ?? '') === 'estado') text-center align-middle @endif @if(!empty($column['wrap'] ?? false)) align-top w-[38%] @endif">
                                                @switch($column['type'] ?? 'text')
                                                    @case('text')
                                                        <span class="font-medium @if(!empty($column['wrap'] ?? false)) whitespace-normal break-words leading-5 @else whitespace-nowrap @endif">
                                                            @if($column['key'] === 'idticket' && data_get($row, $column['key']))
                                                                # {{ data_get($row, $column['key']) }}
                                                            @else
                                                                {{ data_get($row, $column['key']) ?? '-' }}
                                                            @endif
                                                        </span>
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
                                                                'activo' => ['bg' => '#dbeafe', 'text' => '#1d4ed8'],
                                                                'en proceso' => ['bg' => '#ffedd5', 'text' => '#9a3412'],
                                                                'resuelto' => ['bg' => '#dcfce7', 'text' => '#15803d'],
                                                                default       => ['bg' => '#f9fafb', 'text' => '#6b7280'],
                                                            };
                                                        @endphp
                                                        <div class="flex flex-col items-center gap-1">
                                                            <span class="inline-flex items-center rounded-md border px-2.5 py-2 text-xs font-semibold" style="background-color: {{ $estadoColores['bg'] }}; color: {{ $estadoColores['text'] }};">
                                                                {{ $estadoLabel }}
                                                            </span>
                                                            @if(!empty($row->estado_fase_texto))
                                                                <span class="text-xs font-medium text-slate-500">
                                                                    {{ $row->estado_fase_texto }}
                                                                </span>
                                                            @endif
                                                        </div>
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
                                                                <span class="font-medium whitespace-nowrap text-slate-800 dark:text-slate-100">{{ $name }}</span>
                                                                @if($subtitle)
                                                                    <div class="text-slate-500 text-sm whitespace-nowrap mt-0.5">{{ $subtitle }}</div>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    @break
                                                @endswitch
                                            </td>
                                        @endforeach
                                        @if($showActionsColumn ?? true)
                                            <td data-tw-merge="" class="px-5 border-b dark:border-darkmode-300 relative border-dashed py-4 dark:bg-darkmode-600">
                                                <div class="flex items-center justify-center h-full">
                                                    @php
                                                        $currentUser = session('erp_auth.usuario', '');
                                                        $lockedByOther = !empty($row->locked_by_other);
                                                        $statusText = $row->estado_accion_texto ?? null;
                                                        $estadoLabel = mb_strtolower(trim((string) ($row->estado ?? '')));
                                                        $isResolved = $estadoLabel === 'resuelto';
                                                    @endphp
                                                    @if(!$canAttend)
                                                        <div class="flex flex-col items-center gap-2">
                                                            <span class="inline-flex items-center justify-center rounded-md border px-3 py-2 text-sm font-semibold text-slate-500 bg-slate-100 border-slate-200">
                                                                Seguimiento
                                                            </span>
                                                            @if($statusText)
                                                                <span class="text-xs text-slate-500">{{ $statusText }}</span>
                                                            @endif
                                                        </div>
                                                    @elseif($isResolved)
                                                        <div class="flex flex-col items-center gap-2">
                                                            <button type="button" disabled class="inline-flex items-center justify-center rounded-md border px-3 py-2 text-sm font-semibold transition duration-200 opacity-60 cursor-not-allowed" style="color: #ffffff; background-color: #c71010;">
                                                                Finalizado
                                                            </button>
                                                            @if($statusText)
                                                                <span class="text-xs text-slate-500">{{ $statusText }}</span>
                                                            @endif
                                                        </div>
                                                    @elseif($lockedByOther)
                                                        <div class="flex flex-col items-center gap-2">
                                                            <button type="button" disabled class="inline-flex items-center justify-center rounded-md border px-3 py-2 text-sm font-semibold transition duration-200 opacity-60 cursor-not-allowed" style="color: #ffffff; background-color: #c71010;">
                                                                Atender
                                                            </button>
                                                            @if($statusText)
                                                                <span class="text-xs text-slate-500">{{ $statusText }}</span>
                                                            @endif
                                                        </div>
                                                    @else
                                                        <div class="flex flex-col items-center gap-2">
                                                            <a href="{{ route('modules.tickets.show', ['ticketId' => $row->idticket]) }}">
                                                                <button type="button" class="inline-flex items-center justify-center rounded-md border px-3 py-2 text-sm font-semibold transition duration-200 " style="color: #ffffff; background-color: #c71010;">
                                                                    Atender
                                                                </button>
                                                            </a>
                                                                @if($statusText)
                                                                    <span class="text-xs text-slate-500">{{ $statusText }}</span>
                                                            @endif
                                                        </div>
                                                    @endif
                                                </div>
                                            </td>
                                        @endif
                                    </tr>
                                    
                                @empty
                                    <tr>
                                        <td colspan="{{ count($columns) + 1 + ($showGroupClientsColumn ? 1 : 0) + (($showActionsColumn ?? true) ? 1 : 0) }}" class="px-5 py-10 text-center text-slate-500">
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
    </div>        
            
    <style>
        .ticket-stats-white,
        .ticket-table-white {
            background: #ffffff !important;
            box-shadow: none !important;
            border-radius: .6rem !important;
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

        .ticket-filters-bar {
            display: flex;
            align-items: center;
            gap: 1.8rem;
            justify-content: space-evenly;
        }

        .ticket-filters-track {
            display: flex;
            gap: 0.8rem;
            overflow: visible;
            justify-content:start;
            padding-bottom: 0.5rem;
        }

        .ticket-filter-item {
            min-width: 155px;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            flex: 0 0 auto;
        }

        .ticket-filter-item--tom {
            width: 165px;
            min-width: 165px;
            max-width: 165px;
            position: relative;
            z-index: 30;
        }

        .ticket-filter-item--tom:has(.dropdown-active) {
			z-index: 99 !important;
		}

        .ticket-filter-item--wide {
            min-width: 210px;
        }

        .ticket-filter-label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.04em;
        }

        .ticket-filter-control {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            padding: 0.48rem 0.65rem;
            font-size: 0.86rem;
            background: #fff;
            color: #0f172a;
        }

        .ticket-filter-control--select {
            appearance: auto;
            -webkit-appearance: menulist;
            -moz-appearance: auto;
            min-height: 2.45rem;
            padding: 0.42rem 0.75rem;
            line-height: 1.2rem;
        }

        #list-filter-form .ts-wrapper,
        #list-filter-form .ts-wrapper.single,
        #list-filter-form .ts-wrapper.plugin-dropdown_input,
        #list-filter-form .ts-wrapper.plugin-dropdown_input.focus,
        #list-filter-form .ts-wrapper.plugin-dropdown_input.dropdown-active,
        .ticket-filter-item--tom .ts-wrapper,
        .ticket-filter-item--tom .ts-wrapper.single,
        .ticket-filter-item--tom .ts-wrapper.plugin-dropdown_input,
        .ticket-filter-item--tom .ts-wrapper.plugin-dropdown_input.focus,
        .ticket-filter-item--tom .ts-wrapper.plugin-dropdown_input.dropdown-active {
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            display: block !important;
            box-sizing: border-box !important;
            flex: 1 1 auto !important;
        }

        /* Tom Select compacto para ticket-table, alineado con los demás inputs */
        #list-filter-form .tom-select.ts-wrapper,
        #list-filter-form .tom-select,
        #list-filter-form .tom-select.plugin-dropdown_input.focus.dropdown-active,
        .ticket-filter-item--tom .tom-select.ts-wrapper,
        .ticket-filter-item--tom .tom-select,
        .ticket-filter-item--tom .tom-select.plugin-dropdown_input.focus.dropdown-active {
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

        #list-filter-form .tom-select.ts-wrapper .ts-control,
        #list-filter-form .tom-select .ts-control,
        #list-filter-form .tom-select.plugin-dropdown_input.focus.dropdown-active .ts-control,
        .ticket-filter-item--tom .tom-select.ts-wrapper .ts-control,
        .ticket-filter-item--tom .tom-select .ts-control,
        .ticket-filter-item--tom .tom-select.plugin-dropdown_input.focus.dropdown-active .ts-control {
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
        .ticket-filter-item--tom .tom-select.ts-wrapper .ts-control .item {
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
        .ts-dropdown.ts-dropdown-portal,
        .ticket-filter-item--tom .tom-select .ts-dropdown {
            z-index: 9999 !important;
            border: 1px solid #d1d5db !important;
            border-radius: 0.5rem !important;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.12) !important;
            margin-top: 0.35rem !important;
            width: auto !important;
            min-width: 100% !important;
            background: #ffffff !important;
        }

        #list-filter-form .tom-select .ts-dropdown.ts-dropdown-portal {
            position: fixed !important;
            margin-top: 0 !important;
        }

        #list-filter-form .tom-select .ts-dropdown .dropdown-input-wrap,
        .ts-dropdown .dropdown-input-wrap {
            padding: 0.5rem !important;
        }

        #list-filter-form .tom-select .ts-dropdown .dropdown-input-wrap .dropdown-input,
        .ts-dropdown .dropdown-input-wrap .dropdown-input,
        .ticket-filter-item--tom .tom-select .ts-dropdown .dropdown-input-wrap .dropdown-input {
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
        .ts-dropdown .dropdown-input-wrap .dropdown-input:focus-visible,
        .ticket-filter-item--tom .tom-select .ts-dropdown .dropdown-input-wrap .dropdown-input:focus,
        .ticket-filter-item--tom .tom-select .ts-dropdown .dropdown-input-wrap .dropdown-input:focus-visible {
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
        .ts-dropdown .ts-dropdown-content,
        .ticket-filter-item--tom .tom-select .ts-dropdown .ts-dropdown-content {
            max-height: 150px !important;
            overflow-y: auto !important;
        }

        #list-filter-form .tom-select .ts-dropdown .option,
        .ts-dropdown .option,
        .ticket-filter-item--tom .tom-select .ts-dropdown .option {
            padding: 0.55rem 0.75rem;
            font-size: 0.86rem;
        }

        #list-filter-form .tom-select .ts-dropdown .option[data-selectable]:hover:not(.selected),
        #list-filter-form .tom-select .ts-dropdown .option[data-selectable].active:not(.selected),
        .ts-dropdown .option[data-selectable]:hover:not(.selected),
        .ts-dropdown .option[data-selectable].active:not(.selected),
        .ticket-filter-item--tom .tom-select .ts-dropdown .option[data-selectable]:hover:not(.selected),
        .ticket-filter-item--tom .tom-select .ts-dropdown .option[data-selectable].active:not(.selected) {
            background-color: #f8fafc;
        }

        #list-filter-form .tom-select .ts-dropdown .selected,
        .ts-dropdown .selected,
        .ticket-filter-item--tom .tom-select .ts-dropdown .selected {
            background-color: rgb(199 16 16 / 1) !important;
            color: #ffffff !important;
        }

        .ticket-filter-control:focus,
        .ticket-filter-control:focus-visible {
            outline: none;
            border-color: #c71010;
            box-shadow: 0 0 0 3px rgba(199, 16, 16, 0.15);
        }

        .ticket-filters-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
            margin-top: 0.8rem;
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

        @media (max-width: 768px) {
            .ticket-filters-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .ticket-filter-item,
            .ticket-filter-item--wide {
                min-width: 220px;
            }

        }

        /* Responsive fixes para barra de filtros en 'Gestiones' */
        @media (max-width: 768px) {
            .ticket-filters-bar {
                flex-direction: column;
                align-items: stretch;
                gap: 0.75rem;
                padding-left: 0.5rem;
                padding-right: 0.5rem;
            }

            .ticket-filters-track {
                display: flex;
                flex-wrap: wrap;
                gap: 0.6rem;
                justify-content: flex-start;
                padding-bottom: 0;
            }

            .ticket-filter-item,
            .ticket-filter-item--wide,
            .ticket-filter-item--tom {
                flex: 1 1 100%;
                min-width: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }

            #list-filter-form .tom-select.ts-wrapper,
            #list-filter-form .tom-select,
            .ticket-filter-item--tom .tom-select.ts-wrapper,
            .ticket-filter-item--tom .tom-select {
                width: 100% !important;
                min-width: 0 !important;
                max-width: 100% !important;
                box-sizing: border-box !important;
            }

            .ticket-filters-actions {
                width: 100%;
                display: flex;
                gap: 0.5rem;
                justify-content: flex-start;
                margin-top: 0.5rem;
                flex-wrap: wrap;
            }

            .ticket-filters-actions > * {
                flex: 0 0 auto;
            }
        }

        /* Muy pequeño: botones apilados y controles full-width */
        @media (max-width: 420px) {
            .ticket-filters-track {
                gap: 0.5rem;
            }

            .ticket-filter-item,
            .ticket-filter-item--wide,
            .ticket-filter-item--tom {
                flex: 1 1 100%;
            }

            .ticket-filters-actions {
                flex-direction: column;
                gap: 0.5rem;
            }

            .ticket-filters-actions > * {
                width: 100%;
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

            const getWrapper = () => document.getElementById(listWrapperId);
            const getForm = () => document.getElementById(formId);
            const getSearchInput = () => (form ? form.querySelector('[name="q"]') : null);
            const getPageSizeElement = () => (wrapper ? wrapper.querySelector('[name="perPage"]') : null);

            const syncTomSelectControlWidth = (instance) => {
                if (!instance) {
                    return;
                }

                const stableWidth = instance?.input?.dataset?.tomselectBaseWidth;
                if (!stableWidth) {
                    return;
                }

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
                if (!dropdown) {
                    return;
                }

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
                if (!instance || !instance.dropdown) {
                    return;
                }

                if (instance.settings.dropdownParent !== 'body') {
                    return;
                }

                const dropdown = instance.dropdown;
                if (dropdown.dataset.originalParent === undefined && dropdown.parentNode) {
                    dropdown.dataset.originalParent = '';
                }

                if (dropdown.parentNode !== document.body) {
                    dropdown.dataset.originalParent = dropdown.parentNode ? '1' : '';
                    document.body.appendChild(dropdown);
                }

                dropdown.classList.add('ts-dropdown-portal');
            };

            const cleanupTomSelectPortals = () => {
                try {
                    document.querySelectorAll('.ts-dropdown[data-erp-tomselect="1"]').forEach((el) => {
                        if (el && el.parentNode) {
                            el.parentNode.removeChild(el);
                        }
                    });
                } catch (error) {
                    console.warn('cleanupTomSelectPortals failed', error);
                }
            };

            const positionTomSelectDropdown = (instance) => {
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
                if (typeof window.TomSelect !== 'function') {
                    return;
                }

                cleanupTomSelectPortals();
                document.querySelectorAll('select.tom-select').forEach((select) => {
                    if (select.tomselect || select.tomSelect || select._tomselect) {
                        try {
                            (select.tomselect || select.tomSelect || select._tomselect).destroy();
                        } catch (error) {
                            console.warn('TomSelect destroy failed:', error);
                        }
                    }

                    const baseWidth = Math.ceil(select.getBoundingClientRect().width || select.offsetWidth || select.parentElement?.getBoundingClientRect().width || select.parentElement?.offsetWidth || 0);
                    if (baseWidth > 0) {
                        select.dataset.tomselectBaseWidth = `${baseWidth}px`;
                    }

                    const dropdownParent = select.closest('.ticket-filter-item--tom') || wrapper;

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
                        plugins: {
                            dropdown_input: {}
                        }
                    });

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
                let left = rect.right - menuWidth;
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
                window.addEventListener('scroll', handler, true);
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
                window.removeEventListener('scroll', handler, true);
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
                                    const hasFormControls = Boolean(menu.querySelector('select, input[type="text"], input[type="search"], textarea, button[type="submit"], [data-list-clear]'));
                                    const desiredWidth = hasFormControls
                                        ? Math.min(Math.max(Math.ceil(naturalWidth + 16), buttonWidth, 230), 230)
                                        : Math.min(Math.max(buttonWidth, 110), 140);

                                    menu.style.minWidth = `${desiredWidth}px`;
                                    menu.style.right = 'auto';
                                    const inner = menu.querySelector('.dropdown-content');
                                    if (inner) {
                                        inner.style.boxSizing = 'border-box';
                                        inner.style.width = `${desiredWidth}px`;
                                        inner.style.minWidth = `${desiredWidth}px`;
                                        inner.style.maxWidth = `${desiredWidth}px`;
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
				const currentResultStat = document.querySelector('[data-list-result-stat]');
				const nextResultStat = doc.querySelector('[data-list-result-stat]');
				if (currentResultStat && nextResultStat) {
					currentResultStat.textContent = nextResultStat.textContent;
				}
                cleanupTomSelectPortals();
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

            window.addEventListener('popstate', () => {
                if (form && wrapper) {
                    fetchList(window.location.href);
                }
            });

            init();
        })();
    </script>
@endsection
