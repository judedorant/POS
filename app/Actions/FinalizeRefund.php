<?php
namespace App\Actions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
final class FinalizeRefund {
 public function execute(string $tenant,string $id): void {
  $refund=DB::table('refunds')->where('tenant_id',$tenant)->where('id',$id)->lockForUpdate()->firstOrFail();
  if($refund->state==='completed'||DB::table('refund_allocations')->where('tenant_id',$tenant)->where('refund_id',$id)->where('state','!=','completed')->exists())return;
  $sale=DB::table('orders')->where('tenant_id',$tenant)->where('id',$refund->order_id)->firstOrFail();
  $items=DB::table('refund_items as r')->join('order_items as i',function($j){$j->on('i.id','=','r.order_item_id')->on('i.tenant_id','=','r.tenant_id');})->where('r.tenant_id',$tenant)->where('r.refund_id',$id)->where('r.restock',true)->where('i.inventory_deducted',true)->orderBy('i.product_id')->get(['r.quantity','i.product_id']);
  foreach($items as $item)DB::table('products')->where('tenant_id',$tenant)->where('id',$item->product_id)->sharedLock()->firstOrFail();
  if($items->isNotEmpty()){
   DB::table('warehouses')->where('tenant_id',$tenant)->where('id',$sale->warehouse_id)->sharedLock()->firstOrFail();
   $operation=(string)Str::uuid7();DB::table('inventory_operations')->insert(['id'=>$operation,'tenant_id'=>$tenant,'operation_id'=>$refund->id,'payload_hash'=>$refund->payload_hash,'kind'=>'return','reason'=>$refund->reason,'actor_membership_id'=>$refund->actor_id,'created_at'=>now()]);
   foreach($items as $item){
    // The original sale necessarily created a stock row; historical product tracking is snapshotted.
    $stock=DB::table('inventory_stocks')->where('tenant_id',$tenant)->where('warehouse_id',$sale->warehouse_id)->where('product_id',$item->product_id)->lockForUpdate()->firstOrFail();
    DB::update('UPDATE inventory_stocks SET on_hand=on_hand+cast(? as numeric),version=version+1,updated_at=now() WHERE tenant_id=? AND id=?',[$item->quantity,$tenant,$stock->id]);
    DB::table('stock_movements')->insert(['id'=>(string)Str::uuid7(),'tenant_id'=>$tenant,'operation_id'=>$operation,'warehouse_id'=>$sale->warehouse_id,'product_id'=>$item->product_id,'quantity_delta'=>$item->quantity,'created_at'=>now()]);
   }
  }
  RecordAudit::record($tenant,'refund.completed','refund',$id,null,['order_id'=>$refund->order_id,'amount_minor'=>(string)$refund->amount_minor],'system');
  DB::table('refunds')->where('tenant_id',$tenant)->where('id',$id)->update(['state'=>'completed','updated_at'=>now()]);RecordTenantChange::record($tenant,['inventory','sales']);
 }
}
