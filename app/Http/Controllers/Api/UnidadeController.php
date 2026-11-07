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
    public function index(ListagemRequest $request)
    {
        $unidades = Unidade::with('horarios')
            ->where('ativa', true)
            ->orderBy('nome')
            ->paginate($request->limite());

        return UnidadeResource::collection($unidades);
    }

    public function show(int $id)
    {
        $unidade = Unidade::ativaOuFalha($id)->load('horarios');

        return new UnidadeResource($unidade);
    }

    public function update(int $id, AtualizarUnidadeRequest $request, ConfigurarUnidade $configurar)
    {
        $unidade = Unidade::ativaOuFalha($id);
        Gate::authorize('configurar', $unidade);

        return new UnidadeResource($configurar->executar($unidade, $request->validated()));
    }
}
