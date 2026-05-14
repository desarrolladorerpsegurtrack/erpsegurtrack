@extends('layouts.layout')

@section('title', 'Acceso denegado')

@section('content')
    <div class="min-h-screen flex items-center justify-center bg-slate-50 px-4">
        <div class="max-w-xl w-full bg-white border border-slate-200 shadow-sm rounded-3xl p-10 text-center">
            <h1 class="text-3xl font-semibold text-slate-900 mb-3">Acceso denegado</h1>
            <p class="text-slate-600 mb-8 leading-relaxed">
                {{ $exception->getMessage() ?: 'No tienes acceso a esta acción.' }}
            </p>
            <button type="button"
                style="background:red;color:#fff;padding:12px 24px;border-radius:9px;;display:inline-flex"
                onclick="location.href='{{ url()->previous() ?: url('/') }}'">
                Volver
            </button>
        </div>
    </div>
@endsection
