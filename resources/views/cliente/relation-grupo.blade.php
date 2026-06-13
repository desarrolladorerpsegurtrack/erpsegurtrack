<div class="overflow-hidden rounded-xl border border-black bg-white shadow-sm">
    <div class="border-b border-black px-4 py-3 text-sm font-semibold text-slate-800 bg-slate-200">
        Clientes del Grupo
    </div>

    <div class="">
        @php
            $groups = collect($relationGroups ?? [])->values();
        @endphp

        @if($groups->isEmpty())
            <div class="text-sm text-slate-500">Sin clientes asociados.</div>
        @else
            @foreach($groups as $group)
                <div class="">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm border-collapse border border-black">
                            <thead class="bg-slate-300 text-slate-800">
                                <tr>
                                    @foreach(($group['columns'] ?? []) as $col)
                                        <th class="px-4 py-3 whitespace-nowrap font-semibold border-b border-black">
                                            {{ $col['label'] ?? '' }}
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach(($group['records'] ?? []) as $record)
                                    <tr class="bg-slate-100 border-b border-black hover:bg-slate-200">
                                        @foreach(($group['columns'] ?? []) as $col)
                                            @php
                                                $val = data_get($record, $col['key'] ?? '') ?? '-';
                                                $type = $col['type'] ?? 'text';
                                                $isFirst = $loop->index === 0;
                                                $groupKey = (string) ($group['key'] ?? '');
                                                $canLinkFirst = $isFirst && $groupKey === 'detallegrupocliente' &&

                                                    \Illuminate\Support\Facades\Route::has('modules.clientes.edit') && !empty(data_get($record, 'idcliente'));
                                            @endphp
                                            <td
                                                class="px-4 py-3 align-middle border-b border-black {{ $type === 'status' ? 'text-center' : '' }}">
                                                @if($type === 'status')
                                                    @php
                                                        $isActive = (string) $val === '1' || stripos((string) $val, 'activo') !== false;
                                                        $label = $isActive ? 'Activo' : (trim((string) $val) ?: '-');
                                                    @endphp
                                                    <div class="flex items-center gap-1.5 justify-start">
                                                        <i data-lucide="database"
                                                            class="h-3.5 w-3.5 {{ $isActive ? 'text-danger' : 'text-slate-400' }}"></i>
                                                        <span
                                                            class="{{ $isActive ? 'text-danger' : 'text-slate-500' }} font-medium">{{ $label }}</span>
                                                    </div>
                                                @else
                                                    @if($canLinkFirst)
                                                        <a href="{{ route('modules.clientes.edit', data_get($record, 'idcliente')) }}"
                                                            class="font-medium text-slate-700 hover:text-primary hover:underline">
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