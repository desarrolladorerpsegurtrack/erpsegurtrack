@php
    $cancelUrl = $cancelUrl ?? route('modules.tickets.cancel', ['ticketId' => $ticket->idticket]);
    $advanceUrl = $advanceUrl ?? route('modules.tickets.advance', ['ticketId' => $ticket->idticket]);
    $actionLabel = $actionLabel ?? 'Guardar';
    $actionValue = $actionValue ?? 'save';
@endphp

@php
    $cancelUrl = $cancelUrl ?? route('modules.tickets.cancel', ['ticketId' => $ticket->idticket]);
    $advanceUrl = $advanceUrl ?? route('modules.tickets.advance', ['ticketId' => $ticket->idticket]);
    $actionLabel = $actionLabel ?? 'Guardar';
    $actionValue = $actionValue ?? 'save';
@endphp

<input type="hidden" id="erp-lock-resource" value="{{ $lockResource ?? 'ticket' }}">
<input type="hidden" id="erp-lock-id" value="{{ $lockId ?? $ticket->idticket }}">
<input type="hidden" name="action" id="form-action-input" value="{{ $actionValue }}">

<button type="submit" formaction="{{ $cancelUrl }}" formnovalidate
    onclick="document.getElementById('form-action-input').name='';"
    class="inline-flex items-center justify-center rounded-md duration-200 border shadow-sm py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed border-secondary text-slate-500 dark:border-darkmode-100/40 dark:text-slate-300 [&:hover:not(:disabled)]:bg-secondary/20 [&:hover:not(:disabled)]:dark:bg-darkmode-100/10 border border-slate-600 px-4 py-2 text-sm font-semibold text-slate-600 transition duration-200 hover:bg-slate-100"> 
    Cancelar
</button>

<button type="submit" formaction="{{ $advanceUrl }}"
    class="inline-flex items-center justify-center rounded-md border px-4 py-2 text-sm font-semibold text-white shrink-0 transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none dark:focus:ring-slate-700 dark:focus:ring-opacity-50 [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 [&:not(button)]:text-center disabled:opacity-70 disabled:cursor-not-allowed border-secondary text-slate-500 dark:border-darkmode-100/40 dark:text-slate-300 [&:hover:not(:disabled)]:bg-secondary/20 [&:hover:not(:disabled)]:dark:bg-darkmode-100/10 transition duration-200"
    style="background-color:#c71010;">
    {{ $actionLabel }}
</button>