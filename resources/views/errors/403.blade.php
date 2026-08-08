@extends('layouts.layout')

@section('title', 'Acceso denegado')

@section('content')
    <div class="min-h-screen flex items-center justify-center bg-slate-50 px-4">
        <div class="max-w-xl w-full bg-white border border-slate-200 shadow-sm rounded-3xl p-10 text-center">
            <h1 class="text-3xl font-semibold text-slate-900 mb-3">Acceso denegado</h1>
            <p class="text-slate-600 mb-8 leading-relaxed">
                {{ $exception->getMessage() ?: 'No tienes acceso a esta acción.' }}
            </p>
            <button
                type="button"
                        onclick="if (window.history.length > 1) {
                            window.history.back();
                        } else {
                            location.href='{{ route('home') }}';
                        }"
                        class="inline-flex w-full sm:w-auto items-center justify-center gap-2 rounded-lg bg-danger px-5 py-2.5 text-sm font-medium text-white transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                </svg>

                Volver atrás
            </button>
        </div>
    </div>
@endsection
