<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" lang="es" data-erp-preload="1">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="erp-current-user" content="{{ session('erp_auth.usuario', '') }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="ERP SEGURTRACK">
    <meta name="author" content="SEGURTRACK">
    <title>@yield('title', 'ERP SEGURTRACK')</title>
    @vite(['resources/css/tailwise.css', 'resources/js/dashboard.js', 'resources/js/realtime.js'])
    @stack('styles')
</head>
<body>
    @php
        $authData = session('erp_auth', []);
        $roles = collect($authData['roles'] ?? [])->map(fn ($role) => mb_strtolower(trim((string) $role)))->filter()->values();
        $permissions = collect($authData['permissions'] ?? [])
            ->mapWithKeys(function ($actions, $permissionKey): array {
                $normalizedKey = \App\Support\ErpPermission::normalizePermissionKey((string) $permissionKey);
                if ($normalizedKey === null) {
                    return [];
                }

                $normalizedActions = collect(is_array($actions) ? $actions : [])
                    ->map(fn ($value) => \App\Support\ErpPermission::normalizeAction((string) $value))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                return [$normalizedKey => $normalizedActions];
            });

        $userName = $authData['usuario'] ?? 'Mi cuenta';
        $userPhoto = null;
        $userInitials = 'US';
        if (!empty($authData['personal_dni'])) {
            $personal = \Illuminate\Support\Facades\DB::table('personal')->where('dniPersonal', $authData['personal_dni'])->first();
            if ($personal && !empty($personal->foto)) {
                $userPhoto = asset('storage/' . $personal->foto);
            }
            if ($personal) {
                $nameParts = preg_split('/\s+/', trim(($personal->nombre ?? '') . ' ' . ($personal->apellido ?? '')));
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
                $userInitials = $initials ?: 'US';
            }
        }

        $moduleLinks = [
            'personal' => ['title' => 'Personal', 'route' => 'modules.personal', 'icon' => 'users'],
            'roles' => ['title' => 'Roles', 'route' => 'modules.roles', 'icon' => 'shield-check'],
            'usuarios' => ['title' => 'Usuarios', 'route' => 'modules.usuarios', 'icon' => 'user-square'],
            'clientes' => ['title' => 'Clientes', 'route' => 'modules.clientes', 'icon' => 'building-2'],
            'lineas_chips' => ['title' => 'Lineas y Chips', 'route' => 'modules.lineas-chips', 'icon' => 'smartphone'],
            'vehiculos' => ['title' => 'Vehículos', 'route' => 'modules.vehiculos', 'icon' => 'truck'],
            'dispositivo_cliente' => ['title' => 'Dispositivo cliente', 'route' => 'modules.dispositivo-cliente', 'icon' => 'cpu'],
            'servicio_cliente' => ['title' => 'Servicio cliente', 'route' => 'modules.servicio-cliente', 'icon' => 'file-text'],
            'almacen' => ['title' => 'Almacén', 'route' => 'modules.almacen', 'icon' => 'warehouse'],
            'tickets' => ['title' => 'Gestiones', 'route' => 'modules.tickets', 'icon' => 'ticket'],
            'configuracion' => ['title' => 'Configuración', 'route' => 'modules.configuracion', 'icon' => 'settings'],
            'sistema' => ['title' => 'Sistema', 'route' => 'modules.sistema', 'icon' => 'settings-2'],
        ];

        $isAdmin = $roles->contains('admin');

        $hasAnyAction = function (string $permissionKey) use ($permissions, $isAdmin): bool {
            if ($isAdmin) {
                return true;
            }

            $moduleKey = \App\Support\ErpPermission::permissionKeyToModule($permissionKey) ?? $permissionKey;
            $allowedActions = $moduleKey === 'tickets'
                ? collect(['ver', 'ver_flujo'])
                : collect(['ver']);

            $directActions = collect($permissions->get($permissionKey, []))
                ->map(fn ($value) => \App\Support\ErpPermission::normalizeAction((string) $value))
                ->filter()
                ->unique()
                ->values();

            return $directActions->intersect($allowedActions)->isNotEmpty();
        };

        $visibleClientes = [
            'cliente' => $hasAnyAction('clientes.cliente'),
            'grupo_cliente' => $hasAnyAction('clientes.grupo_cliente'),
            'servicio_cliente' => $hasAnyAction('servicio_cliente'),
            'vehiculos' => $hasAnyAction('vehiculos'),
            'dispositivo_cliente' => $hasAnyAction('dispositivo_cliente'),
        ];

        $visibleAlmacen = [
            'almacen' => $hasAnyAction('almacen.almacen'),
            'planes_servicios' => $hasAnyAction('almacen.planes_servicios'),
            'nota_ingreso' => $hasAnyAction('almacen.nota_ingreso'),
            'nota_salida' => $hasAnyAction('almacen.nota_salida'),
        ];

        $visibleConfiguracion = [
            'estado' => $hasAnyAction('configuracion.estado'),
            'tipo_contacto' => $hasAnyAction('configuracion.tipo_contacto'),
            'ubigeo' => $hasAnyAction('configuracion.ubigeo'),
            'cargo' => $hasAnyAction('configuracion.cargo'),
            'auditoria' => $hasAnyAction('configuracion.auditoria'),
            'moneda' => $hasAnyAction('configuracion.moneda'),
            'tributo' => $hasAnyAction('configuracion.tributo'),
            'unidad_medida' => $hasAnyAction('configuracion.unidad_medida'),
            'detalle_lista_precio' => $hasAnyAction('configuracion.detalle_lista_precio'),
            'elemento_almacen' => $hasAnyAction('configuracion.elemento_almacen'),
            'empresapropietaria' => $hasAnyAction('configuracion.empresapropietaria'),
            'modelo' => $hasAnyAction('configuracion.modelo'),
            'marca' => $hasAnyAction('configuracion.marca'),
            'tecnologia' => $hasAnyAction('configuracion.tecnologia'),
            'tipo_gasto' => $hasAnyAction('configuracion.tipo_gasto'),
            'tipo_cobro' => $hasAnyAction('configuracion.tipo_cobro'),
            'tipo_plataforma' => $hasAnyAction('configuracion.tipo_plataforma'),
            'plataforma' => $hasAnyAction('configuracion.plataforma'),
            'tipo_elemento' => $hasAnyAction('configuracion.tipo_elemento'),
            'tipo_documento' => $hasAnyAction('configuracion.tipo_documento'),
            'forma_pago' => $hasAnyAction('configuracion.forma_pago'),
            'entidad_bancaria' => $hasAnyAction('configuracion.entidad_bancaria'),
            'operador' => $hasAnyAction('configuracion.operador'),
            'tipo_vehiculo' => $hasAnyAction('configuracion.tipo_vehiculo'),
            'tipo_operacion' => $hasAnyAction('configuracion.tipo_operacion'),
            'lista_precio' => $hasAnyAction('configuracion.lista_precio'),
            'tipo_pedido' => $hasAnyAction('configuracion.tipo_pedido'),
            'proveedor' => $hasAnyAction('configuracion.proveedor'),
            'certificadosunat' => $hasAnyAction('configuracion.certificadosunat'),
            'vigencia_oferta' => $hasAnyAction('configuracion.vigencia_oferta'),
            'vista' => $hasAnyAction('configuracion.vista'),
            'flujo' => $hasAnyAction('configuracion.flujo'),
            'flujoregla' => $hasAnyAction('configuracion.flujoregla'),
            'historialflujo' => $hasAnyAction('configuracion.historialflujo'),
        ];

        $visibleSistema = [
            'vista' => $hasAnyAction('sistema.vista'),
            'flujo' => $hasAnyAction('sistema.flujo'),
            'flujoregla' => $hasAnyAction('sistema.flujoregla'),
            'historialflujo' => $hasAnyAction('sistema.historialflujo'),
        ];

        $visibleLineasChips = [
            'numero_telefonico' => $hasAnyAction('lineas_chips.numero_telefonico'),
            'numero_dispositivo' => $hasAnyAction('lineas_chips.numero_dispositivo'),
            'simcard' => $hasAnyAction('lineas_chips.simcard'),
            'detallesimcard' => $hasAnyAction('lineas_chips.detallesimcard'),
        ];

        $visibleModules = [];
        if ($hasAnyAction('personal')) {
            $visibleModules[] = 'personal';
        }
        if ($hasAnyAction('roles')) {
            $visibleModules[] = 'roles';
        }
        if ($hasAnyAction('usuarios')) {
            $visibleModules[] = 'usuarios';
        }
        if ($hasAnyAction('clientes') || collect($visibleClientes)->contains(true)) {
            $visibleModules[] = 'clientes';
        }
        if ($hasAnyAction('lineas_chips') || collect($visibleLineasChips)->contains(true)) {
            $visibleModules[] = 'lineas_chips';
        }
        if ($hasAnyAction('almacen') || collect($visibleAlmacen)->contains(true)) {
            $visibleModules[] = 'almacen';
        }
        if ($hasAnyAction('tickets')) {
            $visibleModules[] = 'tickets';
        }
        if ($hasAnyAction('configuracion') || collect($visibleConfiguracion)->contains(true)) {
            $visibleModules[] = 'configuracion';
        }
        if ($hasAnyAction('sistema') || collect($visibleSistema)->contains(true)) {
            $visibleModules[] = 'sistema';
        }

        $userModuleActions = collect($permissions->get('usuarios', []))
            ->map(fn ($value) => \App\Support\ErpPermission::normalizeAction((string) $value))
            ->filter()
            ->unique()
            ->values();

        $canResetOwnPassword = $isAdmin || (
            $userModuleActions->contains('ver')
            && $userModuleActions->contains('editar')
        );
    @endphp

    <div class="dagger before:content-[''] before:bg-gradient-to-b before:from-slate-100 before:to-slate-50 before:fixed before:inset-0">
        <div class="[&.loading-page--before-hide]:h-screen [&.loading-page--before-hide]:relative loading-page loading-page--before-hide [&.loading-page--before-hide]:before:block [&.loading-page--hide]:before:opacity-0 before:content-[''] before:transition-opacity before:duration-300 before:hidden before:inset-0 before:h-screen before:w-screen before:fixed before:bg-gradient-to-b before:from-theme-1 before:to-theme-2 before:z-[60] [&.loading-page--before-hide]:after:block [&.loading-page--hide]:after:opacity-0 after:content-[''] after:transition-opacity after:duration-300 after:hidden after:h-16 after:w-16 after:animate-pulse after:fixed after:opacity-50 after:inset-0 after:m-auto after:bg-loading-puff after:bg-cover after:z-[61]">
            <div class="fixed top-0 left-0 z-50 h-screen side-menu group side-menu--collapsed">
                <div class="box fixed inset-x-0 top-0 z-10 flex h-[65px] rounded-none border-x-0 border-t-0">
                    <div class="side-menu__content bg-white flex-none flex items-center z-10 px-5 h-full xl:w-[275px] overflow-hidden relative duration-300 group-[.side-menu--collapsed]:xl:w-[91px] group-[.side-menu--collapsed.side-menu--on-hover]:xl:w-[275px] group-[.side-menu--collapsed.side-menu--on-hover]:xl:shadow-[6px_0_12px_-4px_#0000001f] before:content-[''] before:hidden before:xl:block before:absolute before:right-0 before:border-r before:border-dashed before:border-slate-300/70 before:h-4/6 before:group-[.side-menu--collapsed.side-menu--on-hover]:xl:border-solid before:group-[.side-menu--collapsed.side-menu--on-hover]:xl:h-full">
                        <a class="hidden items-center transition-[margin] xl:flex group-[.side-menu--collapsed.side-menu--on-hover]:xl:ml-0 group-[.side-menu--collapsed]:xl:ml-2" href="{{ route('home') }}">
                            <div class="flex h-[34px] w-[34px] items-center justify-center rounded-lg bg-red-600 ">
                                <img src="{{ asset('images/logo-baner.png') }}" alt="Segurtrack" class="h-[42px] w-auto rounded-[4px] bg-white p-0.5">
                            </div>
                            <div class="ml-3.5 font-medium transition-opacity group-[.side-menu--collapsed.side-menu--on-hover]:xl:opacity-100 group-[.side-menu--collapsed]:xl:opacity-0">SEGURTRACK</div>
                        </a>

                        <a class="toggle-compact-menu ml-auto hidden h-[20px] w-[20px] items-center justify-center rounded-full border border-slate-600/40 transition-[opacity,transform] hover:bg-slate-600/5 group-[.side-menu--collapsed]:xl:rotate-180 group-[.side-menu--collapsed.side-menu--on-hover]:xl:opacity-100 group-[.side-menu--collapsed]:xl:opacity-0 3xl:flex" href="#">
                            <i data-lucide="arrow-left" class="h-3.5 w-3.5 stroke-[1.3]"></i>
                        </a>

                        <div class="flex items-center gap-1 xl:hidden">
                            <a class="open-mobile-menu rounded-full p-2 hover:bg-slate-100" href="#">
                                <i data-lucide="align-justify" class="stroke-[1] h-[18px] w-[18px]"></i>
                            </a>
                            <a class="rounded-full p-2 hover:bg-slate-100" data-tw-toggle="modal" data-tw-target="#quick-search" href="javascript:;">
                                <i data-lucide="search" class="stroke-[1] h-[18px] w-[18px]"></i>
                            </a>
                        </div>
                    </div>

                    <div class="absolute inset-x-0 h-full transition-[padding] duration-100 xl:pl-[275px] group-[.side-menu--collapsed]:xl:pl-[91px]">
                        <div class="flex h-full w-full items-center px-5">
                            @hasSection('breadcrumb')
                                @yield('breadcrumb')
                            @else
                                <nav aria-label="breadcrumb" class="flex hidden flex-1 xl:block">
                                    <ol class="flex items-center text-theme-1">
                                        <li><a href="#">Inicio</a></li>
                                        <li class="relative ml-5 pl-0.5 before:content-[''] before:w-[14px] before:h-[14px] before:bg-chevron-black before:transform before:rotate-[-90deg] before:bg-[length:100%] before:-ml-[1.125rem] before:absolute before:my-auto before:inset-y-0 text-slate-600 cursor-text">
                                            <a href="#">@yield('header', 'Inicio')</a>
                                        </li>
                                    </ol>
                                </nav>
                            @endif

                            <div class="relative hidden flex-1 justify-center xl:flex" data-tw-toggle="modal" data-tw-target="#quick-search">
                                <div class="flex w-[350px] cursor-pointer items-center rounded-[0.5rem] border bg-slate-50 px-3.5 py-2 text-slate-400 transition-colors hover:bg-slate-100">
                                    <i data-lucide="search" class="stroke-[1] h-[18px] w-[18px]"></i>
                                    <div class="ml-2.5 mr-auto">Búsqueda rápida...</div>
                                </div>
                            </div>

                            <div class="flex flex-1 items-center">
                                <div class="ml-auto flex items-center gap-1">
                                    <a class="request-full-screen rounded-full p-2 hover:bg-slate-100" href="javascript:;" aria-label="Pantalla completa" title="Pantalla completa">
                                        <i data-lucide="expand" class="stroke-[1] h-[18px] w-[18px]"></i>
                                    </a>
                                    <a class="rounded-full p-2 hover:bg-slate-100" data-tw-toggle="modal" data-tw-target="#notifications-panel" href="javascript:;">
                                        <i data-lucide="bell" class="stroke-[1] h-[18px] w-[18px]"></i>
                                    </a>
                                </div>

                                <div data-tw-placement="bottom-end" class="dropdown relative ml-5">
                                    <button data-tw-toggle="dropdown" aria-expanded="false" class="cursor-pointer h-[36px] w-[36px] overflow-hidden rounded-full border-[3px] border-slate-200/70 bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-100">
                                        @if(!empty($userPhoto ?? null))
                                            <img src="{{ $userPhoto }}" alt="Usuario">
                                        @else
                                            <span class="flex h-full w-full items-center justify-center text-sm font-semibold">{{ $userInitials ?? 'US' }}</span>
                                        @endif
                                    </button>
                                    <div data-transition="" data-selector=".show" data-enter="transition-all ease-linear duration-150" data-enter-from="absolute !mt-5 invisible opacity-0 translate-y-1" data-enter-to="!mt-1 visible opacity-100 translate-y-0" data-leave="transition-all ease-linear duration-150" data-leave-from="!mt-1 visible opacity-100 translate-y-0" data-leave-to="absolute !mt-5 invisible opacity-0 translate-y-1" class="dropdown-menu absolute z-[9999] hidden">
                                        <div class="dropdown-content rounded-md border-transparent bg-white p-2 shadow-[0px_3px_10px_#00000017] mt-1 w-56">
                                            <p class="cursor-pointer flex items-center p-2 transition duration-300 ease-in-out rounded-md hover:bg-slate-200/60 dropdown-item">
                                                <i data-lucide="user" class="stroke-[1] mr-2 h-4 w-4"></i>
                                                {{ $userName ?? 'Mi cuenta' }}
                                            </p>
                                            @if($canResetOwnPassword)
                                                <a class="cursor-pointer flex items-center p-2 transition duration-300 ease-in-out rounded-md hover:bg-slate-200/60 dropdown-item" href="{{ route('modules.usuarios.edit', ['usuario' => session('erp_auth.usuario')]) }}">
                                                    <i data-lucide="lock" class="stroke-[1] mr-2 h-4 w-4"></i>
                                                    Restablecer contraseña
                                                </a>
                                            @endif
                                            <div class="h-px my-2 -mx-2 bg-slate-200/60"></div>
                                            <form method="POST" action="{{ route('logout') }}" class="w-full">
                                                @csrf
                                                <button type="submit" class="cursor-pointer flex w-full items-center p-2 transition duration-300 ease-in-out rounded-md hover:bg-slate-200/60 dropdown-item">
                                                    <i data-lucide="power" class="stroke-[1] mr-2 h-4 w-4"></i>
                                                    Cerrar sesión
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="side-menu__content absolute inset-y-0 z-10 xl:top-[65px] xl:z-0">
                    <div class="box xl:ml-0 border-y-0 border-l-0 rounded-none w-[275px] duration-300 transition-[width,margin] group-[.side-menu--collapsed]:xl:w-[91px] group-[.side-menu--collapsed.side-menu--on-hover]:xl:shadow-[6px_0_12px_-4px_#0000000f] group-[.side-menu--collapsed.side-menu--on-hover]:xl:w-[275px] relative overflow-hidden h-full flex flex-col after:content-[''] after:fixed after:inset-0 after:bg-black/80 after:z-[-1] after:xl:hidden group-[.side-menu--mobile-menu-open]:ml-0 group-[.side-menu--mobile-menu-open]:after:block -ml-[275px] after:hidden">
                        <div class="close-mobile-menu fixed ml-[275px] w-10 h-10 items-center justify-center xl:hidden [&.close-mobile-menu--mobile-menu-open]:flex hidden">
                            <a class="ml-5 mt-5" href="#">
                                <i data-lucide="x" class="stroke-[1] h-8 w-8 text-white"></i>
                            </a>
                        </div>

                        <div class="scrollable-ref w-full h-full z-20 px-5 overflow-y-auto overflow-x-hidden pb-3 [-webkit-mask-image:-webkit-linear-gradient(top,rgba(0,0,0,0),black_30px)] [&:-webkit-scrollbar]:w-0 [&:-webkit-scrollbar]:bg-transparent [&_.simplebar-content]:p-0 [&_.simplebar-track.simplebar-vertical]:w-[10px] [&_.simplebar-track.simplebar-vertical]:mr-0.5 [&_.simplebar-track.simplebar-vertical_.simplebar-scrollbar]:before:bg-slate-400/30">
                            <ul class="scrollable">
                                <li class="side-menu__divider">INICIO</li>

                                <li>
                                    <a href="{{ route('home') }}" class="side-menu__link {{ request()->routeIs('home') ? 'side-menu__link--active' : '' }}">
                                        <i data-lucide="home" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                        <div class="side-menu__link__title">Inicio</div>
                                    </a>
                                </li>

                                <li class="side-menu__divider">MODULOS</li>

                                @foreach($visibleModules as $module)
                                    @php $link = $moduleLinks[$module]; @endphp
                                    @if($module === 'personal')
                                        <li>
                                            <a href="{{ route('modules.personal') }}" class="side-menu__link {{ request()->routeIs('modules.personal*') ? 'side-menu__link--active' : '' }}">
                                                <i data-lucide="{{ $link['icon'] }}" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                                <div class="side-menu__link__title">{{ $link['title'] }}</div>
                                            </a>
                                        </li>
                                    @elseif($module === 'roles')
                                        <li>
                                            <a href="{{ route('modules.roles') }}" class="side-menu__link {{ request()->routeIs('modules.roles*') ? 'side-menu__link--active' : '' }}">
                                                <i data-lucide="{{ $link['icon'] }}" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                                <div class="side-menu__link__title">{{ $link['title'] }}</div>
                                            </a>
                                        </li>
                                    @elseif($module === 'usuarios')
                                        <li>
                                            <a href="{{ route('modules.usuarios') }}" class="side-menu__link {{ request()->routeIs('modules.usuarios*') ? 'side-menu__link--active' : '' }}">
                                                <i data-lucide="{{ $link['icon'] }}" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                                <div class="side-menu__link__title">{{ $link['title'] }}</div>
                                            </a>
                                        </li>
                                    @elseif($module === 'clientes')
                                        @php
                                            $isClientesActive = request()->routeIs('modules.clientes*')
                                                || request()->routeIs('modules.vehiculos*')
                                                || request()->routeIs('modules.dispositivo-cliente*')
                                                || request()->routeIs('modules.servicio-cliente*');
                                        @endphp
                                        <li>
                                            <a href="javascript:;" class="side-menu__link {{ $isClientesActive ? 'side-menu__link--active' : '' }} [&.side-menu__link--active]:side-menu__link--open" data-tw-toggle="collapse" data-tw-target="#side-menu-clientes">
                                                <i data-lucide="{{ $link['icon'] }}" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                                <div class="side-menu__link__title">{{ $link['title'] }}</div>
                                                <i data-lucide="chevron-down" class="stroke-[1] w-5 h-5 side-menu__link__chevron"></i>
                                            </a>
                                            <ul id="side-menu-clientes" class="side-menu__ul-collapse {{ $isClientesActive ? '' : 'hidden' }}">
                                                @if($visibleClientes['cliente'])
                                                    <li>
                                                        <a href="{{ route('modules.clientes') }}" class="side-menu__link {{ request()->routeIs('modules.clientes') ? 'side-menu__link--active' : '' }}">
                                                            <i data-lucide="chevron-right" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Cliente</div>
                                                        </a>
                                                    </li>
                                                @endif
                                                @if($visibleClientes['grupo_cliente'])
                                                    <li>
                                                        <a href="{{ route('modules.clientes.grupos.index') }}" class="side-menu__link {{ request()->routeIs('modules.clientes.grupos*') ? 'side-menu__link--active' : '' }}">
                                                            <i data-lucide="chevron-right" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Grupo Cliente</div>
                                                        </a>
                                                    </li>
                                                @endif
                                                @if($visibleClientes['servicio_cliente'])
                                                    <li>
                                                        <a href="{{ route('modules.servicio-cliente') }}" class="side-menu__link {{ request()->routeIs('modules.servicio-cliente*') ? 'side-menu__link--active' : '' }}">
                                                            <i data-lucide="chevron-right" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Servicio cliente</div>
                                                        </a>
                                                    </li>
                                                @endif
                                                @if($visibleClientes['vehiculos'])
                                                    <li>
                                                        <a href="{{ route('modules.vehiculos') }}" class="side-menu__link {{ request()->routeIs('modules.vehiculos*') ? 'side-menu__link--active' : '' }}">
                                                            <i data-lucide="chevron-right" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Vehículos</div>
                                                        </a>
                                                    </li>
                                                @endif
                                                @if($visibleClientes['dispositivo_cliente'])
                                                    <li>
                                                        <a href="{{ route('modules.dispositivo-cliente') }}" class="side-menu__link {{ request()->routeIs('modules.dispositivo-cliente*') ? 'side-menu__link--active' : '' }}">
                                                            <i data-lucide="chevron-right" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Dispositivo cliente</div>
                                                        </a>
                                                    </li>
                                                @endif
                                            </ul>
                                        </li>
                                    @elseif($module === 'lineas_chips')
                                        @php
                                            $isLineasChipsActive = request()->routeIs('modules.lineas-chips*');
                                            $showLineasChips = collect($visibleLineasChips)->contains(true);
                                        @endphp
                                        <li>
                                            <a href="javascript:;" class="side-menu__link {{ $isLineasChipsActive ? 'side-menu__link--active' : '' }} [&.side-menu__link--active]:side-menu__link--open" data-tw-toggle="collapse" data-tw-target="#side-menu-lineas-chips">
                                                <i data-lucide="{{ $link['icon'] }}" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                                <div class="side-menu__link__title">{{ $link['title'] }}</div>
                                                <i data-lucide="chevron-down" class="stroke-[1] w-5 h-5 side-menu__link__chevron"></i>
                                            </a>
                                            <ul id="side-menu-lineas-chips" class="side-menu__ul-collapse {{ $isLineasChipsActive ? '' : 'hidden' }}">
                                                @if($visibleLineasChips['numero_dispositivo'])
                                                    <li>
                                                        <a href="{{ route('modules.lineas-chips.numeros-dispositivo.index') }}" class="side-menu__link {{ request()->routeIs('modules.lineas-chips.numeros-dispositivo*') ? 'side-menu__link--active' : '' }}">
                                                            <i data-lucide="chevron-right" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Números de dispositivo</div>
                                                        </a>
                                                    </li>
                                                @endif
                                                @if($visibleLineasChips['numero_telefonico'])
                                                    <li>
                                                        <a href="{{ route('modules.lineas-chips.numeros-telefonico.index') }}" class="side-menu__link {{ request()->routeIs('modules.lineas-chips.numeros-telefonico*') ? 'side-menu__link--active' : '' }}">
                                                            <i data-lucide="chevron-right" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Número Telefónico</div>
                                                        </a>
                                                    </li>
                                                @endif
                                                @if($visibleLineasChips['simcard'])
                                                    <li>
                                                        <a href="{{ route('modules.lineas-chips.simcard.index') }}" class="side-menu__link {{ request()->routeIs('modules.lineas-chips.simcard*') ? 'side-menu__link--active' : '' }}">
                                                            <i data-lucide="chevron-right" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Plastico(SimCard)</div>
                                                        </a>
                                                    </li>
                                                @endif
                                                @if($visibleLineasChips['detallesimcard'])
                                                    <li>
                                                        <a href="{{ route('modules.lineas-chips.detallesimcard.index') }}" class="side-menu__link {{ request()->routeIs('modules.lineas-chips.detallesimcard*') ? 'side-menu__link--active' : '' }}">
                                                            <i data-lucide="chevron-right" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Asignacion SimCard</div>
                                                        </a>
                                                    </li>
                                                @endif
                                            </ul>
                                        </li>
                                    @elseif($module === 'almacen')
                                        @php
                                            $isAlmacenActive = request()->routeIs('modules.almacen*');
                                        @endphp
                                        <li>
                                            <a href="javascript:;" class="side-menu__link {{ $isAlmacenActive ? 'side-menu__link--active' : '' }} [&.side-menu__link--active]:side-menu__link--open" data-tw-toggle="collapse" data-tw-target="#side-menu-almacen">
                                                <i data-lucide="package" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                                <div class="side-menu__link__title">{{ $link['title'] }}</div>
                                                <i data-lucide="chevron-down" class="stroke-[1] w-5 h-5 side-menu__link__chevron"></i>
                                            </a>
                                            <ul id="side-menu-almacen" class="side-menu__ul-collapse {{ $isAlmacenActive ? '' : 'hidden' }}">
                                                @if($visibleAlmacen['almacen'])
                                                    <li>
                                                        <a href="{{ route('modules.almacen') }}" class="side-menu__link {{ request()->routeIs('modules.almacen') ? 'side-menu__link--active' : '' }}">
                                                            <i data-lucide="chevron-right" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Almacén</div>
                                                        </a>
                                                    </li>
                                                @endif
                                                @if($visibleAlmacen['planes_servicios'])
                                                    <li>
                                                        <a href="{{ route('modules.almacen.planes-servicios.index') }}" class="side-menu__link {{ request()->routeIs('modules.almacen.planes-servicios*') ? 'side-menu__link--active' : '' }}">
                                                            <i data-lucide="chevron-right" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Planes y servicios</div>
                                                        </a>
                                                    </li>
                                                @endif
                                                @if($visibleAlmacen['nota_ingreso'])
                                                    <li>
                                                        <a href="{{ route('modules.almacen.nota-ingreso.index') }}" class="side-menu__link {{ request()->routeIs('modules.almacen.nota-ingreso*') ? 'side-menu__link--active' : '' }}">
                                                            <i data-lucide="chevron-right" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Nota de ingreso</div>
                                                        </a>
                                                    </li>
                                                @endif
                                                @if($visibleAlmacen['nota_salida'])
                                                    <li>
                                                        <a href="{{ route('modules.almacen.nota-salida.index') }}" class="side-menu__link {{ request()->routeIs('modules.almacen.nota-salida*') ? 'side-menu__link--active' : '' }}">
                                                            <i data-lucide="chevron-right" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Nota de salida</div>
                                                        </a>
                                                    </li>
                                                @endif
                                            </ul>
                                        </li>
                                    @elseif($module === 'tickets')
                                        <li>
                                            <a href="{{ route('modules.tickets') }}" class="side-menu__link {{ request()->routeIs('modules.tickets*') ? 'side-menu__link--active' : '' }}">
                                                <i data-lucide="{{ $link['icon'] }}" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                                <div class="side-menu__link__title">{{ $link['title'] }}</div>
                                            </a>
                                        </li>
                                    @elseif($module === 'configuracion')
                                        @php
                                            $isConfiguracionActive = request()->routeIs('modules.configuracion*');
                                        @endphp
                                        <li>
                                            <a href="javascript:;" class="side-menu__link {{ $isConfiguracionActive ? 'side-menu__link--active' : '' }} [&.side-menu__link--active]:side-menu__link--open" data-tw-toggle="collapse" data-tw-target="#side-menu-configuracion">
                                                <i data-lucide="{{ $link['icon'] }}" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                                <div class="side-menu__link__title">{{ $link['title'] }}</div>
                                                <i data-lucide="chevron-down" class="stroke-[1] w-5 h-5 side-menu__link__chevron"></i>
                                            </a>
                                            @php
                                                $showConfigCliente = $visibleConfiguracion['ubigeo'] || $visibleConfiguracion['estado'] || $visibleConfiguracion['tipo_contacto'];
                                                $showConfigPersonal = $visibleConfiguracion['cargo'];
                                                $showConfigVehiculos = $visibleConfiguracion['tipo_vehiculo'] || $visibleConfiguracion['operador'];
                                                $showConfigTicket = $visibleConfiguracion['tipo_operacion'];
                                                $showConfigFinanzas = $visibleConfiguracion['proveedor'] || $visibleConfiguracion['tipo_cobro'] || $visibleConfiguracion['entidad_bancaria'] || $visibleConfiguracion['tipo_gasto'];
                                                $showConfigAlmacen = $visibleConfiguracion['empresapropietaria'] || $visibleConfiguracion['modelo'] || $visibleConfiguracion['marca'] || $visibleConfiguracion['unidad_medida'] || $visibleConfiguracion['tipo_pedido'] || $visibleConfiguracion['tipo_elemento'] || $visibleConfiguracion['tecnologia'] || $visibleConfiguracion['lista_precio'] || $visibleConfiguracion['detalle_lista_precio'] || $visibleConfiguracion['elemento_almacen'];
                                                $showConfigFacturacion = $visibleConfiguracion['vigencia_oferta'] || $visibleConfiguracion['moneda'] || $visibleConfiguracion['forma_pago'] || $visibleConfiguracion['certificadosunat'] || $visibleConfiguracion['tributo'] || $visibleConfiguracion['tipo_documento'];
                                                $showConfigPlataforma = $visibleConfiguracion['tipo_plataforma'] || $visibleConfiguracion['plataforma'] ;
                                                $showConfigSistema = $visibleSistema['vista'] || $visibleSistema['flujo'] || $visibleSistema['flujoregla'] || $visibleSistema['historialflujo'];
                                                $isConfigClienteActive = request()->routeIs('modules.configuracion.ubigeos*') || request()->routeIs('modules.configuracion.estados*') || request()->routeIs('modules.configuracion.tipos-contacto*');
                                                $isConfigPersonalActive = request()->routeIs('modules.configuracion.cargos*');
                                                $isConfigVehiculosActive = request()->routeIs('modules.configuracion.tipos-vehiculo*') || request()->routeIs('modules.configuracion.operadores*');
                                                $isConfigTicketActive = request()->routeIs('modules.configuracion.tipos-operacion*');
                                                $isConfigFinanzasActive = request()->routeIs('modules.configuracion.proveedores*') || request()->routeIs('modules.configuracion.tipos-cobro*') || request()->routeIs('modules.configuracion.entidades-bancarias*') || request()->routeIs('modules.configuracion.tipos-gasto*');
                                                $isConfigAlmacenActive = request()->routeIs('modules.configuracion.empresapropietaria*') || request()->routeIs('modules.configuracion.modelo*') || request()->routeIs('modules.configuracion.marcas*') || request()->routeIs('modules.configuracion.unidad-medida*') || request()->routeIs('modules.configuracion.tipos-pedido*') || request()->routeIs('modules.configuracion.tipos-elemento*') || request()->routeIs('modules.configuracion.tecnologias*') || request()->routeIs('modules.configuracion.listas-precio*') || request()->routeIs('modules.configuracion.detalle-lista-precio*') || request()->routeIs('modules.configuracion.elemento-almacen*');
                                                $isConfigFacturacionActive = request()->routeIs('modules.configuracion.vigencias-oferta*') || request()->routeIs('modules.configuracion.monedas*') || request()->routeIs('modules.configuracion.formas-pago*') || request()->routeIs('modules.configuracion.certificados-sunat*') || request()->routeIs('modules.configuracion.tributos*') || request()->routeIs('modules.configuracion.tipos-documento*');
                                                $isConfigPlataformaActive = request()->routeIs('modules.configuracion.tipos-plataforma*') || request()->routeIs('modules.configuracion.plataforma*');
                                                $isConfigSistemaActive = request()->routeIs('modules.configuracion.vistas*') || request()->routeIs('modules.configuracion.flujos*') || request()->routeIs('modules.configuracion.flujo-reglas*') || request()->routeIs('modules.configuracion.historial-flujos*');
                                            

                                                $showAnyGroupedConfig = $showConfigCliente || $showConfigPersonal || $showConfigVehiculos || $showConfigTicket || $showConfigFinanzas || $showConfigAlmacen || $showConfigFacturacion || $showConfigPlataforma || $showConfigSistema;
                                            @endphp
                                            <ul id="side-menu-configuracion" class="side-menu__ul-collapse {{ $isConfiguracionActive ? '' : 'hidden' }}">
                                                @if($showConfigCliente)
                                                    <li>
                                                        <a href="javascript:;" class="side-menu__link ml-2 border-slate-200/20 pl-2 {{ $isConfigClienteActive ? 'side-menu__link--active' : '' }} [&.side-menu__link--active]:side-menu__link--open" data-tw-toggle="collapse" data-tw-target="#side-menu-config-cliente">
                                                            <i data-lucide="{{ $moduleLinks['clientes']['icon'] }}" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Cliente</div>
                                                            <i data-lucide="chevron-down" class="stroke-[1] w-3 h-3 side-menu__link__chevron"></i>
                                                        </a>
                                                        <ul id="side-menu-config-cliente" class="side-menu__ul-collapse {{ $isConfigClienteActive ? '' : 'hidden' }}">
                                                             @if($visibleConfiguracion['estado'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.estados.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.estados*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Estado Cliente</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                            @if($visibleConfiguracion['tipo_contacto'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.tipos-contacto.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.tipos-contacto*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Tipo de Contacto</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                            @if($visibleConfiguracion['ubigeo'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.ubigeos.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.ubigeos*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-4 h-4 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Ubigeo</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                        </ul>
                                                    </li>
                                                @endif
                                                @if($showConfigFinanzas)
                                                    <li>
                                                        <a href="javascript:;" class=" ml-2 border-slate-200/20 pl-2 side-menu__link {{ $isConfigFinanzasActive ? 'side-menu__link--active' : '' }} [&.side-menu__link--active]:side-menu__link--open" data-tw-toggle="collapse" data-tw-target="#side-menu-config-finanzas">
                                                            <i data-lucide="wallet" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Finanzas</div>
                                                            <i data-lucide="chevron-down" class="stroke-[1] w-3 h-3 side-menu__link__chevron"></i>
                                                        </a>
                                                        <ul id="side-menu-config-finanzas" class="side-menu__ul-collapse {{ $isConfigFinanzasActive ? '' : 'hidden' }}">
                                                            @if($visibleConfiguracion['entidad_bancaria'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.entidades-bancarias.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.entidades-bancarias*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Entidad Bancaria</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                            @if($visibleConfiguracion['proveedor'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.proveedores.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.proveedores*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Proveedor</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                            @if($visibleConfiguracion['tipo_cobro'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.tipos-cobro.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.tipos-cobro*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Tipo de Cobro</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                            @if($visibleConfiguracion['tipo_gasto'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.tipos-gasto.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.tipos-gasto*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Tipo de Gasto</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                        </ul>
                                                    </li>
                                                @endif
                                                @if($showConfigFacturacion)
                                                    <li>
                                                        <a href="javascript:;" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ $isConfigFacturacionActive ? 'side-menu__link--active' : '' }} [&.side-menu__link--active]:side-menu__link--open" data-tw-toggle="collapse" data-tw-target="#side-menu-config-facturacion">
                                                            <i data-lucide="file-text" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Facturación/Ventas</div>
                                                            <i data-lucide="chevron-down" class="stroke-[1] w-3 h-3 side-menu__link__chevron"></i>
                                                        </a>
                                                        <ul id="side-menu-config-facturacion" class="side-menu__ul-collapse {{ $isConfigFacturacionActive ? '' : 'hidden' }}">
                                                            @if($visibleConfiguracion['certificadosunat'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.certificados-sunat.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.certificados-sunat*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Certificados SUNAT</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                            @if($visibleConfiguracion['forma_pago'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.formas-pago.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.formas-pago*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Forma de Pago</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                            @if($visibleConfiguracion['moneda'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.monedas.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.monedas*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Moneda</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                            @if($visibleConfiguracion['tributo'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.tributos.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.tributos*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Tributo</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                            @if($visibleConfiguracion['tipo_documento'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.tipos-documento.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.tipos-documento*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Tipo de Documento</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                             @if($visibleConfiguracion['vigencia_oferta'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.vigencias-oferta.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.vigencias-oferta*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Vigencia de Oferta</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                        </ul>
                                                    </li>
                                                @endif
                                                @if($showConfigPersonal)
                                                    <li>
                                                        <a href="javascript:;" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ $isConfigPersonalActive ? 'side-menu__link--active' : '' }} [&.side-menu__link--active]:side-menu__link--open" data-tw-toggle="collapse" data-tw-target="#side-menu-config-personal">
                                                            <i data-lucide="users" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Personal</div>
                                                            <i data-lucide="chevron-down" class="stroke-[1] w-3 h-3 side-menu__link__chevron"></i>
                                                        </a>
                                                        <ul id="side-menu-config-personal" class="side-menu__ul-collapse {{ $isConfigPersonalActive ? '' : 'hidden' }}">
                                                            @if($visibleConfiguracion['cargo'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.cargos.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.cargos*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Cargo Personal</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                        </ul>
                                                    </li>
                                                @endif
                                                @if($showConfigPlataforma)
                                                    <li>
                                                        <a href="javascript:;" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ $isConfigPlataformaActive ? 'side-menu__link--active' : '' }} [&.side-menu__link--active]:side-menu__link--open" data-tw-toggle="collapse" data-tw-target="#side-menu-config-plataforma">
                                                            <i data-lucide="monitor" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Plataforma</div>
                                                            <i data-lucide="chevron-down" class="stroke-[1] w-3 h-3 side-menu__link__chevron"></i>
                                                        </a>
                                                        <ul id="side-menu-config-plataforma" class="side-menu__ul-collapse {{ $isConfigPlataformaActive ? '' : 'hidden' }}">
                                                            @if($visibleConfiguracion['plataforma'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.plataforma.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.plataforma*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Plataforma</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                            @if($visibleConfiguracion['tipo_plataforma'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.tipos-plataforma.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.tipos-plataforma*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Tipo de Plataforma</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                        </ul>
                                                    </li>
                                                @endif
                                                @if($showConfigTicket)
                                                    <li>
                                                        <a href="javascript:;" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ $isConfigTicketActive ? 'side-menu__link--active' : '' }} [&.side-menu__link--active]:side-menu__link--open" data-tw-toggle="collapse" data-tw-target="#side-menu-config-ticket">
                                                            <i data-lucide="ticket" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Gestion</div>
                                                            <i data-lucide="chevron-down" class="stroke-[1] w-3 h-3 side-menu__link__chevron"></i>
                                                        </a>
                                                        <ul id="side-menu-config-ticket" class="side-menu__ul-collapse {{ $isConfigTicketActive ? '' : 'hidden' }}">
                                                            @if($visibleConfiguracion['tipo_operacion'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.tipos-operacion.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.tipos-operacion*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Tipo de Operación</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                        </ul>
                                                    </li>
                                                @endif
                                                @if($showConfigAlmacen)
                                                    <li>
                                                        <a href="javascript:;" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ $isConfigAlmacenActive ? 'side-menu__link--active' : '' }} [&.side-menu__link--active]:side-menu__link--open" data-tw-toggle="collapse" data-tw-target="#side-menu-config-almacen">
                                                            <i data-lucide="box" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Almacén</div>
                                                            <i data-lucide="chevron-down" class="stroke-[1] w-3 h-3 side-menu__link__chevron"></i>
                                                        </a>
                                                        <ul id="side-menu-config-almacen" class="side-menu__ul-collapse {{ $isConfigAlmacenActive ? '' : 'hidden' }}">
                                                            @if($visibleConfiguracion['detalle_lista_precio'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.detalle-lista-precio.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.detalle-lista-precio*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Detalle Lista de Precio</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                            @if($visibleConfiguracion['empresapropietaria'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.empresapropietaria.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.empresapropietaria*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Empresa Propietaria</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                            @if($visibleConfiguracion['elemento_almacen'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.elemento-almacen.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.elemento-almacen*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Elemento Almacén</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                            @if($visibleConfiguracion['lista_precio'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.listas-precio.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.listas-precio*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Lista de Precio</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                            @if($visibleConfiguracion['marca'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.marcas.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.marcas*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Marca</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                            @if($visibleConfiguracion['modelo'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.modelo.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.modelo*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Modelo</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                            @if($visibleConfiguracion['tecnologia'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.tecnologias.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.tecnologias*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Tecnología</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                            @if($visibleConfiguracion['tipo_elemento'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.tipos-elemento.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.tipos-elemento*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Tipo de Elemento</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                            @if($visibleConfiguracion['tipo_pedido'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.tipos-pedido.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.tipos-pedido*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Tipo de Pedido</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                            
                                                            @if($visibleConfiguracion['unidad_medida'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.unidad-medida.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.unidad-medida*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Unidad de Medida</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                        </ul>
                                                    </li>
                                                @endif
                                                @if($showConfigVehiculos)
                                                    <li>
                                                        <a href="javascript:;" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ $isConfigVehiculosActive ? 'side-menu__link--active' : '' }} [&.side-menu__link--active]:side-menu__link--open" data-tw-toggle="collapse" data-tw-target="#side-menu-config-vehiculos">
                                                            <i data-lucide="truck" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Vehículos y Chips</div>
                                                            <i data-lucide="chevron-down" class="stroke-[1] w-3 h-3 side-menu__link__chevron"></i>
                                                        </a>
                                                        <ul id="side-menu-config-vehiculos" class="side-menu__ul-collapse {{ $isConfigVehiculosActive ? '' : 'hidden' }}">
                                                            @if($visibleConfiguracion['operador'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.operadores.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.operadores*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Operador</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                            @if($visibleConfiguracion['tipo_vehiculo'])
                                                                <li>
                                                                    <a href="{{ route('modules.configuracion.tipos-vehiculo.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.tipos-vehiculo*') ? 'side-menu__link--active' : '' }}">
                                                                        <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                                        <div class="side-menu__link__title">Tipo de Vehículo</div>
                                                                    </a>
                                                                </li>
                                                            @endif
                                                        </ul>
                                                    </li>
                                                @endif
                                                @if($visibleConfiguracion['auditoria'])
                                                    @if($showAnyGroupedConfig)
                                                        <li class="mx-5 mt-3 border-t border-slate-200/60"></li>
                                                    @endif
                                                    <li>
                                                        <a href="{{ route('modules.configuracion.auditoria.index') }}" class="ml-2 border-slate-200/20 pl-2 side-menu__link {{ request()->routeIs('modules.configuracion.auditoria*') ? 'side-menu__link--active' : '' }}">
                                                            <i data-lucide="file-text" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Auditoría</div>
                                                        </a>
                                                    </li>
                                                @endif
                                            </ul>
                                        </li>
                                    @elseif($module === 'sistema')
                                        @php
                                            $isSistemaActive = request()->routeIs('modules.sistema*');
                                        @endphp
                                        <li>
                                            <a href="javascript:;" class="side-menu__link {{ $isSistemaActive ? 'side-menu__link--active' : '' }} [&.side-menu__link--active]:side-menu__link--open" data-tw-toggle="collapse" data-tw-target="#side-menu-sistema">
                                                <i data-lucide="settings-2" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                                <div class="side-menu__link__title">Sistema</div>
                                                <i data-lucide="chevron-down" class="stroke-[1] w-5 h-5 side-menu__link__chevron"></i>
                                            </a>
                                            <ul id="side-menu-sistema" class="side-menu__ul-collapse {{ $isSistemaActive ? '' : 'hidden' }}">
                                                @if($visibleSistema['vista'])
                                                    <li>
                                                        <a href="{{ route('modules.sistema.vistas.index') }}" class="side-menu__link ml-2 {{ request()->routeIs('modules.sistema.vistas*') ? 'side-menu__link--active' : '' }}">
                                                            <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Vista</div>
                                                        </a>
                                                    </li>
                                                @endif
                                                @if($visibleSistema['flujo'])
                                                    <li>
                                                        <a href="{{ route('modules.sistema.flujos.index') }}" class="side-menu__link ml-2 {{ request()->routeIs('modules.sistema.flujos*') ? 'side-menu__link--active' : '' }}">
                                                            <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Flujo</div>
                                                        </a>
                                                    </li>
                                                @endif
                                                @if($visibleSistema['flujoregla'])
                                                    <li>
                                                        <a href="{{ route('modules.sistema.flujo-reglas.index') }}" class="side-menu__link ml-2 {{ request()->routeIs('modules.sistema.flujo-reglas*') ? 'side-menu__link--active' : '' }}">
                                                            <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Flujo Regla</div>
                                                        </a>
                                                    </li>
                                                @endif
                                                @if($visibleSistema['historialflujo'])
                                                    <li>
                                                        <a href="{{ route('modules.sistema.historial-flujos.index') }}" class="side-menu__link ml-2 {{ request()->routeIs('modules.sistema.historial-flujos*') ? 'side-menu__link--active' : '' }}">
                                                            <i data-lucide="chevron-right" class="stroke-[1] w-3 h-3 side-menu__link__icon"></i>
                                                            <div class="side-menu__link__title">Historial Flujo</div>
                                                        </a>
                                                    </li>
                                                @endif
                                            </ul>
                                        </li>
                                    @else
                                        <li>
                                            <a href="{{ route($link['route']) }}" class="side-menu__link {{ request()->routeIs($link['route'] . '*') ? 'side-menu__link--active' : '' }}">
                                                <i data-lucide="{{ $link['icon'] }}" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                                <div class="side-menu__link__title">{{ $link['title'] }}</div>
                                            </a>
                                        </li>
                                    @endif
                                @endforeach
                                <li class="side-menu__divider">SALIR</li>
                                <li>
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button type="submit" class="side-menu__link w-full text-left">
                                            <i data-lucide="log-out" class="stroke-[1] w-5 h-5 side-menu__link__icon"></i>
                                            <div class="side-menu__link__title">Cerrar sesión</div>
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <div id="quick-search" aria-hidden="true" tabindex="-1" class="modal group bg-gradient-to-b from-theme-1/50 via-theme-2/50 to-black/50 transition-[visibility,opacity] w-screen h-screen fixed left-0 top-0 overflow-y-hidden z-[60] [&:not(.show)]:duration-[0s,0.2s] [&:not(.show)]:delay-[0.2s,0s] [&:not(.show)]:invisible [&:not(.show)]:opacity-0 [&.show]:visible [&.show]:opacity-100 [&.show]:duration-[0s,0.1s]">
                <div class="relative mx-auto my-2 w-[95%] scale-95 transition-transform group-[.show]:scale-100 sm:mt-40 sm:w-[600px] lg:w-[700px]">
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 flex w-12 items-center justify-center">
                            <i data-lucide="search" class="stroke-[1] w-5 h-5 -mr-1.5 text-slate-500"></i>
                        </div>
                        <input type="text" placeholder="Búsqueda rápida..." class="w-full rounded-lg border-0 py-3.5 pl-12 pr-14 text-base shadow-lg focus:ring-0">
                        <div class="absolute inset-y-0 right-0 flex w-14 items-center">
                            <div class="mr-auto rounded-[0.4rem] border bg-slate-100 px-2 py-1 text-xs text-slate-500/80">ESC</div>
                        </div>
                    </div>

                    <div class="global-search global-search--show-result group relative z-10 mt-1 max-h-[468px] overflow-y-auto rounded-lg bg-white pb-1 shadow-lg sm:max-h-[615px]">
                        <div class="flex flex-col items-center justify-center pb-28 pt-20 group-[.global-search--show-result]:hidden">
                            <i data-lucide="search-x" class="h-20 w-20 fill-theme-1/5 stroke-[0.5] text-theme-1/20"></i>
                            <div class="mt-5 text-xl font-medium">No se encontraron resultados</div>
                            <div class="mt-3 w-2/3 text-center leading-relaxed text-slate-500">
                                No se encontraron resultados para <span class="global-search__keyword font-medium italic"></span>. Intenta con otro término.
                            </div>
                        </div>

                        <div class="hidden group-[.global-search--show-result]:block">
                            <div class="px-5 py-4">
                                <div class="text-xs uppercase text-slate-500">Enlaces rápidos</div>
                                <div class="mt-3.5 flex flex-wrap gap-2">
                                    <a class="flex items-center gap-x-1.5 rounded-full border border-slate-300/70 px-3 py-0.5 hover:bg-slate-50" href="{{ route('home') }}">
                                        <i data-lucide="gauge-circle" class="h-4 w-4 stroke-[1.3]"></i>
                                        Inicio
                                    </a>
                                    @foreach($visibleModules as $module)
                                        @php $link = $moduleLinks[$module]; @endphp
                                        <a class="flex items-center gap-x-1.5 rounded-full border border-slate-300/70 px-3 py-0.5 hover:bg-slate-50" href="{{ route($link['route']) }}">
                                            <i data-lucide="{{ $link['icon'] }}" class="h-4 w-4 stroke-[1.3]"></i>
                                            {{ $link['title'] }}
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div data-tw-backdrop="" aria-hidden="true" tabindex="-1" id="notifications-panel" class="modal group bg-gradient-to-b from-theme-1/50 via-theme-2/50 to-black/50 transition-[visibility,opacity] w-screen h-screen fixed left-0 top-0 [&:not(.show)]:duration-[0s,0.2s] [&:not(.show)]:delay-[0.2s,0s] [&:not(.show)]:invisible [&:not(.show)]:opacity-0 [&.show]:visible [&.show]:opacity-100 [&.show]:duration-[0s,0.4s]">
                <div class="ml-auto h-screen flex flex-col bg-white relative shadow-md transition-[margin-right] duration-[0.6s] -mr-[100%] group-[.show]:mr-0 sm:w-[460px] w-72 rounded-[0.75rem_0_0_0.75rem/1.1rem_0_0_1.1rem]">
                    <a class="absolute inset-y-0 left-0 right-auto my-auto -ml-[60px] flex h-8 w-8 items-center justify-center rounded-full border border-white/90 bg-white/5 text-white/90 transition-all hover:rotate-180 hover:scale-105 hover:bg-white/10 focus:outline-none sm:-ml-[105px] sm:h-14 sm:w-14" data-tw-dismiss="modal" href="javascript:;">
                        <i data-lucide="x" class="stroke-[1] h-8 w-8"></i>
                    </a>
                    <div class="flex items-center border-b border-slate-200/60 px-6 py-5">
                        <h2 class="mr-auto text-base font-medium">Notificaciones</h2>
                        <button class="transition duration-200 border shadow-sm items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed border-secondary text-slate-500 [&:hover:not(:disabled)]:bg-secondary/20 hidden sm:flex">
                            <i data-lucide="shield-check" class="stroke-[1] mr-2 h-4 w-4"></i>
                            Marcar todo como leído
                        </button>
                    </div>
                    <div class="overflow-y-auto flex-1 p-0">
                        <div class="flex flex-col gap-0.5 p-3">
                            <a class="flex items-center rounded-xl px-3 py-2.5 hover:bg-slate-100/80" href="#">
                                <div>
                                    <div class="image-fit h-11 w-11 overflow-hidden rounded-full border-2 border-slate-200/70">
                                    </div>
                                </div>
                                <div class="sm:ml-5">
                                    <div class="font-medium">Publicó una actualización de estado</div>
                                    <div class="mt-0.5 text-slate-500">Compartió novedades del proyecto</div>
                                    <div class="mt-1.5 text-xs text-slate-500">Dom mar 2021</div>
                                </div>
                            </a>

                            <a class="flex items-center rounded-xl px-3 py-2.5 hover:bg-slate-100/80" href="#">
                                <div>
                                    <div class="image-fit h-11 w-11 overflow-hidden rounded-full border-2 border-slate-200/70">
                                    </div>
                                </div>
                                <div class="sm:ml-5">
                                    <div class="font-medium">Tarea completada: revisar propuesta del proyecto</div>
                                    <div class="mt-0.5 text-slate-500">Revisó y dejó comentarios</div>
                                    <div class="my-3.5 w-40 rounded-[0.6rem] border bg-slate-50/80 p-1 sm:w-56">
                                        <div class="grid grid-cols-3 overflow-hidden rounded-[0.6rem]">
                                            <div class="image-fit h-12 cursor-pointer overflow-hidden border border-slate-100 saturate-[.6] hover:saturate-100 sm:h-16">
                                            </div>
                                            <div class="image-fit h-12 cursor-pointer overflow-hidden border border-slate-100 saturate-[.6] hover:saturate-100 sm:h-16">
                                            </div>
                                            <div class="image-fit h-12 cursor-pointer overflow-hidden border border-slate-100 saturate-[.6] hover:saturate-100 sm:h-16">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mt-1.5 text-xs text-slate-500">Sáb oct 2022</div>
                                </div>
                            </a>

                            <a class="flex items-center rounded-xl px-3 py-2.5 hover:bg-slate-100/80" href="#">
                                <div>
                                    <div class="image-fit h-11 w-11 overflow-hidden rounded-full border-2 border-slate-200/70">
                                    </div>
                                </div>
                                <div class="sm:ml-5">
                                    <div class="font-medium">Inicio de sesión exitoso</div>
                                    <div class="mt-0.5 text-slate-500">Accedió al panel</div>
                                    <div class="my-3.5 w-40 rounded-[0.6rem] border bg-slate-50/80 p-1 sm:w-56">
                                        <div class="grid grid-cols-3 overflow-hidden rounded-[0.6rem]">
                                            <div class="image-fit h-12 cursor-pointer overflow-hidden border border-slate-100 saturate-[.6] hover:saturate-100 sm:h-16">
                                            </div>
                                            <div class="image-fit h-12 cursor-pointer overflow-hidden border border-slate-100 saturate-[.6] hover:saturate-100 sm:h-16">
                                            </div>
                                            <div class="image-fit h-12 cursor-pointer overflow-hidden border border-slate-100 saturate-[.6] hover:saturate-100 sm:h-16">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mt-1.5 text-xs text-slate-500">Lun jun 2021</div>
                                </div>
                            </a>

                            <a class="flex items-center rounded-xl px-3 py-2.5 hover:bg-slate-100/80" href="#">
                                <div>
                                    <div class="image-fit h-11 w-11 overflow-hidden rounded-full border-2 border-slate-200/70">
                                    </div>
                                </div>
                                <div class="sm:ml-5">
                                    <div class="font-medium">Cerró sesión</div>
                                    <div class="mt-0.5 text-slate-500">Salió del panel</div>
                                    <div class="my-3.5 w-40 rounded-[0.6rem] border bg-slate-50/80 p-1 sm:w-56">
                                        <div class="grid grid-cols-3 overflow-hidden rounded-[0.6rem]">
                                            <div class="image-fit h-12 cursor-pointer overflow-hidden border border-slate-100 saturate-[.6] hover:saturate-100 sm:h-16">
                                            </div>
                                            <div class="image-fit h-12 cursor-pointer overflow-hidden border border-slate-100 saturate-[.6] hover:saturate-100 sm:h-16">
                                            </div>
                                            <div class="image-fit h-12 cursor-pointer overflow-hidden border border-slate-100 saturate-[.6] hover:saturate-100 sm:h-16">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mt-1.5 text-xs text-slate-500">Mar feb 2022</div>
                                </div>
                            </a>

                            <a class="flex items-center rounded-xl px-3 py-2.5 hover:bg-slate-100/80" href="#">
                                <div>
                                    <div class="image-fit h-11 w-11 overflow-hidden rounded-full border-2 border-slate-200/70">
                                    </div>
                                </div>
                                <div class="sm:ml-5">
                                    <div class="font-medium">Subió grabaciones de audio</div>
                                    <div class="mt-0.5 text-slate-500">Grabó episodios del podcast</div>
                                    <div class="mt-1.5 text-xs text-slate-500">Mar nov 2022</div>
                                </div>
                                <div class="ml-auto h-2 w-2 flex-none rounded-full border border-primary/40 bg-primary/40"></div>
                            </a>

                            <a class="flex items-center rounded-xl px-3 py-2.5 hover:bg-slate-100/80" href="#">
                                <div>
                                    <div class="image-fit h-11 w-11 overflow-hidden rounded-full border-2 border-slate-200/70">
                                    </div>
                                </div>
                                <div class="sm:ml-5">
                                    <div class="font-medium">Subió imágenes</div>
                                    <div class="mt-0.5 text-slate-500">Agregó capturas del proyecto</div>
                                    <div class="my-3.5 w-40 rounded-[0.6rem] border bg-slate-50/80 p-1 sm:w-56">
                                        <div class="grid grid-cols-3 overflow-hidden rounded-[0.6rem]">
                                            <div class="image-fit h-12 cursor-pointer overflow-hidden border border-slate-100 saturate-[.6] hover:saturate-100 sm:h-16">
                                            </div>
                                            <div class="image-fit h-12 cursor-pointer overflow-hidden border border-slate-100 saturate-[.6] hover:saturate-100 sm:h-16">
                                            </div>
                                            <div class="image-fit h-12 cursor-pointer overflow-hidden border border-slate-100 saturate-[.6] hover:saturate-100 sm:h-16">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mt-1.5 text-xs text-slate-500">Jue dic 2020</div>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content transition-[margin,width] duration-100 px-5 mt-[65px] pt-[31px] pb-16 relative z-10 content--compact xl:ml-[275px] [&.content--compact]:xl:ml-[91px]">
                <div class="container">
                    @yield('content')
                </div>
            </div>
        </div>
    </div>
    @stack('modals')
    @stack('scripts')
</body>
</html>
