<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Con `schedule:run` una vez por minuto esto corre una vez por minuto. Cada 5 s solo con schedule:work.
Schedule::command('monitor:run')->everyFiveSeconds()->withoutOverlapping();
