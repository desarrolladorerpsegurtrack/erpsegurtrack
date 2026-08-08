@extends('dashboard.overview-1')

@section('title', $title ?? 'Formulario')
@section('header', $moduleTitle ?? 'Formulario')
@section('breadcrumb')
    <nav aria-label="breadcrumb" class="flex hidden flex-1 xl:block">
        <ol class="flex items-center text-theme-1">
            <li><a href="#">Inicio</a></li>
            <li class="relative ml-5 pl-0.5 before:content-[''] before:w-[14px] before:h-[14px] before:bg-chevron-black before:transform before:rotate-[-90deg] before:bg-[length:100%] before:-ml-[1.125rem] before:absolute before:my-auto before:inset-y-0 text-slate-600 cursor-text">
                <span>{{ $moduleTitle ?? 'Módulo Personal' }}</span>
            </li>
            <li class="relative ml-5 pl-0.5 before:content-[''] before:w-[14px] before:h-[14px] before:bg-chevron-black before:transform before:rotate-[-90deg] before:bg-[length:100%] before:-ml-[1.125rem] before:absolute before:my-auto before:inset-y-0 text-slate-600 cursor-text">
                <span>{{ isset($mode) && $mode === 'edit' ? 'Editar' : (isset($mode) && $mode === 'create' ? 'Crear' : ($title ?? 'Acción')) }}</span>
            </li>
        </ol>
    </nav>@endsection

@php
    $quickDireccionField = collect($fields ?? [])->first(fn ($field) => ($field['quickCreate'] ?? false) === true);
    $quickContactoField = collect($fields ?? [])->first(fn ($field) => ($field['quickCreateContact'] ?? false) === true);
    $quickCredencialField = collect($fields ?? [])->first(fn ($field) => ($field['quickCreateCredential'] ?? false) === true);
    $quickEstadoField = collect($fields ?? [])->first(fn ($field) => ($field['quickCreateEstado'] ?? false) === true);
    $quickCargoField = collect($fields ?? [])->first(fn ($field) => ($field['quickCreateCargo'] ?? false) === true);
    $quickDispositivoField = collect($fields ?? [])->first(fn ($field) => ($field['quickCreateDispositivo'] ?? false) === true);
    $quickDetalleListaPrecioField = collect($fields ?? [])->first(fn ($field) => ($field['quickCreateDetalleListaPrecio'] ?? false) === true);
    $quickAddressModalField = collect($fields ?? [])->first(fn ($field) => ($field['quickAddressModal'] ?? false) === true);
    $hasQuickDireccion = !empty($quickDireccionField) || !empty($quickAddressModalField);
    $hasQuickContacto = !empty($quickContactoField);
    $hasQuickCredencial = !empty($quickCredencialField);
    $hasQuickEstado = !empty($quickEstadoField);
    $hasQuickCargo = !empty($quickCargoField);
    $hasQuickDispositivo = !empty($quickDispositivoField);
    $hasQuickDetalleListaPrecio = !empty($quickDetalleListaPrecioField);

    $authData = session('erp_auth', []);
    $userRoles = collect($authData['roles'] ?? [])
        ->map(fn ($role) => mb_strtolower(trim((string) $role)))
        ->filter();
    $isAdmin = $userRoles->contains('admin');
    $credentialPermissions = collect($authData['permissions']['clientes.credenciales'] ?? [])
        ->map(fn ($value) => App\Support\ErpPermission::normalizeAction((string) $value))
        ->filter()
        ->unique();
    $canQuickCredential = $isAdmin || (
        $credentialPermissions->contains('ver')
        && ($credentialPermissions->contains('crear') || $credentialPermissions->contains('editar'))
    );
    $canQuickCredentialEdit = $isAdmin || $credentialPermissions->contains('editar');
    $canQuickCredentialDelete = $isAdmin || $credentialPermissions->contains('eliminar');

    $dispositivoPermissions = collect($authData['permissions']['dispositivo_cliente'] ?? [])
        ->map(fn ($value) => App\Support\ErpPermission::normalizeAction((string) $value))
        ->filter()
        ->unique();
    $canSeeDispositivoField = $isAdmin || $dispositivoPermissions->contains('ver');
    $canQuickDispositivo = $isAdmin || (
        $dispositivoPermissions->contains('ver')
        && ($dispositivoPermissions->contains('crear') || $dispositivoPermissions->contains('editar'))
    );
    $canQuickDispositivoEdit = $isAdmin || $dispositivoPermissions->contains('editar');
    $canQuickDispositivoDelete = $isAdmin || $dispositivoPermissions->contains('eliminar');
@endphp

@section('content')
    @php
        $errors = $errors ?? session('errors') ?? new \Illuminate\Support\ViewErrorBag();
    @endphp
    <style>
        .custom-checkbox-wrapper {
            position: relative;
            display: inline-flex;
            align-items: flex-start;
            gap: 0.625rem;
            padding: 0.50rem 0.9rem;
            border: 1px solid #cbd5e1;
            border-radius: 0.35rem;
            background-color: #ffffff;
            cursor: pointer;
            transition: background-color 0.2s ease, border-color 0.2s ease;
            white-space: normal;
        }

        .role-cards-grid .checkbox-object-item {
            height: auto;
            min-height: 2.75rem;
            padding: 0.55rem 0.7rem;
        }

        .role-cards-grid .checkbox-object-title {
            font-size: 0.88rem;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        .checkbox-object-detail {
            display: block;
            font-size: 0.8rem;
            color: #475569;
            line-height: 1.4;
            white-space: normal;
            word-break: break-word;
        }

        .vista-selector-shell {
            display: grid;
            gap: 1rem;
            grid-template-columns: minmax(0, 0.68fr) minmax(390px, 1.32fr);
            align-items: start;
        }

        .vista-selector-panel,
        .vista-selected-panel {
            border: 1px solid #dbe2ea;
            border-radius: 0.50rem;
            background-color: #ffffff;
            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }

        /* Quick actions: mantenemos flex en escritorio, grid en mobile */
        .quick-actions-grid {
            display: flex;
            gap: 0.375rem;
            align-items: center;
        }

        /* Ajustes locales para TomSelect (cotizaciones): alinear verticalmente y usar altura compacta */
        .tom-select.tom-select--compact.ts-wrapper,
        .tom-select.tom-select--compact.ts-wrapper .ts-control {
            min-height: 2.2rem !important;
            height: 2.2rem !important;
            padding: 0.23rem 0.75rem 0.1rem 0.35rem !important;
            align-items: flex-start !important;
            line-height: 1.2rem !important;
        }
        .tom-select.tom-select--compact.ts-wrapper .ts-control .items,
        .tom-select.tom-select--compact.ts-wrapper .ts-control .item {
            min-height: 2rem !important;
            height: auto !important;
            margin: 0 !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            max-width: 100% !important;
            display: block !important;
        }

        #modal-inputs-container > .product-col {
            min-width: 21rem;
            max-width: 36rem;
            flex: 1 1 0%;
        }

        #modal-inputs-container > .product-col .form-control,
        #modal-inputs-container > .product-col .ts-wrapper {
            width: 100%;
            min-width: 0;
            max-width: 100%;
        }

        #modal-inputs-container > .product-col .ts-control .items,
        #modal-inputs-container > .product-col .ts-control .item {
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            white-space: nowrap !important;
            max-width: 100% !important;
        }

        #modal-agregar-item .modal-table-wrapper > table {
            width: 100%;
            min-width: 760px;
            table-layout: auto;
        }

        #modal-agregar-item .modal-table-wrapper > table th,
        #modal-agregar-item .modal-table-wrapper > table td {
            padding: 0.75rem 0.85rem;
        }

        #modal-agregar-item .modal-table-wrapper > table th:first-child,
        #modal-agregar-item .modal-table-wrapper > table td:first-child {
            width: 380px;
            max-width: 400px;
            min-width: 360px;
            white-space: normal;
            word-break: break-word;
        }

        #modal-agregar-item .modal-table-wrapper > table td {
            white-space: nowrap;
        }

        #modal-agregar-item .modal-table-wrapper > table td:first-child {
            white-space: normal;
        }

        @media (max-width: 640px) {
            #cotizaciones-container .overflow-x-auto {
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch;
            }

            #cotizaciones-container .overflow-x-auto > table {
                width: max-content !important;
                min-width: 720px !important;
                table-layout: auto !important;
            }

            #cotizaciones-container .overflow-x-auto > table th,
            #cotizaciones-container .overflow-x-auto > table td {
                white-space: nowrap !important;
            }

            #cotizaciones-container .overflow-x-auto > table th:first-child,
            #cotizaciones-container .overflow-x-auto > table td:first-child {
                white-space: normal !important;
                max-width: 220px !important;
                min-width: 180px !important;
            }

            #cotizaciones-container .overflow-x-auto > table td .form-control {
                min-width: 0 !important;
            }

            .quick-actions-grid {
                display: grid;
                grid-template-columns: auto auto;
                gap: 0.5rem;
                align-items: center;
            }
            .quick-actions-grid .quick-pick-btn {
                grid-column: 1 / -1;
                justify-self: center;
                width: auto;
            }
        }
            .quick-actions-grid {
                display: grid;
                grid-template-columns: auto auto;
                gap: 0.5rem;
                align-items: center;
            }
            .quick-actions-grid .quick-pick-btn {
                grid-column: 1 / -1;
                justify-self: center;
                width: auto;
            }
        }

        @media (max-width: 420px) {
            .quick-actions-grid {
                grid-template-columns: 1fr;
            }
        }

        .quick-item-text {
            flex: 1 1 0%;
            min-width: 0;
        }

        .quick-modal-header { flex-wrap: wrap; }
        @media (max-width: 640px) {
            .quick-modal-header { flex-wrap: nowrap; gap: 0.5rem; align-items: center; }
        }
        .vista-selector-search {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 0.8rem;
            background-color: #ffffff;
            color: #0f172a;
            padding: 0.72rem 0.9rem;
            font-size: 0.9rem;
            margin-bottom: 0.85rem;
        }
        .vista-selector-list {
            display: grid;
            gap: 0.55rem;
            max-height: 19rem;
            overflow: auto;
            padding-right: 0.15rem;
        }
        .vista-selector-option {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 0.50rem;
            background-color: #ffffff;
            padding: 0.78rem 0.85rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease, background-color 0.2s ease;
        }
        .vista-selector-option:hover {
            border-color: #cbd5e1;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
            transform: translateY(-1px);
            background-color: #fcfdff;
        }
        .vista-selector-option.is-selected {
            border-color: #e2e8f0;
            background-color: #ffffff;
        }
        .vista-selector-option.is-hidden {
            display: none;
        }
        .vista-selector-option-main {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
            flex: 1 1 auto;
        }
        .vista-selector-option-title {
            font-size: 0.92rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.25;
        }
        .vista-selector-option-detail {
            font-size: 0.8rem;
            color: #64748b;
            line-height: 1.35;
        }
        .vista-selector-option-meta {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            padding: 0.14rem 0.45rem;
            background-color: #eef2f7;
            color: #334155;
            font-size: 0.72rem;
            font-weight: 700;
            border-radius: 0.50rem;
            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        .vista-selector-option-input-wrap {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.2rem;
            height: 1.2rem;
            border: 1px solid #cbd5e1;
            border-radius: 9999px;
            background-color: #ffffff;
            flex-shrink: 0;
            margin-top: 0.15rem;
        }
        .vista-selector-option-input-wrap input {
            position: absolute;
            inset: 0;
            margin: 0;
            opacity: 0;
            cursor: pointer;
        }
        .vista-selector-option-input-box {
            width: 0.95rem;
            height: 0.95rem;
            border-radius: 9999px;
            border: 1px solid transparent;
            background-color: #ffffff;
            position: relative;
        }
        .vista-selector-option-input-box::after {
            content: '';
            position: absolute;
            inset: 0;
            margin: auto;
            width: 0.42rem;
            height: 0.42rem;
            border-radius: 9999px;
            background-color: transparent;
            transform: scale(0);
            transition: transform 0.15s ease, background-color 0.15s ease;
        }
        .vista-selector-option-input-wrap input:checked + .vista-selector-option-input-box {
            background-color: #dc2626;
            border-color: #dc2626;
        }
        .vista-selector-option-input-wrap input:checked + .vista-selector-option-input-box::after {
            background-color: #ffffff;
            transform: scale(1);
        }
        .vista-selector-option-input-wrap input:focus-visible + .vista-selector-option-input-box {
            box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.15);
        }
        .vista-selector-option-check {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            width: 100%;
            cursor: pointer;
        }
        .vista-selector-option-check input:checked ~ .vista-selector-option-main {
            color: inherit;
        }
        .vista-selector-panel {
            padding: 0.95rem;
        }
        .vista-selector-title,
        .vista-selected-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            font-size: 0.88rem;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 0.7rem;
        }
        .vista-selector-help {
            font-size: 0.78rem;
            color: #64748b;
            font-weight: 500;
        }
        .vista-selector-select {
            width: 100%;
            min-height: 16rem;
            border-radius: 0.75rem;
            border: 1px solid #d1d5db;
            background-color: #ffffff;
            color: #0f172a;
            padding: 0.45rem;
        }
        .vista-selected-panel {
            padding: 0.95rem;
        }
        .vista-selected-list {
            display: grid;
            gap: 0.4rem;
            max-height: 30rem;
            overflow: auto;
            padding-right: 0.15rem;
        }
        .vista-selected-head,
        .vista-selected-row {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(0, 1.2fr) minmax(110px, 0.55fr) auto;
            gap: 0.65rem;
            align-items: center;
        }
        .vista-selected-head {
            padding: 0 0.35rem 0.2rem;
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .vista-selected-row {
            border: 1px solid #e2e8f0;
            border-radius: 0.50rem;
            background-color: #ffffff;
            padding: 0.7rem 0.8rem;
        }
        .vista-selected-row-name {
            min-width: 0;
            font-size: 0.9rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.25;
        }
        .vista-selected-row-detail,
        .vista-selected-row-state {
            min-width: 0;
            font-size: 0.8rem;
            line-height: 1.35;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .vista-selected-row-state {
            display: inline-flex;
            width: fit-content;
            padding: 0.14rem 0.48rem;
            border-radius: 0.50rem;
            background-color: #eef2f7;
            color: #334155;
            font-weight: 700;
        }
        .vista-selected-empty {
            border: 1px dashed #cbd5e1;
            border-radius: 0.50rem;
            background-color: #f8fafc;
            color: #64748b;
            font-size: 0.84rem;
            padding: 0.8rem 0.9rem;
        }
        @media (max-width: 1024px) {
            .vista-selected-row {
                grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr) minmax(96px, 0.55fr) auto;
            }
        }
        @media (max-width: 720px) {
            .vista-selector-shell {
                grid-template-columns: 1fr;
            }
            .vista-selector-panel {
                order: 2;
            }
            .vista-selected-panel {
                order: 1;
            }
            .vista-selected-head {
                display: none;
            }
            .vista-selected-list {
                max-height: 12rem;
            }
            .vista-selector-list {
                max-height: 14rem;
            }
        }
        @media (max-width: 480px) {
            .vista-selected-row {
                grid-template-columns: minmax(0, 1fr) auto;
                gap: 0.4rem;
            }
            .vista-selected-row-detail,
            .vista-selected-row-state {
                display: none;
            }
            .vista-selector-option {
                padding: 0.6rem 0.7rem;
            }
            .vista-selector-search {
                font-size: 0.84rem;
                padding: 0.6rem 0.75rem;
            }
        }

        /* Forzar orden en móvil: primero buscar/seleccionar, luego seleccionadas */
        @media (max-width: 640px) {
            .vista-selector-shell {
                display: flex;
                flex-direction: column;
                gap: 0.75rem;
            }
            .vista-selector-panel {
                order: 1;
            }
            .vista-selected-panel {
                order: 2;
            }
        }
        input.custom-checkbox {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            margin: 0;
            opacity: 0;
            cursor: pointer;
            z-index: 2;
        }
        .custom-checkbox-box {
            width: 1.05rem;
            height: 1.05rem;
            border: 1px solid #cbd5e1;
            border-radius: 0.5rem;
            background-color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-sizing: border-box;
            position: relative;
            transition: border-color 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease;
        }
        .custom-checkbox-wrapper .text-sm {
            display: block;
            white-space: normal !important;
            overflow-wrap: anywhere;
            word-break: break-word;
            max-width: calc(100% - 2.2rem);
        }
        .custom-checkbox-box::after {
            content: '';
            position: absolute;
            inset: 0;
            margin: auto;
            width: 0.55rem;
            height: 0.55rem;
            border-radius: 0.5rem;
            background-color: transparent;
            transform: scale(0);
            transition: transform 0.15s ease, background-color 0.15s ease;
        }
        input.custom-checkbox:checked + .custom-checkbox-box {
            border-color: #dc2626;
            background-color: #dc2626;
        }
        input.custom-checkbox:checked + .custom-checkbox-box::after {
            background-color: #ffffff;
            transform: scale(1);
        }
        input.custom-checkbox:focus-visible + .custom-checkbox-box {
            box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.15);
        }
        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="number"],
        input[type="date"],
        input[type="tel"],
        input[type="search"],
        input[type="url"],
        textarea,
        select {
            width: 100%;
            border-radius: 0.35rem;
            border: 1px solid #d1d5db;
            background-color: #ffffff;
            color: #0f172a;
            box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.06);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
        }
        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="password"]:focus,
        input[type="number"]:focus,
        input[type="date"]:focus,
        input[type="tel"]:focus,
        input[type="search"]:focus,
        input[type="url"]:focus,
        textarea:focus,
        select:focus {
            outline: none;
            border-color: #dc2626;
            box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.12);
            transform: translateY(-0.5px);
        }
        input::placeholder,
        textarea::placeholder {
            color: #94a3b8;
            opacity: 1;
        }
        select {
            appearance: none;
            background-color: #ffffff;
            background-image: none;
            padding-right: 0.9rem;
        }
        .litepicker .container__months .month-item-header div {
            display: inline-flex;
            align-items: center;
        }
        .litepicker .container__months .month-item-header div > .month-item-name,
        .litepicker .container__months .month-item-header div > .month-item-year {
            width: auto;
            min-width: 6.5rem;
            flex: 0 0 auto;
        }
        .permission-switch-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            background-color: #ffffff;
            padding: 0.5rem 0.75rem;
            cursor: pointer;
        }
        .permission-switch-row input {
            cursor: pointer;
        }
        .permission-switch {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 46px;
            height: 26px;
            flex-shrink: 0;
            cursor: pointer;
        }
        .permission-switch input {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            margin: 0;
            padding: 0;
            opacity: 0;
            cursor: pointer;
        }
        .permission-switch-track {
            position: absolute;
            inset: 0;
            border-radius: 9999px;
            background-color: #e5e7eb;
            transition: background-color 0.2s ease, transform 0.2s ease;
        }
        .permission-switch-track::before {
            content: '';
            position: absolute;
            top: 3px;
            left: 3px;
            width: 20px;
            height: 20px;
            border-radius: 9999px;
            background-color: #ffffff;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.12);
            transition: left 0.2s ease, transform 0.2s ease;
        }
        .permission-switch input:checked + .permission-switch-track {
            background-color: #dc2626;
        }
        .permission-switch input:checked + .permission-switch-track::before {
            left: calc(100% - 23px);
        }
        .permission-switch input:focus-visible + .permission-switch-track {
            box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.15);
        }
        .permission-module-card {
            background-color: #f1f5f9;
            border-color: #cbd5e1;
        }
        .module-select-all-label {
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 700;
            color: #0f172a;
        }
        .module-select-all-wrapper {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.55rem;
            height: 1.55rem;
            border: 1px solid #cbd5e1;
            border-radius: 9999px;
            background-color: #ffffff;
            transition: border-color 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease;
        }
        .module-select-all {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            margin: 0;
            padding: 0;
            opacity: 0;
            cursor: pointer;
            z-index: 2;
        }
        .module-select-all-box {
            width: 1.3rem;
            height: 1.3rem;
            border-radius: 9999px;
            border: 1px solid transparent;
            background-color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.2s ease, border-color 0.2s ease;
            z-index: 1;
        }
        .permissions-card-table-wrapper {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            overflow-x: auto;
            overflow-y: hidden;
            -webkit-overflow-scrolling: touch;
        }
        .permissions-card-table {
            width: 100%;
            min-width: 500px;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 0.88rem;
        }
        .permissions-card-table th,
        .permissions-card-table td {
            border: 1px solid #e2e8f0;
            padding: 0.48rem 0.12rem;
            text-align: center;
            background-color: #ffffff;
        }
        .permissions-card-table thead th {
            background-color: #f1f5f9;
            color: #475569;
            font-size: 0.78rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            padding: 0.6rem 0.5rem; /* mayor espacio horizontal en headers */
            white-space: nowrap;
        }
        /* Ajuste específico: encabezados de la tabla de detalles (cotización) más pequeños y compactos */
        #detalle-table thead th {
            font-size: 13px !important;
            padding: 0.35rem 0.45rem !important;
            white-space: nowrap;
        }
        /* Encabezados de acciones (no primera columna) con ancho mínimo para respirar */
        .permissions-card-table thead th:not(:first-child) {
            min-width: 100px;
            padding-left: 0.6rem;
            padding-right: 0.6rem;
        }
        /* Checkbox circular para acciones dentro de la tabla de permisos */
        .permission-action-checkbox-wrapper {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.55rem;
            height: 1.55rem;
            border: 1px solid #cbd5e1;
            border-radius: 9999px;
            background-color: #ffffff;
            transition: border-color 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease;
        }
        .permission-action-checkbox {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            margin: 0;
            padding: 0;
            opacity: 0;
            cursor: pointer;
            z-index: 2;
        }
        .permission-action-checkbox-box {
            width: 1.25rem;
            height: 1.25rem;
            border-radius: 9999px;
            border: 1px solid transparent;
            background-color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.2s ease, border-color 0.2s ease, transform 0.15s ease;
            z-index: 1;
        }
        .permission-action-checkbox-box::after {
            content: '';
            width: 0.42rem;
            height: 0.42rem;
            border-radius: 9999px;
            background-color: transparent;
            transform: scale(0);
            transition: transform 0.15s ease, background-color 0.15s ease;
        }
        .permission-action-checkbox:checked + .permission-action-checkbox-box {
            background-color: #dc2626;
            border-color: #dc2626;
        }
        .permission-action-checkbox:checked + .permission-action-checkbox-box::after {
            background-color: #ffffff;
            transform: scale(1);
        }
        .permission-action-checkbox:focus-visible + .permission-action-checkbox-box {
            box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.15);
        }
        .module-select-all:checked + .module-select-all-box {
            background-color: #dc2626;
            border-color: #dc2626;
        }
        .permissions-field-error {
            border-color: #dc2626 !important;
            background-color: rgba(254, 226, 226, 0.6) !important;
        }
        .module-select-all:checked + .module-select-all-box::after {
            content: '';
            width: 0.45rem;
            height: 0.8rem;
            border-right: 2px solid #ffffff;
            border-bottom: 2px solid #ffffff;
            transform: rotate(45deg);
        }
        .module-select-all:focus-visible + .module-select-all-box {
            box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.15);
        }
        .permissions-table-wrapper {
            width: 100%;
            min-width: 0;
            max-width: 100%;
        }
        fieldset[data-permissions-fieldset] {
            min-width: 0;
            max-width: 100%;
            overflow: hidden;
        }
        .permissions-module-tabs-shell {
            border: 1px solid #dbe2ea;
            border-radius: 0.8rem;
            background-color: #ffffff;
            padding: 0.24rem;
            margin-bottom: 0.85rem;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05);
            overflow-x: auto;
        }
        .permissions-module-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
            width: 100%;
            max-width: 100%;
            justify-content: flex-start;
        }
        .permissions-module-tab {
            flex: 0 1 11rem;
            min-width: 6rem;
            border: 1px solid transparent;
            background-color: #f8fafc;
            color: #475569;
            font-weight: 600;
            font-size: 0.82rem;
            line-height: 1.2;
            padding: 0.52rem 0.9rem;
            border-radius: 0.6rem;
            transition: color 0.2s ease, background-color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
            white-space: normal;
            text-align: center;
        }
        .permissions-module-tab:hover {
            color: #1e293b;
            background-color: #eef2f7;
        }
        .permissions-module-tab.is-active {
            background-color: #ffffff;
            color: #dc2626;
            border-color: #f0c5c5;
            box-shadow: 0 6px 16px rgba(220, 38, 38, 0.12);
        }
        .permissions-card.permissions-module-panel {
            display: none;
            min-width: 0;
            width: 100%;
            max-width: 100%;
        }
        .permissions-card.permissions-module-panel.is-active {
            display: block;
        }
        .permissions-module-panels {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            overflow: hidden;
        }
        .permissions-module-content-shell {
            border: 1px solid #dbe1ea;
            border-radius: 0.85rem;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            padding: 0.75rem;
        }
        .permissions-module-content-caption {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.78rem;
            font-weight: 700;
            color: #334155;
            background-color: #eef2f7;
            border: 1px solid #dbe1ea;
            border-radius: 9999px;
            padding: 0.18rem 0.55rem;
            margin-bottom: 0.5rem;
        }
        .permissions-config-groups {
            display: grid;
            gap: 0.75rem;
        }
        .permissions-config-group {
            border: 1px solid #e2e8f0;
            border-radius: 0.85rem;
            padding: 0.5rem;
            background-color: #ffffff;
        }
        .permissions-config-group-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            margin-bottom: 0.45rem;
        }
        .permissions-config-group-controls {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .permissions-config-group-body {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .permissions-config-group-toggle {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            border: 1px solid #cbd5e1;
            background-color: #ffffff;
            color: #334155;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s ease, border-color 0.2s ease;
        }
        .permissions-config-group-toggle:hover {
            background-color: #f8fafc;
            border-color: #94a3b8;
        }
        .permissions-config-group-toggle-icon {
            font-size: 0.75rem;
            line-height: 0;
        }
        .permissions-config-group.collapsed .permissions-config-group-body {
            display: none;
        }
        .permissions-config-group-title {
            font-size: 0.85rem;
            font-weight: 700;
            color: #0f172a;
        }
        .permissions-modules-columns {
            display: flex;
            flex-wrap: wrap;
            gap: 0.85rem;
            align-items: flex-start;
        }
        .permissions-column {
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
            flex: 1 1 calc(33.333% - 0.85rem);
            min-width: 280px;
        }
        .permissions-card {
            background-color: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 1rem;
            padding: 0.85rem;
            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.08);
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            width: 100%;
            min-width: 0;
            min-height: auto;
            margin: 0;
            overflow: hidden;
        }
        @media (max-width: 1280px) {
            .permissions-column {
                flex: 1 1 calc(50% - 0.85rem);
            }
        }
        @media (max-width: 768px) {
            .permissions-column {
                flex: 1 1 100%;
            }
            fieldset[data-permissions-fieldset] {
                padding: 0.75rem;
                border-radius: 0.95rem;
            }
            .permissions-module-tabs-shell {
                padding: 0.2rem;
                margin-bottom: 0.55rem;
            }
            .permissions-module-tabs {
                display: grid;
                grid-template-columns: 1fr;
                gap: 0.3rem;
            }
            .permissions-module-tab {
                flex: none;
                min-width: 0;
                width: 100%;
                font-size: 0.74rem;
                padding: 0.5rem 0.55rem;
            }
            .permissions-card {
                padding: 0.5rem;
            }
            .permissions-card-table {
                min-width: 430px;
                font-size: 0.78rem;
            }
            .permissions-card-table thead th {
                font-size: 0.68rem;
            }
            .permissions-card-table th:first-child,
            .permissions-card-table td:first-child {
                min-width: 106px;
            }
            .permissions-action-cell {
                width: 36px;
            }
        }
        .permissions-card-group {
            background-color: #f8fafc;
            border-color: #e2e8f0;
            padding: 0.65rem;
            margin-top: 0;
            box-shadow: none;
        }
        .permissions-card-title {
            white-space: normal;
            overflow: visible;
            text-overflow: unset;
            max-width: 100%;
        }
        .permissions-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.5rem;
            margin-bottom: 0.65rem;
        }
        .permissions-card-group .permissions-card-title {
            font-size: 0.92rem;
            font-weight: 700;
            color: #0f172a;
        }
        .permissions-card-group .permissions-card-subtitle {
            font-size: 0.78rem;
            color: #64748b;
        }
        .permissions-card-group .permissions-card-table th,
        .permissions-card-group .permissions-card-table td {
            padding: 0.44rem 0.12rem;
        }
        .permissions-card-group .permissions-card-table thead th {
            font-size: 0.75rem;
        }
        .permissions-card:not(.collapsed) {
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.12);
        }
        .permissions-card.collapsed .permissions-card-table-wrapper {
            display: none;
        }
        .permissions-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            margin-bottom: 0.65rem;
        }
        .permissions-card-controls {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .permissions-card-toggle {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            border: 1px solid #cbd5e1;
            background-color: #ffffff;
            color: #334155;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s ease, border-color 0.2s ease;
        }
        .permissions-card-toggle:hover {
            background-color: #f8fafc;
            border-color: #94a3b8;
        }
        .permissions-card-toggle-icon {
            font-size: 0.75rem;
            line-height: 0;
        }
        .permissions-card-title {
            font-size: 0.92rem;
            font-weight: 700;
            color: #0f172a;
        }
        .permissions-card-subtitle {
            font-size: 0.78rem;
            color: #475569;
        }
        .permissions-card-table-wrapper {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            overflow-x: auto;
            overflow-y: hidden;
            -webkit-overflow-scrolling: touch;
        }
        .permissions-card-table {
            width: 100%;
            min-width: 500px;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 0.88rem;
        }
        .permissions-card-table th,
        .permissions-card-table td {
            border: 1px solid #e2e8f0;
            padding: 0.48rem 0.12rem;
            text-align: center;
            background-color: #ffffff;
        }
        .permissions-card-table thead th {
            background-color: #f1f5f9;
            color: #475569;
            font-size: 0.78rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            padding: 0.48rem 0.12rem;
        }
        .permissions-card-table th:first-child,
        .permissions-card-table td:first-child {
            text-align: left;
            min-width: 160px;
            padding-left: 0.35rem;
            padding-right: 0.35rem;
        }
        .permissions-submodule-label {
            display: inline-flex;
            align-items: center;
            gap: 0.18rem;
            font-weight: 500;
            color: #334155;
            font-size: 0.88rem;
        }
        .permissions-submodule-label::before {
            content: '';
            width: 0.5rem;
            height: 1px;
            background-color: #cbd5e1;
        }
        .permissions-submodule-label {
            flex-wrap: wrap;
            max-width: 100%;
        }

        .permissions-card-table td {
            white-space: normal;
            word-break: break-word;
            overflow-wrap: anywhere;
            max-width: 100%;
        }

        .permissions-card-table td:first-child {
            min-width: 0;
            max-width: 240px;
        }
        .permissions-action-cell {
            width: 54px;
            padding: 0.12rem;
        }

        @media (max-width: 640px) {
            .permissions-table-wrapper {
                max-width: 100%;
                overflow-x: hidden;
                overflow-y: visible;
            }
            .permissions-module-tabs-shell {
                margin-bottom: 0.65rem;
                padding: 0.2rem;
                overflow-x: hidden;
            }
            .permissions-module-tabs {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 0.35rem;
                min-width: 0;
                width: 100%;
            }
            .permissions-module-tab {
                font-size: 0.74rem;
                padding: 0.58rem 0.6rem;
                white-space: normal;
                text-align: center;
                width: 100%;
            }
            .permissions-module-content-shell {
                padding: 0.45rem;
            }
            .permissions-modules-columns {
                display: grid;
                grid-template-columns: 1fr;
                gap: 0.7rem;
            }
            .permissions-column {
                min-width: 0;
                width: 100%;
                gap: 0.7rem;
            }
            .permissions-card {
                padding: 0.6rem;
                border-radius: 0.85rem;
            }
            .permissions-card-header {
                flex-direction: column;
                align-items: stretch;
                flex-wrap: wrap;
                gap: 0.5rem;
                margin-bottom: 0.5rem;
            }
            .permissions-card-header > div:first-child {
                flex: 1 1 auto;
                min-width: 0;
            }
            .permissions-card-controls {
                width: 100%;
                justify-content: flex-start;
                gap: 0.4rem;
            }
            .module-select-all-label {
                width: 100%;
                justify-content: space-between;
                font-size: 0.88rem;
                gap: 0.35rem;
            }
            .permissions-card-title {
                font-size: 0.9rem;
                line-height: 1.25;
            }
            .permissions-card-toggle {
                font-size: 0.74rem;
                padding: 0.32rem 0.62rem;
            }

            /* ── Responsive: mostrar tabla de escritorio dentro de contenedor con scroll horizontal ── */
            .permissions-card-table-wrapper {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .permissions-card-table {
                display: table;
                min-width: 220px; /* fuerza ancho mínimo para mostrar columnas en móviles y activar scroll */
                width: max-content;
                font-size: 0.84rem;
                border-collapse: collapse;
                table-layout: auto;
            }
            .permissions-card-table thead {
                display: table-header-group;
            }
            .permissions-card-table tbody {
                display: table-row-group;
            }
            .permissions-card-table tbody tr {
                display: table-row;
                border: 1px solid #e2e8f0;
                background-color: #ffffff;
            }
            .permissions-card-table tbody td:first-child {
                font-size: 0.84rem;
                font-weight: 600;
                color: #1e293b;
                text-align: left;
                padding: 0.48rem 0.4rem;
                min-width: 160px;
            }
            .permissions-card-table tbody td.permissions-action-cell {
                min-width: 52px;
                padding: 0.3rem 0.4rem;
                text-align: center;
            }
            .permissions-card-table tbody td.permissions-action-cell::before {
                display: none;
            }
            .permissions-action-cell {
                width: 54px;
            }
        }

        @media (max-width: 480px) {
            .permissions-module-tabs {
                grid-template-columns: 1fr;
            }
            .permissions-module-tab {
                font-size: 0.72rem;
                padding: 0.55rem 0.65rem;
            }
            .permissions-card {
                padding: 0.55rem;
            }
            .permissions-card-table tbody tr {
                padding: 0.45rem 0.5rem;
            }
            .permissions-card-table tbody td:first-child {
                font-size: 0.8rem;
            }
            .permissions-card-table tbody td.permissions-action-cell {
                min-width: 44px;
            }
            .permissions-card-table tbody td.permissions-action-cell::before {
                font-size: 0.58rem;
            }
        }
        @media (max-width: 767px) {
            #modal-inputs-container {
                display: grid !important;
                grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
                gap: 0.75rem !important;
                align-items: stretch !important;
            }
            #modal-inputs-container > .product-col {
                grid-column: span 4 / span 4 !important;
            }
            #modal-inputs-container > .modal-input-col {
                width: 100% !important;
            }
            #modal-inputs-container > .modal-input-col label {
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            #modal-inputs-container > .modal-btn-col {
                grid-column: span 4 / span 4 !important;
                width: 100% !important;
                margin-top: 0.25rem !important;
            }
        }

        .credential-row-disabled {
            opacity: 0.55;
            transition: opacity 0.2s ease;
        }

        .credential-row-disabled input {
            cursor: not-allowed;
        }
        /* Ajustes responsivos globales para modales quick-* */
        /* Hace que el modal sea desplazable en dispositivos pequeños y limite su ancho */
        /* Ocultar solo cuando el modal tenga la clase 'hidden' para respetar utilidades Tailwind como .flex */
        [id$="-modal"].hidden {
            display: none;
        }
        [id$="-modal"].flex,
        [id$="-modal"].block,
        [id$="-modal"]:not(.hidden) {
            display: flex;
            align-items: flex-start;
            padding: 1rem;
            overflow-y: auto;
        }
        [id$="-modal"] > div {
            max-width: 980px;
            width: 100%;
            margin: 1rem auto;
            max-height: calc(100vh - 2.5rem);
            overflow: auto;
            border-radius: 12px;
        }
        /* Modal dialog specific adjustments to ensure proper scrolling and padding on small screens */
        .modal-dialog {
            width: 720px;
            max-width: 92%;
            margin: 1rem auto;
            position: relative;
            border-radius: 15px;
            background: #ffffff;
            box-shadow: 0 20px 40px rgba(2,6,23,0.12);
            max-height: calc(100vh - 2.5rem);
            overflow: auto;
        }
        .modal-dialog .modal-content {
            padding: 40px 48px;
            text-align: left;
        }
        @media (max-width: 640px) {
            .modal-dialog {
                width: 100%;
                max-width: 100%;
                border-radius: 12px;
                margin: 0.5rem;
            }
            .modal-dialog .modal-content {
                padding: 18px 20px;
            }
        }
        @media (min-width: 768px) {
            [id$="-modal"] {
                align-items: center;
                padding: 1.5rem;
            }
            [id$="-modal"] > div {
                width: calc(100% - 4rem);
            }
        }
        @media (min-width: 768px) {
            #modal-paquetes-content { overflow: hidden !important; }
            #modal-paquetes-list { max-height: 50vh !important; }
            #modal-paquetes-preview-scroll { max-height: 60vh !important; }
        }
    </style>
    <div class="content transition-[margin,width] duration-100 px-3 mt-[50px] pt-[31px] pb-8 relative z-10 content--compact xl:ml-[275px] [&.content--compact]:xl:ml-[91px]">
        <div class="container">
            <div class="grid grid-cols-12 gap-x-6 gap-y-10">
                <div class="col-span-12">
                    <!-- HEADER -->
                    <div class="panel panel-header mb-6 flex items-center justify-between gap-3">
                        <h1 class="min-w-0 flex-1 truncate text-xl font-semibold text-slate-900">{{ $title ?? 'Formulario' }}</h1>
                        <a href="{{ $backRoute }}" class="shrink-0 transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed border-secondary text-slate-500 dark:border-darkmode-100/40 dark:text-slate-300 [&:hover:not(:disabled)]:bg-secondary/20 [&:hover:not(:disabled)]:dark:bg-darkmode-100/10" style="border-color:#000000;color:#000000;">
                            <i data-tw-merge="" data-lucide="arrow-left" class="mr-2 h-4 w-4 stroke-[1.3]"></i>
                            Volver
                        </a>
                    </div>

                    <!-- FORMULARIO -->
                    <form id="main-crud-form" method="POST" action="{{ $formAction }}" enctype="multipart/form-data" class="box box--stacked flex flex-col p-5">
                        @csrf
                        <input type="hidden" name="download_after_save" id="download-after-save" value="0">
                        <input type="hidden" name="include_image" id="include-image-flag" value="{{ ($mode ?? '') === 'create' ? '0' : '1' }}">
                        <input type="hidden" name="group_confirm" id="group-confirm-flag" value="0">
                        <input type="hidden" name="tipoDocumentoIDCliente" id="tipo-documento-id-cliente" value="">
                        @if($mode === 'edit')
                            @method('PUT')
                        @endif

                        <!-- Input oculto para asegurar que siempre exista `descuento` en el DOM -->
                        <input type="hidden" name="descuento" value="0">

                        @if(isset($mode) && $mode === 'edit' && isset($lockResource) && isset($lockId))
                            <input type="hidden" id="erp-lock-resource" value="{{ $lockResource }}">
                            <input type="hidden" id="erp-lock-id" value="{{ $lockId }}">
                            <input type="hidden" id="erp-relation-summary-template" value="{{ route('modules.relations.summary', ['resource' => '__RESOURCE__', 'id' => '__ID__']) }}">
                        @endif

                        <!-- ALERTAS DE SESIÓN Y VALIDACIÓN -->
                        @if(session('success'))
                            <div class="mb-4 rounded-lg border px-4 py-3 text-base font-semibold relative" style="border-color:#16a34a;background-color:#dcfce7;color:#14532d;">
                                ✓ {{ session('success') }}
                                <button type="button" class="absolute top-0 right-0 mt-2 mr-2 text-lg font-bold text-gray-600 hover:text-gray-800" onclick="this.parentElement.style.display='none';">&times;</button>
                            </div>
                        @endif
                        @php
                            $downloadPdfUrls = session('download_pdf_urls', []);
                            if (is_string(session('download_pdf_url')) && empty($downloadPdfUrls)) {
                                $downloadPdfUrls = [session('download_pdf_url')];
                            }
                        @endphp
                        @if(!empty($downloadPdfUrls))
                            <script>
                                document.addEventListener('DOMContentLoaded', function () {
                                    var urls = {!! json_encode($downloadPdfUrls) !!};
                                    if (!Array.isArray(urls) || urls.length === 0) {
                                        return;
                                    }

                                    urls.forEach(function (url, index) {
                                        setTimeout(function () {
                                            if (!url) {
                                                return;
                                            }
                                            var a = document.createElement('a');
                                            a.href = url;
                                            a.download = '';
                                            a.style.display = 'none';
                                            document.body.appendChild(a);
                                            a.click();
                                            document.body.removeChild(a);
                                        }, index * 1200);
                                    });
                                });
                            </script>
                        @endif
                        @if(session('error'))
                            <div class="mb-4 rounded-lg border px-4 py-3 text-lg font-semibold relative" style="border-color:#a31616;background-color:#fcdcdc;color:#531414;">
                                ⚠️ {{ session('error') }}
                                <button type="button" class="absolute top-0 right-0 mt-2 mr-2 text-lg font-bold text-gray-600 hover:text-gray-800" onclick="this.parentElement.style.display='none';">&times;</button>
                            </div>
                        @endif

                        @if($errors->any())
                            <div class="mb-4 rounded-lg border px-4 py-3 text-sm font-semibold relative" style="border-color:#a31616;background-color:#fcdcdc;color:#531414;">
                                ⚠️ Hay errores que impiden continuar:
                                <button type="button" class="absolute top-0 right-0 mt-2 mr-2 text-lg font-bold text-gray-600 hover:text-gray-800" onclick="this.parentElement.style.display='none';">&times;</button>
                                <ul class="list-disc pl-5 mt-3 space-y-1">
                                    @foreach($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if(isset($lockBlocked) && $lockBlocked)
                            <div class="mb-4 rounded-lg border  px-4 py-3 text-lg font-semibold" style="border-color:#a31616;background-color:#fcdcdc;color:#531414;">
                                ⚠️ Este registro está siendo editado por {{ $lockOwner }}. No se puede modificar hasta que se libere.
                            </div>
                        @elseif(isset($lockInfo) && $lockInfo && isset($lockOwner) && !$lockBlocked)
                            <div class="mb-4 rounded-lg border px-4 py-3 text-lg font-semibold" style="border-color:#16a34a;background-color:#dcfce7;color:#14532d;">
                                ✅ Has bloqueado este registro. El bloqueo expira el {{ $lockInfo['expires_at'] }}.
                            </div>
                        @endif

                        @if(!empty($topSections) && is_array($topSections))
                            <div class="mb-8 space-y-6">
                                @foreach($topSections as $topSection)
                                    @include($topSection['view'], $topSection['data'] ?? [])
                                @endforeach
                            </div>
                        @endif

                        <!-- CAMPOS DINÁMICOS -->
                        <div class="">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="font-semibold text-lg">Datos Generales</h3>
                            </div>
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                            @foreach($fields as $field)
                                @php
                                    $fieldValue = old($field['name'], $field['value'] ?? data_get($record, $field['name']));
                                    $hasError = $errors->has($field['name']);
                                    $errorMessage = $errors->first($field['name']);
                                    $colSpan = $field['colSpan'] ?? 1;
                                    $colClass = $colSpan === 2 ? 'md:col-span-2' : ($colSpan === 1 ? '' : 'md:col-span-' . $colSpan);
                                @endphp

                                {{-- Omitir aquí los campos de totales y los campos que van al resumen financiero --}}
                                @if(in_array($field['name'] ?? '', ['subtotal','descuento','igv','total','formaPago_idformaPago','moneda_idmoneda','comentario'], true))
                                    @continue
                                @endif

                                

                                {{-- Evitar renderizar individualmente los campos de totales después de haber mostrado el bloque compacto --}}
                                @if(isset($renderedTotals) && in_array($field['name'] ?? '', ['descuento','igv','total'], true))
                                    @continue
                                @endif

                                @if((($field['quickCreateCredential'] ?? false) === true && !($canSeeCredencialesField ?? true)) ||
                                    (($field['quickCreateDispositivo'] ?? false) === true && !($canSeeDispositivoField ?? true)) ||
                                    (($field['name'] ?? '') === 'dispositivoSeleccionado' && !($canSeeDispositivoField ?? true)))
                                    @continue
                                @endif

                                @if(($field['type'] ?? '') === 'hidden')
                                    <input type="hidden" name="{{ $field['name'] }}" value="{{ $fieldValue }}">
                                    @continue
                                @endif

                                <div class="crud-field-wrapper {{ $colClass }}">
                                    @switch($field['type'])
                                        @case('text')
                                        @case('email')
                                        @case('password')
                                        @case('number')
                                        @case('date')
                                        @case('datetime-local')
                                            @php
                                                $fieldHelpText = $field['helpText'] ?? null;
                                                if (!$fieldHelpText) {
                                                    if ($field['name'] === 'dniPersonal') {
                                                        $fieldHelpText = 'Solo números, exactamente 8 dígitos.';
                                                    } elseif (in_array($field['name'], ['apellido', 'nombre'], true)) {
                                                        $fieldHelpText = 'Solo letras. Mínimo 2 caracteres.';
                                                    } elseif ($field['type'] === 'email' || str_contains($field['name'], 'correo')) {
                                                        $fieldHelpText = 'Debe incluir @ y terminar en .com.';
                                                    }
                                                }
                                                $validationMessage = $field['validationMessage'] ?? $fieldHelpText ?? '';
                                            @endphp
                                            <div class="mb-2">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <label class="block text-sm font-medium text-slate-700">
                                                        <span>
                                                            {{ $field['label'] }}
                                                            @if(($field['required'] ?? false))
                                                                <span class="text-red-500">*</span>
                                                            @endif
                                                        </span>
                                                    </label>

                                                    @if(request()->routeIs('modules.ventas.cotizaciones.*'))
                                                        @if(($field['name'] ?? '') === 'direccion')
                                                            <button
                                                                type="button"
                                                                data-address-picker-button
                                                                data-client-field="cliente_idcliente"
                                                                data-url="{{ route('modules.clientes.direcciones.opciones') }}"
                                                                class=" items-center gap-1 text-xs " style=" color: #da2c2c !important;"
                                                            >
                                                                <i data-tw-merge data-lucide="zoom-in" class="stroke-[1] w-4 h-4 mx-auto block mx-auto block"></i>
                                                            </button>
                                                        @elseif(($field['name'] ?? '') === 'telefono')
                                                            <button
                                                                type="button"
                                                                data-contact-picker-button
                                                                data-contact-type="telefono"
                                                                data-client-field="cliente_idcliente"
                                                                data-url-template="{{ route('modules.clientes.contactos.opciones', ['cliente' => '__CLIENTE__']) }}"
                                                                class=" items-center gap-1 text-xs " style=" color: #da2c2c !important;"
                                                            >
                                                                <i data-tw-merge data-lucide="zoom-in" class="stroke-[1] w-4 h-4 mx-auto block mx-auto block"></i>
                                                            </button>
                                                        @elseif(($field['name'] ?? '') === 'correo')
                                                            <button
                                                                type="button"
                                                                data-contact-picker-button
                                                                data-contact-type="correo"
                                                                data-client-field="cliente_idcliente"
                                                                data-url-template="{{ route('modules.clientes.contactos.opciones', ['cliente' => '__CLIENTE__']) }}"
                                                                class=" items-center gap-1 text-xs " style=" color: #da2c2c !important;"
                                                            >
                                                                <i data-tw-merge data-lucide="zoom-in" class="stroke-[1] w-4 h-4 mx-auto block mx-auto block"></i>
                                                            </button>
                                                        @endif
                                                    @endif
                                                    
                                                    @if($fieldHelpText)
                                                        <p id="{{ $field['name'] }}-help" class="text-xs text-slate-500 sm:text-right">{{ $fieldHelpText }}</p>
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="relative">
                                                <input 
                                                    type="{{ $field['type'] === 'date' ? 'text' : $field['type'] }}" 
                                                    name="{{ $field['name'] }}" 
                                                    @if(($field['name'] ?? '') === 'direccion') id="field-direccion" @elseif(($field['name'] ?? '') === 'telefono') id="field-telefono" @elseif(($field['name'] ?? '') === 'correo') id="field-correo" @elseif(($field['name'] ?? '') === 'cliente_idcliente_visual') id="cliente-idcliente-visual" @endif
                                                    @if($field['type'] !== 'password') value="{{ $fieldValue }}" @endif
                                                    class="w-full rounded-lg border {{ $hasError ? 'border-red-500' : 'border-slate-300' }} px-3 py-2 text-sm transition duration-200 ease-in-out focus:border-primary focus:ring-1 focus:ring-primary {{ $field['type'] === 'date' ? 'datepicker' : '' }} {{ ($field['readonly'] ?? false) ? 'bg-slate-50 cursor-not-allowed' : '' }}"
                                                    {{ ($field['type'] === 'date') ? 'data-no-default="true"' : '' }}
                                                    {{ ($field['required'] ?? false) ? 'required' : '' }}
                                                    {{ ($field['disabled'] ?? false) ? 'disabled' : '' }}
                                                    {{ ($field['readonly'] ?? false) ? 'readonly' : '' }}
                                                    {{ (($readOnly ?? false) && !($field['editable'] ?? false)) ? 'disabled' : '' }}
                                                    {{ isset($field['maxlength']) ? "maxlength={$field['maxlength']}" : '' }}
                                                    {{ isset($field['minlength']) ? "minlength={$field['minlength']}" : '' }}
                                                    @if(isset($field['pattern'])) pattern="{{ $field['pattern'] }}" @endif
                                                    @if(isset($field['inputmode'])) inputmode="{{ $field['inputmode'] }}" @endif
                                                    @if(isset($field['datalistOptions']) && is_array($field['datalistOptions'])) data-datalist-options='@json($field['datalistOptions'])' autocomplete="off" @endif
                                                    {{ isset($field['min']) ? "min={$field['min']}" : '' }}
                                                    {{ isset($field['max']) ? "max={$field['max']}" : '' }}
                                                    {{ isset($field['step']) ? "step={$field['step']}" : '' }}
                                                    @if($fieldHelpText) aria-describedby="{{ $field['name'] }}-help" @endif
                                                    @if($validationMessage) data-validation-message="{{ e($validationMessage) }}" @endif
                                                    @if(($field['quickCreateSimcard'] ?? false) === true) data-quick-create-simcard="true" @endif
                                                    @if(($field['quickCreateNumero'] ?? false) === true) data-quick-create-numero="true" @endif
                                                    oninvalid="this.setCustomValidity(this.dataset.validationMessage || this.validationMessage)"
                                                    oninput="this.setCustomValidity('')"
                                                    placeholder="{{ $field['placeholder'] ?? $field['label'] ?? '' }}"
                                                >
                                                @if(($field['quickCreateDetalleListaPrecio'] ?? false) === true)
                                                    <input
                                                        type="hidden"
                                                        name="{{ $field['quickCreateDetalleListaPrecioPayloadInput'] ?? 'detalle_lista_precio_payload' }}"
                                                        value="{{ old($field['quickCreateDetalleListaPrecioPayloadInput'] ?? 'detalle_lista_precio_payload', '[]') }}"
                                                    >
                                                @endif
                                                @if(isset($field['datalistOptions']) && is_array($field['datalistOptions']))
                                                    <div class="custom-datalist hidden absolute left-0 right-0 z-20 mt-1 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg">
                                                        <div class="custom-datalist-options overflow-y-auto" style="max-height: 150px;"></div>
                                                    </div>
                                                @endif
                                            </div>
                                            @if($hasError)
                                                <p class="mt-1 text-sm font-semibold text-red-600" style="color: #b63434;">
                                                    {{ $errorMessage }}
                                                </p>
                                            @endif
                                        @break

                                        @case('select')
                                            <div class="mb-2 flex items-center justify-between gap-2">
                                                <label class="block text-sm font-medium text-slate-700">
                                                    {{ $field['label'] }}
                                                </label>

                                                @if(($field['name'] ?? '') === 'cliente_idcliente')
                                                    <a href="{{ route('modules.clientes.create', ['return_route' => 'modules.ventas.cotizaciones.create']) }}" class=" items-center gap-1 text-xs " style=" color: #da2c2c !important;">
                                                         <i data-tw-merge data-lucide="zoom-in" class="stroke-[1] w-4 h-4 mx-auto block mx-auto block"></i>
                                                    </a>
                                                @endif
                                            </div>
                                            <select 
                                                id="select-{{ $field['name'] }}"
                                                name="{{ $field['name'] }}" 
                                                @if(!empty($field['tomSelect'])) data-placeholder="{{ $field['placeholder'] ?? 'Selecciona una opcion' }}" @endif
                                                class="{{ !empty($field['tomSelect']) ? 'tom-select tom-select--compact ' : '' }}w-full rounded-lg border {{ $hasError ? 'border-red-500' : 'border-slate-300' }} px-3 py-2 text-sm transition duration-200 ease-in-out focus:border-primary focus:ring-1 focus:ring-primary {{ ($field['readonly'] ?? false) ? 'bg-slate-50 cursor-not-allowed' : '' }}"
                                                {{ ($field['required'] ?? false) ? 'required' : '' }}
                                                {{ ($field['disabled'] ?? false) ? 'disabled' : '' }}
                                                {{ ($field['readonly'] ?? false) ? 'disabled' : '' }}
                                                {{ (($readOnly ?? false) && !($field['editable'] ?? false)) ? 'disabled' : '' }}
                                                @if(($field['quickCreateSimcard'] ?? false) === true) data-quick-create-simcard="true" @endif
                                                @if(($field['quickCreateNumero'] ?? false) === true) data-quick-create-numero="true" @endif
                                            >
                                            @php
                                                $normalizedOptions = [];
                                                if ($field['options'] ?? false) {
                                                    $normalizedOptions = collect($field['options'])->mapWithKeys(function ($optionValue, $optionKey) {
                                                        if (is_array($optionValue) || is_object($optionValue)) {
                                                            return [data_get($optionValue, 'value', $optionKey) => data_get($optionValue, 'label', '')];
                                                        }

                                                        return [$optionKey => $optionValue];
                                                    })->all();
                                                }
                                                $showPlaceholder = !collect($normalizedOptions)->keys()->contains('');
                                                $placeholderText = $field['placeholder'] ?? 'Selecciona una opción';
                                            @endphp
                                            @if($showPlaceholder)
                                                <option value="">{{ $placeholderText }}</option>
                                            @endif
                                            @if(!empty($normalizedOptions))
                                                @foreach($normalizedOptions as $optionKey => $optionLabel)
                                                    <option 
                                                        value="{{ $optionKey }}" 
                                                        title="{{ $optionLabel }}"
                                                        @if((string) $fieldValue === (string) $optionKey) selected @endif
                                                    >
                                                        {{ $optionLabel }}
                                                    </option>
                                                @endforeach
                                            @elseif($field['optionsData'] ?? false)
                                                    @foreach($field['optionsData'] as $option)
                                                        @php
                                                            $optKey = data_get($option, $field['optionKey'] ?? 'id');
                                                            $optLabel = data_get($option, $field['optionLabel'] ?? 'name');
                                                        @endphp
                                                        <option 
                                                            value="{{ $optKey }}" 
                                                            title="{{ $optLabel }}"
                                                            @if((string) $fieldValue === (string) $optKey) selected @endif
                                                        >
                                                            {{ $optLabel }}
                                                        </option>
                                                    @endforeach
                                                @endif
                                            </select>
                                            @if(($field['readonly'] ?? false))
                                                <input type="hidden" name="{{ $field['name'] }}" value="{{ $fieldValue }}">
                                            @endif
                                            @if($hasError)
                                                <p class="mt-1 text-sm font-semibold text-red-600" style="color: #b63434;">
                                                    {{ $errorMessage }}
                                                </p>
                                            @endif
                                        @break

                                        @case('textarea')
                                            <label class="mb-2 block text-sm font-medium text-slate-700">
                                                {{ $field['label'] }}
                                                @if(($field['required'] ?? false))
                                                    <span class="text-red-500">*</span>
                                                @endif
                                            </label>
                                            <textarea 
                                                name="{{ $field['name'] }}" 
                                                class="w-full rounded-lg border {{ $hasError ? 'border-red-500' : 'border-slate-300' }} px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary {{ $colClass }}"
                                                rows="{{ $field['rows'] ?? 4 }}"
                                                {{ ($field['required'] ?? false) ? 'required' : '' }}
                                                {{ (($readOnly ?? false) && !($field['editable'] ?? false)) ? 'disabled' : '' }}
                                                {{ isset($field['maxlength']) ? "maxlength={$field['maxlength']}" : '' }}
                                                placeholder="{{ $field['placeholder'] ?? $field['label'] ?? '' }}"
                                            >{{ $fieldValue }}</textarea>
                                            @if($hasError)
                                                <p class="mt-1 text-sm text-red-500">{{ $errorMessage }}</p>
                                            @endif
                                        @break
                                        
                                        @case('switch')
                                            <label class="mb-2 block text-sm font-medium text-slate-700">
                                                {{ $field['label'] }}
                                                @if(($field['required'] ?? false)) <span class="text-red-500">*</span> @endif
                                            </label>
                                            @php
                                                $optionKey = collect($field['options'] ?? [])->keys()->first() ?? '1';
                                                $isChecked = is_array($fieldValue) && in_array((string)$optionKey, array_map('strval', $fieldValue), true);
                                            @endphp
                                            <label class="inline-flex items-center gap-3 text-sm text-slate-500">
                                                <span class="font-medium">{{ $field['switchLabels']['off'] ?? 'NO' }}</span>
                                                <span class="permission-switch">
                                                    <input
                                                        type="checkbox"
                                                        name="{{ $field['name'] }}[]"
                                                        value="{{ $optionKey }}"
                                                        class="permission-switch-input"
                                                        data-off-label="{{ $field['switchLabels']['off'] ?? 'No' }}"
                                                        data-on-label="{{ $field['switchLabels']['on'] ?? 'Sí' }}"
                                                        {{ $isChecked ? 'checked' : '' }}
                                                        {{ ($readOnly ?? false) ? 'disabled' : '' }}
                                                    >
                                                    <span class="permission-switch-track" aria-hidden="true"></span>
                                                </span>
                                                <span class="font-medium">{{ $field['switchLabels']['on'] ?? 'SI' }}</span>
                                            </label>
                                            @if($hasError)
                                                <p class="mt-1 text-sm text-red-500">{{ $errorMessage }}</p>
                                            @endif
                                        @break
                                        @case('checkbox')
                                            <fieldset class="md:col-span-2">
                                                <legend class="mb-2 block text-sm font-medium text-slate-700">{{ $field['label'] }}</legend>
                                                @php
                                                    $checkboxGridClass = $field['checkboxGrid'] ?? ((isset($field['options']) && is_array($field['options']) && count($field['options']) > 1) ? 'md:grid-cols-2' : 'md:grid-cols-1');
                                                @endphp
                                                <div class="grid gap-2 {{ $checkboxGridClass }}">
                                                        @if(isset($field['options']) && is_array($field['options']))
                                                            @php
                                                                $normalizedOptions = collect($field['options'])->mapWithKeys(function ($optionValue, $optionKey) {
                                                                    if (is_array($optionValue) || is_object($optionValue)) {
                                                                        return [data_get($optionValue, 'value', $optionKey) => data_get($optionValue, 'label', '')];
                                                                    }

                                                                    return [$optionKey => $optionValue];
                                                                })->all();
                                                            @endphp
                                                            @foreach($normalizedOptions as $optionKey => $optionLabel)
                                                                <label class="custom-checkbox-wrapper">
                                                                    <input 
                                                                        type="checkbox" 
                                                                        name="{{ $field['name'] }}[]" 
                                                                        value="{{ $optionKey }}"
                                                                        @if((is_array($fieldValue) && in_array((string) $optionKey, array_map('strval', $fieldValue), true)) || ((string) $fieldValue === (string) $optionKey)) checked @endif
                                                                        class="custom-checkbox"
                                                                    >
                                                                    <span class="custom-checkbox-box" aria-hidden="true"></span>
                                                                    <span class="text-sm text-slate-700">{{ $optionLabel }}</span>
                                                                </label>
                                                            @endforeach
                                                        @else
                                                        <label class="custom-checkbox-wrapper">
                                                            <input 
                                                                    type="checkbox" 
                                                                    name="{{ $field['name'] }}" 
                                                                    value="1"
                                                                    @if($fieldValue) checked @endif
                                                                class="custom-checkbox"
                                                            >
                                                            <span class="custom-checkbox-box" aria-hidden="true"></span>
                                                                <span class="text-sm text-slate-700">{{ $field['label'] }}</span>
                                                        </label>
                                                        @endif
                                                </div>
                                                @if($hasError)
                                                    <p class="mt-1 text-sm text-red-500">{{ $errorMessage }}</p>
                                                @endif
                                            </fieldset>
                                        @break

                                        @case('checkbox-object')
                                            <fieldset class="md:col-span-2" data-checkbox-group="{{ $field['name'] }}" data-single-selection="{{ ($field['singleSelection'] ?? false) ? 'true' : 'false' }}">
                                                <legend class="mb-2 block text-sm font-medium text-slate-700">{{ $field['label'] }}</legend>
                                                <div class="grid gap-3 {{ $field['checkboxGrid'] ?? 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4' }}">
                                                    @foreach($field['optionsData'] as $option)
                                                        @php
                                                            $optKey = data_get($option, $field['optionKey'] ?? 'id');
                                                            $optLabel = data_get($option, $field['optionLabel'] ?? 'name');
                                                            $optDescription = data_get($option, 'optionDescription', '');
                                                            $isChecked = is_array($fieldValue)
                                                                ? in_array((string) $optKey, array_map('strval', $fieldValue), true)
                                                                : ((string) $fieldValue === (string) $optKey);
                                                            $optTitle = $field['name'] === 'role_ids'
                                                                ? 'Rol: ' . $optLabel
                                                                : $optLabel;
                                                        @endphp
                                                        <label class="checkbox-object-item">
                                                            <input 
                                                                type="checkbox" 
                                                                class="custom-checkbox"
                                                                name="{{ $field['name'] }}[]" 
                                                                value="{{ $optKey }}"
                                                                @if($isChecked) checked @endif
                                                                {{ ($readOnly ?? false) ? 'disabled' : '' }}
                                                                @if(($field['singleSelection'] ?? false)) data-single-selection-item="true" @endif
                                                                @if($field['name'] === 'role_ids')
                                                                    data-role-option="true"
                                                                    data-role-id="{{ $optKey }}"
                                                                    data-role-permissions-matrix='@json(data_get($option, 'permissionMatrix', []))'
                                                                    data-role-vista-ids='@json(data_get($option, 'vista_ids', []))'
                                                                @endif
                                                            >
                                                            <span class="custom-checkbox-box" aria-hidden="true"></span>
                                                            <div class="checkbox-object-content">
                                                                <span class="checkbox-object-title">{{ $optTitle }}</span>
                                                                @if($optDescription && $field['name'] !== 'role_ids')
                                                                    <span class="checkbox-object-detail">{{ $optDescription }}</span>
                                                                @endif
                                                            </div>
                                                        </label>
                                                    @endforeach
                                                </div>
                                                @if($hasError)
                                                    <p class="mt-1 text-sm text-red-500">{{ $errorMessage }}</p>
                                                @endif
                                            </fieldset>
                                        @break

                                        @case('partial')
                                            <div class="md:col-span-2">
                                                @include($field['partial'], $field['data'] ?? [])
                                            </div>
                                        @break
                                            @case('file')
                                                @php
                                                    $fileKind = $field['fileKind'] ?? 'image';
                                                    $acceptTypes = $field['accept'] ?? 'image/jpeg,image/png';
                                                    $isImageFile = $fileKind === 'image';
                                                @endphp
                                                <label class="mb-2 block text-sm font-medium text-slate-700">
                                                    {{ $field['label'] }}
                                                    @if(($field['required'] ?? false))
                                                        <span class="text-red-500">*</span>
                                                    @endif
                                                </label>
                                                <div class="file-upload-wrapper" data-file-input-wrapper>
                                                    @if(!empty($fieldValue))
                                                        <div class="flex items-center gap-4 mb-2">
                                                            @if($isImageFile)
                                                                <div>
                                                                    <img src="{{ asset('storage/' . $fieldValue) }}" alt="{{ $field['label'] }}" style="max-height:120px; max-width:160px; object-fit:cover; border-radius:6px;" />
                                                                </div>
                                                            @else
                                                                <div class="max-w-sm rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700" style="background-color: #e0f2fe;">
                                                                    <p class="text-xs font-semibold text-slate-500" style="color: #000000;">Imagen actual(pulsa para ver)</p>
                                                                    <a data-file-link href="{{ asset('storage/' . $fieldValue) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-primary hover:underline">
                                                                        {{ basename((string) $fieldValue) }}
                                                                    </a>
                                                                </div>
                                                            @endif
                                                            <div>
                                                                <label class="inline-flex items-center gap-2 rounded-md border-dashed border-2 p-3 text-slate-600 cursor-pointer file-upload-placeholder" style="border-color: #a7b2c2;">
                                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-700" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1M16 8l-4-4m0 0L8 8m4-4v12"/></svg>
                                                                    <span class="text-sm text-slate-700 font-medium">Cambiar {{ strtolower($field['label']) }}</span>
                                                                    <input type="file" name="{{ $field['name'] }}" accept="{{ $acceptTypes }}" data-file-kind="{{ $fileKind }}" data-file-label="{{ strtolower($field['label']) }}" class="hidden file-upload-input" onchange="showFileSelectionMessage(this)" {{ ($readOnly ?? false) ? 'disabled' : '' }}>
                                                                </label>
                                                            </div>
                                                        </div>
                                                    @else
                                                        <label class="flex items-center gap-3 rounded-md border-dashed border-2 p-3 text-slate-600 cursor-pointer file-upload-placeholder" style="border-color: #a7b2c2;" data-file-upload-placeholder>
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4 4 4M17 8v8a4 4 0 01-4 4H7"/></svg>
                                                            <span class="text-sm file-upload-label">Seleccionar {{ strtolower($field['label']) }}</span>
                                                            <input type="file" name="{{ $field['name'] }}" accept="{{ $acceptTypes }}" data-file-kind="{{ $fileKind }}" data-file-label="{{ strtolower($field['label']) }}" class="hidden file-upload-input" onchange="showFileSelectionMessage(this)" {{ ($readOnly ?? false) ? 'disabled' : '' }}>
                                                        </label>
                                                    @endif

                                                    <div class="file-input-message text-sm mt-2 hidden" style="color:#16a34a"></div>
                                                </div>
                                                @if($hasError)
                                                    <p class="mt-1 text-sm text-red-500" style="color: #c71010;">{{ $errorMessage }}</p>
                                                @endif
                                            @break

                                        @case('alert')
                                            @php
                                                $alertType = $field['alertType'] ?? 'info';
                                                $alertColors = [
                                                    'info' => 'bg-blue-50 border-blue-200 text-blue-700',
                                                    'warning' => 'bg-yellow-50 border-yellow-200 text-yellow-700',
                                                    'error' => 'bg-red-50 border-red-200 text-red-700',
                                                    'success' => 'bg-green-50 border-green-200 text-green-700',
                                                ];
                                                $alertClass = $alertColors[$alertType] ?? $alertColors['info'];
                                            @endphp
                                            <div class="rounded-lg border px-4 py-3 {{ $alertClass }} md:col-span-2">
                                                <p class="text-sm">{{ $field['message'] ?? '' }}</p>
                                            </div>
                                        @break

                                        @case('static')
                                            <div class="md:col-span-2">
                                                <label class="block text-sm font-medium text-slate-700 mb-2">
                                                    {{ $field['label'] }}
                                                </label>
                                                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                                                    {{ $field['value'] ?? '' }}
                                                </div>
                                            </div>
                                        @break
                                    @endswitch
                                </div>
                            @endforeach
                        </div>


                        <hr class="my-6 border-slate-200">

                        <!-- DETALLE DE PRODUCTOS / SERVICIOS MÚLTIPLES -->
                        <div class="mt-4" id="multiple-quote-section">
                            <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 md:flex-row md:items-center md:justify-between">
                                <h3 class="text-lg font-semibold text-black">Detalle de Productos / Servicios</h3>
                                <div class="flex flex-wrap items-center gap-2 justify-end ml-auto">
                                    <button type="button" id="btn-add-paquetes" style="min-width:80px;padding:8px 10px;border-color:#000000;color:#000000;"  class="inline-flex items-center justify-center gap-1 rounded-md border border-slate-400 bg-white px-3 h-[38px] text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 transition-colors" {{ ($readOnly ?? false) ? 'disabled' : '' }}>
                                        Paquetes
                                    </button>
                                    <button type="button" id="btn-add-item-modal" style="min-width:80px;padding:8px 10px;" class="inline-flex items-center justify-center gap-1 rounded-md bg-primary px-3 h-[38px] text-sm font-medium text-white shadow-sm hover:opacity-90 transition-opacity" {{ ($readOnly ?? false) ? 'disabled' : '' }}>
                                        + Agregar nuevo item
                                    </button>
                                </div>
                            </div>
                            
                            <div id="cotizaciones-container" class="mt-6 flex flex-col gap-8">
                                <div id="detalle-empty" class="text-center py-10 bg-slate-50 border border-dashed border-slate-300 rounded-md">
                                    <p class="text-sm text-slate-500 italic">No hay items agregados. Haz clic en "+ Agregar nuevo item" para comenzar.</p>
                                </div>
                            </div>
                        </div>

                        <hr class="my-6 border-slate-200">

                        <!-- DATOS GENERALES -->
                        <div id="datos-generales-global" class="{{ ($mode === 'edit') ? '' : 'hidden' }}">
                            @php
                                $f_vig = null; $f_fp = null; $f_mon = null; $f_com = null;
                                foreach($fields as $ff) {
                                    if(($ff['name'] ?? '') === 'vigenciaOferta_idvigenciaOferta') $f_vig = $ff;
                                    if(($ff['name'] ?? '') === 'formaPago_idformaPago') $f_fp = $ff;
                                    if(($ff['name'] ?? '') === 'moneda_idmoneda') $f_mon = $ff;
                                    if(($ff['name'] ?? '') === 'comentario') $f_com = $ff;
                                }
                            @endphp

                            <h3 class="font-semibold text-lg mb-4 mt-6">Información Adicional</h3>
                            <div class="grid grid-cols-4 gap-6 resumen-cotizacion">
                                <!-- LADO IZQUIERDO -->
                                <div class="col-span-2 lg:col-span-2 flex flex-col gap-5 resumen-left">
                                    <div class="grid grid-cols-1 gap-4 resumen-rowgrid">
                                        @if($f_fp)
                                            @php $val_fp = old($f_fp['name'], $f_fp['value'] ?? data_get($record, $f_fp['name'])); @endphp
                                            <div class="flex flex-col gap-2">
                                                <label class="form-label">{{ $f_fp['label'] ?? 'Forma de Pago' }} @if(($f_fp['required'] ?? false))<span class="text-red-500">*</span>@endif</label>
                                                <select name="{{ $f_fp['name'] }}" class="{{ !empty($f_fp['tomSelect']) ? 'tom-select tom-select--compact ' : '' }}form-control" {{ (($readOnly ?? false) && !($f_fp['editable'] ?? false)) ? 'disabled' : '' }}>
                                                    <option value="">Selecciona un Forma de Pago</option>
                                                    @foreach($f_fp['optionsData'] ?? [] as $opt)
                                                        <option value="{{ data_get($opt, $f_fp['optionKey'] ?? 'idformaPago') }}" {{ (string)$val_fp === (string)data_get($opt, $f_fp['optionKey'] ?? 'idformaPago') ? 'selected' : '' }}>{{ data_get($opt, $f_fp['optionLabel'] ?? 'label') }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endif
                                        
                                        @if($f_mon)
                                            @php $val_mon = old($f_mon['name'], $f_mon['value'] ?? data_get($record, $f_mon['name'])); @endphp
                                            <div class="flex flex-col gap-2">
                                                <label class="form-label">{{ $f_mon['label'] ?? 'Moneda' }} @if(($f_mon['required'] ?? false))<span class="text-red-500">*</span>@endif</label>
                                                <select name="{{ $f_mon['name'] }}" class="{{ !empty($f_mon['tomSelect']) ? 'tom-select tom-select--compact ' : '' }}form-control" {{ (($readOnly ?? false) && !($f_mon['editable'] ?? false)) ? 'disabled' : '' }}>
                                                    <option value="">Selecciona una Moneda</option>
                                                    @foreach($f_mon['optionsData'] ?? [] as $opt)
                                                        <option value="{{ data_get($opt, $f_mon['optionKey'] ?? 'idmoneda') }}" {{ (string)$val_mon === (string)data_get($opt, $f_mon['optionKey'] ?? 'idmoneda') ? 'selected' : '' }}>{{ data_get($opt, $f_mon['optionLabel'] ?? 'label') }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                <!-- LADO DERECHO -->
                                <div class="col-span-2 lg:col-span-2 flex flex-col gap-4 h-full resumen-right">
                                    @if($f_com)
                                        @php $val_com = old($f_com['name'], $f_com['value'] ?? data_get($record, $f_com['name'])); @endphp
                                        <div class="flex-1 flex flex-col gap-2">
                                            <label class="form-label">{{ $f_com['label'] ?? 'Comentario' }}</label>
                                            <textarea name="{{ $f_com['name'] }}" class="form-control flex-1 resize-y min-h-[100px]" {{ (($readOnly ?? false) && !($f_com['editable'] ?? false)) ? 'disabled' : '' }}>{{ $val_com }}</textarea>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div id="datos-generales-by-quote"></div>
                        <div id="datos-generales-placeholder" class="text-sm text-slate-500 italic">Cada cotización tendrá sus propios datos generales cuando agregues varias tablas.</div>

                        <!-- MODAL AGREGAR ITEM -->
                        
                        <!-- MODAL PAQUETES -->
                        <div id="modal-paquetes" class="fixed inset-0 hidden items-center justify-center px-4 backdrop-blur-sm" style="z-index: 9999; background-color: rgba(0, 0, 0, 0.78);">
                            <div class="w-full rounded-lg bg-white shadow-2xl ring-1 ring-slate-900/10 border-t-4 border-slate-700 overflow-hidden flex flex-col" style="max-width: 880px; height: 85vh; max-height: 85vh;">
                                <div class="flex items-start justify-between border-b border-slate-200 bg-gradient-to-r from-slate-50 to-white px-6 py-5 shrink-0">
                                    <div>
                                        <h3 class="text-lg font-bold text-slate-900">Seleccionar Paquete</h3>
                                        <p class="text-sm text-slate-600">Busca y selecciona un paquete para previsualizar sus ítems antes de añadirlos.</p>
                                    </div>
                                    <button type="button" id="close-modal-paquetes" class="ml-auto rounded-lg border-0 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-100 transition duration-200 flex-shrink-0">
                                        X
                                    </button>
                                </div>
                                <div id="modal-paquetes-content" class="flex-1 grid grid-cols-1 md:grid-cols-2 gap-4 px-6 py-4 bg-slate-50/50" style="overflow-y: auto;">
                                    <div class="bg-white p-2 rounded-md border border-slate-200 shadow-sm h-fit">
                                        <div class="flex items-start justify-between gap-3 mb-4">
                                            <div>
                                                <label class="block text-xs font-semibold text-slate-600 mb-1">Buscar y seleccionar paquete</label>
                                                <p class="text-xs text-slate-500">Escribe para filtrar paquetes y selecciona uno para ver sus ítems.</p>
                                            </div>
                                        </div>
                                        <div class="relative">
                                            <input id="modal-paquetes-search" type="search" placeholder="Buscar paquete..." class="form-control w-full px-8 py-2" autocomplete="off">
                                            <span class="absolute inset-y-0 px-2 py-2 flex items-center text-slate-400 pointer-events-none">
                                                <i data-lucide="search" class="w-4 h-4"></i>
                                            </span>
                                        </div>
                                        <div id="modal-paquetes-list" class="mt-4 grid gap-3 pr-1" style="max-height: 160px; overflow-y: auto;"></div>
                                        
                                    </div>

                                    <!-- Previsualización -->
                                    <div class="bg-white border border-slate-200 rounded-md shadow-sm overflow-hidden hidden h-fit" id="paquete-preview-container">
                                        <div class="bg-slate-50 px-4 py-2 border-b border-slate-200">
                                            <h4 class="text-sm font-bold text-slate-700">Previsualización de Ítems</h4>
                                        </div>
                                        <div id="modal-paquetes-preview-scroll" class="w-full" style="max-height: 200px; overflow-y: auto; overflow-x: auto;">
                                            <table class="w-full text-left text-sm text-slate-600">
                                                <thead class="bg-slate-200 text-xs uppercase text-slate-500 font-semibold border-b border-slate-200 sticky top-0 z-10">
                                                    <tr>
                                                        <th class="px-4 py-3 min-w-[200px]">Producto / Servicio</th>
                                                        <th class="px-2 py-3 w-28 text-center min-w-[90px]">Precio Unit.</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="modal-paquetes-tbody" class="divide-y divide-slate-100 bg-white">
                                                    <!-- Preview rows injected here -->
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <div class="border-t border-slate-200 bg-slate-50 px-6 py-4 shrink-0 flex justify-end gap-3">
                                    <div id="modal-paquetes-message" class="modal-inline-message text-sm text-primary mr-auto hidden" style="align-self:center"></div>
                                    <button type="button" id="btn-cancel-paquetes" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 transition duration-200">
                                        Cancelar
                                    </button>
                                    <button type="button" id="btn-save-paquete" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed bg-primary border-primary text-white dark:border-primary">
                                        Añadir Paquete
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- MODAL AGREGAR ITEM -->

                        <div id="modal-agregar-item" class="fixed inset-0 hidden items-center justify-center px-4 backdrop-blur-sm" style="z-index: 9999; background-color: rgba(0, 0, 0, 0.78);">
                            <div class="w-full rounded-lg bg-white shadow-2xl ring-1 ring-slate-900/10 border-t-4 border-emerald-600 overflow-hidden flex flex-col" style="max-width: 880px; height: 55vh; max-height: 65vh;">
                                <div class="flex items-start justify-between border-b border-slate-200 bg-gradient-to-r from-slate-50 to-white px-6 py-5 shrink-0">
                                    <div>
                                        <h3 class="text-lg font-bold text-slate-900">Agregar Items a Cotizar</h3>
                                        <p class="text-sm text-slate-600" id="modal-subtitle">Selecciona los productos. Al guardar, se separarán automáticamente por tipo.</p>
                                    </div>
                                    <button type="button" id="close-modal-agregar" class="ml-auto rounded-lg border-0 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-100 transition duration-200 flex-shrink-0">
                                        X   
                                    </button>
                                </div>

                                <div class="flex-1 overflow-y-auto px-6 py-4 bg-slate-50/50">
                                    <div id="modal-inputs-container" class="flex flex-col md:flex-row gap-4 mb-6 items-end bg-white p-4 rounded-md border border-slate-200 shadow-sm">
                                        <div class="flex-1 w-full relative product-col">
                                            <label class="block text-xs font-semibold text-slate-600 mb-1">Producto / Servicio</label>
                                            <select id="modal-product-select" class="form-control w-full" data-placeholder="Buscar producto o servicio...">
                                                <option value="">Buscar producto o servicio...</option>
                                            </select>
                                        </div>
                                        <div class="w-20 modal-input-col">
                                            <label class="block text-xs font-semibold text-slate-600 mb-3">Comodato</label>
                                            <label class="inline-flex items-center gap-2 text-sm text-slate-600">
                                                <input type="checkbox" id="modal-cetear" class="h-6 w-6 transition-all duration-100 ease-in-out shadow-sm border-slate-200 cursor-pointer rounded focus:ring-4 focus:ring-offset-0 focus:ring-primary focus:ring-opacity-20 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&[type='radio']]:checked:bg-primary [&[type='radio']]:checked:border-primary [&[type='radio']]:checked:border-opacity-10 [&[type='checkbox']]:checked:bg-primary [&[type='checkbox']]:checked:border-primary [&[type='checkbox']]:checked:border-opacity-10 [&:disabled:not(:checked)]:bg-slate-100 [&:disabled:not(:checked)]:cursor-not-allowed [&:disabled:not(:checked)]:dark:bg-darkmode-800/50 [&:disabled:checked]:opacity-70 [&:disabled:checked]:cursor-not-allowed [&:disabled:checked]:dark:bg-darkmode-800/50" disabled>
                                            </label>
                                        </div>
                                        <div class="w-20 modal-input-col">
                                            <label class="block text-xs font-semibold text-slate-600 mb-1">Cant.</label>
                                            <input type="number" id="modal-qty" value="1" min="1" step="1" class="form-control text-sm w-full text-center">
                                        </div>
                                        <div class="w-28 modal-input-col">
                                            <label class="block text-xs font-semibold text-slate-600 mb-1">Precio Unit.</label>
                                            <input type="number" id="modal-price" step="0.01" class="form-control text-sm w-full">
                                        </div>
                                        <div class="w-20 modal-input-col">
                                            <label class="block text-xs font-semibold text-slate-600 mb-1">Desc. %</label>
                                            <input type="number" id="modal-discount" value="0" step="0.01" class="form-control text-sm w-full text-center">
                                        </div>
                                        <button type="button" id="btn-add-modal-row" class="rounded bg-emerald-600 text-white px-4 py-2 text-sm font-medium hover:bg-emerald-700 h-[38px] modal-btn-col">
                                            Añadir
                                        </button>
                                    </div>

                                    <div class="overflow-x-auto w-full modal-table-wrapper">
                                        <table class="w-full min-w-[500px] text-left text-sm text-slate-600 border border-slate-200 rounded-md overflow-hidden bg-white shadow-sm">
                                            <thead class="bg-slate-100 text-xs uppercase text-slate-500">
                                                <tr>
                                                    <th class="px-3 py-2 border-b">Producto</th>
                                                    <th class="px-3 py-2 border-b text-center">Cant.</th>
                                                    <th class="px-3 py-2 border-b text-right">P.Unit</th>
                                                    <th class="px-3 py-2 border-b text-center">Desc %</th>
                                                    <th class="px-3 py-2 border-b text-right">Subtotal</th>
                                                    <th class="px-3 py-2 border-b text-center">X</th>
                                                </tr>
                                            </thead>
                                            <tbody id="modal-temp-body">
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div class="border-t border-slate-200 bg-slate-50 px-6 py-4 flex justify-end gap-3 shrink-0">
                                    <div id="modal-agregar-message" class="modal-inline-message text-sm text-primary mr-auto hidden" style="align-self:center"></div>
                                    <button type="button" id="cancel-modal-agregar" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancelar</button>
                                    <button type="button" id="save-modal-agregar" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">Añadir items </button>
                                </div>
                            </div>
                        </div>

                        <script>
                            (function (){
                                const almacenOptions = @json($almacenes ?? []);
                                const initialDetalles = @json($detalles ?? []);
                                const globalRecord = @json($record ?? null);
                                window.formReadOnly = {{ ($readOnly ?? false) ? 'true' : 'false' }};
                                let isEditMode = {{ ($mode === 'edit') ? 'true' : 'false' }};
                                
                                // Expose a global function to change formReadOnly and enable/disable all controls
                                window.setFormEditMode = function(isEditable) {
                                    window.formReadOnly = !isEditable;
                                };

                                window.enableDetailControls = function() {
                                    const wrappers = document.querySelectorAll('[id^="group-wrapper-"]');
                                    wrappers.forEach((wrapper) => {
                                        wrapper.querySelectorAll('input, select, textarea, button').forEach((el) => {
                                            if (el.classList.contains('summary-total') || el.classList.contains('summary-subtotal') || el.classList.contains('summary-igv')) {
                                                return;
                                            }
                                            el.disabled = false;
                                            if (el.classList.contains('btn-remove')) {
                                                el.classList.remove('opacity-50', 'cursor-not-allowed');
                                            }
                                        });
                                    });

                                    const addButtons = [document.getElementById('btn-add-item-modal'), document.getElementById('btn-add-paquetes')];
                                    addButtons.forEach((btn) => {
                                        if (btn) {
                                            btn.disabled = false;
                                        }
                                    });
                                };
                                
                                const btnOpenModal = document.getElementById('btn-add-item-modal');
                                const modal = document.getElementById('modal-agregar-item');
                                const modalPaquetes = document.getElementById('modal-paquetes');
                                const btnAddPaquetes = document.getElementById('btn-add-paquetes');
                                const btnClosePaquetes = document.getElementById('close-modal-paquetes');
                                const btnCancelPaquetes = document.getElementById('btn-cancel-paquetes');
                                const btnSavePaquete = document.getElementById('btn-save-paquete');
                                const inputPaquetesSearch = document.getElementById('modal-paquetes-search');
                                const btnPaquetesClear = document.getElementById('modal-paquetes-clear');
                                const listPaquetes = document.getElementById('modal-paquetes-list');
                                const tbodyPaquetes = document.getElementById('modal-paquetes-tbody');
                                const previewContainerPaquetes = document.getElementById('paquete-preview-container');

                                const paquetesData = @json($paquetes ?? []);
                                let selectedPaqueteId = null;
                                let selectedPaqueteDetalles = [];
                                const btnClose = document.getElementById('close-modal-agregar');
                                const btnCancel = document.getElementById('cancel-modal-agregar');
                                const btnSave = document.getElementById('save-modal-agregar');
                                const btnAddRow = document.getElementById('btn-add-modal-row');
                                const tbody = document.getElementById('modal-temp-body');
                                const container = document.getElementById('cotizaciones-container');
                                const emptyState = document.getElementById('detalle-empty');
                                const datosGeneralesPlaceholder = document.getElementById('datos-generales-placeholder');
                                const modalSubtitle = document.getElementById('modal-subtitle');
                                
                                const selectProd = document.getElementById('modal-product-select');
                                const inpQty = document.getElementById('modal-qty');
                                const inpPrice = document.getElementById('modal-price');
                                const inpDisc = document.getElementById('modal-discount');
                                const inpCetear = document.getElementById('modal-cetear');
                                
                                if (selectProd) selectProd.addEventListener('change', function() {
                                    clearModalMessage(modal);
                                    const opt = almacenOptions.find(o => String(o.idalmacen) === String(this.value));
                                    updateCetearForOption(opt);
                                });
                                if (inpCetear) {
                                    inpCetear.addEventListener('change', function() {
                                        const opt = almacenOptions.find(o => String(o.idalmacen) === String(selectProd.value));
                                        if (!opt || !isEquipoTipo(opt.tipo_nombre)) {
                                            inpCetear.checked = false;
                                            return;
                                        }
                                        if (inpCetear.checked) {
                                            inpPrice.value = '0.00';
                                        } else {
                                            const p = parseFloat(opt.precioUnitario || opt.precio || opt.price || 0);
                                            if (!Number.isNaN(p)) inpPrice.value = p.toFixed(2);
                                        }
                                    });
                                }
                                let tomSelectInstance = null;
                                let tempItems = []; // For modal
                                const oldCotizaciones = @json(old('cotizaciones', []));
                                const groupGeneralOptions = {
                                    vigencias: @json($f_vig['optionsData'] ?? []),
                                    formasPago: @json($f_fp['optionsData'] ?? []),
                                    monedas: @json($f_mon['optionsData'] ?? []),
                                };
                                
                                if (isEditMode) {
                                    btnSave.textContent = 'Agregar Items';
                                    modalSubtitle.textContent = 'Selecciona los productos y presiona Agregar para insertarlos a tu cotización.';
                                }

                                function populateModalProductOptions() {
                                    const selectedIds = new Set(tempItems.map(item => String(item.id)));
                                    selectProd.innerHTML = '';

                                    const placeholder = document.createElement('option');
                                    placeholder.value = '';
                                    placeholder.textContent = 'Buscar producto o servicio...';
                                    selectProd.appendChild(placeholder);

                                    almacenOptions.forEach(opt => {
                                        const option = document.createElement('option');
                                        option.value = opt.idalmacen;
                                        option.textContent = normalizeLabelText(opt.label);
                                        if (selectedIds.has(String(opt.idalmacen))) {
                                            option.disabled = true;
                                            option.dataset.disabled = 'true';
                                            option.className = 'text-primary';
                                        }
                                        selectProd.appendChild(option);
                                    });
                                }

                                function refreshModalProductSelect() {
                                    const TS = window.TomSelect || (typeof TomSelect !== 'undefined' ? TomSelect : null);
                                    const selectedIds = new Set(tempItems.map(item => String(item.id)));
                                    populateModalProductOptions();
                                    selectProd.value = '';

                                    if (selectProd.tomselect && typeof selectProd.tomselect.destroy === 'function') {
                                        selectProd.tomselect.destroy();
                                    }
                                    if (tomSelectInstance && typeof tomSelectInstance.destroy === 'function') {
                                        tomSelectInstance.destroy();
                                    }
                                    tomSelectInstance = null;

                                    if (TS) {
                                        try {
                                            tomSelectInstance = new TS(selectProd, {
                                                create: false,
                                                placeholder: 'Buscar producto o servicio...',
                                                sortField: { field: "text", direction: "asc" },
                                                maxOptions: 500,
                                                controlClass: 'ts-control form-control',
                                                wrapperClass: 'ts-wrapper tom-select tom-select--compact',
                                                render: {
                                                    option: function(data, escape) {
                                                        const disabledClass = data.disabled ? ' text-slate-500 opacity-60' : '';
                                                        return `<div class="px-2 py-1 text-xs ${disabledClass}" title="${escape(data.text)}">${escape(data.text)}</div>`;
                                                    },
                                                    item: function(data, escape) {
                                                        return `<div class="truncate" title="${escape(data.text)}">${escape(data.text)}</div>`;
                                                    }
                                                },
                                                onChange: function(val) {
                                                    if (!val) return;
                                                    if (selectedIds.has(String(val))) {
                                                        if (typeof this.clear === 'function') this.clear(true);
                                                        return;
                                                    }
                                                    const opt = almacenOptions.find(o => String(o.idalmacen) === String(val));
                                                    updateCetearForOption(opt);
                                                }
                                            });
                                        } catch(e) {
                                            console.warn('[Modal] TomSelect init failed:', e);
                                        }
                                    }
                                }

                                function initTomSelect() {
                                    refreshModalProductSelect();
                                }

                                function isEquipoTipo(tipoNombre) {
                                    if (!tipoNombre) return false;
                                    const tipo = String(tipoNombre).toUpperCase();
                                    return !tipo.includes('SERVIC') && !tipo.includes('PLAN');
                                }

                                function updateCetearForOption(opt) {
                                    const enabled = Boolean(opt && isEquipoTipo(opt.tipo_nombre));
                                    if (inpCetear) {
                                        inpCetear.disabled = !enabled;
                                        if (!enabled) {
                                            inpCetear.checked = false;
                                        }
                                    }
                                    if (!opt) {
                                        if (inpPrice) inpPrice.value = '';
                                        return;
                                    }
                                    const price = parseFloat(opt.precioUnitario || opt.precio || opt.price || 0);
                                    if (enabled && inpCetear && inpCetear.checked) {
                                        inpPrice.value = '0.00';
                                    } else if (!Number.isNaN(price) && inpPrice) {
                                        inpPrice.value = price.toFixed(2);
                                    }
                                }

                                function setGroupComentario(tipo, value) {
                                    const safeTipo = getSafeTipo(tipo);
                                    const wrapper = getOrCreateGroupWrapper(tipo);
                                    const textarea = wrapper.querySelector(`textarea[name="cotizaciones[${safeTipo}][comentario]"]`);
                                    if (textarea && !textarea.value.trim()) {
                                        textarea.value = value;
                                    }
                                }

                                function escapeHtml(value) {
                                    return String(value || '').replace(/[&<>\"]/g, function (char) {
                                        return {
                                            '&': '&amp;',
                                            '<': '&lt;',
                                            '>': '&gt;',
                                            '"': '&quot;'
                                        }[char];
                                    });
                                }

                                function normalizeLabelText(label) {
                                    let text = String(label || '').trim();
                                    if (!text) return '';
                                    // Remove common trailing suffix for service labels like "- No"
                                    text = text.replace(/\s*-\s*No\s*$/i, '').trim();
                                    return text;
                                }

                                function getGroupFieldValue(safeTipo, fieldName, defaultValue = '') {
                                    const oldGroup = oldCotizaciones[safeTipo] || {};
                                    if (oldGroup[fieldName] !== undefined && oldGroup[fieldName] !== null) {
                                        return oldGroup[fieldName];
                                    }
                                    // Si existe un registro global (p. ej. copia desde otra cotización),
                                    // usar sus valores como iniciales independientemente del modo.
                                    if (globalRecord) {
                                        return globalRecord[fieldName] ?? defaultValue;
                                    }
                                    return defaultValue;
                                }

                                function renderGroupGeneralFields(safeTipo) {
                                    if (isEditMode) {
                                        return '';
                                    }
                                    const prefix = `cotizaciones[${safeTipo}][`;
                                    const suffix = ']';

                                    // Defaults por tipo: Contado (1) para todos, Dolar (2) para EQUIPAMIENTO, Sol (1) para PLANES y SERVICIOS TÉCNICOS
                                    const isEquipamiento = safeTipo === 'equipamiento';
                                    const defaultFormaPago = '1';  // Contado
                                    const defaultMoneda = isEquipamiento ? '2' : '1'; // Dolar para equipo, Sol para planes/servicios

                                    const selectedVigencia = getGroupFieldValue(safeTipo, 'vigenciaOferta_idvigenciaOferta', '');
                                    const selectedFormaPago = getGroupFieldValue(safeTipo, 'formaPago_idformaPago', defaultFormaPago);
                                    const selectedMoneda = getGroupFieldValue(safeTipo, 'moneda_idmoneda', defaultMoneda);
                                    const comentario = getGroupFieldValue(safeTipo, 'comentario', '');

                                    const vigenciaOptions = groupGeneralOptions.vigencias.map(opt => `
                                        <option value="${opt.idvigenciaOferta ?? ''}" ${String(selectedVigencia) === String(opt.idvigenciaOferta ?? '') ? 'selected' : ''}>
                                            ${escapeHtml(opt.detalle ?? opt.label ?? '')}
                                        </option>
                                    `).join('');

                                    const formaPagoOptions = groupGeneralOptions.formasPago.map(opt => `
                                        <option value="${opt.idformaPago ?? ''}" ${String(selectedFormaPago) === String(opt.idformaPago ?? '') ? 'selected' : ''}>
                                            ${escapeHtml(opt.detalle ?? opt.label ?? '')}
                                        </option>
                                    `).join('');

                                    const monedaOptions = groupGeneralOptions.monedas.map(opt => `
                                        <option value="${opt.idmoneda ?? ''}" ${String(selectedMoneda) === String(opt.idmoneda ?? '') ? 'selected' : ''}>
                                            ${escapeHtml(opt.detalle ?? opt.label ?? '')}
                                        </option>
                                    `).join('');

                                    return `
                                        <div class="p-4 border-b border-slate-200 bg-slate-50">
                                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 lg:grid-cols-4">
                                                <div class="flex flex-col gap-2">
                                                    <label class="text-sm font-semibold text-slate-700">Forma de Pago</label>
                                                    <select name="${prefix}formaPago_idformaPago${suffix}" class="form-control rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 focus:border-primary focus:ring-primary" ${formReadOnly ? 'disabled' : ''}>
                                                        <option value="">Seleccione forma de pago</option>
                                                        ${formaPagoOptions}
                                                    </select>
                                                </div>
                                                <div class="flex flex-col gap-2">
                                                    <label class="text-sm font-semibold text-slate-700">Moneda</label>
                                                    <select name="${prefix}moneda_idmoneda${suffix}" class="form-control rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 focus:border-primary focus:ring-primary" ${formReadOnly ? 'disabled' : ''}>
                                                        <option value="">Seleccione moneda</option>
                                                        ${monedaOptions}
                                                    </select>
                                                </div>
                                                <div class="flex flex-col gap-2">
                                                    <label class="text-sm font-semibold text-slate-700">Comentario</label>
                                                    <textarea name="${prefix}comentario${suffix}" class="form-control rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 focus:border-primary focus:ring-primary min-h-[90px]" ${formReadOnly ? 'disabled' : ''}>${escapeHtml(comentario)}</textarea>
                                                </div>
                                            </div>
                                        </div>
                                    `;
                                }

                                function initGroupTomSelects(container) {
                                    const TS = window.TomSelect || (typeof TomSelect !== 'undefined' ? TomSelect : null);
                                    if (!TS) return;
                                    container.querySelectorAll('select.tom-select').forEach((select) => {
                                        if (select.tomselect || select.tomSelect || select._tomselect) return;
                                        try {
                                            new TS(select, {
                                                create: false,
                                                allowEmptyOption: true,
                                                maxOptions: 500,
                                                sortField: { field: 'text', direction: 'asc' },
                                                controlClass: 'ts-control form-control',
                                                wrapperClass: 'ts-wrapper tom-select tom-select--compact',
                                                placeholder: select.dataset.placeholder || select.getAttribute('data-placeholder') || '',
                                            });
                                        } catch (e) {
                                            console.warn('[TomSelect] initGroupTomSelects failed:', e);
                                        }
                                    });
                                }

                                // ==========================================
                                // LOGICA MODAL PAQUETES
                                // ==========================================
                                function filterPaquetes(query) {
                                    const term = String(query || '').trim().toLowerCase();
                                    return paquetesData.filter(p => {
                                        const text = `${p.nombre || ''} ${p.descripcion || ''}`.toLowerCase();
                                        return text.includes(term);
                                    });
                                }

                                function renderPaquetePreview(paqueteId) {
                                    selectedPaqueteId = paqueteId;
                                    listPaquetes.querySelectorAll('button[data-package-id]').forEach(btn => {
                                        const isSelected = btn.getAttribute('data-package-id') === String(paqueteId);
                                        btn.classList.toggle('border-primary', isSelected);
                                        btn.classList.toggle('border-slate-200', !isSelected);
                                        btn.classList.toggle('bg-white', !isSelected);
                                    });

                                    const pq = paquetesData.find(p => String(p.id) === String(paqueteId));
                                    if (!pq) return;

                                    
                                    selectedPaqueteDetalles = Array.isArray(pq.detalles) ? pq.detalles : (Array.isArray(pq.items) ? pq.items : []);
                                    if (selectedPaqueteDetalles.length > 0) {
                                        let html = '';
                                        selectedPaqueteDetalles.forEach(d => {
                                            const label = d.label || d.nombre || d.producto || 'Ítem';
                                            const priceStr = parseFloat(d.precio || d.price || d.precioUnitario || 0).toFixed(2);
                                            const tipoNombre = d.tipo_nombre || d.tipo || 'Sin tipo';
                                            const badgeColor = tipoNombre.toUpperCase().includes('PLAN')
                                                ? 'bg-blue-100 text-blue-700'
                                                : tipoNombre.toUpperCase().includes('SERVIC')
                                                    ? 'bg-orange-100 text-orange-700'
                                                    : 'bg-emerald-100 text-emerald-700';
                                            html += `
                                                <tr class="hover:bg-slate-50 transition-colors">
                                                    <td class="px-4 py-2 text-slate-800 font-medium">${label}</td>
                                                    <td class="px-2 py-2 text-center text-slate-600">S/ ${priceStr}</td>
                                                </tr>`;
                                        });
                                        tbodyPaquetes.innerHTML = html;
                                        previewContainerPaquetes.classList.remove('hidden');
                                    } else {
                                        tbodyPaquetes.innerHTML = `<tr><td colspan="2" class="px-4 py-4 text-center text-sm text-slate-500 italic">Este paquete no tiene ítems configurados.</td></tr>`;
                                        previewContainerPaquetes.classList.remove('hidden');
                                    }
                                }

                                function renderPaquetesList(query = '') {
                                    const paquetes = filterPaquetes(query);
                                    if (!paquetes.length) {
                                        listPaquetes.innerHTML = '<div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500 text-center">No se encontraron paquetes.</div>';
                                        return;
                                    }

                                    listPaquetes.innerHTML = paquetes.map(p => {
                                        const isActive = String(p.id) === String(selectedPaqueteId);
                                        const activeClass = isActive ? 'border-red-500 bg-red-50' : 'border-slate-200 bg-white';
                                        const description = p.descripcion ? `<p class="text-xs text-slate-500 mt-1 line-clamp-2">${p.descripcion}</p>` : '';
                                        const count = Array.isArray(p.detalles) ? p.detalles.length : (Array.isArray(p.items) ? p.items.length : 0);
                                        return `
                                            <button type="button" class="w-full text-left rounded-2xl border ${activeClass} p-4 transition hover:border-slate-400 hover:bg-slate-50 focus:ring-2 focus:ring-primary focus:outline-none" data-package-id="${p.id}">
                                                <div class="flex items-start justify-between gap-4">
                                                    <div class="min-w-0">
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <span class="text-sm font-semibold text-slate-900 truncate">${p.nombre || 'Paquete sin nombre'}</span>
                                                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500">${count} ítem${count === 1 ? '' : 's'}</span>
                                                        </div>
                                                        ${description}
                                                    </div>
                                                </div>
                                            </button>`;
                                    }).join('');

                                    listPaquetes.querySelectorAll('button[data-package-id]').forEach(btn => {
                                        btn.addEventListener('click', () => renderPaquetePreview(btn.getAttribute('data-package-id')));
                                    });
                                }

                                function openModalPaquetes() {
                                    if (window.formReadOnly) return;
                                    if (modalPaquetes.parentNode !== document.body) {
                                        document.body.appendChild(modalPaquetes);
                                    }
                                    modalPaquetes.classList.remove('hidden');
                                    modalPaquetes.classList.add('flex');
                                    document.body.style.overflow = 'hidden';

                                    selectedPaqueteId = null;
                                    selectedPaqueteDetalles = [];
                                    inputPaquetesSearch.value = '';
                                    clearModalMessage(modalPaquetes);
                                    previewContainerPaquetes.classList.add('hidden');
                                    renderPaquetesList('');
                                    inputPaquetesSearch.focus();
                                }

                                function closeModalPaquetes() {
                                    modalPaquetes.classList.add('hidden');
                                    modalPaquetes.classList.remove('flex');
                                    document.body.style.overflow = '';
                                }

                                if (inputPaquetesSearch) {
                                    inputPaquetesSearch.addEventListener('input', function() {
                                        renderPaquetesList(this.value);
                                    });
                                }

                                if (btnPaquetesClear) {
                                    btnPaquetesClear.addEventListener('click', function() {
                                        if (inputPaquetesSearch) {
                                            inputPaquetesSearch.value = '';
                                            renderPaquetesList('');
                                            inputPaquetesSearch.focus();
                                        }
                                    });
                                }

                                function recalcGroup(rawId) {
                                    recalcGroupTotals(rawId);
                                }

                                function recalcGrandTotal() {
                                    document.querySelectorAll('[id^="group-wrapper-"]').forEach(wrapper => {
                                        const safeTipo = wrapper.id.replace('group-wrapper-', '');
                                        recalcGroupTotals(safeTipo);
                                    });
                                }

                                if (btnAddPaquetes) btnAddPaquetes.addEventListener('click', openModalPaquetes);
                                if (btnClosePaquetes) btnClosePaquetes.addEventListener('click', closeModalPaquetes);
                                if (btnCancelPaquetes) btnCancelPaquetes.addEventListener('click', closeModalPaquetes);

                                if (btnSavePaquete) {
                                    btnSavePaquete.addEventListener('click', () => {
                                        if (selectedPaqueteDetalles.length === 0) {
                                                setModalMessage(modalPaquetes, 'No hay ítems para añadir en el paquete seleccionado.');
                                                return;
                                            }

                                        let addedCount = 0;
                                        let omittedCount = 0;

                                        // For each item in the package, create a temp record and append to groups
                                        selectedPaqueteDetalles.forEach(item => {
                                            const qty = 1; // Default qty 1 for package items
                                            const prc = parseFloat(item.precio || 0);
                                            const dsc = 0; // Default discount 0
                                            const st = (qty * prc) * (1 - dsc/100);

                                            const record = {
                                                id: item.idalmacen,
                                                qty: qty,
                                                price: prc,
                                                desc: dsc,
                                                subtotal: st
                                            };
                                             
                                            const gKey = getGroupKey(item.tipo_nombre || 'Sin tipo');
                                            const wasAdded = addRowToGroup(gKey, record);
                                            if (wasAdded) {
                                                    addedCount += 1;
                                                } else {
                                                    omittedCount += 1;
                                                }
                                        });
                                        // Si no se añadió ningún ítem
                                        if (addedCount === 0) {
                                            setModalMessage(modalPaquetes, 'Todos los productos del paquete ya están en el detalle. No se añadió nada.', 0, 'error');
                                            const cancelBtn = document.getElementById('btn-cancel-paquetes');
                                            if (cancelBtn) cancelBtn.focus();
                                            return;
                                        }

                                        // Recalculate totals si se añadió al menos uno
                                        if (addedCount > 0) {
                                            const allWrappers = document.querySelectorAll('[id^="group-wrapper-"]');
                                            allWrappers.forEach(w => {
                                                const rawId = w.id.replace('group-wrapper-', '');
                                                recalcGroup(rawId);
                                            });
                                            recalcGrandTotal();
                                        }

                                        // Parcial: algunos añadidos y algunos omitidos -> mensaje informativo y auto-cierre
                                        if (omittedCount > 0) {
                                            setModalMessage(modalPaquetes, `Se añadieron ${addedCount} producto${addedCount === 1 ? '' : 's'}. ${omittedCount} ya existían y se omitieron.`, 2000, 'info');
                                            setTimeout(() => closeModalPaquetes(), 2100);
                                            return;
                                        }

                                        // Todos añadidos: cerramos inmediatamente
                                        closeModalPaquetes();
                                    });
                                }
                                // ==========================================


                                function openModal() {
                                    if (window.formReadOnly) return;
                                    // Move modal to body to ensure position:fixed covers full viewport
                                    if (modal.parentNode !== document.body) {
                                        document.body.appendChild(modal);
                                    }
                                    modal.classList.remove('hidden');
                                    modal.classList.add('flex');
                                    document.body.style.overflow = 'hidden';
                                    clearModalMessage(modal);
                                    initTomSelect();
                                    tempItems = [];
                                    renderTempTable();
                                }

                                function closeModal() {
                                    modal.classList.add('hidden');
                                    modal.classList.remove('flex');
                                    document.body.style.overflow = '';
                                    
                                    // Limpiar los campos del modal
                                    if (selectProd) {
                                        selectProd.value = '';
                                        if (selectProd.tomselect) {
                                            selectProd.tomselect.clear(true);
                                        }
                                    }
                                    if (inpQty) inpQty.value = '1';
                                    if (inpPrice) inpPrice.value = '';
                                    if (inpDisc) inpDisc.value = '0';
                                    if (inpCetear) inpCetear.checked = false;
                                }

                                

                                btnOpenModal.addEventListener('click', openModal);
                                btnClose.addEventListener('click', closeModal);
                                btnCancel.addEventListener('click', closeModal);

                                btnAddRow.addEventListener('click', function() {
                                    const id = selectProd.value;
                                    if(!id) {
                                        if (selectProd) selectProd.focus();
                                        return;
                                    }

                                    if (tempItems.some(item => String(item.id) === String(id))) {
                                        if (selectProd) selectProd.focus();
                                        return;
                                    }

                                    if (isProductAlreadyInDetail(id)) {
                                        setModalMessage(modal, 'Este producto/servicio ya está agregado en el detalle de la cotización.');
                                        if (selectProd) selectProd.focus();
                                        return;
                                    }

                                    const opt = almacenOptions.find(o => o.idalmacen == id);
                                    if(!opt) return;

                                    const qty = parseFloat(inpQty.value) || 1;
                                    const price = parseFloat(inpPrice.value) || 0;
                                    const desc = parseFloat(inpDisc.value) || 0;
                                    const subtotal = (qty * price * (1 - desc/100)).toFixed(2);

                                    tempItems.push({
                                        id: id,
                                        label: normalizeLabelText(opt.label),
                                        tipo_nombre: opt.tipo_nombre,
                                        qty: qty,
                                        price: price,
                                        desc: desc,
                                        subtotal: subtotal,
                                        cetear: Boolean(inpCetear && inpCetear.checked && isEquipoTipo(opt.tipo_nombre))
                                    });

                                    renderTempTable();
                                    if(tomSelectInstance) tomSelectInstance.clear();
                                    updateCetearForOption(null);
                                    inpQty.value = 1;
                                    inpPrice.value = '';
                                    inpDisc.value = 0;
                                });

                                function renderTempTable() {
                                    tbody.innerHTML = '';
                                    tempItems.forEach((item, index) => {
                                        const tr = document.createElement('tr');
                                        tr.innerHTML = `
                                            <td class="px-3 py-2 border-b text-xs">${item.label}</td>
                                            <td class="px-3 py-2 border-b text-center text-xs">${item.qty}</td>
                                            <td class="px-3 py-2 border-b text-right text-xs">${Number(item.price).toFixed(2)}</td>
                                            <td class="px-3 py-2 border-b text-center text-xs">${item.desc}%</td>
                                            <td class="px-3 py-2 border-b text-right font-medium text-xs">${item.subtotal}</td>
                                            <td class="px-3 py-2 border-b text-center">
                                                <button type="button" class="text-red-500 hover:text-red-700" onclick="window.removeTempItem(${index})">X</button>
                                            </td>
                                        `;
                                        tbody.appendChild(tr);
                                    });
                                    if (selectProd) {
                                        refreshModalProductSelect();
                                    }
                                }

                                window.removeTempItem = function(index) {
                                    tempItems.splice(index, 1);
                                    renderTempTable();
                                };

                                // ====== CORE MULTI-TABLE LOGIC ======

                                function setModalMessage(modalEl, msg, timeout = 3500, variant = 'error') {
                                    try {
                                        if (!modalEl) return;
                                        const el = modalEl.querySelector('.modal-inline-message');
                                        if (!el) return;
                                        // Normalize classes for color variants
                                        el.classList.remove('text-primary', 'text-primary', 'text-primary');
                                        if (variant === 'error') el.classList.add('text-primary');
                                        else if (variant === 'info') el.classList.add('text-primary');
                                        else if (variant === 'success') el.classList.add('text-primary');

                                        el.textContent = msg || '';
                                        el.classList.remove('hidden');
                                        if (el._hideTimeout) clearTimeout(el._hideTimeout);
                                        if (timeout && timeout > 0) {
                                            el._hideTimeout = setTimeout(() => {
                                                el.classList.add('hidden');
                                                el.textContent = '';
                                                delete el._hideTimeout;
                                            }, timeout);
                                        }
                                    } catch (e) {
                                        // ignore
                                    }
                                }

                                function clearModalMessage(modalEl) {
                                    try {
                                        if (!modalEl) return;
                                        const el = modalEl.querySelector('.modal-inline-message');
                                        if (!el) return;
                                        if (el._hideTimeout) { clearTimeout(el._hideTimeout); delete el._hideTimeout; }
                                        el.classList.add('hidden');
                                        el.textContent = '';
                                    } catch (e) {}
                                }

                                function getExistingDetailProductIds() {
                                    const ids = new Set();
                                    document.querySelectorAll('.row-product').forEach((input) => {
                                        const value = String(input.value || '').trim();
                                        if (value) {
                                            ids.add(value);
                                        }
                                    });
                                    return ids;
                                }

                                function isProductAlreadyInDetail(productId) {
                                    if (productId === null || productId === undefined || productId === '') {
                                        return false;
                                    }
                                    const normalizedId = String(productId).trim();
                                    if (!normalizedId) {
                                        return false;
                                    }
                                    return getExistingDetailProductIds().has(normalizedId);
                                }

                                function getGroupKey(tipo_nombre) {
                                    if (isEditMode) return 'UNICA';
                                    let tipo = (tipo_nombre || '').toUpperCase().trim();
                                    if (tipo.includes('SERVIC')) return 'SERVICIOS TÉCNICOS';
                                    if (tipo.includes('PLAN')) return 'PLANES';
                                    // EQUIPAMIENTO is the default (includes equip, video, cámara, etc.)
                                    return 'EQUIPAMIENTO';
                                }

                                function getSafeTipo(tipo) {
                                    if (isEditMode) return 'unica';
                                    return tipo.replace(/[^a-zA-Z0-9]/g, '_').toLowerCase();
                                }

                                // This creates or fetches the wrapper for a group
                                function getOrCreateGroupWrapper(tipo) {
                                    const safeTipo = getSafeTipo(tipo);
                                    let wrapper = document.getElementById(`group-wrapper-${safeTipo}`);
                                    if (wrapper) return wrapper;
                                    
                                    emptyState.style.display = 'none';

                                    wrapper = document.createElement('div');
                                    wrapper.id = `group-wrapper-${safeTipo}`;
                                    wrapper.className = 'border border-slate-200 rounded-md bg-white shadow-sm overflow-hidden';
                                    
                                    const headerTitle = isEditMode ? 'Detalle de la Cotización' : `Cotización: ${tipo}`;
                                    const sumPrefix = isEditMode ? '' : `cotizaciones[${safeTipo}][`;
                                    const sumSuffix = isEditMode ? '' : ']';

                                    // Default values from DB if editing, else 0
                                    let initDescGlobal = isEditMode && globalRecord ? (globalRecord.descuento || 0) : 0;
                                    let initIgvGlobal = isEditMode && globalRecord ? (globalRecord.igv || 18) : 18;
                                    
                                    wrapper.innerHTML = `
                                        <div class="bg-slate-100 px-4 py-3 border-b border-slate-200 font-bold text-slate-800 flex justify-between items-center">
                                            <span>${headerTitle}</span>
                                            ${!isEditMode ? `<input type="hidden" name="cotizaciones[${safeTipo}][tipo_nombre]" value="${tipo}">` : ''}
                                        </div>
                                        <div class="p-0 overflow-x-auto">
                                            <table class="w-full text-left text-sm text-slate-600 min-w-[800px]">
                                                <thead class="bg-slate-50 text-[11px] font-bold uppercase tracking-[0.1em] text-slate-500 border-b border-slate-200">
                                                    <tr>
                                                        <th class="px-4 py-3 w-[45%]">Producto / Servicio</th>
                                                        <th class="px-2 py-3 text-center w-[8%]">Cant.</th>
                                                        <th class="px-2 py-3 text-center w-[15%]">Precio Unit.</th>
                                                        <th class="px-2 py-3 text-center w-[12%]">Desc. %</th>
                                                        <th class="px-4 py-3 text-center w-[15%]">Subtotal</th>
                                                        <th class="px-4 py-3 text-center w-[5%]">X</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="group-tbody-${safeTipo}" data-group="${safeTipo}">
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="bg-slate-50 px-4 py-2 border-t border-slate-200">
                                            <div class="grid grid-cols-2 gap-4 sm:flex sm:flex-row sm:justify-end sm:gap-6 w-full">
                                                <div class="flex flex-col gap-1 w-full sm:w-32">
                                                    <label class="text-xs text-slate-500 tracking-wider">Subtotal</label>
                                                    <input type="text" name="${sumPrefix}subtotal${sumSuffix}" readonly class="form-control text-sm font-medium bg-transparent px-2 py-1 text-right h-8 summary-subtotal" value="0.00">
                                                </div>
                                                <div class="flex flex-col gap-1 w-full sm:w-32">
                                                    <label class="text-xs text-slate-500 tracking-wider">Descuento Global (%)</label>
                                                    <input type="number" step="0.01" name="${sumPrefix}descuento${sumSuffix}" class="form-control text-sm text-right px-2 py-1 h-8 summary-desc-global" value="${initDescGlobal}" ${formReadOnly ? 'disabled' : ''}>
                                                </div>
                                                <div class="flex flex-col gap-1 w-full sm:w-32">
                                                    <label class="text-xs text-slate-500 tracking-wider">IGV (%)</label>
                                                    <input type="text" name="${sumPrefix}igv${sumSuffix}" readonly class="form-control text-sm font-medium bg-transparent px-2 py-1 text-right h-8 summary-igv" value="${initIgvGlobal}">
                                                </div>
                                                <div class="flex flex-col gap-1 w-full sm:w-32">
                                                    <label class="text-xs text-slate-700 tracking-wider">Total</label>
                                                    <input type="text" name="${sumPrefix}total${sumSuffix}" readonly class="form-control text-base font-bold text-emerald-700 bg-transparent px-2 py-1 text-right h-8 summary-total" value="0.00">
                                                </div>
                                            </div>
                                        </div>
                                        ${renderGroupGeneralFields(safeTipo)}
                                    `;
                                    container.appendChild(wrapper);
                                    if (datosGeneralesPlaceholder) {
                                        datosGeneralesPlaceholder.classList.add('hidden');
                                    }
                                    initGroupTomSelects(wrapper);

                                    // Bind global discount listener
                                    const inpDescG = wrapper.querySelector('.summary-desc-global');
                                    inpDescG.addEventListener('input', function() { recalcGroupTotals(safeTipo); });

                                    return wrapper;
                                }

                                // Adds a fully editable row to a group's table
                                function addRowToGroup(tipo, itemData) {
                                    const safeTipo = getSafeTipo(tipo);
                                    getOrCreateGroupWrapper(tipo);
                                    const tbody = document.getElementById(`group-tbody-${safeTipo}`);
                                    const productId = itemData && (itemData.id ?? itemData.almacen_idalmacen ?? itemData.producto_idproducto ?? '');
                                    const normalizedId = productId === null || productId === undefined || productId === '' ? '' : String(productId).trim();

                                    if (normalizedId && isProductAlreadyInDetail(normalizedId)) {
                                        return false;
                                    }
                                    
                                    const itemLabel = normalizeLabelText(itemData.label || itemData.producto || itemData.nombre || (() => {
                                        const opt = almacenOptions.find(o => String(o.idalmacen) === String(itemData.id));
                                        return opt ? opt.label : '';
                                    })());

                                    const tr = document.createElement('tr');
                                    tr.className = 'border-b border-slate-100 hover:bg-slate-50/50 transition-colors group-row';
                                    
                                    tr.innerHTML = `
                                        <td class="px-4 py-2">
                                            <div class="w-full">
                                                <input type="text" readonly class="form-control row-product-label w-full truncate overflow-hidden text-xs text-slate-800 bg-slate-50 border border-slate-200 px-2 py-2" value="${escapeHtml(itemLabel)}" title="${escapeHtml(itemLabel)}">
                                                <input type="hidden" class="row-product" value="${itemData.id || ''}">
                                            </div>
                                        </td>
                                        <td class="px-2 py-2 text-center">
                                            <input type="number" min="0" step="1" class="form-control row-qty text-center h-9 px-1" value="${itemData.qty || 1}" ${window.formReadOnly ? 'disabled' : ''}>
                                        </td>
                                        <td class="px-2 py-2 text-right">
                                            <input type="number" min="0" step="0.01" class="form-control row-price text-right h-9 px-1" value="${Number(itemData.price || 0).toFixed(2)}" ${window.formReadOnly ? 'disabled' : ''}>
                                        </td>
                                        <td class="px-2 py-2 text-center">
                                            <input type="number" min="0" step="0.01" class="form-control row-desc text-center h-9 px-1" value="${itemData.desc || 0}" ${window.formReadOnly ? 'disabled' : ''}>
                                        </td>
                                        <td class="px-4 py-2 text-right">
                                            <input type="text" readonly class="form-control row-subtotal text-right h-9 bg-slate-100 border-none font-medium px-2" value="${Number(itemData.subtotal || 0).toFixed(2)}">
                                        </td>
                                        <td class="px-4 py-2 text-center">
                                            <button type="button" class="btn-remove text-red-500 hover:text-red-700 bg-white border border-red-200 rounded p-1.5 hover:bg-red-50 transition-colors" ${window.formReadOnly ? 'disabled' : ''}>
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                                            </button>
                                        </td>
                                    `;
                                    tbody.appendChild(tr);

                                    // Init inputs and logic for this row
                                    const sel = tr.querySelector('.row-product');
                                    const iQty = tr.querySelector('.row-qty');
                                    const iPrice = tr.querySelector('.row-price');
                                    const iDesc = tr.querySelector('.row-desc');
                                    const rmBtn = tr.querySelector('.btn-remove');

                                    const onInput = () => recalcRow(tr, safeTipo);
                                    iQty.addEventListener('input', onInput);
                                    iPrice.addEventListener('input', onInput);
                                    iDesc.addEventListener('input', onInput);

                                    rmBtn.addEventListener('click', function() {
                                        if (window.formReadOnly) return;
                                        tr.remove();
                                        checkEmptyGroup(safeTipo);
                                        reindexGroup(safeTipo);
                                        recalcGroupTotals(safeTipo);
                                    });

                                    if (window.formReadOnly) {
                                        iQty.disabled = iPrice.disabled = iDesc.disabled = rmBtn.disabled = true;
                                    }

                                    recalcRow(tr, safeTipo);
                                    reindexGroup(safeTipo);
                                    return true;
                                }

                                function checkEmptyGroup(safeTipo) {
                                    const tbody = document.getElementById(`group-tbody-${safeTipo}`);
                                    if (tbody && tbody.children.length === 0) {
                                        const wrapper = document.getElementById(`group-wrapper-${safeTipo}`);
                                        if (wrapper) wrapper.remove();
                                    }
                                    const anyWrapper = container.querySelector('[id^="group-wrapper-"]');
                                    if (!anyWrapper) {
                                        emptyState.style.display = 'block';
                                        if (datosGeneralesPlaceholder) {
                                            datosGeneralesPlaceholder.classList.remove('hidden');
                                        }
                                    }
                                }

                                function recalcRow(tr, safeTipo) {
                                    const qty = parseFloat(tr.querySelector('.row-qty').value) || 0;
                                    const price = parseFloat(tr.querySelector('.row-price').value) || 0;
                                    const desc = parseFloat(tr.querySelector('.row-desc').value) || 0;
                                    const subtotal = qty * price * (1 - desc/100);
                                    tr.querySelector('.row-subtotal').value = subtotal.toFixed(2);
                                    recalcGroupTotals(safeTipo);
                                }

                                function reindexGroup(safeTipo) {
                                    const tbody = document.getElementById(`group-tbody-${safeTipo}`);
                                    if(!tbody) return;
                                    
                                    const rows = tbody.querySelectorAll('.group-row');
                                    const inputPrefix = isEditMode ? 'detalle' : `cotizaciones[${safeTipo}][detalle]`;
                                    
                                    rows.forEach((row, i) => {
                                        // Product select
                                        const sel = row.querySelector('.row-product');
                                        if(sel) sel.name = `${inputPrefix}[${i}][almacen_idalmacen]`;
                                        
                                        // Qty
                                        const iQty = row.querySelector('.row-qty');
                                        if(iQty) iQty.name = `${inputPrefix}[${i}][cantidad]`;
                                        
                                        // Price
                                        const iPrice = row.querySelector('.row-price');
                                        if(iPrice) iPrice.name = `${inputPrefix}[${i}][precioUnitario]`;
                                        
                                        // Desc
                                        const iDesc = row.querySelector('.row-desc');
                                        if(iDesc) iDesc.name = `${inputPrefix}[${i}][descuento]`;
                                        
                                        // Total
                                        const iSub = row.querySelector('.row-subtotal');
                                        if(iSub) iSub.name = `${inputPrefix}[${i}][total]`; // Note: backend expects 'total' per row
                                    });
                                }

                                function enableDetailControls() {
                                    const wrappers = document.querySelectorAll('[id^="group-wrapper-"]');
                                    wrappers.forEach((wrapper) => {
                                        wrapper.querySelectorAll('input, select, textarea, button').forEach((el) => {
                                            if (el.classList.contains('summary-total') || el.classList.contains('summary-subtotal') || el.classList.contains('summary-igv')) {
                                                return;
                                            }
                                            el.disabled = false;
                                            if (el.classList.contains('btn-remove')) {
                                                el.classList.remove('opacity-50', 'cursor-not-allowed');
                                            }
                                        });
                                    });

                                    const addButtons = [document.getElementById('btn-add-item-modal'), document.getElementById('btn-add-paquetes')];
                                    addButtons.forEach((btn) => {
                                        if (btn) {
                                            btn.disabled = false;
                                        }
                                    });
                                }

                                function recalcGroupTotals(safeTipo) {
                                    const wrapper = document.getElementById(`group-wrapper-${safeTipo}`);
                                    if(!wrapper) return;
                                    const tbody = wrapper.querySelector('tbody');
                                    if(!tbody) return;

                                    let rawSubtotal = 0;
                                    tbody.querySelectorAll('.row-subtotal').forEach(el => {
                                        rawSubtotal += parseFloat(el.value) || 0;
                                    });

                                    const inpSub = wrapper.querySelector('.summary-subtotal');
                                    const inpDescG = wrapper.querySelector('.summary-desc-global');
                                    const inpIgv = wrapper.querySelector('.summary-igv');
                                    const inpTotal = wrapper.querySelector('.summary-total');

                                    const descGlobalPercent = parseFloat(inpDescG.value) || 0;
                                    const igvPercent = parseFloat(inpIgv.value) || 18;

                                    const descAmount = rawSubtotal * (descGlobalPercent / 100);
                                    const baseNeto = rawSubtotal - descAmount;
                                    const igvAmount = baseNeto * (igvPercent / 100);
                                    const finalTotal = baseNeto + igvAmount;

                                    if(inpSub) inpSub.value = rawSubtotal.toFixed(2);
                                    if(inpTotal) inpTotal.value = finalTotal.toFixed(2);

                                    // Sync hidden inputs for edit mode
                                    if (isEditMode) {
                                        const hSub = document.getElementById('edit-hidden-subtotal');
                                        const hDesc = document.getElementById('edit-hidden-descuento');
                                        const hIgv = document.getElementById('edit-hidden-igv');
                                        const hTotal = document.getElementById('edit-hidden-total');
                                        if(hSub) hSub.value = rawSubtotal.toFixed(2);
                                        if(hDesc && inpDescG) hDesc.value = inpDescG.value;
                                        if(hIgv) hIgv.value = igvPercent.toFixed(2);
                                        if(hTotal) hTotal.value = finalTotal.toFixed(2);
                                    }
                                }

                                // Handle save from modal
                                btnSave.addEventListener('click', function() {
                                    if(tempItems.length === 0) { setModalMessage(modal, 'No hay items seleccionados.'); return; }
                                    let duplicateCount = 0;
                                    const updatedComentarios = new Set();
                                    tempItems.forEach(item => {
                                        const tipo = getGroupKey(item.tipo_nombre);
                                        const wasAdded = addRowToGroup(tipo, item);
                                        if (item.cetear && !updatedComentarios.has(tipo)) {
                                            setGroupComentario(tipo, 'Comodato');
                                            updatedComentarios.add(tipo);
                                        }
                                        if (!wasAdded) {
                                            duplicateCount += 1;
                                        }
                                    });
                                    if (duplicateCount > 0) {
                                        setModalMessage(modal, 'Algunos productos ya estaban agregados y no se incluyeron nuevamente.');
                                    }
                                    closeModal();
                                });

                                // Initialize from server side (edit mode or validation failed redirect)
                                if (initialDetalles.length > 0) {
                                    // Map them and add
                                    initialDetalles.forEach(d => {
                                        const almacenId = d.almacen_idalmacen || d.almacen;
                                        const opt = almacenOptions.find(o => o.idalmacen == almacenId);
                                        
                                        const tipo = getGroupKey(opt ? opt.tipo_nombre : '');
                                        
                                        const itemData = {
                                            id: almacenId,
                                            qty: parseFloat(d.cantidad) || 0,
                                            price: parseFloat(d.precioUnitario) || 0,
                                            desc: parseFloat(d.descuento) || 0,
                                            subtotal: parseFloat(d.total) || 0
                                        };
                                        addRowToGroup(tipo, itemData);
                                    });
                                } else if (oldCotizaciones && Object.keys(oldCotizaciones).length > 0) {
                                    Object.keys(oldCotizaciones).forEach(tipo => {
                                        const group = oldCotizaciones[tipo];
                                        if (group && group.detalle && Array.isArray(group.detalle)) {
                                            group.detalle.forEach(d => {
                                                const almacenId = d.almacen_idalmacen;
                                                const itemData = {
                                                    id: almacenId,
                                                    qty: parseFloat(d.cantidad) || 0,
                                                    price: parseFloat(d.precioUnitario) || 0,
                                                    desc: parseFloat(d.descuento) || 0,
                                                    subtotal: parseFloat(d.total) || 0
                                                };
                                                addRowToGroup(tipo, itemData);
                                            });
                                        }
                                    });
                                }
                            })();
                        </script>
                        
{{-- Hidden totals for edit mode so the controller can read them --}}
                        @if(($mode ?? '') === 'edit' && $record)
                            <input type="hidden" name="subtotal" id="edit-hidden-subtotal" value="{{ old('subtotal', $record->subtotal ?? '0.00') }}">
                            <input type="hidden" name="descuento" id="edit-hidden-descuento" value="{{ old('descuento', $record->descuento ?? '0.00') }}">
                            <input type="hidden" name="igv" id="edit-hidden-igv" value="{{ old('igv', $record->igv ?? '18.00') }}">
                            <input type="hidden" name="total" id="edit-hidden-total" value="{{ old('total', $record->total ?? '0.00') }}">
                        @endif

                        <!-- BOTONES DE ACCIÓN -->
                        <div class="mt-6 flex items-center justify-end gap-2 resumen-actions">
                            @if($readOnly ?? false)
                                <!-- Modo lectura -->
                                <span id="main-save-status" class="hidden inline-flex items-center gap-2 text-xs font-medium text-slate-600">
                                    <svg class="h-4 w-4" width="25" viewBox="-2 -2 42 42" xmlns="http://www.w3.org/2000/svg" stroke="#475569">
                                        <g fill="none" fill-rule="evenodd">
                                            <g transform="translate(1 1)" stroke-width="4">
                                                <circle stroke-opacity=".5" cx="18" cy="18" r="18" />
                                                <path d="M36 18c0-9.94-8.06-18-18-18">
                                                    <animateTransform type="rotate" attributeName="transform" from="0 18 18" to="360 18 18" dur="1s" repeatCount="indefinite" />
                                                </path>
                                            </g>
                                        </g>
                                    </svg>
                                    Guardando datos...
                                </span>
                                <a href="{{ $backRoute }}" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed border-secondary text-slate-500 dark:border-darkmode-100/40 dark:text-slate-300 [&:hover:not(:disabled)]:bg-secondary/20 [&:hover:not(:disabled)]:dark:bg-darkmode-100/10" style=" border-color: #000000; color: #000000;">
                                    cancelar
                                </a>
                                <button type="button" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed bg-primary border-primary text-white dark:border-primary" id="btnEditar" {{ ($lockBlocked ?? false) ? 'disabled' : '' }}>
                                    <i data-tw-merge="" data-lucide="edit-2" class="mr-2 h-4 w-4 stroke-[1.3]"></i>
                                    Editar
                                </button>
                            @else
                                <!-- Modo edición -->
                                <span id="main-save-status" class="hidden inline-flex items-center gap-2 text-xs font-medium text-slate-600">
                                    <svg class="h-4 w-4" width="25" viewBox="-2 -2 42 42" xmlns="http://www.w3.org/2000/svg" stroke="#475569">
                                        <g fill="none" fill-rule="evenodd">
                                            <g transform="translate(1 1)" stroke-width="4">
                                                <circle stroke-opacity=".5" cx="18" cy="18" r="18" />
                                                <path d="M36 18c0-9.94-8.06-18-18-18">
                                                    <animateTransform type="rotate" attributeName="transform" from="0 18 18" to="360 18 18" dur="1s" repeatCount="indefinite" />
                                                </path>
                                            </g>
                                        </g>
                                    </svg>
                                    Guardando datos...
                                </span>
                                <a href="{{ $backRoute }}" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed border-secondary text-slate-500 dark:border-darkmode-100/40 dark:text-slate-300 [&:hover:not(:disabled)]:bg-secondary/20 [&:hover:not(:disabled)]:dark:bg-darkmode-100/10" style="border-color:#000000;color:#000000;">
                                    cancelar
                                </a>
                                <button type="submit" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed bg-primary border-primary text-white dark:border-primary">
                                    Guardar cambios
                                </button>
                            @endif
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
                <script>
                    const confirmWithModal = ({ title = 'Confirmar', message = '', submitText = 'Aceptar', cancelText = 'Cancelar' } = {}) => {
                        const modal = document.getElementById('delete-confirmation-modal');
                        if (!modal) {
                            return Promise.resolve(window.confirm(message || title));
                        }
                        const titleEl = modal.querySelector('#delete-confirmation-title');
                        const msgEl = modal.querySelector('#delete-confirmation-message');
                        const submitBtn = modal.querySelector('#delete-confirmation-submit');
                        const closeBtns = Array.from(modal.querySelectorAll('[data-delete-modal-close]'));
                        if (titleEl) titleEl.textContent = title;
                        if (msgEl) msgEl.textContent = message;
                        if (submitBtn) submitBtn.textContent = submitText;
                        modal.style.display = 'flex';
                        modal.style.justifyContent = 'center';
                        // Use center alignment on larger screens, but align to top on small devices
                        modal.style.alignItems = (window.innerWidth >= 768) ? 'center' : 'flex-start';
                        // Ensure body doesn't scroll while modal is open
                        document.body.style.overflow = 'hidden';
                        // Ensure the dialog has proper max-height and scrolling
                        const dialogEl = modal.querySelector('.modal-dialog') || modal.querySelector('> div');
                        if (dialogEl) {
                            dialogEl.style.maxHeight = 'calc(100vh - 2.5rem)';
                            dialogEl.style.overflow = 'auto';
                        }
                        return new Promise((resolve) => {
                            const cleanup = () => {
                                modal.style.display = 'none';
                                document.body.style.overflow = '';
                                closeBtns.forEach(b => b.removeEventListener('click', onCancel));
                                submitBtn.removeEventListener('click', onConfirm);
                            };
                            const onCancel = () => { cleanup(); resolve(false); };
                            const onConfirm = () => { cleanup(); resolve(true); };
                            closeBtns.forEach(b => b.addEventListener('click', onCancel));
                            submitBtn.addEventListener('click', onConfirm);
                        });
                    };
                </script>

    <div id="delete-confirmation-modal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:9999;background:rgba(0,0,0,0.8);" role="dialog" aria-modal="true" aria-labelledby="delete-confirmation-title" aria-describedby="delete-confirmation-message">
        <div class="modal-dialog">
            <button type="button" data-delete-modal-close style="position:absolute;right:16px;top:16px;height:44px;width:44px;border-radius:9999px;border:1px solid #e6e9ee;background:#fff;color:#6b7280;display:inline-flex;align-items:center;justify-content:center;" aria-label="Cerrar">
                <i data-lucide="x" style="width:16px;height:16px"></i>
            </button>
            <div class="modal-content">
                <div style="margin:0 auto 24px;display:flex;height:64px;width:64px;align-items:center;justify-content:center;border-radius:9999px;border:1px solid #ef4444;background:#fff7f7;color:#ef4444;">
                    <i data-lucide="alert-circle" style="width:22px;height:22px"></i>
                </div>
                <h2 id="delete-confirmation-title" style="font-size:22px;font-weight:600;margin:0;color:#111827;">Confirmar edición</h2>
                <p id="delete-confirmation-message" style="margin-top:12px;color:#6b7280;font-size:14px;line-height:1.6;">Estás a punto de guardar cambios en este registro.</p>
                <div id="delete-confirmation-relations" class="mt-5 hidden rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"></div>
                <div id="delete-confirmation-hint" class="mt-3 hidden text-sm text-slate-600"></div>
                <div style="margin-top:26px;display:flex;gap:12px;justify-content:flex-end;flex-wrap:wrap;align-items:center;">
                    <span id="delete-confirmation-status" class="hidden inline-flex items-center gap-2 text-xs font-medium text-slate-600">
                        <svg class="h-4 w-4" width="25" viewBox="-2 -2 42 42" xmlns="http://www.w3.org/2000/svg" stroke="#475569">
                            <g fill="none" fill-rule="evenodd">
                                <g transform="translate(1 1)" stroke-width="4">
                                    <circle stroke-opacity=".5" cx="18" cy="18" r="18" />
                                    <path d="M36 18c0-9.94-8.06-18-18-18">
                                        <animateTransform type="rotate" attributeName="transform" from="0 18 18" to="360 18 18" dur="1s" repeatCount="indefinite" />
                                    </path>
                                </g>
                            </g>
                        </svg>
                        Guardando datos...
                    </span>
                    <button type="button" data-delete-modal-close style="min-width:120px;padding:10px 18px;border-radius:10px;border:1px solid #e6e9ee;background:#ffffff;color:#374151;font-weight:600;">Cancelar</button>
                    <button type="button" id="delete-confirmation-submit" style="min-width:120px;padding:10px 18px;border-radius:10px;background:#ef4444;color:#ffffff;font-weight:600;border:none;">Guardar cambios</button>
                </div>
            </div>
        </div> 
    </div>

    <!-- Select2 removido: usando select nativo -->

    <!-- Select2 completamente removido: select nativo en uso -->

    @if($hasQuickDireccion)
        <div id="direccion-list-modal" class="fixed inset-0 hidden items-center justify-center p-4" style="z-index: 10000; background-color: rgba(0, 0, 0, 0.78);" role="dialog" aria-modal="true">
            <div class="w-full overflow-hidden rounded-md bg-white shadow-[0_24px_80px_rgba(15,23,42,0.16)] flex flex-col max-h-[85vh] modal-dialog" style="max-width: 650px;">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-6 py-4">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-800">Listado de direcciones</h3>
                        <p class="mt-1 text-sm text-slate-600">Selecciona una dirección del cliente actual para usar en la cotización.</p>
                    </div>
                    <button type="button" id="direccion-list-close" class="ml-auto rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700">
                        <span class="text-2xl leading-none" style="display:flex; align-items:center; justify-content:center; width:20px; height:20px;">×</span>
                    </button>
                </div>

                <div class="px-6 py-5 overflow-y-auto flex-1" style="min-height: 0;">
                    <div class="mb-4">
                        <input id="direccion-list-search" type="text" placeholder="Buscar dirección..." class="w-full rounded-md border border-slate-300 px-4 py-2.5 text-sm transition duration-200 focus:border-red-600 focus:ring-2 focus:ring-red-500/20" />
                    </div>

                    <div id="direccion-list-container" class="space-y-3 overflow-y-auto rounded-md border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700" style="max-height: 360px; min-height: 120px;"></div>
                    <p id="direccion-list-empty" class="hidden text-sm text-slate-500">No se encuentran direcciones para este cliente.</p>
                </div>

                <div class="border-t border-slate-200 px-6 py-4 flex justify-end">
                    <button type="button" id="direccion-list-close-footer" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-100">Cerrar</button>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const modal = document.getElementById('direccion-list-modal');
                if (modal && modal.parentElement !== document.body) {
                    document.body.appendChild(modal);
                }
                const closeBtn = document.getElementById('direccion-list-close');
                const closeFooterBtn = document.getElementById('direccion-list-close-footer');
                const listContainer = document.getElementById('direccion-list-container');
                const emptyMessage = document.getElementById('direccion-list-empty');
                const searchInput = document.getElementById('direccion-list-search');
                const buttons = document.querySelectorAll('[data-address-picker-button]');
                const direccionInput = document.getElementById('field-direccion');
                let listItems = [];

                const closeModal = () => {
                    if (!modal) return;
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                    document.body.style.overflow = '';
                };

                const renderList = (items) => {
                    if (!listContainer) return;
                    const term = String(searchInput?.value || '').trim().toLowerCase();
                    const filtered = !term
                        ? items
                        : items.filter((item) => {
                            const direccion = String(item.direccion || item.label || '').toLowerCase();
                            const tipo = String(item.tipo || '').toLowerCase();
                            const ubigeo = String(item.ubigeo_text || '').toLowerCase();
                            return direccion.includes(term) || tipo.includes(term) || ubigeo.includes(term);
                        });

                    listContainer.innerHTML = '';
                    if (!filtered.length) {
                        emptyMessage?.classList.remove('hidden');
                        return;
                    }
                    emptyMessage?.classList.add('hidden');

                    filtered.forEach((item) => {
                        const direccionText = String(item.direccion || item.label || '').trim();
                        const tipoText = String(item.tipo || '').trim();
                        const ubigeoText = String(item.ubigeo_text || '').trim();
                        const isSelected = direccionInput && String(direccionInput.value || '').trim() === direccionText;

                        const card = document.createElement('div');
                        card.className = 'rounded-md border border-slate bg-white p-4 shadow-sm mb-3';
                        if (isSelected) {
                            card.className = 'rounded-md border border-primary bg-white p-4 shadow-sm mb-3';
                        }

                        const header = document.createElement('div');
                        header.className = 'mb-2 flex items-center justify-between gap-4 w-full';
                        const title = document.createElement('div');
                        title.className = 'min-w-0 flex-1 text-sm font-semibold text-slate-900';
                        title.textContent = direccionText || 'Dirección sin etiqueta';
                        const selectBtn = document.createElement('button');
                        selectBtn.type = 'button';
                        selectBtn.className = 'modal-select-price ml-4 inline-flex items-center rounded-md border px-3 py-1 text-xs font-medium ' + (isSelected ? 'border-red-600 bg-primary text-white' : 'border-slate-200 text-slate-700 hover:bg-slate-100');
                        selectBtn.textContent = isSelected ? 'Seleccionado' : 'Seleccionar';
                        selectBtn.addEventListener('click', () => {
                            if (direccionInput) {
                                direccionInput.value = direccionText;
                                direccionInput.dispatchEvent(new Event('change', { bubbles: true }));
                            }
                            closeModal();
                        });
                        header.appendChild(title);
                        header.appendChild(selectBtn);

                        const meta = document.createElement('div');
                        meta.className = 'text-xs text-slate-500';
                        meta.innerHTML = [tipoText ? '<span>' + tipoText + '</span>' : '', ubigeoText ? '<span>' + ubigeoText + '</span>' : ''].filter(Boolean).join(' · ');

                        card.appendChild(header);
                        if (meta.textContent) {
                            card.appendChild(meta);
                        }
                        listContainer.appendChild(card);
                    });
                };

                const loadDirecciones = async (url) => {
                    if (!url) return;
                    listContainer.innerHTML = '<p class="text-xs text-slate-500">Cargando...</p>';
                    emptyMessage?.classList.add('hidden');
                    try {
                        const response = await fetch(url, { headers: { Accept: 'application/json' } });
                        const payload = await response.json();
                        listItems = Array.isArray(payload.data) ? payload.data : [];
                        renderList(listItems);
                    } catch (error) {
                        listContainer.innerHTML = '<p class="text-xs text-red-600">No se pudo cargar el listado de direcciones.</p>';
                    }
                };

                buttons.forEach((button) => {
                    button.addEventListener('click', () => {
                        const clienteField = button.dataset.clientField || 'cliente_idcliente';
                        const clienteInput = document.querySelector('[name="' + clienteField + '"]');
                        const clienteId = clienteInput ? String(clienteInput.value || '').trim() : '';
                        if (!clienteId) {
                            return;
                        }
                        const url = button.dataset.url;
                        if (!url) {
                            return;
                        }
                        let fetchUrl;
                        try {
                            const parsed = new URL(url, window.location.origin);
                            parsed.searchParams.set('cliente', clienteId);
                            fetchUrl = parsed.toString();
                        } catch (e) {
                            fetchUrl = url + (url.includes('?') ? '&' : '?') + 'cliente=' + encodeURIComponent(clienteId);
                        }
                        loadDirecciones(fetchUrl);
                        if (modal) {
                            modal.classList.remove('hidden');
                            modal.classList.add('flex');
                            document.body.style.overflow = 'hidden';
                        }
                    });
                });

                searchInput?.addEventListener('input', () => renderList(listItems));
                closeBtn?.addEventListener('click', closeModal);
                closeFooterBtn?.addEventListener('click', closeModal);
            });
        </script>
    @endif

    <div id="contact-list-modal" class="fixed inset-0 hidden items-center justify-center p-4" style="z-index: 10000; background-color: rgba(0, 0, 0, 0.78);" role="dialog" aria-modal="true">
        <div class="w-full overflow-hidden rounded-md bg-white shadow-[0_24px_80px_rgba(15,23,42,0.16)] flex flex-col max-h-[85vh] modal-dialog" style="max-width: 650px;">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-6 py-4">
                <div>
                    <h3 id="contact-list-title" class="text-lg font-semibold text-slate-800">Listado de contactos</h3>
                    <p id="contact-list-subtitle" class="mt-1 text-sm text-slate-600">Selecciona un contacto del cliente actual para usar en el formulario.</p>
                </div>
                <button type="button" id="contact-list-close" class="ml-auto rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700">
                    <span class="text-2xl leading-none" style="display:flex; align-items:center; justify-content:center; width:20px; height:20px;">×</span>
                </button>
            </div>

            <div class="px-6 py-5 overflow-y-auto flex-1" style="min-height: 0;">
                <div class="mb-4">
                    <input id="contact-list-search" type="text" placeholder="Buscar contacto..." class="w-full rounded-md border border-slate-300 px-4 py-2.5 text-sm transition duration-200 focus:border-red-600 focus:ring-2 focus:ring-red-500/20" />
                </div>

                <div id="contact-list-container" class="space-y-3 overflow-y-auto rounded-md border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700" style="max-height: 360px; min-height: 120px;"></div>
                <p id="contact-list-empty" class="hidden text-sm text-slate-500">No se encuentran contactos para este cliente.</p>
            </div>

            <div class="border-t border-slate-200 px-6 py-4 flex justify-end">
                <button type="button" id="contact-list-close-footer" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-100">Cerrar</button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modal = document.getElementById('contact-list-modal');
            if (modal && modal.parentElement !== document.body) {
                document.body.appendChild(modal);
            }
            const closeBtn = document.getElementById('contact-list-close');
            const closeFooterBtn = document.getElementById('contact-list-close-footer');
            const listContainer = document.getElementById('contact-list-container');
            const emptyMessage = document.getElementById('contact-list-empty');
            const searchInput = document.getElementById('contact-list-search');
            const titleEl = document.getElementById('contact-list-title');
            const subtitleEl = document.getElementById('contact-list-subtitle');
            const buttons = document.querySelectorAll('[data-contact-picker-button]');
            const telefonoInput = document.getElementById('field-telefono');
            const correoInput = document.getElementById('field-correo');
            let listItems = [];
            let currentContactType = null;
            let currentUrlTemplate = null;

            const closeModal = () => {
                if (!modal) return;
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                document.body.style.overflow = '';
            };

            const renderList = (items) => {
                if (!listContainer) return;
                const term = String(searchInput?.value || '').trim().toLowerCase();
                const filtered = !term
                    ? items
                    : items.filter((item) => {
                        const label = String(item.label || '').toLowerCase();
                        const correo = String(item.correo || '').toLowerCase();
                        const correo2 = String(item.correo2 || '').toLowerCase();
                        const numero = String(item.numero || '').toLowerCase();
                        const numero2 = String(item.numero2 || '').toLowerCase();
                        return label.includes(term) || correo.includes(term) || correo2.includes(term) || numero.includes(term) || numero2.includes(term);
                    });

                listContainer.innerHTML = '';
                if (!filtered.length) {
                    emptyMessage?.classList.remove('hidden');
                    return;
                }
                emptyMessage?.classList.add('hidden');

                filtered.forEach((item) => {
                    const primaryValue = currentContactType === 'correo'
                        ? String(item.correo || item.correo2 || '').trim()
                        : String(item.numero || item.numero2 || '').trim();
                    const isSelected = currentContactType === 'correo'
                        ? correoInput && String(correoInput.value || '').trim() === primaryValue
                        : telefonoInput && String(telefonoInput.value || '').trim() === primaryValue;

                    const card = document.createElement('div');
                    card.className = 'rounded-md border border-slate-200 bg-white p-4 shadow-sm mb-3';
                    if (isSelected) {
                        card.className = 'rounded-md border border-primary bg-white p-4 shadow-sm mb-3';
                    }

                    const header = document.createElement('div');
                    header.className = 'mb-2 flex items-start justify-between gap-4 w-full';
                    const title = document.createElement('div');
                    title.className = 'min-w-0 flex-1 text-sm font-semibold text-slate-900';
                    title.textContent = String(item.label || 'Contacto sin nombre');

                    const selectBtn = document.createElement('button');
                    selectBtn.type = 'button';
                    selectBtn.className = 'inline-flex items-center rounded-md border px-3 py-1 text-xs font-semibold ' + (isSelected ? 'border-primary bg-primary text-white' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-100');
                    selectBtn.textContent = isSelected ? 'Seleccionado' : 'Seleccionar';
                    selectBtn.addEventListener('click', () => {
                        if (currentContactType === 'correo' && correoInput) {
                            correoInput.value = primaryValue;
                            correoInput.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                        if (currentContactType === 'telefono' && telefonoInput) {
                            telefonoInput.value = primaryValue;
                            telefonoInput.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                        closeModal();
                    });

                    header.appendChild(title);
                    header.appendChild(selectBtn);

                    // Mostrar el valor principal (correo o teléfono) junto al título
                    const primaryValueForTitle = primaryValue;
                    // Usar el label existente o construir uno localmente si no existe
                    let baseLabel = String(item.label || buildLocalLabel(item) || 'Contacto sin nombre');
                    // Si estamos mostrando correos, eliminar posibles números agregados al label
                    if (currentContactType === 'correo') {
                        // elimina segmentos tipo " - 987456321" o " - 963 321 478" (secuencia con al menos 6 dígitos,
                        // permite espacios, guiones, paréntesis o puntos en la agrupación)
                        baseLabel = baseLabel.replace(/\s*-\s*(?=(?:.*\d){6,})[\d\-\s\.\(\)]+/g, '').trim();
                    } else if (currentContactType === 'telefono') {
                        // si mostramos teléfonos, eliminar posibles correos en el label
                        baseLabel = baseLabel.replace(/\s*-\s*[^-\s@]+@[^-\s@]+\b/g, '').trim();
                    }

                    let titleText = baseLabel;
                    // Evitar duplicar el valor si ya está presente en el label
                    if (primaryValueForTitle && !String(baseLabel).includes(primaryValueForTitle)) {
                        titleText += ' - ' + primaryValueForTitle;
                    }
                    title.textContent = titleText;

                    // Añadir sólo el encabezado (sin línea secundaria)
                    card.appendChild(header);
                    listContainer.appendChild(card);
                });
            };

            const loadContacts = async (url) => {
                if (!url) return;
                listContainer.innerHTML = '<p class="text-xs text-slate-500">Cargando...</p>';
                emptyMessage?.classList.add('hidden');
                try {
                    const response = await fetch(url, { headers: { Accept: 'application/json' } });
                    const payload = await response.json();
                    listItems = Array.isArray(payload.data) ? payload.data : [];
                    if (currentContactType === 'telefono') {
                        titleEl.textContent = 'Listado de teléfonos';
                        subtitleEl.textContent = 'Selecciona un teléfono del contacto del cliente para usar en la cotización.';
                    } else {
                        titleEl.textContent = 'Listado de correos';
                        subtitleEl.textContent = 'Selecciona un correo del contacto del cliente para usar en la cotización.';
                    }
                    renderList(listItems);
                } catch (error) {
                    listContainer.innerHTML = '<p class="text-xs text-red-600">No se pudo cargar el listado de contactos.</p>';
                }
            };

            buttons.forEach((button) => {
                button.addEventListener('click', () => {
                    const clienteField = button.dataset.clientField || 'cliente_idcliente';
                    const clienteInput = document.querySelector('[name="' + clienteField + '"]');
                    const clienteId = clienteInput ? String(clienteInput.value || '').trim() : '';
                    if (!clienteId) {
                        return;
                    }
                    currentContactType = button.dataset.contactType || 'telefono';
                    currentUrlTemplate = button.dataset.urlTemplate || '';
                    if (!currentUrlTemplate) {
                        return;
                    }
                    const url = currentUrlTemplate.replace('__CLIENTE__', encodeURIComponent(clienteId));
                    loadContacts(url);
                    if (modal) {
                        modal.classList.remove('hidden');
                        modal.classList.add('flex');
                        document.body.style.overflow = 'hidden';
                    }
                });
            });

            searchInput?.addEventListener('input', () => renderList(listItems));
            closeBtn?.addEventListener('click', closeModal);
            closeFooterBtn?.addEventListener('click', closeModal);
        });
    </script>

    @if($readOnly ?? false)
    <script>
        window.ERPRealtime = window.ERPRealtime || {};
        window.ERPRealtime.pageLockBlocked = {{ isset($lockBlocked) && $lockBlocked ? 'true' : 'false' }};
        window.ERPRealtime.currentUser ||= '{{ session('erp_auth.usuario', '') }}';

        document.getElementById('btnEditar').addEventListener('click', async function() {
            window.crudFormEditUnlocked = true;
            const lockResource = document.getElementById('erp-lock-resource')?.value || '';
            const lockId = document.getElementById('erp-lock-id')?.value || '';

            if (lockResource && lockId && window.ERPRealtime?.acquireLock) {
                this.disabled = true;
                this.classList.add('opacity-70', 'cursor-not-allowed');

                const lockGranted = await window.ERPRealtime.acquireLock(lockResource, lockId);
                if (!lockGranted) {
                    return;
                }

                window.ERPRealtime.pageLockBlocked = false;
            }

            // Habilitar todos los campos
            const fields = document.querySelectorAll('input, select, textarea');
            fields.forEach(field => {
                field.disabled = false;
            });

            // Habilitar TomSelect específicamente (instancias y wrappers)
            const tomSelectElements = document.querySelectorAll('select.tom-select');
            tomSelectElements.forEach(el => {
                try {
                    // Intenta habilitar la instancia si está adjunta al elemento
                    const inst = el.tomselect || el.tomSelect || el._tomselect || null;
                    if (inst && typeof inst.enable === 'function') {
                        inst.enable();
                    }

                    // Asegurar que el select nativo esté habilitado
                    el.disabled = false;

                    // Habilitar inputs dentro del wrapper de TomSelect (si existe)
                    const wrapper = el.nextElementSibling;
                    if (wrapper) {
                        const innerControls = wrapper.querySelectorAll('input, button, [role="combobox"]');
                        innerControls.forEach(ic => { ic.disabled = false; });
                        wrapper.classList.remove('ts-disabled', 'disabled');
                    }
                } catch (e) {
                    // noop
                }
            });

            const quickButtons = document.querySelectorAll('[data-quick-create-button]');
            quickButtons.forEach((button) => {
                button.disabled = false;
            });

            const detalleAddButton = document.getElementById('btn-add-detalle');
            if (detalleAddButton) {
                detalleAddButton.disabled = false;
            }

            document.querySelectorAll('.btn-remove').forEach((button) => {
                button.disabled = false;
                button.classList.remove('opacity-50', 'cursor-not-allowed');
            });

            // Cambiar el estado de lectura a editable
            if (window.setFormEditMode) {
                window.setFormEditMode(true);
            }

            if (window.enableDetailControls) {
                window.enableDetailControls();
            }

            if (window.updateCredentialRows) {
                window.updateCredentialRows();
            }

            if (window.syncRolePermissionsLock) {
                window.syncRolePermissionsLock();
            }

            const vistaSelectorFieldsets = document.querySelectorAll('[data-vista-selector-fieldset]');
            const syncVistaSelector = (fieldset) => {
                if (!fieldset) {
                    return;
                }

                if (fieldset.dataset.vistaSelectorReady === '1') {
                    return;
                }
                fieldset.dataset.vistaSelectorReady = '1';

                const search = fieldset.querySelector('[data-vista-search]');
                const options = Array.from(fieldset.querySelectorAll('[data-vista-option]'));
                const selectedList = fieldset.querySelector('[data-vista-selected-list]');
                const checks = Array.from(fieldset.querySelectorAll('[data-vista-checkbox]'));
                const selectAll = fieldset.querySelector('[data-vista-select-all]');
                const roleInputs = Array.from(document.querySelectorAll('input[name="role_ids[]"], input[name="role_ids"]'));
                if (!selectedList || options.length === 0) {
                    return;
                }

                const renderSelected = () => {
                    const selectedOptions = options.filter((option) => {
                        const checkbox = option.querySelector('[data-vista-checkbox]');
                        return checkbox?.checked;
                    });

                    selectedList.innerHTML = '';

                    if (selectedOptions.length === 0) {
                        const empty = document.createElement('div');
                        empty.className = 'vista-selected-empty';
                        empty.dataset.vistaEmpty = 'true';
                        empty.textContent = 'Todavía no has seleccionado ninguna vista.';
                        selectedList.appendChild(empty);
                        return;
                    }

                    selectedOptions.forEach((option) => {
                        const checkbox = option.querySelector('[data-vista-checkbox]');
                        const item = document.createElement('div');
                        item.className = 'vista-selected-row';
                        item.dataset.vistaItem = checkbox?.value || '';

                        const nameCell = document.createElement('div');
                        nameCell.className = 'vista-selected-row-name';
                        nameCell.textContent = option.dataset.vistaName || (option.querySelector('.vista-selector-option-title')?.textContent || checkbox?.value || '');

                        const detailCell = document.createElement('div');
                        detailCell.className = 'vista-selected-row-detail';
                        detailCell.textContent = String(option.dataset.vistaDetail || '').trim() || '-';

                        const stateCell = document.createElement('div');
                        stateCell.className = 'vista-selected-row-state';
                        stateCell.textContent = String(option.dataset.vistaState || '').trim() || '-';

                        item.appendChild(nameCell);
                        item.appendChild(detailCell);
                        item.appendChild(stateCell);
                        selectedList.appendChild(item);
                    });

                    if (selectAll) {
                        selectAll.checked = selectedOptions.length === options.length;
                        selectAll.indeterminate = selectedOptions.length > 0 && selectedOptions.length < options.length;
                    }
                };

                const applyFilter = () => {
                    const query = String(search?.value || '').trim().toLowerCase();
                    options.forEach((option) => {
                        const text = String(option.dataset.vistaText || '').toLowerCase();
                        option.classList.toggle('is-hidden', query !== '' && !text.includes(query));
                    });
                };

                const sortOptionsBySelection = () => {
                    const selectorList = fieldset.querySelector('[data-vista-selector-list]');
                    if (!selectorList) return;

                    const selected = [];
                    const unselected = [];

                    options.forEach((option) => {
                        const checkbox = option.querySelector('[data-vista-checkbox]');
                        if (checkbox?.checked) {
                            selected.push(option);
                        } else {
                            unselected.push(option);
                        }
                    });

                    // Reordenar: seleccionados primero, luego no seleccionados
                    const sorted = [...selected, ...unselected];
                    sorted.forEach((option) => {
                        selectorList.appendChild(option);
                    });
                };

                const syncOptionState = (option) => {
                    const checkbox = option.querySelector('[data-vista-checkbox]');
                    option.classList.toggle('is-selected', !!checkbox?.checked);
                };

                const clearRoleSelection = () => {
                    if (!checks.some((checkbox) => checkbox.checked)) {
                        return;
                    }

                    roleInputs.forEach((roleInput) => {
                        if (roleInput.checked) {
                            roleInput.checked = false;
                            roleInput.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    });
                };

                checks.forEach((checkbox) => {
                    checkbox.addEventListener('change', () => {
                        const option = checkbox.closest('[data-vista-option]');
                        if (option) {
                            syncOptionState(option);
                        }
                        sortOptionsBySelection();
                        renderSelected();
                        clearRoleSelection();
                    });
                });

                if (selectAll) {
                    selectAll.addEventListener('change', () => {
                        clearRoleSelection();
                    });
                }

                options.forEach(syncOptionState);
                if (search) {
                    search.addEventListener('input', applyFilter);
                }

                applyFilter();
                sortOptionsBySelection();
                renderSelected();
            };

            vistaSelectorFieldsets.forEach((fieldset) => {
                syncVistaSelector(fieldset);
            });

            // Encontrar y cambiar el botón original de "Editar" por uno con tipo submit de "Guardar cambios"
            const buttonsDiv = document.querySelector('.mt-6.flex.items-center.justify-end.gap-2');
            
            // Crear botón de Guardar cambios
            const guardarBtn = document.createElement('button');
            guardarBtn.type = 'submit';
            guardarBtn.className = 'transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed bg-primary border-primary text-white dark:border-primary';
            guardarBtn.textContent = 'Guardar cambios';
            
            // Insertar los botones nuevos antes de este botón (Editar)
            buttonsDiv.insertBefore(guardarBtn, this);

            // Ocultar el boton Editar original
            this.style.display = 'none';
        });
    </script>
    @endif

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const vistaSelectorFieldsets = document.querySelectorAll('[data-vista-selector-fieldset]');

            vistaSelectorFieldsets.forEach((fieldset) => {
                if (!fieldset || fieldset.dataset.vistaSelectorReady === '1') {
                    return;
                }

                fieldset.dataset.vistaSelectorReady = '1';

                const search = fieldset.querySelector('[data-vista-search]');
                const options = Array.from(fieldset.querySelectorAll('[data-vista-option]'));
                const selectedList = fieldset.querySelector('[data-vista-selected-list]');
                const checks = Array.from(fieldset.querySelectorAll('[data-vista-checkbox]'));
                const selectAll = fieldset.querySelector('[data-vista-select-all]');
                const roleInputs = Array.from(document.querySelectorAll('input[name="role_ids[]"], input[name="role_ids"]'));

                if (!selectedList || options.length === 0) {
                    return;
                }

                const getSelectedRoleVistaIds = () => {
                    const selectedRole = roleInputs.find((input) => input.checked);
                    if (!selectedRole) {
                        return [];
                    }

                    try {
                        const ids = JSON.parse(selectedRole.dataset.roleVistaIds || '[]');
                        return Array.isArray(ids) ? ids.map((id) => String(id)) : [];
                    } catch (error) {
                        return [];
                    }
                };

                const syncSelectedVistasFromRole = () => {
                    const selectedIds = getSelectedRoleVistaIds();
                    const selectedSet = new Set(selectedIds);

                    checks.forEach((checkbox) => {
                        const shouldCheck = selectedSet.has(String(checkbox.value));
                        checkbox.checked = shouldCheck;
                        const option = checkbox.closest('[data-vista-option]');
                        if (option) {
                            option.classList.toggle('is-selected', shouldCheck);
                        }
                    });

                    if (selectAll) {
                        const checkedCount = checks.filter((checkbox) => checkbox.checked).length;
                        selectAll.checked = checkedCount === checks.length;
                        selectAll.indeterminate = checkedCount > 0 && checkedCount < checks.length;
                    }

                    sortOptionsBySelection();
                    renderSelected();
                };

                const renderSelected = () => {
                    const selectedOptions = options.filter((option) => {
                        const checkbox = option.querySelector('[data-vista-checkbox]');
                        return checkbox?.checked;
                    });

                    selectedList.innerHTML = '';

                    if (selectedOptions.length === 0) {
                        const empty = document.createElement('div');
                        empty.className = 'vista-selected-empty';
                        empty.dataset.vistaEmpty = 'true';
                        empty.textContent = 'Todavía no has seleccionado ninguna vista.';
                        selectedList.appendChild(empty);
                        return;
                    }

                    const head = document.createElement('div');
                    head.className = 'vista-selected-head';
                    head.innerHTML = '<span>Vista</span><span>Detalle</span><span>Estado</span><span></span>';
                    selectedList.appendChild(head);

                    selectedOptions.forEach((option) => {
                        const checkbox = option.querySelector('[data-vista-checkbox]');
                        const row = document.createElement('div');
                        row.className = 'vista-selected-row';
                        row.dataset.vistaItem = checkbox?.value || '';
                        row.dataset.vistaText = String(option.dataset.vistaText || '');

                        const nameCell = document.createElement('div');
                        nameCell.className = 'vista-selected-row-name';
                        nameCell.textContent = option.dataset.vistaName || (option.querySelector('.vista-selector-option-title')?.textContent || checkbox?.value || '');

                        const detailCell = document.createElement('div');
                        detailCell.className = 'vista-selected-row-detail';
                        detailCell.textContent = String(option.dataset.vistaDetail || '').trim() || '-';

                        const stateCell = document.createElement('div');
                        stateCell.className = 'vista-selected-row-state';
                        stateCell.textContent = String(option.dataset.vistaState || '').trim() || '-';
                        row.appendChild(nameCell);
                        row.appendChild(detailCell);
                        row.appendChild(stateCell);
                        selectedList.appendChild(row);
                    });

                    if (selectAll) {
                        selectAll.checked = selectedOptions.length === options.length;
                        selectAll.indeterminate = selectedOptions.length > 0 && selectedOptions.length < options.length;
                    }
                };

                const applyFilter = () => {
                    const query = String(search?.value || '').trim().toLowerCase();
                    options.forEach((option) => {
                        const text = String(option.dataset.vistaText || '').toLowerCase();
                        option.classList.toggle('is-hidden', query !== '' && !text.includes(query));
                    });
                };

                const sortOptionsBySelection = () => {
                    const selectorList = fieldset.querySelector('[data-vista-selector-list]');
                    if (!selectorList) return;

                    const selected = [];
                    const unselected = [];

                    options.forEach((option) => {
                        const checkbox = option.querySelector('[data-vista-checkbox]');
                        if (checkbox?.checked) {
                            selected.push(option);
                        } else {
                            unselected.push(option);
                        }
                    });

                    // Reordenar: seleccionados primero, luego no seleccionados
                    const sorted = [...selected, ...unselected];
                    sorted.forEach((option) => {
                        selectorList.appendChild(option);
                    });
                };

                checks.forEach((checkbox) => {
                    checkbox.addEventListener('change', () => {
                        const option = checkbox.closest('[data-vista-option]');
                        if (option) {
                            option.classList.toggle('is-selected', !!checkbox.checked);
                        }
                        sortOptionsBySelection();
                        renderSelected();
                        if (checks.some((item) => item.checked)) {
                            roleInputs.forEach((roleInput) => {
                                if (roleInput.checked) {
                                    roleInput.checked = false;
                                    roleInput.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                            });
                        }
                    });
                });

                if (selectAll) {
                    selectAll.addEventListener('change', () => {
                        const shouldCheck = !!selectAll.checked;
                        checks.forEach((checkbox) => {
                            checkbox.checked = shouldCheck;
                            const option = checkbox.closest('[data-vista-option]');
                            if (option) {
                                option.classList.toggle('is-selected', shouldCheck);
                            }
                        });
                        sortOptionsBySelection();
                        renderSelected();
                        if (shouldCheck) {
                            roleInputs.forEach((roleInput) => {
                                if (roleInput.checked) {
                                    roleInput.checked = false;
                                    roleInput.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                            });
                        }
                    });
                }

                roleInputs.forEach((roleInput) => {
                    roleInput.addEventListener('change', () => {
                        if (roleInput.checked) {
                            syncSelectedVistasFromRole();
                        }
                    });
                });

                options.forEach((option) => {
                    const checkbox = option.querySelector('[data-vista-checkbox]');
                    option.classList.toggle('is-selected', !!checkbox?.checked);
                });

                if (search) {
                    search.addEventListener('input', applyFilter);
                }

                applyFilter();
                sortOptionsBySelection();
                renderSelected();
            });
        });

        function hasAllowedExtension(fileName, allowedExtensions) {
            const extension = String(fileName || '').split('.').pop().toLowerCase();
            return allowedExtensions.includes(extension);
        }

        function isValidImageFile(file) {
            if (!file) return false;
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
            const allowedExt = ['jpg', 'jpeg', 'png'];
            const hasValidType = file.type === '' || allowedTypes.includes(file.type);
            return hasValidType && hasAllowedExtension(file.name, allowedExt);
        }

        function isValidPdfFile(file) {
            if (!file) return false;
            const allowedTypes = ['application/pdf', 'application/x-pdf'];
            const hasValidType = file.type === '' || allowedTypes.includes(file.type);
            return hasValidType && hasAllowedExtension(file.name, ['pdf']);
        }

        function isValidFileByKind(file, fileKind) {
            if (fileKind === 'pdf') {
                return isValidPdfFile(file);
            }
            return isValidImageFile(file);
        }

        function showFileSelectionMessage(input) {
            const file = input.files && input.files[0];
            if (!file) return;

            const wrapper = input.closest('[data-file-input-wrapper]');
            if (!wrapper) return;

            const messageNode = wrapper.querySelector('.file-input-message');
            if (!messageNode) return;

            const fileKind = input.dataset.fileKind === 'pdf' ? 'pdf' : 'image';
            const maxSize = 2 * 1024 * 1024; // 2 MB en bytes

            if (file.size > maxSize) {
                input.value = '';
                messageNode.textContent = 'El archivo excede el tamaño máximo de 2 MB.';
                messageNode.classList.remove('hidden');
                messageNode.style.color = '#dc2626';
                return;
            }
            const labelText = String(input.dataset.fileLabel || input.name || 'archivo').toLowerCase();
            const invalidMessage = fileKind === 'pdf'
                ? 'Solo se permiten archivos PDF.'
                : 'Solo se permiten imágenes JPG, JPEG y PNG.';

            if (!isValidFileByKind(file, fileKind)) {
                input.value = '';
                messageNode.textContent = invalidMessage;
                messageNode.classList.remove('hidden');
                messageNode.style.color = '#dc2626';
                return;
            }

            const hasExistingFile = fileKind === 'image'
                ? !!wrapper.querySelector('img')
                : !!wrapper.querySelector('a[data-file-link]');

            const successMessage = hasExistingFile
                ? 'Se cambió Archivo exitosamente.'
                : 'Archivo seleccionado correctamente.';

            messageNode.textContent = successMessage;
            messageNode.classList.remove('hidden');
            messageNode.style.color = '#16a34a';
        }

        (function () {
            const form = document.querySelector('form');
            if (!form) return;

            const fileInputs = form.querySelectorAll('input[type="file"]');
            fileInputs.forEach((input) => {
                input.addEventListener('change', function () {
                    showFileSelectionMessage(this);
                });
            });
        })();

        (function () {
            const tipoSelect = document.querySelector('select[name="tipoCliente"]');
            const idInput = document.querySelector('input[name="idcliente"]');
            if (!tipoSelect || !idInput) return;

            const idContainer = idInput.closest('.relative')?.parentElement;
            const idLabel = idContainer?.querySelector('label');
            const idHelp = document.getElementById('idcliente-help');
            const editButton = document.getElementById('btnEditar');
            const isEditContext = !!editButton;
            const isIdclienteEditable = () => !editButton || editButton.style.display === 'none';
            const initialTipoValue = String(tipoSelect.value ?? '').trim();
            const initialIdValue = String(idInput.value ?? '').trim();
            let lastTipo = initialTipoValue;

            // Crear botón de consulta junto al label del identificador (RUC/DNI), estilo "Crear rápido".
            let consultBtn = idContainer?.querySelector('button[data-consult-button]');
            if (!consultBtn && idContainer) {
                consultBtn = document.createElement('button');
                consultBtn.type = 'button';
                consultBtn.setAttribute('data-consult-button', 'true');
                consultBtn.id = 'consult-idcliente-button';
                consultBtn.style.display = 'none';
                consultBtn.setAttribute('style', 'background-color: #dc2626 !important; color: #fff !important; display: none;');
                consultBtn.className = 'ml-1 inline-flex items-center gap-1 rounded border border-red-600 px-2 py-1 text-xs transition hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-600 focus:ring-opacity-50 disabled:cursor-not-allowed disabled:opacity-70';
                consultBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35"/><circle cx="11" cy="11" r="6" /></svg>' +
                    '<span class="ml-1 consult-text">Consultar</span>' +
                    '<span class="ml-2 h-4 w-4 consult-spinner hidden" role="status" aria-live="polite">' +
                    '<svg class="h-full w-full" width="25" viewBox="0 0 120 30" xmlns="http://www.w3.org/2000/svg" fill="#ffffff">' +
                    '<circle cx="15" cy="15" r="15">' +
                    '<animate values="15;9;15" attributeName="r" from="15" to="15" begin="0s" dur="0.8s" calcMode="linear" repeatCount="indefinite"></animate>' +
                    '<animate values="1;.5;1" attributeName="fill-opacity" from="1" to="1" begin="0s" dur="0.8s" calcMode="linear" repeatCount="indefinite"></animate>' +
                    '</circle>' +
                    '<circle cx="60" cy="15" r="9" fill-opacity="0.3">' +
                    '<animate values="9;15;9" attributeName="r" from="9" to="9" begin="0s" dur="0.8s" calcMode="linear" repeatCount="indefinite"></animate>' +
                    '<animate values=".5;1;.5" attributeName="fill-opacity" from="0.5" to="0.5" begin="0s" dur="0.8s" calcMode="linear" repeatCount="indefinite"></animate>' +
                    '</circle>' +
                    '<circle cx="105" cy="15" r="15">' +
                    '<animate values="15;9;15" attributeName="r" from="15" to="15" begin="0s" dur="0.8s" calcMode="linear" repeatCount="indefinite"></animate>' +
                    '<animate values="1;.5;1" attributeName="fill-opacity" from="1" to="1" begin="0s" dur="0.8s" calcMode="linear" repeatCount="indefinite"></animate>' +
                    '</circle>' +
                    '</svg>' +
                    '</span>';
                // Insertar al nivel del label, conservando el asterisco y la estructura del label.
                const labelContainer = idLabel?.querySelector(':scope > span') || idLabel;
                if (idHelp) {
                    idHelp.after(consultBtn); 
                } else if (idLabel) {
                    idLabel.after(consultBtn);
                } else {
                    idContainer.appendChild(consultBtn);
                }
                consultBtn.addEventListener('click', async () => {
                    const tipo = tipoSelect.value === '1' ? 'ruc' : 'dni';
                    const valor = idInput.value.trim();

                    consultBtn.disabled = true;
                    const btnOriginalHtml = consultBtn.innerHTML;
                    // Mostrar texto de 'Consultando' y el spinner (si existen los elementos), evitando innerHTML cuando sea posible
                    const consultTextEl = consultBtn.querySelector('.consult-text');
                    const consultSpinnerEl = consultBtn.querySelector('.consult-spinner');
                    if (consultTextEl) consultTextEl.textContent = 'Consultando';
                    if (consultSpinnerEl) consultSpinnerEl.classList.remove('hidden');
                    else consultBtn.innerHTML = '<span class="ml-1">Consultando...</span>';               
                    try {
                        const response = await fetch(`/api/consultar-documento?tipo=${tipo}&valor=${valor}`);
                        const data = await response.json();

                        if (data.status === 'success' && data.data) {
                            const d = data.data;
                            
                            // 1 & 2. Razón Social y Nombre Comercial
                            const nombre = d.razon_social || d.full_name || '';
                            const inputRazonSocial = document.querySelector('input[name="razonSocial"]');
                            const inputNombres = document.querySelector('#pn_nombres');
                            const inputApellidos = document.querySelector('#pn_apellidos');

                            if (inputRazonSocial) {
                                const selectTipo = document.querySelector('select[name="tipoCliente"]');
                                if (selectTipo && selectTipo.value === '0') {
                                    if (inputNombres && d.first_name) {
                                        inputNombres.value = d.first_name;
                                    }
                                    if (inputApellidos && (d.first_last_name || d.second_last_name)) {
                                        inputApellidos.value = `${d.first_last_name || ''} ${d.second_last_name || ''}`.trim();
                                    }
                                    inputRazonSocial.value = `${inputNombres ? inputNombres.value : ''} ${inputApellidos ? inputApellidos.value : ''}`.trim();
                                } else if (nombre) {
                                    inputRazonSocial.value = nombre;
                                }
                            }

                            const inputNombreComercial = document.querySelector('input[name="nombreComercial"]');
                            if (inputNombreComercial) {
                                if (d.full_name) {
                                    inputNombreComercial.value = d.full_name;
                                } else if (nombre) {
                                    inputNombreComercial.value = nombre;
                                }
                            }

                            // 3. Rubro
                            const inputRubro = document.querySelector('input[name="rubro"]');
                            if (inputRubro && d.actividad_economica) {
                                inputRubro.value = d.actividad_economica;
                            }

                            // 4. Estado
                            const selectEstado = document.querySelector('select[name="estadoCliente_idestadoCliente"]');
                            if (selectEstado && d.estado) {
                                const inst = selectEstado.tomselect || selectEstado.tomSelect || selectEstado._tomselect || null;
                                if (inst && inst.options) {
                                    const targetEstado = String(d.estado).trim().toUpperCase();
                                    for (const key in inst.options) {
                                        const optionText = String(inst.options[key].text || inst.options[key].label || '').trim().toUpperCase();
                                        if (optionText === targetEstado) {
                                            inst.setValue(key);
                                            break;
                                        }
                                    }
                                } else {
                                    // Fallback to select without tomselect
                                    Array.from(selectEstado.options).forEach(opt => {
                                        if (opt.text.trim().toUpperCase() === String(d.estado).trim().toUpperCase()) {
                                            selectEstado.value = opt.value;
                                        }
                                    });
                                }
                            }

                            // 5. Retención (detraccion switch)
                            const checkboxRetencion = document.querySelector('input[name="detraccion"], input[name="detraccion[]"]');
                            if (checkboxRetencion && typeof d.es_agente_retencion !== 'undefined') {
                                checkboxRetencion.checked = d.es_agente_retencion;
                            }

                            // Dirección Temporal
                            const direccionesPayload = document.querySelector('input[name="direcciones_payload"]');
                            const selectDireccion = document.querySelector('select[name="direccionCliente_iddireccionCliente"]');
                            if (direccionesPayload && d.direccion) {
                                const tempId = 'tmp-' + Date.now();
                                const tempAddress = {
                                    tempId: tempId,
                                    tipo: 'Principal', 
                                    direccion: d.direccion,
                                    ubigeo_idubigeo: Number(d.ubigeo) || null,
                                        ubigeo_text: (function(){
                                            if (d.departamento || d.provincia || d.distrito) {
                                                return [d.departamento, d.provincia, d.distrito].filter(Boolean).join(' / ').toUpperCase();
                                            }
                                            if (d.ubigeo_text || d.ubigeo_label) return d.ubigeo_text || d.ubigeo_label;
                                            // intenta buscar texto de ubigeo en selects existentes (fallback)
                                            try {
                                                const id = String(d.ubigeo || '');
                                                if (!id) return '';
                                                const selects = Array.from(document.querySelectorAll('select'));
                                                for (const s of selects) {
                                                    const opt = s.querySelector('option[value="' + id + '"]');
                                                    if (opt && opt.textContent && opt.textContent.trim() !== '') return opt.textContent.trim();
                                                }
                                            } catch (e) {}
                                            return '';
                                        })(),
                                    linkUbicacion: ''
                                };
                                let currentPayload = [];
                                try {
                                    currentPayload = JSON.parse(direccionesPayload.value || '[]');
                                } catch(e) {}
                                currentPayload.push(tempAddress);
                                direccionesPayload.value = JSON.stringify(currentPayload);
                                
                                // Actualizar el TomSelect visual de direcciones
                                if (selectDireccion) {
                                    let ubigeoDisplay = tempAddress.ubigeo_idubigeo ? String(tempAddress.ubigeo_idubigeo) : '';
                                    if (tempAddress.ubigeo_text) {
                                        ubigeoDisplay += ubigeoDisplay ? `-${tempAddress.ubigeo_text}` : tempAddress.ubigeo_text;
                                    }
                                    const label = tempAddress.direccion + (ubigeoDisplay ? ` (${ubigeoDisplay})` : '');
                                    const inst = selectDireccion.tomselect || selectDireccion.tomSelect || selectDireccion._tomselect || null;
                                    if (inst && typeof inst.addOption === 'function') {
                                        inst.addOption({ value: tempId, text: label }, true);
                                        inst.addItem(tempId);
                                    } else {
                                        let option = selectDireccion.querySelector(`option[value="${tempId}"]`);
                                        if (!option) {
                                            option = document.createElement('option');
                                            option.value = tempId;
                                            selectDireccion.appendChild(option);
                                        }
                                        option.textContent = label;
                                        selectDireccion.value = tempId;
                                    }
                                    selectDireccion.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                            }
                        } 
                    } catch (error) {
                        console.error("Error en la petición:", error);
                    } finally {
                        consultBtn.disabled = false;
                        consultBtn.innerHTML = btnOriginalHtml;
                    }
                });
            }

            if (String(idInput.value ?? '') !== initialIdValue) {
                idInput.value = initialIdValue;
            }

            const setLabelText = (labelElement, text) => {
                if (!labelElement) return;

                // Mantiene el asterisco de requerido sin reusar el contenedor del label.
                const requiredStar = labelElement.querySelector('.text-red-500');
                const textContainer = labelElement.querySelector(':scope > span > span')
                    || labelElement.querySelector(':scope > span')
                    || labelElement;

                textContainer.textContent = text;
                if (requiredStar) {
                    textContainer.appendChild(requiredStar);
                }
            };

            const updateIdclienteField = () => {
                const tipoValue = String(tipoSelect.value ?? '').trim();
                // Si cambió el tipo de cliente, reiniciar el valor del input
                if (tipoValue !== lastTipo) {
                    try {
                        // Limpia el valor, resetea validez y dispara evento input para que cualquier mask/validator observe el cambio
                        if (String(idInput.value || '').length > 0) {
                            idInput.value = '';
                            idInput.setCustomValidity('');
                            idInput.dispatchEvent(new Event('input', { bubbles: true }));
                        }
                    } catch (e) {
                        // silenciar errores de compatibilidad
                        console.warn('Error al resetear idcliente tras cambio de tipo:', e);
                    }
                    lastTipo = tipoValue;
                }
                const hasSelection = tipoValue === '1' || tipoValue === '0';
                const isEmpresa = tipoValue === '1';
                const labelText = hasSelection ? (isEmpresa ? 'RUC' : 'DNI') : 'Cliente';
                const fieldLength = isEmpresa ? 11 : 8;
                const normalizedValue = String(idInput.value ?? '').trim();
                const hasChangedFromInitial = normalizedValue !== initialIdValue || tipoValue !== initialTipoValue;
                const shouldStrictValidate = !isEditContext || hasChangedFromInitial;
                const helpText = hasSelection
                    ? (shouldStrictValidate
                        ? (isEmpresa
                            ? 'Obligatorio. Solo números, exactamente 11 dígitos.'
                            : 'Obligatorio. Solo números, exactamente 8 dígitos.')
                        : (isEmpresa
                            ? 'RUC registrado. Si no lo modificas, no se validará nuevamente.'
                            : 'DNI registrado. Si no lo modificas, no se validará nuevamente.'))
                    : (isEditContext ? '' : 'Selecciona tipo de cliente primero.');
                const validationMessage = hasSelection
                    ? (shouldStrictValidate
                        ? (isEmpresa
                            ? 'Ingresa un RUC válido de 11 dígitos.'
                            : 'Ingresa un DNI válido de 8 dígitos.')
                        : '')
                    : (isEditContext ? '' : 'Selecciona tipo de cliente primero.');

                if (String(idInput.value ?? '') !== normalizedValue) {
                    idInput.value = normalizedValue;
                }

                setLabelText(idLabel, labelText);
                idInput.placeholder = hasSelection ? labelText : 'Cliente';
                const canEditIdcliente = isIdclienteEditable();
                idInput.disabled = !(hasSelection && canEditIdcliente);
                idInput.required = hasSelection && canEditIdcliente && shouldStrictValidate;
                idInput.dataset.validationMessage = validationMessage;
                idInput.setCustomValidity('');

                if (consultBtn) {
                    // Mostrar el botón cuando haya un tipo seleccionado y el campo sea editable
                    consultBtn.style.display = (hasSelection && canEditIdcliente) ? 'inline-flex' : 'none';
                }

                if (hasSelection) {
                    if (shouldStrictValidate) {
                        // Evitar excepción cuando se reduce maxLength por debajo del minlength actual:
                        // si el nuevo fieldLength es menor que el minlength existente, primero bajar minlength,
                        // luego aplicar maxLength. En caso contrario, aplicar maxLength primero.
                        const currentMin = Number(idInput.minLength || 0);
                        if (fieldLength < currentMin) {
                            idInput.minLength = fieldLength;
                            idInput.maxLength = fieldLength;
                        } else {
                            idInput.maxLength = fieldLength;
                            idInput.minLength = fieldLength;
                        }
                        idInput.pattern = `^[0-9]{${fieldLength}}$`;
                    } else {
                        // No strict: permitir cualquier longitud hasta fieldLength
                        idInput.minLength = 0;
                        idInput.maxLength = fieldLength;
                        idInput.pattern = '^[0-9]*$';
                    }
                    idInput.inputMode = 'numeric';
                } else {
                    idInput.minLength = 0;
                    idInput.maxLength = 11;
                    idInput.pattern = '^[0-9]{8,11}$';
                    idInput.inputMode = 'numeric';
                }

                if (idHelp) {
                    idHelp.textContent = helpText;
                }
            };

            if (editButton) {
                editButton.addEventListener('click', () => {
                    setTimeout(updateIdclienteField, 0);
                });
            }

            tipoSelect.addEventListener('change', updateIdclienteField);
            updateIdclienteField();
        })();

        (function () {
            const escapeHtml = (value) => value.replace(/[&<>"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[char]));
            const autocompleteInputs = Array.from(document.querySelectorAll('input[data-datalist-options]'));
            autocompleteInputs.forEach((input) => {
                const wrapper = input.closest('.relative');
                const dropdown = wrapper?.querySelector('.custom-datalist');
                const optionsContainer = wrapper?.querySelector('.custom-datalist-options');
                if (!dropdown || !optionsContainer) return;

                let options = [];
                try {
                    options = JSON.parse(input.dataset.datalistOptions || '[]');
                } catch (error) {
                    options = [];
                }

                const renderOptions = (items) => {
                    if (!items.length) {
                        optionsContainer.innerHTML = '<div class="px-3 py-2 text-sm text-slate-500">No hay coincidencias</div>';
                        dropdown.classList.remove('hidden');
                        return;
                    }
                    optionsContainer.innerHTML = items.map((item) => `
                        <button type="button" class="w-full text-left px-3 py-2 text-sm text-slate-700 hover:bg-slate-100 focus:bg-slate-100 outline-none">
                            ${escapeHtml(item)}
                        </button>
                    `).join('');
                    dropdown.classList.remove('hidden');
                    optionsContainer.querySelectorAll('button').forEach((button) => {
                        button.addEventListener('click', () => {
                            input.value = button.textContent.trim();
                            dropdown.classList.add('hidden');
                            input.focus();
                        });
                    });
                };

                const updateDropdown = () => {
                    const search = input.value.trim().toLowerCase();
                    const filtered = search === ''
                        ? options.slice(0, 20)
                        : options.filter((item) => item.toLowerCase().includes(search));
                    renderOptions(filtered);
                };

                input.addEventListener('input', updateDropdown);
                input.addEventListener('focus', updateDropdown);
                input.addEventListener('blur', () => {
                    setTimeout(() => dropdown.classList.add('hidden'), 150);
                });
            });
        })();

        (function () {
            const mainForm = document.getElementById('main-crud-form');
            if (!mainForm) return;

            const statusIndicator = document.getElementById('main-save-status');
            const relationSummaryTemplate = document.getElementById('erp-relation-summary-template')?.value || null;
            const relationResource = document.getElementById('erp-lock-resource')?.value || null;
            const relationRecordId = document.getElementById('erp-lock-id')?.value || null;
            const relationModal = document.getElementById('delete-confirmation-modal');
            const relationModalTitle = relationModal ? relationModal.querySelector('#delete-confirmation-title') : null;
            const relationModalMessage = relationModal ? relationModal.querySelector('#delete-confirmation-message') : null;
            const relationModalRelations = relationModal ? relationModal.querySelector('#delete-confirmation-relations') : null;
            const relationModalHint = relationModal ? relationModal.querySelector('#delete-confirmation-hint') : null;
            const relationModalSubmit = relationModal ? relationModal.querySelector('#delete-confirmation-submit') : null;
            const relationModalCloseButtons = relationModal ? relationModal.querySelectorAll('[data-delete-modal-close]') : [];
            const relationCache = new Map();
            const isEditMode = Boolean(relationResource && relationRecordId && relationModal);
            const isCreateMode = !isEditMode;
            let relationConfirmationConfirmed = false;
            let relationModalActive = false; // indica que el modal de confirmación fue abierto por openRelationModal

            const getSubmitButton = () => mainForm.querySelector('button[type="submit"]');

            const buildRelationSummaryUrl = (resource, id) => {
                if (!relationSummaryTemplate || !resource || !id) {
                    return null;
                }

                return relationSummaryTemplate.replace('__RESOURCE__', encodeURIComponent(resource)).replace('__ID__', encodeURIComponent(id));
            };

            const resetRelationModal = () => {
                if (!relationModalTitle || !relationModalMessage || !relationModalRelations || !relationModalHint || !relationModalSubmit) {
                    return;
                }

                relationModalTitle.textContent = 'Confirmar edición';
                relationModalMessage.textContent = 'Estás a punto de guardar cambios en este registro.';
                relationModalRelations.innerHTML = '';
                relationModalRelations.classList.add('hidden');
                relationModalHint.textContent = '';
                relationModalHint.classList.add('hidden');
                relationModalSubmit.textContent = 'Guardar cambios';
                relationModalSubmit.style.background = '#dc2626';
            };

            const closeRelationModal = () => {
                if (!relationModal) {
                    return;
                }

                relationModal.style.display = 'none';
                document.body.style.overflow = '';
                relationConfirmationConfirmed = false;
                relationModalActive = false;
                resetRelationModal();
                unlockSubmit();
                const statusSpan = relationModal.querySelector('#delete-confirmation-status');
                if (statusSpan) {
                    statusSpan.classList.add('hidden');
                }
            };

            const setCreateDownloadFlags = () => {
                document.getElementById('download-after-save').value = '1';
                document.getElementById('include-image-flag').value = '0';
            };

            const openRelationModal = (summary) => {
                if (!relationModal || !relationModalMessage || !relationModalRelations || !relationModalHint || !relationModalSubmit || !relationModalTitle) {
                    return;
                }

                const recordLabel = summary?.recordLabel || 'este registro';
                const relations = Array.isArray(summary?.relations) ? summary.relations : [];

                relationModalTitle.textContent = 'Confirmar edición';
                relationModalMessage.textContent = `Vas a guardar cambios en ${recordLabel}. Revisa las relaciones antes de continuar.`;
                relationModalRelations.innerHTML = '';

                if (relations.length > 0) {
                    const relationList = document.createElement('div');
                    relationList.className = 'space-y-3';

                    relations.forEach((relation) => {
                        const records = Array.isArray(relation.records) ? relation.records.filter(Boolean) : [];
                        const extraCount = Math.max((Number(relation.count || 0) - records.length), 0);
                        const relationText = records.length > 0 ? records.join(', ') : 'sin detalle adicional';
                        const suffix = extraCount > 0 ? ` y otros ${extraCount} más` : '';
                        const block = document.createElement('div');
                        block.className = 'rounded-lg border border-amber-200 bg-white px-4 py-3';

                        const heading = document.createElement('div');
                        heading.className = 'font-semibold text-amber-900';
                        heading.textContent = `Relacionado con ${relation.label} (${relation.count})`;

                        const body = document.createElement('p');
                        body.className = 'mt-1 text-sm text-amber-800 leading-6';
                        body.textContent = `Este registro está relacionado con ${relation.count} ${relation.label}${relation.count === 1 ? '' : 'es'}: ${relationText}${suffix}.`;

                        block.appendChild(heading);
                        block.appendChild(body);
                        relationList.appendChild(block);
                    });

                    relationModalRelations.appendChild(relationList);
                    relationModalRelations.classList.remove('hidden');
                }

                relationModalHint.textContent = 'Si continúas, el sistema validará la integridad de datos antes de aplicar la actualización.';
                relationModalHint.classList.remove('hidden');
                relationModalSubmit.textContent = 'Guardar cambios';
                relationModalSubmit.style.background = '#dc2626';
                relationModalActive = true;
                relationModal.style.display = 'flex';
                relationModal.style.justifyContent = 'center';
                relationModal.style.alignItems = 'center';
                relationModal.style.background = 'rgba(0,0,0,0.8)';
                relationModal.style.zIndex = '9999';
                document.body.style.overflow = 'hidden';
                lockSubmit();
            };

            const loadRelationSummary = async () => {
                if (!relationResource || !relationRecordId) {
                    return { resource: relationResource, recordId: relationRecordId, recordLabel: null, relations: [] };
                }

                const cacheKey = `${relationResource}:${relationRecordId}`;
                if (relationCache.has(cacheKey)) {
                    return relationCache.get(cacheKey);
                }

                const url = buildRelationSummaryUrl(relationResource, relationRecordId);
                if (!url) {
                    const emptySummary = { resource: relationResource, recordId: relationRecordId, recordLabel: null, relations: [] };
                    relationCache.set(cacheKey, emptySummary);
                    return emptySummary;
                }

                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                const payload = await response.json();
                const summary = response.ok && payload && payload.ok && payload.data ? payload.data : { resource: relationResource, recordId: relationRecordId, recordLabel: null, relations: [] };
                relationCache.set(cacheKey, summary);
                return summary;
            };

            if (relationModal) {
                if (relationModal.parentElement !== document.body) {
                    document.body.appendChild(relationModal);
                }

                relationModalCloseButtons.forEach((button) => {
                    button.removeEventListener('click', closeRelationModal);
                    button.addEventListener('click', closeRelationModal);
                });

                if (relationModalSubmit) {
                    relationModalSubmit.removeEventListener('click', closeRelationModal);
                    relationModalSubmit.addEventListener('click', () => {
                        // Solo realizar el submit automático si el modal fue abierto por openRelationModal
                        if (!relationModalActive) {
                            // Si se está usando el modal como confirm genérico, solo cerramos/resolvemos en la promesa
                            return;
                        }
                        relationConfirmationConfirmed = true;
                        lockSubmit(relationModalSubmit);
                        const statusSpan = relationModal.querySelector('#delete-confirmation-status');
                        if (statusSpan) {
                            statusSpan.classList.remove('hidden');
                        }
                        if (window.enableDetailControls) {
                            window.enableDetailControls();
                        }
                        mainForm.submit();
                    });
                }
            }

            const lockSubmit = (button) => {
                const submitButton = button || getSubmitButton();
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.classList.add('opacity-70', 'cursor-not-allowed');
                }
                if (statusIndicator) {
                    statusIndicator.classList.remove('hidden');
                }
            };

            const unlockSubmit = (button) => {
                const submitButton = button || getSubmitButton();
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.classList.remove('opacity-70', 'cursor-not-allowed');
                }
                if (statusIndicator) {
                    statusIndicator.classList.add('hidden');
                }
                mainForm.dataset.submitted = 'false';
            };

            mainForm.addEventListener('submit', function (event) {
                if (mainForm.dataset.submitted === 'true') {
                    event.preventDefault();
                    return;
                }

                // Bloquear botón inmediatamente
                lockSubmit(event.submitter);
                mainForm.dataset.submitted = 'true';
                if (window.enableDetailControls) {
                    window.enableDetailControls();
                }

                // If creating and multiple cotizaciones are present, ask whether to group them
                if (isCreateMode) {
                    try {
                        const groupWrappers = document.querySelectorAll('[id^="group-wrapper-"]');
                        const groupCount = groupWrappers ? groupWrappers.length : 0;
                        if (groupCount >= 2 && !mainForm.dataset.groupDecision) {
                            // Pause submit and ask user
                            event.preventDefault();
                            unlockSubmit(event.submitter);
                            mainForm.dataset.submitted = 'false';
                            confirmWithModal({
                                title: '¿Quieres agrupar esta cotización?',
                                message: `Se crearán ${groupCount} cotizaciones. ¿Deseas agruparlas en un mismo grupo?`,
                                submitText: 'Sí',
                                cancelText: 'No'
                            }).then((confirmed) => {
                                let input = mainForm.querySelector('input[name="group_confirm"]');
                                if (!input) {
                                    input = document.createElement('input');
                                    input.type = 'hidden';
                                    input.name = 'group_confirm';
                                    mainForm.appendChild(input);
                                }
                                input.value = confirmed ? '1' : '0';
                                mainForm.dataset.groupDecision = 'true';
                                setCreateDownloadFlags();
                                mainForm.submit();
                            });
                            return;
                        }
                    } catch (e) {
                        console.warn('Agrupado: error al determinar cantidad de cotizaciones', e);
                    }
                }

                if (isCreateMode) {
                    setCreateDownloadFlags();
                }

                if (isEditMode && !relationConfirmationConfirmed) {
                    event.preventDefault();
                    loadRelationSummary()
                        .then((summary) => {
                            openRelationModal(summary);
                        })
                        .catch(() => {
                            openRelationModal(null);
                        });
                    return;
                }

                // Bloquear botón inmediatamente
                lockSubmit(event.submitter);
                mainForm.dataset.submitted = 'true';
                if (window.enableDetailControls) {
                    window.enableDetailControls();
                }
            });
        })(); 
    </script>

    <style>
        /* Truncar texto largo en TomSelect (para campos como Dirección) */
        .ts-control > .item, .ts-dropdown .option {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            function decorateTomSelectTitles(root) {
                (root || document).querySelectorAll('.ts-dropdown .option, .ts-control .item').forEach(function(el) {
                    if (!el.hasAttribute('title')) {
                        const text = el.textContent.trim();
                        if (text) el.setAttribute('title', text);
                    }
                });
            }

            decorateTomSelectTitles(document);

            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    mutation.addedNodes.forEach(function(node) {
                        if (!(node instanceof HTMLElement)) return;
                        if (node.matches('.ts-dropdown .option, .ts-control .item')) {
                            if (!node.hasAttribute('title')) {
                                const text = node.textContent.trim();
                                if (text) node.setAttribute('title', text);
                            }
                        } else {
                            decorateTomSelectTitles(node);
                        }
                    });
                });
            });

            observer.observe(document.body, { childList: true, subtree: true });
        });

        // Lógica de separación de Persona Natural (Nombres y Apellidos) vs Empresa (Razón Social)
        document.addEventListener('DOMContentLoaded', function() {
            const tipoClienteSelect = document.querySelector('select[name="tipoCliente"]');
            const razonSocialInput = document.querySelector('input[name="razonSocial"]');
            
            if (tipoClienteSelect && razonSocialInput) {
                const razonSocialWrapper = razonSocialInput.closest('.crud-field-wrapper');
                if (!razonSocialWrapper) return;
                
                // Nombres + Apellidos wrapper (row, 2 columnas responsivas)
                const nombresApellidosWrapper = document.createElement('div');
                nombresApellidosWrapper.className = razonSocialWrapper.className + ' persona-natural-field hidden';
                nombresApellidosWrapper.innerHTML = `
                    <div class="grid grid-cols-12 gap-x-6">
                        <div class="col-span-12 md:col-span-6">
                            <label for="pn_nombres" class="text-sm font-medium text-slate-700 mb-2 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between block text-sm">
                                <span>Nombres</span>
                                <span class="text-xs text-slate-500 font-normal">Mínimo 2 caracteres.</span>
                            </label>
                            <input id="pn_nombres" name="pn_nombres" type="text" class="disabled:bg-slate-100 disabled:cursor-not-allowed dark:disabled:bg-darkmode-800/50 dark:disabled:border-transparent [&[readonly]]:bg-slate-100 [&[readonly]]:cursor-not-allowed [&[readonly]]:dark:bg-darkmode-800/50 [&[readonly]]:dark:border-transparent transition duration-200 ease-in-out w-full text-sm border-slate-200 shadow-sm rounded-md placeholder:text-slate-400/90 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 dark:placeholder:text-slate-500/80" placeholder="Ingrese nombres">
                        </div>
                        <div class="col-span-12 md:col-span-6">
                            <label for="pn_apellidos" class="text-sm font-medium text-slate-700 mb-2 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between block text-sm">
                                <span>Apellidos</span>
                                <span class="text-xs text-slate-500 font-normal">Mínimo 2 caracteres.</span>
                            </label>
                            <input id="pn_apellidos" name="pn_apellidos" type="text" class="disabled:bg-slate-100 disabled:cursor-not-allowed dark:disabled:bg-darkmode-800/50 dark:disabled:border-transparent [&[readonly]]:bg-slate-100 [&[readonly]]:cursor-not-allowed [&[readonly]]:dark:bg-darkmode-800/50 [&[readonly]]:dark:border-transparent transition duration-200 ease-in-out w-full text-sm border-slate-200 shadow-sm rounded-md placeholder:text-slate-400/90 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 dark:placeholder:text-slate-500/80" placeholder="Ingrese apellidos">
                        </div>
                    </div>
                `;

                // Insert after Razón Social as single grouped row
                razonSocialWrapper.parentNode.insertBefore(nombresApellidosWrapper, razonSocialWrapper.nextSibling);

                const inputNombres = nombresApellidosWrapper.querySelector('#pn_nombres');
                const inputApellidos = nombresApellidosWrapper.querySelector('#pn_apellidos');

                // Split existing value
                if (razonSocialInput.value && tipoClienteSelect.value === '0') {
                    const parts = razonSocialInput.value.split(' ');
                    if (parts.length > 2) {
                        inputNombres.value = parts.slice(0, Math.ceil(parts.length/2)).join(' ');
                        inputApellidos.value = parts.slice(Math.ceil(parts.length/2)).join(' ');
                    } else {
                        inputNombres.value = parts[0] || '';
                        inputApellidos.value = parts[1] || '';
                    }
                }

                const updateRazonSocial = () => {
                    if (tipoClienteSelect.value === '0') {
                        const fullName = (inputNombres.value.trim() + ' ' + inputApellidos.value.trim()).trim();
                        razonSocialInput.value = fullName;
                    }
                };

                const toggleFields = () => {
                    const isPersonaNatural = (tipoClienteSelect.value === '0');
                    if (isPersonaNatural) {
                        razonSocialWrapper.style.display = 'none';
                        nombresApellidosWrapper.classList.remove('hidden');
                    } else {
                        razonSocialWrapper.style.display = '';
                        nombresApellidosWrapper.classList.add('hidden');
                    }
                };

                inputNombres.addEventListener('input', updateRazonSocial);
                inputApellidos.addEventListener('input', updateRazonSocial);
                tipoClienteSelect.addEventListener('change', toggleFields);
                
                // Allow TomSelect to trigger changes if it is initialized
                setTimeout(() => {
                    const inst = tipoClienteSelect.tomselect || tipoClienteSelect.tomSelect || tipoClienteSelect._tomselect;
                    if (inst) {
                        inst.on('change', toggleFields);
                    }
                }, 500);

                toggleFields();
            }
        });
    </script>

        <script>
            // Lógica para mostrar/ocultar y habilitar/deshabilitar campos condicionales
            document.addEventListener('DOMContentLoaded', function() {
                // Función auxiliar para encontrar el contenedor padre de un campo
                const getFieldContainer = (field) => {
                    const localWrapper = field.closest('.crud-field-wrapper');
                    if (localWrapper) {
                        return localWrapper;
                    }

                    // Fallback para campos antiguos sin wrapper dedicado.
                    let parent = field.closest('[class*="col-span"]');
                    return parent || field.parentElement;
                };

                // Para Número relacionado con SimCard
                const checkboxDesearelacionarSimcard = document.querySelector('input[name="desea_relacionar_simcard"], input[name="desea_relacionar_simcard[]"]');
                if (checkboxDesearelacionarSimcard) {
                    const simcardFields = document.querySelectorAll('[data-quick-create-simcard="true"]');
                    const updateSimcardFieldsState = () => {
                        const isChecked = checkboxDesearelacionarSimcard.checked;
                        simcardFields.forEach(field => {
                            const container = getFieldContainer(field);
                            if (isChecked) {
                                // Mostrar y habilitar
                                container.style.display = '';
                                field.disabled = false;
                                field.classList.remove('bg-slate-200', 'cursor-not-allowed');
                            } else {
                                // Ocultar y deshabilitar
                                container.style.display = 'none';
                                field.disabled = true;
                                field.classList.add('bg-slate-200', 'cursor-not-allowed');
                            }
                        });
                    };
                    
                    checkboxDesearelacionarSimcard.addEventListener('change', updateSimcardFieldsState);
                    updateSimcardFieldsState(); // Inicializar estado
                }

                // Para SimCard relacionado con Número
                const checkboxDesearelacionarNumero = document.querySelector('input[name="desea_relacionar_numero"], input[name="desea_relacionar_numero[]"]');
                if (checkboxDesearelacionarNumero) {
                    const numeroFields = document.querySelectorAll('[data-quick-create-numero="true"]');
                    const updateNumeroFieldsState = () => {
                        const isChecked = checkboxDesearelacionarNumero.checked;
                        numeroFields.forEach(field => {
                            const container = getFieldContainer(field);
                            if (isChecked) {
                                // Mostrar y habilitar
                                container.style.display = '';
                                field.disabled = false;
                                field.classList.remove('bg-slate-100', 'cursor-not-allowed');
                            } else {
                                // Ocultar y deshabilitar
                                container.style.display = 'none';
                                field.disabled = true;
                                field.classList.add('bg-slate-100', 'cursor-not-allowed');
                            }
                        });
                    };
                    
                    checkboxDesearelacionarNumero.addEventListener('change', updateNumeroFieldsState);
                    updateNumeroFieldsState(); // Inicializar estado
                }
            });
        </script>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const quickButton = document.getElementById('quick-detalle-lista-precio-button');
                if (!quickButton) {
                    return;
                }

                const mainForm = document.getElementById('main-crud-form');
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                const parseJson = (value) => {
                    try {
                        const parsed = JSON.parse(String(value || '[]'));
                        return Array.isArray(parsed) ? parsed : [];
                    } catch {
                        return [];
                    }
                };
                const formatMoney = (value) => {
                    const numeric = Number(value);
                    if (!Number.isFinite(numeric)) {
                        return 'S/ 0,00';
                    }
                    return 'S/ ' + numeric.toLocaleString('es-PE', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                    });
                };
                const ensurePayloadInput = (inputName) => {
                    if (!mainForm) {
                        return null;
                    }

                    let input = mainForm.querySelector('input[name="' + inputName + '"]');
                    if (!input) {
                        input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = inputName;
                        input.value = '[]';
                        mainForm.appendChild(input);
                    }

                    return input;
                };

                const ensureSelectedInput = (inputName) => {
                    if (!mainForm) return null;
                    const name = String(inputName || 'detalle_lista_precio_selected');
                    let input = mainForm.querySelector('input[name="' + name + '"]');
                    if (!input) {
                        input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = name;
                        input.value = '';
                        mainForm.appendChild(input);
                    }
                    return input;
                };

                const modal = document.getElementById('quick-detalle-lista-precio-modal');
                if (!modal) {
                    return;
                }

                if (modal.parentElement !== document.body) {
                    document.body.appendChild(modal);
                }

                const listSelect = modal.querySelector('#quick-detalle-lista');
                const priceInput = modal.querySelector('#quick-detalle-precio');
                const addButton = modal.querySelector('#quick-detalle-add');
                const closeButton = modal.querySelector('#quick-detalle-close');
                const cancelButton = modal.querySelector('#quick-detalle-cancel');
                const feedback = modal.querySelector('#quick-detalle-feedback');
                const pendingList = modal.querySelector('#quick-detalle-pending-list');
                const savedList = modal.querySelector('#quick-detalle-saved-list');
                const almacenBadge = modal.querySelector('#quick-detalle-almacen');
                const pendingCount = modal.querySelector('#quick-detalle-pending-count');
                const savedCount = modal.querySelector('#quick-detalle-saved-count');
                const searchInput = modal.querySelector('#quick-detalle-search');
                const mainPriceInput = document.querySelector('input[name="precio"]');
                let tomSelectInstance = null;
                let currentPayloadInput = null;
                let currentPayloadInputName = 'detalle_lista_precio_payload';
                let currentPendingItems = [];
                let currentSavedItems = [];
                let currentListOptions = [];
                let editingTempId = null;
                let selectedTempId = null;
                let selectedInput = null;
                let suppressMainPriceSync = false;

                const normalizeOptionLabel = (item) => {
                    const value = String(item?.value ?? item?.id ?? '');
                    const label = String(item?.label ?? item?.text ?? '');
                    return {
                        value: value,
                        label: label !== '' ? label : ('Lista #' + value),
                    };
                };

                const normalizePayloadItem = (item) => {
                    const listId = String(item?.ListaPrecio_idListaPrecio ?? item?.listaprecio_id ?? item?.idListaPrecio ?? '');
                    const price = String(item?.precio ?? '').trim();
                    return {
                        tempId: String(item?.tempId ?? item?.id ?? ('tmp-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8))),
                        ListaPrecio_idListaPrecio: listId,
                        listaprecio_nombre: String(item?.listaprecio_nombre ?? item?.label ?? ''),
                        precio: price,
                    };
                };

                const readPayload = () => {
                    if (!currentPayloadInput) {
                        return [];
                    }

                    return parseJson(currentPayloadInput.value).map(normalizePayloadItem).filter((item) => item.ListaPrecio_idListaPrecio !== '');
                };

                const writePayload = () => {
                    if (!currentPayloadInput) {
                        return;
                    }

                    currentPayloadInput.value = JSON.stringify(currentPendingItems.map((item) => ({
                        tempId: item.tempId,
                        ListaPrecio_idListaPrecio: item.ListaPrecio_idListaPrecio,
                        listaprecio_nombre: item.listaprecio_nombre,
                        precio: item.precio,
                    })));
                };

                const getListLabel = (listId) => {
                    const match = currentListOptions.find((item) => String(item.value) === String(listId));
                    if (match) {
                        return match.label;
                    }

                    return 'Lista #' + String(listId);
                };

                const buildRow = (item, type) => {
                    const label = type === 'saved'
                        ? String(item.listaprecio_nombre || getListLabel(item.ListaPrecio_idListaPrecio))
                        : String(item.listaprecio_nombre || getListLabel(item.ListaPrecio_idListaPrecio));
                    const price = formatMoney(item.precio);
                    const row = document.createElement('div');
                    const isSelected = selectedTempId !== null && String(item.tempId) === String(selectedTempId);

                    row.className = 'flex items-center justify-between mb-3 gap-4 rounded-md border p-3 transition ' +
                        (isSelected ? 'border-primary bg-red-50/50 ring-1 ring-inset ring-red-200' : 'border-slate-200 bg-white hover:border-slate-300');

                    const text = document.createElement('div');
                    text.className = 'min-w-0 flex-1';
                    text.innerHTML = '<div class="truncate text-sm font-semibold text-slate-800">' + label + '</div>' +
                        '<div class="text-xs text-slate-500">Precio: ' + price + '</div>';

                    row.appendChild(text);

                    const populateEditor = () => {
                        if (tomSelectInstance && typeof tomSelectInstance.setValue === 'function') {
                            tomSelectInstance.setValue(String(item.ListaPrecio_idListaPrecio), true);
                        } else if (listSelect) {
                            listSelect.value = String(item.ListaPrecio_idListaPrecio);
                        }

                        if (priceInput) {
                            priceInput.value = String(item.precio ?? '');
                        }

                        editingTempId = String(item.tempId);

                        if (feedback) {
                            feedback.textContent = 'Editando detalle seleccionado.';
                            feedback.className = 'text-sm text-emerald-600';
                        }
                    };

                    if (type === 'pending') {
                        const actions = document.createElement('div');
                        actions.className = 'flex items-center gap-2';

                        const selectButton = document.createElement('button');
                        selectButton.type = 'button';
                        selectButton.className = 'rounded border px-2 py-1 text-[11px] font-semibold shadow-sm transition';
                        selectButton.textContent = isSelected ? 'Seleccionado' : 'Seleccionar';
                        if (isSelected) {
                            selectButton.disabled = true;
                            selectButton.className += ' bg-primary text-white';
                        } else {
                            selectButton.className += ' border-slate-200 bg-white text-slate-700 hover:bg-slate-50';
                        }
                        selectButton.addEventListener('click', () => {
                            const nextSelectedTempId = String(item.tempId);
                            selectedInput = selectedInput || ensureSelectedInput(currentPayloadInputName + '_selected');

                            if (mainPriceInput) {
                                suppressMainPriceSync = true;
                                mainPriceInput.value = String(item.precio ?? '');
                                mainPriceInput.dispatchEvent(new Event('input', { bubbles: true }));
                                mainPriceInput.dispatchEvent(new Event('change', { bubbles: true }));
                                suppressMainPriceSync = false;
                            }

                            selectedTempId = nextSelectedTempId;
                            if (selectedInput) selectedInput.value = String(selectedTempId);

                            closeModal();
                        });

                        actions.appendChild(selectButton);

                        const editButton = document.createElement('button');
                        editButton.type = 'button';
                        editButton.className = 'rounded border border-slate-200 bg-slate-50 px-2 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-100';
                        editButton.textContent = 'Editar';
                        editButton.addEventListener('click', populateEditor);

                        const removeButton = document.createElement('button');
                        removeButton.type = 'button';
                        removeButton.className = 'rounded border border-red-200 bg-red-50 px-2 py-1 text-[11px] font-semibold text-red-700 hover:bg-red-100';
                        removeButton.textContent = 'Quitar';
                        removeButton.addEventListener('click', () => {
                            currentPendingItems = currentPendingItems.filter((entry) => String(entry.tempId) !== String(item.tempId));
                            writePayload();
                            renderLists();
                        });

                        actions.appendChild(editButton);
                        actions.appendChild(removeButton);
                        row.appendChild(actions);
                    } else {
                        row.addEventListener('click', populateEditor);
                        row.classList.add('cursor-pointer', 'transition', 'hover:border-red-300', 'hover:bg-red-50/40');
                    }

                    return row;
                };

                const renderLists = () => {
                    const term = String(searchInput?.value || '').trim().toLowerCase();
                    const visibleItems = term === ''
                        ? currentPendingItems
                        : currentPendingItems.filter((item) => {
                            const label = String(item.listaprecio_nombre || getListLabel(item.ListaPrecio_idListaPrecio)).toLowerCase();
                            const price = String(item.precio || '').toLowerCase();
                            return label.includes(term) || price.includes(term);
                        });

                    if (pendingCount) {
                        pendingCount.textContent = String(visibleItems.length);
                    }
                    if (savedCount) {
                        savedCount.textContent = String(currentSavedItems.length);
                    }

                    const workingList = savedList || pendingList;
                    if (workingList) {
                        workingList.innerHTML = '';
                        if (visibleItems.length === 0) {
                            workingList.innerHTML = '<div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-500">Todavía no has agregado detalles de precio.</div>';
                        } else {
                            visibleItems.forEach((item) => {
                                workingList.appendChild(buildRow(item, 'pending'));
                            });
                        }
                    }
                };

                const initTomSelect = () => {
                    if (!listSelect || typeof window.TomSelect !== 'function') {
                        return;
                    }

                    if (listSelect.tomselect) {
                        tomSelectInstance = listSelect.tomselect;
                        return;
                    }

                    if (tomSelectInstance && typeof tomSelectInstance.destroy === 'function') {
                        tomSelectInstance.destroy();
                    }

                    tomSelectInstance = new TomSelect(listSelect, {
                        create: false,
                        allowEmptyOption: true,
                        maxOptions: 1000,
                        searchField: ['text'],
                        sortField: { field: 'text', direction: 'asc' },
                    });
                };

                const syncTomSelectOptions = () => {
                    if (!listSelect) {
                        return;
                    }

                    const options = currentListOptions.map((item) => ({
                        value: String(item.value),
                        text: String(item.label),
                    }));

                    if (tomSelectInstance) {
                        if (typeof tomSelectInstance.clear === 'function') {
                            tomSelectInstance.clear(true);
                        }
                        if (typeof tomSelectInstance.clearOptions === 'function') {
                            tomSelectInstance.clearOptions();
                        }
                        options.forEach((option) => {
                            if (typeof tomSelectInstance.addOption === 'function') {
                                tomSelectInstance.addOption(option);
                            }
                        });
                        if (typeof tomSelectInstance.refreshOptions === 'function') {
                            tomSelectInstance.refreshOptions(false);
                        }

                        const wrapper = listSelect.nextElementSibling;
                        const dropdownEl = wrapper?.querySelector('.ts-dropdown');
                        const dropdownContent = dropdownEl?.querySelector('.ts-dropdown-content');
                        if (dropdownContent) {
                            dropdownContent.style.maxHeight = '128px';
                            dropdownContent.style.overflowY = 'auto';
                        }

                        return;
                    }

                    listSelect.innerHTML = '<option value="">Selecciona una lista de precio</option>';
                    options.forEach((option) => {
                        const element = document.createElement('option');
                        element.value = option.value;
                        element.textContent = option.text;
                        listSelect.appendChild(element);
                    });
                };

                const closeModal = () => {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                    document.body.style.overflow = '';
                    editingTempId = null;
                    if (feedback) {
                        feedback.textContent = '';
                        feedback.className = 'text-sm text-slate-500';
                    }
                };

                const openModal = (button) => {
                    currentPayloadInputName = button.getAttribute('data-quick-detalle-payload-input') || 'detalle_lista_precio_payload';
                    currentPayloadInput = ensurePayloadInput(currentPayloadInputName);
                    currentListOptions = parseJson(button.getAttribute('data-quick-detalle-list-options') || '[]').map(normalizeOptionLabel);
                    currentSavedItems = parseJson(button.getAttribute('data-quick-detalle-existing') || '[]').map(normalizePayloadItem);
                    currentPendingItems = readPayload();

                    // Ensure selected input exists and restore selection if any
                    selectedInput = ensureSelectedInput(currentPayloadInputName + '_selected');
                    selectedTempId = selectedInput && String(selectedInput.value || '').trim() !== '' ? String(selectedInput.value) : null;

                    if (currentPendingItems.length === 0 && currentSavedItems.length > 0) {
                        currentPendingItems = currentSavedItems.map((item) => ({
                            tempId: String(item.tempId),
                            ListaPrecio_idListaPrecio: String(item.ListaPrecio_idListaPrecio),
                            listaprecio_nombre: String(item.listaprecio_nombre),
                            precio: String(item.precio),
                        }));
                    }

                    editingTempId = null;

                    if (currentPayloadInput && currentPayloadInput.value.trim() === '') {
                        currentPayloadInput.value = '[]';
                    }

                    if (almacenBadge) {
                        const almacenLabel = String(button.getAttribute('data-quick-detalle-almacen-label') || '').trim();
                        const almacenId = String(button.getAttribute('data-quick-detalle-almacen-id') || '').trim();
                        almacenBadge.textContent = almacenLabel !== ''
                            ? almacenLabel + (almacenId !== '' ? ' (' + almacenId + ')' : '')
                            : 'Se asignará automáticamente al guardar el almacén.';
                    }

                    initTomSelect();
                    syncTomSelectOptions();

                    renderLists();

                    if (priceInput) {
                        priceInput.value = '';
                    }
                    if (listSelect) {
                        listSelect.value = '';
                    }
                    if (feedback) {
                        feedback.textContent = '';
                        feedback.className = 'text-sm text-slate-500';
                    }

                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                    document.body.style.overflow = 'hidden';
                };

                closeButton?.addEventListener('click', closeModal);
                cancelButton?.addEventListener('click', closeModal);

                const clearSelectedDetail = () => {
                    if (suppressMainPriceSync) {
                        return;
                    }

                    selectedTempId = null;
                    if (selectedInput) {
                        selectedInput.value = '';
                    }
                };

                mainPriceInput?.addEventListener('input', clearSelectedDetail);
                mainPriceInput?.addEventListener('change', clearSelectedDetail);

                addButton?.addEventListener('click', () => {
                    const listId = String(listSelect?.value ?? '').trim();
                    const priceValue = String(priceInput?.value ?? '').trim();

                    if (listId === '' || priceValue === '') {
                        if (feedback) {
                            feedback.textContent = 'Selecciona una lista de precio y escribe un precio antes de guardar.';
                            feedback.className = 'text-sm text-red-600';
                        }
                        return;
                    }

                    const label = getListLabel(listId);
                    const existingIndex = editingTempId !== null
                        ? currentPendingItems.findIndex((entry) => String(entry.tempId) === String(editingTempId))
                        : currentPendingItems.findIndex((entry) => String(entry.ListaPrecio_idListaPrecio) === String(listId));
                    const nextItem = {
                        tempId: existingIndex >= 0 ? currentPendingItems[existingIndex].tempId : ('tmp-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8)),
                        ListaPrecio_idListaPrecio: listId,
                        listaprecio_nombre: label,
                        precio: priceValue,
                    };

                    if (existingIndex >= 0) {
                        currentPendingItems[existingIndex] = nextItem;
                    } else {
                        currentPendingItems.push(nextItem);
                    }

                    writePayload();
                    renderLists();
                    editingTempId = null;

                    // Mark newly created item as selected automatically
                    selectedTempId = nextItem.tempId;
                    selectedInput = selectedInput || ensureSelectedInput(currentPayloadInputName + '_selected');
                    if (selectedInput) selectedInput.value = String(selectedTempId);

                    if (mainPriceInput) {
                        suppressMainPriceSync = true;
                        mainPriceInput.value = priceValue;
                        mainPriceInput.dispatchEvent(new Event('input', { bubbles: true }));
                        mainPriceInput.dispatchEvent(new Event('change', { bubbles: true }));
                        suppressMainPriceSync = false;
                    }

                    if (tomSelectInstance && typeof tomSelectInstance.clear === 'function') {
                        tomSelectInstance.clear(true);
                    } else if (listSelect) {
                        listSelect.value = '';
                    }

                    if (priceInput) {
                        priceInput.value = '';
                    }

                    if (feedback) {
                        feedback.textContent = 'Detalle agregado a la lista temporal.';
                        feedback.className = 'text-sm text-emerald-600';
                    }

                    closeModal();
                });

                searchInput?.addEventListener('input', () => {
                    renderLists();
                });

                quickButton.addEventListener('click', () => {
                    if (quickButton.disabled) {
                        return;
                    }

                    openModal(quickButton);
                });
            });
        </script>

        <script>
            // Rehidrata payloads temporales en los TomSelect del formulario principal
            document.addEventListener('DOMContentLoaded', function () {
                const mainForm = document.getElementById('main-crud-form');
                if (!mainForm) return;

                const parseJson = (value) => {
                    try {
                        const parsed = JSON.parse(String(value || '[]'));
                        return Array.isArray(parsed) ? parsed : [];
                    } catch {
                        return [];
                    }
                };

                const addOptionToSelect = (select, id, label) => {
                    if (!select) return;
                    const inst = select.tomselect || select.tomSelect || select._tomselect || null;
                    try {
                        if (inst && typeof inst.addOption === 'function') {
                            inst.addOption({ value: String(id), text: String(label) }, true);
                        } else {
                            let opt = select.querySelector('option[value="' + id + '"]');
                            if (!opt) {
                                opt = document.createElement('option');
                                opt.value = String(id);
                                select.appendChild(opt);
                            }
                            opt.textContent = String(label);
                        }
                    } catch (e) {
                        // noop
                    }
                };

                const ensureSelected = (select, id) => {
                    if (!select || !id) return;
                    const inst = select.tomselect || select.tomSelect || select._tomselect || null;
                    try {
                        if (inst && typeof inst.setValue === 'function') {
                            inst.setValue(String(id));
                        } else if (inst && typeof inst.addItem === 'function') {
                            inst.addItem(String(id));
                        } else {
                            select.value = String(id);
                        }
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                    } catch (e) {}
                };

                const rehydrate = (payloadName, selectName, labelBuilder) => {
                    const payloadInput = mainForm.querySelector('input[name="' + payloadName + '"]');
                    const select = document.querySelector('select[name="' + selectName + '"]');
                    if (!payloadInput || !select) return;

                    const items = parseJson(payloadInput.value);
                    if (!items || items.length === 0) return;

                    // Añade las opciones temporales desde el payload
                    items.forEach(function (item, idx) {
                        const id = String(item.tempId ?? item.id ?? ('tmp-' + idx));
                        let label = '';
                        try {
                            label = labelBuilder(item) || item.label || item.text || id;
                        } catch (e) {
                            label = item.label || id;
                        }
                        addOptionToSelect(select, id, label);
                    });

                    // Si el select tiene un valor actual que coincide con un temp id, asegurarlo como seleccionado
                    const currentVal = String(select.value || '');
                    if (currentVal) {
                        const found = items.find((it, ix) => String(it.tempId ?? it.id ?? ('tmp-' + ix)) === currentVal);
                        if (found) {
                            ensureSelected(select, currentVal);
                        }
                    }
                };

                // Builders para etiquetas (fallbacks simples)
                const buildAddressLabel = (it) => {
                    const direccion = String(it.direccion || it.label || 'Dirección temporal').trim();
                    const ubigeo = String(it.ubigeo_text || '').trim();
                    return ubigeo ? direccion + ' (' + ubigeo + ')' : direccion;
                };

                const buildContactLabel = (it) => {
                    const nombre = String(it.nombreApellido || it.label || 'Contacto temporal').trim();
                    const tipo = String(it.tipoContacto_label || it.tipo || '').trim();
                    const numero = String(it.numero || '').trim();
                    let lbl = nombre;
                    if (tipo) lbl += ' (' + tipo + ')';
                    if (numero) lbl += ' - ' + numero;
                    return lbl;
                };

                const buildCredentialLabel = (it) => {
                    const usuario = String(it.usuario || it.label || 'Credencial temporal').trim();
                    const estado = String(it.estadoRecepcion || it.estado || '') === '1' ? 'Sí' : 'No';
                    const fecha = String(it.fechaCreacion || '').trim();
                    let lbl = usuario + ' - ' + estado;
                    if (fecha) lbl += ' - ' + fecha;
                    return lbl;
                };

                // Ejecutar rehidratación para direcciones, contactos y credenciales
                rehydrate('direcciones_payload', 'direccionCliente_iddireccionCliente', buildAddressLabel);
                rehydrate('contactos_payload', 'contactoSeleccionado', buildContactLabel);
                rehydrate('credenciales_payload', 'credencialSeleccionada', buildCredentialLabel);
            });
        </script>
        @includeIf('cliente.relation-panel-script')
        <script>
            // Autocompletar dirección, teléfono y correo al seleccionar cliente
            document.addEventListener('DOMContentLoaded', function () {
                const clienteSelect = document.querySelector('select[name="cliente_idcliente"], input[name="cliente_idcliente"]');
                const tipoClienteInput = document.querySelector('input[name="tipoDocumentoIDCliente"]');

                function fillClientInfo(data, overwrite = false) {
                    try {
                        if (!data || !data.data) return;
                        const info = data.data;
                        const dir = document.querySelector('input[name="direccion"]');
                        const tel = document.querySelector('input[name="telefono"]');
                        const mail = document.querySelector('input[name="correo"]');
                        const ruc = document.querySelector('input[name="rucDni"]') || document.querySelector('input[name="ruc_dni"]') || document.querySelector('input[name="ruc"]');

                        // Limpiar siempre los campos antes de rellenar con el nuevo cliente
                        if (overwrite) {
                            if (dir) dir.value = '';
                            if (tel) tel.value = '';
                            if (mail) mail.value = '';
                            if (ruc) ruc.value = '';
                        }

                        if (dir && (overwrite || String(dir.value).trim() === '')) {
                            dir.value = info.direccion || '';
                        }
                        if (tel && (overwrite || String(tel.value).trim() === '')) {
                            tel.value = info.telefono || '';
                        }
                        if (mail && (overwrite || String(mail.value).trim() === '')) {
                            mail.value = info.correo || '';
                        }
                        if (tipoClienteInput) {
                            const clienteId = info.clienteId ?? '';
                            if (typeof clienteId === 'string') {
                                const normalized = clienteId.trim();
                                if (normalized.length === 8) {
                                    tipoClienteInput.value = 'DNI';
                                } else if (normalized.length > 8) {
                                    tipoClienteInput.value = 'RUC';
                                } else {
                                    tipoClienteInput.value = '';
                                }
                            } else {
                                tipoClienteInput.value = '';
                            }
                        }
                    } catch (e) {
                        console.error(e);
                    }
                }


                async function fetchClienteInfo(clienteId, overwrite = true) {
                    try {
                        if (!clienteId) {
                            fillClientInfo({ data: { direccion: '', correo: '', telefono: '', clienteId: '' } }, overwrite);
                            return;
                        }
                        const url = '/modulos/ventas/cotizaciones/cliente/' + encodeURIComponent(String(clienteId)) + '/info';
                        const res = await fetch(url, { credentials: 'same-origin' });
                        if (!res.ok) return;
                        const json = await res.json();
                        if (json && json.ok) {
                            json.data.clienteId = clienteId;
                            fillClientInfo(json, overwrite);
                        }
                    } catch (e) { console.error(e); }
                }

                const clienteIdVisual = document.getElementById('cliente-idcliente-visual');

                function syncClienteIdVisual(clienteId) {
                    if (clienteIdVisual) {
                        clienteIdVisual.value = clienteId || '';
                    }
                }

                // TomSelect may wrap the real select; listen change on the select/input itself
                clienteSelect.addEventListener('change', function () {
                    const val = this.value || '';
                    syncClienteIdVisual(val);
                    fetchClienteInfo(val, true);
                });

                // Si ya hay un cliente seleccionado al cargar la página, obtener su info
                try {
                    const initial = clienteSelect.value || '';
                    syncClienteIdVisual(initial);
                    if (initial) fetchClienteInfo(initial, false);
                } catch (e) { /* noop */ }            });
    </script>
@endsection

@push('styles')
    <style>
        /* Permitir que los labels largos en formularios se quiebren y no desborden */
        /* Selectores específicos para no romper otras partes del layout */
        label.block > .flex > span:first-child {
            display: inline-block;
            white-space: normal !important;
            overflow-wrap: anywhere !important;
            word-break: break-word !important;
            max-width: 100%;
        }
        /* Si hay un control al lado (radio/checkbox), permitir que la línea se divida correctamente */
        label.block > .flex { align-items: flex-start; }
    </style>
@endpush