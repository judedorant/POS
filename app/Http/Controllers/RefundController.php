<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Role;
use App\Actions\CreateRefund;
final class RefundController {
 private function allow(Request $r):void {abort_unless(in_array('sales.refund',Role::findOrFail($r->attributes->get('membership')->role)->permissions,true),403);}
 public function index(Request $r){$this->allow($r);$r->validate(['page'=>'sometimes|integer|min:1']);return response()->json(DB::table('refunds')->where('tenant_id',$r->attributes->get('tenant')->id)->orderByDesc('created_at')->paginate(25));}
 public function retry(Request $r){
  $this->allow($r);$r->validate(['note'=>'required|string|max:1000']);$tenant=$r->attributes->get('tenant')->id;
  $get=function()use($tenant,$r){return DB::table('refund_allocations')->where('tenant_id',$tenant)->where('id',$r->route('allocation'))->firstOrFail();};
  $a=DB::transaction(function()use($tenant,$r,$get){DB::select("select set_config('app.tenant_id',?,true)",[$tenant]);return $get();});
  abort_unless($a->state==='requires_review'&&$a->provider_id,409,'Only a confirmed failed provider refund can be retried.');
  $remote=(new \App\Actions\StripeGateway)->request('GET','refunds/'.$a->provider_id);
  abort_unless(($remote['id']??null)===$a->provider_id&&($remote['livemode']??true)===false&&in_array($remote['status']??'',['failed','canceled']),409,'Provider refund is not confirmed failed.');
  return DB::transaction(function()use($tenant,$r,$a,$remote){
   DB::select("select set_config('app.tenant_id',?,true)",[$tenant]);
   DB::table('refunds')->where('tenant_id',$tenant)->where('id',$a->refund_id)->lockForUpdate()->firstOrFail();
   $current=DB::table('refund_allocations')->where('tenant_id',$tenant)->where('id',$a->id)->lockForUpdate()->firstOrFail();
   abort_unless($current->state==='requires_review'&&$current->provider_id===$a->provider_id,409);
   $history=json_decode($current->provider_history,true);$history[]=['id'=>$remote['id'],'status'=>$remote['status'],'reviewer'=>$r->attributes->get('membership')->id,'note'=>$r->input('note'),'at'=>now()->toISOString()];
   DB::table('refund_allocations')->where('tenant_id',$tenant)->where('id',$a->id)->update(['state'=>'pending','provider_id'=>null,'attempt'=>$current->attempt+1,'provider_history'=>json_encode($history),'updated_at'=>now()]);
   DB::table('refunds')->where('tenant_id',$tenant)->where('id',$a->refund_id)->update(['state'=>'pending','updated_at'=>now()]);
   \App\Actions\RecordAudit::record($tenant,'refund.retry_authorized','refund_allocation',$a->id,$r->attributes->get('membership')->id,['attempt'=>$current->attempt+1,'note'=>$r->input('note')]);
   \App\Actions\RecordTenantChange::record($tenant,['sales']);return response()->json(['state'=>'pending']);
  });
 }
 public function store(Request $r,CreateRefund $action){
  $this->allow($r);$data=$r->validate(['request_id'=>'required|uuid','reason'=>'required|string|max:500','items'=>'required|array|min:1|max:100','items.*.order_item_id'=>'required|uuid|distinct','items.*.quantity'=>['required','string','regex:/^(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,4})?$/'],'items.*.restock'=>'required|boolean']);
  $refund=$action->execute($r->attributes->get('tenant')->id,$r->route('order'),$r->attributes->get('membership')->id,$data);
  return response()->json(['refund'=>$refund,'allocations'=>DB::table('refund_allocations as a')->join('transactions as p',function($j){$j->on('p.id','=','a.transaction_id')->on('p.tenant_id','=','a.tenant_id');})->where('a.tenant_id',$refund->tenant_id)->where('a.refund_id',$refund->id)->get(['a.*','p.method'])],201);
 }
}
