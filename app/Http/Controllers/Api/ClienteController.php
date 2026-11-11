<?php

namespace App\Http\Controllers\Api;

use App\Application\Clientes\CadastrarCliente;
use App\Application\Clientes\ConsultarCliente;
use App\Application\Clientes\RevogarConsentimento;
use App\Domain\Exceptions\CodigoErro;
use App\Domain\Exceptions\NegocioException;
use App\Domain\Usuarios\Finalidade;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use App\Http\Controllers\Controller;
use App\Http\Requests\CadastroClienteRequest;
use App\Http\Resources\ClienteResource;
use App\Http\Resources\ConsentimentoResource;
use App\Http\Resources\UsuarioResource;
use Illuminate\Support\Facades\Gate;

class ClienteController extends Controller
{
    /**
     * Cadastra cliente
     *
     * Público. `aceiteTermos` é obrigatório; fidelidade e marketing são consentimentos separados. A data de nascimento só vale com consentimento de fidelidade.
     */
    public function store(CadastroClienteRequest $request, CadastrarCliente $cadastrar)
    {
        $cliente = $cadastrar->executar($request->validated(), $request->ip());

        return (new UsuarioResource($cliente))->response()->setStatusCode(201);
    }

    /**
     * Meus consentimentos
     *
     * Só CLIENTE.
     */
    public function consentimentos()
    {
        $lista = auth('api')->user()->consentimentos()->orderBy('id')->get();

        return ConsentimentoResource::collection($lista);
    }

    /**
     * Revoga consentimento
     *
     * Só CLIENTE. `FIDELIDADE` ou `MARKETING` (os termos não se revogam: 422). Revogar fidelidade apaga a data de nascimento. 404 se não há consentimento ativo.
     */
    public function revogar(string $finalidade, RevogarConsentimento $revogar)
    {
        $consentimento = $revogar->executar(auth('api')->user(), Finalidade::from($finalidade));

        return new ConsentimentoResource($consentimento);
    }

    /**
     * Dados do cliente para a equipe
     *
     * ADMIN (qualquer cliente), GERENTE e ATENDENTE (só quem já pediu na unidade, senão 403). E-mail e telefone saem mascarados e no lugar da data de nascimento vai a faixa etária. Cada leitura grava `ACESSO_DADOS_PESSOAIS` na auditoria.
     */
    public function show(int $id, ConsultarCliente $consultar)
    {
        $cliente = Usuario::where('perfil', Perfil::CLIENTE)->find($id);
        if (! $cliente) {
            throw new NegocioException(CodigoErro::NAO_ENCONTRADO, 'Cliente não encontrado');
        }

        Gate::authorize('verCliente', $cliente);

        return new ClienteResource($consultar->executar(auth('api')->user(), $cliente));
    }
}
