<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
final class ChangeFeedController {
 public function __invoke(Request $r){
  $data=$r->validate(['after'=>['sometimes','regex:/^[0-9]{1,18}$/'],'epoch'=>'nullable|uuid']);$tenant=$r->attributes->get('tenant')->id;
  $stream=DB::table('tenant_streams')->where('tenant_id',$tenant)->first();$after=(int)($data['after']??0);
  if(!$stream)return response()->json(['epoch'=>null,'cursor'=>'0','events'=>[],'reset'=>true,'has_more'=>false]);
  if(($data['epoch']??null)!==$stream->epoch||$after>$stream->sequence)return response()->json(['epoch'=>$stream->epoch,'cursor'=>(string)$stream->sequence,'events'=>[],'reset'=>true,'has_more'=>false]);
  $events=DB::table('outbox_events')->where('tenant_id',$tenant)->where('sequence','>',$after)->where('sequence','<=',$stream->sequence)->orderBy('sequence')->limit(100)->get(['id','sequence','topics']);
  $cursor=$events->isEmpty()?$after:$events->last()->sequence;
  return response()->json(['epoch'=>$stream->epoch,'cursor'=>(string)$cursor,'reset'=>false,'has_more'=>$cursor<$stream->sequence,'events'=>$events->map(fn($e)=>['id'=>$e->id,'sequence'=>(string)$e->sequence,'topics'=>json_decode($e->topics,true)])]);
 }
}
