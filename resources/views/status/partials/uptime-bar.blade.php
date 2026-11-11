<div class="uptime" aria-label="Uptime over the last {{ count($component->uptime_history) }} days">
    <div class="bars">
        @foreach ($component->uptime_history as $day)
            @php($worst = $day['row']?->worstStatus())
            @php($pct = $day['row']?->uptimePercent())
            <span class="bar {{ $worst ? 'is-'.$worst->css() : 'is-nodata' }}"
                  title="{{ $day['date']->toFormattedDateString() }}: {{ $pct === null ? 'No data' : rtrim(rtrim(number_format($pct, 2), '0'), '.').'% uptime' }}@if ($worst && ! $worst->isOperational()) · worst: {{ $worst->getLabel() }}@endif"></span>
        @endforeach
    </div>
    <div class="uptime-legend">
        <span>{{ count($component->uptime_history) }} days ago</span>
        <span>{{ $component->uptime_percent === null ? 'No data' : $component->uptime_percent.'% uptime' }}</span>
        <span>Today</span>
    </div>
</div>
