<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
final class IdentityContext {
    public function handle(Request $request, Closure $next) {
        abort_if($request->user()->disabled_at !== null, 403);
        return DB::transaction(function () use ($request, $next) {
            DB::select("select set_config('app.user_id', ?, true)", [(string) $request->user()->id]);
            return $next($request);
        });
    }
}
