<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SessionController;
use Illuminate\Http\Request;
Route::prefix('v1')->group(function () {
    Route::post('webhooks/stripe', \App\Http\Controllers\StripeWebhookController::class);
    Route::prefix('tenants/{tenant}/payments')->whereUuid('tenant')->middleware(['auth:sanctum',\App\Http\Middleware\SyncTenantContext::class])->group(function () {
        Route::post('', [\App\Http\Controllers\PaymentController::class,'store']);
        Route::post('refund-allocations/{allocation}/retry', [\App\Http\Controllers\RefundController::class,'retry'])->whereUuid('allocation');
        Route::post('{session}/{action}', [\App\Http\Controllers\PaymentController::class,'reconcile'])->whereUuid('session')->where('action','reconcile|cancel');
    });
    Route::post('login', [SessionController::class, 'store'])->middleware('throttle:5,1');
    Route::post('tenants/{tenant}/sync/orders', [\App\Http\Controllers\OfflineController::class,'sync'])->middleware(['auth:sanctum',\App\Http\Middleware\SyncTenantContext::class,'throttle:60,1']);
    Route::middleware(['auth:sanctum', \App\Http\Middleware\IdentityContext::class])->group(function () {
        Route::prefix('tenants/{tenant}/{resource}')->where(['resource'=>'categories|products|warehouses','tenant'=>'[0-9a-fA-F-]{36}'])->middleware('tenant')->group(function () {
            Route::get('', [\App\Http\Controllers\CatalogController::class,'index']);
            Route::post('', [\App\Http\Controllers\CatalogController::class,'store']);
            Route::get('{record}', [\App\Http\Controllers\CatalogController::class,'show'])->whereUuid('record');
            Route::patch('{record}', [\App\Http\Controllers\CatalogController::class,'update'])->whereUuid('record');
        });
        Route::prefix('tenants/{tenant}/inventory')->middleware('tenant')->group(function () {
            Route::get('{view}', [\App\Http\Controllers\InventoryController::class,'index'])->where('view','stocks|movements');
            Route::post('operations', [\App\Http\Controllers\InventoryController::class,'store']);
        });
        Route::prefix('tenants/{tenant}/orders')->middleware('tenant')->group(function () {
            Route::get('', [\App\Http\Controllers\SaleController::class,'index']);
            Route::post('', [\App\Http\Controllers\SaleController::class,'store']);
            Route::get('{order}', [\App\Http\Controllers\SaleController::class,'show'])->whereUuid('order');
        });
        Route::prefix('tenants/{tenant}/offline')->middleware('tenant')->group(function () {
            Route::post('prepare', [\App\Http\Controllers\OfflineController::class,'prepare'])->middleware('throttle:10,1');
            Route::get('reviews', [\App\Http\Controllers\OfflineController::class,'reviews']);
            Route::post('reviews/{operation}', [\App\Http\Controllers\OfflineController::class,'review'])->whereUuid('operation');
            Route::delete('leases/{lease}', [\App\Http\Controllers\OfflineController::class,'revoke'])->whereUuid('lease');
        });
        Route::get('tenants/{tenant}/changes', \App\Http\Controllers\ChangeFeedController::class)->middleware('tenant')->whereUuid('tenant');
        Route::get('tenants/{tenant}/payments', [\App\Http\Controllers\PaymentController::class,'index'])->middleware('tenant');
        Route::get('tenants/{tenant}/refunds', [\App\Http\Controllers\RefundController::class,'index'])->middleware('tenant');
        Route::post('tenants/{tenant}/orders/{order}/refunds', [\App\Http\Controllers\RefundController::class,'store'])->middleware('tenant')->whereUuid('order');
        Route::prefix('tenants/{tenant}/reports')->whereUuid('tenant')->middleware('tenant')->group(function () {
            Route::get('sales', [\App\Http\Controllers\ReportController::class,'sales']);
            Route::get('reconciliation', [\App\Http\Controllers\ReportController::class,'reconciliation']);
            Route::get('low-stock', [\App\Http\Controllers\ReportController::class,'lowStock']);
            Route::get('audit', [\App\Http\Controllers\ReportController::class,'audit']);
        });
        Route::post('tenants', [\App\Http\Controllers\TenantController::class, 'store'])->middleware('throttle:10,1');
        Route::patch('tenants/{tenant}', [\App\Http\Controllers\TenantController::class, 'update'])->middleware('tenant');
        Route::delete('session', [SessionController::class, 'destroy']);
        Route::get('me', fn (Request $request) => $request->user());
        Route::get('tenants', fn (Request $request) => $request->user()->tenants()->wherePivot('status', 'active')->get());
        Route::get('tenants/{tenant}/context', fn (Request $request) => [
            'tenant' => $request->attributes->get('tenant'),
            'role' => $request->attributes->get('membership')->role,
        ])->middleware('tenant');
    });
});
