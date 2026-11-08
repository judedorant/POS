<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        DB::statement('ALTER TABLE tenant_memberships ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE tenant_memberships FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY own_memberships ON tenant_memberships USING (user_id = nullif(current_setting('app.user_id', true), '')::uuid) WITH CHECK (user_id = nullif(current_setting('app.user_id', true), '')::uuid)");
    }
    public function down(): void {
        DB::statement('DROP POLICY own_memberships ON tenant_memberships');
        DB::statement('ALTER TABLE tenant_memberships DISABLE ROW LEVEL SECURITY');
    }
};
