<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('filemax:cleanup')->daily()->withoutOverlapping();
Schedule::command('filemax:send-expiry-reminders')->everyThreeHours()->withoutOverlapping();
// Daily, alongside filemax:cleanup, so scale-to-zero environments aren't woken just for this; access already stops at expiry.
Schedule::command('filemax:purge-secrets')->daily()->withoutOverlapping();
