<?php

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BiometricLockService
{
    private const LOCK_NAME = 'biometric-enrollment-global';

    public function run(Closure $callback): mixed
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return DB::transaction(function () use ($callback) {
                User::query()->orderBy('id')->lockForUpdate()->firstOrFail();

                return $callback();
            });
        }

        $acquired = (int) (DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [self::LOCK_NAME])->acquired ?? 0);
        if ($acquired !== 1) {
            throw new RuntimeException('Biometric matching is unavailable');
        }

        try {
            return $callback();
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [self::LOCK_NAME]);
        }
    }
}
