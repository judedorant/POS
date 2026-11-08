<?php
namespace Tests\Feature;
use App\Models\{User, Tenant, TenantMembership};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
final class RowSecurityTest extends TestCase {
    use RefreshDatabase;
    public function test_restricted_database_role_cannot_read_other_memberships(): void {
        $a = User::create(['name'=>'A','email'=>'a@example.com','password'=>'long-password']);
        $b = User::create(['name'=>'B','email'=>'b@example.com','password'=>'long-password']);
        $tenant = Tenant::create(['name'=>'Shop','slug'=>'shop']);
        foreach ([$a,$b] as $user) TenantMembership::create(['tenant_id'=>$tenant->id,'user_id'=>$user->id,'role'=>'cashier']);
        DB::statement('CREATE ROLE phase1_rls_probe NOLOGIN NOSUPERUSER NOBYPASSRLS');
        DB::statement('GRANT USAGE ON SCHEMA public TO phase1_rls_probe');
        DB::statement('GRANT SELECT ON tenant_memberships TO phase1_rls_probe');
        DB::statement('SET LOCAL ROLE phase1_rls_probe');
        try {
            DB::select("select set_config('app.user_id', '', true)");
            $this->assertSame(0, TenantMembership::count());
            DB::select("select set_config('app.user_id', ?, true)", [$a->id]);
            $this->assertSame([$a->id], TenantMembership::pluck('user_id')->all());
        } finally { DB::statement('RESET ROLE'); }
    }
}
