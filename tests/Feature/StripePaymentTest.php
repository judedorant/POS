<?php
namespace Tests\Feature;
use App\Models\{User,Tenant,TenantMembership};
use App\Actions\ApplyProviderRefund;
use App\Jobs\ProcessCardRefund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Tests\TestCase;
final class StripePaymentTest extends TestCase {
 use RefreshDatabase;
 private function fixture():array{
  config(['payments.stripe_key'=>'sk_test_fixture','payments.webhook_secret'=>'whsec_fixture']);Http::preventStrayRequests();
  $u=User::create(['name'=>'Admin','email'=>'stripe@example.com','password'=>'long-password']);$t=Tenant::create(['name'=>'Shop','slug'=>'shop']);$m=TenantMembership::create(['tenant_id'=>$t->id,'user_id'=>$u->id,'role'=>'admin']);$this->actingAs($u);$base='/api/v1/tenants/'.$t->id;
  $p=$this->postJson($base.'/products',['name'=>'Coffee','sku'=>'COF','sale_price_minor'=>250])->json('id');$w=$this->postJson($base.'/warehouses',['name'=>'Main','code'=>'MAIN'])->json('id');
  $this->postJson($base.'/inventory/operations',['operation_id'=>(string)Str::uuid(),'kind'=>'adjustment','product_id'=>$p,'warehouse_id'=>$w,'quantity'=>'10','reason'=>'Opening'])->assertCreated();
  $command=['client_order_id'=>(string)Str::uuid(),'warehouse_id'=>$w,'cash_applied_minor'=>100,'cash_tendered_minor'=>200,'items'=>[['product_id'=>$p,'quantity'=>'2','expected_price_minor'=>250]]];
  Http::fake(['api.stripe.com/v1/checkout/sessions'=>Http::response(['id'=>'cs_test_fixture','url'=>'https://checkout.stripe.com/test','livemode'=>false])]);
  $session=$this->postJson($base.'/payments',$command)->assertCreated()->json();return [$base,$session,$t,$command];
 }
 private function remote(array $s,object $t,string $status='complete'):array{return ['id'=>'cs_test_fixture','livemode'=>false,'metadata'=>['tenant_id'=>$t->id,'session_id'=>$s['id']],'status'=>$status,'payment_status'=>$status==='complete'?'paid':'unpaid','payment_intent'=>'pi_test_fixture','amount_total'=>400,'currency'=>'usd'];}
 private function webhook(array $object,string $type='checkout.session.completed',string $id='evt_test_fixture'){
  $body=json_encode(['id'=>$id,'type'=>$type,'livemode'=>false,'data'=>['object'=>$object]]);$time=time();$signature='t='.$time.',v1='.hash_hmac('sha256',$time.'.'.$body,'whsec_fixture');
  return $this->call('POST','/api/v1/webhooks/stripe',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_STRIPE_SIGNATURE'=>$signature],$body);
 }
 public function test_reserved_stock_split_tender_and_webhook_idempotency():void{
  [$base,$s,$tenant,$command]=$this->fixture();$this->assertSame('2.0000',DB::table('inventory_stocks')->value('reserved'));
  $this->postJson($base.'/payments',$command)->assertCreated();Http::assertSentCount(1);
  $this->webhook($this->remote($s,$tenant))->assertOk();$this->webhook($this->remote($s,$tenant))->assertOk();
  $this->assertDatabaseCount('orders',1);$this->assertDatabaseCount('transactions',2);$this->assertSame(1,DB::table('audit_logs')->where('action','sale.completed')->count());$this->assertDatabaseHas('audit_logs',['action'=>'payment.settled','actor_type'=>'provider','actor_id'=>null]);$this->assertSame('0.0000',DB::table('inventory_stocks')->value('reserved'));$this->assertSame('8.0000',DB::table('inventory_stocks')->value('on_hand'));
  $this->assertDatabaseHas('orders',['total_minor'=>500,'cash_applied_minor'=>100,'change_minor'=>100]);
  $this->webhook($this->remote($s,$tenant,'expired'),'checkout.session.expired','evt_expired_late')->assertOk();$this->assertDatabaseCount('orders',1);
 }
 public function test_expiry_releases_reservation_and_bad_signatures_are_rejected():void{
  [$base,$s,$tenant]=$this->fixture();$this->postJson('/api/v1/webhooks/stripe',['anything'=>'bad'])->assertStatus(400);
  $this->webhook($this->remote($s,$tenant,'expired'),'checkout.session.expired')->assertOk();$this->assertSame('0.0000',DB::table('inventory_stocks')->value('reserved'));$this->assertDatabaseCount('orders',0);
 }
 public function test_amount_mismatch_cannot_settle_and_stock_stays_reserved():void{
  [$base,$s,$tenant]=$this->fixture();$remote=$this->remote($s,$tenant);$remote['amount_total']=1;$this->webhook($remote)->assertStatus(409);$this->assertDatabaseCount('orders',0);$this->assertSame('2.0000',DB::table('inventory_stocks')->value('reserved'));
 }
 public function test_mixed_refund_cash_then_card_returns_stock_once():void{
  [$base,$s,$tenant]=$this->fixture();$this->webhook($this->remote($s,$tenant))->assertOk();$order=DB::table('orders')->first();$line=DB::table('order_items')->first();
  $refund=$this->postJson($base.'/orders/'.$order->id.'/refunds',['request_id'=>(string)Str::uuid(),'reason'=>'Return','items'=>[['order_item_id'=>$line->id,'quantity'=>'2','restock'=>true]]])->assertCreated()->assertJsonPath('refund.state','pending')->json('refund.id');
  $this->assertSame('8.0000',DB::table('inventory_stocks')->value('on_hand'));$allocation=DB::table('refund_allocations')->where('state','pending')->first();
  $remote=['id'=>'re_test_fixture','livemode'=>false,'metadata'=>['tenant_id'=>$tenant->id,'allocation_id'=>$allocation->id],'amount'=>400,'currency'=>'usd','payment_intent'=>'pi_test_fixture','status'=>'succeeded'];
  Http::fake(['api.stripe.com/v1/refunds'=>Http::response($remote)]);(new ProcessCardRefund($tenant->id,$allocation->id))->handle();
  $this->webhook($remote,'refund.updated','evt_refund')->assertOk();
  $this->assertDatabaseHas('refunds',['id'=>$refund,'state'=>'completed']);$this->assertSame('10.0000',DB::table('inventory_stocks')->value('on_hand'));$this->assertSame(500,(int)DB::table('transactions')->where('kind','refund')->sum('amount_minor'));
 }
 public function test_failed_refund_can_be_verified_and_retried_without_replaying_old_events():void{
  [$base,$s,$tenant]=$this->fixture();$this->webhook($this->remote($s,$tenant))->assertOk();$order=DB::table('orders')->first();$line=DB::table('order_items')->first();
  $this->postJson($base.'/orders/'.$order->id.'/refunds',['request_id'=>(string)Str::uuid(),'reason'=>'Return','items'=>[['order_item_id'=>$line->id,'quantity'=>'2','restock'=>true]]])->assertCreated();
  $a=DB::table('refund_allocations')->where('state','pending')->first();
  $failed=['id'=>'re_failed','livemode'=>false,'metadata'=>['tenant_id'=>$tenant->id,'allocation_id'=>$a->id,'attempt'=>'0'],'amount'=>400,'currency'=>'usd','payment_intent'=>'pi_test_fixture','status'=>'failed'];
  (new ApplyProviderRefund)->execute($tenant->id,$a->id,$failed);
  Http::fake(['api.stripe.com/v1/refunds/re_failed'=>Http::response($failed)]);
  $this->postJson($base.'/payments/refund-allocations/'.$a->id.'/retry',['note'=>'Verified provider failure'])->assertOk();
  $this->webhook($failed,'refund.failed','evt_old_failure')->assertOk();$this->assertDatabaseHas('refund_allocations',['id'=>$a->id,'state'=>'pending','attempt'=>1]);
  $success=[...$failed,'id'=>'re_retry_success','status'=>'succeeded','metadata'=>['tenant_id'=>$tenant->id,'allocation_id'=>$a->id,'attempt'=>'1']];
  (new ApplyProviderRefund)->execute($tenant->id,$a->id,$success);
  $this->assertSame('10.0000',DB::table('inventory_stocks')->value('on_hand'));$this->assertSame(500,(int)DB::table('transactions')->where('kind','refund')->sum('amount_minor'));
 }
 public function test_card_attempt_blocks_cash_reuse_of_order_id():void{
  [$base,$s,$tenant,$command]=$this->fixture();$command['cash_tendered_minor']=1000;
  $this->postJson($base.'/orders',$command)->assertConflict();$this->assertDatabaseCount('orders',0);$this->assertSame('2.0000',DB::table('inventory_stocks')->value('reserved'));
 }

}
