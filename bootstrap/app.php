<?php

use App\Http;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', then: function () {
        Route::prefix('internal')->group(__DIR__.'/../routes/internal.php');
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->group('api', ['web']);
        $middleware->alias(['notice' => Http\Middleware\RequireNotice::class]);
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $r) => $r->is('api/*') || $r->is('internal/*') || $r->expectsJson());
        $exceptions->render(fn (ValidationException $e) => response()->json(['message' => $e->validator->errors()->first(), 'errors' => $e->errors()], 422));
        $exceptions->render(fn (AuthenticationException $e) => response()->json(['message' => '先登录，再一起逛音乐库吧。'], 401));
        $exceptions->render(fn (UniqueConstraintViolationException $e) => response()->json(['message' => '已有相同的关联或名称，请刷新后核对。'], 409));
        $exceptions->render(fn (LockTimeoutException $e) => response()->json(['message' => '这份资源正在处理，稍等一下再试。'], 423));
    })->create();
