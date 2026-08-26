<div class="px-0 py-0">
    <div class="flex flex-col gap-3">
        @php
            $relationEditRoutes = [
                'vehiculos' => [
                    'route' => 'modules.vehiculos.edit',
                    'key' => 'placa',
                ],
                'servicio_cliente' => [
                    'route' => 'modules.servicio-cliente.edit',
                    'key' => 'idservicioCliente',
                ],
                'dispositivo_cliente' => [
                    'route' => 'modules.dispositivo-cliente.edit',
                    'key' => 'iddispositivoCliente',
                ],
                // Edición de dispositivos en el módulo Almacén
                'almacen' => [
                    'route' => 'modules.almacen.edit',
                    'key' => 'idalmacen',
                ],
                // Soporte para listar clientes dentro de un grupo
                'detallegrupocliente' => [
                    'route' => 'modules.clientes.edit',
                    'key' => 'idcliente',
                ],
                'clientes' => [
                    'route' => 'modules.clientes.edit',
                    'key' => 'idcliente',
                ],
            ];

            // Identificar si estamos en el módulo de clientes para aplicar el acordeón interactivo de 3 niveles
            $isClienteModule = request()->routeIs('modules.clientes*') || request()->routeIs('modules.clientes');

            // Agrupar relaciones por clave para fácil acceso en el acordeón
            $groupsByKey = collect($relationGroups)->keyBy('key');

            $serviceGroup = $groupsByKey->get('servicio_cliente') ?? [
                'key' => 'servicio_cliente',
                'label' => 'Servicio cliente',
                'columns' => [
                    ['key' => 'idservicioCliente', 'label' => 'ID'],
                    ['key' => 'vehiculo_placa', 'label' => 'Vehículo'],
                    ['key' => 'almacen_detalle', 'label' => 'Servicio'],
                    ['key' => 'plataforma', 'label' => 'Plataforma'],
                    ['key' => 'fechaInicio', 'label' => 'Fecha Inicio'],
                    ['key' => 'fechaVencimiento', 'label' => 'Fecha Fin'],
                    ['key' => 'monto', 'label' => 'Monto'],
                    ['key' => 'estado', 'label' => 'Estado'],
                    ['key' => 'docReferencia', 'label' => 'Documento'],
                ],
                'records' => []
            ];

            $vehicleGroup = $groupsByKey->get('vehiculos') ?? [
                'key' => 'vehiculos',
                'label' => 'Vehículos',
                'columns' => [
                    ['key' => 'placa', 'label' => 'Vehículo'],
                    ['key' => 'numero', 'label' => 'Número'],
                    ['key' => 'tipo_vehiculo', 'label' => 'Tipo'],
                    ['key' => 'anio', 'label' => 'Año'],
                    ['key' => 'marca', 'label' => 'Marca'],
                    ['key' => 'modelo', 'label' => 'Modelo'],
                    ['key' => 'color', 'label' => 'Color'],
                    ['key' => 'tracto', 'label' => 'Tracto'],
                ],
                'records' => []
            ];

            $deviceGroup = $groupsByKey->get('dispositivo_cliente') ?? [
                'key' => 'dispositivo_cliente',
                'label' => 'Dispositivo cliente',
                'columns' => [
                    ['key' => 'iddispositivoCliente', 'label' => 'ID Dispositivo'],
                    ['key' => 'numero', 'label' => 'Número'],
                    ['key' => 'vehiculo_placa', 'label' => 'Vehículo'],
                    ['key' => 'marcaDispositivo', 'label' => 'Marca'],
                    ['key' => 'modeloDispositivo', 'label' => 'Modelo'],
                    ['key' => 'fechaInstalacion', 'label' => 'Fecha instalación'],
                    ['key' => 'fechaBaja', 'label' => 'Fecha baja'],
                    ['key' => 'estado', 'label' => 'Estado'],
                ],
                'records' => []
            ];

            $hasServices = !empty($serviceGroup['records']);
            $hasVehicles = !empty($vehicleGroup['records']);
            $serviceTypeCount = collect($serviceGroup['records'] ?? [])->count();
            $serviceVehicleCount = collect($serviceGroup['records'] ?? [])
                ->flatMap(function ($service) {
                    $plates = $service['vehicle_plates'] ?? [];
                    return !empty($plates) ? $plates : [data_get($service, 'vehiculo_placa')];
                })
                ->filter(fn ($plate) => trim((string) $plate) !== '')
                ->unique()
                ->count();

            // Determinar el nivel inicial en el que arrancará
            $startLevel = 1;
            if (!$hasServices) {
                $startLevel = $hasVehicles ? 2 : 1;
            }
        @endphp

        @if($isClienteModule && ($hasServices || $hasVehicles))
            <!-- CONTENEDOR ACORDEÓN INTERACTIVO DE 3 NIVELES -->
            <div id="relation-panel-{{ $row->idcliente ?? 'generic' }}" class="flex flex-col gap-3 relation-panel-accordion"
                data-client-id="{{ $row->idcliente ?? '' }}"
                data-start-level="{{ $startLevel }}"
                data-has-services="{{ $hasServices ? 'true' : 'false' }}"
                data-has-vehicles="{{ $hasVehicles ? 'true' : 'false' }}">

                <!-- Cabecera / Breadcrumbs de navegación -->
                <div
                    class="flex items-center justify-between border border-slate-200 rounded-md bg-slate-50 px-4 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wider shadow-sm">
                    <div class="flex items-center gap-2 flex-wrap breadcrumb-list">
                        @if($hasServices)
                            <span class="bc-item bc-1 text-primary cursor-pointer hover:underline"
                                data-level="1">Servicios</span>
                        @endif

                        @if($hasVehicles || $hasServices)
                            <span class="bc-separator sep-1 text-slate-400 @if($hasServices) hidden @endif">/</span>
                            <span class="bc-item bc-2 text-slate-500 @if($hasServices) hidden @endif"
                                data-level="2">Vehículos</span>
                        @endif

                    </div>
                </div>

                <!-- NIVEL 1: SERVICIO CLIENTE -->
                @if($hasServices)
                    <div class="level-container level-1-container">
                        <div class="overflow-hidden rounded-md border border-black bg-white shadow-sm">
                            <div
                                class="border-b border-black px-4 py-3 text-sm font-semibold text-slate-800 bg-slate-200 flex justify-between items-center gap-4">
                                <div class="flex items-center gap-4">
                                    <span>Servicios Cliente</span>
                                    <span class="text-xs font-normal text-slate-600">Haz clic en una fila para ver sus vehículos</span>
                                </div>
                                <div class="ml-auto flex shrink-0 items-center gap-4 text-sm font-semibold text-slate-800">
                                    <span>N° Tipo Servicios: {{ $serviceTypeCount }}</span>
                                    <span>N° Vehículos: {{  $serviceVehicleCount }}</span>
                                </div>
                            </div>
                            <div style="max-height: 200px; overflow-x: auto; overflow-y: auto;">
                                @php
                                    $serviceMaxTs = null;
                                    $serviceTimestamps = [];
                                    foreach ((array) ($serviceGroup['records'] ?? []) as $r) {
                                        $f = data_get($r, 'fechaAsignacion') ?? data_get($r, 'fecha_asignacion') ?? null;
                                        if (!empty($f)) {
                                            $ts = @strtotime($f);
                                            if ($ts !== false && $ts !== null) $serviceTimestamps[] = $ts;
                                        }
                                    }
                                    if (!empty($serviceTimestamps)) $serviceMaxTs = max($serviceTimestamps);
                                @endphp
                                <table class="w-full table-fixed text-left text-sm border-collapse border border-black">
                                    <colgroup>
                                        <col style="width: 60%;">
                                        <col style="width: 21%;">
                                        <col style="width: 14%;">
                                        <col style="width: 5%;">
                                    </colgroup>
                                    <thead class="bg-slate-300 text-slate-800">
                                        <tr>
                                            @foreach(($serviceGroup['columns'] ?? []) as $col)
                                                <th title="{{ $col['label'] ?? '' }}" class="sticky top-0 z-10 bg-slate-300 px-4 py-3 whitespace-nowrap font-normal border-b border-black">
                                                    {{ $col['label'] ?? '' }}
                                                </th>
                                            @endforeach
                                            <th class="sticky top-0 z-10 bg-slate-300 w-10 border-b border-black"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach(($serviceGroup['records'] ?? []) as $record)
                                            @php
                                                $editConfig = $relationEditRoutes['servicio_cliente'] ?? null;
                                                $editValue = $editConfig ? data_get($record, $editConfig['key']) : null;
                                                $recFecha = data_get($record, 'fechaAsignacion') ?? data_get($record, 'fecha_asignacion') ?? null;
                                                $recVigenteStyle = '';
                                                if (!empty($recFecha) && !empty($serviceMaxTs)) {
                                                    $recTs = @strtotime($recFecha);
                                                    if ($recTs !== false && $recTs === $serviceMaxTs) {
                                                        $recVigenteStyle = 'color: #dc2626 !important;';
                                                    }
                                                }
                                            @endphp
                                            <tr style="{{ $recVigenteStyle }}" class="bg-slate-100 border-b border-black hover:bg-slate-200 cursor-pointer transition-colors service-row-clickable"
                                                data-service-type="{{ $record['almacen_detalle'] ?? '' }}"
                                                data-service-plates='@json($record['vehicle_plates'] ?? [])'
                                                data-service-id="{{ $record['idservicioCliente'] ?? '' }}">
                                                @foreach(($serviceGroup['columns'] ?? []) as $columnIndex => $column)
                                                    @php
                                                        $relationValue = data_get($record, $column['key'] ?? '') ?? '-';
                                                        $relationKey = (string) ($column['key'] ?? '');
                                                        $relationType = $column['type'] ?? 'text';
                                                        $isFirstColumn = $columnIndex === 0;
                                                        $canEditRow = false;
                                                    @endphp
                                                    <td title="{{ (string) $relationValue }}"
                                                        class="px-4 py-3 align-middle border-b border-black {{ $isFirstColumn ? 'font-semibold text-slate-800' : 'text-slate-700' }}">
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
                                                            <div
                                                                class="flex items-center gap-1.5 {{ $isFirstColumn && $canEditRow ? 'text-primary' : '' }}">
                                                                <i data-lucide="database"
                                                                    class="h-3.5 w-3.5 stroke-[1.7] {{ $isActive ? 'text-danger' : 'text-slate-400' }}"></i>
                                                                <span
                                                                    class="whitespace-nowrap font-medium {{ $isActive ? 'text-danger' : 'text-slate-500' }}">{{ $label }}</span>
                                                            </div>
                                                        @elseif($relationType === 'date' || str_starts_with($relationKey, 'fecha'))
                                                            @php
                                                                $formattedRelationDate = '-';
                                                                if (!empty($relationValue) && $relationValue !== '0000-00-00 ') {
                                                                    try {
                                                                        $relationDate = \Illuminate\Support\Carbon::parse($relationValue);
                                                                        $relationMonths = ['ene.', 'feb.', 'mar.', 'abr.', 'may.', 'jun.', 'jul.', 'ago.', 'sep.', 'oct.', 'nov.', 'dic.'];
                                                                        $formattedRelationDate = sprintf(
                                                                            '%s %s %s',
                                                                            $relationDate->format('d'),
                                                                            $relationMonths[(int) $relationDate->format('m') - 1],
                                                                            $relationDate->format('Y'),

                                                                        );
                                                                    } catch (\Throwable $throwable) {
                                                                        $formattedRelationDate = (string) $relationValue;
                                                                    }
                                                                }
                                                            @endphp
                                                            @if($isFirstColumn && $canEditRow)
                                                                <a href="{{ route($editConfig['route'], [$editValue, 'return_route' => 'modules.clientes']) }}"
                                                                    class="font-medium text-slate-700 hover:text-primary hover:underline">
                                                                    <span>{{ $formattedRelationDate }}</span>
                                                                </a>
                                                            @else
                                                                <span class="whitespace-nowrap">{{ $formattedRelationDate }}</span>
                                                            @endif
                                                        @else
                                                            @if($isFirstColumn && $canEditRow)
                                                                <a href="{{ route($editConfig['route'], [$editValue, 'return_route' => 'modules.clientes']) }}"
                                                                    class="font-medium text-slate-700 hover:text-primary hover:underline">
                                                                    <span class="whitespace-nowrap">{{ $relationValue }}</span>
                                                                </a>
                                                            @else
                                                                <span class="whitespace-nowrap">{{ $relationValue }}</span>
                                                            @endif
                                                        @endif
                                                    </td>
                                                @endforeach
                                                <td class="px-3 py-3 align-middle text-right text-slate-400 border-b border-black">
                                                    <i data-lucide="chevron-right" class="h-4 w-4 stroke-[2]"></i>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                @endif

                <!-- NIVEL 2: VEHÍCULOS -->
                @if($hasVehicles || $hasServices)
                    <div class="level-container level-2-container hidden">
                        <div class="overflow-hidden rounded-md border border-black bg-white shadow-sm">
                            <div class="border-b border-black px-4 py-3 text-sm font-normal text-slate-800 bg-slate-200 flex justify-between items-center gap-4">
                                <div class="flex items-center gap-4">   
                                    <span class="lvl2-title-label">Vehículos del Cliente</span>
                                </div>
                                <div class="ml-auto flex shrink-0 items-center gap-4 text-sm font-semibold text-slate-800">
                                    <span>N° Tipo Servicios: {{ $serviceTypeCount }}</span>
                                    <span>N° Vehículos: {{ $serviceVehicleCount }}</span>
                                </div>
                            </div>
                            <div style="max-width: 100%; max-height: 200px; overflow-x: auto; overflow-y: auto;">
                                @php
                                    $vehicleMaxTs = null;
                                    $vehicleTimestamps = [];
                                    foreach ((array) ($vehicleGroup['records'] ?? []) as $r) {
                                        $f = data_get($r, 'fechaAsignacion') ?? data_get($r, 'fecha_asignacion') ?? null;
                                        if (!empty($f)) {
                                            $ts = @strtotime($f);
                                            if ($ts !== false && $ts !== null) $vehicleTimestamps[] = $ts;
                                        }
                                    }
                                    if (!empty($vehicleTimestamps)) $vehicleMaxTs = max($vehicleTimestamps);
                                @endphp
                                <table class="w-full text-left text-sm border-collapse border border-black" style="width: 100%; min-width: 1200px; table-layout: auto;">
                                    <thead class="bg-slate-300 text-slate-800">
                                        <tr>
                                            @foreach(($vehicleGroup['columns'] ?? []) as $col)
                                                <th title="{{ $col['label'] ?? '' }}" style="{{ $loop->first ? 'min-width: 110px;' : '' }}" class="sticky top-0 z-10 bg-slate-300 px-4 py-3 whitespace-nowrap font-normal border-b border-black">
                                                    {{ $col['label'] ?? '' }}
                                                </th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach(($vehicleGroup['records'] ?? []) as $record)
                                            @php
                                                $editConfig = $relationEditRoutes['vehiculos'] ?? null;
                                                $editValue = $editConfig ? data_get($record, $editConfig['key']) : null;
                                                $recFecha = data_get($record, 'fechaAsignacion') ?? data_get($record, 'fecha_asignacion') ?? null;
                                                $recVigenteStyle = '';
                                                if (!empty($recFecha) && !empty($vehicleMaxTs)) {
                                                    $recTs = @strtotime($recFecha);
                                                    if ($recTs !== false && $recTs === $vehicleMaxTs) {
                                                        $recVigenteStyle = 'color: #dc2626 !important;';
                                                    }
                                                }
                                            @endphp
                                            <tr style="{{ $recVigenteStyle }}; width: 100%;" class="bg-slate-100 border-b border-black hover:bg-slate-200 transition-colors vehicle-row-clickable"
                                                data-placa="{{ $record['placa'] ?? '' }}">
                                                @foreach(($vehicleGroup['columns'] ?? []) as $columnIndex => $column)
                                                    @php
                                                        $relationValue = data_get($record, $column['key'] ?? '') ?? '-';
                                                        $relationKey = (string) ($column['key'] ?? '');
                                                        $relationType = $column['type'] ?? 'text';
                                                        $isFirstColumn = $columnIndex === 0;
                                                        $canEditRow = $isFirstColumn
                                                            && $editConfig
                                                            && !empty($editValue)
                                                            && \Illuminate\Support\Facades\Route::has($editConfig['route']);
                                                    @endphp
                                                    <td title="{{ (string) $relationValue }}" style="{{ $isFirstColumn ? 'min-width: 110px;' : '' }}"
                                                        class="px-4 py-3 align-middle whitespace-nowrap border-b border-black {{ $isFirstColumn ? 'font-semibold text-slate-800' : 'text-slate-700' }}">
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
                                                            <div
                                                                class="flex items-center gap-1.5 {{ $isFirstColumn && $canEditRow ? 'text-primary' : '' }}">
                                                                <i data-lucide="database"
                                                                    class="h-3.5 w-3.5 stroke-[1.7] {{ $isActive ? 'text-danger' : 'text-slate-400' }}"></i>
                                                                <span
                                                                    class="whitespace-nowrap font-medium {{ $isActive ? 'text-danger' : 'text-slate-500' }}">{{ $label }}</span>
                                                            </div>
                                                        @elseif($relationType === 'date' || str_starts_with($relationKey, 'fecha'))
                                                            @php
                                                                $formattedRelationDate = '-';
                                                                if (!empty($relationValue) && $relationValue !== '0000-00-00') {
                                                                    try {
                                                                        $relationDate = \Illuminate\Support\Carbon::parse($relationValue);
                                                                        $relationMonths = ['ene.', 'feb.', 'mar.', 'abr.', 'may.', 'jun.', 'jul.', 'ago.', 'sep.', 'oct.', 'nov.', 'dic.'];
                                                                        $formattedRelationDate = sprintf(
                                                                            '%s %s %s',
                                                                            $relationDate->format('d'),
                                                                            $relationMonths[(int) $relationDate->format('m') - 1],
                                                                            $relationDate->format('Y')
                                                                        );
                                                                    } catch (\Throwable $throwable) {
                                                                        $formattedRelationDate = (string) $relationValue;
                                                                    }
                                                                }
                                                            @endphp
                                                            @if($isFirstColumn && $canEditRow)
                                                                <a href="{{ route($editConfig['route'], [$editValue, 'return_route' => 'modules.clientes']) }}"
                                                                    class="font-medium text-slate-700 hover:text-primary hover:underline">
                                                                    <span>{{ $formattedRelationDate }}</span>
                                                                </a>
                                                            @else
                                                                <span class="whitespace-nowrap">{{ $formattedRelationDate }}</span>
                                                            @endif
                                                        @else
                                                            @if($isFirstColumn && $canEditRow)
                                                                <a href="{{ route($editConfig['route'], [$editValue, 'return_route' => 'modules.clientes']) }}"
                                                                    class="font-medium text-slate-700 hover:text-primary hover:underline">
                                                                    <span class="whitespace-nowrap">{{ $relationValue }}</span>
                                                                </a>
                                                            @else
                                                                <span class="whitespace-nowrap">{{ $relationValue }}</span>
                                                            @endif
                                                        @endif
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                        <!-- Fila en caso de que no haya vehículos asociados al servicio -->
                                        <tr class="no-records-row hidden">
                                            <td colspan="{{ count($vehicleGroup['columns']) }}"
                                                class="px-4 py-8 text-center text-slate-400 bg-slate-50/50">
                                                No hay vehículos asociados a este servicio.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                @endif

                

                <!-- SCRIPT GLOBAL REMOVIDO HACIA relation-panel-script.blade.php -->
            </div>
        @else
            <!-- VISTA GENÉRICA COMPATIBLE PARA OTROS MÓDULOS (VEHÍCULOS Y GRUPO DE CLIENTES) -->
            @foreach($relationGroups as $relationGroup)
                @php
                    $groupKey = (string) ($relationGroup['key'] ?? '');
                    $editConfig = $relationEditRoutes[$groupKey] ?? null;
                @endphp

                <div class="overflow-hidden rounded-md border border-black bg-white shadow-sm" >
                    <div class="border-b border-black px-2 py-2 text-sm font-semibold text-slate-800 bg-slate-200">
                        {{ $relationGroup['label'] ?? 'Relación' }}
                    </div>

                    <div style="max-height: 130px; overflow-x: auto; overflow-y: auto;">
                        <table class="w-full text-left text-sm border-collapse border border-black">
                            <thead class="bg-slate-300 text-slate-800">
                                <tr>
                                    @foreach(($relationGroup['columns'] ?? []) as $relationColumn)
                                        <th title="{{ $relationColumn['label'] ?? '' }}" class="sticky top-0 z-10 bg-slate-300 px-4 py-3 whitespace-nowrap font-semibold border-b border-black">
                                            {{ $relationColumn['label'] ?? '' }}
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach(($relationGroup['records'] ?? []) as $relationRecord)
                                    @php
                                        $editValue = $editConfig ? data_get($relationRecord, $editConfig['key']) : null;
                                    @endphp
                                    <tr class="bg-slate-100 border-b border-black hover:bg-slate-200 transition-colors">
                                        @foreach(($relationGroup['columns'] ?? []) as $columnIndex => $relationColumn)
                                            @php
                                                $relationValue = data_get($relationRecord, $relationColumn['key'] ?? '') ?? '-';
                                                $relationKey = (string) ($relationColumn['key'] ?? '');
                                                $relationType = $relationColumn['type'] ?? 'text';
                                                $isFirstColumn = $columnIndex === 0;
                                                $canEditRow = $isFirstColumn
                                                    && $editConfig
                                                    && !empty($editValue)
                                                    && \Illuminate\Support\Facades\Route::has($editConfig['route']);
                                            @endphp

                                            <td title="{{ (string) $relationValue }}"
                                                class="px-4 py-3 align-middle border-b border-black {{ $isFirstColumn ? 'font-semibold text-slate-800' : 'text-slate-700' }}">
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
                                                    <div
                                                        class="flex items-center gap-1.5 {{ $isFirstColumn && $canEditRow ? 'text-primary' : '' }}">
                                                        <i data-lucide="database"
                                                            class="h-3.5 w-3.5 stroke-[1.7] {{ $isActive ? 'text-danger' : 'text-slate-400' }}"></i>
                                                        <span
                                                            class="whitespace-nowrap font-medium {{ $isActive ? 'text-danger' : 'text-slate-500' }}">{{ $label }}</span>
                                                    </div>
                                                @elseif($relationType === 'date' || str_starts_with($relationKey, 'fecha'))
                                                    @php
                                                        $formattedRelationDate = '-';
                                                        if (!empty($relationValue) && $relationValue !== '0000-00-00') {
                                                            try {
                                                                $relationDate = \Illuminate\Support\Carbon::parse($relationValue);
                                                                $relationMonths = ['ene.', 'feb.', 'mar.', 'abr.', 'may.', 'jun.', 'jul.', 'ago.', 'sep.', 'oct.', 'nov.', 'dic.'];
                                                                $formattedRelationDate = sprintf(
                                                                    '%s %s %s',
                                                                    $relationDate->format('d'),
                                                                    $relationMonths[(int) $relationDate->format('m') - 1],
                                                                    $relationDate->format('Y'),
                                                                );
                                                            } catch (\Throwable $throwable) {
                                                                $formattedRelationDate = (string) $relationValue;
                                                            }
                                                        }
                                                    @endphp
                                                    @if($isFirstColumn && $canEditRow)
                                                        <a href="{{ route($editConfig['route'], [$editValue, 'return_route' => 'modules.clientes']) }}"
                                                            class="font-medium text-slate-700 hover:text-primary hover:underline">
                                                            <span>{{ $formattedRelationDate }}</span>
                                                        </a>
                                                    @else
                                                        <span class="whitespace-nowrap">{{ $formattedRelationDate }}</span>
                                                    @endif
                                                @else
                                                    @if($isFirstColumn && $canEditRow)
                                                        <a href="{{ route($editConfig['route'], [$editValue, 'return_route' => 'modules.clientes']) }}"
                                                            class="font-medium text-slate-700 hover:text-primary hover:underline">
                                                            <span class="whitespace-nowrap">{{ $relationValue }}</span>
                                                        </a>
                                                    @else
                                                        <span class="whitespace-nowrap">{{ $relationValue }}</span>
                                                    @endif
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        @endif
    </div>
</div>