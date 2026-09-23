<?php

namespace App\Listeners;

use App\Contracts\ActivityLoggable;
use App\Models\Activity;

class LogActivity
{
    public function handle(ActivityLoggable $event): void
    {
        Activity::create($event->toActivityLog());
    }
}
