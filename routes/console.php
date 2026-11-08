<?php
use Illuminate\Support\Facades\Artisan;
use App\Models\User;
Artisan::command('pos:create-user {email} {name}', function () {
    $password = $this->secret('Password (at least 12 characters)');
    if (! is_string($password) || strlen($password) < 12) { $this->error('Password too short.'); return 1; }
    $email = strtolower(trim($this->argument('email')));
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) { $this->error('Invalid email.'); return 1; }
    User::create(['email' => $email, 'name' => $this->argument('name'), 'password' => $password]);
    $this->info('User created. Sign in to create a business.');
})->purpose('Provision an initial user without a public registration endpoint');

Artisan::command('pos:dispatch-outbox',function(){
    \App\Models\Tenant::query()->where('status','active')->select('id')->chunkById(100,function($tenants){
        foreach($tenants as $tenant) \Illuminate\Support\Facades\DB::transaction(function()use($tenant){
            \Illuminate\Support\Facades\DB::select("select set_config('app.tenant_id',?,true)",[$tenant->id]);
            if(\Illuminate\Support\Facades\DB::table('outbox_events')->where('tenant_id',$tenant->id)->whereNull('delivered_at')->where('available_at','<=',now())->exists())
                \App\Jobs\DeliverTenantOutbox::dispatch($tenant->id)->onConnection('database')->onQueue('realtime');
        });
    });
})->purpose('Queue delivery of committed tenant events');
\Illuminate\Support\Facades\Schedule::command('pos:dispatch-outbox')->everyTenSeconds()->withoutOverlapping();

Artisan::command('pos:dispatch-refunds',function(){
    \App\Models\Tenant::query()->select('id')->chunkById(100,function($tenants){
        foreach($tenants as $tenant) \Illuminate\Support\Facades\DB::transaction(function()use($tenant){
            \Illuminate\Support\Facades\DB::select("select set_config('app.tenant_id',?,true)",[$tenant->id]);
            $rows=\Illuminate\Support\Facades\DB::table('refund_allocations')->where('tenant_id',$tenant->id)->where('state','pending')->orderBy('created_at')->limit(100)->get();
            foreach($rows as $row) \App\Jobs\ProcessCardRefund::dispatch($tenant->id,$row->id)->onConnection('database')->onQueue('payments');
        });
    });
})->purpose('Recover pending card-refund work after request/worker failures');
\Illuminate\Support\Facades\Schedule::command('pos:dispatch-refunds')->everyTenSeconds()->withoutOverlapping();
Artisan::command('pos:reconcile-payments',function(){
    \App\Models\Tenant::query()->select('id')->chunkById(100,function($tenants){
        foreach($tenants as $tenant) \Illuminate\Support\Facades\DB::transaction(function()use($tenant){
            \Illuminate\Support\Facades\DB::select("select set_config('app.tenant_id',?,true)",[$tenant->id]);
            $rows=\Illuminate\Support\Facades\DB::table('payment_sessions')->where('tenant_id',$tenant->id)->where('state','reserved')->whereNotNull('provider_id')->orderBy('created_at')->limit(100)->get();
            foreach($rows as $row) \App\Jobs\ReconcileCardPayment::dispatch($tenant->id,$row->id)->onConnection('database')->onQueue('payments');
        });
    });
})->purpose('Recover paid/expired provider sessions when webhooks are delayed');
\Illuminate\Support\Facades\Schedule::command('pos:reconcile-payments')->everyMinute()->withoutOverlapping();

\Illuminate\Support\Facades\Schedule::call(function () {
    \Illuminate\Support\Facades\Cache::put('ops:heartbeat:scheduler', time(), 600);
    foreach (['payments','realtime'] as $queue) {
        \App\Jobs\OperationalHeartbeat::dispatch($queue)->onConnection('database')->onQueue($queue);
    }
})->name('operational-heartbeats')->everyMinute()->withoutOverlapping();

Artisan::command('pos:ops-check', function () {
    $healthy = true;
    foreach (['scheduler','payments','realtime'] as $name) {
        $last = \Illuminate\Support\Facades\Cache::get('ops:heartbeat:'.$name);
        $age = is_numeric($last) ? max(0,time()-(int)$last) : null;
        $ok = $age !== null && $age < 180;
        $healthy = $healthy && $ok;
        $this->line($name.': '.($ok ? 'ok' : 'stale').' age_seconds='.($age ?? 'missing'));
    }
    $db = \Illuminate\Support\Facades\DB::class;
    foreach (['payments','realtime'] as $queue) {
        $oldest = $db::table('jobs')->where('queue',$queue)->where('available_at','<=',time())->min('available_at');
        $age = $oldest === null ? 0 : max(0,time()-(int)$oldest);
        $this->line($queue.': due_age_seconds='.$age);
        $healthy = $healthy && $age < 180;
    }
    $failed = $db::table('failed_jobs')->count();
    $this->line('failed_jobs='.$failed);
    return $healthy && $failed === 0 ? 0 : 1;
})->purpose('Check scheduler, both queues, and failed jobs; nonzero means alert');

Artisan::command('pos:production-check', function () {
    $checks = [
        'production' => app()->environment('production'),
        'debug_disabled' => !config('app.debug'),
        'https' => str_starts_with(config('app.url'), 'https://'),
        'secure_sessions' => config('session.secure') && config('session.encrypt') && config('session.driver') === 'database',
        'shared_cache' => config('cache.default') === 'database',
        'durable_queue' => config('queue.default') === 'database',
        'verified_db_tls' => config('database.connections.pgsql.sslmode') === 'verify-full',
        'restricted_origins' => !in_array('*',config('reverb.apps.apps.0.allowed_origins',[]),true),
    ];
    $role = \Illuminate\Support\Facades\DB::selectOne('select rolsuper, rolbypassrls from pg_roles where rolname=current_user');
    $checks['restricted_db_role'] = !$role->rolsuper && !$role->rolbypassrls;
    $unsafe = \Illuminate\Support\Facades\DB::selectOne("select count(*) as n from pg_class c join pg_namespace n on n.oid=c.relnamespace where n.nspname='public' and c.relkind='r' and exists (select 1 from pg_attribute a where a.attrelid=c.oid and a.attname='tenant_id' and not a.attisdropped) and (not c.relrowsecurity or not c.relforcerowsecurity)");
    $checks['forced_tenant_rls'] = (int)$unsafe->n === 0;
    $owner = \Illuminate\Support\Facades\DB::selectOne("select count(*) as n from pg_tables where schemaname='public' and tableowner=current_user");
    $checks['runtime_owns_no_tables'] = (int)$owner->n === 0;
    $schema = \Illuminate\Support\Facades\DB::selectOne("select has_schema_privilege(current_user,'public','CREATE') as allowed");
    $checks['runtime_cannot_create_tables'] = !$schema->allowed;
    $audit = \Illuminate\Support\Facades\DB::selectOne("select count(*) as n from pg_trigger where tgrelid='public.audit_logs'::regclass and tgname='immutable_audit' and tgenabled='O'");
    $checks['audit_append_only_trigger'] = (int)$audit->n === 1;
    $tls = \Illuminate\Support\Facades\DB::selectOne('select ssl from pg_stat_ssl where pid=pg_backend_pid()');
    $checks['db_tls_active'] = (bool)($tls->ssl ?? false);
    foreach ($checks as $name=>$ok) $this->line($name.': '.($ok?'ok':'FAIL'));
    return in_array(false,$checks,true) ? 1 : 0;
})->purpose('Fail deployment when production security requirements are unmet');
