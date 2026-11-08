<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use App\Models\Tenant;
final class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        abort_if($request->user()->disabled_at !== null, 403);
        $tenant = Tenant::query()->whereKey($request->route('tenant'))->where('status', 'active')->firstOrFail();
        $membership = $tenant->memberships()->where('user_id', $request->user()->id)->where('status', 'active')->first();
        abort_unless($membership, 404);
        \Illuminate\Support\Facades\DB::select("select set_config('app.tenant_id', ?, true)", [(string) $tenant->id]);
        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('membership', $membership);
        return app(AuditAccessDenied::class)->handle($request,$next);
    }
}
