<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="larsadmin-name" content="{{ \Maxcxam\Larsadmin\Larsadmin::name() }}">

    <title inertia>{{ \Maxcxam\Larsadmin\Larsadmin::name() }}</title>

    <script>
        try { var t = localStorage.getItem('larsadmin.theme'); if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t) } catch (e) {}
    </script>
    <link rel="stylesheet" href="{{ \Maxcxam\Larsadmin\Larsadmin::asset('app.css') }}">
    <script type="module" src="{{ \Maxcxam\Larsadmin\Larsadmin::asset('app.js') }}"></script>

    @inertiaHead
</head>
<body class="h-full bg-canvas text-fg antialiased">
    @inertia
</body>
</html>
