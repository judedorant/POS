<?php
namespace App\Actions;
use App\Models\{Tenant, TenantMembership, User};
use Illuminate\Support\Facades\DB;
final class ProvisionTenant {
    public function execute(User $owner, array $data): Tenant {
        return DB::transaction(function () use ($owner, $data) {
            $tenant = Tenant::create($data);
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'role' => 'admin']);
            \Illuminate\Support\Facades\DB::select("select set_config('app.tenant_id',?,true)",[$tenant->id]);
            $member=TenantMembership::where('tenant_id',$tenant->id)->where('user_id',$owner->id)->firstOrFail();
            RecordAudit::record($tenant->id,'tenant.created','tenant',$tenant->id,$member->id,['name'=>$tenant->name]);
            return $tenant;
        });
    }
}
