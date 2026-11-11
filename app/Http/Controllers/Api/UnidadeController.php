<?php

namespace App\Http\Controllers\Api;

use App\Application\Unidades\ConfigurarUnidade;
use App\Domain\Unidades\Unidade;
use App\Http\Controllers\Controller;
use App\Http\Requests\AtualizarUnidadeRequest;
use App\Http\Requests\ListagemRequest;
use App\Http\Resources\UnidadeResource;
use Illuminate\Support\Facades\Gate;

class UnidadeController extends Controller
{
    /**
     * Lista as unidades ativas
     *
     * Público, paginado.
     */
    public function index(ListagemRequest $request)
    {
        $unidades = Unidade::with('horarios')
            ->where('ativa', true)
            ->orderBy('nome')
            ->paginate($request->limite());

        return UnidadeResource::collection($unidades);
    }

    /**
     * Detalha uma unidade
     *
     * Público. Traz formato e horários de funcionamento. Unidade inexistente ou inativa: 404 `UNIDADE_NAO_ENCONTRADA`.
     */
    public function show(int $id)
    {
        $unidade = Unidade::ativaOuFalha($id)->load('horarios');

        return new UnidadeResource($unidade);
    }

    /**
     * Atualiza formato e horários
     *
     * ADMIN em qualquer unidade, GERENTE só na sua (403). Trocar para formato `REDUZIDO` com produto que exige cozinha completa no cardápio: 422 `PRODUTO_INCOMPATIVEL_COM_UNIDADE`.
     */
    public function update(int $id, AtualizarUnidadeRequest $request, ConfigurarUnidade $configurar)
    {
        $unidade = Unidade::ativaOuFalha($id);
        Gate::authorize('configurar', $unidade);

        return new UnidadeResource($configurar->executar($unidade, $request->validated()));
    }
}
