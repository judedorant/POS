<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\Role;
use App\Actions\MoveInventory;
final class InventoryController {
 public function index(Request $r) {
  $data=$r->validate(['warehouse_id'=>'nullable|uuid','product_id'=>'nullable|uuid','page'=>'sometimes|integer|min:1']);
  $table=$r->route('view')==='movements'?'stock_movements':'inventory_stocks';
  $q=DB::table($table.' as s')->where('s.tenant_id',$r->attributes->get('tenant')->id)
   ->join('products as p',function($j){$j->on('p.id','=','s.product_id')->on('p.tenant_id','=','s.tenant_id');})
   ->join('warehouses as w',function($j){$j->on('w.id','=','s.warehouse_id')->on('w.tenant_id','=','s.tenant_id');})
   ->select('s.*','p.name as product_name','p.sku','p.unit','w.name as warehouse_name');
  foreach(['warehouse_id','product_id'] as $filter) if(!empty($data[$filter])) $q->where('s.'.$filter,$data[$filter]);
  if($table==='stock_movements') $q->join('inventory_operations as o',function($j){$j->on('o.id','=','s.operation_id')->on('o.tenant_id','=','s.tenant_id');})->addSelect('o.kind','o.reason','o.actor_membership_id');
  return response()->json($q->orderByDesc('s.created_at')->orderByDesc('s.id')->paginate(25));
 }
 public function store(Request $r,MoveInventory $action) {
  abort_unless(in_array('inventory.manage',Role::findOrFail($r->attributes->get('membership')->role)->permissions,true),403);
  $data=$r->validate([
   'operation_id'=>'required|uuid','kind'=>['required',Rule::in(['adjustment','transfer'])],
   'product_id'=>'required|uuid','warehouse_id'=>'required|uuid',
   'destination_id'=>'required_if:kind,transfer|nullable|uuid|different:warehouse_id',
   'quantity'=>['required','string','regex:/^-?(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,4})?$/'],
   'reason'=>'required|string|max:500'
  ]);
  $valid=DB::selectOne('select (cast(? as numeric) <> 0) as nonzero, (cast(? as numeric) > 0) as positive',[$data['quantity'],$data['quantity']]);
  if(!$valid->nonzero || ($data['kind']==='transfer' && !$valid->positive)) throw \Illuminate\Validation\ValidationException::withMessages(['quantity'=>'Use a nonzero adjustment or a positive transfer quantity.']);
  if($data['kind']==='adjustment') unset($data['destination_id']);
  $result=$action->execute($r->attributes->get('tenant')->id,$r->attributes->get('membership')->id,$data);
  return response()->json($result,$result['replayed']?200:201);
 }
}
