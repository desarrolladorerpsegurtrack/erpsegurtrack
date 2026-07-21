@extends('dashboard.overview-1')

@section('title', $title ?? 'Planificación')
@section('header', $title ?? 'Planificación')

@section('breadcrumb')
    <nav aria-label="breadcrumb" class="flex hidden flex-1 xl:block">
        <ol class="flex items-center text-theme-1">
            <li><a href="{{ route('home') }}">Inicio</a></li>
            <li class="relative ml-5 pl-0.5 before:content-[''] before:w-[14px] before:h-[14px] before:bg-chevron-black before:transform before:rotate-[-90deg] before:bg-[length:100%] before:-ml-[1.125rem] before:absolute before:my-auto before:inset-y-0 text-slate-600 cursor-text">
                <span>{{ $title ?? 'Módulo' }}</span>
            </li>
        </ol>
    </nav>
@endsection

@section('content')
    <div class="grid w-full grid-cols-12 gap-x-6 gap-y-10">
        <div class="col-span-12">
                    <!-- HEADER CON TÍTULO Y BOTÓN NUEVO -->
            <div class="flex flex-col gap-y-3 md:h-10 md:flex-row md:items-center">
                <div class="text-base font-medium group-[.mode--light]:text-white">
                    {{ $title ?? 'Módulo Planificación' }}
                </div>
            </div>
        </div>
    </div>
@endsection        