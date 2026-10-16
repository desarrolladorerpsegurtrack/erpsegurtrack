<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="erp-current-user" content="{{ session('erp_auth.usuario', '') }}">
    <meta name="erp-notifications-muted" content="{{ !empty($notificationsMuted) ? '1' : '0' }}">
    <!-- Temporal: cambiar a 1 para volver a mostrar ventanas emergentes. -->
    <meta name="erp-notification-popups-enabled" content="0">
    <title>@yield('title', 'ERP SEGURTRACK')</title>
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    @vite(['resources/css/tailwise.css', 'resources/js/tailwise.js', 'resources/js/realtime.js'])
</head>
<body class="erp-body">
    @yield('content')
</body>
</html>
