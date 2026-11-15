@php
    $locale = app()->getLocale();
    $duration = fn (int $s) => $s < 60
        ? trans_choice(':count second|:count seconds', $s)
        : \Carbon\CarbonInterval::seconds($s)->cascade()->locale($locale)->forHumans(['short' => true, 'parts' => 2]);
    $pct = $row?->uptimePercent();
@endphp
<div class="day-detail">
    <div class="day-detail-head">
        <strong>{{ $component->name }}</strong>
        <span class="muted">{{ $day->locale($locale)->isoFormat('LL') }}</span>
    </div>

    @if (! $row || $row->trackedSeconds() === 0)
        <p class="muted">{{ __('No data') }}</p>
    @else
        <p class="day-detail-uptime">{{ __(':pct% uptime', ['pct' => rtrim(rtrim(number_format($pct, 3), '0'), '.')]) }}</p>

        <ul class="day-breakdown">
            @foreach ($row->segments(0) as $segment)
                <li>
                    <span class="dot is-{{ $segment['status']->css() }}"></span>
                    {{ $segment['status']->getLabel() }}
                    <span class="muted">{{ $duration($segment['seconds']) }}</span>
                </li>
            @endforeach
        </ul>

        @if (count($periods) > 0)
            @php($span = max(1, $periods[array_key_last($periods)]['to']->getTimestamp() - $periods[0]['from']->getTimestamp()))
            <div class="day-timeline" aria-hidden="true">
                @foreach ($periods as $p)
                    <span class="is-{{ $p['status']->css() }}" style="width: {{ round(100 * ($p['to']->getTimestamp() - $p['from']->getTimestamp()) / $span, 3) }}%"></span>
                @endforeach
            </div>
        @endif

        @php($issues = array_filter($periods, fn ($p) => ! $p['status']->isOperational()))
        @if ($issues)
            <ul class="day-periods">
                @foreach ($issues as $p)
                    <li>
                        <span class="dot is-{{ $p['status']->css() }}"></span>
                        <span class="day-period-time">@include('status.partials.time', ['at' => $p['from'], 'format' => 'time'])– @include('status.partials.time', ['at' => $p['to'], 'format' => 'time'])</span>
                        <span>{{ $p['status']->getLabel() }}</span>
                        <span class="muted">{{ $duration($p['to']->getTimestamp() - $p['from']->getTimestamp()) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif

    @if ($incidents->isNotEmpty())
        <h4>{{ __('Incidents') }}</h4>
        <ul class="day-links">
            @foreach ($incidents as $incident)
                <li><a href="{{ route('incidents.show', $incident) }}">{{ $incident->title }}</a></li>
            @endforeach
        </ul>
    @endif

    @if ($maintenances->isNotEmpty())
        <h4>{{ __('Planned maintenance') }}</h4>
        <ul class="day-links">
            @foreach ($maintenances as $m)
                <li>{{ $m->title }}</li>
            @endforeach
        </ul>
    @endif
</div>
