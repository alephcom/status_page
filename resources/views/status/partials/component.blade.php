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
        </span>
        <span class="pill is-{{ $component->status->css() }}">{{ $component->status->getLabel() }}</span>
    </div>
    @if (($view ?? 'normal') !== 'micro')
        @include('status.partials.uptime-bar', ['component' => $component])
    @endif
</div>
