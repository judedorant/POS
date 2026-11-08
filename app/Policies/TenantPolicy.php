<?php
namespace App\Policies;
use App\Models\{Tenant, User, Role};
final class TenantPolicy {
    public function update(User $user, Tenant $tenant): bool {
        if ($user->disabled_at !== null || $tenant->status !== 'active') return false;
        $membership = $tenant->memberships()->where('user_id', $user->id)->where('status', 'active')->first();
        return $membership && in_array('tenant.manage', Role::findOrFail($membership->role)->permissions, true);
    }
}
