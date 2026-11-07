<?php

namespace Tests\Feature;

use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ExigePerfilTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth:api', 'perfil:ADMIN,GERENTE'])->get('/teste/restrito', fn () => ['ok' => true]);
    }

    private function comPerfil(Perfil $perfil): string
    {
        $usuario = Usuario::factory()->create(['perfil' => $perfil]);

        return $this->postJson('/auth/login', ['email' => $usuario->email, 'senha' => 'senha123'])
            ->json('accessToken');
    }

    public function test_perfil_permitido_passa(): void
    {
        foreach ([Perfil::ADMIN, Perfil::GERENTE] as $perfil) {
            $this->comToken($this->comPerfil($perfil))->getJson('/teste/restrito')->assertOk();
        }
    }

    public function test_perfil_nao_permitido_leva_403(): void
    {
        foreach ([Perfil::ATENDENTE, Perfil::COZINHA, Perfil::CLIENTE] as $perfil) {
            $r = $this->comToken($this->comPerfil($perfil))->getJson('/teste/restrito');

            $r->assertStatus(403)->assertJsonPath('error', 'ACESSO_NEGADO');
        }
    }

    public function test_sem_token_leva_401_antes_de_checar_perfil(): void
    {
        $this->getJson('/teste/restrito')->assertStatus(401)->assertJsonPath('error', 'NAO_AUTENTICADO');
    }
}
