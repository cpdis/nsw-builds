<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('nsw:sync')
    ->dailyAt('03:00')
    ->timezone('Australia/Sydney')
    ->withoutOverlapping()
    ->onOneServer();
