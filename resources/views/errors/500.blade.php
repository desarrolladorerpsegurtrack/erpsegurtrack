@extends('layouts.layout')

@section('title', 'Página no encontrada')

@section('content')
    <div class="min-h-screen flex items-center justify-center bg-slate-200 px-4">

        <div class="w-full max-w-2xl text-center">

            {{-- Ilustración / número 500 --}}
            <div class="relative mb-8">

                {{-- 500 de fondo --}}
                <span class="select-none text-[140px] sm:text-[180px] font-bold leading-none tracking-tight text-slate-100">
                    500
                </span>

                {{-- Ícono central --}}
                <div class="absolute inset-0 flex items-center justify-center">
                    <div class="flex h-20 w-20 items-center justify-center rounded-2xl bg-white border border-slate-200 shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg"
                            class="h-10 w-10 text-slate-400"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="1.7">

                            <path stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />

                        </svg>
                    </div>
                </div>
            </div>

            {{-- Contenido --}}
            <div class="mx-auto max-w-lg">

                <h1 class="text-2xl sm:text-3xl font-semibold text-slate-900">
                    Página no encontrada
                </h1>

                <p class="mt-3 text-sm sm:text-base leading-7 text-slate-500">
                    Parece que la página que estás buscando no existe,
                    fue movida o ya no está disponible.
                </p>

                {{-- Acciones --}}
                <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-3">

                    {{-- Volver --}}
                    <button
                        type="button"
                        onclick="if (window.history.length > 1) {
                            window.history.back();
                        } else {
                            location.href='{{ route('home') }}';
                        }"
                        class="inline-flex w-full sm:w-auto items-center justify-center gap-2
                               rounded-lg bg-danger px-5 py-2.5
                               text-sm font-medium text-white
                               transition hover:bg-slate-800
                               focus:outline-none focus:ring-2
                               focus:ring-slate-400 focus:ring-offset-2">

                        <svg xmlns="http://www.w3.org/2000/svg"
                            class="h-4 w-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="2">

                            <path stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />

                        </svg>

                        Volver atrás
                    </button>
                </div>
            </div>

            {{-- Mensaje inferior --}}
            <p class="mt-10 text-xs text-slate-400">
                Si crees que esto es un error, contacta con el área de Sistemas.
            </p>

        </div>
    </div>
@endsection