<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Jadwal extends Model
{
    use SoftDeletes;

    protected $table = 'jadwals';

    protected $fillable = [
        'mata_kuliah_id',
        'kelas_id',
        'dosen_id',
        'geofence_id',
        'hari',
        'jam_mulai',
        'jam_selesai',
        'ruangan',
        'durasi_menit',
        'status',
    ];

    protected function durasiMenit(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ?? (
                $this->jam_mulai && $this->jam_selesai
                    ? (int) ((strtotime($this->jam_selesai) - strtotime($this->jam_mulai)) / 60)
                    : null
            ),
        );
    }

    public function mataKuliah(): BelongsTo
    {
        return $this->belongsTo(MataKuliah::class);
    }

    /**
     * RENCANA 2: kelas & dosen di-plot per jadwal (pindah dari mata_kuliahs).
     */
    public function kelas(): BelongsTo
    {
        return $this->belongsTo(Kelas::class);
    }

    public function dosen(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dosen_id');
    }

    public function geofence(): BelongsTo
    {
        return $this->belongsTo(Geofence::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * RENCANA 2: mahasiswa dari kelas jadwal (untuk notifikasi/reminder).
     */
    public function mahasiswaKelas(): HasManyThrough
    {
        return $this->hasManyThrough(
            MahasiswaKelas::class,
            Kelas::class,
            'id',
            'kelas_id',
            'kelas_id',
            'id'
        );
    }
}
