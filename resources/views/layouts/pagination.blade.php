<nav class="mr-auto w-full flex-1 sm:w-auto">
    <ul class="flex w-full mr-0 sm:mr-auto sm:w-auto">
        <li class="flex-1 sm:flex-initial">
            <a href="{{ $paginator->onFirstPage() ? 'javascript:;' : $paginator->url(1) }}" aria-disabled="{{ $paginator->onFirstPage() ? 'true' : 'false' }}" tabindex="{{ $paginator->onFirstPage() ? '-1' : '0' }}" class="transition duration-200 border items-center justify-center py-2 rounded-md focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none min-w-0 sm:min-w-[40px] shadow-none font-normal flex border-transparent text-slate-800 sm:mr-2 px-1 sm:px-3 {{ $paginator->onFirstPage() ? 'pointer-events-none opacity-50' : '' }}">
                <i data-tw-merge="" data-lucide="chevrons-left" class="stroke-[1] h-4 w-4"></i>
            </a>
        </li>
        <li class="flex-1 sm:flex-initial">
            <a href="{{ $paginator->onFirstPage() ? 'javascript:;' : ($paginator->previousPageUrl() ?? $paginator->url(1)) }}" aria-disabled="{{ $paginator->onFirstPage() ? 'true' : 'false' }}" tabindex="{{ $paginator->onFirstPage() ? '-1' : '0' }}" class="transition duration-200 border items-center justify-center py-2 rounded-md focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none min-w-0 sm:min-w-[40px] shadow-none font-normal flex border-transparent text-slate-800 sm:mr-2 px-1 sm:px-3 {{ $paginator->onFirstPage() ? 'pointer-events-none opacity-50' : '' }}">
                <i data-tw-merge="" data-lucide="chevron-left" class="stroke-[1] h-4 w-4"></i>
            </a>
        </li>
        @php
            $lastPage = $paginator->lastPage();
            $currentPage = $paginator->currentPage();
        @endphp

        {{-- Si hay muchas páginas, limitar la cantidad visible a 4 a partir de la página 1000 --}}
        @if($lastPage >= 1000)
            @php
                $maxVisible = 3;
                $half = intdiv($maxVisible, 2);
                $start = $currentPage - $half;
                $end = $start + $maxVisible - 1;
                if ($start < 1) { $start = 1; $end = min($maxVisible, $lastPage); }
                if ($end > $lastPage) { $end = $lastPage; $start = max(1, $lastPage - $maxVisible + 1); }
            @endphp

            @if($start > 1)
                <li class="flex-1 sm:flex-initial">
                    <a href="{{ $paginator->url(1) }}" class="transition duration-200 border items-center justify-center py-2 rounded-md focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none min-w-0 sm:min-w-[40px] shadow-none font-normal flex border-transparent sm:mr-2 px-1 sm:px-3 text-slate-800">1</a>
                </li>
            @endif

            @if($start > 2)
                <li class="flex-1 sm:flex-initial">
                    <span class="transition duration-200 border items-center justify-center py-2 rounded-md min-w-0 sm:min-w-[40px] shadow-none font-normal flex border-transparent text-slate-400 sm:mr-2 px-1 sm:px-3">&hellip;</span>
                </li>
            @endif

            @for ($i = $start; $i <= $end; $i++)
                <li class="flex-1 sm:flex-initial">
                    <a href="{{ $paginator->url($i) }}" class="transition duration-200 border items-center justify-center py-2 rounded-md focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none min-w-0 sm:min-w-[40px] shadow-none font-normal flex border-transparent sm:mr-2 px-1 sm:px-3 {{ $i == $currentPage ? '!box dark:bg-darkmode-400' : 'text-slate-800' }}">{{ $i }}</a>
                </li>
            @endfor

            @if($end < $lastPage - 1)
                <li class="flex-1 sm:flex-initial">
                    <span class="transition duration-200 border items-center justify-center py-2 rounded-md min-w-0 sm:min-w-[40px] shadow-none font-normal flex border-transparent text-slate-400 sm:mr-2 px-1 sm:px-3">&hellip;</span>
                </li>
            @endif

            @if($end < $lastPage)
                <li class="flex-1 sm:flex-initial">
                    <a href="{{ $paginator->url($lastPage) }}" class="transition duration-200 border items-center justify-center py-2 rounded-md focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none min-w-0 sm:min-w-[40px] shadow-none font-normal flex border-transparent sm:mr-2 px-1 sm:px-3 text-slate-800">{{ $lastPage }}</a>
                </li>
            @endif

        @elseif (!empty($elements))
            @foreach ($elements as $element)
                @if (is_string($element))
                    <li class="flex-1 sm:flex-initial">
                        <span class="transition duration-200 border items-center justify-center py-2 rounded-md min-w-0 sm:min-w-[40px] shadow-none font-normal flex border-transparent text-slate-400 sm:mr-2 px-1 sm:px-3">{{ $element }}</span>
                    </li>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        <li class="flex-1 sm:flex-initial">
                            <a href="{{ $url }}" class="transition duration-200 border items-center justify-center py-2 rounded-md focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none min-w-0 sm:min-w-[40px] shadow-none font-normal flex border-transparent sm:mr-2 px-1 sm:px-3 {{ $page == $paginator->currentPage() ? '!box dark:bg-darkmode-400' : 'text-slate-800' }}">
                                {{ $page }}
                            </a>
                        </li>
                    @endforeach
                @endif
            @endforeach
        @else
            <li class="flex-1 sm:flex-initial">
                <span class="transition duration-200 border items-center justify-center py-2 rounded-md min-w-0 sm:min-w-[40px] shadow-none font-normal flex border-transparent text-slate-800 sm:mr-2 px-1 sm:px-3 !box">1</span>
            </li>
        @endif
        <li class="flex-1 sm:flex-initial">
            <a href="{{ $paginator->hasMorePages() ? ($paginator->nextPageUrl() ?? $paginator->url($paginator->lastPage())) : 'javascript:;' }}" aria-disabled="{{ $paginator->hasMorePages() ? 'false' : 'true' }}" tabindex="{{ $paginator->hasMorePages() ? '0' : '-1' }}" class="transition duration-200 border items-center justify-center py-2 rounded-md focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none min-w-0 sm:min-w-[40px] shadow-none font-normal flex border-transparent text-slate-800 sm:mr-2 px-1 sm:px-3 {{ $paginator->hasMorePages() ? '' : 'pointer-events-none opacity-50' }}">
                <i data-tw-merge="" data-lucide="chevron-right" class="stroke-[1] h-4 w-4"></i>
            </a>
        </li>
        <li class="flex-1 sm:flex-initial">
            <a href="{{ $paginator->hasMorePages() ? $paginator->url($paginator->lastPage()) : 'javascript:;' }}" aria-disabled="{{ $paginator->hasMorePages() ? 'false' : 'true' }}" tabindex="{{ $paginator->hasMorePages() ? '0' : '-1' }}" class="transition duration-200 border items-center justify-center py-2 rounded-md focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none min-w-0 sm:min-w-[40px] shadow-none font-normal flex border-transparent text-slate-800 sm:mr-2 px-1 sm:px-3 {{ $paginator->hasMorePages() ? '' : 'pointer-events-none opacity-50' }}">
                <i data-tw-merge="" data-lucide="chevrons-right" class="stroke-[1] h-4 w-4"></i>
            </a>
        </li>
    </ul>
</nav>
