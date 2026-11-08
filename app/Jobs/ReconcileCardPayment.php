<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\{ShouldQueue,ShouldBeUnique};
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use App\Actions\{StripeGateway,SettleCardPayment};
final class ReconcileCardPayment implements ShouldQueue,ShouldBeUnique {
 use Queueable;
 public int $tries=3;public int $timeout=30;public int $uniqueFor=300;
 public function __construct(public string $tenant,public string $session){}
 public function uniqueId():string{return $this->tenant.':'.$this->session;}
 public function backoff():array{return [10,60,120];}
 public function handle():void{
  $s=DB::transaction(function(){DB::select("select set_config('app.tenant_id',?,true)",[$this->tenant]);return DB::table('payment_sessions')->where('tenant_id',$this->tenant)->where('id',$this->session)->firstOrFail();});
  if($s->state!=='reserved'||!$s->provider_id)return;
  $remote=(new StripeGateway)->request('GET','checkout/sessions/'.$s->provider_id);
  (new SettleCardPayment)->execute($this->tenant,$s->id,$remote);
 }
}
