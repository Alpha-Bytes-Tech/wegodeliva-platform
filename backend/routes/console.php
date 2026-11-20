<?php

use Illuminate\Support\Facades\Schedule;

// Background jobs (spec 04 §4.6). Laravel Cloud runs the scheduler.
Schedule::command('orders:release-scheduled')->everyMinute()->withoutOverlapping();
