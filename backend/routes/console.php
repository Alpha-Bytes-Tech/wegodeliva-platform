<?php

use Illuminate\Support\Facades\Schedule;

// Background jobs (spec 04 §4.6). Laravel Cloud runs the scheduler.
Schedule::command('orders:release-scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('payments:expire')->everyMinute()->withoutOverlapping();
Schedule::command('payments:reconcile')->dailyAt('06:00')->timezone('Africa/Lagos');
Schedule::command('orders:monitor')->everyMinute()->withoutOverlapping();
Schedule::command('payouts:sync')->everyFiveMinutes()->withoutOverlapping();
