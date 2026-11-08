<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Role;
use App\Actions\CompleteCashSale;
final class SaleController {
 private function authorize(Request $r,string $permission): void {abort_unless(in_array($permission,Role::findOrFail($r->attributes->get('membership')->role)->permissions,true),403);}
 private function orders(Request $r){$q=DB::table('orders')->where('tenant_id',$r->attributes->get('tenant')->id);if($r->attributes->get('membership')->role!=='admin')$q->where('cashier_membership_id',$r->attributes->get('membership')->id);return $q;}
 private function receipt(Request $r,string $id): array {
  $order=$this->orders($r)->where('id',$id)->firstOrFail();
  return ['refund_allocations'=>DB::table('refund_allocations')->where('tenant_id',$order->tenant_id)->whereIn('refund_id',DB::table('refunds')->select('id')->where('tenant_id',$order->tenant_id)->where('order_id',$id))->get(['id','state','amount_minor']),'order'=>$order,'items'=>DB::table('order_items')->where('tenant_id',$order->tenant_id)->where('order_id',$id)->orderBy('id')->get(),'refunds'=>DB::table('refunds')->where('tenant_id',$order->tenant_id)->where('order_id',$id)->get(),'payments'=>DB::table('transactions')->where('tenant_id',$order->tenant_id)->where('order_id',$id)->get()];
 }
 public function index(Request $r){$this->authorize($r,'sales.read');$r->validate(['page'=>'sometimes|integer|min:1']);return response()->json($this->orders($r)->orderByDesc('completed_at')->orderByDesc('id')->paginate(25));}
 public function show(Request $r){$this->authorize($r,'sales.read');return response()->json($this->receipt($r,$r->route('order')));}
 public function store(Request $r,CompleteCashSale $action){
  $this->authorize($r,'sales.create');
  $data=$r->validate(['client_order_id'=>'required|uuid','warehouse_id'=>'required|uuid','cash_tendered_minor'=>'required|integer|min:0|max:999999999999','items'=>'required|array|min:1|max:100','items.*.product_id'=>'required|uuid|distinct','items.*.expected_price_minor'=>'required|integer|min:0|max:999999999999','items.*.quantity'=>['required','string','regex:/^(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,4})?$/']]);
  foreach($data['items'] as $item)if(!preg_match('/[1-9]/',$item['quantity']))throw \Illuminate\Validation\ValidationException::withMessages(['items'=>'Quantity must be positive.']);
  $result=$action->execute($r->attributes->get('tenant'),$r->attributes->get('membership')->id,$data);
  return response()->json([...$this->receipt($r,$result['order_id']),'replayed'=>$result['replayed']],$result['replayed']?200:201);
 }
}
