<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Role;
use App\Actions\SyncOfflineSale;
final class OfflineController {
 private function sales(Request $r): void {abort_unless(in_array('sales.create',Role::findOrFail($r->attributes->get('membership')->role)->permissions,true),403);}
 public function prepare(Request $r){
  $this->sales($r);$data=$r->validate(['device_id'=>'required|uuid','warehouse_id'=>'required|uuid']);$tenant=$r->attributes->get('tenant');
  DB::table('warehouses')->where('tenant_id',$tenant->id)->where('id',$data['warehouse_id'])->where('is_active',true)->firstOrFail();
  $products=DB::table('products')->where('tenant_id',$tenant->id)->where('is_active',true)->orderBy('id')->limit(5001)->get();
  abort_if($products->count()>5000,422,'Offline catalog currently supports up to 5,000 products.');
  $id=(string)Str::uuid7();$issued=now();$expires=$issued->copy()->addHours(8);
  $catalog=$products->keyBy('id')->toJson();
  DB::table('offline_leases')->insert(['id'=>$id,'tenant_id'=>$tenant->id,'membership_id'=>$r->attributes->get('membership')->id,'device_id'=>$data['device_id'],'warehouse_id'=>$data['warehouse_id'],'catalog'=>$catalog,'issued_at'=>$issued,'expires_at'=>$expires]);
  \App\Actions\RecordAudit::record($tenant->id,'offline.lease_issued','offline_lease',$id,$r->attributes->get('membership')->id,['warehouse_id'=>$data['warehouse_id'],'device_id'=>$data['device_id'],'expires_at'=>$expires->toISOString()]);
  return response()->json(['lease_id'=>$id,'device_id'=>$data['device_id'],'warehouse_id'=>$data['warehouse_id'],'issued_at'=>$issued->toISOString(),'expires_at'=>$expires->toISOString(),'products'=>$products,'stocks'=>DB::table('inventory_stocks')->where('tenant_id',$tenant->id)->where('warehouse_id',$data['warehouse_id'])->get()],201);
 }
 public function sync(Request $r,SyncOfflineSale $action){
  $this->sales($r);$data=$r->validate(['orders'=>'required|array|min:1|max:50','orders.*'=>'required|array']);
  $results=[];foreach($data['orders'] as $order){
   try{$results[]=$action->execute($r->attributes->get('tenant'),$r->attributes->get('membership'),$r->user()->id,$order);}
   catch(\Throwable $e){report($e);$results[]=['operation_id'=>$order['operation_id']??null,'status'=>'retryable','code'=>'temporary_failure'];}
  }
  return response()->json(['results'=>$results]);
 }
 public function reviews(Request $r){
  abort_unless($r->attributes->get('membership')->role==='admin',403);
  return response()->json(DB::table('sync_operations')->where('tenant_id',$r->attributes->get('tenant')->id)->where('status','requires_review')->orderBy('created_at')->paginate(25));
 }
 public function review(Request $r,SyncOfflineSale $action){
  abort_unless($r->attributes->get('membership')->role==='admin',403);
  $data=$r->validate(['decision'=>'required|in:approve,reject','note'=>'required|string|max:1000']);$tenant=$r->attributes->get('tenant');
  return DB::transaction(function()use($r,$data,$tenant,$action){
   $op=DB::table('sync_operations')->where('tenant_id',$tenant->id)->where('id',$r->route('operation'))->lockForUpdate()->firstOrFail();
   abort_unless($op->status==='requires_review',409,'This operation has already been reviewed.');
   $order=null;$status='rejected';$code='review_rejected';
   if($data['decision']==='approve'){
    $lease=DB::table('offline_leases')->where('tenant_id',$tenant->id)->where('id',$op->lease_id)->firstOrFail();
    $order=$action->complete($tenant,$lease,json_decode($op->payload,true))['order_id'];$status='accepted';$code=$action->negative($tenant->id,$lease->warehouse_id)?'negative_stock':'review_approved';
   }
   DB::table('sync_operations')->where('tenant_id',$tenant->id)->where('id',$op->id)->update(['status'=>$status,'code'=>$code,'order_id'=>$order,'reviewer_id'=>$r->attributes->get('membership')->id,'review_note'=>$data['note'],'updated_at'=>now()]);
   \App\Actions\RecordAudit::record($tenant->id,'offline.reviewed','sync_operation',$op->id,$r->attributes->get('membership')->id,['decision'=>$data['decision'],'note'=>$data['note'],'order_id'=>$order]);
   return response()->json(['status'=>$status,'code'=>$code,'order_id'=>$order]);
  });
 }
 public function revoke(Request $r){
  abort_unless($r->attributes->get('membership')->role==='admin',403);
  $lease=DB::table('offline_leases')->where('tenant_id',$r->attributes->get('tenant')->id)->where('id',$r->route('lease'))->firstOrFail();
  DB::table('offline_leases')->where('tenant_id',$lease->tenant_id)->where('id',$lease->id)->update(['revoked_at'=>now()]);\App\Actions\RecordAudit::record($lease->tenant_id,'offline.lease_revoked','offline_lease',$lease->id,$r->attributes->get('membership')->id);return response()->noContent();
 }
}
