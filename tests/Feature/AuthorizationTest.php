<?php
namespace Tests\Feature;
use App\Models\{User, Tenant, TenantMembership};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
final class AuthorizationTest extends TestCase {
    use RefreshDatabase;
    public function test_cashier_cannot_update_tenant(): void {
        $user = User::create(['name'=>'Cashier','email'=>'cash@example.com','password'=>'long-password']);
        $tenant = Tenant::create(['name'=>'Shop','slug'=>'shop']);
        TenantMembership::create(['tenant_id'=>$tenant->id,'user_id'=>$user->id,'role'=>'cashier']);
        $this->actingAs($user)->patchJson('/api/v1/tenants/'.$tenant->id, ['name'=>'Changed'])->assertForbidden();
        $this->assertDatabaseHas('tenants', ['id'=>$tenant->id,'name'=>'Shop']);
    }
    public function test_provisioning_grants_owner_admin_and_ignores_supplied_role(): void {
        $user = User::create(['name'=>'Owner','email'=>'owner@example.com','password'=>'long-password']);
        $response = $this->actingAs($user)->postJson('/api/v1/tenants', ['name'=>'Shop','slug'=>'shop','timezone'=>'UTC','default_currency'=>'USD','role'=>'cashier']);
        $response->assertCreated();
        $this->patchJson('/api/v1/tenants/'.$response->json('id'), ['name'=>'Renamed'])->assertOk();
    }
    public function test_suspended_membership_is_denied(): void {
        $user = User::create(['name'=>'Cashier','email'=>'cash@example.com','password'=>'long-password']);
        $tenant = Tenant::create(['name'=>'Shop','slug'=>'shop']);
        TenantMembership::create(['tenant_id'=>$tenant->id,'user_id'=>$user->id,'role'=>'admin','status'=>'suspended']);
        $this->actingAs($user)->getJson('/api/v1/tenants/'.$tenant->id.'/context')->assertNotFound();
    }
}
