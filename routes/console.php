<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('statuspage:poll')
    ->everyThirtySeconds()
    ->withoutOverlapping(5);

Schedule::command('statuspage:rollup-uptime')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('statuspage:tick')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('statuspage:poll-metrics')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

Schedule::command('statuspage:prune')
    ->dailyAt('03:17')
    ->withoutOverlapping();
