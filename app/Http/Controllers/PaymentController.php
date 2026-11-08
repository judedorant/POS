<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Role;
use App\Actions\{PrepareCardPayment,StripeGateway,SettleCardPayment};
final class PaymentController {
 private function allow(Request $r):void {abort_unless(in_array('sales.create',Role::findOrFail($r->attributes->get('membership')->role)->permissions,true),403);}
 private function query(Request $r){$q=DB::table('payment_sessions')->where('tenant_id',$r->attributes->get('tenant')->id);if($r->attributes->get('membership')->role!=='admin')$q->where('actor_id',$r->attributes->get('membership')->id);return $q;}
 private function scope(Request $r,\Closure $fn){return DB::transaction(function()use($r,$fn){DB::select("select set_config('app.user_id',?,true),set_config('app.tenant_id',?,true)",[$r->user()->id,$r->attributes->get('tenant')->id]);return $fn();});}
 private function output(object $s):array{$data=json_decode($s->command,true);return ['cash_received_minor'=>$data['cash_tendered_minor'],'cash_applied_minor'=>$data['cash_applied_minor']]+array_intersect_key((array)$s,array_flip(['id','client_order_id','state','total_minor','card_minor','currency','checkout_url','order_id','expires_at']));}
 public function index(Request $r){$this->allow($r);return response()->json(['stripe_enabled'=>str_starts_with((string)config('payments.stripe_key'),'sk_test_'),'sessions'=>$this->query($r)->orderByDesc('created_at')->limit(25)->get()->map(fn($s)=>$this->output($s))]);}
 public function store(Request $r,PrepareCardPayment $action){
  $this->allow($r);abort_unless(str_starts_with((string)config('payments.stripe_key'),'sk_test_'),503,'Configure Stripe test credentials first.');
  $data=$r->validate(['client_order_id'=>'required|uuid','warehouse_id'=>'required|uuid','cash_applied_minor'=>'required|integer|min:0|max:99999999','cash_tendered_minor'=>'required|integer|min:0|max:999999999999','items'=>'required|array|min:1|max:100','items.*.product_id'=>'required|uuid|distinct','items.*.expected_price_minor'=>'required|integer|min:0|max:999999999999','items.*.quantity'=>['required','string','regex:/^(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,4})?$/']]);
  foreach($data['items'] as $line)if(!preg_match('/[1-9]/',$line['quantity']))throw \Illuminate\Validation\ValidationException::withMessages(['items'=>'Quantity must be positive.']);
  $s=$action->execute($r->attributes->get('tenant'),$r->attributes->get('membership'),$r->user()->id,$data);
  try{$s=$action->initialize($s);}catch(\Illuminate\Http\Client\RequestException|\Illuminate\Http\Client\ConnectionException $e){report($e);return response()->json(['message'=>'Provider result is pending. Retry the same payment request.','session'=>$this->output($s)],502);}
  return response()->json($this->output($s),201);
 }
 public function reconcile(Request $r){
  $this->allow($r);$s=$this->scope($r,fn()=>$this->query($r)->where('id',$r->route('session'))->firstOrFail());
  if(!$s->provider_id)$s=(new PrepareCardPayment)->initialize($s);
  $gateway=new StripeGateway;$remote=$gateway->request('GET','checkout/sessions/'.$s->provider_id);
  if($r->route('action')==='cancel'&&($remote['status']??null)==='open')$remote=$gateway->request('POST','checkout/sessions/'.$s->provider_id.'/expire',[],'pos-expire-'.$s->id);
  $s=(new SettleCardPayment)->execute($s->tenant_id,$s->id,$remote);return response()->json($this->output($s));
 }
}
