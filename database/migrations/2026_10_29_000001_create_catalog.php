<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema, DB};
return new class extends Migration {
 public function up(): void {
  Schema::create('categories', function(Blueprint $t) {
   $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
   $t->string('name',120); $t->string('slug',80); $t->boolean('is_active')->default(true); $t->timestampsTz();
   $t->unique(['tenant_id','id']); $t->unique(['tenant_id','slug']);
  });
  Schema::create('warehouses', function(Blueprint $t) {
   $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
   $t->string('name',120); $t->string('code',80); $t->text('address')->nullable(); $t->boolean('is_active')->default(true); $t->timestampsTz();
   $t->unique(['tenant_id','id']); $t->unique(['tenant_id','code']);
  });
  Schema::create('products', function(Blueprint $t) {
   $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete(); $t->uuid('category_id')->nullable();
   $t->string('name',160); $t->string('sku',80); $t->string('barcode',80)->nullable();
   $t->string('unit',16)->default('each'); $t->bigInteger('sale_price_minor');
   $t->boolean('track_inventory')->default(true); $t->boolean('is_active')->default(true); $t->timestampsTz();
   $t->unique(['tenant_id','id']); $t->unique(['tenant_id','sku']); $t->unique(['tenant_id','barcode']);
   $t->foreign(['tenant_id','category_id'])->references(['tenant_id','id'])->on('categories')->restrictOnDelete();
   $t->index(['tenant_id','category_id','is_active']);
  });
  DB::statement('ALTER TABLE products ADD CONSTRAINT price_nonnegative CHECK (sale_price_minor >= 0)');
  foreach (['categories','products','warehouses'] as $table) {
   DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
   DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
   DB::statement("CREATE POLICY tenant_isolation ON $table USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)");
  }
  foreach (['admin','inventory_manager'] as $role) {
   $permissions = json_decode(DB::table('roles')->where('code',$role)->value('permissions'),true);
   DB::table('roles')->where('code',$role)->update(['permissions'=>json_encode([...$permissions,'catalog.manage'])]);
  }
 }
 public function down(): void {
  Schema::dropIfExists('products'); Schema::dropIfExists('warehouses'); Schema::dropIfExists('categories');
  foreach (['admin','inventory_manager'] as $role) {
   $permissions = json_decode(DB::table('roles')->where('code',$role)->value('permissions'),true);
   DB::table('roles')->where('code',$role)->update(['permissions'=>json_encode(array_values(array_diff($permissions,['catalog.manage'])))]);
  }
 }
};
