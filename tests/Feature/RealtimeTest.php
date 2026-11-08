<?php
namespace Tests\Feature;
use App\Models\{User,Tenant,TenantMembership};
use App\Actions\RecordTenantChange;
use App\Jobs\DeliverTenantOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Broadcast};
use Tests\TestCase;
final class RealtimeTest extends TestCase {
 use RefreshDatabase;
 private function fixture(): array {
  $u=User::create(['name'=>'User','email'=>'realtime@example.com','password'=>'long-password']);$t=Tenant::create(['name'=>'Shop','slug'=>'shop']);$m=TenantMembership::create(['tenant_id'=>$t->id,'user_id'=>$u->id,'role'=>'admin']);$this->actingAs($u);return [$t,$m];
 }
 public function test_committed_changes_have_ordered_cursors_and_no_data_leak(): void {
  [$t]=$this->fixture();$base='/api/v1/tenants/'.$t->id;
  $this->postJson($base.'/categories',['name'=>'First','slug'=>'first'])->assertCreated();
  $initial=$this->getJson($base.'/changes')->assertOk()->assertJsonPath('reset',true)->json();
  $this->postJson($base.'/categories',['name'=>'Second','slug'=>'second'])->assertCreated();
  $this->getJson($base.'/changes?epoch='.$initial['epoch'].'&after='.$initial['cursor'])->assertOk()->assertJsonPath('reset',false)->assertJsonPath('events.0.sequence','2')->assertJsonPath('events.0.topics.0','catalog')->assertJsonMissingPath('events.0.name');
  $other=Tenant::create(['name'=>'Other','slug'=>'other']);$this->getJson('/api/v1/tenants/'.$other->id.'/changes')->assertNotFound();
 }
 public function test_rollback_does_not_advance_stream_or_leave_event(): void {
  [$t]=$this->fixture();
  try{DB::transaction(function()use($t){RecordTenantChange::record($t->id,['inventory']);throw new \RuntimeException('rollback');});}catch(\RuntimeException $e){}
  $this->assertDatabaseCount('outbox_events',0);$this->assertDatabaseCount('tenant_streams',0);
  DB::transaction(fn()=>RecordTenantChange::record($t->id,['inventory']));$this->assertSame(1,(int)DB::table('tenant_streams')->value('sequence'));
 }
 public function test_channel_auth_requires_active_tenant_membership(): void {
  [$t,$m]=$this->fixture();$this->withHeader('Origin','http://localhost')->postJson('/api/v1/broadcasting/auth',['socket_id'=>'123.456','channel_name'=>'private-tenants.'.$t->id])->assertOk()->assertJsonStructure(['auth']);
  $other=Tenant::create(['name'=>'Other','slug'=>'other']);$this->postJson('/api/v1/broadcasting/auth',['socket_id'=>'123.456','channel_name'=>'private-tenants.'.$other->id])->assertForbidden();
  $m->update(['status'=>'suspended']);$this->postJson('/api/v1/broadcasting/auth',['socket_id'=>'123.456','channel_name'=>'private-tenants.'.$t->id])->assertForbidden();
 }
 public function test_delivery_failure_is_retained_then_retried_and_acknowledged(): void {
  [$t]=$this->fixture();DB::transaction(fn()=>RecordTenantChange::record($t->id,['sales']));
  $broadcaster=\Mockery::mock();Broadcast::shouldReceive('connection')->with('reverb')->andReturn($broadcaster);
  $broadcaster->shouldReceive('broadcast')->once()->andThrow(new \RuntimeException('unavailable'));
  (new DeliverTenantOutbox($t->id))->handle();
  $this->assertDatabaseHas('outbox_events',['attempts'=>1,'delivered_at'=>null,'last_error'=>'broadcast_failed']);
  $this->travel(10)->seconds();
  $broadcaster->shouldReceive('broadcast')->once()->with(['private-tenants.'.$t->id],'tenant.changed',\Mockery::on(fn($p)=>isset($p['event_id'])&&$p['sequence']==='1'&&count($p)===2))->andReturnNull();
  (new DeliverTenantOutbox($t->id))->handle();
  $this->assertNotNull(DB::table('outbox_events')->value('delivered_at'));
  (new DeliverTenantOutbox($t->id))->handle();$this->assertSame(2,(int)DB::table('outbox_events')->value('attempts'));
 }
}
