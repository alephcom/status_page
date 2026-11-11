<?php

namespace App\Console\Commands;

use App\Services\MetricPoller;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('statuspage:poll-metrics')]
#[Description('Fetch recent Zabbix history for the graphs shown under components')]
class PollMetrics extends Command
{
    public function handle(MetricPoller $poller): int
    {
        $result = $poller->poll();

        $this->info("Metrics polled: {$result['ok']} ok, {$result['failed']} failed.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
