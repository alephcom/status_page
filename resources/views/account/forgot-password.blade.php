@extends('layouts.public')

@section('title', __('Reset your password').' – '.$siteName)

@section('content')
<section class="block">
    <h1>{{ __('Reset your password') }}</h1>
    <p>{{ __('Enter your e-mail address and we will send you a link to choose a new password.') }}</p>
    <form method="post" action="{{ route('password.email') }}" class="form">
        @csrf
        <label>{{ __('E-mail address') }}
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
        </label>
        @error('email')<p class="error">{{ $message }}</p>@enderror
        <button type="submit">{{ __('Send reset link') }}</button>
    </form>
    <p><a href="{{ route('login') }}">{{ __('Back to sign in') }}</a></p>
</section>
@endsection
