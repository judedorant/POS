<?php
namespace Tests\Feature;
use App\Models\{User,Tenant,TenantMembership};
use App\Actions\RecordAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
final class AuditTest extends TestCase {
 use RefreshDatabase;
 private function fixture():array {
  $u=User::create(['name'=>'Owner','email'=>'audit@example.com','password'=>'long-password']);$t=Tenant::create(['name'=>'Shop','slug'=>'shop']);$m=TenantMembership::create(['tenant_id'=>$t->id,'user_id'=>$u->id,'role'=>'admin']);$this->actingAs($u);return ['/api/v1/tenants/'.$t->id,$t,$m];
 }
 public function test_business_audit_captures_safe_changes_and_request_reference():void {
  [$base,$tenant,$member]=$this->fixture();$response=$this->postJson($base.'/products',['name'=>'Rice','sku'=>'RICE','sale_price_minor'=>100,'password'=>'never-record-this','stripe_secret'=>'never-record-this'])->assertCreated();$id=$response->json('id');
  $reference=$response->headers->get('X-Request-ID');$this->assertTrue(Str::isUuid($reference));
  $audit=$this->getJson($base.'/reports/audit?request_id='.$reference)->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.action','catalog.created')->assertJsonPath('data.0.actor_id',$member->id)->json('data.0');
  $details=$audit['details'];$this->assertSame('Rice',$details['after']['name']);$this->assertStringNotContainsString('never-record-this',json_encode($audit));
  $this->patchJson($base.'/products/'.$id,['sale_price_minor'=>150])->assertOk();
  $row=DB::table('audit_logs')->where('action','catalog.updated')->first();$change=json_decode($row->details,true);$this->assertSame(100,$change['before']['sale_price_minor']);$this->assertSame(150,$change['after']['sale_price_minor']);
 }
 public function test_audit_rolls_back_with_business_transaction():void {
  [$base,$tenant,$member]=$this->fixture();
  try{DB::transaction(function()use($tenant,$member){RecordAudit::record($tenant->id,'test.change','tenant',$tenant->id,$member->id);throw new \RuntimeException('rollback');});}catch(\RuntimeException $e){}
  $this->assertDatabaseCount('audit_logs',0);
 }
 public function test_unauthorized_tenant_action_is_audited_without_request_body():void {
  [$base,$tenant,$member]=$this->fixture();$member->update(['role'=>'cashier']);
  $this->postJson($base.'/products',['name'=>'Private payload','password'=>'do-not-record'])->assertForbidden();
  $this->assertDatabaseHas('audit_logs',['action'=>'access.denied','tenant_id'=>$tenant->id,'actor_id'=>$member->id]);$this->assertStringNotContainsString('do-not-record',DB::table('audit_logs')->value('details'));
 }
 public function test_audit_history_cannot_be_modified():void {
  [$base,$tenant,$member]=$this->fixture();DB::transaction(fn()=>RecordAudit::record($tenant->id,'test.change','tenant',$tenant->id,$member->id));
  $this->expectException(\Illuminate\Database\QueryException::class);DB::table('audit_logs')->update(['action'=>'rewritten']);
 }
 public function test_restricted_database_role_cannot_read_another_tenants_audit():void {
  [$base,$tenant,$member]=$this->fixture();$other=Tenant::create(['name'=>'Other','slug'=>'other']);
  DB::transaction(function()use($tenant,$member,$other){RecordAudit::record($tenant->id,'test.change','tenant',$tenant->id,$member->id);RecordAudit::record($other->id,'test.change','tenant',$other->id,null,[],'system');});
  DB::statement('CREATE ROLE audit_probe NOLOGIN NOSUPERUSER NOBYPASSRLS');DB::statement('GRANT USAGE ON SCHEMA public TO audit_probe');DB::statement('GRANT SELECT ON audit_logs TO audit_probe');DB::statement('SET LOCAL ROLE audit_probe');
  try{DB::select("select set_config('app.tenant_id','',true)");$this->assertSame(0,DB::table('audit_logs')->count());DB::select("select set_config('app.tenant_id',?,true)",[$tenant->id]);$this->assertSame([$tenant->id],DB::table('audit_logs')->pluck('tenant_id')->all());}finally{DB::statement('RESET ROLE');}
 }
}
