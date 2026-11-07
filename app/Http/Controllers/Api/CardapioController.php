<?php

namespace App\Http\Controllers\Api;

use App\Application\Cardapio\MontarCardapio;
use App\Domain\Cardapio\ItemCardapio;
use App\Domain\Unidades\Unidade;
use App\Http\Controllers\Controller;
use App\Http\Requests\CardapioRequest;
use App\Http\Requests\ItemCardapioRequest;
use App\Http\Resources\ItemCardapioResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class CardapioController extends Controller
{
    public function index(int $id, CardapioRequest $request)
    {
        $unidade = Unidade::ativaOuFalha($id);
        $data = $request->query('data') ? Carbon::parse($request->query('data')) : now();

        $itens = ItemCardapio::cardapioDe($unidade, $data)->paginate($request->limite());

        return ItemCardapioResource::collection($itens);
    }

    public function salvar(int $id, int $produtoId, ItemCardapioRequest $request, MontarCardapio $montar)
    {
        $unidade = Unidade::ativaOuFalha($id);
        Gate::authorize('montarCardapio', $unidade);

        return new ItemCardapioResource($montar->executar($unidade, $produtoId, $request->validated()));
    }
}
