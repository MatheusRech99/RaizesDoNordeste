<?php

namespace App\Http\Controllers\Api;

use App\Application\Estoque\MovimentarEstoque;
use App\Domain\Estoque\Estoque;
use App\Domain\Estoque\MovimentacaoEstoque;
use App\Domain\Estoque\TipoMovimentacao;
use App\Domain\Unidades\Unidade;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListagemRequest;
use App\Http\Requests\MovimentacoesRequest;
use App\Http\Requests\MovimentarEstoqueRequest;
use App\Http\Resources\EstoqueResource;
use App\Http\Resources\MovimentacaoEstoqueResource;
use Illuminate\Support\Facades\Gate;

class EstoqueController extends Controller
{
    public function index(int $id, ListagemRequest $request)
    {
        $unidade = Unidade::ativaOuFalha($id);
        Gate::authorize('verEstoque', $unidade);

        $saldos = Estoque::where('unidade_id', $unidade->id)
            ->with('produto')
            ->join('produtos', 'produtos.id', '=', 'estoques.produto_id')
            ->orderBy('produtos.nome')
            ->select('estoques.*')
            ->paginate($request->limite());

        return EstoqueResource::collection($saldos);
    }

    public function movimentacoes(int $id, MovimentacoesRequest $request)
    {
        $unidade = Unidade::ativaOuFalha($id);
        Gate::authorize('verEstoque', $unidade);

        $lista = MovimentacaoEstoque::query()
            ->whereHas('estoque', function ($q) use ($unidade, $request) {
                $q->where('unidade_id', $unidade->id);

                if ($request->query('produtoId')) {
                    $q->where('produto_id', $request->query('produtoId'));
                }
            })
            ->when($request->query('tipo'), fn ($q, $tipo) => $q->where('tipo', $tipo))
            ->with('estoque')
            ->orderByDesc('id')
            ->paginate($request->limite());

        return MovimentacaoEstoqueResource::collection($lista);
    }

    public function movimentar(int $id, MovimentarEstoqueRequest $request, MovimentarEstoque $movimentar)
    {
        $unidade = Unidade::ativaOuFalha($id);
        Gate::authorize('movimentarEstoque', $unidade);

        $mov = $movimentar->executar(
            $unidade,
            $request->integer('produtoId'),
            TipoMovimentacao::from($request->input('tipo')),
            $request->integer('quantidade'),
            $request->input('motivo'),
            auth('api')->user(),
        );

        return (new MovimentacaoEstoqueResource($mov->load('estoque')))->response()->setStatusCode(201);
    }
}
