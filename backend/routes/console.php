<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduler: Auto-close attendance setiap menit (cek jadwal yang sudah lewat)
Schedule::command('attendance:auto-close')->everyMinute()->withoutOverlapping()->onOneServer();

// Scheduler: Mark absent setiap menit. Command hanya memproses jadwal yang
// jam selesainya (plus toleransi pulang) sudah lewat, sehingga ALPHA muncul
// segera setelah kelas berakhir tanpa menunggu akhir hari.
Schedule::command('attendance:mark-absent')->everyMinute()->withoutOverlapping()->onOneServer();

// Scheduler: Reminder absen 15 menit sebelum kelas (cek setiap 5 menit)
Schedule::command('attendance:send-reminder')->everyFiveMinutes();

Schedule::command('notifications:process-outbox')->everyMinute()->withoutOverlapping()->onOneServer();

// Scheduler: Backup database harian jam 02:00 (rotasi 14 hari).
Schedule::command('backup:database')->dailyAt('02:00')->withoutOverlapping();

// Development lokal: `php artisan serve:all` menjalankan dev server DAN
// scheduler sekaligus dari satu proses (lihat app/Console/Commands/ServeAll.php).
