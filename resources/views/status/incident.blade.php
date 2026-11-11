@extends('layouts.public')

@section('title', $incident->title.' – '.$siteName)

@section('content')
<section class="block">
    <p><a href="{{ route('status') }}">← Current status</a></p>
    <h1>{{ $incident->title }}</h1>
    <p>
        <span class="pill is-incident-{{ $incident->status->value }}">{{ $incident->status->getLabel() }}</span>
        <span class="muted">Impact: {{ $incident->impact->getLabel() }} ·
            Started @include('status.partials.time', ['at' => $incident->started_at])
            @if ($incident->resolved_at) · Resolved @include('status.partials.time', ['at' => $incident->resolved_at]) @endif
        </span>
    </p>
    @if ($incident->components->isNotEmpty())
        <p class="muted">Affects: {{ $incident->components->pluck('name')->join(', ') }}</p>
    @endif

    <ol class="timeline">
        @foreach ($incident->updates as $update)
            <li>
                <strong>{{ $update->status->getLabel() }}</strong>
                <small class="muted">@include('status.partials.time', ['at' => $update->created_at])</small>
                <div class="markdown">{!! Str::markdown($update->message, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
            </li>
        @endforeach
    </ol>
</section>
@endsection
