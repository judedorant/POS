<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
 public function up(): void {
  Schema::table('orders',function(Blueprint $t){$t->bigInteger('cash_applied_minor')->default(0);});
  DB::statement('ALTER TABLE orders DISABLE TRIGGER immutable_sale');
  DB::statement('UPDATE orders SET cash_applied_minor=total_minor');
  DB::statement('ALTER TABLE orders ENABLE TRIGGER immutable_sale');
  DB::statement('ALTER TABLE orders DROP CONSTRAINT valid_cash_sale');
  DB::statement('ALTER TABLE orders ADD CONSTRAINT valid_cash_sale CHECK (total_minor>=0 AND cash_applied_minor>=0 AND cash_applied_minor<=total_minor AND cash_tendered_minor>=cash_applied_minor AND change_minor=cash_tendered_minor-cash_applied_minor)');
  Schema::table('transactions',function(Blueprint $t){
   $t->dropUnique(['tenant_id','order_id']);$t->unique(['tenant_id','id']);$t->string('kind')->default('payment');$t->string('provider_reference')->nullable();$t->unique(['tenant_id','provider_reference']);$t->uuid('refund_id')->nullable();$t->uuid('original_transaction_id')->nullable();
   $t->foreign(['tenant_id','original_transaction_id'])->references(['tenant_id','id'])->on('transactions')->restrictOnDelete();
  });
  Schema::table('order_items',function(Blueprint $t){$t->unique(['tenant_id','id']);$t->boolean('inventory_deducted')->default(false);});
  DB::statement('ALTER TABLE order_items DISABLE TRIGGER immutable_sale');
  DB::statement("UPDATE order_items SET inventory_deducted=true WHERE EXISTS (SELECT 1 FROM stock_movements m JOIN inventory_operations o ON m.operation_id=o.id AND m.tenant_id=o.tenant_id WHERE o.operation_id=order_items.order_id AND m.product_id=order_items.product_id AND m.tenant_id=order_items.tenant_id)");
  DB::statement('ALTER TABLE order_items ENABLE TRIGGER immutable_sale');
  Schema::create('payment_sessions',function(Blueprint $t){
   $t->uuid('id')->primary();$t->uuid('tenant_id');$t->uuid('actor_id');$t->uuid('warehouse_id');$t->uuid('client_order_id');$t->string('payload_hash',64);$t->jsonb('command');$t->jsonb('catalog');$t->bigInteger('total_minor');$t->bigInteger('card_minor');$t->char('currency',3);$t->string('state')->default('reserved');$t->string('provider_id')->nullable()->unique();$t->text('checkout_url')->nullable();$t->uuid('order_id')->nullable();$t->timestampTz('expires_at');$t->timestampsTz();
   $t->unique(['tenant_id','id']);$t->unique(['tenant_id','client_order_id']);$t->index(['tenant_id','state','expires_at']);
   foreach(['actor_id'=>'tenant_memberships','warehouse_id'=>'warehouses','order_id'=>'orders'] as $c=>$table)$t->foreign(['tenant_id',$c])->references(['tenant_id','id'])->on($table)->restrictOnDelete();
  });
  Schema::create('refunds',function(Blueprint $t){
   $t->uuid('id')->primary();$t->uuid('tenant_id');$t->uuid('order_id');$t->uuid('request_id');$t->uuid('actor_id');$t->string('payload_hash',64);$t->string('state')->default('pending');$t->bigInteger('amount_minor');$t->text('reason');$t->timestampsTz();
   $t->unique(['tenant_id','id']);$t->unique(['tenant_id','request_id']);$t->index(['tenant_id','order_id']);
   foreach(['order_id'=>'orders','actor_id'=>'tenant_memberships'] as $c=>$table)$t->foreign(['tenant_id',$c])->references(['tenant_id','id'])->on($table)->restrictOnDelete();
  });
  Schema::create('refund_items',function(Blueprint $t){
   $t->uuid('id')->primary();$t->uuid('tenant_id');$t->uuid('refund_id');$t->uuid('order_item_id');$t->decimal('quantity',18,4);$t->bigInteger('amount_minor');$t->boolean('restock');$t->unique(['tenant_id','refund_id','order_item_id']);$t->index(['tenant_id','order_item_id']);
   foreach(['refund_id'=>'refunds','order_item_id'=>'order_items'] as $c=>$table)$t->foreign(['tenant_id',$c])->references(['tenant_id','id'])->on($table)->restrictOnDelete();
  });
  Schema::create('refund_allocations',function(Blueprint $t){
   $t->uuid('id')->primary();$t->uuid('tenant_id');$t->uuid('refund_id');$t->uuid('transaction_id');$t->bigInteger('amount_minor');$t->string('state');$t->string('provider_id')->nullable()->unique();$t->integer('attempt')->default(0);$t->jsonb('provider_history')->default('[]');$t->timestampsTz();$t->index(['tenant_id','transaction_id']);
   foreach(['refund_id'=>'refunds','transaction_id'=>'transactions'] as $c=>$table)$t->foreign(['tenant_id',$c])->references(['tenant_id','id'])->on($table)->restrictOnDelete();
  });
  Schema::table('transactions',function(Blueprint $t){$t->foreign(['tenant_id','refund_id'])->references(['tenant_id','id'])->on('refunds')->restrictOnDelete();});
  Schema::create('provider_events',function(Blueprint $t){$t->string('id')->primary();$t->uuid('tenant_id');$t->string('type');$t->timestampTz('created_at');});
  foreach(['payment_sessions','refunds','refund_items','refund_allocations','provider_events'] as $table){
   DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
   DB::statement("CREATE POLICY tenant_isolation ON $table USING (tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid) WITH CHECK (tenant_id=nullif(current_setting('app.tenant_id',true),'')::uuid)");
  }
  DB::statement('ALTER TABLE refund_items ADD CONSTRAINT valid_refund_item CHECK (quantity>0 AND amount_minor>=0)');
  DB::statement('ALTER TABLE refund_allocations ADD CONSTRAINT valid_refund_allocation CHECK (amount_minor>0)');
  DB::statement('CREATE TRIGGER immutable_return BEFORE UPDATE OR DELETE ON refund_items FOR EACH ROW EXECUTE FUNCTION reject_inventory_mutation()');
  $p=json_decode(DB::table('roles')->where('code','admin')->value('permissions'),true);DB::table('roles')->where('code','admin')->update(['permissions'=>json_encode([...$p,'sales.refund'])]);
 }
 public function down(): void {
  if(DB::table('refunds')->exists()||DB::table('payment_sessions')->exists())throw new \RuntimeException('Cannot roll back payment history; restore from a verified backup instead.');
  Schema::table('transactions',function(Blueprint $t){$t->dropForeign(['tenant_id','refund_id']);});
  foreach(['provider_events','refund_allocations','refund_items','refunds','payment_sessions'] as $table)Schema::dropIfExists($table);
  Schema::table('order_items',function(Blueprint $t){$t->dropColumn('inventory_deducted');$t->dropUnique(['tenant_id','id']);});
  Schema::table('transactions',function(Blueprint $t){$t->dropForeign(['tenant_id','original_transaction_id']);$t->dropUnique(['tenant_id','provider_reference']);$t->dropUnique(['tenant_id','id']);$t->dropColumn(['kind','provider_reference','refund_id','original_transaction_id']);});
  Schema::table('transactions',function(Blueprint $t){$t->unique(['tenant_id','order_id']);});
  DB::statement('ALTER TABLE orders DROP CONSTRAINT valid_cash_sale');
  Schema::table('orders',function(Blueprint $t){$t->dropColumn('cash_applied_minor');});
  DB::statement('ALTER TABLE orders ADD CONSTRAINT valid_cash_sale CHECK (total_minor>=0 AND cash_tendered_minor>=total_minor AND change_minor=cash_tendered_minor-total_minor)');
  $p=json_decode(DB::table('roles')->where('code','admin')->value('permissions'),true);DB::table('roles')->where('code','admin')->update(['permissions'=>json_encode(array_values(array_diff($p,['sales.refund'])))]);
 }
};
