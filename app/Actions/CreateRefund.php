<?php
namespace App\Actions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
final class CreateRefund {
 public function execute(string $tenant,string $order,string $actor,array $data): object {
  usort($data['items'],fn($a,$b)=>strcmp($a['order_item_id'],$b['order_item_id']));
  $data=['request_id'=>$data['request_id'],'reason'=>$data['reason'],'items'=>array_map(fn($i)=>['order_item_id'=>$i['order_item_id'],'quantity'=>$i['quantity'],'restock'=>(bool)$i['restock']],$data['items'])];
  $hash=hash('sha256',json_encode([$order,$actor,$data]));
  return DB::transaction(function()use($tenant,$order,$actor,$data,$hash){
   DB::select('select pg_advisory_xact_lock(hashtextextended(?,0))',['refund:'.$tenant.':'.$data['request_id']]);
   $existing=DB::table('refunds')->where('tenant_id',$tenant)->where('request_id',$data['request_id'])->first();
   if($existing){abort_unless(hash_equals($existing->payload_hash,$hash),409,'Refund ID already used with a different payload.');$existing->replayed=true;return $existing;}
   $sale=DB::table('orders')->where('tenant_id',$tenant)->where('id',$order)->lockForUpdate()->firstOrFail();
   $lines=[];$total=0;
   foreach($data['items'] as $item){
    $line=DB::table('order_items')->where('tenant_id',$tenant)->where('order_id',$order)->where('id',$item['order_item_id'])->first();
    if(!$line)throw ValidationException::withMessages(['items'=>'Choose items from this receipt.']);
    $prior=DB::table('refund_items')->where('tenant_id',$tenant)->where('order_item_id',$line->id)->selectRaw('coalesce(sum(quantity),0)::text as quantity,coalesce(sum(amount_minor),0) as amount')->first();
    $calculation=DB::selectOne('select cast(? as numeric)>0 AND cast(? as numeric)+cast(? as numeric)<=cast(? as numeric) as valid, round(cast(? as numeric)*(cast(? as numeric)+cast(? as numeric))/cast(? as numeric),0)::bigint-cast(? as bigint) as amount',[$item['quantity'],$prior->quantity,$item['quantity'],$line->quantity,$line->line_total_minor,$prior->quantity,$item['quantity'],$line->quantity,$prior->amount]);
    if(!$calculation->valid)throw ValidationException::withMessages(['items'=>'Refund quantity exceeds the remaining sold quantity.']);
    $amount=(int)$calculation->amount;$total+=$amount;$lines[]=['line'=>$line,'quantity'=>$item['quantity'],'amount'=>$amount,'restock'=>$item['restock']];
   }
   $id=(string)Str::uuid7();DB::table('refunds')->insert(['id'=>$id,'tenant_id'=>$tenant,'order_id'=>$order,'request_id'=>$data['request_id'],'actor_id'=>$actor,'payload_hash'=>$hash,'amount_minor'=>$total,'reason'=>$data['reason'],'created_at'=>now(),'updated_at'=>now()]);
   foreach($lines as $item)DB::table('refund_items')->insert(['id'=>(string)Str::uuid7(),'tenant_id'=>$tenant,'refund_id'=>$id,'order_item_id'=>$item['line']->id,'quantity'=>$item['quantity'],'amount_minor'=>$item['amount'],'restock'=>$item['restock']]);
   $remaining=$total;
   $payments=DB::table('transactions')->where('tenant_id',$tenant)->where('order_id',$order)->where('kind','payment')->orderByRaw("CASE WHEN method='cash' THEN 0 ELSE 1 END")->orderBy('id')->get();
   foreach($payments as $payment){
    if(!$remaining)break;
    $allocated=(int)DB::table('refund_allocations')->where('tenant_id',$tenant)->where('transaction_id',$payment->id)->sum('amount_minor');
    $amount=min($remaining,(int)$payment->amount_minor-$allocated);if($amount<=0)continue;$allocation=(string)Str::uuid7();
    DB::table('refund_allocations')->insert(['id'=>$allocation,'tenant_id'=>$tenant,'refund_id'=>$id,'transaction_id'=>$payment->id,'amount_minor'=>$amount,'state'=>$payment->method==='cash'?'completed':'pending','created_at'=>now(),'updated_at'=>now()]);
    if($payment->method==='cash')DB::table('transactions')->insert(['id'=>(string)Str::uuid7(),'tenant_id'=>$tenant,'order_id'=>$order,'kind'=>'refund','refund_id'=>$id,'original_transaction_id'=>$payment->id,'amount_minor'=>$amount,'currency'=>$sale->currency,'processed_at'=>now()]);
    $remaining-=$amount;
   }
   if($remaining)throw ValidationException::withMessages(['payment'=>'Refund exceeds the unrefunded payment balance.']);
   RecordAudit::record($tenant,'refund.requested','refund',$id,$actor,['order_id'=>$order,'amount_minor'=>(string)$total,'reason'=>$data['reason']]);
   (new FinalizeRefund)->execute($tenant,$id);
   RecordTenantChange::record($tenant,['sales']);
   $result=DB::table('refunds')->where('tenant_id',$tenant)->where('id',$id)->first();$result->replayed=false;return $result;
  });
 }
}
