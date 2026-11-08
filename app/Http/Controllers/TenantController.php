<?php
namespace App\Http\Controllers;
use App\Actions\ProvisionTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
final class TenantController {
    public function store(Request $request, ProvisionTenant $action) {
        abort_if($request->user()->disabled_at !== null, 403);
        $data = $request->validate(['name' => 'required|string|max:120', 'slug' => 'required|alpha_dash:ascii|max:80|unique:tenants,slug', 'timezone' => 'required|timezone', 'default_currency' => 'required|in:USD,EUR,GBP,SGD']);
        return response()->json($action->execute($request->user(), $data), 201);
    }
    public function update(Request $request) {
        $tenant = $request->attributes->get('tenant');
        Gate::authorize('update', $tenant);
        $before=\Illuminate\Support\Facades\DB::table('tenants')->where('id',$tenant->id)->lockForUpdate()->firstOrFail()->name;
        $tenant->update($request->validate(['name' => 'required|string|max:120']));
        \App\Actions\RecordAudit::record($tenant->id,'tenant.updated','tenant',$tenant->id,$request->attributes->get('membership')->id,['before'=>['name'=>$before],'after'=>['name'=>$tenant->name]]);
        return response()->json($tenant);
    }
}
