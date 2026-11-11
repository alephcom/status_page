<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('statuspage:poll')
    ->everyThirtySeconds()
    ->withoutOverlapping(5);

Schedule::command('statuspage:rollup-uptime')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
