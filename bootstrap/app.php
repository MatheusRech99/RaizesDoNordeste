<?php

use App\Http\Middleware\RequestId;
use App\Http\RespostaDeErro;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: '',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RequestId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // a aplicacao inteira e API, entao toda falha sai em JSON
        $exceptions->shouldRenderJsonWhen(fn () => true);
        $exceptions->render(new RespostaDeErro());
    })->create();
