@extends('layouts.public')

@section('title', __('Create an account').' – '.$siteName)

@section('content')
<section class="block">
    <h1>{{ __('Create an account') }}</h1>
    <p>{{ __('With an account you can manage your subscription without waiting for an e-mail.') }}</p>
    <form method="post" action="{{ route('register.store') }}" class="form">
        @csrf
        <label>{{ __('Name') }}
            <input type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name" maxlength="255">
        </label>
        @error('name')<p class="error">{{ $message }}</p>@enderror
        <label>{{ __('E-mail address') }}
            <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email">
        </label>
        @error('email')<p class="error">{{ $message }}</p>@enderror
        <label>{{ __('Password') }}
            <input type="password" name="password" required autocomplete="new-password" minlength="10">
        </label>
        @error('password')<p class="error">{{ $message }}</p>@enderror
        <label>{{ __('Confirm password') }}
            <input type="password" name="password_confirmation" required autocomplete="new-password">
        </label>
        <div class="form-actions">
            <button type="submit">{{ __('Create account') }}</button>
            @if (\App\Http\Controllers\GoogleAuthController::enabled())
                <span class="muted">{{ __('or') }}</span>
                <a class="button google" href="{{ route('auth.google', 'account') }}">@include('status.partials.google-logo') {{ __('Sign in with Google') }}</a>
            @endif
        </div>
    </form>
    <p>{!! __('Already have an account? :link.', ['link' => '<a href="'.e(route('login')).'">'.e(__('Sign in')).'</a>']) !!}</p>
</section>
@endsection
