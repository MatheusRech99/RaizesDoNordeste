<?php

namespace App\Http\Controllers\Api;

use App\Domain\Fidelidade\PontosFidelidade;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListagemRequest;
use App\Http\Resources\PontosResource;

class FidelidadeController extends Controller
{
    public function saldo()
    {
        return response()->json(['data' => ['saldo' => PontosFidelidade::saldoDe(auth('api')->id())]]);
    }

    public function extrato(ListagemRequest $request)
    {
        $lista = PontosFidelidade::where('usuario_id', auth('api')->id())
            ->orderByDesc('id')
            ->paginate($request->limite());

        return PontosResource::collection($lista);
    }
}
