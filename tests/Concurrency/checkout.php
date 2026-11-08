<?php
// Runs only against a disposable test database; uses independent PHP processes/connections.
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(\Throwable $e): void { fwrite(STDERR,get_class($e).": ".$e->getMessage().PHP_EOL); exit(1); });
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use App\Actions\MoveInventory;
if (!str_ends_with((string)config('database.connections.pgsql.database'),'_test')) throw new RuntimeException('Requires a disposable *_test database.');
if (($argv[1]??'')==='worker') {
 [$tenant,$actor,$product,$warehouse,$operation]=array_slice($argv,2);
 try {
  DB::transaction(function() use($tenant,$actor,$product,$warehouse,$operation) {
   DB::select("select set_config('app.tenant_id',?,true)",[$tenant]);
   (new \App\Actions\CompleteCashSale)->execute(\App\Models\Tenant::findOrFail($tenant),$actor,['client_order_id'=>$operation,'warehouse_id'=>$warehouse,'cash_tendered_minor'=>100,'items'=>[['product_id'=>$product,'quantity'=>'6','expected_price_minor'=>1]]]);
  }); echo 'accepted';
 } catch (Illuminate\Validation\ValidationException $e) { echo 'insufficient'; }
 exit;
}
$suffix=(string)Str::uuid();
$user=App\Models\User::create(['name'=>'Concurrency','email'=>$suffix.'@example.com','password'=>'test-password']);
$tenant=App\Models\Tenant::create(['name'=>'Concurrency','slug'=>$suffix]);
$actor=App\Models\TenantMembership::create(['tenant_id'=>$tenant->id,'user_id'=>$user->id,'role'=>'admin']);
$product=(string)Str::uuid();$warehouse=(string)Str::uuid();
DB::table('products')->insert(['id'=>$product,'tenant_id'=>$tenant->id,'name'=>'Test','sku'=>'TEST','sale_price_minor'=>1]);
DB::table('warehouses')->insert(['id'=>$warehouse,'tenant_id'=>$tenant->id,'name'=>'Test','code'=>'TEST']);
(new MoveInventory)->execute($tenant->id,$actor->id,['operation_id'=>(string)Str::uuid(),'kind'=>'adjustment','product_id'=>$product,'warehouse_id'=>$warehouse,'quantity'=>'10','reason'=>'Opening']);
// Hold the balance lock until both workers have started and must contend for it.
DB::beginTransaction();
DB::table('inventory_stocks')->where('tenant_id',$tenant->id)->lockForUpdate()->first();
$workers=[];
foreach([1,2] as $i) {
 $worker=new Process([PHP_BINARY,__FILE__,'worker',$tenant->id,$actor->id,$product,$warehouse,(string)Str::uuid()]);
 $worker->start();$workers[]=$worker;
}
usleep(500000);DB::commit();
$output=[];foreach($workers as $worker){$worker->wait();if(!$worker->isSuccessful())throw new RuntimeException($worker->getErrorOutput());$output[]=trim($worker->getOutput());}
sort($output);
if($output!==['accepted','insufficient'])throw new RuntimeException('Expected one accepted debit: '.json_encode($output));
$balance=DB::table('inventory_stocks')->where('tenant_id',$tenant->id)->value('on_hand');
$ledger=DB::table('stock_movements')->where('tenant_id',$tenant->id)->sum('quantity_delta');
if($balance!=='4.0000'||$ledger!==$balance)throw new RuntimeException('Balance/ledger mismatch.');
if(DB::table('orders')->where('tenant_id',$tenant->id)->count()!==1 || DB::table('transactions')->where('tenant_id',$tenant->id)->count()!==1)throw new RuntimeException('Expected one sale and payment.');
echo "Concurrent checkout test passed: one sale accepted, one rejected; balance and ledger = 4.0000\n";
