@extends('layouts.public')

@section('title', __('Your subscription').' – '.$siteName)

@section('content')
<section class="block">
    <h1>{{ __('Your subscription') }}</h1>
    <p>{!! __('Updates go to :email.', ['email' => '<strong>'.e($subscriber->email).'</strong>']) !!}
        @unless ($subscriber->isVerified())<em>{{ __('This address has not been confirmed yet.') }}</em>@endunless
    </p>
    <form method="post" action="{{ route('subscription.update', $subscriber) }}" class="form">
        @csrf @method('put')
        @include('status.partials.component-checkboxes', ['selected' => $subscriber->components->pluck('id')->all()])
        @include('status.partials.min-impact', ['selected' => $subscriber->min_impact?->value])
        <button type="submit">{{ __('Save preferences') }}</button>
    </form>
    <form method="post" action="{{ route('subscription.destroy', $subscriber) }}" class="form">
        @csrf @method('delete')
        <button type="submit" class="secondary">{{ __('Unsubscribe') }}</button>
    </form>
</section>
@endsection
