<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('statements:send --send')
    ->monthlyOn(5, '09:00')
    ->timezone('Africa/Dar_es_Salaam')
    ->when(fn (): bool => config('statements.monthly_email_enabled'))
    ->onOneServer()
    ->withoutOverlapping();
