<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Jadwal extends Model
{
    use SoftDeletes;

    protected $table = 'jadwals';

    protected $fillable = [
        'mata_kuliah_id',
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

    public function geofence(): BelongsTo
    {
        return $this->belongsTo(Geofence::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }
}
