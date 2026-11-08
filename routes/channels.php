<?php
use Illuminate\Support\Facades\Broadcast;
use App\Models\{User,Tenant};
Broadcast::channel('tenants.{tenant}',function(User $user,string $tenant){
 if(!\Illuminate\Support\Str::isUuid($tenant)||$user->disabled_at!==null)return false;
 $business=Tenant::whereKey($tenant)->where('status','active')->first();
 return $business && $business->memberships()->where('user_id',$user->id)->where('status','active')->exists();
});
