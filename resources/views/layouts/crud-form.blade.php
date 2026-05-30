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
    </nav>
@endsection

@php
    $quickDireccionField = collect($fields ?? [])->first(fn ($field) => ($field['quickCreate'] ?? false) === true);
    $quickContactoField = collect($fields ?? [])->first(fn ($field) => ($field['quickCreateContact'] ?? false) === true);
    $quickCredencialField = collect($fields ?? [])->first(fn ($field) => ($field['quickCreateCredential'] ?? false) === true);
    $quickEstadoField = collect($fields ?? [])->first(fn ($field) => ($field['quickCreateEstado'] ?? false) === true);
    $quickCargoField = collect($fields ?? [])->first(fn ($field) => ($field['quickCreateCargo'] ?? false) === true);
    $quickDispositivoField = collect($fields ?? [])->first(fn ($field) => ($field['quickCreateDispositivo'] ?? false) === true);
    $quickDetalleListaPrecioField = collect($fields ?? [])->first(fn ($field) => ($field['quickCreateDetalleListaPrecio'] ?? false) === true);
    $hasQuickDireccion = !empty($quickDireccionField);
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
            align-items: center;
            gap: 0.625rem;
            padding: 0.50rem 0.9rem;
            border: 1px solid #cbd5e1;
            border-radius: 0.35rem;
            background-color: #ffffff;
            cursor: pointer;
            transition: background-color 0.2s ease, border-color 0.2s ease;
            white-space: nowrap;
            box-sizing: border-box;
            width: auto;
        }
        .checkbox-object-item {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.45rem 0.65rem;
            min-width: 7rem;
            height: 3rem;
            border: 1px solid #e2e8f0;
            border-radius: 0.35rem;
            background-color: #ffffff;
            color: #0f172a;
            position: relative;
            cursor: pointer;
            user-select: none;
            transition: background-color 0.2s ease, border-color 0.2s ease, transform 0.15s ease;
        }
        .checkbox-object-item:hover {
            background-color: #f8fafc;
            transform: translateY(-0.5px);
        }
        .tom-select.tom-select--compact.ts-wrapper,
        .tom-select.tom-select--compact.ts-wrapper .ts-control,
        .tom-select.tom-select--compact.plugin-dropdown_input.focus.dropdown-active .ts-control {
            min-height: 2.2rem !important;
            height: 2.2rem !important;
            padding: 0.2rem 0.75rem !important;
            line-height: 1.2rem !important;
        }
        .tom-select.tom-select--compact.ts-wrapper .ts-control {
            min-height: 2.2rem !important;
            height: 2.2rem !important;
            padding: 0.3rem 0.75rem 0.1rem 0.75rem !important;
            line-height: 1.2rem !important;
            align-items: flex-start !important;
        }

        .tom-select.tom-select--compact.ts-wrapper .ts-control .items,
        .tom-select.tom-select--compact.ts-wrapper .ts-control .item {
            min-height: 2rem !important;
            height: auto !important;
            line-height: 1.2rem !important;
            margin: 0 !important;
        }
        .tom-select.tom-select--compact.ts-wrapper .ts-control .item {
            padding: 0 .35rem !important;
        }
        .checkbox-object-content {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            min-width: 0;
        }
        .checkbox-object-title {
            display: block;
            font-size: 0.92rem;
            font-weight: 600;
            color: #111827;
            line-height: 1.1;
            white-space: normal;
            overflow-wrap: anywhere;
        }
        .crud-field-wrapper {
            min-width: 0;
        }
        .role-cards-grid {
            display: grid;
            grid-template-columns: repeat(7, max-content);
            gap: 0.45rem;
            justify-items: start;
            align-items: center;
        }
        @media (max-width: 1024px) {
            .role-cards-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                justify-items: stretch;
            }
            .role-cards-grid .checkbox-object-item {
                width: 100%;
                min-width: 0;
                justify-content: flex-start;
            }
        }
        @media (max-width: 640px) {
            .role-cards-grid {
                grid-template-columns: 1fr;
                gap: 0.55rem;
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
            .vista-selected-head {
                display: none;
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
        .permission-action-checkbox-wrapper {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.2rem;
            height: 1.2rem;
            border: 1px solid #cbd5e1;
            border-radius: 9999px;
            background-color: #ffffff;
            transition: border-color 0.2s ease, background-color 0.2s ease;
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
            width: 0.95rem;
            height: 0.95rem;
            border-radius: 9999px;
            border: 1px solid transparent;
            background-color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
            transition: background-color 0.2s ease, border-color 0.2s ease;
            z-index: 1;
        }
        .permission-action-checkbox-box::after {
            content: '';
            width: 0.42rem;
            height: 0.42rem;
            border-radius: 9999px;
            background-color: transparent;
            transition: background-color 0.2s ease, transform 0.2s ease;
            transform: scale(0);
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
                -webkit-overflow-scrolling: touch;
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
            
            .permissions-card-table {
                min-width: 460px;
                font-size: 0.82rem;
            }
            .permissions-card-table thead th {
                font-size: 0.72rem;
                white-space: nowrap;
            }
            .permissions-card-table th:first-child,
            .permissions-card-table td:first-child {
                min-width: 120px;
            }
            .permissions-action-cell {
                width: 40px;
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

            .permissions-card-table {
                min-width: 380px;
                font-size: 0.72rem;
            }

            .permissions-card-table thead th {
                font-size: 0.68rem;
            }

            .permissions-card-table th:first-child,
            .permissions-card-table td:first-child {
                min-width: 108px;
            }

            .permissions-action-cell {
                width: 38px;
            }
        }

        .credential-row-disabled {
            opacity: 0.55;
            transition: opacity 0.2s ease;
        }

        .credential-row-disabled input {
            cursor: not-allowed;
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var getToggleActionInputs = function (toggle, card) {
                if (!toggle || !card) {
                    return [];
                }

                if (toggle.dataset.scope === 'group' && toggle.dataset.group) {
                    return Array.from(card.querySelectorAll('input.permission-switch-input[data-group="' + toggle.dataset.group + '"]'));
                }

                return Array.from(card.querySelectorAll('input.permission-switch-input'));
            };

            document.querySelectorAll('fieldset[data-permissions-fieldset] .permissions-card').forEach(function (card) {
                var toggles = Array.from(card.querySelectorAll('.module-select-all'));
                if (toggles.length === 0) {
                    return;
                }

                var updateModuleToggles = function () {
                    toggles.forEach(function (toggle) {
                        var actionInputs = getToggleActionInputs(toggle, card);
                        var allChecked = actionInputs.length > 0 && actionInputs.every(function (input) {
                            return input.checked;
                        });
                        var someChecked = actionInputs.some(function (input) {
                            return input.checked;
                        });
                        toggle.checked = allChecked;
                        toggle.indeterminate = !allChecked && someChecked;
                    });
                };

                card.querySelectorAll('input.permission-switch-input:not([disabled])').forEach(function (input) {
                    input.addEventListener('change', updateModuleToggles);
                });

                toggles.forEach(function (toggle) {
                    toggle.addEventListener('change', function () {
                        var actionInputs = getToggleActionInputs(toggle, card).filter(function (input) {
                            return !input.disabled;
                        });
                        actionInputs.forEach(function (input) {
                            input.checked = toggle.checked;
                        });
                        updateModuleToggles();
                        if (typeof window.unlockRoleForManualEdit === 'function') {
                            window.unlockRoleForManualEdit();
                        }
                        if (typeof window.updateCredentialRows === 'function') {
                            window.updateCredentialRows();
                        }
                        if (typeof window.updateVistaSelectorVisibility === 'function') {
                            window.updateVistaSelectorVisibility();
                        }
                    });
                });

                updateModuleToggles();
            });

            document.querySelectorAll('input.permission-switch-input[data-on-label]').forEach(function (input) {
                var label = input.closest('.permission-switch')?.querySelector('.switch-label');
                if (!label) {
                    return;
                }

                var updateLabel = function () {
                    label.textContent = input.checked ? input.dataset.onLabel : input.dataset.offLabel;
                };

                input.addEventListener('change', updateLabel);
                updateLabel();
            });

            var initializePermissionAccordions = function () {
                document.querySelectorAll('fieldset[data-permissions-fieldset] .permissions-card').forEach(function (card) {
                    var toggle = card.querySelector('.permissions-card-toggle');
                    if (!toggle) {
                        return;
                    }

                    card.classList.remove('collapsed');
                    toggle.setAttribute('aria-expanded', 'true');
                    toggle.querySelector('.permissions-card-toggle-label').textContent = 'Ocultar';
                    toggle.querySelector('.permissions-card-toggle-icon').textContent = '▴';

                    toggle.addEventListener('click', function () {
                        var isCollapsed = card.classList.toggle('collapsed');
                        toggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
                        var labelEl = toggle.querySelector('.permissions-card-toggle-label');
                        var iconEl = toggle.querySelector('.permissions-card-toggle-icon');
                        if (labelEl) labelEl.textContent = isCollapsed ? 'Mostrar' : 'Ocultar';
                        if (iconEl) iconEl.textContent = isCollapsed ? '▾' : '▴';
                    });
                });
            };

            var updateConfiguracionGroupExpansion = function () {
                document.querySelectorAll('.permissions-config-group[data-config-group]').forEach(function (group) {
                    var toggle = group.querySelector('[data-config-group-toggle]');
                    if (!toggle) {
                        return;
                    }

                    var labelEl = toggle.querySelector('.permissions-config-group-toggle-label');
                    var iconEl = toggle.querySelector('.permissions-config-group-toggle-icon');
                    var groupInputs = Array.from(group.querySelectorAll('input.permission-switch-input'));
                    var anyChecked = groupInputs.some(function (input) { return input.checked; });
                    var shouldCollapse = !anyChecked;

                    group.classList.toggle('collapsed', shouldCollapse);
                    toggle.setAttribute('aria-expanded', shouldCollapse ? 'false' : 'true');
                    if (labelEl) labelEl.textContent = shouldCollapse ? 'Mostrar' : 'Ocultar';
                    if (iconEl) iconEl.textContent = shouldCollapse ? '▾' : '▴';
                });
            };

            var initializeConfiguracionGroupAccordions = function () {
                document.querySelectorAll('.permissions-config-group[data-config-group]').forEach(function (group) {
                    var toggle = group.querySelector('[data-config-group-toggle]');
                    if (!toggle) {
                        return;
                    }

                    toggle.addEventListener('click', function () {
                        var isCollapsed = group.classList.toggle('collapsed');
                        toggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
                        var labelEl = toggle.querySelector('.permissions-config-group-toggle-label');
                        var iconEl = toggle.querySelector('.permissions-config-group-toggle-icon');
                        if (labelEl) labelEl.textContent = isCollapsed ? 'Mostrar' : 'Ocultar';
                        if (iconEl) iconEl.textContent = isCollapsed ? '▾' : '▴';
                    });
                });
            };

            var syncModuleToggleStates = function () {
                document.querySelectorAll('fieldset[data-permissions-fieldset] .permissions-card').forEach(function (card) {
                    var toggles = Array.from(card.querySelectorAll('.module-select-all'));
                    if (toggles.length === 0) {
                        return;
                    }

                    toggles.forEach(function (toggle) {
                        var actionInputs = getToggleActionInputs(toggle, card);
                        var allChecked = actionInputs.length > 0 && actionInputs.every(function (input) {
                            return input.checked;
                        });
                        var someChecked = actionInputs.some(function (input) {
                            return input.checked;
                        });

                        toggle.checked = allChecked;
                        toggle.indeterminate = !allChecked && someChecked;
                    });
                });
            };

            var updatePermissionCardExpansion = function () {
                document.querySelectorAll('fieldset[data-permissions-fieldset] .permissions-card').forEach(function (card) {
                    var toggle = card.querySelector('.permissions-card-toggle');
                    if (!toggle) {
                        return;
                    }

                    var anyChecked = Array.from(card.querySelectorAll('input.permission-switch-input')).some(function (input) {
                        return input.checked;
                    });

                    if (anyChecked) {
                        card.classList.remove('collapsed');
                        toggle.setAttribute('aria-expanded', 'true');
                        toggle.querySelector('.permissions-card-toggle-label').textContent = 'Ocultar';
                        toggle.querySelector('.permissions-card-toggle-icon').textContent = '▴';
                    } else {
                        var isCollapsed = card.classList.contains('collapsed');
                        toggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
                        toggle.querySelector('.permissions-card-toggle-label').textContent = isCollapsed ? 'Mostrar' : 'Ocultar';
                        toggle.querySelector('.permissions-card-toggle-icon').textContent = isCollapsed ? '▾' : '▴';
                    }
                });
            };

            var updateVistaSelectorVisibility = function () {
                var vistaSelectorFieldset = form.querySelector('fieldset[data-vista-selector-fieldset]');
                if (!vistaSelectorFieldset) {
                    return;
                }

                var ticketsVerInput = form.querySelector('input[name="permissions[tickets][ver]"]');
                var shouldShow = !!(ticketsVerInput && ticketsVerInput.checked);
                vistaSelectorFieldset.classList.toggle('hidden', !shouldShow);
            };

            var syncTicketsViewModeExclusive = function () {
                var ticketsVerInput = form.querySelector('input[name="permissions[tickets][ver]"]');
                var ticketsVerFlujoInput = form.querySelector('input[name="permissions[tickets][ver_flujo]"]');

                if (!ticketsVerInput || !ticketsVerFlujoInput) {
                    return;
                }

                [ticketsVerInput, ticketsVerFlujoInput].forEach(function (input) {
                    if (input.dataset.exclusiveApplied === 'true') {
                        input.disabled = input.dataset.exclusiveBaseDisabled === 'true';
                        input.dataset.exclusiveApplied = 'false';
                    }

                    input.dataset.exclusiveBaseDisabled = input.disabled ? 'true' : 'false';
                });

                if (ticketsVerInput.checked) {
                    ticketsVerFlujoInput.checked = false;
                    ticketsVerFlujoInput.disabled = true;
                    ticketsVerFlujoInput.dataset.exclusiveApplied = 'true';
                    return;
                }

                if (ticketsVerFlujoInput.checked) {
                    ticketsVerInput.checked = false;
                    ticketsVerInput.disabled = true;
                    ticketsVerInput.dataset.exclusiveApplied = 'true';
                }

                updateVistaSelectorVisibility();
            };

            var form = document.getElementById('main-crud-form');
            if (form) {
                var initializePermissionTabs = function () {
                    document.querySelectorAll('fieldset[data-permissions-fieldset]').forEach(function (fieldset) {
                        var tabs = Array.from(fieldset.querySelectorAll('[data-permission-tab]'));
                        var panels = Array.from(fieldset.querySelectorAll('[data-permission-panel]'));

                        if (tabs.length === 0 || panels.length === 0) {
                            return;
                        }

                        var activateTab = function (moduleKey) {
                            tabs.forEach(function (tab) {
                                var isActive = tab.dataset.permissionTab === moduleKey;
                                tab.classList.toggle('is-active', isActive);
                                tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
                            });

                            panels.forEach(function (panel) {
                                panel.classList.toggle('is-active', panel.dataset.permissionPanel === moduleKey);
                            });
                        };

                        tabs.forEach(function (tab) {
                            tab.addEventListener('click', function () {
                                activateTab(tab.dataset.permissionTab);
                            });
                        });

                        var initiallyActive = tabs.find(function (tab) {
                            return tab.classList.contains('is-active');
                        }) || tabs[0];

                        if (initiallyActive) {
                            activateTab(initiallyActive.dataset.permissionTab);
                        }
                    });
                };

                var isReadOnlyView = document.getElementById('btnEditar') !== null;
                var updateCredentialRows = function () {
                    var permissionFieldsets = form.querySelectorAll('fieldset[data-permissions-fieldset]');
                    var isReadOnlyLocked = isReadOnlyView && window.crudFormEditUnlocked !== true;

                    permissionFieldsets.forEach(function (fieldset) {
                        var lockedByRole = fieldset.dataset.lockedByRole === 'true';
                        var clienteVer = fieldset.querySelector('input[name="permissions[clientes.cliente][ver]"]');
                        var clienteCrear = fieldset.querySelector('input[name="permissions[clientes.cliente][crear]"]');
                        var clienteEditar = fieldset.querySelector('input[name="permissions[clientes.cliente][editar]"]');
                        var vehiculoVer = fieldset.querySelector('input[name="permissions[vehiculos][ver]"]');
                        var vehiculoCrear = fieldset.querySelector('input[name="permissions[vehiculos][crear]"]');
                        var vehiculoEditar = fieldset.querySelector('input[name="permissions[vehiculos][editar]"]');
                        var conditionalRows = fieldset.querySelectorAll('tr.credential-permission-row, tr.dependent-permission-row');

                        conditionalRows.forEach(function (row) {
                            var submodule = row.dataset.submodule;
                            var canUse = false;

                            var hasClienteVer = clienteVer && clienteVer.checked;
                            var hasClienteCreateOrEdit = (clienteCrear && clienteCrear.checked) || (clienteEditar && clienteEditar.checked);
                            var hasVehiculoVer = vehiculoVer && vehiculoVer.checked;
                            var hasVehiculoCreateOrEdit = (vehiculoCrear && vehiculoCrear.checked) || (vehiculoEditar && vehiculoEditar.checked);
                            var lineasDetalleVer = fieldset.querySelector('input[name="permissions[lineas_chips.detallesimcard][ver]"]');
                            var hasLineasDetalleVer = lineasDetalleVer && lineasDetalleVer.checked;

                            if (submodule === 'clientes.credenciales') {
                                canUse = hasClienteVer && hasClienteCreateOrEdit;
                            } else if (submodule === 'dispositivo_cliente') {
                                canUse = true;
                            } else if (submodule === 'lineas_chips.cargar_numeros' || submodule === 'lineas_chips.bajar_numeros') {
                                canUse = hasLineasDetalleVer;
                            }

                            row.querySelectorAll('input.permission-switch-input').forEach(function (input) {
                                if (input.dataset.forceEnabled === 'true') {
                                    return;
                                }

                                input.disabled = isReadOnlyLocked || lockedByRole || !canUse;
                                if (!isReadOnlyLocked && !lockedByRole && !canUse) {
                                    input.checked = false;
                                }
                            });

                            row.classList.toggle('credential-row-disabled', isReadOnlyLocked || lockedByRole || !canUse);
                        });
                    });
                };

                var initializeRolePermissionSync = function () {
                    var roleCheckboxGroup = form.querySelector('fieldset[data-checkbox-group="role_ids"][data-single-selection="true"]');
                    var permissionFieldset = form.querySelector('fieldset[data-permissions-fieldset][data-role-aware="true"]');

                    if (!roleCheckboxGroup || !permissionFieldset) {
                        return;
                    }

                    var roleInputs = Array.from(roleCheckboxGroup.querySelectorAll('input[name="role_ids[]"]'));
                    if (roleInputs.length === 0) {
                        return;
                    }

                    var permissionInputs = Array.from(permissionFieldset.querySelectorAll('input.permission-switch-input'));
                    var moduleToggles = Array.from(permissionFieldset.querySelectorAll('.module-select-all'));
                    var requiredWhenUnlocked = permissionFieldset.dataset.requiredWhenUnlocked === 'true' ? 'true' : 'false';
                    var isRoleLocked = permissionFieldset.dataset.lockedByRole === 'true';
                    var manualDraftMatrix = {};

                    var cloneMatrix = function (matrix) {
                        try {
                            return JSON.parse(JSON.stringify(matrix || {}));
                        } catch (error) {
                            return {};
                        }
                    };

                    var matrixFromInputs = function () {
                        var matrix = {};
                        permissionInputs.forEach(function (input) {
                            var match = String(input.name || '').match(/^permissions\[(.+?)\]\[(.+?)\]$/);
                            if (!match) {
                                return;
                            }

                            var moduleKey = match[1];
                            var actionKey = match[2];
                            if (!matrix[moduleKey]) {
                                matrix[moduleKey] = {};
                            }
                            matrix[moduleKey][actionKey] = input.checked === true;
                        });

                        return matrix;
                    };

                    var matrixFromRoleInput = function (roleInput) {
                        if (!roleInput) {
                            return {};
                        }

                        try {
                            return JSON.parse(roleInput.dataset.rolePermissionsMatrix || '{}');
                        } catch (error) {
                            return {};
                        }
                    };

                    var applyMatrix = function (matrix) {
                        permissionInputs.forEach(function (input) {
                            var match = String(input.name || '').match(/^permissions\[(.+?)\]\[(.+?)\]$/);
                            if (!match) {
                                return;
                            }

                            var moduleKey = match[1];
                            var actionKey = match[2];
                            var moduleMatrix = matrix && matrix[moduleKey] ? matrix[moduleKey] : {};
                            input.checked = moduleMatrix[actionKey] === true;
                        });
                    };

                    var setRoleLockState = function (locked) {
                        isRoleLocked = locked;
                        permissionFieldset.dataset.lockedByRole = locked ? 'true' : 'false';
                        permissionFieldset.dataset.required = locked ? 'false' : requiredWhenUnlocked;

                        if (locked) {
                            permissionFieldset.classList.remove('permissions-field-error');
                            var errorMessage = permissionFieldset.querySelector('.permissions-field-error-message');
                            if (errorMessage) {
                                errorMessage.textContent = '';
                                errorMessage.classList.add('hidden');
                            }
                        }
                    };

                    var enforceSingleSelection = function (activeInput) {
                        if (!activeInput || !activeInput.checked) {
                            return;
                        }

                        roleInputs.forEach(function (input) {
                            if (input === activeInput) {
                                return;
                            }
                            input.checked = false;
                        });
                    };

                    var unlockRoleForManualEdit = function () {
                        if (!isRoleLocked) {
                            return;
                        }

                        var selectedRoleInput = roleInputs.find(function (input) {
                            return input.checked;
                        });

                        if (selectedRoleInput) {
                            selectedRoleInput.checked = false;
                        }

                        setRoleLockState(false);
                        manualDraftMatrix = cloneMatrix(matrixFromInputs());
                        updatePermissionCardExpansion();
                        syncModuleToggleStates();
                        updateConfiguracionGroupExpansion();
                        syncTicketsViewModeExclusive();
                        updateVistaSelectorVisibility();
                        if (typeof window.updateCredentialRows === 'function') {
                            window.updateCredentialRows();
                        }
                    };

                    var syncWithRoleSelection = function () {
                        var selectedRoleInput = roleInputs.find(function (input) {
                            return input.checked;
                        });

                        if (selectedRoleInput) {
                            var roleMatrix = matrixFromRoleInput(selectedRoleInput);
                            if (!isRoleLocked) {
                                manualDraftMatrix = cloneMatrix(roleMatrix);
                            }

                            applyMatrix(roleMatrix);
                            setRoleLockState(true);
                            updatePermissionCardExpansion();
                            syncModuleToggleStates();
                            updateConfiguracionGroupExpansion();
                            syncTicketsViewModeExclusive();
                            updateVistaSelectorVisibility();
                            if (typeof window.updateCredentialRows === 'function') {
                                window.updateCredentialRows();
                            }
                            return;
                        }

                        setRoleLockState(false);
                        applyMatrix(manualDraftMatrix);
                        updatePermissionCardExpansion();
                        syncModuleToggleStates();
                        updateConfiguracionGroupExpansion();
                        syncTicketsViewModeExclusive();
                        updateVistaSelectorVisibility();
                        if (typeof window.updateCredentialRows === 'function') {
                            window.updateCredentialRows();
                        }
                    };

                    try {
                        manualDraftMatrix = JSON.parse(permissionFieldset.dataset.manualFallbackMatrix || '{}');
                    } catch (error) {
                        manualDraftMatrix = {};
                    }

                    if (!manualDraftMatrix || typeof manualDraftMatrix !== 'object') {
                        manualDraftMatrix = {};
                    }

                    if (Object.keys(manualDraftMatrix).length === 0) {
                        manualDraftMatrix = cloneMatrix(matrixFromInputs());
                    }

                    roleInputs.forEach(function (input) {
                        input.addEventListener('change', function () {
                            enforceSingleSelection(input);
                            syncWithRoleSelection();
                        });
                    });

                    permissionInputs.forEach(function (input) {
                        input.addEventListener('change', function () {
                            if (isRoleLocked) {
                                unlockRoleForManualEdit();
                            }

                            manualDraftMatrix = cloneMatrix(matrixFromInputs());
                            updatePermissionCardExpansion();
                            syncTicketsViewModeExclusive();
                            if (String(this.name || '') === 'permissions[tickets][ver]') {
                                updateVistaSelectorVisibility();
                            }
                            if (typeof window.updateCredentialRows === 'function') {
                                window.updateCredentialRows();
                            }
                        });
                    });

                    window.unlockRoleForManualEdit = unlockRoleForManualEdit;

                    window.syncRolePermissionsLock = syncWithRoleSelection;
                    syncWithRoleSelection();
                };

                window.updateCredentialRows = updateCredentialRows;
                window.syncTicketsViewModeExclusive = syncTicketsViewModeExclusive;

                document.querySelectorAll(
                    'input[name="permissions[clientes.cliente][ver]"], input[name="permissions[clientes.cliente][crear]"], input[name="permissions[clientes.cliente][editar]"],' +
                    'input[name="permissions[vehiculos][ver]"], input[name="permissions[vehiculos][crear]"], input[name="permissions[vehiculos][editar]"],' +
                    'input[name="permissions[lineas_chips.detallesimcard][ver]"]'
                ).forEach(function (input) {
                    input.addEventListener('change', updateCredentialRows);
                });

                var ticketsVerInput = form.querySelector('input[name="permissions[tickets][ver]"]');
                if (ticketsVerInput) {
                    ticketsVerInput.addEventListener('change', updateVistaSelectorVisibility);
                    ticketsVerInput.addEventListener('change', syncTicketsViewModeExclusive);
                }

                var ticketsVerFlujoInput = form.querySelector('input[name="permissions[tickets][ver_flujo]"]');
                if (ticketsVerFlujoInput) {
                    ticketsVerFlujoInput.addEventListener('change', syncTicketsViewModeExclusive);
                }

                if (!isReadOnlyView) {
                    updateCredentialRows();
                }

                initializePermissionAccordions();
                initializeConfiguracionGroupAccordions();
                initializePermissionTabs();
                initializeRolePermissionSync();
                syncModuleToggleStates();
                updatePermissionCardExpansion();
                updateConfiguracionGroupExpansion();
                updateVistaSelectorVisibility();
                syncTicketsViewModeExclusive();

                form.addEventListener('submit', function (event) {
                    var permissionFieldsets = form.querySelectorAll('fieldset[data-permissions-fieldset]');
                    var invalidFound = false;

                    permissionFieldsets.forEach(function (fieldset) {
                        if (fieldset.dataset.required !== 'true') {
                            return;
                        }

                        var inputs = fieldset.querySelectorAll('input.permission-switch-input');
                        var anyChecked = Array.from(inputs).some(function (input) {
                            return input.checked;
                        });
                        var errorMessage = fieldset.querySelector('.permissions-field-error-message');

                        fieldset.classList.toggle('permissions-field-error', !anyChecked);

                        if (!anyChecked) {
                            invalidFound = true;
                            if (errorMessage) {
                                errorMessage.textContent = 'Debes seleccionar al menos un permiso.';
                                errorMessage.classList.remove('hidden');
                            }
                        } else if (errorMessage) {
                            errorMessage.textContent = '';
                            errorMessage.classList.add('hidden');
                        }
                    });

                    if (invalidFound) {
                        event.preventDefault();
                        var firstInvalid = form.querySelector('fieldset.permissions-field-error');
                        if (firstInvalid) {
                            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }
                });

                if (form) {
                    var validatedInputs = form.querySelectorAll('input[pattern], input[type="email"], input[data-validation-message]');
                    validatedInputs.forEach(function (input) {
                        input.addEventListener('input', function () {
                            this.setCustomValidity('');
                        });
                        input.addEventListener('invalid', function () {
                            if (!this.validity.valid) {
                                var customMessage = this.dataset.validationMessage || this.validationMessage;
                                this.setCustomValidity(customMessage);
                            }
                        });
                    });
                }
            }
        });
    </script>
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
                        @if($mode === 'edit')
                            @method('PUT')
                        @endif

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
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            @foreach($fields as $field)
                                @php
                                    $fieldValue = old($field['name'], $field['value'] ?? data_get($record, $field['name']));
                                    $hasError = $errors->has($field['name']);
                                    $errorMessage = $errors->first($field['name']);
                                    $colSpan = $field['colSpan'] ?? 1;
                                    $colClass = $colSpan === 2 ? 'md:col-span-2' : ($colSpan === 1 ? '' : 'md:col-span-' . $colSpan);
                                @endphp

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
                                                        $fieldHelpText = 'Correo válido. Debe incluir @ y terminar en .com.';
                                                    }
                                                }
                                                $validationMessage = $field['validationMessage'] ?? $fieldHelpText ?? '';
                                            @endphp
                                            <div class="mb-2 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                                <label class="block text-sm font-medium text-slate-700">
                                                    <span class="flex items-center gap-2">
                                                        <span>
                                                            {{ $field['label'] }}
                                                            @if(($field['required'] ?? false))
                                                                <span class="text-red-500">*</span>
                                                            @endif
                                                        </span>
                                                        @if(($field['quickCreateDetalleListaPrecio'] ?? false) === true)
                                                            <button
                                                                type="button"
                                                                id="quick-detalle-lista-precio-button"
                                                                data-quick-create-button="true"
                                                                data-quick-detalle-payload-input="{{ $field['quickCreateDetalleListaPrecioPayloadInput'] ?? 'detalle_lista_precio_payload' }}"
                                                                data-quick-detalle-list-options='@json($field['quickCreateDetalleListaPrecioOptions'] ?? [])'
                                                                data-quick-detalle-existing='@json($field['quickCreateDetalleListaPrecioExisting'] ?? [])'
                                                                data-quick-detalle-almacen-id="{{ $field['quickCreateDetalleListaPrecioAlmacenId'] ?? '' }}"
                                                                data-quick-detalle-almacen-label="{{ $field['quickCreateDetalleListaPrecioAlmacenLabel'] ?? '' }}"
                                                                style="background-color: #dc2626 !important; color: #fff !important;"
                                                                class="ml-1 inline-flex items-center gap-1 rounded border border-red-600 px-2 py-1 text-xs transition hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-600 focus:ring-opacity-50 disabled:cursor-not-allowed disabled:opacity-70"
                                                                {{ ($readOnly ?? false) ? 'disabled' : '' }}
                                                            >
                                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                                                                Crear rápido
                                                            </button>
                                                        @endif
                                                    </span>
                                                </label>
                                                @if($fieldHelpText)
                                                    <p id="{{ $field['name'] }}-help" class="text-xs text-slate-500 sm:text-right">{{ $fieldHelpText }}</p>
                                                @endif
                                            </div>
                                            <div class="relative">
                                                <input 
                                                    type="{{ $field['type'] === 'date' ? 'text' : $field['type'] }}" 
                                                    name="{{ $field['name'] }}" 
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
                                            <div class="mb-2 flex items-center justify-between">
                                                <label class="block text-sm font-medium text-slate-700">
                                                    {{ $field['label'] }}
                                                    @if(($field['required'] ?? false))
                                                        <span class="text-red-500">*</span>
                                                    @endif
                                                </label>
                                                @if(($field['quickCreate'] ?? false) === true)
                                                    <button
                                                        type="button"
                                                        data-quick-create-button
                                                        data-quick-direccion-button
                                                        data-quick-target="select-{{ $field['name'] }}"
                                                        data-quick-list-url="{{ $field['quickCreateListUrl'] ?? '' }}"
                                                        data-quick-store-url="{{ $field['quickCreateStoreUrl'] ?? '' }}"
                                                        data-quick-update-url-template="{{ $field['quickCreateUpdateUrlTemplate'] ?? '' }}"
                                                        data-quick-delete-url-template="{{ $field['quickCreateDeleteUrlTemplate'] ?? '' }}"
                                                        data-quick-export-pdf-url="{{ $field['quickCreateExportPdfUrl'] ?? '' }}"
                                                        data-quick-export-xlsx-url="{{ $field['quickCreateExportXlsxUrl'] ?? '' }}"
                                                        data-quick-payload-input="{{ $field['quickCreatePayloadInput'] ?? 'direcciones_payload' }}"
                                                        data-quick-ubigeos='@json($field['quickCreateUbigeos'] ?? [])'
                                                        style="background-color: #dc2626 !important; color: #fff !important;" class="ml-2 px-2 py-1 rounded border border-red-600 text-xs hover:bg-red-700 transition flex items-center gap-1 focus:outline-none focus:ring-2 focus:ring-red-600 focus:ring-opacity-50 disabled:opacity-70 disabled:cursor-not-allowed"
                                                        {{ ($readOnly ?? false) ? 'disabled' : '' }}
                                                    >
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                                                        Crear rápido
                                                    </button>
                                                @elseif(($field['quickCreateContact'] ?? false) === true)
                                                    <button
                                                        type="button"
                                                        data-quick-create-button
                                                        data-quick-contact-button
                                                        data-quick-contact-target="select-{{ $field['name'] }}"
                                                        data-quick-contact-mode="{{ $field['quickContactMode'] ?? 'create' }}"
                                                        data-quick-contact-list-url="{{ $field['quickContactListUrl'] ?? '' }}"
                                                        data-quick-contact-store-url="{{ $field['quickContactStoreUrl'] ?? '' }}"
                                                        data-quick-contact-update-url-template="{{ $field['quickContactUpdateUrlTemplate'] ?? '' }}"
                                                        data-quick-contact-delete-url-template="{{ $field['quickContactDeleteUrlTemplate'] ?? '' }}"
                                                        data-quick-export-pdf-url="{{ $field['quickContactExportPdfUrl'] ?? '' }}"
                                                        data-quick-export-xlsx-url="{{ $field['quickContactExportXlsxUrl'] ?? '' }}"
                                                        data-quick-contact-tipos='@json($field['quickContactTipos'] ?? [])'
                                                        data-quick-contact-payload-input="{{ $field['quickContactPayloadInput'] ?? 'contactos_payload' }}"
                                                        style="background-color: #dc2626 !important; color: #fff !important;" class="ml-2 px-2 py-1 rounded border border-red-600 text-xs hover:bg-red-700 transition flex items-center gap-1 focus:outline-none focus:ring-2 focus:ring-red-600 focus:ring-opacity-50 disabled:opacity-70 disabled:cursor-not-allowed"
                                                        {{ ($readOnly ?? false) ? 'disabled' : '' }}
                                                    >
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                                                        Crear rápido
                                                    </button>
                                                @elseif(($field['quickCreateCredential'] ?? false) === true && ($canQuickCredential ?? false))
                                                    <button
                                                        type="button"
                                                        data-quick-create-button
                                                        data-quick-credential-button
                                                        data-quick-credential-target="select-{{ $field['name'] }}"
                                                        data-quick-credential-mode="{{ $field['quickCredentialMode'] ?? 'create' }}"
                                                        data-quick-credential-list-url="{{ $field['quickCredentialListUrl'] ?? '' }}"
                                                        data-quick-credential-store-url="{{ $field['quickCredentialStoreUrl'] ?? '' }}"
                                                        data-quick-credential-update-url-template="{{ $field['quickCredentialUpdateUrlTemplate'] ?? '' }}"
                                                        data-quick-credential-delete-url-template="{{ $field['quickCredentialDeleteUrlTemplate'] ?? '' }}"
                                                        data-quick-export-pdf-url="{{ $field['quickCredentialExportPdfUrl'] ?? '' }}"
                                                        data-quick-export-xlsx-url="{{ $field['quickCredentialExportXlsxUrl'] ?? '' }}"
                                                        data-quick-credential-payload-input="{{ $field['quickCredentialPayloadInput'] ?? 'credenciales_payload' }}"
                                                        data-quick-credential-can-edit="{{ $canQuickCredentialEdit ? 'true' : 'false' }}"
                                                        data-quick-credential-can-delete="{{ $canQuickCredentialDelete ? 'true' : 'false' }}"
                                                        style="background-color: #dc2626 !important; color: #fff !important;" class="ml-2 px-2 py-1 rounded border border-red-600 text-xs hover:bg-red-700 transition flex items-center gap-1 focus:outline-none focus:ring-2 focus:ring-red-600 focus:ring-opacity-50 disabled:opacity-70 disabled:cursor-not-allowed"
                                                        {{ ($readOnly ?? false) ? 'disabled' : '' }}
                                                    >
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                                                        Crear rápido
                                                    </button>
                                                @elseif(($field['quickCreateEstado'] ?? false) === true)
                                                    <button
                                                        type="button"
                                                        data-quick-create-button
                                                        data-quick-estado-button
                                                        data-quick-estado-target="select-{{ $field['name'] }}"
                                                        data-quick-estado-list-url="{{ $field['quickEstadoListUrl'] ?? '' }}"
                                                        data-quick-estado-store-url="{{ $field['quickEstadoStoreUrl'] ?? '' }}"
                                                        data-quick-estado-update-url-template="{{ $field['quickEstadoUpdateUrlTemplate'] ?? '' }}"
                                                        data-quick-estado-delete-url-template="{{ $field['quickEstadoDeleteUrlTemplate'] ?? '' }}"
                                                        style="background-color: #dc2626 !important; color: #fff !important;" class="ml-2 px-2 py-1 rounded border border-red-600 text-xs hover:bg-red-700 transition flex items-center gap-1 focus:outline-none focus:ring-2 focus:ring-red-600 focus:ring-opacity-50 disabled:opacity-70 disabled:cursor-not-allowed"
                                                        {{ ($readOnly ?? false) ? 'disabled' : '' }}
                                                    >
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                                                        Crear rápido
                                                    </button>
                                                @elseif(($field['quickCreateCargo'] ?? false) === true)
                                                    <button
                                                        type="button"
                                                        data-quick-create-button
                                                        data-quick-cargo-button
                                                        data-quick-cargo-target="select-{{ $field['name'] }}"
                                                        data-quick-cargo-list-url="{{ $field['quickCargoListUrl'] ?? '' }}"
                                                        data-quick-cargo-store-url="{{ $field['quickCargoStoreUrl'] ?? '' }}"
                                                        data-quick-cargo-update-url-template="{{ $field['quickCargoUpdateUrlTemplate'] ?? '' }}"
                                                        data-quick-cargo-delete-url-template="{{ $field['quickCargoDeleteUrlTemplate'] ?? '' }}"
                                                        style="background-color: #dc2626 !important; color: #fff !important;" class="ml-2 px-2 py-1 rounded border border-red-600 text-xs hover:bg-red-700 transition flex items-center gap-1 focus:outline-none focus:ring-2 focus:ring-red-600 focus:ring-opacity-50 disabled:opacity-70 disabled:cursor-not-allowed"
                                                        {{ ($readOnly ?? false) ? 'disabled' : '' }}
                                                    >
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                                                        Crear rápido
                                                    </button>
                                                @elseif(($field['quickCreateDispositivo'] ?? false) === true && ($canQuickDispositivo ?? false))
                                                    <button
                                                        type="button"
                                                        data-quick-create-button
                                                        data-quick-dispositivo-button
                                                        data-quick-dispositivo-target="select-{{ $field['name'] }}"
                                                        data-quick-dispositivo-mode="{{ $field['quickDispositivoMode'] ?? 'create' }}"
                                                        data-quick-dispositivo-list-url="{{ $field['quickDispositivoListUrl'] ?? '' }}"
                                                        data-quick-dispositivo-store-url="{{ $field['quickDispositivoStoreUrl'] ?? '' }}"
                                                        data-quick-dispositivo-update-url-template="{{ $field['quickDispositivoUpdateUrlTemplate'] ?? '' }}"
                                                        data-quick-dispositivo-delete-url-template="{{ $field['quickDispositivoDeleteUrlTemplate'] ?? '' }}"
                                                        data-quick-dispositivo-export-pdf-url="{{ $field['quickDispositivoExportPdfUrl'] ?? '' }}"
                                                        data-quick-dispositivo-export-xlsx-url="{{ $field['quickDispositivoExportXlsxUrl'] ?? '' }}"
                                                        data-quick-dispositivo-payload-input="{{ $field['quickDispositivoPayloadInput'] ?? 'dispositivos_payload' }}"
                                                        data-quick-dispositivo-can-edit="{{ $canQuickDispositivoEdit ? 'true' : 'false' }}"
                                                        data-quick-dispositivo-can-delete="{{ $canQuickDispositivoDelete ? 'true' : 'false' }}"
                                                        style="background-color: #dc2626 !important; color: #fff !important;" class="ml-2 px-2 py-1 rounded border border-red-600 text-xs hover:bg-red-700 transition flex items-center gap-1 focus:outline-none focus:ring-2 focus:ring-red-600 focus:ring-opacity-50 disabled:opacity-70 disabled:cursor-not-allowed"
                                                        {{ ($readOnly ?? false) ? 'disabled' : '' }}
                                                    >
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                                                        Crear rápido
                                                    </button>
                                                @endif
                                            </div>
                                            <select 
                                                id="select-{{ $field['name'] }}"
                                                name="{{ $field['name'] }}" 
                                                @if(!empty($field['tomSelect'])) data-placeholder="{{ $field['placeholder'] ?? 'Selecciona una opción' }}" @endif
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

                                        @case('vista-permissions')
                                            @php
                                                $vistaOptions = $field['optionsData'] ?? [];
                                                $selectedVistaIds = is_array($fieldValue) ? array_map('strval', $fieldValue) : [];
                                                $selectedVistas = collect($vistaOptions)
                                                    ->filter(fn ($vista) => in_array((string) data_get($vista, $field['optionKey'] ?? 'idvista'), $selectedVistaIds, true))
                                                    ->values();
                                            @endphp
                                            <fieldset class="md:col-span-2 rounded-3xl border border-slate-200 p-4 hidden {{ $hasError ? 'permissions-field-error' : '' }}" data-vista-selector-fieldset>
                                                <legend class="mb-2 block text-sm font-medium text-slate-700">
                                                    {{ $field['label'] }}
                                                    @if(($field['required'] ?? false))
                                                        <span class="text-red-500">*</span>
                                                    @endif
                                                </legend>
                                                <div class="vista-selector-shell">
                                                    <div class="vista-selector-panel">
                                                        <div class="vista-selector-title">
                                                            <span>Buscar y seleccionar accion</span>
                                                           <label>
                                                            <span class="text-sm font-semibold ext-slate-700">Todo</span>
                                                            <span class="permission-action-checkbox-wrapper">
                                                                <input
                                                                    type="checkbox"
                                                                    class="permission-action-checkbox"
                                                                    data-vista-select-all
                                                                    {{ ($readOnly ?? false) ? 'disabled' : '' }}
                                                                >
                                                                <span class="permission-action-checkbox-box" aria-hidden="true"></span>
                                                            </span>
                                                           </label>
                                                        </div>
                                                        <input
                                                            type="search"
                                                            class="vista-selector-search"
                                                            placeholder="Buscar vista..."
                                                            data-vista-search
                                                            {{ ($readOnly ?? false) ? 'disabled' : '' }}
                                                        >
                                                        <div class="vista-selector-list" data-vista-selector-list>
                                                            @forelse($vistaOptions as $vista)
                                                                @php
                                                                    $vistaId = data_get($vista, $field['optionKey'] ?? 'idvista');
                                                                    $vistaName = data_get($vista, $field['optionLabel'] ?? 'nombre');
                                                                    $vistaDetail = data_get($vista, 'detalle', '');
                                                                    $vistaState = data_get($vista, 'estado', null);
                                                                    $vistaStateLabel = trim((string) $vistaState);
                                                                    if ($vistaStateLabel !== '' && !in_array(mb_strtolower($vistaStateLabel), ['activo', 'inactivo'], true)) {
                                                                        $vistaStateLabel = $vistaStateLabel === '1' ? 'Activo' : ($vistaStateLabel === '0' ? 'Inactivo' : ucfirst(mb_strtolower($vistaStateLabel)));
                                                                    }
                                                                    $vistaText = mb_strtolower(trim($vistaName . ' ' . $vistaDetail . ' ' . $vistaStateLabel));
                                                                    $isSelected = in_array((string) $vistaId, $selectedVistaIds, true);
                                                                @endphp
                                                                <label class="vista-selector-option {{ $isSelected ? 'is-selected' : '' }}" data-vista-option data-vista-text="{{ $vistaText }}" data-vista-name="{{ e($vistaName) }}" data-vista-detail="{{ e($vistaDetail) }}" data-vista-state="{{ e($vistaStateLabel) }}">
                                                                    <span class="vista-selector-option-input-wrap" aria-hidden="true">
                                                                        <input
                                                                            type="checkbox"
                                                                            name="vista_permissions[]"
                                                                            value="{{ $vistaId }}"
                                                                            @if($isSelected) checked @endif
                                                                            {{ ($readOnly ?? false) ? 'disabled' : '' }}
                                                                            data-vista-checkbox
                                                                        >
                                                                        <span class="vista-selector-option-input-box"></span>
                                                                    </span>
                                                                    <div class="vista-selector-option-main">
                                                                        <span class="vista-selector-option-title">{{ $vistaName }}</span>
                                                                        @if($vistaDetail)
                                                                            <span class="vista-selector-option-detail">{{ $vistaDetail }}</span>
                                                                        @endif
                                                                        @if($vistaStateLabel !== '')
                                                                            <span class="vista-selector-option-meta">Estado {{ $vistaStateLabel }}</span>
                                                                        @endif
                                                                    </div>
                                                                </label>
                                                            @empty
                                                                <div class="vista-selected-empty">
                                                                    No hay vistas registradas.
                                                                </div>
                                                            @endforelse
                                                        </div>
                                                    </div>
                                                    <div class="vista-selected-panel">
                                                        <div class="vista-selected-title">
                                                            <span>Seleccionadas</span>
                                                            <span class="vista-selector-help">Se guardarán solo estas vistas</span>
                                                        </div>
                                                        <div class="vista-selected-list" data-vista-selected-list>
                                                            <div class="vista-selected-head">
                                                                <span>Vista</span>
                                                                <span>Detalle</span>
                                                                <span>Estado</span>
                                                            </div>
                                                            @forelse($selectedVistas as $vista)
                                                                @php
                                                                    $vistaId = data_get($vista, $field['optionKey'] ?? 'idvista');
                                                                    $vistaName = data_get($vista, $field['optionLabel'] ?? 'nombre');
                                                                    $vistaDetail = data_get($vista, 'detalle', '');
                                                                    $vistaState = data_get($vista, 'estado', null);
                                                                    $vistaStateLabel = trim((string) $vistaState);
                                                                    if ($vistaStateLabel !== '' && !in_array(mb_strtolower($vistaStateLabel), ['activo', 'inactivo'], true)) {
                                                                        $vistaStateLabel = $vistaStateLabel === '1' ? 'Activo' : ($vistaStateLabel === '0' ? 'Inactivo' : ucfirst(mb_strtolower($vistaStateLabel)));
                                                                    }
                                                                    $vistaText = mb_strtolower(trim($vistaName . ' ' . $vistaDetail . ' ' . $vistaStateLabel));
                                                                @endphp
                                                                <div class="vista-selected-row" data-vista-item="{{ $vistaId }}" data-vista-text="{{ $vistaText }}">
                                                                    <div class="vista-selected-row-name">{{ $vistaName }}</div>
                                                                    <div class="vista-selected-row-detail">{{ $vistaDetail ?: '-' }}</div>
                                                                    <div class="vista-selected-row-state">{{ $vistaStateLabel ?: '-' }}</div>
                                                                </div>
                                                            @empty
                                                                <div class="vista-selected-empty" data-vista-empty>
                                                                    Todavía no has seleccionado ninguna vista.
                                                                </div>
                                                            @endforelse
                                                        </div>
                                                    </div>
                                                </div>
                                                @if($hasError)
                                                    <p class="mt-1 text-sm text-red-500">{{ $errorMessage }}</p>
                                                @endif
                                            </fieldset>
                                        @break

                                        @case('permissions-matrix')
                                            @php
                                                $permissionModules = $field['modules'] ?? [];
                                                $permissionActions = $field['actions'] ?? [];
                                                $permissionValue = is_array($fieldValue) ? $fieldValue : [];
                                                $lockedByRole = ($field['lockedByRole'] ?? false) === true;
                                                $configuracionGroupDefinitions = [
                                                    'Cliente' => [
                                                        'configuracion.ubigeo',
                                                        'configuracion.estado',
                                                        'configuracion.tipo_contacto',
                                                    ],
                                                    'Finanzas' => [
                                                        'configuracion.proveedor',
                                                        'configuracion.tipo_cobro',
                                                        'configuracion.entidad_bancaria',
                                                        'configuracion.tipo_gasto',
                                                    ],
                                                    'Facturacion' => [
                                                        'configuracion.vigencia_oferta',
                                                        'configuracion.moneda',
                                                        'configuracion.forma_pago',
                                                        'configuracion.certificadosunat',
                                                        'configuracion.tributo',
                                                        'configuracion.tipo_documento',
                                                    ],
                                                    'Personal' => [
                                                        'configuracion.cargo',
                                                    ],
                                                    'Plataforma' => [
                                                        'configuracion.tipo_plataforma',
                                                        'configuracion.plataforma',
                                                    ],
                                                    'Almacén' => [
                                                        'configuracion.detalle_lista_precio',
                                                        'configuracion.empresapropietaria',
                                                        'configuracion.elemento_almacen',
                                                        'configuracion.lista_precio',
                                                        'configuracion.marca',
                                                        'configuracion.modelo',
                                                        'configuracion.tecnologia',
                                                        'configuracion.tipo_elemento',
                                                        'configuracion.tipo_pedido',
                                                        'configuracion.unidad_medida',
                                                    ],
                                                    'Gestion' => [
                                                        'configuracion.tipo_operacion',
                                                    ],
                                                    'Vehículos' => [
                                                        'configuracion.tipo_vehiculo',
                                                        'configuracion.operador',
                                                    ],
                                                ];
                                            @endphp
                                            <fieldset class="md:col-span-2 mt-2 rounded-3xl border border-slate-200 p-4 {{ $hasError ? 'permissions-field-error' : '' }}" data-permissions-fieldset data-required="{{ ($field['required'] ?? false) ? 'true' : 'false' }}" data-required-when-unlocked="{{ ($field['required'] ?? false) ? 'true' : 'false' }}" data-role-aware="{{ ($field['roleAware'] ?? false) ? 'true' : 'false' }}" data-locked-by-role="{{ $lockedByRole ? 'true' : 'false' }}" data-manual-fallback-matrix='@json($field['manualFallbackValue'] ?? [])'>
                                                <legend class="mb-2 block text-sm font-medium text-slate-700">
                                                    {{ $field['label'] }}
                                                    @if(($field['required'] ?? false))
                                                        <span class="text-red-500">*</span>
                                                    @endif
                                                </legend>
                                                <div class="permissions-table-wrapper">
                                                    @php
                                                        $moduleEntries = [];
                                                        foreach ($permissionModules as $moduleKey => $moduleConfig) {
                                                            $moduleLabel = is_array($moduleConfig) ? ($moduleConfig['label'] ?? $moduleKey) : $moduleConfig;
                                                            $submodules = is_array($moduleConfig) ? ($moduleConfig['submodules'] ?? []) : [];
                                                            $moduleEntries[] = [
                                                                'moduleKey' => $moduleKey,
                                                                'moduleLabel' => $moduleLabel,
                                                                'submodules' => $submodules,
                                                                'hasSubmodules' => !empty($submodules),
                                                            ];
                                                        }
                                                    @endphp
                                                    <div class="permissions-module-tabs-shell">
                                                        <div class="permissions-module-tabs" role="tablist" aria-label="Modulos de permisos">
                                                            @foreach ($moduleEntries as $moduleIndex => $moduleEntry)
                                                                <button
                                                                    type="button"
                                                                    class="permissions-module-tab {{ $moduleIndex === 0 ? 'is-active' : '' }}"
                                                                    data-permission-tab="{{ $moduleEntry['moduleKey'] }}"
                                                                    role="tab"
                                                                    aria-selected="{{ $moduleIndex === 0 ? 'true' : 'false' }}"
                                                                >
                                                                    Modulo {{ $moduleEntry['moduleLabel'] }}
                                                                </button>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                    <div class="permissions-module-panels">
                                                        @foreach ($moduleEntries as $moduleIndex => $moduleEntry)
                                                            <div class="permissions-card permissions-module-panel {{ $moduleIndex === 0 ? 'is-active' : '' }}" data-permission-panel="{{ $moduleEntry['moduleKey'] }}">
                                                                <div class="permissions-card-header">
                                                                    <div>
                                                                        <div class="permissions-card-title">Modulo {{ $moduleEntry['moduleLabel'] }}</div>
                                                                    </div>
                                                                    <div class="permissions-card-controls">
                                                                        <label class="module-select-all-label inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                                                                            <span>Todo</span>
                                                                            <span class="module-select-all-wrapper">
                                                                                <input
                                                                                    type="checkbox"
                                                                                    class="module-select-all"
                                                                                    data-scope="module"
                                                                                    data-module="{{ $moduleEntry['moduleKey'] }}"
                                                                                    {{ ($readOnly ?? false || $lockedByRole) ? 'disabled' : '' }}
                                                                                >
                                                                                <span class="module-select-all-box" aria-hidden="true"></span>
                                                                            </span>
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                                <div class="permissions-card-table-wrapper">
                                                                    @if($moduleEntry['moduleKey'] === 'configuracion' && $moduleEntry['hasSubmodules'])
                                                                        @php
                                                                            $configuracionSubmodules = collect($moduleEntry['submodules']);
                                                                            $configuracionGroups = [];
                                                                            $groupedKeys = collect($configuracionGroupDefinitions)->flatten()->all();
                                                                            foreach ($configuracionGroupDefinitions as $groupLabel => $groupKeys) {
                                                                                $groupSubmodules = $configuracionSubmodules->only($groupKeys)->all();
                                                                                if (!empty($groupSubmodules)) {
                                                                                    $configuracionGroups[] = [
                                                                                        'groupLabel' => $groupLabel,
                                                                                        'groupKey' => \Illuminate\Support\Str::slug($groupLabel, '-'),
                                                                                        'submodules' => $groupSubmodules,
                                                                                    ];
                                                                                }
                                                                            }
                                                                            $leftoverSubmodules = $configuracionSubmodules->except($groupedKeys)->all();
                                                                            if (!empty($leftoverSubmodules)) {
                                                                                $configuracionGroups[] = [
                                                                                    'groupLabel' => 'Otros',
                                                                                    'groupKey' => 'otros',
                                                                                    'submodules' => $leftoverSubmodules,
                                                                                ];
                                                                            }
                                                                        @endphp
                                                                        <div class="permissions-config-groups">
                                                                            @foreach($configuracionGroups as $configGroup)
                                                                                <div class="permissions-config-group" data-config-group="{{ $configGroup['groupKey'] }}">
                                                                                    <div class="permissions-config-group-header">
                                                                                        <div class="permissions-config-group-title">{{ $configGroup['groupLabel'] }}</div>
                                                                                        <div class="permissions-config-group-controls">
                                                                                            <label class="module-select-all-label inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                                                                                                <span>Todo</span>
                                                                                                <span class="module-select-all-wrapper">
                                                                                                    <input
                                                                                                        type="checkbox"
                                                                                                        class="module-select-all"
                                                                                                        data-scope="group"
                                                                                                        data-module="configuracion"
                                                                                                        data-group="{{ $configGroup['groupKey'] }}"
                                                                                                        {{ ($readOnly ?? false || $lockedByRole) ? 'disabled' : '' }}
                                                                                                    >
                                                                                                    <span class="module-select-all-box" aria-hidden="true"></span>
                                                                                                </span>
                                                                                            </label>
                                                                                            <button type="button" class="permissions-config-group-toggle" data-config-group-toggle aria-expanded="true">
                                                                                                <span class="permissions-config-group-toggle-label">Ocultar</span>
                                                                                                <span class="permissions-config-group-toggle-icon" aria-hidden="true">▴</span>
                                                                                            </button>
                                                                                        </div>
                                                                                    </div>
                                                                                    <div class="permissions-config-group-body">
                                                                                    <table class="permissions-card-table">
                                                                                        @php
                                                                                            $tablePermissionActions = collect($permissionActions)
                                                                                                ->except('ver_flujo')
                                                                                                ->all();
                                                                                        @endphp
                                                                                        <thead>
                                                                                            <tr>
                                                                                                <th>Modulo</th>
                                                                                                @foreach($tablePermissionActions as $actionLabel)
                                                                                                    <th>{{ $actionLabel }}</th>
                                                                                                @endforeach
                                                                                            </tr>
                                                                                        </thead>
                                                                                        <tbody>
                                                                                            @foreach($configGroup['submodules'] as $subKey => $subLabel)
                                                                                                @php
                                                                                                    $isCredentialRow = $subKey === 'clientes.credenciales';
                                                                                                    $isAuditoriaRow = $subKey === 'configuracion.auditoria';
                                                                                                    $clienteActions = $permissionValue['clientes.cliente'] ?? [];
                                                                                                    $clienteVer = !empty($clienteActions['ver']);
                                                                                                    $clienteCrear = !empty($clienteActions['crear']);
                                                                                                    $clienteEditar = !empty($clienteActions['editar']);
                                                                                                    $credentialDisabled = $isCredentialRow && !($clienteVer && ($clienteCrear || $clienteEditar));
                                                                                                    $configDisplayLabel = preg_replace('/^Configuraci[oó]n\//iu', '', (string) $subLabel);
                                                                                                @endphp
                                                                                                <tr data-submodule="{{ $subKey }}" data-parent-module="configuracion" class="{{ $isCredentialRow ? 'credential-permission-row' : '' }} {{ $credentialDisabled ? 'credential-row-disabled' : '' }}">
                                                                                                    <td>{{ $configDisplayLabel }}</td>
                                                                                                    @foreach($tablePermissionActions as $actionKey => $actionLabel)
                                                                                                        @php
                                                                                                            $isChecked = !empty($permissionValue[$subKey][$actionKey]);
                                                                                                            $isHiddenAction = $isAuditoriaRow && $actionKey !== 'ver';
                                                                                                        @endphp
                                                                                                        @if($isHiddenAction)
                                                                                                            <td class="permissions-action-cell"></td>
                                                                                                        @else
                                                                                                        <td class="permissions-action-cell">
                                                                                                            <label class="permission-action-checkbox-wrapper">
                                                                                                                <input
                                                                                                                    type="checkbox"
                                                                                                                    class="permission-action-checkbox permission-switch-input"
                                                                                                                    data-group="{{ $configGroup['groupKey'] }}"
                                                                                                                    name="permissions[{{ $subKey }}][{{ $actionKey }}]"
                                                                                                                    value="1"
                                                                                                                    @if($isChecked) checked @endif
                                                                                                                    {{ ($readOnly ?? false || $credentialDisabled || $lockedByRole) ? 'disabled' : '' }}
                                                                                                                >
                                                                                                                <span class="permission-action-checkbox-box" aria-hidden="true"></span>
                                                                                                            </label>
                                                                                                        </td>
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
                                                                        <table class="permissions-card-table">
                                                                            @php
                                                                                $tablePermissionActions = collect($permissionActions);
                                                                                if ($moduleEntry['moduleKey'] === 'tickets') {
                                                                                    // Para el módulo Gestiones (tickets) no mostramos Editar/Eliminar
                                                                                    $tablePermissionActions = $tablePermissionActions->except(['editar', 'eliminar']);
                                                                                } else {
                                                                                    // Para el resto removemos la columna "Ver flujo" cuando no aplica
                                                                                    $tablePermissionActions = $tablePermissionActions->except('ver_flujo');
                                                                                }
                                                                                $tablePermissionActions = $tablePermissionActions->all();
                                                                            @endphp
                                                                            <thead>
                                                                                <tr>
                                                                                    <th>Modulo</th>
                                                                                    @foreach($tablePermissionActions as $actionLabel)
                                                                                        <th>{{ $actionLabel }}</th>
                                                                                    @endforeach
                                                                                </tr>
                                                                            </thead>
                                                                            <tbody>
                                                                                @if($moduleEntry['hasSubmodules'])
                                                                                    @foreach($moduleEntry['submodules'] as $subKey => $subLabel)
                                                                                        @php
                                                                                            $isHistorialFlujoRow = $moduleEntry['moduleKey'] === 'sistema' && $subKey === 'sistema.historialflujo';
                                                                                            $isCredentialRow = $subKey === 'clientes.credenciales';
                                                                                            $isDeviceRow = $subKey === 'vehiculos.dispositivo_cliente' || $subKey === 'dispositivo_cliente';
                                                                                            $isLineasChildRow = in_array($subKey, ['lineas_chips.cargar_numeros', 'lineas_chips.bajar_numeros'], true);
                                                                                            $isTicketsRow = $subKey === 'tickets';
                                                                                            $clienteActions = $permissionValue['clientes.cliente'] ?? [];
                                                                                            $clienteVer = !empty($clienteActions['ver']);
                                                                                            $clienteCrear = !empty($clienteActions['crear']);
                                                                                            $clienteEditar = !empty($clienteActions['editar']);
                                                                                            $lineasDetalleActions = $permissionValue['lineas_chips.detallesimcard'] ?? [];
                                                                                            $lineasDetalleVer = !empty($lineasDetalleActions['ver']);
                                                                                            $credentialDisabled = $isCredentialRow && !($clienteVer && ($clienteCrear || $clienteEditar));
                                                                                            $deviceDisabled = false;
                                                                                            $lineasChildDisabled = $isLineasChildRow && !$lineasDetalleVer;
                                                                                            $isConditionalRow = $isCredentialRow || $isDeviceRow || $isLineasChildRow;
                                                                                            $rowDisabled = $credentialDisabled || $deviceDisabled || $lineasChildDisabled;
                                                                                        @endphp
                                                                                        <tr data-submodule="{{ $subKey }}" data-parent-module="{{ $moduleEntry['moduleKey'] }}" data-dependency="{{ $isLineasChildRow ? 'lineas_chips.detallesimcard' : '' }}" class="{{ $isConditionalRow ? 'credential-permission-row' : '' }} {{ $isLineasChildRow ? 'dependent-permission-row' : '' }} {{ $rowDisabled ? 'credential-row-disabled' : '' }}">
                                                                                            <td>{{ $subLabel }}</td>
                                                                                            @foreach($tablePermissionActions as $actionKey => $actionLabel)
                                                                                                @php
                                                                                                    $isEditForbidden = in_array($subKey, ['lineas_chips.detallesimcard', 'lineas_chips.numero_dispositivo'], true) && $actionKey === 'editar';
                                                                                                    $isDeleteForbidden = $isTicketsRow && in_array($actionKey, ['editar', 'eliminar'], true);
                                                                                                    $isVerOnlyRow = in_array($subKey, ['lineas_chips.cargar_numeros', 'lineas_chips.bajar_numeros'], true);
                                                                                                    $isHistorialFlujoHidden = $isHistorialFlujoRow && $actionKey !== 'ver';
                                                                                                    $isActionHidden = $isVerOnlyRow && $actionKey !== 'ver';
                                                                                                    $isChecked = !empty($permissionValue[$subKey][$actionKey]);
                                                                                                @endphp
                                                                                                @if($isEditForbidden || $isDeleteForbidden || $isActionHidden || $isHistorialFlujoHidden)
                                                                                                    <td class="permissions-action-cell"></td>
                                                                                                @else
                                                                                                    <td class="permissions-action-cell">
                                                                                                        <label class="permission-action-checkbox-wrapper">
                                                                                                            <input
                                                                                                                type="checkbox"
                                                                                                                class="permission-action-checkbox permission-switch-input"
                                                                                                                name="permissions[{{ $subKey }}][{{ $actionKey }}]"
                                                                                                                value="1"
                                                                                                                @if($isChecked) checked @endif
                                                                                                                @if($subKey === 'tickets' && $actionKey === 'ver') data-vista-selector-toggle="true" @endif
                                                                                                                {{ ($readOnly ?? false || $rowDisabled || $lockedByRole) ? 'disabled' : '' }}
                                                                                                            >
                                                                                                            <span class="permission-action-checkbox-box" aria-hidden="true"></span>
                                                                                                        </label>
                                                                                                    </td>
                                                                                                @endif
                                                                                            @endforeach
                                                                                        </tr>
                                                                                    @endforeach
                                                                                @else
                                                                                    <tr>
                                                                                        <td class="permissions-module-label">{{ $moduleEntry['moduleLabel'] }}</td>
                                                                                        @foreach($tablePermissionActions as $actionKey => $actionLabel)
                                                                                            @php
                                                                                                $isChecked = !empty($permissionValue[$moduleEntry['moduleKey']][$actionKey]);
                                                                                                $isTicketsModule = $moduleEntry['moduleKey'] === 'tickets';
                                                                                                $isAuditoriaModule = $moduleEntry['moduleKey'] === 'configuracion.auditoria';
                                                                                                $isHiddenAction = ($isTicketsModule && in_array($actionKey, ['editar', 'eliminar'], true)) || ($isAuditoriaModule && $actionKey !== 'ver');
                                                                                            @endphp
                                                                                            @if($isHiddenAction)
                                                                                                <td class="permissions-action-cell"></td>
                                                                                            @else
                                                                                            <td class="permissions-action-cell">
                                                                                                <label class="permission-action-checkbox-wrapper">
                                                                                                    <input
                                                                                                        type="checkbox"
                                                                                                        class="permission-action-checkbox permission-switch-input"
                                                                                                        name="permissions[{{ $moduleEntry['moduleKey'] }}][{{ $actionKey }}]"
                                                                                                        value="1"
                                                                                                        @if($isChecked) checked @endif
                                                                                                        {{ ($readOnly ?? false || $lockedByRole) ? 'disabled' : '' }}
                                                                                                    >
                                                                                                    <span class="permission-action-checkbox-box" aria-hidden="true"></span>
                                                                                                </label>
                                                                                            </td>
                                                                                            @endif
                                                                                        @endforeach
                                                                                    </tr>
                                                                                @endif
                                                                            </tbody>
                                                                        </table>
                                                                    @endif
                                                                </div>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                                @if($hasError)
                                                    <p class="mt-2 text-sm text-red-500">{{ $errorMessage }}</p>
                                                @endif
                                                <p class="mt-2 text-sm text-red-500 hidden permissions-field-error-message"></p>
                                            </fieldset>
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
                                                                <label class="inline-flex items-center gap-2 cursor-pointer rounded-lg border border-slate-300 px-3 py-2 text-sm transition duration-200 ease-in-out hover:border-slate-400 hover:bg-slate-50">
                                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-700" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1M16 8l-4-4m0 0L8 8m4-4v12"/></svg>
                                                                    <span class="text-sm text-slate-700 font-medium">Cambiar {{ strtolower($field['label']) }}</span>
                                                                    <input type="file" name="{{ $field['name'] }}" accept="{{ $acceptTypes }}" data-file-kind="{{ $fileKind }}" data-file-label="{{ strtolower($field['label']) }}" class="hidden file-upload-input" onchange="showFileSelectionMessage(this)" {{ ($readOnly ?? false) ? 'disabled' : '' }}>
                                                                </label>
                                                            </div>
                                                        </div>
                                                    @else
                                                        <label class="flex items-center gap-3 rounded-lg border-dashed border-2 p-3 text-slate-600 border-slate-200 cursor-pointer file-upload-placeholder" data-file-upload-placeholder>
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

                        @if(!empty($extraSections) && is_array($extraSections))
                            <div class="mt-8 space-y-6">
                                @foreach($extraSections as $extraSection)
                                    @include($extraSection['view'], $extraSection['data'] ?? [])
                                @endforeach
                            </div>
                        @endif

                        <!-- Listado de clientes asociados al grupo (solo lectura) -->
                        @if(isset($clientes) && count($clientes) > 0)
                            <div class="mt-8 mb-6">
                                <label class="block text-sm font-semibold text-slate-700 mb-2">Clientes en este grupo</label>
                                <div class="overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-left text-sm text-slate-700">
                                            <thead class="bg-slate-50 text-slate-500">
                                                <tr>
                                                    <th class="px-3 py-3 font-semibold">Cliente</th>
                                                    <th class="px-3 py-3 font-semibold">Razón social</th>
                                                    <th class="px-3 py-3 font-semibold">Rubro</th>
                                                    <th class="px-3 py-3 font-semibold">Dirección</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($clientes as $cliente)
                                                    <tr class="border-t border-slate-200 hover:bg-slate-50">
                                                        <td class="px-3 py-3">
                                                            <a href="{{ route('modules.clientes.edit', $cliente->idcliente) }}" class="font-semibold text-slate-900 hover:text-primary hover:underline">
                                                                {{ $cliente->nombreComercial ?: 'Sin nombre comercial' }}
                                                            </a>
                                                        </td>
                                                        <td class="px-3 py-3 text-slate-600">{{ $cliente->razonSocial ?? 'Sin razón social' }}</td>
                                                        <td class="px-3 py-3 text-slate-600">{{ $cliente->rubro ?? 'Sin rubro' }}</td>
                                                        <td class="px-3 py-3 text-slate-600">{{ $cliente->direccion_completa ?: 'Sin dirección' }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        @endif

                        <!-- Listado de dispositivos cliente vinculados al vehículo (solo lectura) -->
                        @if(isset($dispositivos) && count($dispositivos) > 0)
                            <div class="mt-8 mb-6">
                                <label class="block text-sm font-semibold text-slate-700 mb-2">Dispositivos cliente vinculados</label>
                                <div class="overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-left text-sm text-slate-700">
                                            <thead class="bg-slate-50 text-slate-500">
                                                <tr>
                                                    <th class="px-3 py-3 font-semibold">ID Dispositivo</th>
                                                    <th class="px-3 py-3 font-semibold">Marca</th>
                                                    <th class="px-3 py-3 font-semibold">Modelo</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($dispositivos as $dispositivo)
                                                    <tr class="border-t border-slate-200 hover:bg-slate-50">
                                                        <td class="px-3 py-3">
                                                            <a href="{{ route('modules.dispositivo-cliente.edit', $dispositivo->iddispositivoCliente) }}" class="font-semibold text-slate-900 hover:text-primary hover:underline">
                                                                {{ $dispositivo->iddispositivoCliente }}
                                                            </a>
                                                        </td>
                                                        <td class="px-3 py-3 text-slate-600">{{ $dispositivo->marcaDispositivo ?? 'N/A' }}</td>
                                                        <td class="px-3 py-3 text-slate-600">{{ $dispositivo->modeloDispositivo ?? 'N/A' }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        @endif

                        <!-- BOTONES DE ACCIÓN -->
                        <div class="mt-6 flex items-center justify-end gap-2">
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
                        modal.style.alignItems = 'center';
                        document.body.style.overflow = 'hidden';
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

    <div id="delete-confirmation-modal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:9999;background:rgba(0,0,0,0.8);align-items:center;justify-content:center;" role="dialog" aria-modal="true" aria-labelledby="delete-confirmation-title" aria-describedby="delete-confirmation-message">
        <div style="width:720px;max-width:92%;margin:0 auto;position:relative;border-radius:20px;background:#ffffff;box-shadow:0 20px 40px rgba(2,6,23,0.12);overflow:hidden;">
            <button type="button" data-delete-modal-close style="position:absolute;right:16px;top:16px;height:44px;width:44px;border-radius:9999px;border:1px solid #e6e9ee;background:#fff;color:#6b7280;display:inline-flex;align-items:center;justify-content:center;" aria-label="Cerrar">
                <i data-lucide="x" style="width:16px;height:16px"></i>
            </button>
            <div style="padding:40px 48px;text-align:left;">
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
        <div id="quick-direccion-modal" class="fixed inset-0 hidden items-center justify-center px-4 backdrop-blur-sm" style="z-index: 9999; background-color: rgba(0, 0, 0, 0.78);">
            <div class="w-full rounded-lg bg-white shadow-2xl ring-1 ring-slate-900/10 border-t-4 border-red-600 overflow-hidden" style="max-width: 980px;">
                <div class="flex items-start justify-between border-b border-slate-200 bg-gradient-to-r from-slate-50 to-white px-6 py-5">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900">Seleccionar o crear direccion</h3>
                        <p class="mt-2 text-sm text-slate-600">Elige una direccion existente o crear rapidamente sin salir del formulario.</p>
                    </div>
                    <button type="button" id="quick-direccion-close" class="ml-auto rounded-lg border-0 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-100 hover:text-red-600 transition duration-200 flex-shrink-0">
                        <i data-lucide="x" class="h-5 w-5"></i>
                    </button>
                </div>

                <div class="grid gap-5 bg-white p-7 md:grid-cols-2">
                    <div>
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <h4 class="text-sm font-bold text-slate-900">Direcciones</h4>
                                <span id="quick-direccion-count" class="rounded-full bg-red-50 px-3 py-1 text-xs font-semibold text-red-600">0</span>
                            </div>
                            <div class="flex items-center gap-2 text-xs text-slate-500 ml-auto">
                                <h4 class="text-sm text-slate-600">Exportar:</h4>
                                <a id="quick-direccion-export-pdf" href="#" target="_blank" class="hidden inline-flex items-center rounded-md border border-slate-200 bg-slate-100 px-3 py-2 font-semibold text-slate-700 hover:bg-slate-200 transition">
                                    <i data-tw-merge="" data-lucide="file-text" class="stroke-[1] mr-2 h-4 w-4"></i>
                                    PDF
                                </a>
                                <a id="quick-direccion-export-xlsx" href="#" target="_blank" class="hidden inline-flex items-center rounded-md border border-slate-200 bg-slate-100 px-3 py-2 font-semibold text-slate-700 hover:bg-slate-200 transition">
                                    <i data-tw-merge="" data-lucide="file-text" class="stroke-[1] mr-2 h-4 w-4"></i>
                                    XLSX
                                </a>
                            </div>
                        </div>
                        <div class="mb-3">
                            <input type="text" id="quick-direccion-search" placeholder="Buscar direccion..." class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm focus:border-red-600 focus:ring-2 focus:ring-red-500/20 transition duration-200">
                        </div>
                        <div id="quick-direccion-list" class="space-y-2 overflow-y-auto rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700" style="max-height: 400px; overflow-y: auto;"></div>
                    </div>

                    <div>
                        <h4 class="mb-2 text-sm font-semibold text-slate-800">Crear direccion rapida</h4>
                        <form id="quick-direccion-form" class="space-y-5 rounded-lg border border-red-200 bg-red-50/30 p-4">
                            <div class="mb-4 relative">
                                <label class="mb-3 block text-sm font-medium text-slate-700">Tipo</label>
                                <input
                                    type="text"
                                    id="quick-tipo"
                                    required
                                    class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 ease-in-out focus:border-red-600 focus:ring-2 focus:ring-red-500/20"
                                    placeholder="Selecciona o escribe un tipo"
                                    data-datalist-options='["principal","base","taller","oficina"]'
                                    autocomplete="off"
                                >
                                <div class="custom-datalist hidden absolute left-0 right-0 z-20 mt-1 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg">
                                    <div class="custom-datalist-options overflow-y-auto" style="max-height: 150px;"></div>
                                </div>
                            </div>
                            <div class="mb-4">
                                <label class="mb-3 block text-sm font-medium text-slate-700">Direccion <span class="text-red-600">*</span> <span class="text-xs font-normal text-slate-500">mínimo 5 caracteres</span></label>
                                <input type="text" id="quick-direccion" maxlength="200" minlength="5" pattern="^[A-Za-z0-9ÁÉÍÓÚáéíóúÑñ\s\.,#\-/]{5,200}$" title="Dirección debe tener al menos 5 caracteres." required class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 ease-in-out focus:border-red-600 focus:ring-2 focus:ring-red-500/20">
                            </div>
                            <div class="mb-4">
                                <label class="mb-3 block text-sm font-medium text-slate-700">Ubigeo <span class="text-red-600">*</span></label>
                                <select id="quick-ubigeo" required class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 ease-in-out focus:border-red-600 focus:ring-2 focus:ring-red-500/20" data-placeholder="Selecciona ubigeo"></select>
                            </div>
                            <div class="mb-4">
                                <label class="mb-3 block text-sm font-medium text-slate-700">Link ubicacion <span class="text-xs font-normal text-slate-500">opcional, formato URL</span></label>
                                <input type="url" id="quick-link" maxlength="300" inputmode="url" placeholder="https://..." class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 ease-in-out focus:border-red-600 focus:ring-2 focus:ring-red-500/20">
                            </div>

                            <div class="mt-4 flex items-center justify-end gap-3 border-t border-red-200 pt-4">
                                <button type="button" id="quick-direccion-cancel" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-100" style=" border-color: #000000; color: #000000;">Cancelar</button>
                                <button type="submit" id="quick-direccion-submit" class="rounded-lg border-0 px-5 py-2.5 text-xs font-semibold shadow-lg transition duration-200" style="background-color: #c1121f; color: #ffffff;">Guardar direccion</button>
                            </div>
                            <p id="quick-direccion-feedback" class="text-xs"></p>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <script>
            (function () {
                const modal = document.getElementById('quick-direccion-modal');
                const closeBtn = document.getElementById('quick-direccion-close');
                const cancelBtn = document.getElementById('quick-direccion-cancel');
                const listContainer = document.getElementById('quick-direccion-list');
                const form = document.getElementById('quick-direccion-form');
                const tipoInput = document.getElementById('quick-tipo');
                const direccionInput = document.getElementById('quick-direccion');
                const ubigeoSelect = document.getElementById('quick-ubigeo');
                const linkInput = document.getElementById('quick-link');
                const searchInput = document.getElementById('quick-direccion-search');
                const countBadge = document.getElementById('quick-direccion-count');
                let ubigeoTomSelect = null;

                const resetDropdownPosition = (dropdown) => {
                    if (!dropdown) {
                        return;
                    }

                    dropdown.style.top = '';
                    dropdown.style.right = '';
                    dropdown.style.bottom = '';
                    dropdown.style.left = '';
                    dropdown.style.width = '';
                    dropdown.style.marginTop = '';
                    dropdown.style.marginBottom = '';
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

                    if (instance.settings.dropdownParent === 'body') {
                        dropdown.style.top = Math.max(window.scrollY + rect.top - dropdownHeight - 4, 0) + 'px';
                        dropdown.style.left = (rect.left + window.scrollX) + 'px';
                        dropdown.style.width = rect.width + 'px';
                    } else {
                        dropdown.style.top = 'auto';
                        dropdown.style.bottom = '100%';
                        dropdown.style.marginTop = '0';
                        dropdown.style.marginBottom = '0.25rem';
                    }
                };

                const initUbigeoTomSelect = () => {
                    if (!ubigeoSelect || typeof window.TomSelect !== 'function') {
                        return;
                    }

                    if (ubigeoTomSelect && typeof ubigeoTomSelect.destroy === 'function') {
                        ubigeoTomSelect.destroy();
                        ubigeoTomSelect = null;
                    }

                    ubigeoTomSelect = new TomSelect(ubigeoSelect, {
                        plugins: { dropdown_input: {} },
                        create: false,
                        sortField: [{ field: 'text', direction: 'asc' }],
                        placeholder: ubigeoSelect.dataset.placeholder || 'Selecciona ubigeo',
                        allowEmptyOption: true,
                        render: {
                            item: function(data, escape) {
                                return '<div>' + escape(data.text) + '</div>';
                            }
                        }
                    });

                    ubigeoTomSelect.on('dropdown_open', () => {
                        updateDropdownPosition(ubigeoTomSelect);
                    });

                    ubigeoTomSelect.on('dropdown_close', () => {
                        resetDropdownPosition(ubigeoTomSelect.dropdown);
                    });

                    const ubigeoWrapper = ubigeoSelect.nextElementSibling;
                    if (ubigeoWrapper) {
                        const dropdownEl = ubigeoWrapper.querySelector('.ts-dropdown');
                        const dropdownContent = dropdownEl?.querySelector('.ts-dropdown-content');
                        if (dropdownContent) {
                            dropdownContent.style.maxHeight = '110px';
                            dropdownContent.style.overflowY = 'auto';
                        }
                    }

                    // Oculta el select original para evitar mostrar un cuadro vacío encima
                    try {
                        ubigeoSelect.style.display = 'none';
                    } catch (e) {
                        // no-op
                    }
                };

                const renderUbigeos = () => {
                    if (!ubigeoSelect) {
                        return;
                    }

                    if (ubigeoTomSelect && typeof ubigeoTomSelect.clearOptions === 'function') {
                        ubigeoTomSelect.clearOptions();
                        ubigeoTomSelect.addOption({ value: '', text: 'Selecciona ubigeo' });
                        currentUbigeos.forEach((item) => {
                            ubigeoTomSelect.addOption({ value: String(item.id ?? ''), text: String(item.label ?? '') });
                        });
                        if (typeof ubigeoTomSelect.refreshOptions === 'function') {
                            ubigeoTomSelect.refreshOptions(false);
                        }
                    } else {
                        ubigeoSelect.innerHTML = '<option value="">Selecciona ubigeo</option>';
                        currentUbigeos.forEach((item) => {
                            const option = document.createElement('option');
                            option.value = String(item.id ?? '');
                            option.textContent = String(item.label ?? '');
                            ubigeoSelect.appendChild(option);
                        });
                        initUbigeoTomSelect();
                    }
                };

                const submitBtn = document.getElementById('quick-direccion-submit');
                const feedback = document.getElementById('quick-direccion-feedback');
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
                const quickButtons = document.querySelectorAll('[data-quick-direccion-button]');

                const confirmDireccion = ({ title = 'Confirmar', message = '', submitText = 'Eliminar', cancelText = 'Cancelar' } = {}) => {
                    const id = 'quick-direccion-confirm-modal';
                    let modal = document.getElementById(id);
                    if (!modal) {
                        modal = document.createElement('div');
                        modal.id = id;
                        modal.style = 'display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:10000;background:rgba(0,0,0,0.6);align-items:center;justify-content:center;';
                        modal.innerHTML = `
                            <div style="width:720px;max-width:92%;margin:0 auto;position:relative;border-radius:12px;background:#fff;padding:28px;box-shadow:0 12px 28px rgba(2,6,23,0.12);">
                                <h3 style="margin:0 0 10px;font-size:18px;font-weight:600;color:#111827;">${title}</h3>
                                <p style="margin:0 0 18px;color:#6b7280;font-size:14px;">${message}</p>
                                <div style="display:flex;gap:12px;justify-content:flex-end;">
                                    <button type="button" class="qdir-cancel" style="min-width:120px;padding:10px 18px;border-radius:8px;border:1px solid #e6e9ee;background:#fff;color:#374151;font-weight:600;">${cancelText}</button>
                                    <button type="button" class="qdir-submit" style="min-width:120px;padding:10px 18px;border-radius:8px;background:#ef4444;color:#fff;font-weight:600;border:none;">${submitText}</button>
                                </div>
                            </div>`;
                        document.body.appendChild(modal);
                    }

                    const submitBtn = modal.querySelector('.qdir-submit');
                    const cancelBtn = modal.querySelector('.qdir-cancel');
                    modal.style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                    return new Promise((resolve) => {
                        const cleanup = () => {
                            modal.style.display = 'none';
                            document.body.style.overflow = '';
                            submitBtn.removeEventListener('click', onConfirm);
                            cancelBtn.removeEventListener('click', onCancel);
                        };
                        const onConfirm = () => { cleanup(); resolve(true); };
                        const onCancel = () => { cleanup(); resolve(false); };
                        cancelBtn.addEventListener('click', onCancel);
                        submitBtn.addEventListener('click', onConfirm);
                    });
                };

                const safeJsonParse = (value) => {
                    try {
                        return JSON.parse(value);
                    } catch {
                        return [];
                    }
                };

                const getPayloadInput = () => {
                    const mainForm = document.getElementById('main-crud-form');
                    if (!mainForm) {
                        return null;
                    }

                    let input = mainForm.querySelector('input[name="' + currentPayloadInputName + '"]');
                    if (!input) {
                        input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = currentPayloadInputName;
                        input.value = '[]';
                        mainForm.appendChild(input);
                    }

                    return input;
                };

                const readLocalDirecciones = () => {
                    const input = getPayloadInput();
                    if (!input) {
                        return [];
                    }

                    try {
                        const parsed = JSON.parse(String(input.value || '[]'));
                        return Array.isArray(parsed) ? parsed : [];
                    } catch {
                        return [];
                    }
                };

                const writeLocalDirecciones = (items) => {
                    const input = getPayloadInput();
                    if (!input) {
                        return;
                    }
                    input.value = JSON.stringify(Array.isArray(items) ? items : []);
                };

                const buildLocalDireccionLabel = (item) => {
                    const direccion = String(item.direccion || '').trim();
                    const ubigeo = String(item.ubigeo_text || '').trim();
                    let label = direccion !== '' ? direccion : 'Dirección temporal';
                    if (ubigeo !== '') {
                        label += ' (' + ubigeo + ')';
                    }
                    return label;
                };

                if (!modal || quickButtons.length === 0) {
                    return;
                }

                // Evita problemas de stacking context: el modal vive al nivel del body.
                if (modal.parentElement !== document.body) {
                    document.body.appendChild(modal);
                }

                let currentSelectId = null;
                let currentListUrl = null;
                let currentStoreUrl = null;
                let currentUpdateUrlTemplate = null;
                let currentDeleteUrlTemplate = null;
                let currentPayloadInputName = 'direcciones_payload';
                let currentUbigeos = [];
                let currentExportPdfUrl = '';
                let currentExportXlsxUrl = '';
                let listItems = [];
                let selectedDireccionId = null;
                let editingDireccionId = null;
                const exportPdfBtn = document.getElementById('quick-direccion-export-pdf');
                const exportXlsxBtn = document.getElementById('quick-direccion-export-xlsx');

                const closeModal = () => {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                    document.body.style.overflow = '';
                    editingDireccionId = null;
                    if (submitBtn) {
                        submitBtn.textContent = 'Guardar direccion';
                    }
                    feedback.textContent = '';
                    feedback.className = 'text-xs';
                };

                const updateExportButtons = () => {
                    if (exportPdfBtn) {
                        if (currentExportPdfUrl) {
                            exportPdfBtn.href = currentExportPdfUrl;
                            exportPdfBtn.classList.remove('hidden');
                        } else {
                            exportPdfBtn.classList.add('hidden');
                        }
                    }
                    if (exportXlsxBtn) {
                        if (currentExportXlsxUrl) {
                            exportXlsxBtn.href = currentExportXlsxUrl;
                            exportXlsxBtn.classList.remove('hidden');
                        } else {
                            exportXlsxBtn.classList.add('hidden');
                        }
                    }
                };

                const applySelectOption = (item) => {
                    const target = document.getElementById(currentSelectId);
                    if (!target || !item) {
                        return;
                    }

                    const value = String(item.id);
                    const label = String(item.label ?? '');

                    const inst = target.tomselect || target.tomSelect || target._tomselect || null;
                    const existingOption = target.querySelector('option[value="' + value + '"]');

                    if (inst && typeof inst.addOption === 'function') {
                        try {
                            if (existingOption) {
                                if (typeof inst.updateOption === 'function') {
                                    inst.updateOption(value, { value: value, text: label });
                                } else {
                                    try { inst.removeOption(value); } catch (e) {}
                                    inst.addOption({ value: value, text: label }, true);
                                }
                            } else {
                                inst.addOption({ value: value, text: label }, true);
                            }

                            if (typeof inst.addItem === 'function') {
                                inst.addItem(value);
                            } else if (typeof inst.setValue === 'function') {
                                inst.setValue(value);
                            }
                        } catch (e) {
                            // fallback to DOM handling below
                            let option = existingOption;
                            if (!option) {
                                option = document.createElement('option');
                                option.value = value;
                                target.appendChild(option);
                            }
                            option.textContent = label;
                            target.value = value;
                        }
                    } else {
                        let option = existingOption;
                        if (!option) {
                            option = document.createElement('option');
                            option.value = value;
                            target.appendChild(option);
                        }
                        option.textContent = label;
                        target.value = value;
                    }

                    selectedDireccionId = value;
                    target.dispatchEvent(new Event('change', { bubbles: true }));
                };

                const replaceIdToken = (template, id) => String(template || '').replace('__ID__', encodeURIComponent(String(id)));

                const beginEditDireccion = (item) => {
                    editingDireccionId = String(item.id);
                    tipoInput.value = String(item.tipo ?? '');
                    direccionInput.value = String(item.direccion ?? '');
                    ubigeoSelect.value = String(item.ubigeo_idubigeo ?? '');
                    // Asegura que TomSelect esté inicializado y muestre el valor al editar
                    initUbigeoTomSelect();
                    if (ubigeoTomSelect && typeof ubigeoTomSelect.setValue === 'function') {
                        try {
                            ubigeoTomSelect.setValue(String(item.ubigeo_idubigeo ?? ''));
                        } catch (e) {
                            // fallback: asigna el value al select nativo
                            ubigeoSelect.value = String(item.ubigeo_idubigeo ?? '');
                        }
                    }
                    linkInput.value = String(item.linkUbicacion ?? '');
                    if (submitBtn) {
                        submitBtn.textContent = 'Actualizar direccion';
                    }
                    feedback.textContent = 'Editando direccion #' + String(item.id);
                    feedback.className = 'text-xs text-slate-600';
                };

                const deleteDireccionLocal = (item) => {
                    const localItems = readLocalDirecciones();
                    const nextItems = localItems.filter((entry) => String(entry.tempId || entry.id) !== String(item.id));
                    writeLocalDirecciones(nextItems);

                    const target = document.getElementById(currentSelectId);
                    if (target) {
                        const value = String(item.id);
                        const inst = target.tomselect || target.tomSelect || target._tomselect || null;
                        try {
                            if (inst && typeof inst.removeItem === 'function') {
                                inst.removeItem(value);
                            }
                            if (inst && typeof inst.removeOption === 'function') {
                                inst.removeOption(value);
                            } else {
                                const option = target.querySelector('option[value="' + value + '"]');
                                if (option) option.remove();
                            }
                        } catch (e) {
                            const option = target.querySelector('option[value="' + value + '"]');
                            if (option) option.remove();
                        }

                        if (String(target.value) === value) {
                            target.value = '';
                            selectedDireccionId = null;
                            target.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }
                };

                const deleteDireccion = async (item) => {
                    if (!currentDeleteUrlTemplate || String(item.id).startsWith('tmp-')) {
                        deleteDireccionLocal(item);
                        return;
                    }

                    const response = await fetch(replaceIdToken(currentDeleteUrlTemplate, item.id), {
                        method: 'DELETE',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                    });

                    const payload = await response.json();
                    if (!response.ok || !payload.ok) {
                        throw new Error(payload.message || 'No se pudo eliminar la direccion.');
                    }

                    const target = document.getElementById(currentSelectId);
                    if (target) {
                        const value = String(item.id);
                        const inst = target.tomselect || target.tomSelect || target._tomselect || null;
                        try {
                            if (inst && typeof inst.removeItem === 'function') inst.removeItem(value);
                            if (inst && typeof inst.removeOption === 'function') inst.removeOption(value);
                            else {
                                const option = target.querySelector('option[value="' + value + '"]');
                                if (option) option.remove();
                            }
                        } catch (e) {
                            const option = target.querySelector('option[value="' + value + '"]');
                            if (option) option.remove();
                        }

                        if (String(target.value) === value) {
                            target.value = '';
                            selectedDireccionId = null;
                            target.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }

                    if (editingDireccionId === String(item.id)) {
                        editingDireccionId = null;
                        form.reset();
                        renderUbigeos();
                        if (submitBtn) {
                            submitBtn.textContent = 'Guardar direccion';
                        }
                    }

                    await loadDirecciones();
                };

                const updateCount = (count) => {
                    if (!countBadge) {
                        return;
                    }
                    countBadge.textContent = String(count);
                };

                const renderList = (items) => {
                    if (!Array.isArray(items) || items.length === 0) {
                        updateCount(0);
                        listContainer.innerHTML = '<p class="text-xs text-slate-500">No hay direcciones registradas.</p>';
                        return;
                    }

                    updateCount(items.length);

                    listContainer.innerHTML = '';
                    items.forEach((item) => {
                        const row = document.createElement('div');
                        const isSelected = String(selectedDireccionId) === String(item.id);
                        row.className = 'flex items-center justify-between mb-2 gap-3 rounded-md border p-2 transition ' +
                            (isSelected ? 'border-primary bg-red-50/50 ring-1 ring-inset ring-red-200' : 'border-slate-200 bg-white hover:border-slate-300');

                        const text = document.createElement('div');
                        text.className = 'pr-2 text-xs text-slate-700 leading-5';
                        text.textContent = String(item.label ?? '');

                        const actions = document.createElement('div');
                        actions.className = 'flex items-center gap-1.5';

                        const editBtn = document.createElement('button');
                        editBtn.type = 'button';
                        editBtn.className = 'rounded border border-slate-300 bg-white px-2 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-50';
                        editBtn.textContent = 'Editar';
                        editBtn.addEventListener('click', () => beginEditDireccion(item));

                        const deleteBtn = document.createElement('button');
                        deleteBtn.type = 'button';
                        deleteBtn.className = 'rounded border border-red-200 bg-red-50 px-2 py-1 text-[11px] font-medium text-red-700 hover:bg-red-100';
                        deleteBtn.textContent = 'Eliminar';
                        deleteBtn.addEventListener('click', async () => {
                            const ok = await confirmDireccion({ title: 'Confirmar eliminación', message: '¿Está seguro de eliminar la dirección seleccionada?', submitText: 'Eliminar', cancelText: 'Cancelar' });
                            if (!ok) return;

                            feedback.textContent = 'Eliminando direccion...';
                            feedback.className = 'text-xs text-slate-600';
                            try {
                                await deleteDireccion(item);
                                feedback.textContent = 'Direccion eliminada correctamente.';
                                feedback.className = 'text-xs text-emerald-700';
                            } catch (error) {
                                feedback.textContent = error.message || 'No se pudo eliminar la direccion.';
                                feedback.className = 'text-xs text-red-600';
                            }
                        });

                        const pickBtn = document.createElement('button');
                        pickBtn.type = 'button';
                        pickBtn.className = 'min-w-[102px] rounded border px-2.5 py-1.5 text-[11px] font-medium shadow-sm ' +
                            (isSelected
                                ? 'border-primary bg-primary text-white'
                                : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50');
                        pickBtn.textContent = isSelected ? 'Seleccionada' : 'Seleccionar';
                        pickBtn.addEventListener('click', () => {
                            applySelectOption(item);
                            closeModal();
                        });

                        actions.appendChild(editBtn);
                        actions.appendChild(deleteBtn);
                        actions.appendChild(pickBtn);

                        row.appendChild(text);
                        row.appendChild(actions);
                        listContainer.appendChild(row);
                    });
                };

                const loadDirecciones = async () => {
                    if (!currentStoreUrl) {
                        const localItems = readLocalDirecciones().map((item, index) => {
                            const id = String(item.tempId || item.id || ('tmp-' + index));
                            return {
                                id: id,
                                label: buildLocalDireccionLabel(item),
                                tipo: String(item.tipo || ''),
                                direccion: String(item.direccion || ''),
                                ubigeo_idubigeo: Number(item.ubigeo_idubigeo || 0),
                                ubigeo_text: String(item.ubigeo_text || ''),
                                linkUbicacion: String(item.linkUbicacion || ''),
                            };
                        });
                        listItems = localItems;
                        renderList(listItems);
                        return;
                    }

                    listContainer.innerHTML = '<p class="text-xs text-slate-500">Cargando...</p>';
                    try {
                        const response = await fetch(currentListUrl, {
                            headers: {
                                Accept: 'application/json',
                            },
                        });

                        const payload = await response.json();
                        listItems = Array.isArray(payload.data) ? payload.data : [];
                        renderList(listItems);
                    } catch (error) {
                        updateCount(0);
                        listContainer.innerHTML = '<p class="text-xs text-red-600">No se pudo cargar las direcciones.</p>';
                    }
                };

                const openModal = (button) => {
                    currentSelectId = button.getAttribute('data-quick-target');
                    currentListUrl = button.getAttribute('data-quick-list-url');
                    currentStoreUrl = button.getAttribute('data-quick-store-url');
                    currentUpdateUrlTemplate = button.getAttribute('data-quick-update-url-template');
                    currentDeleteUrlTemplate = button.getAttribute('data-quick-delete-url-template');
                    currentExportPdfUrl = button.getAttribute('data-quick-export-pdf-url') || '';
                    currentExportXlsxUrl = button.getAttribute('data-quick-export-xlsx-url') || '';
                    currentPayloadInputName = button.getAttribute('data-quick-payload-input') || 'direcciones_payload';
                    currentUbigeos = safeJsonParse(button.getAttribute('data-quick-ubigeos') || '[]');
                    const target = document.getElementById(currentSelectId);
                    selectedDireccionId = target ? String(target.value || '') : null;
                    editingDireccionId = null;

                    updateExportButtons();
                    tipoInput.value = '';
                    direccionInput.value = '';
                    ubigeoSelect.value = '';
                    linkInput.value = '';
                    if (searchInput) {
                        searchInput.value = '';
                    }
                    feedback.textContent = '';
                    feedback.className = 'text-xs';
                    if (submitBtn) {
                        submitBtn.textContent = 'Guardar direccion';
                    }

                    renderUbigeos();
                    if (ubigeoTomSelect && typeof ubigeoTomSelect.setValue === 'function') {
                        try {
                            ubigeoTomSelect.setValue('');
                        } catch (e) {
                            // no-op
                        }
                    }
                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                    document.body.style.overflow = 'hidden';

                    loadDirecciones();
                };

                quickButtons.forEach((button) => {
                    button.addEventListener('click', () => {
                        if (button.disabled || !button.hasAttribute('data-quick-direccion-button')) {
                            return;
                        }
                        openModal(button);
                    });
                });

                closeBtn?.addEventListener('click', closeModal);
                cancelBtn?.addEventListener('click', closeModal);

                // El cierre es solo por botones Cerrar o Cancelar.

                searchInput?.addEventListener('input', () => {
                    const term = String(searchInput.value || '').trim().toLowerCase();
                    if (term === '') {
                        renderList(listItems);
                        return;
                    }

                    const filtered = listItems.filter((item) =>
                        String(item.label || '').toLowerCase().includes(term)
                    );
                    renderList(filtered);
                });

                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    if (!form.checkValidity()) {
                        form.reportValidity();
                        throw new Error('Corrige los campos obligatorios de la dirección.');
                    }
                    feedback.textContent = editingDireccionId ? 'Actualizando direccion...' : 'Guardando direccion...';
                    feedback.className = 'text-xs text-slate-600';
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.classList.add('opacity-70', 'cursor-not-allowed');
                    }

                    try {
                        const isEditing = !!editingDireccionId;
                        const isLocal = !currentStoreUrl;
                        const target = document.getElementById(currentSelectId);

                        if (isLocal) {
                            if (!direccionInput.value.trim() || !ubigeoSelect.value) {
                                throw new Error('Completa la direccion y el ubigeo.');
                            }

                            const localItems = readLocalDirecciones();
                            const tempId = editingDireccionId || ('tmp-' + Date.now());
                            const ubigeoText = ubigeoSelect.options[ubigeoSelect.selectedIndex]?.text || '';
                            const localItem = {
                                tempId: tempId,
                                tipo: tipoInput.value || null,
                                direccion: String(direccionInput.value || '').trim(),
                                ubigeo_idubigeo: Number(ubigeoSelect.value || 0),
                                ubigeo_text: ubigeoText,
                                linkUbicacion: linkInput.value || null,
                            };

                            const existingIndex = localItems.findIndex((entry) => String(entry.tempId || entry.id) === tempId);
                            if (existingIndex >= 0) {
                                localItems[existingIndex] = localItem;
                            } else {
                                localItems.push(localItem);
                            }
                            writeLocalDirecciones(localItems);

                            if (target) {
                                let option = target.querySelector('option[value="' + tempId + '"]');
                                if (!option) {
                                    option = document.createElement('option');
                                    option.value = tempId;
                                    target.appendChild(option);
                                }
                                option.textContent = buildLocalDireccionLabel(localItem);
                                target.value = tempId;
                                selectedDireccionId = tempId;
                                target.dispatchEvent(new Event('change', { bubbles: true }));
                            }

                            editingDireccionId = null;
                            if (submitBtn) {
                                submitBtn.textContent = 'Guardar direccion';
                            }
                            if (ubigeoTomSelect && typeof ubigeoTomSelect.setValue === 'function') {
                                try {
                                    ubigeoTomSelect.setValue('');
                                } catch (e) {
                                    // no-op
                                }
                            }
                            await loadDirecciones();
                            closeModal();
                            return;
                        }

                        const url = isEditing
                            ? replaceIdToken(currentUpdateUrlTemplate, editingDireccionId)
                            : currentStoreUrl;

                        const response = await fetch(url, {
                            method: isEditing ? 'PUT' : 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            body: JSON.stringify({
                                tipo: tipoInput.value || null,
                                direccion: direccionInput.value,
                                ubigeo_idubigeo: ubigeoSelect.value,
                                linkUbicacion: linkInput.value || null,
                            }),
                        });

                        const payload = await response.json();
                        if (!response.ok || !payload.ok) {
                            throw new Error(payload.message || (isEditing ? 'No se pudo actualizar la direccion.' : 'No se pudo crear la direccion.'));
                        }

                        if (payload.data) {
                            applySelectOption(payload.data);
                        }

                        editingDireccionId = null;
                        if (submitBtn) {
                            submitBtn.textContent = 'Guardar direccion';
                        }
                        if (ubigeoTomSelect && typeof ubigeoTomSelect.setValue === 'function') {
                            try {
                                ubigeoTomSelect.setValue('');
                            } catch (e) {
                                // no-op
                            }
                        }

                        await loadDirecciones();

                        closeModal();
                    } catch (error) {
                        feedback.textContent = error.message || 'Ocurrio un error al guardar direccion.';
                        feedback.className = 'text-xs text-red-600';
                    } finally {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.classList.remove('opacity-70', 'cursor-not-allowed');
                        }
                    }
                });
            })();
        </script>
    @endif

    @if($hasQuickDetalleListaPrecio)
        <div id="quick-detalle-lista-precio-modal" class="fixed inset-0 hidden items-center justify-center px-4 backdrop-blur-sm" style="z-index: 9999; background-color: rgba(0, 0, 0, 0.78);">
            <div class="w-full rounded-xl bg-white shadow-2xl ring-1 ring-slate-900/10 border-t-4 border-red-600 overflow-hidden" style="max-width: 980px;">
                <div class="flex items-start justify-between border-b border-slate-200 bg-gradient-to-r from-slate-50 to-white px-6 py-5">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900">Crear detalle de lista de precio</h3>
                        <p class="mt-2 text-sm text-slate-600">Agrega varios precios para este almacén antes de guardar el formulario.</p>
                    </div>
                    <button type="button" id="quick-detalle-close" class="ml-auto flex-shrink-0 rounded-lg border-0 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition duration-200 hover:bg-slate-100 hover:text-red-600">
                        <i data-lucide="x" class="h-5 w-5"></i>
                    </button>
                </div>

                <div class="grid gap-5 bg-white p-7 md:grid-cols-2">
                    <div>
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <h4 class="text-sm font-bold text-slate-900">Detalles</h4>
                                <span id="quick-detalle-pending-count" class="rounded-full bg-red-50 px-3 py-1 text-xs font-semibold text-red-600">0</span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <input type="text" id="quick-detalle-search" placeholder="Buscar lista de precio..." class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 focus:border-red-600 focus:ring-2 focus:ring-red-500/20">
                        </div>
                        <div>
                            <div class="space-y-2 overflow-y-auto rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700" id="quick-detalle-saved-list" style="max-height: 225px; overflow-y: auto;"></div>
                        </div>
                    </div>
                    <div>
                        <h4 class="mb-4 text-sm font-semibold text-slate-800">Agregar precio rápido</h4>
                        <form class="space-y-5 rounded-lg gap-4 border border-gray-5 bg-red-50/30 p-4" onsubmit="return false;">
                            <div class="grid mt-2 gap-4 sm:grid-cols-[1.4fr_0.8fr] mb-4">
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Lista de precio *</label>
                                    <select id="quick-detalle-lista" required class="tom-select tom-select--compact w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></select>
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Precio *</label>
                                    <input id="quick-detalle-precio" type="number" min="0" step="0.01" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm transition duration-200 focus:border-red-600 focus:ring-2 focus:ring-red-500/20" placeholder="0.00">
                                </div>
                            </div>
                            <div class="mt-6 mb-2 flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
                                <button type="button" id="quick-detalle-cancel" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-100" style=" border-color: #000000; color: #000000;">Cancelar</button>
                                <button type="button" id="quick-detalle-add" class="rounded-md border-0 px-4 py-2 text-xs font-semibold text-white shadow-sm transition duration-200" style="background-color: #c1121f; color: #ffffff;">Guardar detalle</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if($hasQuickContacto)
        <div id="quick-contacto-modal" class="fixed inset-0 hidden items-center justify-center px-4 backdrop-blur-sm" style="z-index: 9999; background-color: rgba(0, 0, 0, 0.78);">
            <div class="w-full rounded-lg bg-white shadow-2xl ring-1 ring-slate-900/10 border-t-4 border-red-600 overflow-hidden" style="max-width: 980px;">
                <div class="flex items-start justify-between border-b border-slate-200 bg-gradient-to-r from-slate-50 to-white px-6 py-5">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900">Seleccionar o crear contacto</h3>
                        <p class="mt-2 text-sm text-slate-600">Elige un contacto existente o crearlo rapidamente sin salir del formulario.</p>
                        
                    </div>
                    <button type="button" id="quick-contacto-close" class="ml-auto rounded-lg border-0 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-100 hover:text-red-600 transition duration-200 flex-shrink-0">
                        <i data-lucide="x" class="h-5 w-5"></i>
                    </button>
                </div>
                <div class="grid gap-5 bg-white p-7 md:grid-cols-2">
                    <div>
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <h4 class="text-sm font-bold text-slate-900">Contactos</h4>
                                <span id="quick-contacto-count" class="rounded-full bg-red-50 px-3 py-1 text-xs font-semibold text-red-600">0</span>
                            </div>
                            <div class="flex items-center gap-2 text-xs text-slate-500 ml-auto">
                                <span>Exportar:</span>
                                <a id="quick-contacto-export-pdf" href="#" target="_blank" class="hidden inline-flex items-center rounded-md border border-slate-200 bg-slate-100 px-3 py-2 font-semibold text-slate-700 hover:bg-slate-200 transition">
                                    <i data-lucide="file-text" class="stroke-[1] mr-2 h-4 w-4"></i>
                                    PDF
                                </a>
                                <a id="quick-contacto-export-xlsx" href="#" target="_blank" class="hidden inline-flex items-center rounded-md border border-slate-200 bg-slate-100 px-3 py-2 font-semibold text-slate-700 hover:bg-slate-200 transition">
                                    <i data-lucide="file-text" class="stroke-[1] mr-2 h-4 w-4"></i>
                                    XLSX
                                </a>
                            </div>
                        </div>
                        <div class="mb-3">
                            <input type="text" id="quick-contacto-search" placeholder="Buscar contacto..." class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm focus:border-red-600 focus:ring-2 focus:ring-red-500/20 transition duration-200">
                        </div>
                        <div id="quick-contacto-list" class="space-y-2 overflow-y-auto rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700" style="max-height: 600px; overflow-y: auto;"></div>
                    </div>

                    <div>
                        <h4 class="mb-2 text-sm font-semibold text-slate-800">Crear contacto rapido</h4>
                        <form id="quick-contacto-form" class="space-y-6 rounded-lg border border-slate-200 bg-slate-50/40 p-4">
                            <div class="mb-4">
                                <label class="mb-4 block text-sm font-medium text-slate-700">Tipo de contacto *</label>
                                <div class="relative">
                                    <input
                                        type="text"
                                        id="quick-contacto-tipo-display"
                                        readonly
                                        placeholder="Selecciona tipo"
                                        class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 ease-in-out focus:border-red-600 focus:ring-2 focus:ring-red-500/20 bg-white cursor-pointer"
                                    >
                                    <div id="quick-contacto-tipo-dropdown" class="hidden absolute left-0 right-0 z-40 mt-1 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg">
                                        <div id="quick-contacto-tipo-options" style="max-height: 150px; overflow-y: auto;"></div>
                                    </div>
                                </div>
                                <select id="quick-contacto-tipo" required aria-hidden="true" tabindex="-1" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;opacity:0;"></select>
                            </div>
                            <div class="mb-4">
                                <label class="mb-4 block text-sm font-medium text-slate-700">Nombre y apellido * <span class="text-xs font-normal text-slate-500">mínimo 5 caracteres</span></label>
                                <input type="text" id="quick-contacto-nombre" maxlength="100" minlength="5" pattern="^[A-Za-z0-9ÁÉÍÓÚáéíóúÑñ\s\.,\-&]{5,100}$" title="Nombre y apellido debe tener al menos 5 caracteres." required class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 ease-in-out focus:border-red-600 focus:ring-2 focus:ring-red-500/20">
                            </div>
                            <div class="mb-4">
                                <label class="mb-4 block text-sm font-medium text-slate-700">Cargo <span class="text-xs font-normal text-slate-500">mínimo 2 caracteres</span></label>
                                <input type="text" id="quick-contacto-cargo" maxlength="50" minlength="2" pattern="^[A-Za-z0-9ÁÉÍÓÚáéíóúÑñ\s\.,\-&]{2,50}$" title="Cargo debe tener al menos 2 caracteres si se ingresa." class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 ease-in-out focus:border-red-600 focus:ring-2 focus:ring-red-500/20">
                            </div>
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 mb-4">
                                <div class="mb-0">
                                    <label class="mb-4 block text-sm font-medium text-slate-700">Correo * <span class="text-xs font-normal text-slate-500">formato email</span></label>
                                    <input type="email" id="quick-contacto-correo" maxlength="100" required title="Correo debe ser una dirección válida con @." class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 ease-in-out focus:border-red-600 focus:ring-2 focus:ring-red-500/20">
                                </div>
                                <div class="mb-0">
                                    <label class="mb-4 block text-sm font-medium text-slate-700">Correo alterno <span class="text-xs font-normal text-slate-500">formato email</span></label>
                                    <input type="email" id="quick-contacto-correo2" maxlength="100" class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 ease-in-out focus:border-red-600 focus:ring-2 focus:ring-red-500/20">
                                </div>
                            </div>
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 mb-4">
                                <div class="mb-0">
                                    <label class="mb-4 block text-sm font-medium text-slate-700">Numero * <span class="text-xs font-normal text-slate-500">9 dígitos</span></label>
                                    <input type="tel" id="quick-contacto-numero" maxlength="9" minlength="9" required title="Número debe tener exactamente 9 dígitos." inputmode="numeric" pattern="^[0-9]{9}$" title="Número debe tener exactamente 9 dígitos." class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 ease-in-out focus:border-red-600 focus:ring-2 focus:ring-red-500/20">
                                </div>
                                <div class="mb-0">
                                    <label class="mb-4 block text-sm font-medium text-slate-700">Numero alterno <span class="text-xs font-normal text-slate-500">9 dígitos</span></label>
                                    <input type="tel" id="quick-contacto-numero2" maxlength="9" minlength="9" inputmode="numeric" pattern="^[0-9]{9}$" title="Número alterno debe tener exactamente 9 dígitos." class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 ease-in-out focus:border-red-600 focus:ring-2 focus:ring-red-500/20">
                                </div>
                            </div>

                            <div class="mt-4 flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
                                <button type="button" id="quick-contacto-cancel" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-100" style=" border-color: #000000; color: #000000;">Cancelar</button>
                                <button type="submit" id="quick-contacto-submit" class="rounded-md border-0 px-4 py-2 text-xs font-semibold text-white shadow-sm transition duration-200" style="background-color: #c1121f; color: #ffffff;">Guardar contacto</button>
                            </div>
                            <p id="quick-contacto-feedback" class="text-xs"></p>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <script>
            (function () {
                const modal = document.getElementById('quick-contacto-modal');
                const closeBtn = document.getElementById('quick-contacto-close');
                const cancelBtn = document.getElementById('quick-contacto-cancel');
                const listContainer = document.getElementById('quick-contacto-list');
                const form = document.getElementById('quick-contacto-form');
                const tipoSelect = document.getElementById('quick-contacto-tipo');
                const tipoDisplay = document.getElementById('quick-contacto-tipo-display');
                const tipoDropdown = document.getElementById('quick-contacto-tipo-dropdown');
                const tipoOptionsContainer = document.getElementById('quick-contacto-tipo-options');
                const nombreInput = document.getElementById('quick-contacto-nombre');
                const cargoInput = document.getElementById('quick-contacto-cargo');
                const correoInput = document.getElementById('quick-contacto-correo');
                const correo2Input = document.getElementById('quick-contacto-correo2');
                const numeroInput = document.getElementById('quick-contacto-numero');
                const numero2Input = document.getElementById('quick-contacto-numero2');
                const searchInput = document.getElementById('quick-contacto-search');
                const countBadge = document.getElementById('quick-contacto-count');
                const submitBtn = document.getElementById('quick-contacto-submit');
                const feedback = document.getElementById('quick-contacto-feedback');
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
                const quickButtons = document.querySelectorAll('[data-quick-contact-button]');
                let tipoDropdownOpen = false;

                const confirmContacto = ({ title = 'Confirmar', message = '', submitText = 'Eliminar', cancelText = 'Cancelar' } = {}) => {
                    const id = 'quick-contacto-confirm-modal';
                    let modal = document.getElementById(id);
                    if (!modal) {
                        modal = document.createElement('div');
                        modal.id = id;
                        modal.style = 'display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:10000;background:rgba(0,0,0,0.6);align-items:center;justify-content:center;';
                        modal.innerHTML = `
                            <div style="width:640px;max-width:92%;margin:0 auto;position:relative;border-radius:12px;background:#fff;padding:24px;box-shadow:0 12px 28px rgba(2,6,23,0.12);">
                                <h3 style="margin:0 0 8px;font-size:18px;font-weight:600;color:#111827;">${title}</h3>
                                <p style="margin:0 0 14px;color:#6b7280;font-size:14px;">${message}</p>
                                <div style="display:flex;gap:12px;justify-content:flex-end;">
                                    <button type="button" class="qcont-cancel" style="min-width:120px;padding:10px 18px;border-radius:8px;border:1px solid #e6e9ee;background:#fff;color:#374151;font-weight:600;">${cancelText}</button>
                                    <button type="button" class="qcont-submit" style="min-width:120px;padding:10px 18px;border-radius:8px;background:#ef4444;color:#fff;font-weight:600;border:none;">${submitText}</button>
                                </div>
                            </div>`;
                        document.body.appendChild(modal);
                    }

                    const submitBtn = modal.querySelector('.qcont-submit');
                    const cancelBtn = modal.querySelector('.qcont-cancel');
                    modal.style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                    return new Promise((resolve) => {
                        const cleanup = () => {
                            modal.style.display = 'none';
                            document.body.style.overflow = '';
                            submitBtn.removeEventListener('click', onConfirm);
                            cancelBtn.removeEventListener('click', onCancel);
                        };
                        const onConfirm = () => { cleanup(); resolve(true); };
                        const onCancel = () => { cleanup(); resolve(false); };
                        cancelBtn.addEventListener('click', onCancel);
                        submitBtn.addEventListener('click', onConfirm);
                    });
                };

                if (!modal || quickButtons.length === 0) {
                    return;
                }

                if (modal.parentElement !== document.body) {
                    document.body.appendChild(modal);
                }

                const closeTipoDropdown = () => {
                    if (tipoDropdown) {
                        tipoDropdown.classList.add('hidden');
                    }
                    tipoDropdownOpen = false;
                };

                const openTipoDropdown = () => {
                    if (tipoDropdown) {
                        tipoDropdown.classList.remove('hidden');
                    }
                    tipoDropdownOpen = true;
                };

                const renderTipoDropdown = () => {
                    if (!tipoOptionsContainer) {
                        return;
                    }
                    tipoOptionsContainer.innerHTML = '';
                    currentTipos.forEach((item) => {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-100 focus:bg-slate-100 focus:outline-none';
                        button.textContent = String(item.label ?? '');
                        button.dataset.value = String(item.id ?? '');
                        button.addEventListener('click', () => {
                            if (!tipoSelect) {
                                return;
                            }
                            tipoSelect.value = button.dataset.value;
                            if (tipoDisplay) {
                                tipoDisplay.value = button.textContent;
                            }
                            closeTipoDropdown();
                        });
                        tipoOptionsContainer.appendChild(button);
                    });
                };

                if (tipoDisplay) {
                    tipoDisplay.addEventListener('click', () => {
                        if (tipoDropdownOpen) {
                            closeTipoDropdown();
                        } else {
                            renderTipoDropdown();
                            openTipoDropdown();
                        }
                    });
                }

                document.addEventListener('click', (event) => {
                    if (!tipoDropdown || !tipoDisplay) {
                        return;
                    }
                    if (tipoDisplay.contains(event.target) || tipoDropdown.contains(event.target)) {
                        return;
                    }
                    closeTipoDropdown();
                });

                let currentSelectId = null;
                let currentMode = 'create';
                let currentListUrl = null;
                let currentStoreUrl = null;
                let currentUpdateUrlTemplate = null;
                let currentDeleteUrlTemplate = null;
                let currentPayloadInputName = 'contactos_payload';
                let currentExportPdfUrl = '';
                let currentExportXlsxUrl = '';
                let currentTipos = [];
                let listItems = [];
                let selectedContactoId = null;
                let editingContactoId = null;
                const exportPdfBtn = document.getElementById('quick-contacto-export-pdf');
                const exportXlsxBtn = document.getElementById('quick-contacto-export-xlsx');

                const closeModal = () => {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                    document.body.style.overflow = '';
                    editingContactoId = null;
                    if (submitBtn) {
                        submitBtn.textContent = 'Guardar contacto';
                    }
                    feedback.textContent = '';
                    feedback.className = 'text-xs';
                };

                const updateExportButtons = () => {
                    if (exportPdfBtn) {
                        if (currentExportPdfUrl) {
                            exportPdfBtn.href = currentExportPdfUrl;
                            exportPdfBtn.classList.remove('hidden');
                        } else {
                            exportPdfBtn.classList.add('hidden');
                        }
                    }
                    if (exportXlsxBtn) {
                        if (currentExportXlsxUrl) {
                            exportXlsxBtn.href = currentExportXlsxUrl;
                            exportXlsxBtn.classList.remove('hidden');
                        } else {
                            exportXlsxBtn.classList.add('hidden');
                        }
                    }
                };

                const clearCreateForm = () => {
                    tipoSelect.value = '';
                    if (tipoDisplay) {
                        tipoDisplay.value = '';
                    }
                    nombreInput.value = '';
                    cargoInput.value = '';
                    correoInput.value = '';
                    correo2Input.value = '';
                    numeroInput.value = '';
                    numero2Input.value = '';
                };

                const getPayloadInput = () => {
                    const mainForm = document.getElementById('main-crud-form');
                    if (!mainForm) {
                        return null;
                    }

                    let input = mainForm.querySelector('input[name="' + currentPayloadInputName + '"]');
                    if (!input) {
                        input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = currentPayloadInputName;
                        input.value = '[]';
                        mainForm.appendChild(input);
                    }

                    return input;
                };

                const readLocalContacts = () => {
                    const input = getPayloadInput();
                    if (!input) {
                        return [];
                    }

                    try {
                        const parsed = JSON.parse(String(input.value || '[]'));
                        return Array.isArray(parsed) ? parsed : [];
                    } catch (error) {
                        return [];
                    }
                };

                const writeLocalContacts = (contacts) => {
                    const input = getPayloadInput();
                    if (!input) {
                        return;
                    }
                    input.value = JSON.stringify(Array.isArray(contacts) ? contacts : []);
                };

                const tipoLabel = (tipoId) => {
                    const found = currentTipos.find((item) => String(item.id) === String(tipoId));
                    return found ? String(found.label || '') : '';
                };

                const buildLocalLabel = (item) => {
                    const nombre = String(item.nombreApellido || '').trim();
                    const tipo = tipoLabel(item.tipoContacto_idtipoContacto);
                    const numero = String(item.numero || '').trim();

                    let label = nombre !== '' ? nombre : 'Contacto temporal';
                    if (tipo !== '') {
                        label += ' (' + tipo + ')';
                    }
                    if (numero !== '') {
                        label += ' - ' + numero;
                    }

                    return label;
                };

                const applySelectOption = (item) => {
                    const target = document.getElementById(currentSelectId);
                    if (!target || !item) {
                        return;
                    }

                    const value = String(item.id);
                    const label = String(item.label || '');

                    const inst = target.tomselect || target.tomSelect || target._tomselect || null;
                    const existingOption = target.querySelector('option[value="' + value + '"]');

                    if (inst && typeof inst.addOption === 'function') {
                        try {
                            if (existingOption) {
                                if (typeof inst.updateOption === 'function') {
                                    inst.updateOption(value, { value: value, text: label });
                                } else {
                                    try { inst.removeOption(value); } catch (e) {}
                                    inst.addOption({ value: value, text: label }, true);
                                }
                            } else {
                                inst.addOption({ value: value, text: label }, true);
                            }

                            if (typeof inst.addItem === 'function') {
                                inst.addItem(value);
                            } else if (typeof inst.setValue === 'function') {
                                inst.setValue(value);
                            }
                        } catch (e) {
                            let option = existingOption;
                            if (!option) {
                                option = document.createElement('option');
                                option.value = value;
                                target.appendChild(option);
                            }
                            option.textContent = label;
                            target.value = value;
                        }
                    } else {
                        let option = existingOption;
                        if (!option) {
                            option = document.createElement('option');
                            option.value = value;
                            target.appendChild(option);
                        }
                        option.textContent = label;
                        target.value = value;
                    }

                    selectedContactoId = value;
                    target.dispatchEvent(new Event('change', { bubbles: true }));
                };

                const replaceIdToken = (template, id) => String(template || '').replace('__ID__', encodeURIComponent(String(id)));

                const fillContactoForm = (item) => {
                    tipoSelect.value = String(item.tipoContacto_idtipoContacto || '');
                    if (tipoDisplay) {
                        tipoDisplay.value = tipoLabel(item.tipoContacto_idtipoContacto || '');
                    }
                    nombreInput.value = String(item.nombreApellido || '');
                    cargoInput.value = String(item.cargo || '');
                    correoInput.value = String(item.correo || '');
                    correo2Input.value = String(item.correo2 || '');
                    numeroInput.value = String(item.numero || '');
                    numero2Input.value = String(item.numero2 || '');
                };

                const beginEditContacto = (item) => {
                    editingContactoId = String(item.id);
                    fillContactoForm(item);
                    if (submitBtn) {
                        submitBtn.textContent = 'Actualizar contacto';
                    }
                    feedback.textContent = 'Editando contacto #' + String(item.id);
                    feedback.className = 'text-xs text-slate-600';
                };

                const deleteContactoLocal = (id) => {
                    const localContacts = readLocalContacts();
                    const nextContacts = localContacts.filter((item) => String(item.tempId || item.id) !== String(id));
                    writeLocalContacts(nextContacts);

                    const target = document.getElementById(currentSelectId);
                    if (target) {
                        const value = String(id);
                        const inst = target.tomselect || target.tomSelect || target._tomselect || null;
                        try {
                            if (inst && typeof inst.removeItem === 'function') inst.removeItem(value);
                            if (inst && typeof inst.removeOption === 'function') inst.removeOption(value);
                            else {
                                const option = target.querySelector('option[value="' + value + '"]');
                                if (option) option.remove();
                            }
                        } catch (e) {
                            const option = target.querySelector('option[value="' + value + '"]');
                            if (option) option.remove();
                        }

                        if (String(target.value) === value) {
                            target.value = '';
                            selectedContactoId = null;
                            target.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }
                };

                const deleteContactoPersisted = async (id) => {
                    if (!currentDeleteUrlTemplate) {
                        throw new Error('No se definio la ruta para eliminar contacto.');
                    }

                    const response = await fetch(replaceIdToken(currentDeleteUrlTemplate, id), {
                        method: 'DELETE',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                    });

                    const payload = await response.json();
                    if (!response.ok || !payload.ok) {
                        throw new Error(payload.message || 'No se pudo eliminar el contacto.');
                    }

                    const target = document.getElementById(currentSelectId);
                    if (target) {
                        const value = String(id);
                        const inst = target.tomselect || target.tomSelect || target._tomselect || null;
                        try {
                            if (inst && typeof inst.removeItem === 'function') inst.removeItem(value);
                            if (inst && typeof inst.removeOption === 'function') inst.removeOption(value);
                            else {
                                const option = target.querySelector('option[value="' + value + '"]');
                                if (option) option.remove();
                            }
                        } catch (e) {
                            const option = target.querySelector('option[value="' + value + '"]');
                            if (option) option.remove();
                        }

                        if (String(target.value) === value) {
                            target.value = '';
                            selectedContactoId = null;
                            target.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }
                };

                const renderTipos = () => {
                    tipoSelect.innerHTML = '<option value="">Selecciona tipo</option>';
                    currentTipos.forEach((item) => {
                        const option = document.createElement('option');
                        option.value = String(item.id ?? '');
                        option.textContent = String(item.label ?? '');
                        tipoSelect.appendChild(option);
                    });

                    if (tipoDropdownOpen) {
                        renderTipoDropdown();
                    }
                };

                const updateCount = (count) => {
                    if (countBadge) {
                        countBadge.textContent = String(count);
                    }
                };

                const renderList = (items) => {
                    if (!Array.isArray(items) || items.length === 0) {
                        updateCount(0);
                        listContainer.innerHTML = '<p class="text-xs text-slate-500">No hay contactos registrados.</p>';
                        return;
                    }

                    updateCount(items.length);
                    listContainer.innerHTML = '';

                    items.forEach((item) => {
                        const id = String(item.id);
                        const isSelected = String(selectedContactoId) === id;

                        const row = document.createElement('div');
                        row.className = 'flex items-center justify-between mb-2 gap-3 rounded-md border p-2 transition ' +
                            (isSelected ? 'border-primary bg-red-50/50' : 'border-slate-200 bg-white hover:border-slate-300');

                        const text = document.createElement('div');
                        text.className = 'pr-2 text-xs text-slate-700 leading-5';
                        text.textContent = String(item.label || '');

                        const actions = document.createElement('div');
                        actions.className = 'flex items-center gap-1.5';

                        const editBtn = document.createElement('button');
                        editBtn.type = 'button';
                        editBtn.className = 'rounded border border-slate-300 bg-white px-2 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-50';
                        editBtn.textContent = 'Editar';
                        editBtn.addEventListener('click', () => beginEditContacto(item));

                        const deleteBtn = document.createElement('button');
                        deleteBtn.type = 'button';
                        deleteBtn.className = 'rounded border border-red-200 bg-red-50 px-2 py-1 text-[11px] font-medium text-red-700 hover:bg-red-100';
                        deleteBtn.textContent = 'Eliminar';
                        deleteBtn.addEventListener('click', async () => {
                            const ok = await confirmContacto({ title: 'Confirmar eliminación', message: '¿Está seguro de eliminar el contacto seleccionado?', submitText: 'Eliminar', cancelText: 'Cancelar' });
                            if (!ok) return;

                            feedback.textContent = 'Eliminando contacto...';
                            feedback.className = 'text-xs text-slate-600';

                            try {
                                if (currentMode === 'create') {
                                    deleteContactoLocal(item.id);
                                } else {
                                    await deleteContactoPersisted(item.id);
                                }

                                if (editingContactoId === String(item.id)) {
                                    editingContactoId = null;
                                    clearCreateForm();
                                    tipoSelect.value = '';
                                    if (submitBtn) {
                                        submitBtn.textContent = 'Guardar contacto';
                                    }
                                }

                                await loadContacts();
                                feedback.textContent = 'Contacto eliminado correctamente.';
                                feedback.className = 'text-xs text-emerald-700';
                            } catch (error) {
                                feedback.textContent = error.message || 'No se pudo eliminar el contacto.';
                                feedback.className = 'text-xs text-red-600';
                            }
                        });

                        const pickBtn = document.createElement('button');
                        pickBtn.type = 'button';
                        pickBtn.className = 'min-w-[102px] rounded border px-2.5 py-1.5 text-[11px] font-medium shadow-sm ' +
                            (isSelected
                                ? 'border-primary bg-primary text-white'
                                : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50');
                        pickBtn.textContent = isSelected ? 'Seleccionada' : 'Seleccionar';
                        pickBtn.addEventListener('click', () => {
                            applySelectOption(item);
                            closeModal();
                        });
                        
                        actions.appendChild(editBtn);
                        actions.appendChild(deleteBtn);
                        actions.appendChild(pickBtn);

                        row.appendChild(text);
                        row.appendChild(actions);
                        listContainer.appendChild(row);
                    });
                };

                const loadContacts = async () => {
                    if (currentMode === 'create') {
                        const localContacts = readLocalContacts();
                        listItems = localContacts.map((item, index) => {
                            const id = String(item.tempId || item.id || ('tmp-' + index));
                            return {
                                id: id,
                                label: buildLocalLabel(item),
                                tipoContacto_idtipoContacto: Number(item.tipoContacto_idtipoContacto || 0),
                                nombreApellido: String(item.nombreApellido || ''),
                                cargo: String(item.cargo || ''),
                                correo: String(item.correo || ''),
                                correo2: String(item.correo2 || ''),
                                numero: String(item.numero || ''),
                                numero2: String(item.numero2 || ''),
                            };
                        });
                        renderList(listItems);
                        return;
                    }

                    listContainer.innerHTML = '<p class="text-xs text-slate-500">Cargando...</p>';
                    try {
                        const response = await fetch(currentListUrl, {
                            headers: {
                                Accept: 'application/json',
                            },
                        });

                        const payload = await response.json();
                        listItems = Array.isArray(payload.data) ? payload.data : [];
                        renderList(listItems);
                    } catch (error) {
                        updateCount(0);
                        listContainer.innerHTML = '<p class="text-xs text-red-600">No se pudo cargar los contactos.</p>';
                    }
                };

                const openModal = (button) => {
                    currentSelectId = button.getAttribute('data-quick-contact-target');
                    currentMode = button.getAttribute('data-quick-contact-mode') || 'create';
                    currentListUrl = button.getAttribute('data-quick-contact-list-url');
                    currentStoreUrl = button.getAttribute('data-quick-contact-store-url');
                    currentUpdateUrlTemplate = button.getAttribute('data-quick-contact-update-url-template');
                    currentDeleteUrlTemplate = button.getAttribute('data-quick-contact-delete-url-template');
                    currentExportPdfUrl = button.getAttribute('data-quick-export-pdf-url') || '';
                    currentExportXlsxUrl = button.getAttribute('data-quick-export-xlsx-url') || '';
                    currentPayloadInputName = button.getAttribute('data-quick-contact-payload-input') || 'contactos_payload';

                    try {
                        currentTipos = JSON.parse(button.getAttribute('data-quick-contact-tipos') || '[]');
                    } catch (error) {
                        currentTipos = [];
                    }

                    const target = document.getElementById(currentSelectId);
                    selectedContactoId = target ? String(target.value || '') : null;
                    editingContactoId = null;
                    updateExportButtons();
                    renderTipos();
                    tipoSelect.value = '';
                    clearCreateForm();
                    if (searchInput) {
                        searchInput.value = '';
                    }
                    if (submitBtn) {
                        submitBtn.textContent = 'Guardar contacto';
                    }
                    feedback.textContent = '';
                    feedback.className = 'text-xs';

                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                    document.body.style.overflow = 'hidden';
                    loadContacts();
                };

                quickButtons.forEach((button) => {
                    button.addEventListener('click', () => {
                        if (button.disabled) {
                            return;
                        }
                        openModal(button);
                    });
                });

                closeBtn?.addEventListener('click', closeModal);
                cancelBtn?.addEventListener('click', closeModal);

                // El cierre es solo por botones Cerrar o Cancelar.

                searchInput?.addEventListener('input', () => {
                    const term = String(searchInput.value || '').trim().toLowerCase();
                    if (term === '') {
                        renderList(listItems);
                        return;
                    }

                    const filtered = listItems.filter((item) => {
                        const label = String(item.label || '').toLowerCase();
                        const descripcion = String(item.descripcion || '').toLowerCase();
                        return label.includes(term) || descripcion.includes(term);
                    });
                    renderList(filtered);
                });

                form?.addEventListener('submit', async (event) => {
                    event.preventDefault();

                    const tipoContactoId = String(tipoSelect.value || '').trim();
                    const nombreApellido = String(nombreInput.value || '').trim();
                    const cargo = String(cargoInput.value || '').trim();
                    const correo = String(correoInput.value || '').trim();
                    const correo2 = String(correo2Input.value || '').trim();
                    const numero = String(numeroInput.value || '').trim();
                    const numero2 = String(numero2Input.value || '').trim();

                    if (!tipoContactoId) {
                        feedback.textContent = 'El tipo de contacto es obligatorio.';
                        feedback.className = 'text-xs text-red-600';
                        return;
                    }

                    if (nombreApellido.length < 5) {
                        feedback.textContent = 'El nombre y apellido debe tener al menos 5 caracteres.';
                        feedback.className = 'text-xs text-red-600';
                        return;
                    }

                    if (!correo) {
                        feedback.textContent = 'El correo es obligatorio.';
                        feedback.className = 'text-xs text-red-600';
                        return;
                    }

                    if (!/^[0-9]{9}$/.test(numero)) {
                        feedback.textContent = 'El número debe tener exactamente 9 dígitos.';
                        feedback.className = 'text-xs text-red-600';
                        return;
                    }

                    feedback.textContent = editingContactoId ? 'Actualizando contacto...' : 'Guardando contacto...';
                    feedback.className = 'text-xs text-slate-600';

                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.classList.add('opacity-70', 'cursor-not-allowed');
                    }

                    const contactPayload = {
                        tipoContacto_idtipoContacto: tipoContactoId,
                        nombreApellido: nombreApellido,
                        cargo: cargo || null,
                        correo: correo,
                        correo2: correo2 || null,
                        numero: numero,
                        numero2: numero2 || null,
                    };

                    try {
                        if (currentMode === 'create' && !currentStoreUrl) {
                            const localContacts = readLocalContacts();
                            if (editingContactoId) {
                                const nextContacts = localContacts.map((item) => {
                                    if (String(item.tempId || item.id) !== editingContactoId) {
                                        return item;
                                    }
                                    return { ...item, ...contactPayload };
                                });
                                writeLocalContacts(nextContacts);
                                const updatedItem = nextContacts.find((item) => String(item.tempId || item.id) === editingContactoId);
                                if (updatedItem) {
                                    applySelectOption({
                                        id: updatedItem.tempId ?? updatedItem.id,
                                        label: buildLocalLabel(updatedItem),
                                    });
                                }
                            } else {
                                const tempId = 'tmp-' + Date.now();
                                const newContact = { tempId, ...contactPayload };
                                localContacts.push(newContact);
                                writeLocalContacts(localContacts);
                                applySelectOption({
                                    id: tempId,
                                    label: buildLocalLabel(newContact),
                                });
                            }

                            editingContactoId = null;
                            clearCreateForm();
                            tipoSelect.value = '';
                            if (submitBtn) {
                                submitBtn.textContent = 'Guardar contacto';
                            }
                            await loadContacts();
                            closeModal();
                            return;
                        }

                        const isEditing = !!editingContactoId;
                        const url = isEditing
                            ? replaceIdToken(currentUpdateUrlTemplate, editingContactoId)
                            : currentStoreUrl;

                        if (!url) {
                            throw new Error('No se definió la ruta para guardar el contacto.');
                        }

                        const response = await fetch(url, {
                            method: isEditing ? 'PUT' : 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            body: JSON.stringify(contactPayload),
                        });

                        const payload = await response.json();
                        if (!response.ok || !payload.ok) {
                            throw new Error(payload.message || (isEditing ? 'No se pudo actualizar el contacto.' : 'No se pudo crear el contacto.'));
                        }

                        if (payload.data) {
                            applySelectOption(payload.data);
                        }

                        editingContactoId = null;
                        clearCreateForm();
                        tipoSelect.value = '';
                        if (submitBtn) {
                            submitBtn.textContent = 'Guardar contacto';
                        }

                        await loadContacts();
                        closeModal();
                    } catch (error) {
                        feedback.textContent = error.message || 'Ocurrió un error al guardar el contacto.';
                        feedback.className = 'text-xs text-red-600';
                    } finally {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.classList.remove('opacity-70', 'cursor-not-allowed');
                        }
                    }
                });
            })();
        </script>
    @endif

    @if($hasQuickCredencial)
        <div id="quick-credencial-modal" class="fixed inset-0 hidden items-center justify-center px-4 backdrop-blur-sm" style="z-index: 9999; background-color: rgba(0, 0, 0, 0.78);">
            <div class="w-full rounded-lg bg-white shadow-2xl ring-1 ring-slate-900/10 border-t-4 border-red-600 overflow-hidden" style="max-width: 980px;">
                <div class="flex items-start justify-between border-b border-slate-200 bg-gradient-to-r from-slate-50 to-white px-6 py-5">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900">Seleccionar o crear credencial</h3>
                        <p class="mt-2 text-sm text-slate-600">Elige una credencial existente o créala rápidamente sin salir del formulario.</p>
                    </div>
                    <button type="button" id="quick-credencial-close" class="ml-auto rounded-lg border-0 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-100 hover:text-red-600 transition duration-200 flex-shrink-0">
                        <i data-lucide="x" class="h-5 w-5"></i>
                    </button>
                </div>
                <div class="grid gap-5 bg-white p-7 md:grid-cols-2">
                    <div>
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <h4 class="text-sm font-bold text-slate-900">Credenciales</h4>
                                <span id="quick-credencial-count" class="rounded-full bg-red-50 px-3 py-1 text-xs font-semibold text-red-600">0</span>
                            </div>
                            <div class="flex items-center gap-2 text-xs text-slate-500 ml-auto">
                                <span>Exportar:</span>
                                <a id="quick-credencial-export-pdf" href="#" target="_blank" class="hidden inline-flex items-center rounded-md border border-slate-200 bg-slate-100 px-3 py-2 font-semibold text-slate-700 hover:bg-slate-200 transition">
                                    <i data-lucide="file-text" class="stroke-[1] mr-2 h-4 w-4"></i>
                                    PDF
                                </a>
                                <a id="quick-credencial-export-xlsx" href="#" target="_blank" class="hidden inline-flex items-center rounded-md border border-slate-200 bg-slate-100 px-3 py-2 font-semibold text-slate-700 hover:bg-slate-200 transition">
                                    <i data-lucide="file-text" class="stroke-[1] mr-2 h-4 w-4"></i>
                                    XLSX
                                </a>
                            </div>
                        </div>
                        <div class="mb-3">
                            <input type="text" id="quick-credencial-search" placeholder="Buscar credencial..." class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm focus:border-red-600 focus:ring-2 focus:ring-red-500/20 transition duration-200">
                        </div>
                        <div id="quick-credencial-list" class="space-y-2 overflow-y-auto rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700" style="max-height: 600px; overflow-y: auto;"></div>
                    </div>

                    <div>
                        <h4 class="mb-2 text-sm font-semibold text-slate-800">Crear credencial rápida</h4>
                        <form id="quick-credencial-form" class="space-y-6 rounded-lg border border-slate-200 bg-slate-50/40 p-4">
                            <div class="mb-4">
                                <label class="mb-4 block text-sm font-medium text-slate-700">Usuario * <span class="text-xs font-normal text-slate-500">mínimo 2 caracteres</span></label>
                                <input type="text" id="quick-credencial-usuario" maxlength="100" minlength="2" required class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 ease-in-out focus:border-red-600 focus:ring-2 focus:ring-red-500/20">
                            </div>
                            <div class="mb-4">
                                <label class="mb-2 block text-sm font-medium text-slate-700">
                                    Clave * <span class="text-xs font-normal text-slate-500">mínimo 8 caracteres</span>
                                </label>
                                <div class="relative">
                                    <input type="password" id="quick-credencial-clave" maxlength="100" minlength="8" required class="w-full rounded-lg border border-slate-300 px-4 py-2.5 pr-12 text-sm transition duration-200 ease-in-out focus:border-red-600 focus:ring-2 focus:ring-red-500/20">
                                    <button type="button" id="quick-credencial-toggle-password" class="absolute inset-y-0 right-0 flex items-center pr-4 text-slate-400 hover:text-slate-600" aria-label="Mostrar contraseña">
                                        <svg id="icon-eye" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="hidden">
                                            <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>
                                        </svg>
                                        <svg id="icon-eye-off" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.52 13.52 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            <div class="mb-4">
                                <label class="mb-4 block text-sm font-medium text-slate-700">Fecha de creación *</label>
                                <input type="text" id="quick-credencial-fechaCreacion" required class="datepicker w-full rounded-lg border border-slate-300 px-4 py-2.5 text-sm transition duration-200 ease-in-out focus:border-red-600 focus:ring-2 focus:ring-red-500/20">
                            </div>
                            <div class="mb-4">
                                <label class="mb-4 block text-sm font-medium text-slate-700">Estado de recepción</label>
                                <label class="custom-checkbox-wrapper">
                                    <input type="checkbox" id="quick-credencial-estadoRecepcion" class="h-4 w-4 rounded border-slate-300 text-red-600 focus:ring-red-500">
                                    <span class="text-sm text-slate-700">Estado de recepción</span>
                                </label>
                            </div>

                            <div class="mt-4 flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
                                <button type="button" id="quick-credencial-cancel" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-100" style=" border-color: #000000; color: #000000;">Cancelar</button>
                                <button type="submit" id="quick-credencial-submit" class="rounded-md border-0 px-4 py-2 text-xs font-semibold text-white shadow-sm transition duration-200" style="background-color: #c1121f; color: #ffffff;">Guardar credencial</button>
                            </div>
                            <p id="quick-credencial-feedback" class="text-xs"></p>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <script>
            (function () {
                const modal = document.getElementById('quick-credencial-modal');
                const closeBtn = document.getElementById('quick-credencial-close');
                const cancelBtn = document.getElementById('quick-credencial-cancel');
                const listContainer = document.getElementById('quick-credencial-list');
                const form = document.getElementById('quick-credencial-form');
                const usuarioInput = document.getElementById('quick-credencial-usuario');
                const claveInput = document.getElementById('quick-credencial-clave');
                const fechaInput = document.getElementById('quick-credencial-fechaCreacion');
                const claveToggleBtn = document.getElementById('quick-credencial-toggle-password');
                const estadoInput = document.getElementById('quick-credencial-estadoRecepcion');
                const searchInput = document.getElementById('quick-credencial-search');
                const countBadge = document.getElementById('quick-credencial-count');
                const submitBtn = document.getElementById('quick-credencial-submit');
                const feedback = document.getElementById('quick-credencial-feedback');
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
                const quickButtons = document.querySelectorAll('[data-quick-credential-button]');
                let currentSelectId = null;
                const confirmCredencial = ({ title = 'Confirmar', message = '', submitText = 'Eliminar', cancelText = 'Cancelar' } = {}) => {
                    const id = 'quick-credencial-confirm-modal';
                    let modal = document.getElementById(id);
                    if (!modal) {
                        modal = document.createElement('div');
                        modal.id = id;
                        modal.style = 'display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:10000;background:rgba(0,0,0,0.6);align-items:center;justify-content:center;';
                        modal.innerHTML = `
                            <div style="width:680px;max-width:92%;margin:0 auto;position:relative;border-radius:12px;background:#fff;padding:26px;box-shadow:0 12px 28px rgba(2,6,23,0.12);">
                                <h3 style="margin:0 0 10px;font-size:18px;font-weight:600;color:#111827;">${title}</h3>
                                <p style="margin:0 0 16px;color:#6b7280;font-size:14px;">${message}</p>
                                <div style="display:flex;gap:12px;justify-content:flex-end;">
                                    <button type="button" class="qcred-cancel" style="min-width:120px;padding:10px 18px;border-radius:8px;border:1px solid #e6e9ee;background:#fff;color:#374151;font-weight:600;">${cancelText}</button>
                                    <button type="button" class="qcred-submit" style="min-width:120px;padding:10px 18px;border-radius:8px;background:#ef4444;color:#fff;font-weight:600;border:none;">${submitText}</button>
                                </div>
                            </div>`;
                        document.body.appendChild(modal);
                    }

                    const submitBtn = modal.querySelector('.qcred-submit');
                    const cancelBtn = modal.querySelector('.qcred-cancel');
                    modal.style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                    return new Promise((resolve) => {
                        const cleanup = () => {
                            modal.style.display = 'none';
                            document.body.style.overflow = '';
                            submitBtn.removeEventListener('click', onConfirm);
                            cancelBtn.removeEventListener('click', onCancel);
                        };
                        const onConfirm = () => { cleanup(); resolve(true); };
                        const onCancel = () => { cleanup(); resolve(false); };
                        cancelBtn.addEventListener('click', onCancel);
                        submitBtn.addEventListener('click', onConfirm);
                    });
                };
                const confirmDispositivo = ({ title = 'Confirmar', message = '', submitText = 'Eliminar', cancelText = 'Cancelar' } = {}) => {
                    const id = 'quick-dispositivo-confirm-modal';
                    let modal = document.getElementById(id);
                    if (!modal) {
                        modal = document.createElement('div');
                        modal.id = id;
                        modal.style = 'display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:10000;background:rgba(0,0,0,0.6);align-items:center;justify-content:center;';
                        modal.innerHTML = `
                            <div style="width:680px;max-width:92%;margin:0 auto;position:relative;border-radius:12px;background:#fff;padding:26px;box-shadow:0 12px 28px rgba(2,6,23,0.12);">
                                <h3 style="margin:0 0 10px;font-size:18px;font-weight:600;color:#111827;">${title}</h3>
                                <p style="margin:0 0 16px;color:#6b7280;font-size:14px;">${message}</p>
                                <div style="display:flex;gap:12px;justify-content:flex-end;">
                                    <button type="button" class="qdisp-cancel" style="min-width:120px;padding:10px 18px;border-radius:8px;border:1px solid #e6e9ee;background:#fff;color:#374151;font-weight:600;">${cancelText}</button>
                                    <button type="button" class="qdisp-submit" style="min-width:120px;padding:10px 18px;border-radius:8px;background:#ef4444;color:#fff;font-weight:600;border:none;">${submitText}</button>
                                </div>
                            </div>`;
                        document.body.appendChild(modal);
                    }

                    const submitBtn = modal.querySelector('.qdisp-submit');
                    const cancelBtn = modal.querySelector('.qdisp-cancel');
                    modal.style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                    return new Promise((resolve) => {
                        const cleanup = () => {
                            modal.style.display = 'none';
                            document.body.style.overflow = '';
                            submitBtn.removeEventListener('click', onConfirm);
                            cancelBtn.removeEventListener('click', onCancel);
                        };
                        const onConfirm = () => { cleanup(); resolve(true); };
                        const onCancel = () => { cleanup(); resolve(false); };
                        cancelBtn.addEventListener('click', onCancel);
                        submitBtn.addEventListener('click', onConfirm);
                    });
                };
                let currentMode = 'create';
                let currentListUrl = null;
                let currentStoreUrl = null;
                let currentUpdateUrlTemplate = null;
                let currentDeleteUrlTemplate = null;
                let currentPayloadInputName = 'credenciales_payload';
                let currentExportPdfUrl = '';
                let currentExportXlsxUrl = '';
                let currentCredentialCanEdit = 'false';
                let currentCredentialCanDelete = 'false';
                let listItems = [];
                let selectedCredencialId = null;
                let editingCredencialId = null;
                const exportPdfBtn = document.getElementById('quick-credencial-export-pdf');
                const exportXlsxBtn = document.getElementById('quick-credencial-export-xlsx');

                const updatePasswordToggleIcon = (visible) => {
                    if (!claveToggleBtn) {
                        return;
                    }

                    // Si existen los SVG internos con ids, alternamos su visibilidad
                    const iconEye = claveToggleBtn.querySelector('#icon-eye');
                    const iconEyeOff = claveToggleBtn.querySelector('#icon-eye-off');
                    if (iconEye && iconEyeOff) {
                        // visible === true => contraseña visible -> mostrar eye-off
                        iconEye.classList.toggle('hidden', !visible);
                        iconEyeOff.classList.toggle('hidden', visible);
                        return;
                    }

                    // Fallback: reemplazar innerHTML como antes
                    claveToggleBtn.innerHTML = visible
                        ? '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10 10 0 0 1 6.06 6.06"/><path d="M1 1l22 22"/></svg>'
                        : '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>';
                };

                const togglePasswordVisibility = () => {
                    if (!claveInput) {
                        return;
                    }
                    const isPassword = claveInput.type === 'password';
                    claveInput.type = isPassword ? 'text' : 'password';
                    updatePasswordToggleIcon(!isPassword);
                };

                if (!modal || quickButtons.length === 0) {
                    return;
                }

                if (modal.parentElement !== document.body) {
                    document.body.appendChild(modal);
                }

                const closeModal = () => {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                    document.body.style.overflow = '';
                    editingCredencialId = null;
                    if (submitBtn) {
                        submitBtn.textContent = 'Guardar credencial';
                    }
                    feedback.textContent = '';
                    feedback.className = 'text-xs';
                };

                const replaceIdToken = (template, id) => String(template || '').replace('__ID__', encodeURIComponent(String(id)));

                const updateExportButtons = () => {
                    if (exportPdfBtn) {
                        if (currentExportPdfUrl) {
                            exportPdfBtn.href = currentExportPdfUrl;
                            exportPdfBtn.classList.remove('hidden');
                        } else {
                            exportPdfBtn.classList.add('hidden');
                        }
                    }
                    if (exportXlsxBtn) {
                        if (currentExportXlsxUrl) {
                            exportXlsxBtn.href = currentExportXlsxUrl;
                            exportXlsxBtn.classList.remove('hidden');
                        } else {
                            exportXlsxBtn.classList.add('hidden');
                        }
                    }
                };

                const getPayloadInput = () => {
                    const mainForm = document.getElementById('main-crud-form');
                    if (!mainForm) {
                        return null;
                    }

                    let input = mainForm.querySelector('input[name="' + currentPayloadInputName + '"]');
                    if (!input) {
                        input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = currentPayloadInputName;
                        input.value = '[]';
                        mainForm.appendChild(input);
                    }

                    return input;
                };

                const readLocalCredenciales = () => {
                    const input = getPayloadInput();
                    if (!input) {
                        return [];
                    }

                    try {
                        const parsed = JSON.parse(String(input.value || '[]'));
                        return Array.isArray(parsed) ? parsed : [];
                    } catch (error) {
                        return [];
                    }
                };

                const writeLocalCredenciales = (credenciales) => {
                    const input = getPayloadInput();
                    if (!input) {
                        return;
                    }
                    input.value = JSON.stringify(Array.isArray(credenciales) ? credenciales : []);
                };

                const toDateValue = (value) => {
                    if (!value) {
                        return '';
                    }

                    const normalized = String(value).trim();
                    const match = normalized.match(/^(\d{4}-\d{2}-\d{2})/);
                    return match ? match[1] : normalized;
                };

                const parseLocalDate = (value) => {
                    const dateValue = toDateValue(value);
                    if (!dateValue) {
                        return null;
                    }

                    const dateParts = dateValue.match(/^(\d{4})-(\d{2})-(\d{2})$/);
                    if (dateParts) {
                        const year = Number(dateParts[1]);
                        const month = Number(dateParts[2]) - 1;
                        const day = Number(dateParts[3]);
                        return new Date(year, month, day);
                    }

                    const parsed = new Date(dateValue);
                    return Number.isNaN(parsed.valueOf()) ? null : parsed;
                };

                const formatFechaDisplay = (value) => {
                    const parsed = parseLocalDate(value);
                    if (!parsed) {
                        return '';
                    }

                    const monthNames = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
                    return `${String(parsed.getDate()).padStart(2, '0')} ${monthNames[parsed.getMonth()]}, ${parsed.getFullYear()}`;
                };

                const parseFechaInput = (value) => {
                    const raw = String(value || '').trim();
                    if (!raw) {
                        return '';
                    }

                    const isoMatch = raw.match(/^(\d{4}-\d{2}-\d{2})/);
                    if (isoMatch) {
                        return isoMatch[1];
                    }

                    const formattedMatch = raw.match(/^(\d{1,2})\s+([A-Za-zÀ-ÿ]+),?\s*(\d{4})$/);
                    if (formattedMatch) {
                        const day = Number(formattedMatch[1]);
                        const monthName = formattedMatch[2].toLowerCase();
                        const year = formattedMatch[3];
                        const months = {
                            ene: '01', feb: '02', mar: '03', abr: '04', may: '05', jun: '06', jul: '07', ago: '08', sep: '09', oct: '10', nov: '11', dic: '12',
                        };
                        const month = months[monthName] ?? null;
                        if (month) {
                            return `${year}-${month}-${String(day).padStart(2, '0')}`;
                        }
                    }

                    const parsed = new Date(raw);
                    if (!Number.isNaN(parsed.valueOf())) {
                        return `${parsed.getFullYear()}-${String(parsed.getMonth() + 1).padStart(2, '0')}-${String(parsed.getDate()).padStart(2, '0')}`;
                    }

                    return raw;
                };

                const getTodayLocalIso = () => {
                    const today = new Date();
                    return `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
                };

                const buildLocalLabel = (item) => {
                    const usuario = String(item.usuario || '').trim();
                    const rawFecha = String(item.fechaCreacion || '').trim();
                    const fecha = toDateValue(rawFecha);
                    const estado = String(item.estadoRecepcion || '0') === '1' ? 'Sí' : 'No';

                    let label = usuario !== '' ? usuario : 'Credencial temporal';
                    label += ' - ' + estado;
                    if (fecha !== '') {
                        const parsed = parseLocalDate(fecha);
                        if (parsed) {
                            const monthNames = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
                            const formattedFecha = `${String(parsed.getDate()).padStart(2, '0')} ${monthNames[parsed.getMonth()]}, ${parsed.getFullYear()}`;
                            label += ' - ' + formattedFecha;
                        } else {
                            label += ' - ' + fecha;
                        }
                    }

                    return label;
                };

                const applySelectOption = (item) => {
                    const target = document.getElementById(currentSelectId);
                    if (!target || !item) {
                        return;
                    }

                    const value = String(item.id);
                    const label = String(item.label || '');

                    const inst = target.tomselect || target.tomSelect || target._tomselect || null;
                    const existingOption = target.querySelector('option[value="' + value + '"]');

                    if (inst && typeof inst.addOption === 'function') {
                        try {
                            if (existingOption) {
                                if (typeof inst.updateOption === 'function') {
                                    inst.updateOption(value, { value: value, text: label });
                                } else {
                                    try { inst.removeOption(value); } catch (e) {}
                                    inst.addOption({ value: value, text: label }, true);
                                }
                            } else {
                                inst.addOption({ value: value, text: label }, true);
                            }

                            if (typeof inst.addItem === 'function') {
                                inst.addItem(value);
                            } else if (typeof inst.setValue === 'function') {
                                inst.setValue(value);
                            }
                        } catch (e) {
                            let option = existingOption;
                            if (!option) {
                                option = document.createElement('option');
                                option.value = value;
                                target.appendChild(option);
                            }
                            option.textContent = label;
                            target.value = value;
                        }
                    } else {
                        let option = existingOption;
                        if (!option) {
                            option = document.createElement('option');
                            option.value = value;
                            target.appendChild(option);
                        }
                        option.textContent = label;
                        target.value = value;
                    }

                    selectedCredencialId = value;
                    target.dispatchEvent(new Event('change', { bubbles: true }));
                };

                const fillCredencialForm = (item) => {
                    usuarioInput.value = String(item.usuario || '');
                    claveInput.value = String(item.clave || '');
                    fechaInput.value = formatFechaDisplay(item.fechaCreacion) || formatFechaDisplay(getTodayLocalIso());
                    estadoInput.checked = String(item.estadoRecepcion || '0') === '1';
                };

                const beginEditCredencial = (item) => {
                    editingCredencialId = String(item.id);
                    fillCredencialForm(item);
                    if (submitBtn) {
                        submitBtn.textContent = 'Actualizar credencial';
                    }
                    feedback.textContent = 'Editando credencial #' + String(item.id);
                    feedback.className = 'text-xs text-slate-600';
                };

                const deleteCredencialLocal = (id) => {
                    const localCredenciales = readLocalCredenciales();
                    const nextCredenciales = localCredenciales.filter((item) => String(item.tempId || item.id) !== String(id));
                    writeLocalCredenciales(nextCredenciales);

                    const target = document.getElementById(currentSelectId);
                    if (target) {
                        const value = String(id);
                        const inst = target.tomselect || target.tomSelect || target._tomselect || null;
                        try {
                            if (inst && typeof inst.removeItem === 'function') inst.removeItem(value);
                            if (inst && typeof inst.removeOption === 'function') inst.removeOption(value);
                            else {
                                const option = target.querySelector('option[value="' + value + '"]');
                                if (option) option.remove();
                            }
                        } catch (e) {
                            const option = target.querySelector('option[value="' + value + '"]');
                            if (option) option.remove();
                        }

                        if (String(target.value) === value) {
                            target.value = '';
                            selectedCredencialId = null;
                            target.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }
                };

                const deleteCredencialPersisted = async (id) => {
                    if (!currentDeleteUrlTemplate) {
                        throw new Error('No se definio la ruta para eliminar la credencial.');
                    }

                    const response = await fetch(replaceIdToken(currentDeleteUrlTemplate, id), {
                        method: 'DELETE',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                    });

                    const payload = await response.json();
                    if (!response.ok || !payload.ok) {
                        throw new Error(payload.message || 'No se pudo eliminar la credencial.');
                    }

                    const target = document.getElementById(currentSelectId);
                    if (target) {
                        const value = String(id);
                        const inst = target.tomselect || target.tomSelect || target._tomselect || null;
                        try {
                            if (inst && typeof inst.removeItem === 'function') inst.removeItem(value);
                            if (inst && typeof inst.removeOption === 'function') inst.removeOption(value);
                            else {
                                const option = target.querySelector('option[value="' + value + '"]');
                                if (option) option.remove();
                            }
                        } catch (e) {
                            const option = target.querySelector('option[value="' + value + '"]');
                            if (option) option.remove();
                        }

                        if (String(target.value) === value) {
                            target.value = '';
                            selectedCredencialId = null;
                            target.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }
                };

                const clearCreateForm = () => {
                    usuarioInput.value = '';
                    claveInput.value = '';
                    claveInput.type = 'password';
                    updatePasswordToggleIcon(false);
                    fechaInput.value = formatFechaDisplay(getTodayLocalIso());
                    estadoInput.checked = false;
                };

                const updateCount = (count) => {
                    if (countBadge) {
                        countBadge.textContent = String(count);
                    }
                };

                const renderList = (items) => {
                    if (!Array.isArray(items) || items.length === 0) {
                        updateCount(0);
                        listContainer.innerHTML = '<p class="text-xs text-slate-500">No hay credenciales registradas.</p>';
                        return;
                    }

                    updateCount(items.length);
                    listContainer.innerHTML = '';

                    items.forEach((item) => {
                        const id = String(item.id);
                        const isSelected = String(selectedCredencialId) === id;

                        const row = document.createElement('div');
                        row.className = 'flex items-center justify-between mb-2 gap-3 rounded-md border p-2 transition ' +
                            (isSelected ? 'border-primary bg-red-50/50' : 'border-slate-200 bg-white hover:border-slate-300');

                        const text = document.createElement('div');
                        text.className = 'pr-2 text-xs text-slate-700 leading-5';
                        text.textContent = String(item.label || '');

                        const actions = document.createElement('div');
                        actions.className = 'flex items-center gap-1.5';

                        const canEditCredencial = currentCredentialCanEdit === 'true';
                        const canDeleteCredencial = currentCredentialCanDelete === 'true';

                        const editBtn = document.createElement('button');
                        editBtn.type = 'button';
                        editBtn.className = 'rounded border border-slate-300 bg-white px-2 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-50';
                        editBtn.textContent = 'Editar';
                        editBtn.disabled = !canEditCredencial;
                        if (canEditCredencial) {
                            editBtn.addEventListener('click', () => beginEditCredencial(item));
                        }

                        const deleteBtn = document.createElement('button');
                        deleteBtn.type = 'button';
                        deleteBtn.className = 'rounded border border-red-200 bg-red-50 px-2 py-1 text-[11px] font-medium text-red-700 hover:bg-red-100';
                        deleteBtn.textContent = 'Eliminar';
                        deleteBtn.disabled = !canDeleteCredencial;
                        if (canDeleteCredencial) {
                                deleteBtn.addEventListener('click', async () => {
                                    const ok = await confirmCredencial({ title: 'Confirmar eliminación', message: '¿Está seguro de eliminar la credencial seleccionada?', submitText: 'Eliminar', cancelText: 'Cancelar' });
                                    if (!ok) return;

                                    feedback.textContent = 'Eliminando credencial...';
                                    feedback.className = 'text-xs text-slate-600';

                                    try {
                                        if (currentMode === 'create') {
                                            deleteCredencialLocal(item.id);
                                        } else {
                                            await deleteCredencialPersisted(item.id);
                                        }

                                        if (editingCredencialId === String(item.id)) {
                                            editingCredencialId = null;
                                            clearCreateForm();
                                            if (submitBtn) {
                                                submitBtn.textContent = 'Guardar credencial';
                                            }
                                        }

                                        await loadCredenciales();
                                        feedback.textContent = 'Credencial eliminada correctamente.';
                                        feedback.className = 'text-xs text-emerald-700';
                                    } catch (error) {
                                        feedback.textContent = error.message || 'No se pudo eliminar la credencial.';
                                        feedback.className = 'text-xs text-red-600';
                                    }
                            });
                        }

                        const pickBtn = document.createElement('button');
                        pickBtn.type = 'button';
                        pickBtn.className = 'min-w-[102px] rounded border px-2.5 py-1.5 text-[11px] font-medium shadow-sm ' +
                            (isSelected
                                ? 'border-primary bg-primary text-white'
                                : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50');
                        pickBtn.textContent = isSelected ? 'Seleccionada' : 'Seleccionar';
                        pickBtn.addEventListener('click', () => {
                            applySelectOption(item);
                            closeModal();
                        });

                        if (canEditCredencial) {
                            actions.appendChild(editBtn);
                        }
                        if (canDeleteCredencial) {
                            actions.appendChild(deleteBtn);
                        }
                        actions.appendChild(pickBtn);

                        row.appendChild(text);
                        row.appendChild(actions);
                        listContainer.appendChild(row);
                    });
                };

                const loadCredenciales = async () => {
                    if (currentMode === 'create' && !currentStoreUrl) {
                        const localCredenciales = readLocalCredenciales();
                        listItems = localCredenciales.map((item, index) => {
                            const id = String(item.tempId || item.id || ('tmp-' + index));
                            return {
                                id: id,
                                label: buildLocalLabel(item),
                                usuario: String(item.usuario || ''),
                                clave: String(item.clave || ''),
                                fechaCreacion: String(item.fechaCreacion || ''),
                                estadoRecepcion: String(item.estadoRecepcion || '0'),
                            };
                        });
                        renderList(listItems);
                        return;
                    }

                    listContainer.innerHTML = '<p class="text-xs text-slate-500">Cargando...</p>';
                    try {
                        const response = await fetch(currentListUrl, {
                            headers: {
                                Accept: 'application/json',
                            },
                        });

                        const payload = await response.json();
                        listItems = Array.isArray(payload.data) ? payload.data : [];
                        renderList(listItems);
                    } catch (error) {
                        updateCount(0);
                        listContainer.innerHTML = '<p class="text-xs text-red-600">No se pudo cargar las credenciales.</p>';
                    }
                };

                const openModal = (button) => {
                    currentSelectId = button.getAttribute('data-quick-credential-target');
                    currentMode = button.getAttribute('data-quick-credential-mode') || 'create';
                    currentListUrl = button.getAttribute('data-quick-credential-list-url');
                    currentStoreUrl = button.getAttribute('data-quick-credential-store-url');
                    currentUpdateUrlTemplate = button.getAttribute('data-quick-credential-update-url-template');
                    currentDeleteUrlTemplate = button.getAttribute('data-quick-credential-delete-url-template');
                    currentExportPdfUrl = button.getAttribute('data-quick-export-pdf-url') || '';
                    currentExportXlsxUrl = button.getAttribute('data-quick-export-xlsx-url') || '';
                    currentPayloadInputName = button.getAttribute('data-quick-credential-payload-input') || 'credenciales_payload';
                    currentCredentialCanEdit = button.getAttribute('data-quick-credential-can-edit') || 'false';
                    currentCredentialCanDelete = button.getAttribute('data-quick-credential-can-delete') || 'false';

                    const target = document.getElementById(currentSelectId);
                    selectedCredencialId = target ? String(target.value || '') : null;
                    editingCredencialId = null;
                    updateExportButtons();
                    clearCreateForm();
                    if (searchInput) {
                        searchInput.value = '';
                    }
                    if (submitBtn) {
                        submitBtn.textContent = 'Guardar credencial';
                    }
                    feedback.textContent = '';
                    feedback.className = 'text-xs';
                    if (claveInput) {
                        claveInput.type = 'password';
                    }
                    updatePasswordToggleIcon(false);

                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                    document.body.style.overflow = 'hidden';
                    loadCredenciales();
                };

                // Reemplazamos el manejo por uno que mantiene visibles solo los iconos internos
                if (claveToggleBtn) {
                    // No delegamos al toggle anterior; manejamos aquí para mantener sincronía con los SVGs.
                    const input = claveInput;
                    const toggleBtn = claveToggleBtn;

                    const updateToggleVisibility = () => {
                        if (!input || !toggleBtn) return;
                        const hasText = String(input.value || '').trim().length > 0;
                        toggleBtn.style.display = hasText ? 'flex' : 'none';
                    };

                    const setIconState = (passwordVisible) => {
                        if (!toggleBtn) return;
                        const iconE = toggleBtn.querySelector('#icon-eye');
                        const iconEO = toggleBtn.querySelector('#icon-eye-off');
                        if (iconE && iconEO) {
                            iconE.classList.toggle('hidden', !passwordVisible);
                            iconEO.classList.toggle('hidden', passwordVisible);
                        } else {
                            updatePasswordToggleIcon(passwordVisible);
                        }
                    };

                    // Estado inicial
                    updateToggleVisibility();
                    setIconState(false); // contraseña oculta por defecto

                    // Eventos para mostrar/ocultar el botón según contenido
                    input?.addEventListener('input', updateToggleVisibility);
                    input?.addEventListener('focus', updateToggleVisibility);
                    input?.addEventListener('blur', updateToggleVisibility);

                    // Click del botón: alterna tipo y estados del icono
                    toggleBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        if (!input) return;
                        const willShow = input.type === 'password';
                        input.type = willShow ? 'text' : 'password';
                        setIconState(willShow);
                        input.focus();
                    });
                }

                quickButtons.forEach((button) => {
                    button.addEventListener('click', () => {
                        if (button.disabled) {
                            return;
                        }
                        openModal(button);
                    });
                });

                closeBtn?.addEventListener('click', closeModal);
                cancelBtn?.addEventListener('click', closeModal);

                searchInput?.addEventListener('input', () => {
                    const term = String(searchInput.value || '').trim().toLowerCase();
                    if (term === '') {
                        renderList(listItems);
                        return;
                    }

                    const filtered = listItems.filter((item) => {
                        const label = String(item.label || '').toLowerCase();
                        const descripcion = String(item.descripcion || '').toLowerCase();
                        return label.includes(term) || descripcion.includes(term);
                    });
                    renderList(filtered);
                });

                form?.addEventListener('submit', async (event) => {
                    event.preventDefault();

                    const usuario = String(usuarioInput.value || '').trim();
                    const clave = String(claveInput.value || '').trim();
                    const fechaCreacionRaw = String(fechaInput.value || '').trim();
                    const fechaCreacion = parseFechaInput(fechaCreacionRaw);
                    const estadoRecepcion = estadoInput.checked ? '1' : '0';

                    if (usuario.length < 2) {
                        feedback.textContent = 'El usuario debe tener al menos 2 caracteres.';
                        feedback.className = 'text-xs text-red-600';
                        return;
                    }

                    if (clave.length < 8) {
                        feedback.textContent = 'La clave debe tener al menos 8 caracteres.';
                        feedback.className = 'text-xs text-red-600';
                        return;
                    }

                    if (!fechaCreacionRaw) {
                        feedback.textContent = 'La fecha de creación es obligatoria.';
                        feedback.className = 'text-xs text-red-600';
                        return;
                    }

                    if (!fechaCreacion) {
                        feedback.textContent = 'La fecha de creación debe ser válida.';
                        feedback.className = 'text-xs text-red-600';
                        return;
                    }

                    feedback.textContent = editingCredencialId ? 'Actualizando credencial...' : 'Guardando credencial...';
                    feedback.className = 'text-xs text-slate-600';

                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.classList.add('opacity-70', 'cursor-not-allowed');
                    }

                    const credentialPayload = {
                        usuario: usuario,
                        clave: clave,
                        fechaCreacion: fechaCreacion,
                        estadoRecepcion: estadoRecepcion,
                    };

                    try {
                        if (currentMode === 'create' && !currentStoreUrl) {
                            const localCredenciales = readLocalCredenciales();
                            if (editingCredencialId) {
                                const nextCredenciales = localCredenciales.map((item) => {
                                    if (String(item.tempId || item.id) !== editingCredencialId) {
                                        return item;
                                    }
                                    return { ...item, ...credentialPayload };
                                });
                                writeLocalCredenciales(nextCredenciales);
                                const updatedItem = nextCredenciales.find((item) => String(item.tempId || item.id) === editingCredencialId);
                                if (updatedItem) {
                                    applySelectOption({
                                        id: updatedItem.tempId ?? updatedItem.id,
                                        label: buildLocalLabel(updatedItem),
                                    });
                                }
                            } else {
                                const tempId = 'tmp-' + Date.now();
                                const newCredential = { tempId, ...credentialPayload };
                                localCredenciales.push(newCredential);
                                writeLocalCredenciales(localCredenciales);
                                applySelectOption({
                                    id: tempId,
                                    label: buildLocalLabel(newCredential),
                                });
                            }

                            editingCredencialId = null;
                            clearCreateForm();
                            if (submitBtn) {
                                submitBtn.textContent = 'Guardar credencial';
                            }
                            await loadCredenciales();
                            closeModal();
                            return;
                        }

                        const isEditing = !!editingCredencialId;
                        const url = isEditing
                            ? replaceIdToken(currentUpdateUrlTemplate, editingCredencialId)
                            : currentStoreUrl;

                        if (!url) {
                            throw new Error('No se definió la ruta para guardar la credencial.');
                        }

                        const response = await fetch(url, {
                            method: isEditing ? 'PUT' : 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            body: JSON.stringify(credentialPayload),
                        });

                        const payload = await response.json();
                        if (!response.ok || !payload.ok) {
                            throw new Error(payload.message || (isEditing ? 'No se pudo actualizar la credencial.' : 'No se pudo crear la credencial.'));
                        }

                        if (payload.data) {
                            applySelectOption(payload.data);
                        }

                        editingCredencialId = null;
                        clearCreateForm();
                        if (submitBtn) {
                            submitBtn.textContent = 'Guardar credencial';
                        }

                        await loadCredenciales();
                        closeModal();
                    } catch (error) {
                        feedback.textContent = error.message || 'Ocurrió un error al guardar la credencial.';
                        feedback.className = 'text-xs text-red-600';
                    } finally {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.classList.remove('opacity-70', 'cursor-not-allowed');
                        }
                    }
                });
            })();
        </script>
    @endif


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
                ? `Se cambió ${labelText} exitosamente.`
                : `${labelText.charAt(0).toUpperCase() + labelText.slice(1)} seleccionada correctamente.`;

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

                if (hasSelection) {
                    idInput.maxLength = fieldLength;
                    if (shouldStrictValidate) {
                        idInput.minLength = fieldLength;
                        idInput.pattern = `^[0-9]{${fieldLength}}$`;
                    } else {
                        idInput.minLength = 0;
                        idInput.pattern = '^[0-9]*$';
                    }
                    idInput.inputMode = 'numeric';
                } else {
                    idInput.maxLength = 11;
                    idInput.minLength = 0;
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
            let relationConfirmationConfirmed = false;

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
                resetRelationModal();
                unlockSubmit();
                const statusSpan = relationModal.querySelector('#delete-confirmation-status');
                if (statusSpan) {
                    statusSpan.classList.add('hidden');
                }
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
                        relationConfirmationConfirmed = true;
                        lockSubmit(relationModalSubmit);
                        const statusSpan = relationModal.querySelector('#delete-confirmation-status');
                        if (statusSpan) {
                            statusSpan.classList.remove('hidden');
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

                if (isEditMode && !relationConfirmationConfirmed) {
                    event.preventDefault();
                    loadRelationSummary()
                        .then((summary) => {
                            const relations = Array.isArray(summary.relations) ? summary.relations : [];
                            if (relations.length > 0) {
                                openRelationModal(summary);
                                return;
                            }

                            relationConfirmationConfirmed = true;
                            mainForm.submit();
                        })
                        .catch(() => {
                            relationConfirmationConfirmed = true;
                            mainForm.submit();
                        });
                    return;
                }
            });
        })(); 
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
@endsection

