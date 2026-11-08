<?php
namespace Tests\Feature;
use App\Models\{User,Tenant,TenantMembership};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
final class InventoryTest extends TestCase {
 use RefreshDatabase;
 private function fixture(): array {
  $u=User::create(['name'=>'Manager','email'=>'manager@example.com','password'=>'long-password']);
  $t=Tenant::create(['name'=>'Shop','slug'=>'shop']);
  $m=TenantMembership::create(['tenant_id'=>$t->id,'user_id'=>$u->id,'role'=>'inventory_manager']);
  $base='/api/v1/tenants/'.$t->id; $this->actingAs($u);
  $p=$this->postJson($base.'/products',['name'=>'Rice','sku'=>'RICE','sale_price_minor'=>100,'unit'=>'kg'])->assertCreated()->json('id');
  $a=$this->postJson($base.'/warehouses',['name'=>'A','code'=>'A'])->json('id');
  $b=$this->postJson($base.'/warehouses',['name'=>'B','code'=>'B'])->json('id');
  return [$base,$p,$a,$b,$m];
 }
 private function command(string $p,string $a,string $quantity): array {return ['operation_id'=>(string)Str::uuid(),'kind'=>'adjustment','product_id'=>$p,'warehouse_id'=>$a,'quantity'=>$quantity,'reason'=>'Opening stock'];}
 public function test_decimal_transfer_reconciles_and_retries_are_idempotent(): void {
  [$base,$p,$a,$b]=$this->fixture(); $op=$this->command($p,$a,'10.1234');
  $this->postJson($base.'/inventory/operations',$op)->assertCreated();
  $this->postJson($base.'/inventory/operations',$op)->assertOk()->assertJsonPath('replayed',true);
  $changed=$op; $changed['quantity']='11'; $this->postJson($base.'/inventory/operations',$changed)->assertConflict();
  $transfer=[...$this->command($p,$a,'3.1234'),'kind'=>'transfer','destination_id'=>$b];
  $this->postJson($base.'/inventory/operations',$transfer)->assertCreated();
  $this->assertSame('7.0000',DB::table('inventory_stocks')->where('warehouse_id',$a)->value('on_hand'));
  $this->assertSame('3.1234',DB::table('inventory_stocks')->where('warehouse_id',$b)->value('on_hand'));
  foreach(DB::table('inventory_stocks')->get() as $stock) $this->assertSame($stock->on_hand,DB::table('stock_movements')->where('warehouse_id',$stock->warehouse_id)->sum('quantity_delta'));
  $this->getJson($base.'/inventory/movements')->assertOk()->assertJsonPath('total',3);
 }
 public function test_insufficient_transfer_rolls_back_all_effects(): void {
  [$base,$p,$a,$b]=$this->fixture();
  $this->postJson($base.'/inventory/operations',$this->command($p,$a,'2'))->assertCreated();
  $this->postJson($base.'/inventory/operations',[...$this->command($p,$a,'3'),'kind'=>'transfer','destination_id'=>$b])->assertUnprocessable();
  $this->assertDatabaseCount('stock_movements',1); $this->assertDatabaseCount('inventory_operations',1);
  $this->assertDatabaseCount('inventory_stocks',1);
  $this->assertSame('2.0000',DB::table('inventory_stocks')->value('on_hand'));
 }
 public function test_permissions_precision_and_foreign_warehouse(): void {
  [$base,$p,$a,$b,$m]=$this->fixture();
  foreach(['0','0.00001','1e2'] as $q) $this->postJson($base.'/inventory/operations',$this->command($p,$a,$q))->assertUnprocessable();
  $other=Tenant::create(['name'=>'Other','slug'=>'other']);
  $id=(string)Str::uuid(); DB::table('warehouses')->insert(['id'=>$id,'tenant_id'=>$other->id,'name'=>'Other','code'=>'OTHER']);
  $this->postJson($base.'/inventory/operations',$this->command($p,$id,'1'))->assertUnprocessable();
  $m->update(['role'=>'cashier']);
  $this->postJson($base.'/inventory/operations',$this->command($p,$a,'1'))->assertForbidden();
  $this->getJson($base.'/inventory/stocks')->assertOk();
 }
 public function test_ledger_cannot_be_edited(): void {
  [$base,$p,$a]=$this->fixture();
  $this->postJson($base.'/inventory/operations',$this->command($p,$a,'1'))->assertCreated();
  $this->expectException(\Illuminate\Database\QueryException::class);
  DB::table('stock_movements')->update(['quantity_delta'=>'999']);
 }
}
