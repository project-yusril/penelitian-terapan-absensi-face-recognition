<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ReadinessService
{
    public function checks(): array
    {
        return [
            'database' => $this->check(fn () => DB::selectOne('SELECT 1')),
            'cache' => $this->check(fn () => Cache::get(config('health.cache_key'))),
            'storage' => $this->check(function (): void {
                $disk = Storage::disk(config('health.storage_disk'));
                $path = config('health.storage_sentinel');

                if (! $disk->exists($path) || $disk->get($path) === null) {
                    throw new \RuntimeException('Sentinel unavailable');
                }
            }),
        ];
    }

    private function check(callable $operation): string
    {
        try {
            $operation();

            return 'ok';
        } catch (Throwable) {
            return 'unavailable';
        }
    }
}
