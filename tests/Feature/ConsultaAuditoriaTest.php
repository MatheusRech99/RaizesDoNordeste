<?php

namespace Tests\Feature;

use App\Domain\Auditoria\Acao;
use App\Domain\Auditoria\RegistroAuditoria;
use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsultaAuditoriaTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $admin;
    private Usuario $gerente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Usuario::factory()->create(['perfil' => Perfil::ADMIN]);
        $this->gerente = Usuario::factory()->create(['perfil' => Perfil::GERENTE, 'unidade_id' => Unidade::factory()->create()->id]);
    }

    private function registrar(Acao $acao, ?Usuario $quem = null, string $entidade = 'pedidos', int $id = 1, ?array $antes = null, ?array $depois = null): RegistroAuditoria
    {
        return RegistroAuditoria::create([
            'usuario_id' => ($quem ?? $this->gerente)->id,
            'acao' => $acao,
            'entidade' => $entidade,
            'entidade_id' => $id,
            'dados_antes' => $antes,
            'dados_depois' => $depois,
            'ip' => '10.0.0.1',
        ]);
    }

    private function listar(string $query = '', ?Usuario $quem = null)
    {
        return $this->comoUsuario($quem ?? $this->admin)->getJson('/auditoria'.$query);
    }

    public function test_admin_ve_a_trilha_do_mais_novo_para_o_mais_antigo(): void
    {
        $a = $this->registrar(Acao::CRIACAO_PEDIDO);
        $b = $this->registrar(Acao::CANCELAMENTO_PEDIDO, null, 'pedidos', 1, ['status' => 'PAGO'], ['status' => 'CANCELADO', 'motivo' => 'Erro']);

        $r = $this->listar()->assertOk();

        $r->assertJsonPath('data.0.id', $b->id)
            ->assertJsonPath('data.0.acao', 'CANCELAMENTO_PEDIDO')
            ->assertJsonPath('data.0.usuarioId', $this->gerente->id)
            ->assertJsonPath('data.0.entidade', 'pedidos')
            ->assertJsonPath('data.0.entidadeId', 1)
            ->assertJsonPath('data.0.dadosAntes.status', 'PAGO')
            ->assertJsonPath('data.0.dadosDepois.motivo', 'Erro')
            ->assertJsonPath('data.0.ip', '10.0.0.1')
            ->assertJsonPath('data.1.id', $a->id)
            ->assertJsonPath('meta.total', 2);
        $this->assertNotNull($r->json('data.0.em'));
    }

    public function test_filtra_por_acao_entidade_e_usuario(): void
    {
        $this->registrar(Acao::CRIACAO_PEDIDO);
        $this->registrar(Acao::AJUSTE_ESTOQUE, null, 'estoques', 7);
        $this->registrar(Acao::AJUSTE_ESTOQUE, $this->admin, 'estoques', 8);

        $this->listar('?acao=AJUSTE_ESTOQUE')->assertJsonPath('meta.total', 2);
        $this->listar('?entidade=estoques&entidadeId=7')->assertJsonPath('meta.total', 1);
        $this->listar('?usuarioId='.$this->admin->id)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.entidadeId', 8);
        $this->listar('?acao=AJUSTE_ESTOQUE&usuarioId='.$this->gerente->id)->assertJsonPath('meta.total', 1);
    }

    public function test_filtra_por_periodo_incluindo_o_dia_inteiro(): void
    {
        $this->registrar(Acao::CRIACAO_PEDIDO);
        $this->registrar(Acao::CRIACAO_PEDIDO);
        $hoje = now()->format('Y-m-d');

        $this->listar("?de={$hoje}&ate={$hoje}")->assertJsonPath('meta.total', 2);
        $this->listar('?de='.now()->addDay()->format('Y-m-d'))->assertJsonPath('meta.total', 0);
        $this->listar('?ate='.now()->subDay()->format('Y-m-d'))->assertJsonPath('meta.total', 0);
    }

    public function test_pagina(): void
    {
        foreach (range(1, 5) as $i) {
            $this->registrar(Acao::CRIACAO_PEDIDO, null, 'pedidos', $i);
        }

        $r = $this->listar('?limit=2&page=3')->assertOk();

        $r->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 3)->assertJsonPath('data.0.entidadeId', 1);
    }

    public function test_filtros_invalidos_dao_422(): void
    {
        $this->listar('?acao=INVENTADA')->assertStatus(422)->assertJsonPath('details.0.campo', 'acao');
        $this->listar('?de=09/11/2026')->assertStatus(422)->assertJsonPath('details.0.campo', 'de');
        $this->listar('?de=2026-11-10&ate=2026-11-01')->assertStatus(422)->assertJsonPath('details.0.campo', 'ate');
        $this->listar('?usuarioId=abc')->assertStatus(422)->assertJsonPath('details.0.campo', 'usuarioId');
        $this->listar('?limit=500')->assertStatus(422);
    }

    public function test_so_admin_consulta(): void
    {
        foreach ([Perfil::GERENTE, Perfil::ATENDENTE, Perfil::COZINHA, Perfil::CLIENTE] as $perfil) {
            $u = Usuario::factory()->create(['perfil' => $perfil, 'unidade_id' => Unidade::factory()->create()->id]);
            $this->listar('', $u)->assertStatus(403)->assertJsonPath('error', 'ACESSO_NEGADO');
        }
    }

    public function test_exige_login(): void
    {
        $this->getJson('/auditoria')->assertStatus(401);
    }

    public function test_a_trilha_nao_tem_rota_para_alterar_nem_apagar(): void
    {
        $r = $this->registrar(Acao::CRIACAO_PEDIDO);

        $this->comoUsuario($this->admin)->deleteJson('/auditoria/'.$r->id)->assertStatus(404);
        $this->comoUsuario($this->admin)->putJson('/auditoria/'.$r->id, [])->assertStatus(404);
        $this->comoUsuario($this->admin)->postJson('/auditoria', [])->assertStatus(405);
        $this->comoUsuario($this->admin)->deleteJson('/auditoria')->assertStatus(405);
    }

    public function test_leituras_da_trilha_nao_se_auditam_e_nao_vazam_dados_pessoais(): void
    {
        $this->registrar(Acao::ACESSO_DADOS_PESSOAIS, null, 'usuarios', 5, null, ['campos' => ['nome', 'email']]);

        $r = $this->listar()->assertOk();

        $this->assertSame(1, RegistroAuditoria::count());
        $this->assertStringNotContainsString('senha', $r->getContent());
    }
}
