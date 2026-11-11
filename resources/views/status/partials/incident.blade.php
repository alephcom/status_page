<article class="incident is-{{ $incident->impact->value }}">
    <h3><a href="{{ route('incidents.show', $incident) }}">{{ $incident->title }}</a></h3>
    @if ($incident->components->isNotEmpty())
        <p class="muted">Affects: {{ $incident->components->pluck('name')->join(', ') }}</p>
    @endif
    @foreach ($incident->updates->take($limit ?? 100) as $update)
        <div class="update">
            <strong>{{ $update->status->getLabel() }}</strong> –
            <span class="markdown">{!! Str::markdown($update->message, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</span>
            <small class="muted">@include('status.partials.time', ['at' => $update->created_at])</small>
        </div>
    @endforeach
</article>
