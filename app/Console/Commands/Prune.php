<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Component;
use App\Models\MetricPoint;
use App\Models\Subscriber;
use App\Models\ZabbixProblem;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('statuspage:prune {--dry-run : Only report what would be deleted}')]
#[Description('Delete old status changes, metric samples, resolved problems, audit entries and unconfirmed subscriptions')]
class Prune extends Command
{
    public function handle(): int
    {
        $days = config('statuspage.retention');
        $dry = (bool) $this->option('dry-run');

        // Daily uptime rows are kept; the raw log is only needed to rebuild
        // recent days. Keep at least the uptime window, plus each component's
        // last change before the cutoff as the baseline status.
        $cutoff = now()->subDays(max((int) $days['status_changes'], (int) config('statuspage.uptime_days') + 2));
        $changes = 0;

        Component::query()->each(function (Component $c) use ($cutoff, $dry, &$changes) {
            $baseline = $c->statusChanges()->where('changed_at', '<', $cutoff)->orderByDesc('changed_at')->orderByDesc('id')->value('id');

            if ($baseline) {
                $query = $c->statusChanges()->where('changed_at', '<', $cutoff)->where('id', '!=', $baseline);
                $changes += $dry ? $query->count() : $query->delete();
            }
        });

        $counts = [
            'status changes' => $changes,
            'metric samples' => $this->delete(MetricPoint::query()->where('recorded_at', '<', now()->subDays((int) $days['metric_points'])), $dry),
            'resolved monitoring problems' => $this->delete(ZabbixProblem::query()->whereNotNull('resolved_at')->where('resolved_at', '<', now()->subDays((int) $days['resolved_problems'])), $dry),
            'audit log entries' => $this->delete(AuditLog::query()->where('created_at', '<', now()->subDays((int) $days['audit_log'])), $dry),
            'unconfirmed subscriptions' => $this->delete(Subscriber::query()->whereNull('verified_at')->where('created_at', '<', now()->subDays((int) $days['unverified_subscribers'])), $dry),
        ];

        foreach ($counts as $what => $n) {
            $this->line(($dry ? 'Would delete' : 'Deleted')." {$n} {$what}.");
        }

        return self::SUCCESS;
    }

    private function delete($query, bool $dry): int
    {
        return $dry ? $query->count() : $query->delete();
    }
}
