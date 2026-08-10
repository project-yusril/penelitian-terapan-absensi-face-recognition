<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Prodi extends Model
{
    protected $fillable = [
        'kode',
        'nama',
        'jenjang',
        'jurusan',
        'status',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function mataKuliahs(): HasMany
    {
        return $this->hasMany(MataKuliah::class);
    }

    public function geofences(): HasMany
    {
        return $this->hasMany(Geofence::class);
    }

    public function setting(): HasOne
    {
        return $this->hasOne(ProdiSetting::class);
    }
}
