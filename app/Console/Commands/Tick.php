<?php

namespace App\Console\Commands;

use App\Services\IncidentManager;
use App\Services\MaintenanceManager;
use App\Services\MonitoringWatchdog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('statuspage:tick')]
#[Description('Time-based work: maintenance reminders and start/end notices, recurring maintenance, scheduled incident updates and stale-data alerts')]
class Tick extends Command
{
    public function handle(MaintenanceManager $maintenance, IncidentManager $incidents, MonitoringWatchdog $watchdog): int
    {
        $failed = false;

        // Each step runs even if an earlier one fails.
        foreach ([
            'maintenance' => fn () => $maintenance->tick(),
            'scheduled updates' => fn () => $incidents->publishDueUpdates(),
            'watchdog' => fn () => $watchdog->check(),
        ] as $name => $step) {
            try {
                $step();
            } catch (Throwable $e) {
                $failed = true;
                report($e);
                $this->error("{$name}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
