<?php
namespace App\Actions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
final class MoveInventory {
 public function execute(string $tenant,string $actor,array $data): array {
  // All arithmetic stays in PostgreSQL NUMERIC; quantities never pass through floats.
  $quantity=DB::selectOne('select cast(? as numeric(18,4))::text as quantity',[$data['quantity']])->quantity;
  $canonical=[$actor,$data['kind'],$data['product_id'],$data['warehouse_id'],$data['destination_id']??null,$quantity,$data['reason']];
  $hash=hash('sha256',json_encode($canonical));
  return DB::transaction(function() use($tenant,$actor,$data,$hash,$quantity) {
   // Serializes retries of the same operation before either performs side effects.
   DB::select('select pg_advisory_xact_lock(hashtextextended(?,0))',[$tenant.':'.$data['operation_id']]);
   $old=DB::table('inventory_operations')->where('tenant_id',$tenant)->where('operation_id',$data['operation_id'])->first();
   if($old) { abort_unless(hash_equals($old->payload_hash,$hash),409,'Operation ID was already used with a different payload.'); return ['operation'=>$old,'replayed'=>true]; }
   $product=DB::table('products')->where('tenant_id',$tenant)->where('id',$data['product_id'])->sharedLock()->first();
   if(!$product || !$product->is_active || !$product->track_inventory) throw ValidationException::withMessages(['product_id'=>'Choose an active inventory-tracked product.']);
   $warehouses=[$data['warehouse_id']]; if($data['kind']==='transfer') $warehouses[]=$data['destination_id'];
   sort($warehouses);
   foreach($warehouses as $warehouse) {
    $location=DB::table('warehouses')->where('tenant_id',$tenant)->where('id',$warehouse)->sharedLock()->first();
    if(!$location || !$location->is_active) throw ValidationException::withMessages(['warehouse_id'=>'Choose active warehouses in this business.']);
    DB::statement('INSERT INTO inventory_stocks (id,tenant_id,warehouse_id,product_id,on_hand,reserved,version,created_at,updated_at) VALUES (?,?,?,?,0,0,0,now(),now()) ON CONFLICT (tenant_id,warehouse_id,product_id) DO NOTHING',[(string)Str::uuid7(),$tenant,$warehouse,$product->id]);
    DB::table('inventory_stocks')->where('tenant_id',$tenant)->where('warehouse_id',$warehouse)->where('product_id',$product->id)->lockForUpdate()->first();
   }
   $id=(string)Str::uuid7();
   DB::table('inventory_operations')->insert(['id'=>$id,'tenant_id'=>$tenant,'operation_id'=>$data['operation_id'],'payload_hash'=>$hash,'kind'=>$data['kind'],'reason'=>$data['reason'],'actor_membership_id'=>$actor,'created_at'=>now()]);
   $changes=$data['kind']==='transfer' ? [$data['warehouse_id']=>'-'.$quantity,$data['destination_id']=>$quantity] : [$data['warehouse_id']=>$quantity];
   foreach($changes as $warehouse=>$delta) {
    $updated=DB::update('UPDATE inventory_stocks SET on_hand=on_hand+cast(? as numeric), version=version+1, updated_at=now() WHERE tenant_id=? AND warehouse_id=? AND product_id=? AND on_hand+cast(? as numeric)>=reserved',[$delta,$tenant,$warehouse,$product->id,$delta]);
    if($updated!==1) throw ValidationException::withMessages(['quantity'=>'Insufficient available stock.']);
    DB::table('stock_movements')->insert(['id'=>(string)Str::uuid7(),'tenant_id'=>$tenant,'operation_id'=>$id,'warehouse_id'=>$warehouse,'product_id'=>$product->id,'quantity_delta'=>$delta,'created_at'=>now()]);
   }
   RecordAudit::record($tenant,$data['kind']==='transfer'?'inventory.transferred':'inventory.adjusted','inventory_operation',$id,$actor,['product_id'=>$product->id,'source_warehouse_id'=>$data['warehouse_id'],'destination_id'=>$data['destination_id']??null,'quantity'=>$quantity,'reason'=>$data['reason']]);
   RecordTenantChange::record($tenant,['inventory']);
   return ['operation'=>DB::table('inventory_operations')->where('id',$id)->where('tenant_id',$tenant)->first(),'replayed'=>false];
  });
 }
}
