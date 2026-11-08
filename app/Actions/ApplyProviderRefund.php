<?php
namespace App\Actions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
final class ApplyProviderRefund {
 public function execute(string $tenant,string $id,array $remote):void{
  DB::transaction(function()use($tenant,$id,$remote){
   DB::select("select set_config('app.tenant_id',?,true)",[$tenant]);
   $plain=DB::table('refund_allocations')->where('tenant_id',$tenant)->where('id',$id)->firstOrFail();
   $refund=DB::table('refunds')->where('tenant_id',$tenant)->where('id',$plain->refund_id)->lockForUpdate()->firstOrFail();
   $a=DB::table('refund_allocations')->where('tenant_id',$tenant)->where('id',$id)->lockForUpdate()->firstOrFail();
   $p=DB::table('transactions')->where('tenant_id',$tenant)->where('id',$a->transaction_id)->firstOrFail();
   $history=json_decode($a->provider_history,true);
   if(in_array($remote['id']??null,array_column($history,'id'),true))return;
   abort_unless((int)($remote['metadata']['attempt']??0)===$a->attempt,409,'Refund attempt mismatch.');
   abort_unless(($remote['livemode']??true)===false&&($remote['metadata']['tenant_id']??null)===$tenant&&($remote['metadata']['allocation_id']??null)===$id&&(int)($remote['amount']??-1)===(int)$a->amount_minor&&($remote['payment_intent']??null)===$p->provider_reference&&strtoupper($remote['currency']??'')===$p->currency&&isset($remote['id'])&&(!$a->provider_id||$a->provider_id===$remote['id']),409,'Refund provider mismatch.');
   if($a->state==='completed')return;
   $success=($remote['status']??null)==='succeeded';$state=$success?'completed':(in_array($remote['status']??'',['failed','canceled'])?'requires_review':'pending');
   if($a->state!==$state||$a->provider_id!==$remote['id'])RecordAudit::record($tenant,'refund.provider_updated','refund_allocation',$id,null,['refund_id'=>$a->refund_id,'state'=>$state,'amount_minor'=>(string)$a->amount_minor,'attempt'=>$a->attempt],'provider');
   DB::table('refund_allocations')->where('tenant_id',$tenant)->where('id',$id)->update(['state'=>$state,'provider_id'=>$remote['id'],'updated_at'=>now()]);
   if($success)DB::table('transactions')->insert(['id'=>(string)Str::uuid7(),'tenant_id'=>$tenant,'order_id'=>$refund->order_id,'method'=>'card','kind'=>'refund','refund_id'=>$refund->id,'original_transaction_id'=>$p->id,'provider_reference'=>$remote['id'],'amount_minor'=>$a->amount_minor,'currency'=>$p->currency,'processed_at'=>now()]);
   if($state==='requires_review')DB::table('refunds')->where('tenant_id',$tenant)->where('id',$refund->id)->update(['state'=>'requires_review','updated_at'=>now()]);
   (new FinalizeRefund)->execute($tenant,$refund->id);RecordTenantChange::record($tenant,['sales']);
  });
 }
}
