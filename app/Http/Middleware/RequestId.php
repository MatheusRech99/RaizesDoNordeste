<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        // aproveita o id que veio do cliente/gateway, se tiver
        $id = $request->header('X-Request-Id') ?: (string) Str::uuid();
        $request->attributes->set('requestId', $id);

        $resposta = $next($request);
        $resposta->headers->set('X-Request-Id', $id);

        return $resposta;
    }
}
