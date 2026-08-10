<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function mataKuliahs(): BelongsToMany
    {
        return $this->belongsToMany(MataKuliah::class, 'mahasiswa_mata_kuliah');
    }

    public function dosenMataKuliahs(): HasMany
    {
        return $this->hasMany(MataKuliah::class, 'dosen_id');
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
