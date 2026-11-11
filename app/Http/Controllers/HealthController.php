<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\StatusPageData;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * For monitoring the status page itself. 200 when everything is fine, 503
 * when the database is unreachable, the scheduler has stopped polling, or
 * monitoring data is stale. (/up only says that PHP answers.)
 */
class HealthController extends Controller
{
    public function __invoke(StatusPageData $data): JsonResponse
    {
        $checks = [];

        try {
            DB::connection()->getPdo();
            $checks['database'] = ['ok' => true];
        } catch (Throwable $e) {
            return response()->json(['status' => 'fail', 'checks' => ['database' => ['ok' => false, 'error' => 'unreachable']]], 503);
        }

        $lastPoll = Setting::get('last_poll_at');
        $pollAge = $lastPoll ? (int) CarbonImmutable::parse($lastPoll)->diffInSeconds(now(), true) : null;
        $checks['scheduler'] = [
            'ok' => $pollAge !== null && $pollAge <= max(120, (int) config('statuspage.stale_after')),
            'last_poll_at' => $lastPoll,
            'age_seconds' => $pollAge,
        ];

        $stale = $data->staleness();
        $checks['monitoring'] = [
            'ok' => $stale === null,
            'last_ok_at' => Setting::get('zabbix_last_ok_at'),
            'error' => $stale === null ? null : Setting::get('zabbix_last_error'),
        ];

        if (Schema::hasTable('jobs')) {
            $checks['queue'] = [
                'ok' => true,
                'pending' => DB::table('jobs')->count(),
                'failed' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
            ];
        }

        $ok = collect($checks)->every(fn ($c) => $c['ok']);

        return response()->json(['status' => $ok ? 'ok' : 'fail', 'checks' => $checks], $ok ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
