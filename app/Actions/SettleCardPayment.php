<?php
namespace App\Actions;
use Illuminate\Support\Facades\DB;
use App\Models\Tenant;
final class SettleCardPayment {
 public function execute(string $tenant,string $id,array $remote): object {
  return DB::transaction(function()use($tenant,$id,$remote){
   DB::select("select set_config('app.tenant_id',?,true)",[$tenant]);
   $s=DB::table('payment_sessions')->where('tenant_id',$tenant)->where('id',$id)->lockForUpdate()->firstOrFail();
   abort_unless(($remote['livemode']??true)===false&&($remote['metadata']['tenant_id']??null)===$tenant&&($remote['metadata']['session_id']??null)===$id&&isset($remote['id'])&&(!$s->provider_id||$s->provider_id===$remote['id']),409,'Provider session mismatch.');
   if(in_array($s->state,['completed','expired']))return $s;
   $paid=($remote['payment_status']??null)==='paid'&&($remote['status']??null)==='complete';$expired=($remote['status']??null)==='expired';
   if(!$paid&&!$expired)return $s;
   if($paid)abort_unless((int)($remote['amount_total']??-1)===(int)$s->card_minor&&strtoupper($remote['currency']??'')===$s->currency&&is_string($remote['payment_intent']??null),409,'Payment amount/currency mismatch.');
   $data=json_decode($s->command,true);$catalog=json_decode($s->catalog,true);
   // Match the product -> warehouse -> stock lock order used by checkout/inventory.
   foreach($data['items'] as $line)DB::table('products')->where('tenant_id',$tenant)->where('id',$line['product_id'])->sharedLock()->firstOrFail();
   DB::table('warehouses')->where('tenant_id',$tenant)->where('id',$s->warehouse_id)->sharedLock()->firstOrFail();
   foreach($data['items'] as $line)if($catalog[$line['product_id']]['track_inventory']){
    DB::table('inventory_stocks')->where('tenant_id',$tenant)->where('warehouse_id',$s->warehouse_id)->where('product_id',$line['product_id'])->lockForUpdate()->firstOrFail();
    $count=DB::update('UPDATE inventory_stocks SET reserved=reserved-cast(? as numeric),version=version+1,updated_at=now() WHERE tenant_id=? AND warehouse_id=? AND product_id=? AND reserved>=cast(? as numeric)',[$line['quantity'],$tenant,$s->warehouse_id,$line['product_id'],$line['quantity']]);abort_unless($count===1,409,'Reservation requires reconciliation.');
   }
   $order=null;
   if($paid){
    $business=Tenant::findOrFail($tenant);$business->default_currency=$s->currency;
    $order=(new CompleteCashSale)->execute($business,$s->actor_id,$data,$catalog,['amount_minor'=>(int)$s->card_minor,'payment_intent'=>$remote['payment_intent']])['order_id'];
   }else RecordTenantChange::record($tenant,['inventory','sales']);
   RecordAudit::record($tenant,$paid?'payment.settled':'payment.expired','payment_session',$id,null,['order_id'=>$order,'card_minor'=>(string)$s->card_minor,'currency'=>$s->currency],'provider');
   DB::table('payment_sessions')->where('tenant_id',$tenant)->where('id',$id)->update(['state'=>$paid?'completed':'expired','provider_id'=>$remote['id'],'order_id'=>$order,'updated_at'=>now()]);
   return DB::table('payment_sessions')->where('tenant_id',$tenant)->where('id',$id)->first();
  });
 }
}
