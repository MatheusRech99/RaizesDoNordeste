<?php

namespace Tests\Feature;

use App\Domain\Auditoria\Acao;
use App\Domain\Auditoria\RegistroAuditoria;
use App\Domain\Pedidos\Pedido;
use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DadosPessoaisTest extends TestCase
{
    use RefreshDatabase;

    private Unidade $u1;
    private Unidade $u2;
    private Usuario $maria;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-09 12:00:00'));

        $this->u1 = Unidade::factory()->create();
        $this->u2 = Unidade::factory()->create();
        $this->maria = Usuario::factory()->create([
            'perfil' => Perfil::CLIENTE,
            'nome' => 'Maria Silva',
            'email' => 'maria.silva@exemplo.com',
            'telefone' => '(81) 99999-1234',
            'data_nascimento' => '1990-05-20',
        ]);
        Pedido::factory()->create(['unidade_id' => $this->u1->id, 'cliente_id' => $this->maria->id, 'criado_por' => $this->maria->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function equipe(Perfil $perfil, ?Unidade $u = null): Usuario
    {
        return Usuario::factory()->create(['perfil' => $perfil, 'unidade_id' => ($u ?? $this->u1)->id]);
    }

    private function ver(Usuario $quem, ?int $id = null)
    {
        return $this->comoUsuario($quem)->getJson('/clientes/'.($id ?? $this->maria->id));
    }

    // ---------- mascara e faixa etaria (RNF04) ----------

    public function test_perfil_interno_ve_dados_mascarados_e_so_a_faixa_etaria(): void
    {
        $r = $this->ver($this->equipe(Perfil::ATENDENTE))->assertOk();

        $r->assertJsonPath('data.id', $this->maria->id)
            ->assertJsonPath('data.nome', 'Maria Silva')
            ->assertJsonPath('data.email', 'm***@exemplo.com')
            ->assertJsonPath('data.telefone', '*******1234')
            ->assertJsonPath('data.faixaEtaria', '35_44');

        $texto = $r->getContent();
        $this->assertStringNotContainsString('maria.silva', $texto);
        $this->assertStringNotContainsString('99999', $texto);
        $this->assertStringNotContainsString('1990', $texto);
        $this->assertStringNotContainsString('dataNascimento', $texto);
        $this->assertStringNotContainsString('senha', $texto);
    }

    public function test_faixas_etarias(): void
    {
        $casos = ['2010-01-01' => 'ATE_17', '2005-01-01' => '18_24', '1995-01-01' => '25_34', '1985-01-01' => '35_44', '1975-01-01' => '45_54', '1950-01-01' => '55_MAIS'];

        foreach ($casos as $nascimento => $faixa) {
            $u = Usuario::factory()->make(['data_nascimento' => $nascimento]);
            $this->assertSame($faixa, $u->faixaEtaria(), $nascimento);
        }
    }

    public function test_limites_das_faixas_no_dia_do_aniversario(): void
    {
        // hoje (fixo) e 2026-11-09
        $this->assertSame('ATE_17', Usuario::factory()->make(['data_nascimento' => '2008-11-10'])->faixaEtaria());
        $this->assertSame('18_24', Usuario::factory()->make(['data_nascimento' => '2008-11-09'])->faixaEtaria());
    }

    public function test_sem_nascimento_ou_telefone_vem_nulo(): void
    {
        $sem = Usuario::factory()->create(['perfil' => Perfil::CLIENTE, 'telefone' => null, 'data_nascimento' => null]);
        Pedido::factory()->create(['unidade_id' => $this->u1->id, 'cliente_id' => $sem->id, 'criado_por' => $sem->id]);

        $this->ver($this->equipe(Perfil::GERENTE), $sem->id)->assertOk()
            ->assertJsonPath('data.telefone', null)
            ->assertJsonPath('data.faixaEtaria', null);
    }

    public function test_mascara_de_telefone_curto_e_email_sem_arroba(): void
    {
        $this->assertSame('1234', Usuario::factory()->make(['telefone' => '1234'])->telefoneMascarado());
        $this->assertSame('**5678', Usuario::factory()->make(['telefone' => '125678'])->telefoneMascarado());
        $this->assertNull(Usuario::factory()->make(['telefone' => null])->telefoneMascarado());
        $this->assertSame('a***@x.com', Usuario::factory()->make(['email' => 'ab@x.com'])->emailMascarado());
    }

    // ---------- auditoria de acesso (RNF06) ----------

    public function test_cada_leitura_gera_registro_de_acesso(): void
    {
        $atendente = $this->equipe(Perfil::ATENDENTE);

        $this->ver($atendente)->assertOk();
        $this->ver($atendente)->assertOk();

        $registros = RegistroAuditoria::where('acao', Acao::ACESSO_DADOS_PESSOAIS)->get();
        $this->assertCount(2, $registros);
        $a = $registros->first();
        $this->assertSame($atendente->id, $a->usuario_id);
        $this->assertSame('usuarios', $a->entidade);
        $this->assertSame($this->maria->id, $a->entidade_id);
        $this->assertSame(['nome', 'email', 'telefone', 'faixaEtaria'], $a->dados_depois['campos']);
    }

    public function test_registro_de_acesso_nao_guarda_o_dado_pessoal(): void
    {
        $this->ver($this->equipe(Perfil::ATENDENTE))->assertOk();

        $texto = json_encode(RegistroAuditoria::where('acao', Acao::ACESSO_DADOS_PESSOAIS)->first()->toArray());
        $this->assertStringNotContainsString('maria.silva', $texto);
        $this->assertStringNotContainsString('99999', $texto);
        $this->assertStringNotContainsString('1990', $texto);
    }

    public function test_acesso_negado_ou_inexistente_nao_gera_registro(): void
    {
        $this->ver($this->equipe(Perfil::GERENTE, $this->u2))->assertStatus(403);
        $this->ver($this->equipe(Perfil::COZINHA))->assertStatus(403);
        $this->ver($this->equipe(Perfil::ATENDENTE), 99999)->assertStatus(404);

        $this->assertSame(0, RegistroAuditoria::where('acao', Acao::ACESSO_DADOS_PESSOAIS)->count());
    }

    public function test_sem_auditoria_nao_devolve_dado(): void
    {
        $this->app->bind(\App\Infrastructure\Auditoria\AuditLogger::class, fn () => new class extends \App\Infrastructure\Auditoria\AuditLogger {
            public function registrar(Acao $acao, \Illuminate\Database\Eloquent\Model $entidade, ?array $antes = null, ?array $depois = null, ?int $usuarioId = null): RegistroAuditoria
            {
                throw new \RuntimeException('banco fora');
            }
        });

        $r = $this->ver($this->equipe(Perfil::ATENDENTE));

        $r->assertStatus(500);
        $this->assertStringNotContainsString('Maria', $r->getContent());
    }

    // ---------- escopo (RN13) ----------

    public function test_admin_ve_qualquer_cliente(): void
    {
        $this->ver(Usuario::factory()->create(['perfil' => Perfil::ADMIN, 'unidade_id' => null]))->assertOk();
    }

    public function test_gerente_e_atendente_so_veem_cliente_da_propria_unidade(): void
    {
        foreach ([Perfil::GERENTE, Perfil::ATENDENTE] as $perfil) {
            $this->ver($this->equipe($perfil))->assertOk();
            $this->ver($this->equipe($perfil, $this->u2))->assertStatus(403)->assertJsonPath('error', 'ACESSO_NEGADO');
        }
    }

    public function test_cozinha_e_cliente_nao_acessam(): void
    {
        $this->ver($this->equipe(Perfil::COZINHA))->assertStatus(403);
        $this->ver($this->maria)->assertStatus(403);
    }

    public function test_so_cliente_e_consultavel(): void
    {
        $interno = $this->equipe(Perfil::ATENDENTE);

        $this->ver(Usuario::factory()->create(['perfil' => Perfil::ADMIN, 'unidade_id' => null]), $interno->id)->assertStatus(404);
    }

    public function test_exige_login(): void
    {
        $this->getJson('/clientes/'.$this->maria->id)->assertStatus(401);
    }
}
