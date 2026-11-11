@extends('layouts.public')

@section('title', __('Subscribe').' – '.$siteName)

@section('content')
<section class="block">
    <h1>{{ __('Subscribe to updates') }}</h1>
    <p>{{ __('Get an e-mail when we publish an incident or schedule maintenance.') }}
        {!! __('You can also follow the :feed or add the :calendar to your calendar.', [
            'feed' => '<a href="'.e(route('feed')).'">'.e(__('Atom feed')).'</a>',
            'calendar' => '<a href="'.e(route('calendar')).'">'.e(__('maintenance calendar')).'</a>',
        ]) !!}</p>
    <form method="post" action="{{ route('subscribe.store') }}" class="form">
        @csrf
        <label>{{ __('E-mail address') }}
            <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email">
        </label>
        @error('email')<p class="error">{{ $message }}</p>@enderror
        @include('status.partials.component-checkboxes', ['selected' => array_map('intval', old('components', []))])
        @include('status.partials.min-impact', ['selected' => old('min_impact')])
        <div class="form-actions">
            <button type="submit">{{ __('Subscribe') }}</button>
            @if (\App\Http\Controllers\GoogleAuthController::enabled())
                <span class="muted">{{ __('or') }}</span>
                <button type="submit" class="google" formaction="{{ route('subscribe.google') }}" formnovalidate>@include('status.partials.google-logo') {{ __('Subscribe with Google') }}</button>
            @endif
            @if (\App\Telegram\TelegramClient::enabled())
                <button type="submit" class="telegram" formaction="{{ route('subscribe.telegram') }}" formnovalidate>@include('status.partials.telegram-logo') {{ __('Get updates on Telegram') }}</button>
            @endif
        </div>
        @php($google = \App\Http\Controllers\GoogleAuthController::enabled())
        @php($telegram = \App\Telegram\TelegramClient::enabled())
        @if ($google || $telegram)
            <p class="muted">
                @if ($google){{ __('With Google there is no confirmation e-mail: your address is already verified.') }}@endif
                @if ($telegram){!! __('Telegram opens :bot; press Start, and send /stop there to unsubscribe. You can also add the bot to a group.', ['bot' => '<a href="'.e(\App\Telegram\TelegramClient::startLink()).'">'.e('@'.\App\Telegram\TelegramClient::botUsername()).'</a>']) !!}@endif
            </p>
        @endif
    </form>
    @if (\App\Http\Controllers\GoogleAuthController::enabled())
        <p>{!! __('Already subscribed? :link or use the link in any of our e-mails to change or cancel your subscription.', ['link' => '<a href="'.e(route('auth.google', 'manage')).'">'.e(__('Sign in with Google')).'</a>']) !!}</p>
    @else
        <p>{{ __('Already subscribed? Use the link in any of our e-mails to change or cancel your subscription.') }}</p>
    @endif
</section>

<section class="block">
    <h2>{{ __('Embed our status') }}</h2>
    <p>{{ __('Show a status badge in a README or wiki:') }}</p>
    <p><img src="{{ route('badge') }}" alt="{{ __('Status badge') }}"></p>
    <pre><code>![{{ $siteName }}]({{ route('badge') }})</code></pre>
    <p>{{ __('Or add a live status widget to your own website:') }}</p>
    <pre><code>&lt;script src="{{ route('embed') }}" async&gt;&lt;/script&gt;</code></pre>
</section>
@endsection
