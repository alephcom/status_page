{{-- One series: the heading names it, so no legend. Hover shows the value at the pointer; the table is the non-visual view. --}}
@php($id = 'metric-'.$chart->metric->id)
<figure class="metric" aria-labelledby="{{ $id }}-title">
    <figcaption class="metric-head" id="{{ $id }}-title">
        <span>{{ $chart->metric->name }} <span class="muted">· {{ __('last :hours hours', ['hours' => config('statuspage.metrics.hours')]) }}</span></span>
        <span class="metric-now">{{ $chart->metric->format($chart->latest()) }}</span>
    </figcaption>
    <div class="metric-plot" data-chart='@json($chart->readout())'>
        <svg viewBox="0 0 {{ \App\Support\MetricChart::WIDTH }} {{ \App\Support\MetricChart::HEIGHT }}" preserveAspectRatio="none" role="img"
             aria-label="{{ __(':name: average :avg, minimum :min, maximum :max', ['name' => $chart->metric->name, 'avg' => $chart->metric->format($chart->average()), 'min' => $chart->metric->format($chart->min()), 'max' => $chart->metric->format($chart->max())]) }}">
            <line class="metric-base" x1="0" y1="{{ \App\Support\MetricChart::HEIGHT - 0.5 }}" x2="{{ \App\Support\MetricChart::WIDTH }}" y2="{{ \App\Support\MetricChart::HEIGHT - 0.5 }}" vector-effect="non-scaling-stroke"/>
            <path class="metric-area" d="{{ $chart->areaPath() }}"/>
            <path class="metric-line" d="{{ $chart->linePath() }}" vector-effect="non-scaling-stroke"/>
        </svg>
        <div class="metric-cursor" hidden></div>
        <div class="metric-tip" role="status" hidden></div>
    </div>
    <div class="metric-legend muted">
        <span>{{ __('Avg') }} {{ $chart->metric->format($chart->average()) }}</span>
        <span>{{ __('Min') }} {{ $chart->metric->format($chart->min()) }} · {{ __('Max') }} {{ $chart->metric->format($chart->max()) }}</span>
    </div>
    <details class="metric-table">
        <summary>{{ __('Show as table') }}</summary>
        <table>
            <thead><tr><th scope="col">{{ __('Hour') }}</th><th scope="col">{{ __('Average') }}</th></tr></thead>
            <tbody>
                @foreach ($chart->hourly() as $row)
                    <tr><td>@include('status.partials.time', ['at' => $row['hour']])</td><td>{{ $row['value'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </details>
</figure>
