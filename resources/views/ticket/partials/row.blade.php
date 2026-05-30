<tr class="[&_td]:last:border-b-0">
    @foreach($columns as $columnIndex => $column)
        <td class="px-5 border-b dark:border-darkmode-300 border-dashed py-4 @if(($column['key'] ?? '') === 'estado') text-center align-middle @endif @if(!empty($column['wrap'] ?? false)) align-top w-[38%] @endif">
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
                    <span class="inline-flex items-center rounded-md border px-2.5 py-2 text-xs font-semibold" style="background-color: {{ $estadoColores['bg'] }}; color: {{ $estadoColores['text'] }};">
                        {{ $estadoLabel }}
                    </span>
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
        <td class="px-5 border-b dark:border-darkmode-300 relative border-dashed py-4 dark:bg-darkmode-600">
            <div class="flex items-center justify-center h-full">
                @php
                    $currentUser = session('erp_auth.usuario', '');
                    $lockedByOther = !empty($row->locked_by_other);
                    $lockedUser = $row->lock_usuario ?? ($row->historial_usuario ?? null);
                    $estadoLabel = mb_strtolower(trim((string) ($row->estado ?? '')));
                    $isResolved = $estadoLabel === 'resuelto';
                @endphp
                @if(!$canAttend)
                    <div class="flex flex-col items-center gap-2">
                        <span class="inline-flex items-center justify-center rounded-md border px-3 py-2 text-sm font-semibold text-slate-500 bg-slate-100 border-slate-200">
                            Seguimiento
                        </span>
                        @if($lockedUser)
                            <span class="text-xs text-slate-500">Último en atender: {{ $lockedUser }}</span>
                        @endif
                    </div>
                @elseif($isResolved)
                    <div class="flex flex-col items-center gap-2">
                        <button type="button" disabled class="inline-flex items-center justify-center rounded-md border px-3 py-2 text-sm font-semibold transition duration-200 opacity-60 cursor-not-allowed" style="color: #ffffff; background-color: #c71010;">
                            Finalizado
                        </button>
                        @if($lockedUser)
                            <span class="text-xs text-slate-500">Último en atender: {{ $lockedUser }}</span>
                        @endif
                    </div>
                @elseif($lockedByOther)
                    <div class="flex flex-col items-center gap-2">
                        <button type="button" disabled class="inline-flex items-center justify-center rounded-md border px-3 py-2 text-sm font-semibold transition duration-200 opacity-60 cursor-not-allowed" style="color: #ffffff; background-color: #c71010;">
                            Atender
                        </button>
                        @if($lockedUser)
                            <span class="text-xs text-slate-500">Atendiendo: {{ $lockedUser }}</span>
                        @endif
                    </div>
                @else
                    <div class="flex flex-col items-center gap-2">
                        <a href="{{ route('modules.tickets.show', ['ticketId' => $row->idticket]) }}">
                            <button type="button" class="inline-flex items-center justify-center rounded-md border px-3 py-2 text-sm font-semibold transition duration-200 " style="color: #ffffff; background-color: #c71010;">
                                Atender
                            </button>
                        </a>
                        @if($lockedUser)
                            <span class="text-xs text-slate-500">Último en atender: {{ $lockedUser }}</span>
                        @endif
                    </div>
                @endif
            </div>
        </td>
    @endif
</tr>
