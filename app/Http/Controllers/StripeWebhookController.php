<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Actions\{StripeGateway,SettleCardPayment,ApplyProviderRefund};
final class StripeWebhookController {
 public function __invoke(Request $r,StripeGateway $gateway){
  $event=$gateway->event($r->getContent(),$r->header('Stripe-Signature',''));$object=$event['data']['object'];
  if(!in_array($event['type'],['checkout.session.completed','checkout.session.expired','refund.updated','refund.created','refund.failed']))return response()->json(['received'=>true]);
  abort_unless(($event['livemode']??true)===false,400,'Test-mode events only.');
  $tenant=$object['metadata']['tenant_id']??'';abort_unless(is_string($tenant)&&Str::isUuid($tenant),400,'Missing tenant metadata.');
  DB::transaction(function()use($tenant,$object,$event){
   DB::select("select set_config('app.tenant_id',?,true)",[$tenant]);
   DB::select('select pg_advisory_xact_lock(hashtextextended(?,0))',['stripe-event:'.$event['id']]);
   if(DB::table('provider_events')->where('id',$event['id'])->where('tenant_id',$tenant)->exists())return;
   if(str_starts_with($event['type'],'checkout.session.')){
    $id=$object['metadata']['session_id']??'';abort_unless(is_string($id)&&Str::isUuid($id),400);
    (new SettleCardPayment)->execute($tenant,$id,$object);
   }else{
    $id=$object['metadata']['allocation_id']??'';abort_unless(is_string($id)&&Str::isUuid($id),400);
    (new ApplyProviderRefund)->execute($tenant,$id,$object);
   }
   DB::table('provider_events')->insert(['id'=>$event['id'],'tenant_id'=>$tenant,'type'=>$event['type'],'created_at'=>now()]);
  });return response()->json(['received'=>true]);
 }
}
