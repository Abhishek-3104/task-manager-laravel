<?php

use Illuminate\Support\Facades\Schedule;

/*
| Ask the Django service to mark past-due tasks as OVERDUE.
| Requires the scheduler: `* * * * * php artisan schedule:run` (or `schedule:work`).
*/
Schedule::command('tasks:sync-overdue')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
