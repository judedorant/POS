<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!str_ends_with((string)config('database.connections.pgsql.database'),'_test'))throw new RuntimeException('Disposable test database required.');
App\Models\User::firstOrCreate(['email'=>'browser@example.com'],['name'=>'Browser Cashier','password'=>'browser-test-password']);
