<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // guard e jwt guardam usuario/token entre requisicoes dentro do mesmo teste, entao limpo antes de mandar o token
    protected function comToken(string $token): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();

        return $this->withToken($token);
    }
}
