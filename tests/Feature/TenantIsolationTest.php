<?php
namespace Tests\Feature;
use App\Models\{User, Tenant, TenantMembership};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;
    public function test_membership_is_required_for_each_tenant(): void
    {
        $user = User::create(['name' => 'Cashier', 'email' => 'cashier@example.com', 'password' => 'secret-password']);
        $own = Tenant::create(['name' => 'Own', 'slug' => 'own']);
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other']);
        TenantMembership::create(['tenant_id' => $own->id, 'user_id' => $user->id, 'role' => 'cashier']);
        $this->actingAs($user)->getJson('/api/v1/tenants/'.$own->id.'/context')->assertOk()->assertJsonPath('role', 'cashier');
        $this->getJson('/api/v1/tenants/'.$other->id.'/context')->assertNotFound();
    }
    public function test_anonymous_access_is_denied(): void
    {
        $this->getJson('/api/v1/tenants')->assertUnauthorized();
    }
}
