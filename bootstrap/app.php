<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Configuration\Exceptions;
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix'=>'api/v1','middleware'=>['api','auth:sanctum',App\Http\Middleware\IdentityContext::class]])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(App\Http\Middleware\RequestCorrelation::class);
        $middleware->statefulApi();
        $middleware->alias(['tenant' => App\Http\Middleware\ResolveTenant::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {})
    ->create();
