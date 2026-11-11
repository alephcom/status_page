<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>SLA report {{ $month->format('F Y') }} – {{ $siteName }}</title>
    <style>
        body { font: 13px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #1d2330; margin: 2rem; }
        h1 { font-size: 1.4rem; margin: 0 0 .25rem; }
        .muted, .text-gray-500 { color: #6b7280; }
        table { border-collapse: collapse; width: 100%; margin-top: 1rem; }
        th, td { padding: .35rem .5rem; text-align: left; }
        thead th { border-bottom: 2px solid #1d2330; }
        tbody tr { border-top: 1px solid #e3e6eb; }
        .text-right { text-align: right; }
        .tabular-nums { font-variant-numeric: tabular-nums; }
        .sla-met { color: #1f7a45; font-weight: 600; }
        .sla-missed { color: #b42323; font-weight: 600; }
        @media print { .no-print { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <p class="no-print"><button onclick="window.print()">Print or save as PDF</button></p>
    <h1>{{ $siteName }} – SLA report</h1>
    <p class="muted">{{ $month->format('F Y') }} · generated {{ now()->toDayDateTimeString() }} ({{ config('app.timezone') }})</p>
    @include('reports.sla-table', ['rows' => $rows])
    <p class="muted">Uptime counts major outages as down and partial outages as half down; degraded performance and maintenance count as up.</p>
</body>
</html>
