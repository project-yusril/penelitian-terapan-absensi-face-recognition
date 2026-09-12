<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Semester extends Model
{
    protected $fillable = [
        'tahun_ajaran_id',
        'nama',
        'kode',
        'tanggal_mulai',
        'tanggal_selesai',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
        ];
    }

    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    public function mataKuliahs(): HasMany
    {
        return $this->hasMany(MataKuliah::class);
    }

    /**
     * RENCANA 2: kelas & mahasiswa_kelas per semester.
     */
    public function kelas(): HasMany
    {
        return $this->hasMany(Kelas::class);
    }

    public function mahasiswaKelas(): HasMany
    {
        return $this->hasMany(MahasiswaKelas::class);
    }
}
