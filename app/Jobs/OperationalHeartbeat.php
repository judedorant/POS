<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
final class OperationalHeartbeat implements ShouldQueue {
    use Queueable;
    public function __construct(public string $name) {}
    public function handle(): void { Cache::put('ops:heartbeat:'.$this->name, time(), 600); }
}
