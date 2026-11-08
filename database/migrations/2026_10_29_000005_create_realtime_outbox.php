<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
 public function up(): void {
  Schema::create('tenant_streams',function(Blueprint $t){
   $t->foreignUuid('tenant_id')->primary()->constrained()->restrictOnDelete();$t->uuid('epoch');$t->bigInteger('sequence')->default(0);
  });
  Schema::create('outbox_events',function(Blueprint $t){
   $t->uuid('id')->primary();$t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();$t->bigInteger('sequence');$t->jsonb('topics');$t->timestampTz('created_at');
   $t->timestampTz('available_at');$t->timestampTz('delivered_at')->nullable();$t->integer('attempts')->default(0);$t->string('last_error')->nullable();
   $t->unique(['tenant_id','sequence']);$t->index(['tenant_id','delivered_at','available_at']);
  });
  foreach(['tenant_streams','outbox_events'] as $table){
   DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
   DB::statement("CREATE POLICY tenant_isolation ON $table USING (tenant_id = nullif(current_setting('app.tenant_id',true),'')::uuid) WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id',true),'')::uuid)");
  }
  Schema::create('jobs',function(Blueprint $t){$t->bigIncrements('id');$t->string('queue')->index();$t->longText('payload');$t->unsignedTinyInteger('attempts');$t->unsignedInteger('reserved_at')->nullable();$t->unsignedInteger('available_at');$t->unsignedInteger('created_at');});
  Schema::create('failed_jobs',function(Blueprint $t){$t->id();$t->string('uuid')->unique();$t->text('connection');$t->text('queue');$t->longText('payload');$t->longText('exception');$t->timestamp('failed_at')->useCurrent();});
 }
 public function down(): void {Schema::dropIfExists('failed_jobs');Schema::dropIfExists('jobs');Schema::dropIfExists('outbox_events');Schema::dropIfExists('tenant_streams');}
};
