<?php
namespace App\Http\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Closure;
final class RequestCorrelation {
 public function handle(Request $r,Closure $next){
  $id=(string)Str::uuid7();$r->attributes->set('request_id',$id);$response=$next($r);$response->headers->set('X-Request-ID',$id);return $response;
 }
}
