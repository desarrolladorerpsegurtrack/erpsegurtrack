@if(isset($componentesVehiculo) && $componentesVehiculo->isNotEmpty())
    <div class="mt-8 mb-6">
        <label class="block text-sm font-semibold text-slate-700 mb-2">
            {{ $componentesTitle ?? 'Accesorios' }}
        </label>
        <div class="overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-left text-sm text-slate-700">
                    <thead class="bg-slate-200 text-slate-700">
                        <tr>
                            <th class="px-3 py-3 font-semibold">Tipo</th>
                            <th class="px-3 py-3 font-semibold">Accesorio o sensor</th>
                            <th class="px-3 py-3 font-semibold">IMEI</th>
                            <th class="px-3 py-3 font-semibold">Usa red móvil</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($componentesVehiculo as $componente)
                            <tr class="border-t border-slate-200 hover:bg-slate-50">
                                <td class="px-3 py-3">{{ $componente->categoria }}</td>
                                <td class="px-3 py-3">{{ $componente->producto ?: '-' }}</td>
                                <td class="px-3 py-3 font-medium">{{ $componente->imei }}</td>
                                <td class="px-3 py-3">{{ $componente->usa_red_movil_label }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif
