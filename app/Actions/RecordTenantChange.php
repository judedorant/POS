<?php
namespace App\Actions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
final class RecordTenantChange {
 public static function record(string $tenant,array $topics): void {
  if(DB::transactionLevel()<1)throw new \LogicException('Tenant changes must be recorded inside the business transaction.');
  // This transactional counter locks until commit: a later sequence cannot commit first.
  DB::statement('INSERT INTO tenant_streams (tenant_id,epoch,sequence) VALUES (?,?,0) ON CONFLICT (tenant_id) DO NOTHING',[$tenant,(string)Str::uuid7()]);
  $stream=DB::selectOne('UPDATE tenant_streams SET sequence=sequence+1 WHERE tenant_id=? RETURNING sequence',[$tenant]);
  DB::table('outbox_events')->insert(['id'=>(string)Str::uuid7(),'tenant_id'=>$tenant,'sequence'=>$stream->sequence,'topics'=>json_encode(array_values(array_unique($topics))),'created_at'=>now(),'available_at'=>now()]);
 }
}
