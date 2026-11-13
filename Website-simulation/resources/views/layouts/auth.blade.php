<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · Chatter</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="auth-body">
    <main class="auth-card">
        <div class="brand">
            <span class="brand-mark">C</span>
            <span class="brand-name">Chatter</span>
        </div>
        @yield('content')
    </main>
</body>
</html>
