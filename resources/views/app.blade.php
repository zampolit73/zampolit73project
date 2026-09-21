<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#f0e8d5">
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="icon" type="image/svg+xml" href="{{ asset('icon-192.svg') }}">
<link rel="apple-touch-icon" href="{{ asset('icon-192.svg') }}">
<link rel="manifest" href="{{ asset('site.webmanifest') }}">
<title inertia>{{ $title ?? 'template' }}</title>
@vite('resources/js/app.js')
@inertiaHead
</head>
<body class="app-shell">@inertia</body>
</html>
