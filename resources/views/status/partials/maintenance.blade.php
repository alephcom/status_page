<article class="maintenance" id="maintenance-{{ $maintenance->id }}">
    <h3>
        {{ $maintenance->title }}
        <span class="pill is-maintenance">{{ $maintenance->status->getLabel() }}</span>
    </h3>
    <p class="when">
        @include('status.partials.time', ['at' => $maintenance->scheduled_start]) –
        @include('status.partials.time', ['at' => $maintenance->scheduled_end])
    </p>
    @if ($maintenance->components->isNotEmpty())
        <p class="muted">Affects: {{ $maintenance->components->pluck('name')->join(', ') }}</p>
    @endif
    @if ($maintenance->message && ($view ?? 'normal') !== 'micro')
        <div class="markdown">{!! Str::markdown($maintenance->message, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
    @endif
</article>
