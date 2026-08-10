<?php

namespace Tests\Unit;

use App\Services\ReadinessService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ReadinessServiceTest extends TestCase
{
    public function test_checks_are_read_only(): void
    {
        config()->set('health.storage_disk', 'local');
        config()->set('health.storage_sentinel', '.readiness');
        config()->set('health.cache_key', '_readiness');
        DB::shouldReceive('selectOne')->once()->with('SELECT 1')->andReturn((object) ['1' => 1]);
        Cache::shouldReceive('get')->once()->with('_readiness')->andReturn(null);
        Cache::shouldNotReceive('put');
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->once()->with('.readiness')->andReturnTrue();
        $disk->shouldReceive('get')->once()->with('.readiness')->andReturn('ready');
        $disk->shouldNotReceive('put');
        $disk->shouldNotReceive('delete');
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);

        $this->assertSame([
            'database' => 'ok',
            'cache' => 'ok',
            'storage' => 'ok',
        ], (new ReadinessService)->checks());
    }

    public function test_raw_failures_are_reduced_to_unavailable(): void
    {
        DB::shouldReceive('selectOne')->andThrow(new \RuntimeException('secret database host'));
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('secret cache class'));
        Storage::shouldReceive('disk')->andThrow(new \RuntimeException('secret storage path'));

        $this->assertSame([
            'database' => 'unavailable',
            'cache' => 'unavailable',
            'storage' => 'unavailable',
        ], (new ReadinessService)->checks());
    }
}
