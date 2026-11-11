@extends('layouts.public')

@section('title', __('History').' – '.$siteName)

@section('content')
<section class="block">
    <h1>{{ __('Incident history') }}</h1>
    @forelse ($incidents as $incident)
        @include('status.partials.incident', ['incident' => $incident, 'limit' => 1])
    @empty
        <p class="muted">{{ __('No incidents have been reported.') }}</p>
    @endforelse
    <div class="pager">{{ $incidents->links('status.partials.pager') }}</div>
</section>

@if ($maintenances->isNotEmpty())
    <section class="block">
        <h2>{{ __('Past maintenance') }}</h2>
        @foreach ($maintenances as $maintenance)
            @include('status.partials.maintenance', ['maintenance' => $maintenance->load('components')])
        @endforeach
    </section>
@endif
@endsection
