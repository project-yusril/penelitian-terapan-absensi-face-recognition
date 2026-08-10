<?php

namespace App\Services;

use App\Enums\SpLevel;
use App\Models\AlphaAccumulation;
use App\Models\MataKuliah;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SpDetectionService
{
    protected AlphaAccumulationService $alphaService;

    private ?int $notificationSemesterId = null;

    public function __construct(AlphaAccumulationService $alphaService)
    {
        $this->alphaService = $alphaService;
    }

    /**
     * Evaluasi SP status setelah alpha berubah
     * Dipanggil setiap kali ada perubahan attendance
     */
    public function evaluate(int $userId, ?int $semesterId = null): ?array
    {
        $result = DB::transaction(function () use ($userId, $semesterId): ?array {
            // The user row is the mutex for first-time creation of the canonical
            // user/semester accumulation row.
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $accumulation = $this->alphaService->recalculate($userId, $semesterId);

            if (! $accumulation) {
                return null;
            }

            $accumulation = AlphaAccumulation::whereKey($accumulation->id)->lockForUpdate()->firstOrFail();
            $this->notificationSemesterId = $accumulation->semester_id;
            $totalAlphaMenit = $accumulation->total_alpha_menit;
            $totalAlphaJam = $totalAlphaMenit / 60.0;
            $currentLevel = SpLevel::from($accumulation->sp_status);

            $notifications = [];

            // Cek approaching notifications (belum pernah dikirim)
            $approachingNotifs = $this->checkApproachingNotifications($accumulation, $userId, $totalAlphaJam);
            $notifications = array_merge($notifications, $approachingNotifs);

            // Cek level change notifications (belum pernah dikirim)
            $levelNotifs = $this->checkLevelChangeNotifications($accumulation, $userId, $currentLevel, $totalAlphaJam);
            $notifications = array_merge($notifications, $levelNotifs);

            return [
                'user_id' => $userId,
                'total_alpha_menit' => $totalAlphaMenit,
                'total_alpha_jam' => $totalAlphaJam,
                'sp_status' => $currentLevel->value,
                'notifications_sent' => count($notifications),
            ];
        });
        app(NotificationOutboxService::class)->process();

        return $result;
    }

    /**
     * Cek dan kirim notifikasi approaching (mendekati threshold)
     * Thresholds are in JAM, so totalAlphaJam is used for comparison
     */
    protected function checkApproachingNotifications(AlphaAccumulation $accumulation, int $userId, float $totalAlphaJam): array
    {
        $notifications = [];
        $user = User::find($userId);
        $thresholds = $this->alphaService->getSpThresholds($user->prodi_id);

        foreach ([SpLevel::Sp1, SpLevel::Sp2, SpLevel::Sp3, SpLevel::Do] as $level) {
            $flag = $level->approachingFlag();
            $threshold = $thresholds[$level->value];

            if (! $accumulation->$flag && $totalAlphaJam >= $threshold * 0.8 && $totalAlphaJam < $threshold) {
                $notifications = array_merge($notifications, $this->sendApproachingNotification($user, $level, $totalAlphaJam));
                $accumulation->update([$flag => true]);
            }
        }

        return $notifications;
    }

    /**
     * Cek dan kirim notifikasi level change (masuk SP baru)
     */
    protected function checkLevelChangeNotifications(AlphaAccumulation $accumulation, int $userId, SpLevel $currentLevel, float $totalAlphaJam): array
    {
        $notifications = [];
        $user = User::find($userId);

        $flag = $currentLevel->notificationFlag();

        if (! $flag || $accumulation->$flag) {
            return [];
        }

        // Kirim notifikasi level change
        $notifications = $this->sendLevelChangeNotification($user, $currentLevel, $totalAlphaJam);
        $accumulation->update([$flag => true]);

        return $notifications;
    }

    /**
     * Kirim notifikasi mendekati SP ke recipients yang sesuai
     */
    protected function sendApproachingNotification(User $user, SpLevel $level, float $totalAlphaJam): array
    {
        $notifications = [];
        $urgent = $level === SpLevel::Do;
        $prefix = $urgent ? '[URGENT] ' : '';
        $recipients = $this->getApproachingRecipients($level, $user);
        $totalAlphaJamFormatted = round($totalAlphaJam, 1);
        $label = $level->label();

        // Notif ke mahasiswa
        $notifications[] = $this->createNotification(
            $user->id,
            'sp_warning',
            "{$prefix}Peringatan: Anda mendekati {$label}",
            "Total alpha Anda saat ini: {$totalAlphaJamFormatted} jam. Segera perbaiki kehadiran Anda.",
            ['level' => $level->approachingCode(), 'total_alpha_jam' => $totalAlphaJam, 'urgent' => $urgent]
        );

        // Notif ke recipients lain
        foreach ($recipients as $recipient) {
            $notifications[] = $this->createNotification(
                $recipient->id,
                'sp_warning',
                "{$prefix}Mahasiswa mendekati {$label}",
                "{$user->nama} ({$user->nim}) mendekati {$label}. Total alpha: {$totalAlphaJamFormatted} jam.",
                ['mahasiswa_id' => $user->id, 'level' => $level->approachingCode(), 'urgent' => $urgent]
            );
        }

        return $notifications;
    }

    /**
     * Kirim notifikasi saat level SP berubah
     */
    protected function sendLevelChangeNotification(User $user, SpLevel $newLevel, float $totalAlphaJam): array
    {
        $notifications = [];
        $urgent = $newLevel->isUrgent();
        $prefix = $urgent ? '[URGENT] ' : '';
        $totalAlphaJamFormatted = round($totalAlphaJam, 1);
        $label = $newLevel->label();

        // Notif ke mahasiswa
        $notifications[] = $this->createNotification(
            $user->id,
            'sp_issued',
            "{$prefix}Status SP Anda berubah menjadi {$label}",
            "Total alpha: {$totalAlphaJamFormatted} jam. Segera temui dosen pembimbing akademik Anda.",
            ['level' => $newLevel->value, 'total_alpha_jam' => $totalAlphaJam, 'urgent' => $urgent]
        );

        // Notif ke recipients sesuai level
        $recipients = $this->getLevelChangeRecipients($newLevel, $user);
        foreach ($recipients as $recipient) {
            $notifications[] = $this->createNotification(
                $recipient->id,
                'sp_issued',
                "{$prefix}Mahasiswa masuk {$label}",
                "{$user->nama} ({$user->nim}) dari prodi {$user->prodi?->nama} masuk {$label}. Total alpha: {$totalAlphaJamFormatted} jam.",
                ['mahasiswa_id' => $user->id, 'level' => $newLevel->value, 'urgent' => $urgent]
            );
        }

        // Notif ke orang tua (semua level)
        $parents = $user->parents;
        foreach ($parents as $parent) {
            $notifications[] = $this->createNotification(
                $parent->id,
                'sp_issued',
                "{$prefix}Anak Anda masuk {$label}",
                "{$user->nama} ({$user->nim}) mendapat {$label}. Total alpha: {$totalAlphaJamFormatted} jam.",
                ['mahasiswa_id' => $user->id, 'level' => $newLevel->value, 'urgent' => $urgent]
            );
        }

        return $notifications;
    }

    /**
     * Get recipients untuk notifikasi approaching berdasarkan level
     */
    protected function getApproachingRecipients(SpLevel $level, User $mahasiswa): array
    {
        $recipients = [];

        switch ($level) {
            case SpLevel::Sp1:
                // Approaching SP1: hanya mahasiswa (sudah ditangani di atas)
                break;

            case SpLevel::Sp2:
                // Approaching SP2: + Admin Prodi + Kaprodi
                $kaprodi = User::whereHas('roles', fn ($q) => $q->where('name', 'kaprodi'))
                    ->where('prodi_id', $mahasiswa->prodi_id)
                    ->get();
                $adminProdi = User::whereHas('roles', fn ($q) => $q->where('name', 'admin_prodi'))
                    ->where('prodi_id', $mahasiswa->prodi_id)
                    ->get();
                $recipients = array_merge($recipients, $kaprodi->all(), $adminProdi->all());
                break;

            case SpLevel::Sp3:
                // Approaching SP3: + Admin Prodi + Kaprodi
                $kaprodi = User::whereHas('roles', fn ($q) => $q->where('name', 'kaprodi'))
                    ->where('prodi_id', $mahasiswa->prodi_id)
                    ->get();
                $adminProdi = User::whereHas('roles', fn ($q) => $q->where('name', 'admin_prodi'))
                    ->where('prodi_id', $mahasiswa->prodi_id)
                    ->get();
                $recipients = array_merge($recipients, $kaprodi->all(), $adminProdi->all());
                break;

            case SpLevel::Do:
                // Approaching DO: + Admin Prodi + Kaprodi + Kajur (URGENT)
                $kaprodi = User::whereHas('roles', fn ($q) => $q->where('name', 'kaprodi'))
                    ->where('prodi_id', $mahasiswa->prodi_id)
                    ->get();
                $adminProdi = User::whereHas('roles', fn ($q) => $q->where('name', 'admin_prodi'))
                    ->where('prodi_id', $mahasiswa->prodi_id)
                    ->get();
                $ketua_jurusan = User::whereHas('roles', fn ($q) => $q->where('name', 'ketua_jurusan'))->get();
                $recipients = array_merge($recipients, $kaprodi->all(), $adminProdi->all(), $ketua_jurusan->all());
                break;
        }

        return $recipients;
    }

    /**
     * Get recipients untuk notifikasi level change berdasarkan level
     */
    protected function getLevelChangeRecipients(SpLevel $level, User $mahasiswa): array
    {
        $recipients = [];

        // Kaprodi selalu dapat notif untuk semua level
        $kaprodi = User::whereHas('roles', fn ($q) => $q->where('name', 'kaprodi'))
            ->where('prodi_id', $mahasiswa->prodi_id)
            ->get();
        $recipients = array_merge($recipients, $kaprodi->all());

        // SP1: + Dosen pengampu MK terkait + Admin Prodi
        if ($level === SpLevel::Sp1) {
            $dosenIds = MataKuliah::whereHas('mahasiswas', fn ($q) => $q->where('users.id', $mahasiswa->id))
                ->whereNotNull('dosen_id')
                ->pluck('dosen_id')
                ->unique();
            $dosens = User::whereIn('id', $dosenIds)->get();
            $adminProdi = User::whereHas('roles', fn ($q) => $q->where('name', 'admin_prodi'))
                ->where('prodi_id', $mahasiswa->prodi_id)
                ->get();
            $recipients = array_merge($recipients, $dosens->all(), $adminProdi->all());
        }

        // SP2+: + Kajur + Admin Prodi
        if (in_array($level, [SpLevel::Sp2, SpLevel::Sp3, SpLevel::Do], true)) {
            $ketua_jurusan = User::whereHas('roles', fn ($q) => $q->where('name', 'ketua_jurusan'))->get();
            $adminProdi = User::whereHas('roles', fn ($q) => $q->where('name', 'admin_prodi'))
                ->where('prodi_id', $mahasiswa->prodi_id)
                ->get();
            $recipients = array_merge($recipients, $ketua_jurusan->all(), $adminProdi->all());
        }

        // SP3+: + Admin Jurusan
        if (in_array($level, [SpLevel::Sp3, SpLevel::Do], true)) {
            $adminJurusan = User::whereHas('roles', fn ($q) => $q->where('name', 'admin_jurusan'))->get();
            $recipients = array_merge($recipients, $adminJurusan->all());
        }

        return $recipients;
    }

    /**
     * Helper: create notification record
     */
    protected function createNotification(int $userId, string $type, string $title, string $message, array $data = []): array
    {
        $semesterId = $this->notificationSemesterId ?? 'none';
        $subject = $data['mahasiswa_id'] ?? $userId;
        $level = $data['level'] ?? $type;
        $key = "sp:{$subject}:{$semesterId}:{$type}:{$level}:{$userId}";
        app(NotificationOutboxService::class)->enqueue($key, $userId, $type, $title, $message, $data);

        return ['idempotency_key' => $key];
    }
}
