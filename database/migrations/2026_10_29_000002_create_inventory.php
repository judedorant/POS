<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
 public function up(): void {
  Schema::create('inventory_operations',function(Blueprint $t) {
   $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
   $t->uuid('operation_id'); $t->string('payload_hash',64); $t->string('kind'); $t->text('reason');
   $t->uuid('actor_membership_id'); $t->timestampTz('created_at');
   $t->unique(['tenant_id','id']); $t->unique(['tenant_id','operation_id']);
   $t->foreign(['tenant_id','actor_membership_id'])->references(['tenant_id','id'])->on('tenant_memberships')->restrictOnDelete();
  });
  Schema::create('inventory_stocks',function(Blueprint $t) {
   $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
   $t->uuid('product_id'); $t->uuid('warehouse_id'); $t->decimal('on_hand',18,4)->default(0); $t->decimal('reserved',18,4)->default(0); $t->bigInteger('version')->default(0); $t->timestampsTz();
   $t->unique(['tenant_id','warehouse_id','product_id']); $t->index(['tenant_id','product_id']);
   foreach(['product_id'=>'products','warehouse_id'=>'warehouses'] as $column=>$table) $t->foreign(['tenant_id',$column])->references(['tenant_id','id'])->on($table)->restrictOnDelete();
  });
  Schema::create('stock_movements',function(Blueprint $t) {
   $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
   $t->uuid('operation_id'); $t->uuid('product_id'); $t->uuid('warehouse_id'); $t->decimal('quantity_delta',18,4); $t->timestampTz('created_at');
   foreach(['operation_id'=>'inventory_operations','product_id'=>'products','warehouse_id'=>'warehouses'] as $column=>$table) $t->foreign(['tenant_id',$column])->references(['tenant_id','id'])->on($table)->restrictOnDelete();
   $t->unique(['tenant_id','operation_id','warehouse_id','product_id']);
   $t->index(['tenant_id','warehouse_id','product_id','created_at']);
  });
  DB::statement('ALTER TABLE inventory_stocks ADD CONSTRAINT valid_reservation CHECK (reserved >= 0)');
  DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT nonzero_movement CHECK (quantity_delta <> 0)');
  foreach(['inventory_stocks','stock_movements','inventory_operations'] as $table) {
   DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY"); DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
   DB::statement("CREATE POLICY tenant_isolation ON $table USING (tenant_id = nullif(current_setting('app.tenant_id',true),'')::uuid) WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id',true),'')::uuid)");
  }
  DB::unprepared("CREATE OR REPLACE FUNCTION reject_inventory_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Inventory history is immutable'; END; $$");
  foreach(['stock_movements','inventory_operations'] as $table) DB::statement("CREATE TRIGGER immutable_history BEFORE UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION reject_inventory_mutation()");
  foreach(['admin','inventory_manager'] as $role) {
   $p=json_decode(DB::table('roles')->where('code',$role)->value('permissions'),true);
   DB::table('roles')->where('code',$role)->update(['permissions'=>json_encode([...$p,'inventory.manage'])]);
  }
 }
 public function down(): void {
  Schema::dropIfExists('stock_movements'); Schema::dropIfExists('inventory_stocks'); Schema::dropIfExists('inventory_operations'); DB::statement('DROP FUNCTION reject_inventory_mutation()');
  foreach(['admin','inventory_manager'] as $role) {
   $p=json_decode(DB::table('roles')->where('code',$role)->value('permissions'),true);
   DB::table('roles')->where('code',$role)->update(['permissions'=>json_encode(array_values(array_diff($p,['inventory.manage'])))]);
  }
 }
};
