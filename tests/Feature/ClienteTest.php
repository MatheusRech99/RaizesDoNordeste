<?php

namespace Tests\Feature;

use App\Domain\Usuarios\Consentimento;
use App\Domain\Usuarios\Finalidade;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClienteTest extends TestCase
{
    use RefreshDatabase;

    private function dados(array $extra = []): array
    {
        return array_merge([
            'nome' => 'Maria Silva',
            'email' => 'maria@exemplo.com',
            'senha' => 'senha1234',
            'aceiteTermos' => true,
        ], $extra);
    }

    private function clienteComConsentimentos(bool $fidelidade = true, bool $marketing = true, string $email = 'maria@exemplo.com'): Usuario
    {
        $this->postJson('/clientes', $this->dados([
            'email' => $email,
            'dataNascimento' => $fidelidade ? '1990-05-20' : null,
            'consentimentoFidelidade' => $fidelidade,
            'consentimentoMarketing' => $marketing,
        ]))->assertCreated();

        return Usuario::where('email', $email)->first();
    }

    public function test_cadastro_so_com_termos(): void
    {
        $r = $this->postJson('/clientes', $this->dados());

        $r->assertCreated()
            ->assertJsonPath('data.perfil', 'CLIENTE')
            ->assertJsonPath('data.email', 'maria@exemplo.com')
            ->assertJsonMissingPath('data.senha')
            ->assertJsonMissingPath('data.dataNascimento');

        $cliente = Usuario::where('email', 'maria@exemplo.com')->first();
        $this->assertTrue($cliente->temConsentimento(Finalidade::TERMOS));
        $this->assertFalse($cliente->temConsentimento(Finalidade::FIDELIDADE));
        $this->assertFalse($cliente->temConsentimento(Finalidade::MARKETING));
    }

    public function test_cadastro_com_fidelidade_e_marketing_guarda_nascimento(): void
    {
        $this->postJson('/clientes', $this->dados([
            'telefone' => '81999990000',
            'dataNascimento' => '1990-05-20',
            'consentimentoFidelidade' => true,
            'consentimentoMarketing' => true,
        ]))->assertCreated()->assertJsonMissingPath('data.dataNascimento');

        $cliente = Usuario::where('email', 'maria@exemplo.com')->first();
        $this->assertSame('1990-05-20', $cliente->data_nascimento->toDateString());
        $this->assertTrue($cliente->temConsentimento(Finalidade::FIDELIDADE));
        $this->assertTrue($cliente->temConsentimento(Finalidade::MARKETING));
        $this->assertStringNotContainsString('1990', $this->comoUsuario($cliente)->getJson('/auth/me')->getContent());
    }

    public function test_consentimento_guarda_versao_e_ip(): void
    {
        config(['lgpd.versao_termo' => '2.1']);

        $this->postJson('/clientes', $this->dados(), ['REMOTE_ADDR' => '203.0.113.7'])->assertCreated();

        $c = Consentimento::where('finalidade', Finalidade::TERMOS)->first();
        $this->assertSame('2.1', $c->versao_termo);
        $this->assertSame('203.0.113.7', $c->ip);
        $this->assertTrue($c->aceito);
        $this->assertSame(3, Consentimento::count());
    }

    public function test_sem_aceite_dos_termos_da_422(): void
    {
        foreach ([[], ['aceiteTermos' => false]] as $extra) {
            $dados = $this->dados($extra);
            if ($extra === []) {
                unset($dados['aceiteTermos']);
            }

            $this->postJson('/clientes', $dados)
                ->assertStatus(422)
                ->assertJsonPath('error', 'VALIDACAO')
                ->assertJsonPath('details.0.campo', 'aceiteTermos');
        }

        $this->assertSame(0, Usuario::count());
    }

    public function test_nascimento_sem_consentimento_de_fidelidade_da_422(): void
    {
        $this->postJson('/clientes', $this->dados(['dataNascimento' => '1990-05-20']))
            ->assertStatus(422)
            ->assertJsonPath('error', 'VALIDACAO')
            ->assertJsonPath('details.0.campo', 'dataNascimento');

        $this->postJson('/clientes', $this->dados([
            'dataNascimento' => '1990-05-20',
            'consentimentoFidelidade' => false,
        ]))->assertStatus(422);

        $this->assertSame(0, Usuario::count());
    }

    public function test_valida_campos_do_cadastro(): void
    {
        $this->postJson('/clientes', [
            'email' => 'nao-e-email',
            'senha' => '123',
            'aceiteTermos' => true,
            'consentimentoMarketing' => 'talvez',
            'dataNascimento' => '2999-01-01',
        ])->assertStatus(422)->assertJsonCount(5, 'details');
    }

    public function test_email_repetido_da_422(): void
    {
        Usuario::factory()->create(['email' => 'maria@exemplo.com']);

        $this->postJson('/clientes', $this->dados())
            ->assertStatus(422)
            ->assertJsonPath('details.0.campo', 'email');
    }

    public function test_cliente_cadastrado_consegue_logar(): void
    {
        $this->postJson('/clientes', $this->dados())->assertCreated();

        $this->postJson('/auth/login', ['email' => 'maria@exemplo.com', 'senha' => 'senha1234'])->assertOk();
    }

    public function test_cadastro_nao_deixa_escolher_perfil(): void
    {
        $this->postJson('/clientes', $this->dados(['perfil' => 'ADMIN']))->assertCreated();

        $this->assertSame(Perfil::CLIENTE, Usuario::first()->perfil);
    }

    public function test_cliente_consulta_os_proprios_consentimentos(): void
    {
        $cliente = $this->clienteComConsentimentos(true, false);
        Usuario::factory()->create()->consentimentos()->create([
            'finalidade' => Finalidade::MARKETING, 'aceito' => true, 'versao_termo' => '1.0', 'ip' => '10.0.0.1',
        ]);

        $r = $this->comoUsuario($cliente)->getJson('/clientes/me/consentimentos');

        $r->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(
            ['TERMOS' => true, 'FIDELIDADE' => true, 'MARKETING' => false],
            collect($r->json('data'))->pluck('aceito', 'finalidade')->all(),
        );
        $r->assertJsonPath('data.0.versaoTermo', '1.0')->assertJsonPath('data.0.revogadoEm', null);
    }

    public function test_revogar_fidelidade_limpa_nascimento_e_marca_a_data(): void
    {
        $cliente = $this->clienteComConsentimentos();

        $this->comoUsuario($cliente)->deleteJson('/clientes/me/consentimentos/FIDELIDADE')
            ->assertOk()
            ->assertJsonPath('data.finalidade', 'FIDELIDADE')
            ->assertJsonPath('data.aceito', false);

        $cliente->refresh();
        $this->assertFalse($cliente->temConsentimento(Finalidade::FIDELIDADE));
        $this->assertTrue($cliente->temConsentimento(Finalidade::MARKETING));
        $this->assertNull($cliente->data_nascimento);
        $this->assertNotNull($cliente->consentimentos()->where('finalidade', Finalidade::FIDELIDADE)->first()->revogado_em);
    }

    public function test_revogar_marketing_nao_mexe_no_nascimento(): void
    {
        $cliente = $this->clienteComConsentimentos();

        $this->comoUsuario($cliente)->deleteJson('/clientes/me/consentimentos/MARKETING')->assertOk();

        $this->assertNotNull($cliente->fresh()->data_nascimento);
    }

    public function test_revogar_de_novo_ou_sem_ter_aceito_da_404(): void
    {
        $cliente = $this->clienteComConsentimentos(false, false);

        $this->comoUsuario($cliente)->deleteJson('/clientes/me/consentimentos/MARKETING')
            ->assertNotFound()->assertJsonPath('error', 'NAO_ENCONTRADO');

        $cliente = $this->clienteComConsentimentos(true, true, 'joao@exemplo.com');
        $this->comoUsuario($cliente)->deleteJson('/clientes/me/consentimentos/FIDELIDADE')->assertOk();
        $this->comoUsuario($cliente)->deleteJson('/clientes/me/consentimentos/FIDELIDADE')->assertNotFound();
    }

    public function test_termos_nao_sao_revogaveis(): void
    {
        $cliente = $this->clienteComConsentimentos();

        $this->comoUsuario($cliente)->deleteJson('/clientes/me/consentimentos/TERMOS')
            ->assertStatus(422)->assertJsonPath('error', 'VALIDACAO');

        $this->assertTrue($cliente->temConsentimento(Finalidade::TERMOS));
    }

    public function test_finalidade_desconhecida_da_404(): void
    {
        $cliente = $this->clienteComConsentimentos();

        $this->comoUsuario($cliente)->deleteJson('/clientes/me/consentimentos/OUTRA')->assertNotFound();
    }

    public function test_rotas_de_consentimento_exigem_login_de_cliente(): void
    {
        $this->getJson('/clientes/me/consentimentos')->assertUnauthorized();

        $admin = Usuario::factory()->create(['perfil' => Perfil::ADMIN]);
        $this->comoUsuario($admin)->getJson('/clientes/me/consentimentos')->assertForbidden();
        $this->comoUsuario($admin)->deleteJson('/clientes/me/consentimentos/MARKETING')->assertForbidden();
    }
}
