<?php

namespace App\Http\Controllers\Api;

use App\Domain\Fidelidade\PontosFidelidade;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListagemRequest;
use App\Http\Resources\PontosResource;

class FidelidadeController extends Controller
{
    /**
     * Saldo de pontos
     *
     * Só CLIENTE. Soma dos lançamentos de pontos.
     */
    public function saldo()
    {
        return response()->json(['data' => ['saldo' => PontosFidelidade::saldoDe(auth('api')->id())]]);
    }

    /**
     * Extrato de pontos
     *
     * Só CLIENTE. Do mais novo para o mais antigo. Estornos aparecem com pontos negativos.
     */
    public function extrato(ListagemRequest $request)
    {
        $lista = PontosFidelidade::where('usuario_id', auth('api')->id())
            ->orderByDesc('id')
            ->paginate($request->limite());

        return PontosResource::collection($lista);
    }
}
