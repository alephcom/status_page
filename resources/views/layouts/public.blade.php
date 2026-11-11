<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $siteName)</title>
    <link rel="stylesheet" href="{{ asset('css/status.css') }}?v={{ filemtime(public_path('css/status.css')) }}">
    <link rel="alternate" type="application/atom+xml" title="{{ $siteName }}" href="{{ route('feed') }}">
    @stack('head')
</head>
<body class="@yield('body-class')">
<div class="wrap">
    <header class="site-header">
        <a class="site-name" href="{{ route('status') }}">{{ $siteName }}</a>
        <nav>
            <a href="{{ route('history') }}">History</a>
            <a href="{{ route('subscribe') }}">Subscribe</a>
            <a href="{{ route('feed') }}">Feed</a>
        </nav>
    </header>

    @if (session('status'))
        <div class="notice" role="status">{{ session('status') }}</div>
    @endif

    @yield('content')

    <footer class="site-footer">
        <span>Powered by Zabbix monitoring</span>
        @yield('footer')
    </footer>
</div>
<script src="{{ asset('js/status.js') }}?v={{ filemtime(public_path('js/status.js')) }}" defer></script>
</body>
</html>
