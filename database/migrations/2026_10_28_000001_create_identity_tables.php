<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name'); $t->string('email')->unique();
            $t->string('password'); $t->timestampTz('disabled_at')->nullable(); $t->rememberToken(); $t->timestampsTz();
        });
        Schema::create('tenants', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name'); $t->string('slug')->unique();
            $t->string('status')->default('active')->index(); $t->char('default_currency', 3)->default('USD');
            $t->string('timezone')->default('UTC'); $t->timestampsTz();
        });
        Schema::create('roles', function (Blueprint $t) { $t->string('code')->primary(); $t->json('permissions'); });
        foreach (['admin' => ['tenant.read', 'tenant.manage'], 'cashier' => ['tenant.read'], 'inventory_manager' => ['tenant.read']] as $role => $permissions) {\Illuminate\Support\Facades\DB::table('roles')->insert(['code' => $role, 'permissions' => json_encode($permissions)]); }
        Schema::create('tenant_memberships', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $t->string('role'); $t->foreign('role')->references('code')->on('roles'); $t->index('role');
            $t->enum('status', ['active', 'suspended'])->default('active'); $t->timestampsTz();
            $t->unique(['tenant_id', 'user_id']); $t->unique(['tenant_id', 'id']);
            $t->index('user_id'); $t->index(['tenant_id', 'status']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('tenant_memberships'); Schema::dropIfExists('roles'); Schema::dropIfExists('tenants'); Schema::dropIfExists('users');
    }
};
