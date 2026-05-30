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
            ];
        @endphp

        @foreach($relationGroups as $relationGroup)
            @php
                $groupKey = (string) ($relationGroup['key'] ?? '');
                $editConfig = $relationEditRoutes[$groupKey] ?? null;
            @endphp

            <div class="overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-4 py-3 text-sm font-semibold text-slate-700">
                    {{ $relationGroup['label'] ?? 'Relación' }}
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-100">
                                @foreach(($relationGroup['columns'] ?? []) as $relationColumn)
                                    <th class="px-4 py-3 whitespace-nowrap font-semibold text-slate-600">
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
                                <tr class="border-b border-slate-100 last:border-b-0">
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

                                        <td class="px-4 py-3 align-middle {{ $isFirstColumn ? 'font-semibold text-slate-800' : 'text-slate-700' }}">
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
                                                <div class="flex items-center gap-1.5 {{ $isFirstColumn && $canEditRow ? 'text-primary' : '' }}">
                                                    <i data-lucide="database" class="h-3.5 w-3.5 stroke-[1.7] {{ $isActive ? 'text-danger' : 'text-slate-400' }}"></i>
                                                    <span class="whitespace-nowrap font-medium {{ $isActive ? 'text-danger' : 'text-slate-500' }}">{{ $label }}</span>
                                                </div>
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
                                                @if($isFirstColumn && $canEditRow)
                                                    <a
                                                        href="{{ route($editConfig['route'], $editValue) }}"
                                                        class="font-medium text-slate-700 hover:text-primary hover:underline"
                                                    >
                                                        <span>{{ $formattedRelationDate }}</span>
                                                    </a>
                                                @else
                                                    <span class="whitespace-nowrap">{{ $formattedRelationDate }}</span>
                                                @endif
                                            @else
                                                @if($isFirstColumn && $canEditRow)
                                                    <a
                                                        href="{{ route($editConfig['route'], $editValue) }}"
                                                        class="font-medium text-slate-700 hover:text-primary hover:underline"
                                                    >
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
    </div>
</div>