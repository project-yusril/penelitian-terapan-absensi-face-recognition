<?php

namespace App\Console\Commands;

use App\Models\Jadwal;
use App\Models\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendAttendanceReminder extends Command
{
    protected $signature = 'attendance:send-reminder';

    protected $description = 'Kirim reminder absen 15 menit sebelum kelas dimulai';

    public function handle(): int
    {
        $hariIni = Carbon::now()->locale('id')->isoFormat('dddd');
        $targetTime = Carbon::now()->addMinutes(15)->format('H:i:s');
        $currentTime = Carbon::now()->format('H:i:s');

        // Cari jadwal yang mulai dalam 15 menit ke depan
        $jadwals = Jadwal::with(['mataKuliah.mahasiswas'])
            ->where('hari', $hariIni)
            ->where('status', 'aktif')
            ->where('jam_mulai', '>', $currentTime)
            ->where('jam_mulai', '<=', $targetTime)
            ->get();

        $totalSent = 0;

        foreach ($jadwals as $jadwal) {
            $mk = $jadwal->mataKuliah;
            if (! $mk) {
                continue;
            }

            $mahasiswas = $mk->mahasiswas;

            foreach ($mahasiswas as $mhs) {
                // Cek apakah sudah pernah kirim reminder hari ini untuk jadwal ini
                $alreadySent = Notification::where('user_id', $mhs->id)
                    ->where('type', 'attendance_reminder')
                    ->whereDate('created_at', today())
                    ->whereJsonContains('data->jadwal_id', $jadwal->id)
                    ->exists();

                if ($alreadySent) {
                    continue;
                }

                Notification::create([
                    'user_id' => $mhs->id,
                    'type' => 'attendance_reminder',
                    'title' => 'Reminder: Kelas akan dimulai',
                    'body' => "{$mk->nama} ({$mk->kode_mk}) akan dimulai 15 menit lagi di {$jadwal->ruangan}. Jangan lupa absen!",
                    'data' => [
                        'jadwal_id' => $jadwal->id,
                        'mata_kuliah_id' => $mk->id,
                        'jam_mulai' => $jadwal->jam_mulai,
                        'ruangan' => $jadwal->ruangan,
                    ],
                ]);

                $totalSent++;
            }
        }

        $this->info("Reminder terkirim: {$totalSent} notifikasi untuk {$jadwals->count()} jadwal.");

        return self::SUCCESS;
    }
}
