<?php
namespace Tests\Feature;
use App\Models\{User,Tenant,TenantMembership};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
final class RefundTest extends TestCase {
 use RefreshDatabase;
 private function fixture():array{
  $u=User::create(['name'=>'Admin','email'=>'refund@example.com','password'=>'long-password']);$t=Tenant::create(['name'=>'Shop','slug'=>'shop']);$m=TenantMembership::create(['tenant_id'=>$t->id,'user_id'=>$u->id,'role'=>'admin']);$this->actingAs($u);$base='/api/v1/tenants/'.$t->id;
  $p=$this->postJson($base.'/products',['name'=>'Rice','sku'=>'RICE','sale_price_minor'=>101])->json('id');$w=$this->postJson($base.'/warehouses',['name'=>'Main','code'=>'MAIN'])->json('id');
  $this->postJson($base.'/inventory/operations',['operation_id'=>(string)Str::uuid(),'kind'=>'adjustment','product_id'=>$p,'warehouse_id'=>$w,'quantity'=>'10','reason'=>'Opening'])->assertCreated();
  $receipt=$this->postJson($base.'/orders',['client_order_id'=>(string)Str::uuid(),'warehouse_id'=>$w,'cash_tendered_minor'=>200,'items'=>[['product_id'=>$p,'quantity'=>'1.5','expected_price_minor'=>101]]])->assertCreated()->json();
  return [$base,$receipt,$m,$p];
 }
 private function command(string $line,string $quantity='0.5',bool $restock=true):array{return ['request_id'=>(string)Str::uuid(),'reason'=>'Customer return','items'=>[['order_item_id'=>$line,'quantity'=>$quantity,'restock'=>$restock]]];}
 public function test_partial_rounding_full_refund_and_duplicate_return():void{
  [$base,$receipt]=$this->fixture();$url=$base.'/orders/'.$receipt['order']['id'].'/refunds';$line=$receipt['items'][0]['id'];$command=$this->command($line);
  $this->postJson($url,$command)->assertCreated()->assertJsonPath('refund.amount_minor',51)->assertJsonPath('refund.state','completed');
  $this->postJson($url,$command)->assertCreated();$this->assertDatabaseCount('refunds',1);$this->assertSame('9.0000',DB::table('inventory_stocks')->value('on_hand'));
  $this->postJson($url,$this->command($line))->assertCreated()->assertJsonPath('refund.amount_minor',50);
  $this->postJson($url,$this->command($line))->assertCreated()->assertJsonPath('refund.amount_minor',51);
  $this->assertSame('10.0000',DB::table('inventory_stocks')->value('on_hand'));
  $this->assertSame(152,(int)DB::table('transactions')->where('kind','refund')->sum('amount_minor'));
  $this->postJson($url,$this->command($line))->assertUnprocessable();
 }
 public function test_no_restock_and_permissions_and_foreign_item():void{
  [$base,$receipt,$m,$product]=$this->fixture();$url=$base.'/orders/'.$receipt['order']['id'].'/refunds';
  $this->postJson($url,$this->command((string)Str::uuid()))->assertUnprocessable();
  $this->postJson($url,$this->command($receipt['items'][0]['id'],'0.5',false))->assertCreated();
  $this->assertSame('8.5000',DB::table('inventory_stocks')->value('on_hand'));
  DB::table('products')->where('id',$product)->update(['track_inventory'=>false]);
  $this->postJson($url,$this->command($receipt['items'][0]['id'],'1'))->assertCreated();
  $this->assertSame('9.5000',DB::table('inventory_stocks')->value('on_hand'));
  $m->update(['role'=>'cashier']);$this->postJson($url,$this->command($receipt['items'][0]['id']))->assertForbidden();
 }
}
