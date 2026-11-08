<?php
namespace App\Actions;
use Illuminate\Support\Facades\Http;
final class StripeGateway {
 public function request(string $method,string $path,array $data=[],?string $key=null): array {
  $secret=(string)config('payments.stripe_key');
  abort_unless(str_starts_with($secret,'sk_test_'),503,'Stripe test mode is not configured.');
  $http=Http::asForm()->withBasicAuth($secret,'')->withHeaders(['Stripe-Version'=>'2025-02-24.acacia'])->connectTimeout(3)->timeout(15);
  if($key)$http=$http->withHeaders(['Idempotency-Key'=>$key]);
  $response=$method==='GET'?$http->get('https://api.stripe.com/v1/'.$path,$data):$http->post('https://api.stripe.com/v1/'.$path,$data);
  $response->throw();return $response->json();
 }
 public function event(string $body,string $signature): array {
  $secret=(string)config('payments.webhook_secret');abort_if($secret==='',503,'Webhook verification is not configured.');
  abort_if(strlen($body)>262144,413);
  $values=[];foreach(explode(',',$signature) as $pair){$parts=explode('=',$pair,2);if(count($parts)===2)$values[$parts[0]][]=$parts[1];}
  $timestamp=$values['t'][0]??'';abort_unless(ctype_digit($timestamp)&&abs(time()-(int)$timestamp)<=300,400,'Invalid webhook timestamp.');
  $hash=hash_hmac('sha256',$timestamp.'.'.$body,$secret);$valid=false;foreach($values['v1']??[] as $candidate)$valid=$valid||hash_equals($hash,$candidate);
  abort_unless($valid,400,'Invalid webhook signature.');$event=json_decode($body,true);abort_unless(is_array($event)&&isset($event['id'],$event['type'],$event['data']['object']),400,'Invalid webhook.');return $event;
 }
}
