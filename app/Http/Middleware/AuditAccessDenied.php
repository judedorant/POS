<?php
namespace App\Http\Middleware;
use Illuminate\Http\Request;
use App\Actions\RecordAudit;
use Closure;
final class AuditAccessDenied {
 public function handle(Request $r,Closure $next){
  $response=$next($r);
  if($response->getStatusCode()===403&&$r->attributes->get('tenant'))RecordAudit::record($r->attributes->get('tenant')->id,'access.denied','tenant',$r->attributes->get('tenant')->id,$r->attributes->get('membership')->id,['method'=>$r->method(),'route'=>$r->route()?->uri()]);
  return $response;
 }
}
