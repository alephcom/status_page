@extends('layouts.public')

@section('title', __('Sign in').' – '.$siteName)

@section('content')
<section class="block">
    <h1>{{ __('Sign in') }}</h1>
    <p>{{ __('Sign in to manage your subscription.') }}</p>
    <form method="post" action="{{ route('login.store') }}" class="form">
        @csrf
        <label>{{ __('E-mail address') }}
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
        </label>
        @error('email')<p class="error">{{ $message }}</p>@enderror
        <label>{{ __('Password') }}
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <label class="inline"><input type="checkbox" name="remember" value="1"> {{ __('Remember me') }}</label>
        <div class="form-actions">
            <button type="submit">{{ __('Sign in') }}</button>
            @if (\App\Http\Controllers\GoogleAuthController::enabled())
                <span class="muted">{{ __('or') }}</span>
                <a class="button google" href="{{ route('auth.google', 'account') }}">@include('status.partials.google-logo') {{ __('Sign in with Google') }}</a>
            @endif
        </div>
    </form>
    <p><a href="{{ route('password.request') }}">{{ __('Forgot your password?') }}</a></p>
    <p>{!! __('No account yet? :link.', ['link' => '<a href="'.e(route('register')).'">'.e(__('Create one')).'</a>']) !!}</p>
</section>
@endsection
