<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary(); $t->uuid('user_id')->nullable()->index();
            $t->string('ip_address',45)->nullable(); $t->text('user_agent')->nullable();
            $t->longText('payload'); $t->integer('last_activity')->index();
        });
        Schema::create('cache', function (Blueprint $t) { $t->string('key')->primary(); $t->mediumText('value'); $t->integer('expiration')->index(); });
        Schema::create('cache_locks', function (Blueprint $t) { $t->string('key')->primary(); $t->string('owner'); $t->integer('expiration')->index(); });
    }
    public function down(): void { Schema::dropIfExists('cache_locks'); Schema::dropIfExists('cache'); Schema::dropIfExists('sessions'); }
};
