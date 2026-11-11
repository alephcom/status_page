<style>
    .sla-table table { border-collapse: collapse; width: 100%; font-size: .875rem; }
    .sla-table th, .sla-table td { padding: .5rem .75rem; text-align: left; white-space: nowrap; }
    .sla-table thead th { font-weight: 600; border-bottom: 1px solid rgba(127, 127, 127, .35); }
    .sla-table tbody tr { border-top: 1px solid rgba(127, 127, 127, .2); }
    .sla-table .num { text-align: right; font-variant-numeric: tabular-nums; }
    .sla-table .muted { opacity: .65; }
    .sla-table .sla-met { color: #1f7a45; font-weight: 600; }
    .sla-table .sla-missed { color: #b42323; font-weight: 600; }
</style>
<div class="sla-table" style="overflow-x: auto">
    <table>
        <thead>
            <tr>
                <th>Component</th>
                <th class="num">Uptime</th>
                <th class="num">Target</th>
                <th>SLA</th>
                <th class="num">Major outage</th>
                <th class="num">Partial outage</th>
                <th class="num">Maintenance</th>
                <th class="num">Days with data</th>
            </tr>
        </thead>
        <tbody>
            @php($minutes = fn (int $m) => $m >= 60 ? intdiv($m, 60).' h '.($m % 60).' min' : $m.' min')
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['component']->name }} @if ($row['component']->group)<span class="muted">· {{ $row['component']->group->name }}</span>@endif</td>
                    <td class="num">{{ $row['uptime'] === null ? '—' : number_format($row['uptime'], 3).' %' }}</td>
                    <td class="num">{{ $row['target'] === null ? '—' : rtrim(rtrim(number_format($row['target'], 3), '0'), '.').' %' }}</td>
                    <td>
                        @if ($row['met'] === true) <span class="sla-met">✓ Met</span>
                        @elseif ($row['met'] === false) <span class="sla-missed">✗ Missed</span>
                        @else <span class="muted">—</span>
                        @endif
                    </td>
                    <td class="num">{{ $minutes($row['downtime_minutes']) }}</td>
                    <td class="num">{{ $minutes($row['partial_minutes']) }}</td>
                    <td class="num">{{ $minutes($row['maintenance_minutes']) }}</td>
                    <td class="num">{{ $row['days'] }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="p-2 text-gray-500">No components.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
