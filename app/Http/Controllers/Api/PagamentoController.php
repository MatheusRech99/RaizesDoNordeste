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
