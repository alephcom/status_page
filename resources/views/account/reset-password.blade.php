@extends('layouts.public')

@section('title', __('Choose a new password').' – '.$siteName)

@section('content')
<section class="block">
    <h1>{{ __('Choose a new password') }}</h1>
    <form method="post" action="{{ route('password.update') }}" class="form">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label>{{ __('E-mail address') }}
            <input type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="email">
        </label>
        @error('email')<p class="error">{{ $message }}</p>@enderror
        <label>{{ __('New password') }}
            <input type="password" name="password" required autofocus autocomplete="new-password" minlength="10">
        </label>
        @error('password')<p class="error">{{ $message }}</p>@enderror
        <label>{{ __('Confirm password') }}
            <input type="password" name="password_confirmation" required autocomplete="new-password">
        </label>
        <button type="submit">{{ __('Reset password') }}</button>
    </form>
</section>
@endsection
