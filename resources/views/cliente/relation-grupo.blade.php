<div class="overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
    <div class="border-b border-slate-200 px-4 py-3 text-sm font-semibold text-slate-700 bg-slate-50/50">
        Clientes del Grupo
    </div>

    <div class="px-4 py-3">
        @php
            $groups = collect($relationGroups ?? [])->values();
        @endphp

        @if($groups->isEmpty())
            <div class="text-sm text-slate-500">Sin clientes asociados.</div>
        @else
            @foreach($groups as $group)
                <div class="mb-4">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-slate-100 bg-slate-50/20">
                                    @foreach(($group['columns'] ?? []) as $col)
                                            <th class="px-4 py-3 whitespace-nowrap font-semibold text-slate-600">{{ $col['label'] ?? '' }}</th>
                                        @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach(($group['records'] ?? []) as $record)
                                    <tr class="border-b border-slate-100 last:border-b-0 hover:bg-slate-50/80">
                                        @foreach(($group['columns'] ?? []) as $col)
                                            @php
                                                $val = data_get($record, $col['key'] ?? '') ?? '-';
                                                $type = $col['type'] ?? 'text';
                                                $isFirst = $loop->index === 0;
                                                $groupKey = (string) ($group['key'] ?? '');
                                                $canLinkFirst = $isFirst && $groupKey === 'detallegrupocliente' && 
                                                    
                                                    \Illuminate\Support\Facades\Route::has('modules.clientes.edit') && !empty(data_get($record, 'idcliente'));
                                            @endphp
                                            <td class="px-4 py-3 align-middle {{ $type === 'status' ? 'text-center' : '' }}">
                                                @if($type === 'status')
                                                    @php
                                                        $isActive = (string)$val === '1' || stripos((string)$val,'activo') !== false;
                                                        $label = $isActive ? 'Activo' : (trim((string)$val) ?: '-');
                                                    @endphp
                                                    <div class="flex items-center gap-1.5 justify-start">
                                                        <i data-lucide="database" class="h-3.5 w-3.5 {{ $isActive ? 'text-danger' : 'text-slate-400' }}"></i>
                                                        <span class="{{ $isActive ? 'text-danger' : 'text-slate-500' }} font-medium">{{ $label }}</span>
                                                    </div>
                                                @else
                                                    @if($canLinkFirst)
                                                        <a href="{{ route('modules.clientes.edit', data_get($record, 'idcliente')) }}" class="font-medium text-slate-700 hover:text-primary hover:underline">
                                                            <span class="whitespace-nowrap">{{ $val }}</span>
                                                        </a>
                                                    @else
                                                        <span class="whitespace-nowrap">{{ $val }}</span>
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