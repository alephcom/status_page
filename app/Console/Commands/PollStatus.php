<?php

namespace App\Console\Commands;

use App\Services\StatusPoller;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('statuspage:poll')]
#[Description('Fetch current problems from Zabbix and update component statuses')]
class PollStatus extends Command
{
    public function handle(StatusPoller $poller): int
    {
        $result = $poller->poll();

        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        $this->info("Poll finished, {$result['changed']} component(s) changed status.");

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
