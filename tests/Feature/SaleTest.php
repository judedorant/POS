<?php
namespace Tests\Feature;
use App\Models\{User,Tenant,TenantMembership};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
final class SaleTest extends TestCase {
 use RefreshDatabase;
 private function fixture(): array {
  $u=User::create(['name'=>'Cashier','email'=>'cashier@example.com','password'=>'long-password']);
  $t=Tenant::create(['name'=>'Shop','slug'=>'shop']);
  $m=TenantMembership::create(['tenant_id'=>$t->id,'user_id'=>$u->id,'role'=>'admin']);
  $base='/api/v1/tenants/'.$t->id;$this->actingAs($u);
  $p=$this->postJson($base.'/products',['name'=>'Rice','sku'=>'RICE','sale_price_minor'=>101,'unit'=>'kg'])->json('id');
  $w=$this->postJson($base.'/warehouses',['name'=>'Main','code'=>'MAIN'])->json('id');
  $this->postJson($base.'/inventory/operations',['operation_id'=>(string)Str::uuid(),'kind'=>'adjustment','product_id'=>$p,'warehouse_id'=>$w,'quantity'=>'10','reason'=>'Opening'])->assertCreated();
  $m->update(['role'=>'cashier']);
  return [$base,$p,$w,$m,$t];
 }
 private function command(string $p,string $w,string $qty='1.5'): array {return ['client_order_id'=>(string)Str::uuid(),'warehouse_id'=>$w,'cash_tendered_minor'=>1000,'items'=>[['product_id'=>$p,'quantity'=>$qty,'expected_price_minor'=>101]]];}
 public function test_cash_sale_rounding_snapshots_and_retry(): void {
  [$base,$p,$w]=$this->fixture();$command=$this->command($p,$w);
  $receipt=$this->postJson($base.'/orders',$command)->assertCreated()->assertJsonPath('order.total_minor',152)->assertJsonPath('order.change_minor',848)->json('order.id');
  $this->postJson($base.'/orders',$command)->assertOk()->assertJsonPath('replayed',true)->assertJsonPath('order.id',$receipt);
  $this->assertDatabaseCount('orders',1);$this->assertDatabaseCount('transactions',1);$this->assertDatabaseCount('stock_movements',2);
  $this->assertSame('8.5000',DB::table('inventory_stocks')->value('on_hand'));
  DB::table('products')->where('id',$p)->update(['name'=>'Renamed','sale_price_minor'=>200]);
  $this->getJson($base.'/orders/'.$receipt)->assertOk()->assertJsonPath('items.0.name','Rice')->assertJsonPath('items.0.unit_price_minor',101);
  $this->postJson($base.'/orders',$command)->assertOk();
  $command['cash_tendered_minor']=2000;$this->postJson($base.'/orders',$command)->assertConflict();
 }
 public function test_failure_rolls_back_sale_and_inventory(): void {
  [$base,$p,$w]=$this->fixture();
  $this->postJson($base.'/orders',$this->command($p,$w,'11'))->assertUnprocessable();
  $command=$this->command($p,$w);$command['cash_tendered_minor']=1;$this->postJson($base.'/orders',$command)->assertUnprocessable();
  $command=$this->command($p,$w);$command['items'][0]['expected_price_minor']=1;$this->postJson($base.'/orders',$command)->assertUnprocessable();
  $this->assertDatabaseCount('orders',0);$this->assertDatabaseCount('transactions',0);$this->assertDatabaseCount('order_items',0);$this->assertDatabaseCount('stock_movements',1);
  $this->assertSame('10.0000',DB::table('inventory_stocks')->value('on_hand'));
 }
 public function test_foreign_product_and_duplicate_lines_are_rejected(): void {
  [$base,$p,$w]=$this->fixture();$foreign=Tenant::create(['name'=>'Other','slug'=>'other']);$pid=(string)Str::uuid();
  DB::table('products')->insert(['id'=>$pid,'tenant_id'=>$foreign->id,'name'=>'Foreign','sku'=>'X','sale_price_minor'=>101]);
  $this->postJson($base.'/orders',$this->command($pid,$w))->assertUnprocessable();
  $command=$this->command($p,$w);$command['items'][]=$command['items'][0];$this->postJson($base.'/orders',$command)->assertUnprocessable();
 }
 public function test_receipt_access_and_inventory_manager_permissions(): void {
  [$base,$p,$w,$membership,$tenant]=$this->fixture();$id=$this->postJson($base.'/orders',$this->command($p,$w))->json('order.id');
  $other=User::create(['name'=>'Other','email'=>'other@example.com','password'=>'long-password']);
  $m=TenantMembership::create(['tenant_id'=>$tenant->id,'user_id'=>$other->id,'role'=>'cashier']);
  $this->actingAs($other)->getJson($base.'/orders/'.$id)->assertNotFound();
  $this->getJson($base.'/orders')->assertOk()->assertJsonPath('total',0);
  $m->update(['role'=>'inventory_manager']);$this->postJson($base.'/orders',$this->command($p,$w))->assertForbidden();
 }
 public function test_untracked_product_does_not_require_stock(): void {
  [$base,$p,$w]=$this->fixture();DB::table('products')->where('id',$p)->update(['track_inventory'=>false]);
  $this->postJson($base.'/orders',$this->command($p,$w,'5'))->assertCreated();
  $this->assertDatabaseCount('stock_movements',1);
 }
}
