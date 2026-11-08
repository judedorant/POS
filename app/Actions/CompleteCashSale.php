<?php
namespace App\Actions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
final class CompleteCashSale {
 public function execute(object $tenant,string $actor,array $data, ?array $offlineCatalog = null, ?array $cardSettlement = null): array {
  usort($data['items'],fn($a,$b)=>strcmp($a['product_id'],$b['product_id']));
  foreach($data['items'] as &$line){$line=['product_id'=>$line['product_id'],'quantity'=>DB::selectOne('select cast(? as numeric(18,4))::text as q',[$line['quantity']])->q,'expected_price_minor'=>(int)$line['expected_price_minor']];}unset($line);
  $canonical=[$actor,$data['warehouse_id'],(int)$data['cash_tendered_minor'],$data['items']];
  if($cardSettlement!==null)$canonical[]=$cardSettlement;
  $hash=hash('sha256',json_encode($canonical));
  return DB::transaction(function()use($tenant,$actor,$data,$hash,$offlineCatalog,$cardSettlement){
   DB::select('select pg_advisory_xact_lock(hashtextextended(?,0))',['sale:'.$tenant->id.':'.$data['client_order_id']]);
   $old=DB::table('orders')->where('tenant_id',$tenant->id)->where('client_order_id',$data['client_order_id'])->first();
   if($old){abort_unless(hash_equals($old->payload_hash,$hash),409,'Order ID already used with a different payload.');return ['order_id'=>$old->id,'replayed'=>true];}
   if($cardSettlement===null)abort_if(DB::table('payment_sessions')->where('tenant_id',$tenant->id)->where('client_order_id',$data['client_order_id'])->exists(),409,'This order ID belongs to a card payment attempt.');
   $items=[];$total=0;
   // Product locks precede warehouse/stock locks, matching inventory operations.
   foreach($data['items'] as $line){
    $p=DB::table('products')->where('tenant_id',$tenant->id)->where('id',$line['product_id'])->sharedLock()->first();
    if($offlineCatalog !== null && isset($offlineCatalog[$line['product_id']])) $p=(object)$offlineCatalog[$line['product_id']];
    if(!$p||($offlineCatalog === null && !$p->is_active))throw ValidationException::withMessages(['items'=>'All products must be active in this business.']);
    if((int)$p->sale_price_minor!==$line['expected_price_minor'])throw ValidationException::withMessages(['items'=>'A product price changed. Refresh the catalog and review the cart.']);
    $amount=(int)DB::selectOne('select round(cast(? as numeric)*cast(? as numeric),0)::bigint as amount',[$line['quantity'],$p->sale_price_minor])->amount;
    $total+=$amount;if($total>999999999999)throw ValidationException::withMessages(['items'=>'Order total exceeds the supported limit.']);
    $items[]=['product'=>$p,'quantity'=>$line['quantity'],'amount'=>$amount];
   }
   $warehouse=DB::table('warehouses')->where('tenant_id',$tenant->id)->where('id',$data['warehouse_id'])->sharedLock()->first();
   if(!$warehouse||($offlineCatalog === null && !$warehouse->is_active))throw ValidationException::withMessages(['warehouse_id'=>'Select an active warehouse in this business.']);
   $cashApplied=$total-($cardSettlement['amount_minor']??0);
   if($cashApplied<0)throw ValidationException::withMessages(['payment'=>'Card amount exceeds total.']);
   if((int)$data['cash_tendered_minor']<$cashApplied)throw ValidationException::withMessages(['cash_tendered_minor'=>'Cash received is less than the order total.']);
   $id=(string)Str::uuid7();$operation=(string)Str::uuid7();
   DB::table('orders')->insert(['id'=>$id,'tenant_id'=>$tenant->id,'client_order_id'=>$data['client_order_id'],'payload_hash'=>$hash,'receipt_number'=>'POS-'.$id,'warehouse_id'=>$warehouse->id,'cashier_membership_id'=>$actor,'business_name'=>$tenant->name,'currency'=>$tenant->default_currency,'total_minor'=>$total,'cash_tendered_minor'=>$data['cash_tendered_minor'],'cash_applied_minor'=>$cashApplied,'change_minor'=>(int)$data['cash_tendered_minor']-$cashApplied,'completed_at'=>now()]);
   $tracked=array_filter($items,fn($item)=>$item['product']->track_inventory);
   if($tracked) DB::table('inventory_operations')->insert(['id'=>$operation,'tenant_id'=>$tenant->id,'operation_id'=>$id,'payload_hash'=>$hash,'kind'=>'sale','reason'=>'Receipt POS-'.$id,'actor_membership_id'=>$actor,'created_at'=>now()]);
   foreach($items as $item){$p=$item['product'];
    if($p->track_inventory){
     if($offlineCatalog !== null) DB::statement('INSERT INTO inventory_stocks (id,tenant_id,warehouse_id,product_id,on_hand,reserved,version,created_at,updated_at) VALUES (?,?,?,?,0,0,0,now(),now()) ON CONFLICT (tenant_id,warehouse_id,product_id) DO NOTHING',[(string)Str::uuid7(),$tenant->id,$warehouse->id,$p->id]);
     $stock=DB::table('inventory_stocks')->where('tenant_id',$tenant->id)->where('warehouse_id',$warehouse->id)->where('product_id',$p->id)->lockForUpdate()->first();
     if(!$stock)throw ValidationException::withMessages(['items'=>'Insufficient stock for '.$p->name]);
     $count=DB::update('UPDATE inventory_stocks SET on_hand=on_hand-cast(? as numeric), version=version+1, updated_at=now() WHERE id=? AND tenant_id=? AND (on_hand-reserved>=cast(? as numeric) OR ?)',[$item['quantity'],$stock->id,$tenant->id,$item['quantity'],$offlineCatalog !== null ? 'true' : 'false']);
     if($count!==1)throw ValidationException::withMessages(['items'=>'Insufficient stock for '.$p->name]);
     DB::table('stock_movements')->insert(['id'=>(string)Str::uuid7(),'tenant_id'=>$tenant->id,'operation_id'=>$operation,'warehouse_id'=>$warehouse->id,'product_id'=>$p->id,'quantity_delta'=>'-'.$item['quantity'],'created_at'=>now()]);
    }
    DB::table('order_items')->insert(['id'=>(string)Str::uuid7(),'tenant_id'=>$tenant->id,'order_id'=>$id,'product_id'=>$p->id,'name'=>$p->name,'sku'=>$p->sku,'unit'=>$p->unit,'quantity'=>$item['quantity'],'unit_price_minor'=>$p->sale_price_minor,'inventory_deducted'=>$p->track_inventory,'line_total_minor'=>$item['amount']]);
   }
   if($cashApplied>0 || $total===0) DB::table('transactions')->insert(['id'=>(string)Str::uuid7(),'tenant_id'=>$tenant->id,'order_id'=>$id,'amount_minor'=>$cashApplied,'currency'=>$tenant->default_currency,'processed_at'=>now()]);
   if($cardSettlement) DB::table('transactions')->insert(['id'=>(string)Str::uuid7(),'tenant_id'=>$tenant->id,'order_id'=>$id,'method'=>'card','provider_reference'=>$cardSettlement['payment_intent'],'amount_minor'=>$cardSettlement['amount_minor'],'currency'=>$tenant->default_currency,'processed_at'=>now()]);
   RecordAudit::record($tenant->id,'sale.completed','order',$id,$cardSettlement?null:$actor,['total_minor'=>(string)$total,'currency'=>$tenant->default_currency,'warehouse_id'=>$warehouse->id,'cashier_membership_id'=>$actor,'source'=>$cardSettlement?'stripe':($offlineCatalog?'offline':'cash')],$cardSettlement?'provider':'user');
   RecordTenantChange::record($tenant->id,['inventory','sales']);
   return ['order_id'=>$id,'replayed'=>false];
  });
 }
}
