<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $branding['theme'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $siteName)</title>
    <script>try { var t = localStorage.getItem('statuspage-theme'); if (t) document.documentElement.dataset.theme = t; } catch (e) {}</script>
    <link rel="stylesheet" href="{{ asset('css/status.css') }}?v={{ filemtime(public_path('css/status.css')) }}">
    @if ($branding['color'])
        <style>:root { --brand: {{ $branding['color'] }}; }</style>
    @endif
    @if ($branding['css'])
        <style>{!! str_ireplace('</style', '', $branding['css']) !!}</style>
    @endif
    <link rel="alternate" type="application/atom+xml" title="{{ $siteName }}" href="{{ route('feed') }}">
    @if ($branding['logo'])<link rel="icon" href="{{ $branding['logo'] }}">@endif
    @stack('head')
</head>
<body class="@yield('body-class')">
<a class="skip" href="#main">{{ __('Skip to content') }}</a>
<div class="wrap">
    <header class="site-header">
        <a class="site-name" href="{{ route('status') }}">
            @if ($branding['logo'])<img src="{{ $branding['logo'] }}" alt="" class="logo">@endif
            <span>{{ $siteName }}</span>
        </a>
        <nav aria-label="{{ __('Main') }}">
            <a href="{{ route('history') }}">{{ __('History') }}</a>
            <a href="{{ route('subscribe') }}">{{ __('Subscribe') }}</a>
        </nav>
    </header>

    @if ($branding['announcement'])
        <div class="announcement is-{{ $branding['announcement_level'] }}" role="note">
            <div class="markdown">{!! Str::markdown($branding['announcement'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
        </div>
    @endif

    @if (session('status'))
        <div class="notice" role="status">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="notice is-error" role="alert">{{ session('error') }}</div>
    @endif

    <main id="main">
        @yield('content')
    </main>

    <footer class="site-footer">
        <div class="footer-links">
            @foreach ($branding['footer_links'] as $link)
                <a href="{{ $link['url'] }}" rel="noopener">{{ $link['name'] }}</a>
            @endforeach
            <a href="{{ route('feed') }}">{{ __('Atom feed') }}</a>
            <a href="{{ route('calendar') }}">{{ __('Maintenance calendar') }}</a>
            @yield('footer')
        </div>
        <div class="footer-controls">
            @if (count($branding['locales']) > 1)
                <form method="get" class="lang" action="{{ url()->current() }}">
                    @foreach (request()->except('lang') as $key => $value)
                        @if (is_string($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
                    @endforeach
                    <label><span class="sr-only">{{ __('Language') }}</span>
                        <select name="lang" onchange="this.form.submit()">
                            @foreach ($branding['locales'] as $code => $name)
                                <option value="{{ $code }}" @selected($code === app()->getLocale())>{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <noscript><button type="submit">{{ __('Change') }}</button></noscript>
                </form>
            @endif
            <button type="button" class="theme-toggle" data-theme-toggle
                    data-label-auto="{{ __('Theme: automatic') }}" data-label-light="{{ __('Theme: light') }}" data-label-dark="{{ __('Theme: dark') }}">{{ __('Theme: automatic') }}</button>
        </div>
    </footer>
</div>
<script src="{{ asset('js/status.js') }}?v={{ filemtime(public_path('js/status.js')) }}" defer></script>
</body>
</html>
