<?php

namespace App\Http\Middleware;

use App\Domain\Exceptions\CodigoErro;
use App\Domain\Exceptions\NegocioException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExigePerfil
{
    // uso: ->middleware('perfil:ADMIN,GERENTE')
    public function handle(Request $request, Closure $next, string ...$perfis): Response
    {
        $usuario = $request->user();

        if (! $usuario) {
            throw new NegocioException(CodigoErro::NAO_AUTENTICADO, 'Token ausente, inválido ou expirado');
        }

        if (! in_array($usuario->perfil->value, $perfis)) {
            throw new NegocioException(CodigoErro::ACESSO_NEGADO, 'Seu perfil não pode executar esta operação');
        }

        return $next($request);
    }
}
