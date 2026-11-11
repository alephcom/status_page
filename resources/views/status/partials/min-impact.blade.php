<label>{{ __('Incidents to hear about') }}
    <select name="min_impact">
        <option value="" @selected(blank($selected ?? null))>{{ __('All incidents') }}</option>
        <option value="minor" @selected(($selected ?? null) === 'minor')>{{ __('Minor and more severe') }}</option>
        <option value="major" @selected(($selected ?? null) === 'major')>{{ __('Major and critical only') }}</option>
        <option value="critical" @selected(($selected ?? null) === 'critical')>{{ __('Critical only') }}</option>
    </select>
</label>
<p class="muted small">{{ __('Maintenance notices are always sent.') }}</p>
