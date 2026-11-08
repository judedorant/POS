<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(\Throwable $e): void { fwrite(STDERR,get_class($e).": ".$e->getMessage().PHP_EOL); exit(1); });
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use App\Actions\{MoveInventory,CompleteCashSale,CreateRefund};
if(!str_ends_with((string)config('database.connections.pgsql.database'),'_test'))throw new RuntimeException('Disposable *_test database required.');
if(($argv[1]??'')==='worker'){
 [$tenant,$actor,$order,$line,$request]=array_slice($argv,2);
 try{DB::transaction(function()use($tenant,$actor,$order,$line,$request){DB::select("select set_config('app.tenant_id',?,true)",[$tenant]);(new CreateRefund)->execute($tenant,$order,$actor,['request_id'=>$request,'reason'=>'Concurrent return','items'=>[['order_item_id'=>$line,'quantity'=>'4','restock'=>true]]]);});echo 'accepted';}
 catch(Illuminate\Validation\ValidationException $e){echo 'excess_quantity';}exit;
}
$suffix=(string)Str::uuid();$user=App\Models\User::create(['name'=>'Refund test','email'=>$suffix.'@example.com','password'=>'test-password']);$tenant=App\Models\Tenant::create(['name'=>'Refund test','slug'=>$suffix,'default_currency'=>'USD']);$actor=App\Models\TenantMembership::create(['tenant_id'=>$tenant->id,'user_id'=>$user->id,'role'=>'admin']);
$product=(string)Str::uuid();$warehouse=(string)Str::uuid();DB::table('products')->insert(['id'=>$product,'tenant_id'=>$tenant->id,'name'=>'Item','sku'=>'ITEM','sale_price_minor'=>1]);DB::table('warehouses')->insert(['id'=>$warehouse,'tenant_id'=>$tenant->id,'name'=>'Main','code'=>'MAIN']);
(new MoveInventory)->execute($tenant->id,$actor->id,['operation_id'=>(string)Str::uuid(),'kind'=>'adjustment','product_id'=>$product,'warehouse_id'=>$warehouse,'quantity'=>'10','reason'=>'Opening']);
$order=(new CompleteCashSale)->execute($tenant,$actor->id,['client_order_id'=>(string)Str::uuid(),'warehouse_id'=>$warehouse,'cash_tendered_minor'=>10,'items'=>[['product_id'=>$product,'quantity'=>'6','expected_price_minor'=>1]]])['order_id'];$line=DB::table('order_items')->where('order_id',$order)->value('id');
DB::beginTransaction();DB::table('orders')->where('id',$order)->lockForUpdate()->first();$workers=[];
foreach([1,2] as $i){$worker=new Process([PHP_BINARY,__FILE__,'worker',$tenant->id,$actor->id,$order,$line,(string)Str::uuid()]);$worker->start();$workers[]=$worker;}
usleep(500000);DB::commit();$results=[];foreach($workers as $worker){$worker->wait();if(!$worker->isSuccessful())throw new RuntimeException($worker->getErrorOutput());$results[]=trim($worker->getOutput());}sort($results);
if($results!==['accepted','excess_quantity'])throw new RuntimeException('Expected one refund and one rejection: '.json_encode($results));
if(DB::table('refunds')->where('tenant_id',$tenant->id)->count()!==1||(int)DB::table('transactions')->where('tenant_id',$tenant->id)->where('kind','refund')->sum('amount_minor')!==4||DB::table('inventory_stocks')->where('tenant_id',$tenant->id)->value('on_hand')!=='8.0000')throw new RuntimeException('Refund balance mismatch.');
echo "Concurrent refunds passed: one refund, one rejection; four units refunded and restocked exactly once.\n";
