<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduler: Auto-close attendance setiap menit (cek jadwal yang sudah lewat)
Schedule::command('attendance:auto-close')->everyMinute()->withoutOverlapping()->onOneServer();

// Scheduler: Mark absent setiap hari jam 22:00
Schedule::command('attendance:mark-absent')->dailyAt('22:00')->withoutOverlapping()->onOneServer();

// Scheduler: Reminder absen 15 menit sebelum kelas (cek setiap 5 menit)
Schedule::command('attendance:send-reminder')->everyFiveMinutes();

Schedule::command('notifications:process-outbox')->everyMinute()->withoutOverlapping()->onOneServer();

// Scheduler: Backup database harian jam 02:00 (rotasi 14 hari).
Schedule::command('backup:database')->dailyAt('02:00')->withoutOverlapping();
