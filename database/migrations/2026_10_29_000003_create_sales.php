<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
 public function up(): void {
  Schema::create('orders',function(Blueprint $t){
   $t->uuid('id')->primary();$t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
   $t->uuid('client_order_id');$t->string('payload_hash',64);$t->string('receipt_number');
   $t->uuid('warehouse_id');$t->uuid('cashier_membership_id');$t->string('business_name');$t->char('currency',3);
   $t->string('status')->default('completed');$t->bigInteger('total_minor');$t->bigInteger('cash_tendered_minor');$t->bigInteger('change_minor');$t->timestampTz('completed_at');
   $t->unique(['tenant_id','id']);$t->unique(['tenant_id','client_order_id']);$t->unique(['tenant_id','receipt_number']);$t->index(['tenant_id','completed_at']);
   foreach(['warehouse_id'=>'warehouses','cashier_membership_id'=>'tenant_memberships'] as $column=>$table)$t->foreign(['tenant_id',$column])->references(['tenant_id','id'])->on($table)->restrictOnDelete();
  });
  Schema::create('order_items',function(Blueprint $t){
   $t->uuid('id')->primary();$t->uuid('tenant_id');$t->uuid('order_id');$t->uuid('product_id');$t->string('name');$t->string('sku');$t->string('unit');
   $t->decimal('quantity',18,4);$t->bigInteger('unit_price_minor');$t->bigInteger('line_total_minor');
   foreach(['order_id'=>'orders','product_id'=>'products'] as $column=>$table)$t->foreign(['tenant_id',$column])->references(['tenant_id','id'])->on($table)->restrictOnDelete();
   $t->unique(['tenant_id','order_id','product_id']);$t->index(['tenant_id','product_id']);
  });
  Schema::create('transactions',function(Blueprint $t){
   $t->uuid('id')->primary();$t->uuid('tenant_id');$t->uuid('order_id');$t->string('method')->default('cash');$t->string('status')->default('completed');$t->bigInteger('amount_minor');$t->char('currency',3);$t->timestampTz('processed_at');
   $t->foreign(['tenant_id','order_id'])->references(['tenant_id','id'])->on('orders')->restrictOnDelete();$t->unique(['tenant_id','order_id']);
  });
  DB::statement('ALTER TABLE orders ADD CONSTRAINT valid_cash_sale CHECK (total_minor >= 0 AND cash_tendered_minor >= total_minor AND change_minor = cash_tendered_minor-total_minor)');
  DB::statement('ALTER TABLE order_items ADD CONSTRAINT valid_sale_line CHECK (quantity > 0 AND unit_price_minor >= 0 AND line_total_minor >= 0)');
  DB::statement('ALTER TABLE transactions ADD CONSTRAINT valid_payment CHECK (amount_minor >= 0)');
  foreach(['orders','order_items','transactions'] as $table){
   DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
   DB::statement("CREATE POLICY tenant_isolation ON $table USING (tenant_id = nullif(current_setting('app.tenant_id',true),'')::uuid) WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id',true),'')::uuid)");
   DB::statement("CREATE TRIGGER immutable_sale BEFORE UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION reject_inventory_mutation()");
  }
  foreach(['admin','cashier'] as $role){$p=json_decode(DB::table('roles')->where('code',$role)->value('permissions'),true);DB::table('roles')->where('code',$role)->update(['permissions'=>json_encode([...$p,'sales.create','sales.read'])]);}
 }
 public function down(): void {
  Schema::dropIfExists('transactions');Schema::dropIfExists('order_items');Schema::dropIfExists('orders');
  foreach(['admin','cashier'] as $role){$p=json_decode(DB::table('roles')->where('code',$role)->value('permissions'),true);DB::table('roles')->where('code',$role)->update(['permissions'=>json_encode(array_values(array_diff($p,['sales.create','sales.read'])))]);}
 }
};
