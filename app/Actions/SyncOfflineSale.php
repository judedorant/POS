<?php
namespace App\Actions;
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;
final class SyncOfflineSale {
 public function execute(object $tenant,object $member,string $user,array $raw): array {
  $v=Validator::make($raw,[
   'operation_id'=>'required|uuid','lease_id'=>'required|uuid','device_id'=>'required|uuid','occurred_at'=>'required|date',
   'cash_tendered_minor'=>'required|integer|min:0|max:999999999999','items'=>'required|array|min:1|max:100',
   'items.*.product_id'=>'required|uuid|distinct','items.*.quantity'=>['required','string','regex:/^(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,4})?$/'],'items.*.expected_price_minor'=>'required|integer|min:0|max:999999999999'
  ]);
  if($v->fails())return ['operation_id'=>$raw['operation_id']??null,'status'=>'rejected','code'=>'invalid_payload'];
  $data=$v->validated();usort($data['items'],fn($a,$b)=>strcmp($a['product_id'],$b['product_id']));
  $data['items']=array_map(fn($i)=>['product_id'=>$i['product_id'],'quantity'=>$i['quantity'],'expected_price_minor'=>(int)$i['expected_price_minor']],$data['items']);
  $data=['operation_id'=>$data['operation_id'],'lease_id'=>$data['lease_id'],'device_id'=>$data['device_id'],'occurred_at'=>$data['occurred_at'],'cash_tendered_minor'=>(int)$data['cash_tendered_minor'],'items'=>$data['items']];
  return DB::transaction(function()use($tenant,$member,$user,$data){
   DB::select("select set_config('app.user_id',?,true),set_config('app.tenant_id',?,true)",[$user,$tenant->id]);
   DB::select('select pg_advisory_xact_lock(hashtextextended(?,0))',['sync:'.$tenant->id.':'.$data['operation_id']]);
   $lease=DB::table('offline_leases')->where('tenant_id',$tenant->id)->where('id',$data['lease_id'])->where('membership_id',$member->id)->where('device_id',$data['device_id'])->first();
   if(!$lease)return ['operation_id'=>$data['operation_id'],'status'=>'rejected','code'=>'invalid_lease'];
   $hash=hash('sha256',json_encode($data));
   $old=DB::table('sync_operations')->where('tenant_id',$tenant->id)->where('operation_id',$data['operation_id'])->first();
   if($old){if(!hash_equals($old->payload_hash,$hash))return ['operation_id'=>$data['operation_id'],'status'=>'rejected','code'=>'idempotency_conflict'];return $this->result($old,true);}
   $catalog=json_decode($lease->catalog,true);$code='synced';$status='accepted';$order=null;
   foreach($data['items'] as $item){
    if(!preg_match('/[1-9]/',$item['quantity'])||!isset($catalog[$item['product_id']])||(int)$catalog[$item['product_id']]['sale_price_minor']!==$item['expected_price_minor']){$status='rejected';$code='invalid_catalog_or_quantity';break;}
   }
   $at=CarbonImmutable::parse($data['occurred_at']);
   if($status==='accepted'&&($lease->revoked_at||now()->greaterThan($lease->expires_at)||$at->lessThan($lease->issued_at)||$at->greaterThan($lease->expires_at)||$at->greaterThan(now()->addMinutes(5)))){$status='requires_review';$code='lease_time_or_revocation';}
   if($status==='accepted'){
    try{$result=$this->complete($tenant,$lease,$data);$order=$result['order_id'];$code=$this->negative($tenant->id,$lease->warehouse_id)?'negative_stock':'synced';}
    catch(\Illuminate\Validation\ValidationException $e){$status='rejected';$code='sale_validation_failed';}
    catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){if($e->getStatusCode()!==409)throw $e;$status='rejected';$code='order_id_conflict';}
   }
   $id=(string)Str::uuid7();DB::table('sync_operations')->insert(['id'=>$id,'tenant_id'=>$tenant->id,'lease_id'=>$lease->id,'operation_id'=>$data['operation_id'],'payload_hash'=>$hash,'payload'=>json_encode($data),'status'=>$status,'code'=>$code,'order_id'=>$order,'created_at'=>now(),'updated_at'=>now()]);
   return $this->result(DB::table('sync_operations')->where('tenant_id',$tenant->id)->where('id',$id)->first());
  });
 }
 public function complete(object $tenant,object $lease,array $data): array {
  return (new CompleteCashSale)->execute($tenant,$lease->membership_id,['client_order_id'=>$data['operation_id'],'warehouse_id'=>$lease->warehouse_id,'cash_tendered_minor'=>$data['cash_tendered_minor'],'items'=>$data['items']],json_decode($lease->catalog,true));
 }
 public function negative(string $tenant,string $warehouse): bool {return DB::table('inventory_stocks')->where('tenant_id',$tenant)->where('warehouse_id',$warehouse)->where('on_hand','<',0)->exists();}
 public function result(object $op,bool $replayed=false): array {return ['operation_id'=>$op->operation_id,'status'=>$op->status==='accepted'&&$replayed?'already_processed':$op->status,'code'=>$op->code,'order_id'=>$op->order_id,'review_id'=>$op->id];}
}
