<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('filemax:cleanup')->daily()->withoutOverlapping();
Schedule::command('filemax:send-expiry-reminders')->everyThreeHours()->withoutOverlapping();
