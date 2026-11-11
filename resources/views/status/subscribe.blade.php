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
        <button type="submit">Subscribe</button>
    </form>
</section>
@endsection
