<?php

namespace App\Http\Controllers\Api;

use App\Domain\Exceptions\CodigoErro;
use App\Domain\Exceptions\NegocioException;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UsuarioResource;

class AuthController extends Controller
{
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
    public function refresh()
    {
        return $this->respostaToken(auth('api')->refresh());
    }

    public function logout()
    {
        auth('api')->logout();

        return response()->noContent();
    }

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
