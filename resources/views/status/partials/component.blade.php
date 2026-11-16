<div class="component">
    <div class="component-head">
        <span class="component-name">
            @if ($component->link)
                <a href="{{ $component->link }}" rel="noopener" target="_blank">{{ $component->name }}</a>
            @else
                {{ $component->name }}
            @endif
            @if ($component->description && ($view ?? 'normal') === 'normal')
                <span class="help" title="{{ $component->description }}">?</span>
            @endif
            @if ($component->isPrivate())
                <span class="tag" title="{{ __('Only visible to staff and internal networks') }}">{{ __('Internal') }}</span>
            @endif
        </span>
        <span class="pill is-{{ $component->status->css() }}">{{ $component->status->getLabel() }}</span>
    </div>
    @if (($view ?? 'normal') !== 'micro')
        @include('status.partials.uptime-bar', ['component' => $component])
    @endif
    @if (($view ?? 'normal') === 'normal' && ($showProblems ?? false) && $component->relationLoaded('activeProblems') && $component->activeProblems->isNotEmpty())
        <div class="problems" title="{{ __('Only visible to signed-in staff') }}">
            <div class="problems-head">
                <span class="tag">{{ __('Staff only') }}</span>
                <span>{{ __('Monitoring problems') }} ({{ $component->activeProblems->count() }})</span>
                <a href="{{ \App\Filament\Resources\Incidents\IncidentResource::createUrlFor([$component->id], draft: true) }}">{{ __('Draft incident') }}</a>
            </div>
            <ul>
                @foreach ($component->activeProblems as $problem)
                    <li class="sev-{{ $problem->severity }}">
                        {{ $problem->name }}
                        <span class="muted">· {{ $problem->severityLabel() }} · {{ __('since') }} @include('status.partials.time', ['at' => $problem->started_at])</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
    @if (($view ?? 'normal') === 'normal')
        @foreach ($component->charts ?? [] as $chart)
            @include('status.partials.metric-chart', ['chart' => $chart])
        @endforeach
    @endif
</div>
