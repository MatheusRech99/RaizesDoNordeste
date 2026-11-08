<?php

namespace App\Http\Controllers\Api;

use App\Application\Pedidos\CriarPedido;
use App\Domain\Unidades\Unidade;
use App\Http\Controllers\Controller;
use App\Http\Requests\CriarPedidoRequest;
use App\Http\Resources\PedidoResource;
use Illuminate\Support\Facades\Gate;

class PedidoController extends Controller
{
    public function store(CriarPedidoRequest $request, CriarPedido $criar)
    {
        $unidade = Unidade::ativaOuFalha($request->integer('unidadeId'));
        Gate::authorize('criarPedido', $unidade);

        $pedido = $criar->executar(
            auth('api')->user(),
            $unidade,
            $request->validated(),
            $request->header('Idempotency-Key'),
        );

        return (new PedidoResource($pedido))->response()->setStatusCode(201);
    }
}
