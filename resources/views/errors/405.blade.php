@extends('layouts.layout')

@section('title', 'Método no permitido')

@section('content')

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { box-sizing: border-box; }
        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 24px;
            background: #f8fafc;
            color: #0f172a;
            font-family: Arial, Helvetica, sans-serif;
        }
        main {
            width: min(100%, 560px);
            padding: 48px 30px;
            border: 1px solid #e2e8f0;
            border-radius: 24px;
            background: #fff;
            box-shadow: 0 20px 40px rgb(15 23 42 / 8%);
            text-align: center;
        }
        .icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 24px;
            display: grid;
            place-items: center;
            border-radius: 20px;
            background: #fff1f2;
            color: #e11d48;
            font-size: 32px;
            font-weight: 700;
        }
        p { margin: 16px 0 0; color: #64748b; line-height: 1.7; }
        a:hover, button:hover { background: #be123c; }
        .help { margin-top: 24px; font-size: 14px; }
        @media (max-width: 420px) {
            main { padding: 36px 22px; }
            a, button { width: 100%; margin: 12px 0 0; }
        }
    </style>
</head>
<body>
    <main>
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
        <h1 class="mt-2 text-2xl sm:text-3xl font-bold text-slate-900">Página no encontrada</h1>
        <p>
            La página que estás buscando no existe o ha sido movida. 
            Tranquilo, puedes volver atrás o contactar con el área de Sistemas.
        </p>
        <button
            type="button"
            onclick="if (window.history.length > 1) {
                window.history.back();
            } else {
                location.href='{{ route('home') }}';
            }"
            class="mt-5 inline-flex w-full sm:w-auto items-center justify-center gap-2 rounded-lg bg-danger px-5 py-2.5 text-sm font-medium text-white transition hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-danger focus:ring-offset-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />           
            </svg>

            Volver atrás
        </button>            
    </main>
</body>
</html>

@endsection