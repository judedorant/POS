<?php
namespace App\Http\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Tenant;
use Closure;
final class SyncTenantContext {
 public function handle(Request $r,Closure $next){
  abort_if($r->user()->disabled_at!==null,403);
  DB::transaction(function()use($r){
   DB::select("select set_config('app.user_id',?,true)",[$r->user()->id]);
   $tenant=Tenant::whereKey($r->route('tenant'))->where('status','active')->firstOrFail();
   $membership=$tenant->memberships()->where('user_id',$r->user()->id)->where('status','active')->first();abort_unless($membership,404);
   $r->attributes->set('tenant',$tenant);$r->attributes->set('membership',$membership);
  });
  // No request-wide transaction: each submitted command commits independently.
  return $next($r);
 }
}
