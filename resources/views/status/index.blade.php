@extends('layouts.public')

@section('body-class', 'view-'.$view)

@push('head')
    <meta name="refresh-interval" content="60">
@endpush

@section('content')
<div id="status-root" data-refresh="60">
    @php($overallText = match ($overall) {
        \App\Enums\ComponentStatus::Operational => __('All systems operational'),
        \App\Enums\ComponentStatus::Degraded => __('Some systems are degraded'),
        \App\Enums\ComponentStatus::Maintenance => __('Maintenance in progress'),
        \App\Enums\ComponentStatus::PartialOutage => __('Partial outage'),
        \App\Enums\ComponentStatus::MajorOutage => __('Major outage'),
    })
    <section class="banner is-{{ $overall->css() }}">
        <h1>{{ $overallText }}</h1>
        <p>{{ __('Updated') }} @include('status.partials.time', ['at' => $generatedAt, 'format' => 'time'])</p>
    </section>

    @if ($stale)
        <div class="notice is-stale" role="status">
            <strong>{{ __('Monitoring data may be out of date.') }}</strong>
            @if ($stale['last_ok'])
                {{ __('Last successful update') }} @include('status.partials.time', ['at' => $stale['last_ok']]).
            @else
                {{ __('No monitoring data has been received yet.') }}
            @endif
        </div>
    @endif

    @if ($about && $view === 'normal')
        <section class="about markdown">{!! Str::markdown($about, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</section>
    @endif

    @if ($activeIncidents->isNotEmpty())
        <section class="block">
            <h2>{{ __('Current incidents') }}</h2>
            @foreach ($activeIncidents as $incident)
                @include('status.partials.incident', ['incident' => $incident, 'limit' => $view === 'normal' ? 100 : 1])
            @endforeach
        </section>
    @endif

    @if ($maintenances->isNotEmpty())
        <section class="block">
            <h2>{{ __('Planned maintenance') }}</h2>
            @foreach ($maintenances as $maintenance)
                @include('status.partials.maintenance', ['maintenance' => $maintenance])
            @endforeach
        </section>
    @endif

    @if ($view === 'normal')
        @foreach ($groups as $group)
            <section class="block group">
                <details @if (! $group->collapsed || $group->components->contains(fn ($c) => ! $c->status->isOperational())) open @endif>
                    <summary>
                        <h2>{{ $group->name }}</h2>
                        @php($groupStatus = \App\Enums\ComponentStatus::worst($group->components->pluck('status')))
                        <span class="pill is-{{ $groupStatus->css() }}">{{ $groupStatus->getLabel() }}</span>
                    </summary>
                    @if ($group->description)<p class="muted">{{ $group->description }}</p>@endif
                    <div class="components">
                        @foreach ($group->components as $component)
                            @include('status.partials.component', ['component' => $component])
                        @endforeach
                    </div>
                </details>
            </section>
        @endforeach

        @if ($ungrouped->isNotEmpty())
            <section class="block group">
                <div class="components">
                    @foreach ($ungrouped as $component)
                        @include('status.partials.component', ['component' => $component])
                    @endforeach
                </div>
            </section>
        @endif
    @else
        {{-- Compact / micro: every component in one grid, no grouping. --}}
        <section class="block">
            <div class="components grid">
                @foreach ($components as $component)
                    @include('status.partials.component', ['component' => $component, 'view' => $view])
                @endforeach
            </div>
        </section>
    @endif

    @if ($view === 'normal')
        <section class="block">
            <h2>{{ __('Past incidents') }}</h2>
            @forelse ($recentIncidents as $incident)
                @include('status.partials.incident', ['incident' => $incident, 'limit' => 1])
            @empty
                <p class="muted">{{ __('No incidents in the last :days days.', ['days' => $historyDays]) }}</p>
            @endforelse
            <p><a href="{{ route('history') }}">{{ __('Full incident history') }} →</a></p>
        </section>

        @if (! empty($externalLinks))
            <section class="block">
                <h2>{{ __('Other status pages') }}</h2>
                <ul class="links">
                    @foreach ($externalLinks as $link)
                        <li>
                            <a href="{{ $link['url'] }}" target="_blank" rel="noopener">{{ $link['name'] }}</a>
                            @if (! empty($link['description']))<span class="muted"> – {{ $link['description'] }}</span>@endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endif
</div>
@endsection

@section('footer')
    @foreach (['normal' => __('Normal view'), 'compact' => __('Compact view'), 'micro' => __('Micro view')] as $key => $label)
        @if ($key !== $view)
            <a href="{{ $key === 'normal' ? route('status') : route('status', ['view' => $key]) }}">{{ $label }}</a>
        @endif
    @endforeach
@endsection
