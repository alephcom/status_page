<x-filament-panels::page>
    <div style="display: flex; align-items: center; gap: .75rem">
        <label for="sla-month" style="font-weight: 500">Month</label>
        <select id="sla-month" wire:model.live="month" style="border: 1px solid rgba(127,127,127,.35); border-radius: .5rem; padding: .35rem 2rem .35rem .6rem; background: transparent;">
            @foreach ($this->months() as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    @include('reports.sla-table', ['rows' => $this->rows()])

    <p style="font-size: .875rem; opacity: .7">
        Uptime counts major outages as down and partial outages as half down; degraded performance and maintenance count as up.
        Set targets on each component (SLA target). Days without data are left out.
    </p>
</x-filament-panels::page>
