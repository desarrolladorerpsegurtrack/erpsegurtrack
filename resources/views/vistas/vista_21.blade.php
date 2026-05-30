@extends('dashboard.overview-1')

@section('title', $title ?? 'Vista 2')
@section('header', $title ?? 'Vista 2')


@section('breadcrumb')
    <nav aria-label="breadcrumb" class="flex hidden flex-1 xl:block">
        <ol class="flex items-center text-theme-1">
            <li><a href="{{ route('home') }}">Inicio</a></li>
            <li class="relative ml-5 pl-0.5 before:content-[''] before:w-[14px] before:h-[14px] before:bg-chevron-black before:transform before:rotate-[-90deg] before:bg-[length:100%] before:-ml-[1.125rem] before:absolute before:my-auto before:inset-y-0 text-slate-600 cursor-text">
                <span>{{ $title ?? 'Vista 2' }}</span>
            </li>
        </ol>
    </nav>
@endsection
@section('content')
    <div class="grid grid-cols-12 gap-6">
        <div class="col-span-12">
            <div class="box p-5">
                <h2 class="text-lg font-medium mr-auto">{{ $title ?? 'Vista 2' }}</h2>
                <p class="mt-3">Contenido de la Vista 2 Area Sistemas</p>
                @include('vistas._actions')
            </div>
        </div>
    </div>
@endsection