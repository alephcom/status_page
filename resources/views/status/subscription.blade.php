@extends('layouts.public')

@section('title', 'Your subscription – '.$siteName)

@section('content')
<section class="block">
    <h1>Your subscription</h1>
    <p>Updates go to <strong>{{ $subscriber->email }}</strong>.
        @unless ($subscriber->isVerified())<em>This address has not been confirmed yet.</em>@endunless
    </p>
    <form method="post" action="{{ route('subscription.update', $subscriber) }}" class="form">
        @csrf @method('put')
        @include('status.partials.component-checkboxes', ['selected' => $subscriber->components->pluck('id')->all()])
        <button type="submit">Save preferences</button>
    </form>
    <form method="post" action="{{ route('subscription.destroy', $subscriber) }}" class="form">
        @csrf @method('delete')
        <button type="submit" class="secondary">Unsubscribe</button>
    </form>
</section>
@endsection
