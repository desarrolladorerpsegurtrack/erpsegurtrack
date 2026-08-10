@extends('layouts.layout')

@section('title', 'Página no encontrada')

@section('content')
    <div class="min-h-screen relative flex items-center justify-center bg-slate-50 px-4 overflow-hidden">

        <div class="relative w-full max-w-lg">

            <div class="rounded-3xl bg-white shadow-xl shadow-slate-200/60 ring-1 ring-slate-100 px-6 py-10 sm:px-12 sm:py-12 text-center">

                {{-- Ícono ilustrativo --}}
                <div class="relative mx-auto mb-4 flex h-24 w-24 items-center justify-center">

                    {{-- Aro decorativo --}}
                    <span class="absolute inset-0 rounded-full bg-danger/10"></span>

                    <div class="relative flex h-16 w-16 animate-[bounce_3s_ease-in-out_infinite] items-center justify-center rounded-2xl bg-white shadow-md ring-1 ring-slate-100">
                        <svg xmlns="http://www.w3.org/2000/svg"
                            class="h-10 w-10 text-danger"
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

                {{-- Contenido --}}
                <h1 class="mt-2 text-2xl sm:text-3xl font-bold text-slate-900">
                    Página no encontrada
                </h1>

                <p class="mt-3 text-sm sm:text-base leading-7 text-slate-500 max-w-sm mx-auto">
                    Puede que el enlace esté mal escrito, que la página se haya movido o que ya no exista.
                    Tranquilo, esto no afecta el resto del sistema.
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
                               transition hover:bg-red-700
                               focus:outline-none focus:ring-2
                               focus:ring-danger focus:ring-offset-2">

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
            <p class="mt-6 text-center text-xs text-slate-400">
                ¿Crees que esto es un error? Contacta con el área de
                <span class="font-medium text-slate-500">Sistemas</span>.
            </p>

        </div>
    </div>
@endsection