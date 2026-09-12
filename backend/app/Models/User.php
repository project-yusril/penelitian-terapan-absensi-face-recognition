<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'nama',
        'email',
        'password',
        'nim',
        'nidn',
        'nip',
        'no_hp',
        'tempat_lahir',
        'tanggal_lahir',
        'jenis_kelamin',
        'alamat',
        'prodi_id',
        'kelas',
        'angkatan',
        'semester',
        'jabatan_fungsional',
        'pendidikan_terakhir',
        'bidang_keahlian',
        'foto_profil',
        'foto_enrollment',
        'tanda_tangan',
        'status',
        'must_change_password',
        'activation_pending',
        'enrollment_status',
        'fcm_token',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'foto_enrollment',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_lahir' => 'date',
            'must_change_password' => 'boolean',
            'activation_pending' => 'boolean',
            'last_login_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    // ==================== RELATIONSHIPS ====================

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function prodi(): BelongsTo
    {
        return $this->belongsTo(Prodi::class);
    }

    public function faceEmbeddings(): HasMany
    {
        return $this->hasMany(FaceEmbedding::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function alphaAccumulations(): HasMany
    {
        return $this->hasMany(AlphaAccumulation::class);
    }

    public function spRecords(): HasMany
    {
        return $this->hasMany(SpRecord::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function children(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'parent_student', 'parent_id', 'student_id')
            ->withPivot('hubungan');
    }

    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'parent_student', 'student_id', 'parent_id')
            ->withPivot('hubungan');
    }

    /**
     * RENCANA 2: relasi baru lewat tabel pivot `mahasiswa_kelas`.
     */
    public function mahasiswaKelas(): HasMany
    {
        return $this->hasMany(MahasiswaKelas::class);
    }

    /**
     * RENCANA 2: pivot kelas di semester AKTIF — sumber kelas "saat ini".
     * Snapshot `users.kelas` tetap dipakai untuk display cepat & kompatibilitas
     * mobile, tetapi relasi ini adalah kebenaran kanonikal.
     */
    public function kelasAktif(): HasOne
    {
        return $this->hasOne(MahasiswaKelas::class)
            ->whereHas('semester', fn ($q) => $q->where('status', 'aktif'))
            ->with('kelas')
            ->latestOfMany();
    }

    public function kelas(): BelongsToMany
    {
        return $this->belongsToMany(Kelas::class, 'mahasiswa_kelas', 'user_id', 'kelas_id')
            ->withPivot('semester_id');
    }

    /**
     * RENCANA 2: mata kuliah mahasiswa diturunkan dari kelas -> jadwal.
     */
    public function mataKuliahViaKelas(): HasManyThrough
    {
        return $this->hasManyThrough(
            MataKuliah::class,
            MahasiswaKelas::class,
            'user_id',
            'id',
            'id',
            'mata_kuliah_id'
        )->whereHas('jadwals', function ($query) {
            $query->whereColumn('jadwals.kelas_id', 'mahasiswa_kelas.kelas_id');
        });
    }

    public function reEnrollmentRequests(): HasMany
    {
        return $this->hasMany(ReEnrollmentRequest::class);
    }

    public function auditTrails(): HasMany
    {
        return $this->hasMany(AuditTrail::class);
    }

    // ==================== HELPERS ====================

    public function hasRole(string $roleName): bool
    {
        return $this->roles()->where('name', $roleName)->exists();
    }

    public function hasAnyRole(array $roles): bool
    {
        return $this->roles()->whereIn('name', $roles)->exists();
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
