<?php

namespace App\Http\Controllers\Api;

use App\Application\Cardapio\ManterProduto;
use App\Domain\Cardapio\Produto;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListagemRequest;
use App\Http\Requests\ProdutoRequest;
use App\Http\Resources\ProdutoResource;

class ProdutoController extends Controller
{
    public function index(ListagemRequest $request)
    {
        return ProdutoResource::collection(Produto::orderBy('nome')->paginate($request->limite()));
    }

    public function show(int $id)
    {
        return new ProdutoResource(Produto::acharOuFalha($id));
    }

    public function store(ProdutoRequest $request, ManterProduto $manter)
    {
        return new ProdutoResource($manter->criar($request->validated()));
    }

    public function update(int $id, ProdutoRequest $request, ManterProduto $manter)
    {
        $produto = Produto::acharOuFalha($id);

        return new ProdutoResource($manter->atualizar($produto, $request->validated()));
    }
}
