<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('libraries:scan-due')->everyMinute()->withoutOverlapping(5);
