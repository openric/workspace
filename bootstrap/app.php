<?php

use App\Api\Problem;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\RequireAccess;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/ric/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('sign-in'));
        $middleware->redirectUsersTo(fn () => route('home'));
        $middleware->alias(['active' => EnsureAccountActive::class, 'access' => RequireAccess::class]);
        // The interface's language for every web page (WS-005); the API answers in data, not prose.
        $middleware->appendToGroup('web', SetLocale::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The OpenRiC API answers every error as RFC 7807 problem details (Core Discovery 0.6.0, section 4).
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/ric/*') && ! $request->is('api/ric')) {
                return null;
            }
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $detail = match (true) {
                $status === 404 => 'There is nothing at '.$request->path().'.',
                $status === 405 => 'The OpenRiC read API takes GET only.',
                $status === 429 => 'Too many requests; try again after the time Retry-After gives.',
                default => $status >= 500 ? 'The server could not answer.' : $e->getMessage(),
            };

            return Problem::response($request, $status, $detail, $e instanceof HttpExceptionInterface ? $e->getHeaders() : []);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
