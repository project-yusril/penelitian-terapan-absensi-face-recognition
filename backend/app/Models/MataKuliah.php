<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MataKuliah extends Model
{
    use SoftDeletes;

    protected $table = 'mata_kuliahs';

    protected $fillable = [
        'kode_mk',
        'nama',
        'sks',
        'semester_id',
        'prodi_id',
        'dosen_id',
        'kelas',
        'total_pertemuan',
        'status',
    ];

    /**
     * `kelas_key` adalah generated column (M-20) yang menormalkan `kelas`
     * NULL menjadi string kosong agar unique constraint bekerja. Database
     * menolak setiap penulisan ke kolom ini, sehingga atribut harus dibuang
     * sebelum insert/update dan tidak boleh ikut tersalin oleh `replicate()`.
     */
    protected const GENERATED_COLUMNS = ['kelas_key'];

    protected static function booted(): void
    {
        // `saving` berjalan sebelum seluruh jalur persist, termasuk
        // save(), update(), dan saveOrIgnore().
        static::saving(function (self $model): void {
            foreach (self::GENERATED_COLUMNS as $column) {
                unset($model->attributes[$column]);
            }
        });
    }

    public function replicate(?array $except = null): static
    {
        return parent::replicate(array_merge($except ?? [], self::GENERATED_COLUMNS));
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function prodi(): BelongsTo
    {
        return $this->belongsTo(Prodi::class);
    }

    public function dosen(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dosen_id');
    }

    public function mahasiswas(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'mahasiswa_mata_kuliah');
    }

    public function jadwals(): HasMany
    {
        return $this->hasMany(Jadwal::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }
}
