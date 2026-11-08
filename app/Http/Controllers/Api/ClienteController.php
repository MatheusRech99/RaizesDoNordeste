<?php

namespace App\Http\Controllers\Api;

use App\Application\Clientes\CadastrarCliente;
use App\Application\Clientes\RevogarConsentimento;
use App\Domain\Usuarios\Finalidade;
use App\Http\Controllers\Controller;
use App\Http\Requests\CadastroClienteRequest;
use App\Http\Resources\ConsentimentoResource;
use App\Http\Resources\UsuarioResource;

class ClienteController extends Controller
{
    public function store(CadastroClienteRequest $request, CadastrarCliente $cadastrar)
    {
        $cliente = $cadastrar->executar($request->validated(), $request->ip());

        return (new UsuarioResource($cliente))->response()->setStatusCode(201);
    }

    public function consentimentos()
    {
        $lista = auth('api')->user()->consentimentos()->orderBy('id')->get();

        return ConsentimentoResource::collection($lista);
    }

    public function revogar(string $finalidade, RevogarConsentimento $revogar)
    {
        $consentimento = $revogar->executar(auth('api')->user(), Finalidade::from($finalidade));

        return new ConsentimentoResource($consentimento);
    }
}
