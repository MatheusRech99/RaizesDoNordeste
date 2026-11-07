<?php

namespace App\Http;

use App\Domain\Exceptions\CodigoErro;
use App\Domain\Exceptions\NegocioException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class RespostaDeErro
{
    public function __invoke(Throwable $e, Request $request): JsonResponse
    {
        if ($e instanceof NegocioException) {
            return $this->montar($request, $e->codigo, $e->getMessage(), $e->detalhes);
        }

        if ($e instanceof ValidationException) {
            $detalhes = [];
            foreach ($e->errors() as $campo => $mensagens) {
                foreach ($mensagens as $mensagem) {
                    $detalhes[] = ['campo' => $campo, 'mensagem' => $mensagem];
                }
            }
            return $this->montar($request, CodigoErro::VALIDACAO, 'Dados inválidos', $detalhes);
        }

        if ($e instanceof AuthenticationException) {
            return $this->montar($request, CodigoErro::NAO_AUTENTICADO, 'Token ausente, inválido ou expirado');
        }

        if ($e instanceof AuthorizationException) {
            return $this->montar($request, CodigoErro::ACESSO_NEGADO, 'Você não tem permissão para esta operação');
        }

        if ($e instanceof HttpExceptionInterface) {
            return $this->deHttp($request, $e);
        }

        return $this->montar($request, CodigoErro::ERRO_INTERNO, 'Erro interno do servidor');
    }

    private function deHttp(Request $request, HttpExceptionInterface $e): JsonResponse
    {
        $resposta = match ($e->getStatusCode()) {
            401 => $this->montar($request, CodigoErro::NAO_AUTENTICADO, 'Token ausente, inválido ou expirado'),
            403 => $this->montar($request, CodigoErro::ACESSO_NEGADO, 'Você não tem permissão para esta operação'),
            404 => $this->montar($request, CodigoErro::NAO_ENCONTRADO, 'Recurso não encontrado'),
            405 => $this->montar($request, CodigoErro::METODO_NAO_PERMITIDO, 'Método não permitido para este recurso'),
            429 => $this->montar($request, CodigoErro::MUITAS_REQUISICOES, 'Muitas requisições, tente mais tarde'),
            default => $this->montar($request, CodigoErro::ERRO_INTERNO, 'Erro interno do servidor'),
        };

        // mantem headers que o framework mandou (Allow, Retry-After...)
        foreach ($e->getHeaders() as $nome => $valor) {
            $resposta->headers->set($nome, $valor);
        }

        return $resposta;
    }

    private function montar(Request $request, CodigoErro $codigo, string $mensagem, array $detalhes = []): JsonResponse
    {
        return response()->json([
            'error' => $codigo->value,
            'message' => $mensagem,
            'details' => $detalhes,
            'timestamp' => now()->toIso8601String(),
            'path' => '/'.$request->path(),
            'requestId' => $request->attributes->get('requestId') ?? (string) Str::uuid(),
        ], $codigo->status());
    }
}
