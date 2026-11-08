<?php

namespace App\Http\Middleware;

use App\Domain\Exceptions\CodigoErro;
use App\Domain\Exceptions\NegocioException;
use App\Infrastructure\Idempotencia\ChaveIdempotencia;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

// RN07: uso ->middleware(['auth:api', 'idempotente']) em POST /pedidos e POST /pagamentos
class Idempotente
{
    private const HORAS_DE_VALIDADE = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $chave = $request->header('Idempotency-Key');
        $usuario = $request->user();

        // a chave e opcional
        if ($chave === null || ! $usuario) {
            return $next($request);
        }

        if ($chave === '' || strlen($chave) > 100 || preg_match('/[^\x21-\x7E]/', $chave)) {
            throw new NegocioException(CodigoErro::VALIDACAO, 'Idempotency-Key inválida', [
                ['campo' => 'Idempotency-Key', 'mensagem' => 'Use de 1 a 100 caracteres visíveis, sem espaços'],
            ]);
        }

        $escopo = $request->method().' '.$request->route()->uri();
        $hash = $this->hashDoCorpo($request);

        $registro = $this->achar($usuario->id, $escopo, $chave);

        if (! $registro) {
            try {
                $registro = ChaveIdempotencia::create([
                    'usuario_id' => $usuario->id,
                    'escopo' => $escopo,
                    'chave' => $chave,
                    'hash_corpo' => $hash,
                    'expira_em' => now()->addHours(self::HORAS_DE_VALIDADE),
                ]);
            } catch (UniqueConstraintViolationException) {
                // duas requisicoes com a mesma chave chegaram juntas e a outra ganhou
                return $this->repetida($this->achar($usuario->id, $escopo, $chave), $hash);
            }

            return $this->executar($request, $next, $registro);
        }

        return $this->repetida($registro, $hash);
    }

    private function executar(Request $request, Closure $next, ChaveIdempotencia $registro): Response
    {
        try {
            $resposta = $next($request);
        } catch (Throwable $e) {
            $registro->delete();
            throw $e;
        }

        if ($this->deveGuardar($resposta->getStatusCode())) {
            $registro->update([
                'status_resposta' => $resposta->getStatusCode(),
                'corpo_resposta' => $resposta->getContent(),
            ]);
        } else {
            // falha de validacao, de permissao ou do servidor: a chave fica livre para tentar de novo
            $registro->delete();
        }

        return $resposta;
    }

    private function repetida(?ChaveIdempotencia $registro, string $hash): Response
    {
        if (! $registro || ! hash_equals($registro->hash_corpo, $hash)) {
            throw new NegocioException(
                CodigoErro::CHAVE_IDEMPOTENCIA_CONFLITANTE,
                'Esta Idempotency-Key já foi usada com outro conteúdo',
            );
        }

        if ($registro->emAndamento()) {
            throw new NegocioException(
                CodigoErro::CHAVE_IDEMPOTENCIA_CONFLITANTE,
                'Já existe uma requisição com esta Idempotency-Key em processamento',
            );
        }

        return response($registro->corpo_resposta, $registro->status_resposta, [
            'Content-Type' => 'application/json',
            'Idempotent-Replayed' => 'true',
        ]);
    }

    private function achar(int $usuarioId, string $escopo, string $chave): ?ChaveIdempotencia
    {
        $registro = ChaveIdempotencia::where('usuario_id', $usuarioId)
            ->where('escopo', $escopo)
            ->where('chave', $chave)
            ->first();

        // vencida (24 h): apaga e trata como chave nova
        if ($registro && $registro->expira_em->isPast()) {
            $registro->delete();

            return null;
        }

        return $registro;
    }

    // 2xx e as recusas de regra de negocio (402, 404, 409) valem como resposta original;
    // 422, 401/403, 429 e 5xx nao, porque nada foi executado ou o cliente precisa poder repetir
    private function deveGuardar(int $status): bool
    {
        if ($status >= 200 && $status < 300) {
            return true;
        }

        return in_array($status, [402, 404, 409]);
    }

    // mesma requisicao mesmo com a ordem dos campos do JSON diferente
    private function hashDoCorpo(Request $request): string
    {
        return hash('sha256', json_encode($this->ordenar($request->all())));
    }

    private function ordenar(mixed $valor): mixed
    {
        if (! is_array($valor)) {
            return $valor;
        }

        $valor = array_map(fn ($v) => $this->ordenar($v), $valor);

        if (! array_is_list($valor)) {
            ksort($valor);
        }

        return $valor;
    }
}
