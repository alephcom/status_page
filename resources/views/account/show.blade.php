@extends('layouts.public')

@section('title', __('Your account').' – '.$siteName)

@section('content')
<section class="block">
    <h1>{{ __('Your account') }}</h1>
    <p>{!! __('Signed in as :email.', ['email' => '<strong>'.e($user->email).'</strong>']) !!}
        @if ($user->isStaff())<a href="{{ url('/admin') }}">{{ __('Open the admin panel') }}</a>@endif
    </p>
</section>

<section class="block">
    <h2>{{ __('Your subscription') }}</h2>
    @if (! $user->hasVerifiedEmail())
        <p>{{ __('Confirm your e-mail address to manage your subscription. We sent you a link when you signed up.') }}</p>
        <form method="post" action="{{ route('verification.send') }}" class="form">
            @csrf
            <button type="submit" class="secondary">{{ __('Send the link again') }}</button>
        </form>
    @else
        @if ($subscriber)
            <p>{!! __('Updates go to :email.', ['email' => '<strong>'.e($subscriber->email).'</strong>']) !!}</p>
        @else
            <p>{{ __('You are not subscribed. Choose what to hear about and subscribe below.') }}</p>
        @endif
        <form method="post" action="{{ route('account.subscription.update') }}" class="form">
            @csrf @method('put')
            @include('status.partials.component-checkboxes', ['selected' => $subscriber?->components->pluck('id')->all() ?? []])
            @include('status.partials.min-impact', ['selected' => $subscriber?->min_impact?->value])
            <button type="submit">{{ $subscriber ? __('Save preferences') : __('Subscribe') }}</button>
        </form>
        @if ($subscriber)
            <form method="post" action="{{ route('account.subscription.destroy') }}" class="form">
                @csrf @method('delete')
                <button type="submit" class="secondary">{{ __('Unsubscribe') }}</button>
            </form>
        @endif
    @endif
</section>

<section class="block">
    <h2>{{ __('Change password') }}</h2>
    <form method="post" action="{{ route('account.password.update') }}" class="form">
        @csrf @method('put')
        <label>{{ __('Current password') }}
            <input type="password" name="current_password" required autocomplete="current-password">
        </label>
        @error('current_password', 'password')<p class="error">{{ $message }}</p>@enderror
        <label>{{ __('New password') }}
            <input type="password" name="password" required autocomplete="new-password" minlength="10">
        </label>
        @error('password', 'password')<p class="error">{{ $message }}</p>@enderror
        <label>{{ __('Confirm password') }}
            <input type="password" name="password_confirmation" required autocomplete="new-password">
        </label>
        <button type="submit">{{ __('Change password') }}</button>
    </form>
</section>

<section class="block">
    <form method="post" action="{{ route('logout') }}" class="form">
        @csrf
        <button type="submit" class="secondary">{{ __('Sign out') }}</button>
    </form>
</section>
@endsection
