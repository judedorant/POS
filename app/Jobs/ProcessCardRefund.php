<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\{ShouldQueue,ShouldBeUnique};
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use App\Actions\{StripeGateway,ApplyProviderRefund};
final class ProcessCardRefund implements ShouldQueue,ShouldBeUnique {
 use Queueable;
 public int $tries=5;public int $timeout=30;public int $uniqueFor=300;
 public function __construct(public string $tenant,public string $allocation){}
 public function uniqueId():string{return $this->tenant.':'.$this->allocation;}
 public function backoff():array{return [5,30,60,120];}
 public function handle():void{
  $data=DB::transaction(function(){
   DB::select("select set_config('app.tenant_id',?,true)",[$this->tenant]);
   $a=DB::table('refund_allocations')->where('tenant_id',$this->tenant)->where('id',$this->allocation)->firstOrFail();
   $payment=DB::table('transactions')->where('tenant_id',$this->tenant)->where('id',$a->transaction_id)->firstOrFail();return [$a,$payment];
  });[$a,$payment]=$data;if($a->state==='completed')return;
  if($a->provider_id)$remote=(new StripeGateway)->request('GET','refunds/'.$a->provider_id);
  else $remote=(new StripeGateway)->request('POST','refunds',['payment_intent'=>$payment->provider_reference,'amount'=>$a->amount_minor,'metadata'=>['tenant_id'=>$this->tenant,'allocation_id'=>$a->id,'attempt'=>(string)$a->attempt]],'pos-refund-'.$a->id.($a->attempt?'-attempt-'.$a->attempt:''));
  (new ApplyProviderRefund)->execute($this->tenant,$a->id,$remote);
 }
}
