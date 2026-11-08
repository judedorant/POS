<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
 public function up(): void {
  Schema::create('offline_leases',function(Blueprint $t){
   $t->uuid('id')->primary();$t->uuid('tenant_id');$t->uuid('membership_id');$t->uuid('device_id');$t->uuid('warehouse_id');
   $t->jsonb('catalog');$t->timestampTz('issued_at');$t->timestampTz('expires_at');$t->timestampTz('revoked_at')->nullable();
   $t->unique(['tenant_id','id']);$t->index(['tenant_id','device_id']);
   foreach(['tenant_id'=>'tenants'] as $c=>$table)$t->foreign($c)->references('id')->on($table)->restrictOnDelete();
   foreach(['membership_id'=>'tenant_memberships','warehouse_id'=>'warehouses'] as $c=>$table)$t->foreign(['tenant_id',$c])->references(['tenant_id','id'])->on($table)->restrictOnDelete();
  });
  Schema::create('sync_operations',function(Blueprint $t){
   $t->uuid('id')->primary();$t->uuid('tenant_id');$t->uuid('lease_id');$t->uuid('operation_id');$t->string('payload_hash',64);$t->jsonb('payload');$t->string('status');$t->string('code');$t->uuid('order_id')->nullable();$t->uuid('reviewer_id')->nullable();$t->text('review_note')->nullable();$t->timestampsTz();
   $t->unique(['tenant_id','operation_id']);$t->index(['tenant_id','status','created_at']);
   $t->foreign(['tenant_id','lease_id'])->references(['tenant_id','id'])->on('offline_leases')->restrictOnDelete();
   $t->foreign(['tenant_id','order_id'])->references(['tenant_id','id'])->on('orders')->restrictOnDelete();
   $t->foreign(['tenant_id','reviewer_id'])->references(['tenant_id','id'])->on('tenant_memberships')->restrictOnDelete();
  });
  foreach(['offline_leases','sync_operations'] as $table){
   DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
   DB::statement("CREATE POLICY tenant_isolation ON $table USING (tenant_id = nullif(current_setting('app.tenant_id',true),'')::uuid) WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id',true),'')::uuid)");
  }
 }
 public function down(): void {Schema::dropIfExists('sync_operations');Schema::dropIfExists('offline_leases');}
};
