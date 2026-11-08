<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
class OperationsTest extends TestCase {
    use RefreshDatabase;
    public function test_readiness_checks_shared_database_cache_without_leaking_details(): void {
        config(['cache.default'=>'database']);
        $this->getJson('/readyz')->assertOk()->assertExactJson(['status'=>'ready'])->assertHeader('Cache-Control','no-store, private');
        $this->assertSame(0, DB::table('cache')->count());
    }
    public function test_readiness_failure_is_sanitized(): void {
        Cache::shouldReceive('put')->once()->andThrow(new \RuntimeException('secret connection detail'));
        Cache::shouldReceive('forget')->once();
        $this->getJson('/readyz')->assertStatus(503)->assertExactJson(['status'=>'unavailable']);
    }
    public function test_ops_check_detects_stalled_worker_and_old_queue(): void {
        foreach (['scheduler','payments','realtime'] as $name) Cache::put('ops:heartbeat:'.$name,time(),600);
        $this->artisan('pos:ops-check')->assertExitCode(0);
        Cache::put('ops:heartbeat:payments',time()-181,600);
        $this->artisan('pos:ops-check')->assertExitCode(1);
        Cache::put('ops:heartbeat:payments',time(),600);
        DB::table('jobs')->insert(['queue'=>'payments','payload'=>'{}','attempts'=>0,'reserved_at'=>null,'available_at'=>time()-181,'created_at'=>time()-181]);
        $this->artisan('pos:ops-check')->assertExitCode(1);
    }
    public function test_runtime_preflight_rejects_testing_and_superuser_configuration(): void {
        $this->artisan('pos:production-check')->assertExitCode(1);
    }
}
