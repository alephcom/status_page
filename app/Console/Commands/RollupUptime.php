<?php

namespace App\Console\Commands;

use App\Models\Component;
use App\Services\UptimeCalculator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('statuspage:rollup-uptime {--days=2 : Number of days back to recompute, including today}')]
#[Description('Recompute daily uptime figures from the status change log')]
class RollupUptime extends Command
{
    public function handle(UptimeCalculator $calculator): int
    {
        $days = max(1, (int) $this->option('days'));
        $from = today()->subDays($days - 1);

        Component::query()->each(fn (Component $c) => $calculator->rollup($c, $from, today()));

        $this->info("Uptime recomputed for the last {$days} day(s).");

        return self::SUCCESS;
    }
}
