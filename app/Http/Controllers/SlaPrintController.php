<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\SlaReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Printable SLA report (the browser's print dialog saves it as PDF). */
class SlaPrintController extends Controller
{
    public function __invoke(Request $request, SlaReport $report): View
    {
        abort_unless($request->user()?->role !== null, 403);

        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))
            ? CarbonImmutable::createFromFormat('!Y-m', $request->query('month'))
            : now()->toImmutable()->subMonthNoOverflow()->startOfMonth();

        return view('reports.sla-print', [
            'siteName' => Setting::get('site_name'),
            'month' => $month,
            'rows' => $report->forMonth($month),
        ]);
    }
}
