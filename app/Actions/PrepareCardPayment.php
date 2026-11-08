<?php
namespace App\Actions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
final class PrepareCardPayment {
 public function execute(object $tenant,object $member,string $user,array $data): object {
  usort($data['items'],fn($a,$b)=>strcmp($a['product_id'],$b['product_id']));
  $data=['client_order_id'=>$data['client_order_id'],'warehouse_id'=>$data['warehouse_id'],'cash_tendered_minor'=>(int)$data['cash_tendered_minor'],'cash_applied_minor'=>(int)$data['cash_applied_minor'],'items'=>array_map(fn($i)=>['product_id'=>$i['product_id'],'quantity'=>$i['quantity'],'expected_price_minor'=>(int)$i['expected_price_minor']],$data['items'])];
  $hash=hash('sha256',json_encode([$member->id,$data]));
  return DB::transaction(function()use($tenant,$member,$user,$data,$hash){
   DB::select("select set_config('app.user_id',?,true),set_config('app.tenant_id',?,true)",[$user,$tenant->id]);
   DB::select('select pg_advisory_xact_lock(hashtextextended(?,0))',['sale:'.$tenant->id.':'.$data['client_order_id']]);
   $old=DB::table('payment_sessions')->where('tenant_id',$tenant->id)->where('client_order_id',$data['client_order_id'])->first();
   if($old){abort_unless(hash_equals($old->payload_hash,$hash),409,'Payment ID already used with a different payload.');return $old;}
   abort_if(DB::table('orders')->where('tenant_id',$tenant->id)->where('client_order_id',$data['client_order_id'])->exists(),409,'Order ID already completed.');
   $total=0;$catalog=[];
   foreach($data['items'] as $line){
    $p=DB::table('products')->where('tenant_id',$tenant->id)->where('id',$line['product_id'])->sharedLock()->first();
    if(!$p||!$p->is_active||(int)$p->sale_price_minor!==$line['expected_price_minor'])throw ValidationException::withMessages(['items'=>'A product is unavailable or its price changed.']);
    $total+=(int)DB::selectOne('select round(cast(? as numeric)*cast(? as numeric),0)::bigint as n',[$line['quantity'],$p->sale_price_minor])->n;$catalog[$p->id]=(array)$p;
   }
   if($total>99999999||$total<1||$data['cash_applied_minor']>=$total||$data['cash_tendered_minor']<$data['cash_applied_minor'])throw ValidationException::withMessages(['payment'=>'Use a positive card balance, sufficient cash, and a total at most 99,999,999 minor units.']);
   $minimum=['USD'=>50,'EUR'=>50,'GBP'=>30,'SGD'=>50][$tenant->default_currency]??50;
   if($total-$data['cash_applied_minor']<$minimum)throw ValidationException::withMessages(['payment'=>'Card portion is below the test provider minimum.']);
   DB::table('warehouses')->where('tenant_id',$tenant->id)->where('id',$data['warehouse_id'])->where('is_active',true)->sharedLock()->firstOrFail();
   foreach($data['items'] as $line)if($catalog[$line['product_id']]['track_inventory']){
    $stock=DB::table('inventory_stocks')->where('tenant_id',$tenant->id)->where('warehouse_id',$data['warehouse_id'])->where('product_id',$line['product_id'])->lockForUpdate()->first();
    if(!$stock)throw ValidationException::withMessages(['items'=>'Insufficient stock.']);
    $changed=DB::update('UPDATE inventory_stocks SET reserved=reserved+cast(? as numeric),version=version+1,updated_at=now() WHERE tenant_id=? AND id=? AND on_hand-reserved>=cast(? as numeric)',[$line['quantity'],$tenant->id,$stock->id,$line['quantity']]);
    if($changed!==1)throw ValidationException::withMessages(['items'=>'Insufficient stock.']);
   }
   $id=(string)Str::uuid7();DB::table('payment_sessions')->insert(['id'=>$id,'tenant_id'=>$tenant->id,'actor_id'=>$member->id,'warehouse_id'=>$data['warehouse_id'],'client_order_id'=>$data['client_order_id'],'payload_hash'=>$hash,'command'=>json_encode($data),'catalog'=>json_encode($catalog),'total_minor'=>$total,'card_minor'=>$total-$data['cash_applied_minor'],'currency'=>$tenant->default_currency,'expires_at'=>now()->addMinutes(35),'created_at'=>now(),'updated_at'=>now()]);
   RecordAudit::record($tenant->id,'payment.reserved','payment_session',$id,$member->id,['total_minor'=>(string)$total,'card_minor'=>(string)($total-$data['cash_applied_minor']),'currency'=>$tenant->default_currency]);
   RecordTenantChange::record($tenant->id,['inventory','sales']);return DB::table('payment_sessions')->where('tenant_id',$tenant->id)->where('id',$id)->first();
  });
 }
 public function initialize(object $session): object {
  if($session->provider_id||in_array($session->state,['completed','expired']))return $session;
  $url=rtrim(config('payments.return_url'),'/').'/?payment='.$session->id;
  $remote=(new StripeGateway)->request('POST','checkout/sessions',[
   'mode'=>'payment','payment_method_types'=>['card'],'success_url'=>$url,'cancel_url'=>$url,'expires_at'=>strtotime($session->expires_at),
   'metadata'=>['tenant_id'=>$session->tenant_id,'session_id'=>$session->id],
   'line_items'=>[['quantity'=>1,'price_data'=>['currency'=>strtolower($session->currency),'unit_amount'=>$session->card_minor,'product_data'=>['name'=>'POS sale '.$session->client_order_id]]]]
  ],'pos-checkout-'.$session->id);
  return DB::transaction(function()use($session,$remote){
   DB::select("select set_config('app.tenant_id',?,true)",[$session->tenant_id]);
   $local=DB::table('payment_sessions')->where('tenant_id',$session->tenant_id)->where('id',$session->id)->lockForUpdate()->firstOrFail();
   abort_unless(isset($remote['id'],$remote['url'])&&($remote['livemode']??true)===false,502,'Invalid test checkout response.');
   abort_if($local->provider_id&&$local->provider_id!==$remote['id'],409);
   DB::table('payment_sessions')->where('tenant_id',$session->tenant_id)->where('id',$session->id)->update(['provider_id'=>$remote['id'],'checkout_url'=>$remote['url'],'updated_at'=>now()]);
   return DB::table('payment_sessions')->where('tenant_id',$session->tenant_id)->where('id',$session->id)->first();
  });
 }
}
