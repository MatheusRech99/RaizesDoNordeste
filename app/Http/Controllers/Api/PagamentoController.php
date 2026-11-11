<?php

namespace App\Http\Controllers\Api;

use App\Application\Pagamentos\SolicitarPagamento;
use App\Domain\Exceptions\CodigoErro;
use App\Domain\Exceptions\NegocioException;
use App\Domain\Pedidos\Pedido;
use App\Http\Controllers\Controller;
use App\Http\Requests\SolicitarPagamentoRequest;
use App\Http\Resources\PagamentoResource;
use Illuminate\Support\Facades\Gate;

class PagamentoController extends Controller
{
    /**
     * Solicita o pagamento do pedido
     *
     * CLIENTE (dono do pedido) ou ATENDENTE da unidade. Aceita `Idempotency-Key`. `simularResultado` (`APROVADO`, `RECUSADO`, `ERRO`) controla o gateway mock e não é aceito em produção.
     *
     * - `APROVADO`: 200, pedido vai para `PAGO` e os pontos de fidelidade são creditados (se houver consentimento).
     * - `RECUSADO`: 402 `PAGAMENTO_RECUSADO`, pedido cancelado e estoque devolvido.
     * - `ERRO`: 503 `GATEWAY_INDISPONIVEL`, pedido continua aguardando e pode tentar de novo.
     * - Pedido fora de `AGUARDANDO_PAGAMENTO` ou `valor` diferente do total: 409 `PEDIDO_NAO_PAGAVEL`.
     */
    public function store(SolicitarPagamentoRequest $request, SolicitarPagamento $solicitar)
    {
        $pedido = Pedido::find($request->integer('pedidoId'));
        if (! $pedido) {
            throw new NegocioException(CodigoErro::NAO_ENCONTRADO, 'Pedido não encontrado');
        }

        // quem pode ver o pedido pode pagar: cliente o dele, atendente o da unidade (RN13)
        Gate::authorize('ver', $pedido);

        $pagamento = $solicitar->executar(
            auth('api')->user(),
            $pedido,
            $request->input('valor') !== null ? (string) $request->input('valor') : null,
            $request->header('Idempotency-Key'),
            $request->input('simularResultado'),
        );

        // o resource devolveria 201 por o pagamento ser recem-criado; aqui e 200
        return (new PagamentoResource($pagamento))->response()->setStatusCode(200);
    }
}
