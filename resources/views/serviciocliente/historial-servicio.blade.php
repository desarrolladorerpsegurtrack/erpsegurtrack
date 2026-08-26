<div class="mt-8 mb-6">
    <label class="block text-sm font-semibold text-slate-700 mb-2">Historial del servicio</label>
    <div class="overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
        <div style="overflow-x: auto; max-height: 200px;">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-200 text-xs font-semibold text-slate-500"
                    style="position: sticky; top: 0; z-index: 1;">
                    <tr>
                        <th class="px-4 py-2">ID Dispositivo</th>
                        <th class="px-4 py-2">Vehículo</th>
                        <th class="px-4 py-2">Cliente</th>
                        <th class="px-4 py-2">Número</th>
                        <th class="px-4 py-2">Servicio</th>
                        <th class="px-4 py-2">Fecha inicio</th>
                        <th class="px-4 py-2">Descripción</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse(($historialRows ?? []) as $row)
                        <tr class="border-t border-slate-200 hover:bg-slate-50">
                            <td class="px-3 py-3 whitespace-nowrap text-slate-700 font-medium">
                                {{ $row->dispositivo ?? '-' }}</td>
                            <td class="px-3 py-3 whitespace-nowrap text-slate-600">{{ $row->vehiculo ?? '-' }}</td>
                            <td class="px-3 py-3 whitespace-nowrap text-slate-600">{{ $row->cliente ?? '-' }}</td>
                            <td class="px-3 py-3 whitespace-nowrap text-slate-600">{{ $row->numero ?? '-' }}</td>
                            <td class="px-3 py-3 whitespace-nowrap text-slate-600">{{ $row->servicio ?? '-' }}</td>
                            <td class="px-3 py-3 whitespace-nowrap text-slate-600">{{ $row->fecha_accion ?? '-' }}</td>
                            <td class="px-3 py-3 text-slate-600"
                                style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"
                                title="{{ $row->descripcion ?? '' }}">{{ $row->descripcion ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-6 text-center text-slate-400 text-sm">No hay registros de
                                historial.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>