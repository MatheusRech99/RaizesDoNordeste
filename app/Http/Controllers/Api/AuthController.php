<?php

namespace App\Http\Controllers\Api;

use App\Domain\Exceptions\CodigoErro;
use App\Domain\Exceptions\NegocioException;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UsuarioResource;

class AuthController extends Controller
{
    /**
     * Login
     *
     * Devolve o token JWT (válido por 60 minutos). Credenciais erradas: 401 `NAO_AUTENTICADO`.
     */
    public function login(LoginRequest $request)
    {
        $token = auth('api')->attempt([
            'email' => $request->input('email'),
            'password' => $request->input('senha'),
        ]);

        if (! $token) {
            throw new NegocioException(CodigoErro::NAO_AUTENTICADO, 'E-mail ou senha inválidos');
        }

        return $this->respostaToken($token);
    }

    // sem auth:api de proposito, o refresh aceita token ja expirado (dentro do prazo do refresh)
    /**
     * Renova o token
     *
     * Aceita um token já expirado dentro da janela de renovação e devolve outro.
     */
    public function refresh()
    {
        return $this->respostaToken(auth('api')->refresh());
    }

    /**
     * Logout
     *
     * Invalida o token atual.
     */
    public function logout()
    {
        auth('api')->logout();

        return response()->noContent();
    }

    /**
     * Usuário logado
     *
     * Dados do próprio usuário, sem senha nem data de nascimento.
     */
    public function me()
    {
        return new UsuarioResource(auth('api')->user());
    }

    private function respostaToken(string $token)
    {
        return response()->json([
            'accessToken' => $token,
            'tokenType' => 'Bearer',
            'expiresIn' => auth('api')->factory()->getTTL() * 60,
        ]);
    }
}
