@php
    $cancelUrl = $cancelUrl ?? route('modules.tickets.cancel', ['ticketId' => $ticket->idticket]);
    $advanceUrl = $advanceUrl ?? route('modules.tickets.advance', ['ticketId' => $ticket->idticket]);
    $actionLabel = $actionLabel ?? 'Guardar';
    $actionValue = $actionValue ?? 'save';
@endphp

<input type="hidden" id="erp-lock-resource" value="{{ $lockResource ?? 'ticket' }}">
<input type="hidden" id="erp-lock-id" value="{{ $lockId ?? $ticket->idticket }}">

<div class="mt-6 flex flex-wrap items-center justify-end gap-3">
    <form method="POST" action="{{ $cancelUrl }}" data-lock-action="ticket">
        @csrf
        <button type="submit" class="inline-flex items-center justify-center rounded-md border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 transition duration-200 hover:bg-slate-100">
            Cancelar
        </button>
    </form>

    <form method="POST" action="{{ $advanceUrl }}" data-lock-action="ticket">
        @csrf
        <input type="hidden" name="action" value="{{ $actionValue }}">
        <button type="submit" class="inline-flex items-center justify-center rounded-md border px-4 py-2 text-sm font-semibold text-white transition duration-200" style="background-color:#c71010;">
            {{ $actionLabel }}
        </button>
    </form>
</div>
