<?php

namespace App\Http\Controllers\Api;

use App\Application\Pedidos\CriarPedido;
use App\Domain\Exceptions\CodigoErro;
use App\Domain\Exceptions\NegocioException;
use App\Domain\Pedidos\Pedido;
use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Perfil;
use App\Http\Controllers\Controller;
use App\Http\Requests\CriarPedidoRequest;
use App\Http\Requests\ListarPedidosRequest;
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

    public function index(ListarPedidosRequest $request)
    {
        $usuario = auth('api')->user();
        $unidadeId = $request->query('unidadeId');

        // RN13: equipe da unidade nao filtra por outra unidade
        $daEquipe = ! in_array($usuario->perfil, [Perfil::ADMIN, Perfil::CLIENTE]);
        if ($daEquipe && $unidadeId && (int) $unidadeId !== $usuario->unidade_id) {
            throw new NegocioException(CodigoErro::ACESSO_NEGADO, 'Você não tem permissão para esta unidade');
        }

        $pedidos = Pedido::visivelPara($usuario)
            ->when($unidadeId, fn ($q, $v) => $q->where('unidade_id', $v))
            ->when($request->query('canalPedido'), fn ($q, $v) => $q->where('canal_pedido', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->with('itens.produto')
            ->orderByDesc('id')
            ->paginate($request->limite());

        return PedidoResource::collection($pedidos);
    }

    public function show(int $id)
    {
        $pedido = Pedido::with(['itens.produto', 'historico'])->find($id);
        if (! $pedido) {
            throw new NegocioException(CodigoErro::NAO_ENCONTRADO, 'Pedido não encontrado');
        }

        Gate::authorize('ver', $pedido);

        return new PedidoResource($pedido);
    }
}
