<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{DB,Broadcast};
final class DeliverTenantOutbox implements ShouldQueue, ShouldBeUnique {
 use Queueable;
 public int $uniqueFor=600;
 public function uniqueId(): string { return $this->tenant; }
 public int $tries=3;
 public int $timeout=60;
 public function __construct(public string $tenant){}
 public function handle(): void {
  // Bounded jobs; the scheduler discovers anything left behind after crashes.
  for($i=0;$i<10;$i++){
   $sent=DB::transaction(function(){
    DB::select("select set_config('app.tenant_id',?,true)",[$this->tenant]);
    $event=DB::table('outbox_events')->where('tenant_id',$this->tenant)->whereNull('delivered_at')->where('available_at','<=',now())->orderBy('sequence')->lock('FOR UPDATE SKIP LOCKED')->first();
    if(!$event)return false;
    try{
     // Broadcast hints carry no catalog, financial, or customer data.
     Broadcast::connection('reverb')->broadcast(['private-tenants.'.$this->tenant],'tenant.changed',['event_id'=>$event->id,'sequence'=>(string)$event->sequence]);
     DB::table('outbox_events')->where('tenant_id',$this->tenant)->where('id',$event->id)->update(['delivered_at'=>now(),'attempts'=>$event->attempts+1,'last_error'=>null]);
    }catch(\Throwable $e){
     report($e);
     DB::table('outbox_events')->where('tenant_id',$this->tenant)->where('id',$event->id)->update(['attempts'=>$event->attempts+1,'last_error'=>'broadcast_failed','available_at'=>now()->addSeconds(min(300,2**min($event->attempts+1,8)))]);
     return false;
    }
    return true;
   });
   if(!$sent)break;
  }
 }
}
