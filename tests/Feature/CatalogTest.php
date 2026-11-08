<?php
namespace Tests\Feature;
use App\Models\{User,Tenant,TenantMembership};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
final class CatalogTest extends TestCase {
 use RefreshDatabase;
 private function setupTenant(string $slug, string $role='admin'): array {
  $u=User::create(['name'=>$slug,'email'=>$slug.'@example.com','password'=>'long-password']);
  $t=Tenant::create(['name'=>$slug,'slug'=>$slug]);
  TenantMembership::create(['tenant_id'=>$t->id,'user_id'=>$u->id,'role'=>$role]);
  return [$u,$t];
 }
 public function test_catalog_lifecycle_and_scoped_identifiers(): void {
  [$a,$ta]=$this->setupTenant('a','inventory_manager'); [$b,$tb]=$this->setupTenant('b');
  $base='/api/v1/tenants/'.$ta->id;
  $category=$this->actingAs($a)->postJson($base.'/categories',['name'=>'Drinks','slug'=>'drinks'])->assertCreated()->json('id');
  $product=$this->postJson($base.'/products',['name'=>'Coffee','sku'=>'COF','barcode'=>'123','category_id'=>$category,'sale_price_minor'=>500])->assertCreated()->json('id');
  $this->getJson($base.'/products?q=123')->assertOk()->assertJsonPath('total',1);
  $this->patchJson($base.'/products/'.$product,['is_active'=>false])->assertOk()->assertJsonPath('is_active',false);
  $this->postJson($base.'/products',['name'=>'Duplicate','sku'=>'COF','sale_price_minor'=>500])->assertUnprocessable();
  $this->postJson($base.'/warehouses',['name'=>'Main','code'=>'MAIN'])->assertCreated();
  $other='/api/v1/tenants/'.$tb->id;
  $this->actingAs($b)->postJson($other.'/products',['name'=>'Coffee','sku'=>'COF','sale_price_minor'=>400])->assertCreated();
  $this->getJson($other.'/products/'.$product)->assertNotFound();
  $this->postJson($other.'/products',['name'=>'Bad','sku'=>'BAD','category_id'=>$category,'sale_price_minor'=>400])->assertUnprocessable();
  $this->getJson($base.'/products')->assertNotFound();
 }
 public function test_cashiers_cannot_write_and_price_cannot_be_negative(): void {
  [$u,$t]=$this->setupTenant('cash','cashier');
  $base='/api/v1/tenants/'.$t->id;
  $this->actingAs($u)->getJson($base.'/products')->assertOk();
  foreach(['products','categories','warehouses'] as $resource) $this->postJson($base.'/'.$resource,[])->assertForbidden();
  [$u,$t]=$this->setupTenant('admin');
  $this->actingAs($u)->postJson('/api/v1/tenants/'.$t->id.'/products',['name'=>'Bad','sku'=>'BAD','sale_price_minor'=>-1])->assertUnprocessable();
 }
 public function test_database_rejects_cross_tenant_category_even_without_api(): void {
  [$a,$ta]=$this->setupTenant('a'); [$b,$tb]=$this->setupTenant('b');
  $id=$this->actingAs($a)->postJson('/api/v1/tenants/'.$ta->id.'/categories',['name'=>'A','slug'=>'a'])->json('id');
  $this->expectException(\Illuminate\Database\QueryException::class);
  DB::table('products')->insert(['id'=>(string)\Illuminate\Support\Str::uuid(),'tenant_id'=>$tb->id,'category_id'=>$id,'name'=>'Bad','sku'=>'BAD','sale_price_minor'=>1]);
 }
 public function test_catalog_rls_denies_missing_or_other_tenant_context(): void {
  [$a,$ta]=$this->setupTenant('a'); [$b,$tb]=$this->setupTenant('b');
  foreach([[$a,$ta],[$b,$tb]] as [$u,$t]) $this->actingAs($u)->postJson('/api/v1/tenants/'.$t->id.'/products',['name'=>'Item','sku'=>'ITEM','sale_price_minor'=>1])->assertCreated();
  DB::statement('CREATE ROLE catalog_probe NOLOGIN NOSUPERUSER NOBYPASSRLS');
  DB::statement('GRANT USAGE ON SCHEMA public TO catalog_probe');
  DB::statement('GRANT SELECT ON products TO catalog_probe');
  DB::statement('SET LOCAL ROLE catalog_probe');
  try {
   DB::select("select set_config('app.tenant_id','',true)"); $this->assertSame(0,DB::table('products')->count());
   DB::select("select set_config('app.tenant_id',?,true)",[$ta->id]);
   $this->assertSame([$ta->id],DB::table('products')->pluck('tenant_id')->all());
  } finally { DB::statement('RESET ROLE'); }
 }
}
