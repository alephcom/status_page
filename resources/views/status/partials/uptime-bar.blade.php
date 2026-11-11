<div class="uptime" aria-label="{{ __('Uptime over the last :days days', ['days' => count($component->uptime_history)]) }}">
    <div class="bars">
        @foreach ($component->uptime_history as $day)
            @php($worst = $day['row']?->worstStatus())
            @php($pct = $day['row']?->uptimePercent())
            <span class="bar {{ $worst ? 'is-'.$worst->css() : 'is-nodata' }}"
                  title="{{ $day['date']->locale(app()->getLocale())->isoFormat('ll') }}: {{ $pct === null ? __('No data') : __(':pct% uptime', ['pct' => rtrim(rtrim(number_format($pct, 2), '0'), '.')]) }}@if ($worst && ! $worst->isOperational()) · {{ __('worst') }}: {{ $worst->getLabel() }}@endif"></span>
        @endforeach
    </div>
    <div class="uptime-legend">
        <span>{{ __(':days days ago', ['days' => count($component->uptime_history)]) }}</span>
        <span>{{ $component->uptime_percent === null ? __('No data') : __(':pct% uptime', ['pct' => $component->uptime_percent]) }}</span>
        <span>{{ __('Today') }}</span>
    </div>
</div>
