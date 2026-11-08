<?php
namespace Tests\Feature;
use App\Models\{User,Tenant,TenantMembership};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
final class OfflineSyncTest extends TestCase {
 use RefreshDatabase;
 private function fixture(): array {
  $u=User::create(['name'=>'Cashier','email'=>'cash@example.com','password'=>'long-password']);$t=Tenant::create(['name'=>'Shop','slug'=>'shop']);$m=TenantMembership::create(['tenant_id'=>$t->id,'user_id'=>$u->id,'role'=>'admin']);$base='/api/v1/tenants/'.$t->id;$this->actingAs($u);
  $p=$this->postJson($base.'/products',['name'=>'Rice','sku'=>'RICE','sale_price_minor'=>100])->json('id');$w=$this->postJson($base.'/warehouses',['name'=>'Main','code'=>'MAIN'])->json('id');$device=(string)Str::uuid();
  $lease=$this->postJson($base.'/offline/prepare',['device_id'=>$device,'warehouse_id'=>$w])->assertCreated()->json('lease_id');
  return [$base,$p,$w,$device,$lease,$m,$t];
 }
 private function command(string $p,string $device,string $lease): array {return ['operation_id'=>(string)Str::uuid(),'lease_id'=>$lease,'device_id'=>$device,'occurred_at'=>now()->toISOString(),'cash_tendered_minor'=>1000,'items'=>[['product_id'=>$p,'quantity'=>'2','expected_price_minor'=>100]]];}
 public function test_snapshot_price_archival_negative_stock_and_retry(): void {
  [$base,$p,$w,$device,$lease]=$this->fixture();$command=$this->command($p,$device,$lease);
  DB::table('products')->where('id',$p)->update(['sale_price_minor'=>500,'name'=>'New','is_active'=>false]);
  $this->postJson($base.'/sync/orders',['orders'=>[$command]])->assertOk()->assertJsonPath('results.0.status','accepted')->assertJsonPath('results.0.code','negative_stock');
  $this->assertSame('200', (string)DB::table('orders')->value('total_minor'));$this->assertSame('Rice',DB::table('order_items')->value('name'));
  $this->assertSame('-2.0000',DB::table('inventory_stocks')->value('on_hand'));
  $this->postJson($base.'/sync/orders',['orders'=>[$command]])->assertOk()->assertJsonPath('results.0.status','already_processed');$this->assertDatabaseCount('orders',1);
  $command['cash_tendered_minor']=2000;$this->postJson($base.'/sync/orders',['orders'=>[$command]])->assertJsonPath('results.0.code','idempotency_conflict');
 }
 public function test_batch_isolates_invalid_order(): void {
  [$base,$p,$w,$device,$lease]=$this->fixture();$valid=$this->command($p,$device,$lease);$bad=$this->command($p,$device,$lease);$bad['items'][0]['expected_price_minor']=1;
  $this->postJson($base.'/sync/orders',['orders'=>[$bad,$valid]])->assertOk()->assertJsonPath('results.0.status','rejected')->assertJsonPath('results.1.status','accepted');$this->assertDatabaseCount('orders',1);
 }
 public function test_expired_lease_requires_review_and_admin_can_approve(): void {
  [$base,$p,$w,$device,$lease]=$this->fixture();$command=$this->command($p,$device,$lease);$this->travel(9)->hours();
  $id=$this->postJson($base.'/sync/orders',['orders'=>[$command]])->assertJsonPath('results.0.status','requires_review')->json('results.0.review_id');$this->assertDatabaseCount('orders',0);
  $this->postJson($base.'/offline/reviews/'.$id,['decision'=>'approve','note'=>'Verified cash and receipt'])->assertOk()->assertJsonPath('status','accepted');
  $this->postJson($base.'/sync/orders',['orders'=>[$command]])->assertJsonPath('results.0.status','already_processed');$this->assertDatabaseCount('orders',1);
 }
 public function test_lease_identity_and_review_permissions(): void {
  [$base,$p,$w,$device,$lease,$m,$tenant]=$this->fixture();$command=$this->command($p,$device,$lease);$command['device_id']=(string)Str::uuid();
  $this->postJson($base.'/sync/orders',['orders'=>[$command]])->assertJsonPath('results.0.code','invalid_lease');
  $m->update(['role'=>'cashier']);$this->getJson($base.'/offline/reviews')->assertForbidden();
  $m->update(['status'=>'suspended']);$this->postJson($base.'/sync/orders',['orders'=>[$command]])->assertNotFound();
 }
 public function test_revocation_routes_sale_to_review(): void {
  [$base,$p,$w,$device,$lease]=$this->fixture();$command=$this->command($p,$device,$lease);
  $this->deleteJson($base.'/offline/leases/'.$lease)->assertNoContent();
  $id=$this->postJson($base.'/sync/orders',['orders'=>[$command]])->assertJsonPath('results.0.status','requires_review')->json('results.0.review_id');
  $this->postJson($base.'/offline/reviews/'.$id,['decision'=>'reject','note'=>'Not an authorized sale'])->assertOk();
  $this->postJson($base.'/sync/orders',['orders'=>[$command]])->assertJsonPath('results.0.status','rejected');$this->assertDatabaseCount('orders',0);
 }
}
