<?php

namespace Tests\Feature;

use App\Domain\Usuarios\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function logar(Usuario $usuario, string $senha = 'senha123'): string
    {
        return $this->postJson('/auth/login', ['email' => $usuario->email, 'senha' => $senha])
            ->json('accessToken');
    }

    public function test_login_com_credenciais_certas_devolve_o_token(): void
    {
        $usuario = Usuario::factory()->create();

        $r = $this->postJson('/auth/login', ['email' => $usuario->email, 'senha' => 'senha123']);

        $r->assertOk()->assertJsonStructure(['accessToken', 'tokenType', 'expiresIn']);
        $this->assertSame('Bearer', $r->json('tokenType'));
        $this->assertSame(3600, $r->json('expiresIn'));
    }

    public function test_login_com_senha_errada_da_401_no_formato_padrao(): void
    {
        $usuario = Usuario::factory()->create();

        $r = $this->postJson('/auth/login', ['email' => $usuario->email, 'senha' => 'errada']);

        $r->assertStatus(401)->assertJsonPath('error', 'NAO_AUTENTICADO');
        $r->assertJsonStructure(['error', 'message', 'details', 'timestamp', 'path', 'requestId']);
    }

    public function test_login_com_email_que_nao_existe_da_401(): void
    {
        $r = $this->postJson('/auth/login', ['email' => 'ninguem@teste.com', 'senha' => 'senha123']);

        $r->assertStatus(401)->assertJsonPath('error', 'NAO_AUTENTICADO');
    }

    public function test_login_sem_campos_da_422(): void
    {
        $r = $this->postJson('/auth/login', []);

        $r->assertStatus(422)->assertJsonPath('error', 'VALIDACAO');
        $campos = array_column($r->json('details'), 'campo');
        $this->assertContains('email', $campos);
        $this->assertContains('senha', $campos);
    }

    public function test_me_devolve_o_usuario_sem_senha_nem_data_de_nascimento(): void
    {
        $usuario = Usuario::factory()->create();
        $token = $this->logar($usuario);

        $r = $this->comToken($token)->getJson('/auth/me');

        $r->assertOk()->assertJsonPath('data.email', $usuario->email);
        $r->assertJsonPath('data.perfil', 'CLIENTE');
        $r->assertJsonMissingPath('data.senha');
        $r->assertJsonMissingPath('data.data_nascimento');
        $r->assertJsonMissingPath('data.dataNascimento');
        $this->assertStringNotContainsString($usuario->data_nascimento->format('Y-m-d'), $r->getContent());
    }

    public function test_me_sem_token_da_401(): void
    {
        $this->getJson('/auth/me')->assertStatus(401)->assertJsonPath('error', 'NAO_AUTENTICADO');
    }

    public function test_me_com_token_invalido_da_401(): void
    {
        $this->comToken('isso.nao.e-um-jwt')->getJson('/auth/me')
            ->assertStatus(401)->assertJsonPath('error', 'NAO_AUTENTICADO');
    }

    public function test_token_expira_depois_de_60_minutos(): void
    {
        $token = $this->logar(Usuario::factory()->create());

        $this->travel(61)->minutes();

        $this->comToken($token)->getJson('/auth/me')->assertStatus(401);
    }

    public function test_refresh_com_token_valido_gera_outro_e_invalida_o_antigo(): void
    {
        $antigo = $this->logar(Usuario::factory()->create());

        $r = $this->comToken($antigo)->postJson('/auth/refresh');
        $novo = $r->json('accessToken');

        $r->assertOk();
        $this->assertNotSame($antigo, $novo);
        $this->comToken($novo)->getJson('/auth/me')->assertOk();
        $this->comToken($antigo)->getJson('/auth/me')->assertStatus(401);
    }

    public function test_refresh_aceita_token_expirado_dentro_do_prazo_de_refresh(): void
    {
        $token = $this->logar(Usuario::factory()->create());

        $this->travel(90)->minutes();

        $r = $this->comToken($token)->postJson('/auth/refresh');

        $r->assertOk();
        $this->comToken($r->json('accessToken'))->getJson('/auth/me')->assertOk();
    }

    public function test_refresh_sem_token_da_401(): void
    {
        $this->postJson('/auth/refresh')->assertStatus(401)->assertJsonPath('error', 'NAO_AUTENTICADO');
    }

    public function test_logout_invalida_o_token(): void
    {
        $token = $this->logar(Usuario::factory()->create());

        $this->comToken($token)->postJson('/auth/logout')->assertNoContent();

        $this->comToken($token)->getJson('/auth/me')->assertStatus(401);
    }
}
