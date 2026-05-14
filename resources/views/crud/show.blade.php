@extends('dashboard.overview-1')

@section('content')
<div class="content transition-[margin,width] duration-100 px-5 mt-[65px] pt-[31px] pb-16 relative z-10 content--compact xl:ml-[275px] [&.content--compact]:xl:ml-[91px]">
    <div class="w-full max-w-5xl mx-auto">
        <div class="grid grid-cols-12 gap-6">
            <!-- BEGIN: Breadcrumb & Actions -->
            <div class="col-span-12">
                <div class="flex flex-col gap-y-3 lg:h-10 lg:flex-row lg:items-center">
                    <div class="flex items-center text-lg font-medium">
                        {{ $breadcrumbLabel }}
                        <i data-lucide="arrow-right" class="mx-2 h-5 w-5 stroke-[1.3]"></i>
                        <div class="text-sm sm:text-lg">
                            {{ $recordTitle }}
                        </div>
                    </div>
                    <div class="flex flex-col gap-x-3 gap-y-2 sm:flex-row lg:ml-auto">
                        <a href="{{ route($editRoute, data_get($record, $recordId)) }}" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 bg-primary border-primary text-white">
                            <i data-lucide="pen-square" class="mr-2.5 h-4 w-4 stroke-[1.3]"></i>
                            Editar
                        </a>
                        <a href="{{ route($listRoute) }}" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus-visible:outline-none [&:hover:not(:disabled)]:bg-opacity-90 [&:hover:not(:disabled)]:border-opacity-90 bg-secondary/70 border-secondary/70 text-slate-500 [&:hover:not(:disabled)]:bg-slate-100">
                            <i data-lucide="arrow-left" class="mr-2.5 h-4 w-4 stroke-[1.3]"></i>
                            Volver
                        </a>
                    </div>
                </div>
            </div>
            <!-- END: Breadcrumb & Actions -->

            <!-- BEGIN: Details Content -->
            <div class="col-span-12">
                <div class="grid grid-cols-1 gap-5">
                    @foreach($sections as $section)
                    <div class="box box--stacked flex flex-col p-5 md:p-7">
                        <div class="relative mt-3 rounded-[0.6rem] border border-slate-200/80">
                            <div class="absolute left-0 -mt-2 ml-4 bg-white px-3 text-xs uppercase text-slate-500">
                                <div class="-mt-px">{{ $section['title'] }}</div>
                            </div>
                            <div class="mt-4 flex flex-col gap-5 p-5 md:p-7">
                                @foreach($section['items'] as $item)
                                <div class="flex flex-col sm:flex-row sm:items-center border-b border-slate-200/50 pb-4 last:border-0 last:pb-0">
                                    <div class="flex items-center w-full sm:w-48 gap-3 mb-2 sm:mb-0">
                                        @if(!empty($item['icon']))
                                        <i data-lucide="{{ $item['icon'] }}" class="h-5 w-5 stroke-[1.5] text-slate-400 flex-shrink-0"></i>
                                        @else
                                        <div class="w-5 h-5"></div>
                                        @endif
                                        <div class="font-medium text-slate-600">{{ $item['label'] }}:</div>
                                    </div>
                                    <div class="text-slate-900 font-semibold sm:ml-auto">{{ $item['value'] ?? 'N/A' }}</div>
                                </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            <!-- END: Details Content -->
        </div>
    </div>
</div>
@endsection
