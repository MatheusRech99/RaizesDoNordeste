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
    /**
     * Lista o catálogo
     *
     * ADMIN e GERENTE. Paginado.
     */
    public function index(ListagemRequest $request)
    {
        return ProdutoResource::collection(Produto::orderBy('nome')->paginate($request->limite()));
    }

    /**
     * Detalha um produto
     *
     * ADMIN e GERENTE. 404 `PRODUTO_NAO_ENCONTRADO`.
     */
    public function show(int $id)
    {
        return new ProdutoResource(Produto::acharOuFalha($id));
    }

    /**
     * Cria produto
     *
     * Só ADMIN.
     */
    public function store(ProdutoRequest $request, ManterProduto $manter)
    {
        return (new ProdutoResource($manter->criar($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Atualiza produto
     *
     * Só ADMIN. 404 `PRODUTO_NAO_ENCONTRADO`.
     */
    public function update(int $id, ProdutoRequest $request, ManterProduto $manter)
    {
        $produto = Produto::acharOuFalha($id);

        return new ProdutoResource($manter->atualizar($produto, $request->validated()));
    }
}
