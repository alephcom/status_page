<div class="uptime" aria-label="{{ __('Uptime over the last :days days', ['days' => count($component->uptime_history)]) }}">
    <div class="bars">
        @foreach ($component->uptime_history as $day)
            @php
                $row = $day['row'];
                $pct = $row?->uptimePercent();
                $segments = $row?->segments() ?? [];
                $title = $day['date']->locale(app()->getLocale())->isoFormat('ll').': '
                    .($pct === null ? __('No data') : __(':pct% uptime', ['pct' => rtrim(rtrim(number_format($pct, 2), '0'), '.')]));
                foreach ($segments as $segment) {
                    if (! $segment['status']->isOperational()) {
                        $title .= ' · '.$segment['status']->getLabel().': '
                            .\Carbon\CarbonInterval::seconds($segment['seconds'])->cascade()
                                ->locale(app()->getLocale())->forHumans(['short' => true, 'parts' => 2]);
                    }
                }
            @endphp
            <a class="bar {{ $segments ? '' : 'is-nodata' }}" title="{{ $title }}" data-day
               href="{{ route('components.day', [$component, $day['date']->toDateString()]) }}">
                @foreach ($segments as $segment)
                    <span class="bar-seg is-{{ $segment['status']->css() }}" style="height: {{ round($segment['share'], 2) }}%"></span>
                @endforeach
            </a>
        @endforeach
    </div>
    <div class="uptime-legend">
        <span>{{ __(':days days ago', ['days' => count($component->uptime_history)]) }}</span>
        <span>{{ $component->uptime_percent === null ? __('No data') : __(':pct% uptime', ['pct' => $component->uptime_percent]) }}</span>
        <span>{{ __('Today') }}</span>
    </div>
</div>
