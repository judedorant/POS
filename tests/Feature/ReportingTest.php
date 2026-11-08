<?php
namespace Tests\Feature;
use App\Models\{User,Tenant,TenantMembership};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
final class ReportingTest extends TestCase {
 use RefreshDatabase;
 private function fixture(string $zone='UTC'):array {
  $u=User::create(['name'=>'Owner','email'=>'reports@example.com','password'=>'long-password']);$t=Tenant::create(['name'=>'Shop','slug'=>'shop','timezone'=>$zone,'default_currency'=>'USD']);$m=TenantMembership::create(['tenant_id'=>$t->id,'user_id'=>$u->id,'role'=>'admin']);$this->actingAs($u);$base='/api/v1/tenants/'.$t->id;
  $p=$this->postJson($base.'/products',['name'=>'Rice','sku'=>'RICE','sale_price_minor'=>250,'reorder_point'=>'2.0000'])->assertCreated()->json('id');$w=$this->postJson($base.'/warehouses',['name'=>'Main','code'=>'MAIN'])->assertCreated()->json('id');
  $this->postJson($base.'/inventory/operations',['operation_id'=>(string)Str::uuid(),'kind'=>'adjustment','product_id'=>$p,'warehouse_id'=>$w,'quantity'=>'10','reason'=>'Opening'])->assertCreated();return [$base,$p,$w,$m,$t];
 }
 private function sell(string $base,string $p,string $w):array {
  return $this->postJson($base.'/orders',['client_order_id'=>(string)Str::uuid(),'warehouse_id'=>$w,'cash_tendered_minor'=>1000,'items'=>[['product_id'=>$p,'quantity'=>'2','expected_price_minor'=>250]]])->assertCreated()->json();
 }
 public function test_sales_refunds_and_reconciliation_match_ledgers():void {
  $this->travelTo(now()->setDate(2026,10,29)->setTime(12,0));[$base,$p,$w]=$this->fixture();$receipt=$this->sell($base,$p,$w);
  $this->postJson($base.'/orders/'.$receipt['order']['id'].'/refunds',['request_id'=>(string)Str::uuid(),'reason'=>'Return','items'=>[['order_item_id'=>$receipt['items'][0]['id'],'quantity'=>'1','restock'=>true]]])->assertCreated();
  $query='?from=2026-10-29&to=2026-10-29';
  $this->getJson($base.'/reports/sales'.$query)->assertOk()->assertJsonPath('totals.0.order_count',1)->assertJsonPath('totals.0.gross_minor','500')->assertJsonPath('totals.0.captured_minor','500')->assertJsonPath('totals.0.refunded_minor','250')->assertJsonPath('totals.0.net_activity_minor','250')->assertJsonPath('daily.0.gross_minor','500')->assertJsonPath('tenders.0.net_minor','250');
  $this->getJson($base.'/reports/reconciliation'.$query)->assertOk()->assertJsonPath('checked_orders',1)->assertJsonPath('discrepancy_count',0)->assertJsonPath('balances.0.pending_refund_minor','0');
 }
 public function test_tenant_timezone_boundaries_and_refunds_of_older_orders():void {
  $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-28T15:59:59Z'));[$base,$p,$w]=$this->fixture('Asia/Singapore');$old=$this->sell($base,$p,$w);
  $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-28T16:00:00Z'));$this->sell($base,$p,$w);
  $this->postJson($base.'/orders/'.$old['order']['id'].'/refunds',['request_id'=>(string)Str::uuid(),'reason'=>'Older sale return','items'=>[['order_item_id'=>$old['items'][0]['id'],'quantity'=>'1','restock'=>false]]])->assertCreated();
  $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-29T16:00:00Z'));$this->sell($base,$p,$w);
  $this->getJson($base.'/reports/sales?from=2026-10-29&to=2026-10-29')->assertOk()->assertJsonPath('totals.0.order_count',1)->assertJsonPath('totals.0.gross_minor','500')->assertJsonPath('totals.0.refunded_minor','250')->assertJsonPath('period.start_utc','2026-10-28T16:00:00.000000Z')->assertJsonPath('period.end_utc','2026-10-29T16:00:00.000000Z');
 }
 public function test_low_stock_includes_missing_rows_and_reservations():void {
  [$base,$p,$w]=$this->fixture();$empty=$this->postJson($base.'/products',['name'=>'No stock','sku'=>'EMPTY','sale_price_minor'=>100])->json('id');
  $this->postJson($base.'/products',['name'=>'Service','sku'=>'SERVICE','sale_price_minor'=>100,'track_inventory'=>false])->assertCreated();
  DB::table('inventory_stocks')->where('product_id',$p)->update(['reserved'=>'9']);
  $this->getJson($base.'/reports/low-stock')->assertOk()->assertJsonPath('total',2)->assertJsonPath('data.0.product_id',$empty)->assertJsonPath('data.0.available','0')->assertJsonPath('data.1.available','1.0000')->assertJsonPath('data.1.reorder_point','2.0000');
 }
 public function test_report_permissions_isolation_and_period_validation():void {
  [$base,$p,$w,$member]=$this->fixture();$other=Tenant::create(['name'=>'Other','slug'=>'other']);
  $this->getJson('/api/v1/tenants/'.$other->id.'/reports/sales')->assertNotFound();
  $this->getJson($base.'/reports/sales?from=2025-01-01&to=2026-12-31')->assertUnprocessable();
  $member->update(['role'=>'inventory_manager']);$this->getJson($base.'/reports/low-stock')->assertOk();$this->getJson($base.'/reports/sales')->assertForbidden();$this->getJson($base.'/reports/audit')->assertForbidden();
  $member->update(['role'=>'cashier']);$this->getJson($base.'/reports/low-stock')->assertForbidden();
 }
 public function test_distinct_currencies_are_never_added_together():void {
  [$base,$p,$w,$member,$tenant]=$this->fixture();$this->sell($base,$p,$w);DB::table('tenants')->where('id',$tenant->id)->update(['default_currency'=>'EUR']);$this->sell($base,$p,$w);
  $this->getJson($base.'/reports/sales')->assertOk()->assertJsonCount(2,'totals')->assertJsonPath('totals.0.currency','EUR')->assertJsonPath('totals.0.gross_minor','500')->assertJsonPath('totals.1.currency','USD')->assertJsonPath('totals.1.gross_minor','500');
 }
 public function test_daylight_saving_day_uses_real_local_midnight_boundaries():void {
  $this->travelTo(\Carbon\CarbonImmutable::parse('2026-03-08T05:00:00Z'));[$base,$p,$w]=$this->fixture('America/New_York');$this->sell($base,$p,$w);
  $this->getJson($base.'/reports/sales?from=2026-03-08&to=2026-03-08')->assertOk()->assertJsonPath('totals.0.order_count',1)->assertJsonPath('period.start_utc','2026-03-08T05:00:00.000000Z')->assertJsonPath('period.end_utc','2026-03-09T04:00:00.000000Z');
 }
 public function test_reconciliation_detects_overcapture_and_large_money_stays_exact():void {
  [$base,$p,$w,$member,$tenant]=$this->fixture();$receipt=$this->sell($base,$p,$w);
  DB::table('transactions')->insert(['id'=>(string)Str::uuid(),'tenant_id'=>$tenant->id,'order_id'=>$receipt['order']['id'],'amount_minor'=>'9007199254740993','currency'=>'USD','processed_at'=>now()]);
  $this->getJson($base.'/reports/reconciliation')->assertOk()->assertJsonPath('discrepancy_count',1)->assertJsonPath('exceptions.0.captured_minor','9007199254741493');
  $this->getJson($base.'/reports/sales')->assertOk()->assertJsonPath('totals.0.captured_minor','9007199254741493');
 }

}
