<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('libraries:scan-due')->everyMinute()->withoutOverlapping(5);

Schedule::command('transcodes:reconcile --apply')->everyMinute()->withoutOverlapping(5);
