<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $siteName)</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <meta name="theme-color" content="#c2e8f9" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#1a1442" media="(prefers-color-scheme: dark)">
    <link rel="preload" href="{{ asset('fonts/inter-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/status.css') }}?v={{ filemtime(public_path('css/status.css')) }}">
    <link rel="alternate" type="application/atom+xml" title="{{ $siteName }}" href="{{ route('feed') }}">
    @stack('head')
</head>
<body class="@yield('body-class')">
@php($logoUrl = \App\Models\Setting::get('logo_url'))
<header class="site-header">
    <div class="wrap">
        <a class="site-name" href="{{ route('status') }}">
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $siteName }}">
            @else
                {{ $siteName }}
            @endif
        </a>
        <nav>
            <a href="{{ route('history') }}">History</a>
            <a href="{{ route('feed') }}">Feed</a>
            <a class="cta" href="{{ route('subscribe') }}">Subscribe</a>
        </nav>
    </div>
</header>

<main class="wrap">
    @if (session('status'))
        <div class="notice" role="status">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="notice is-error" role="alert">{{ session('error') }}</div>
    @endif

    @yield('content')
</main>

<footer class="site-footer wrap">
    <span>{{ $siteName }}</span>
    @yield('footer')
</footer>
<script src="{{ asset('js/status.js') }}?v={{ filemtime(public_path('js/status.js')) }}" defer></script>
</body>
</html>
