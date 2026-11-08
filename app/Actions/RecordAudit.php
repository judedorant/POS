<?php
namespace App\Actions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
final class RecordAudit {
 public static function record(string $tenant,string $action,string $entity,string $id,?string $actor=null,array $details=[],string $actorType='user'):void {
  if(DB::transactionLevel()<1)throw new \LogicException('Audit entries must be recorded inside the business transaction.');
  // Only explicit, call-site-selected details are accepted; never serialize requests or credentials.
  $request=app()->bound('request')?request():null;$member=$request?->attributes->get('membership');
  if($actorType==='user'&&$member&&$member->tenant_id===$tenant)$actor=$member->id;
  DB::table('audit_logs')->insert(['id'=>(string)Str::uuid7(),'tenant_id'=>$tenant,'actor_id'=>$actor,'actor_type'=>$actorType,'action'=>$action,'entity_type'=>$entity,'entity_id'=>$id,'request_id'=>$request?->attributes->get('request_id'),'details'=>json_encode($details,JSON_THROW_ON_ERROR),'created_at'=>now()]);
 }
}
