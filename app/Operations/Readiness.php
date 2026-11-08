<?php
namespace App\Operations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
final class Readiness {
    public function __invoke() {
        try {
            DB::select('select 1');
            $key = 'readiness:'.Str::uuid();
            try { Cache::put($key, 'ok', 10); if (Cache::get($key) !== 'ok') throw new \RuntimeException('Cache unavailable'); }
            finally { Cache::forget($key); }
            return response()->json(['status'=>'ready'])->header('Cache-Control','no-store');
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status'=>'unavailable'],503)->header('Cache-Control','no-store');
        }
    }
}
