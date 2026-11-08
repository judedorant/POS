<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
 public function up():void {
  Schema::table('products',function(Blueprint $t){$t->decimal('reorder_point',18,4)->default(0);});
  DB::statement('ALTER TABLE products ADD CONSTRAINT valid_reorder_point CHECK (reorder_point>=0)');
  Schema::create('audit_logs',function(Blueprint $t){
   $t->uuid('id')->primary();$t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();$t->uuid('actor_id')->nullable();$t->string('actor_type',16);$t->string('action',80);$t->string('entity_type',40);$t->uuid('entity_id');$t->uuid('request_id')->nullable();$t->jsonb('details');$t->timestampTz('created_at');
   $t->foreign(['tenant_id','actor_id'])->references(['tenant_id','id'])->on('tenant_memberships')->restrictOnDelete();
   $t->index(['tenant_id','created_at','id']);$t->index(['tenant_id','action','created_at']);$t->index(['tenant_id','entity_type','entity_id']);$t->index(['tenant_id','request_id']);
  });
  DB::statement('ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY');DB::statement('ALTER TABLE audit_logs FORCE ROW LEVEL SECURITY');
  DB::statement("CREATE POLICY tenant_isolation ON audit_logs USING (tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid) WITH CHECK (tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid)");
  DB::unprepared("CREATE OR REPLACE FUNCTION reject_audit_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Audit history is append-only'; END; $$");
  DB::statement('CREATE TRIGGER immutable_audit BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation()');
  foreach(['admin'=>['reports.sales','reports.reconciliation','reports.inventory','audit.read'],'inventory_manager'=>['reports.inventory']] as $role=>$add){$p=json_decode(DB::table('roles')->where('code',$role)->value('permissions'),true);DB::table('roles')->where('code',$role)->update(['permissions'=>json_encode([...$p,...$add])]);}
  Schema::table('transactions',fn(Blueprint $t)=>$t->index(['tenant_id','processed_at','kind']));
  Schema::table('refunds',fn(Blueprint $t)=>$t->index(['tenant_id','created_at','state']));
 }
 public function down():void {
  if(DB::table('audit_logs')->exists())throw new \RuntimeException('Audit history must be retained; archive and restore from a verified backup instead.');
  Schema::dropIfExists('audit_logs');DB::statement('DROP FUNCTION reject_audit_mutation()');
  DB::statement('ALTER TABLE products DROP CONSTRAINT valid_reorder_point');Schema::table('products',fn(Blueprint $t)=>$t->dropColumn('reorder_point'));
  Schema::table('transactions',fn(Blueprint $t)=>$t->dropIndex(['tenant_id','processed_at','kind']));Schema::table('refunds',fn(Blueprint $t)=>$t->dropIndex(['tenant_id','created_at','state']));
  foreach(['admin','inventory_manager'] as $role){$p=json_decode(DB::table('roles')->where('code',$role)->value('permissions'),true);DB::table('roles')->where('code',$role)->update(['permissions'=>json_encode(array_values(array_filter($p,fn($v)=>!str_starts_with($v,'reports.')&&$v!=='audit.read')))]);}
 }
};
