@extends('layouts.public')

@section('title', 'Subscribe – '.$siteName)

@section('content')
<section class="block">
    <h1>Subscribe to updates</h1>
    <p>Get an e-mail when we publish an incident or schedule maintenance. You can also follow the <a href="{{ route('feed') }}">Atom feed</a>.</p>
    <form method="post" action="{{ route('subscribe.store') }}" class="form">
        @csrf
        <label>E-mail address
            <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email">
        </label>
        @error('email')<p class="error">{{ $message }}</p>@enderror
        @include('status.partials.component-checkboxes', ['selected' => array_map('intval', old('components', []))])
        <div class="form-actions">
            <button type="submit">Subscribe</button>
            @if (\App\Http\Controllers\GoogleAuthController::enabled())
                <span class="muted">or</span>
                <button type="submit" class="google" formaction="{{ route('subscribe.google') }}" formnovalidate>@include('status.partials.google-logo') Subscribe with Google</button>
            @endif
        </div>
        @if (\App\Http\Controllers\GoogleAuthController::enabled())
            <p class="muted">With Google there is no confirmation e-mail: your address is already verified.</p>
        @endif
    </form>
    @if (\App\Http\Controllers\GoogleAuthController::enabled())
        <p>Already subscribed? <a href="{{ route('auth.google', 'manage') }}">Sign in with Google</a> or use the link in any of our e-mails to change or cancel your subscription.</p>
    @else
        <p>Already subscribed? Use the link in any of our e-mails to change or cancel your subscription.</p>
    @endif
</section>
@endsection
