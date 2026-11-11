<fieldset class="checkboxes">
    <legend>{{ __('Components') }} <small class="muted">({{ __('leave all unticked to hear about everything') }})</small></legend>
    @foreach ($components->groupBy(fn ($c) => $c->group?->name ?? '') as $groupName => $items)
        @if ($groupName !== '')<p class="group-label">{{ $groupName }}</p>@endif
        @foreach ($items as $component)
            <label>
                <input type="checkbox" name="components[]" value="{{ $component->id }}" @checked(in_array($component->id, $selected ?? [], true))>
                {{ $component->name }}
            </label>
        @endforeach
    @endforeach
</fieldset>
