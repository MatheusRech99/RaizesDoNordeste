<?php

namespace Tests\Feature;

use App\Domain\Auditoria\Acao;
use App\Domain\Auditoria\RegistroAuditoria;
use App\Domain\Pedidos\HistoricoStatus;
use App\Domain\Pedidos\Pedido;
use App\Domain\Pedidos\StatusPedido;
use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusPedidoTest extends TestCase
{
    use RefreshDatabase;

    private Unidade $unidade;
    private Usuario $cozinha;
    private Usuario $atendente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unidade = Unidade::factory()->create();
        $this->cozinha = Usuario::factory()->create(['perfil' => Perfil::COZINHA, 'unidade_id' => $this->unidade->id]);
        $this->atendente = Usuario::factory()->create(['perfil' => Perfil::ATENDENTE, 'unidade_id' => $this->unidade->id]);
    }

    private function pedido(StatusPedido $status, ?Unidade $unidade = null): Pedido
    {
        return Pedido::factory()->create(['status' => $status, 'unidade_id' => ($unidade ?? $this->unidade)->id]);
    }

    private function mudar(Usuario $quem, Pedido $pedido, string $status)
    {
        return $this->comoUsuario($quem)->patchJson("/pedidos/{$pedido->id}/status", ['status' => $status]);
    }

    // ---------- a maquina em si ----------

    public function test_so_as_setas_do_diagrama_sao_permitidas(): void
    {
        $permitidas = [
            'AGUARDANDO_PAGAMENTO' => ['PAGO', 'CANCELADO'],
            'PAGO' => ['EM_PREPARO', 'CANCELADO'],
            'EM_PREPARO' => ['PRONTO', 'CANCELADO'],
            'PRONTO' => ['ENTREGUE'],
            'ENTREGUE' => [],
            'CANCELADO' => [],
        ];

        foreach (StatusPedido::cases() as $de) {
            foreach (StatusPedido::cases() as $para) {
                $this->assertSame(
                    in_array($para->value, $permitidas[$de->value]),
                    $de->podeIrPara($para),
                    "{$de->value} -> {$para->value}",
                );
            }
        }
    }

    // ---------- fluxo feliz ----------

    public function test_cozinha_e_atendente_levam_o_pedido_ate_entregue(): void
    {
        $p = $this->pedido(StatusPedido::PAGO);

        $this->mudar($this->cozinha, $p, 'EM_PREPARO')->assertOk()->assertJsonPath('data.status', 'EM_PREPARO');
        $this->mudar($this->cozinha, $p, 'PRONTO')->assertOk()->assertJsonPath('data.status', 'PRONTO');
        $r = $this->mudar($this->atendente, $p, 'ENTREGUE')->assertOk()->assertJsonPath('data.status', 'ENTREGUE');

        $r->assertJsonCount(3, 'data.historico')
            ->assertJsonPath('data.historico.0.statusAnterior', 'PAGO')
            ->assertJsonPath('data.historico.2.statusNovo', 'ENTREGUE');
        $this->assertSame(StatusPedido::ENTREGUE, $p->fresh()->status);
    }

    public function test_grava_historico_e_auditoria_de_cada_mudanca(): void
    {
        $p = $this->pedido(StatusPedido::PAGO);

        $this->mudar($this->cozinha, $p, 'EM_PREPARO')->assertOk();

        $h = HistoricoStatus::where('pedido_id', $p->id)->first();
        $this->assertSame(StatusPedido::PAGO, $h->status_anterior);
        $this->assertSame(StatusPedido::EM_PREPARO, $h->status_novo);
        $this->assertSame($this->cozinha->id, $h->usuario_id);

        $a = RegistroAuditoria::where('acao', Acao::ATUALIZACAO_STATUS_PEDIDO)->first();
        $this->assertSame($this->cozinha->id, $a->usuario_id);
        $this->assertSame($p->id, $a->entidade_id);
        $this->assertSame('PAGO', $a->dados_antes['status']);
        $this->assertSame('EM_PREPARO', $a->dados_depois['status']);
    }

    // ---------- transicao invalida (RN06) ----------

    public function test_pular_etapa_da_409_e_nao_grava_nada(): void
    {
        $p = $this->pedido(StatusPedido::PAGO);

        $this->mudar($this->cozinha, $p, 'PRONTO')
            ->assertStatus(409)
            ->assertJsonPath('error', 'TRANSICAO_INVALIDA')
            ->assertJsonPath('details.0.statusAtual', 'PAGO')
            ->assertJsonPath('details.0.statusSolicitado', 'PRONTO');

        $this->assertSame(StatusPedido::PAGO, $p->fresh()->status);
        $this->assertSame(0, HistoricoStatus::count());
        $this->assertSame(0, RegistroAuditoria::count());
    }

    public function test_pedido_sem_pagamento_nao_vai_para_o_preparo(): void
    {
        $p = $this->pedido(StatusPedido::AGUARDANDO_PAGAMENTO);

        $this->mudar($this->cozinha, $p, 'EM_PREPARO')->assertStatus(409)->assertJsonPath('error', 'TRANSICAO_INVALIDA');
    }

    public function test_voltar_ou_repetir_status_da_409(): void
    {
        $p = $this->pedido(StatusPedido::EM_PREPARO);

        $this->mudar($this->cozinha, $p, 'EM_PREPARO')->assertStatus(409);
        $p->update(['status' => StatusPedido::PRONTO]);
        $this->mudar($this->cozinha, $p, 'EM_PREPARO')->assertStatus(409);
    }

    public function test_pedido_entregue_ou_cancelado_e_final(): void
    {
        foreach ([StatusPedido::ENTREGUE, StatusPedido::CANCELADO] as $final) {
            $p = $this->pedido($final);

            $this->mudar($this->atendente, $p, 'ENTREGUE')->assertStatus(409)->assertJsonPath('error', 'TRANSICAO_INVALIDA');
            $this->mudar($this->cozinha, $p, 'PRONTO')->assertStatus(409);
        }
    }

    // ---------- permissao por perfil ----------

    public function test_atendente_nao_mexe_no_preparo_e_cozinha_nao_entrega(): void
    {
        $paga = $this->pedido(StatusPedido::PAGO);
        $pronta = $this->pedido(StatusPedido::PRONTO);

        $this->mudar($this->atendente, $paga, 'EM_PREPARO')->assertStatus(403)->assertJsonPath('error', 'ACESSO_NEGADO');
        $this->mudar($this->cozinha, $pronta, 'ENTREGUE')->assertStatus(403)->assertJsonPath('error', 'ACESSO_NEGADO');
        $this->assertSame(StatusPedido::PAGO, $paga->fresh()->status);
    }

    public function test_perfil_sem_permissao_na_rota_leva_403(): void
    {
        $p = $this->pedido(StatusPedido::PAGO);

        foreach ([Perfil::CLIENTE, Perfil::GERENTE, Perfil::ADMIN] as $perfil) {
            $u = Usuario::factory()->create(['perfil' => $perfil, 'unidade_id' => $perfil === Perfil::GERENTE ? $this->unidade->id : null]);
            $this->mudar($u, $p, 'EM_PREPARO')->assertStatus(403)->assertJsonPath('error', 'ACESSO_NEGADO');
        }
    }

    public function test_equipe_de_outra_unidade_leva_403(): void
    {
        $outra = Unidade::factory()->create();
        $p = $this->pedido(StatusPedido::PAGO, $outra);

        $this->mudar($this->cozinha, $p, 'EM_PREPARO')->assertStatus(403);
        $this->assertSame(StatusPedido::PAGO, $p->fresh()->status);
    }

    public function test_pago_e_cancelado_nao_passam_por_esta_rota(): void
    {
        $p = $this->pedido(StatusPedido::AGUARDANDO_PAGAMENTO);

        foreach (['PAGO', 'CANCELADO', 'AGUARDANDO_PAGAMENTO', 'SUMIU'] as $status) {
            $this->mudar($this->atendente, $p, $status)->assertStatus(422)->assertJsonPath('details.0.campo', 'status');
        }
    }

    // ---------- borda ----------

    public function test_status_ausente_da_422(): void
    {
        $p = $this->pedido(StatusPedido::PAGO);

        $this->comoUsuario($this->cozinha)->patchJson("/pedidos/{$p->id}/status", [])
            ->assertStatus(422)->assertJsonPath('details.0.campo', 'status');
    }

    public function test_pedido_inexistente_da_404(): void
    {
        $this->comoUsuario($this->cozinha)->patchJson('/pedidos/99999/status', ['status' => 'EM_PREPARO'])
            ->assertStatus(404)->assertJsonPath('error', 'NAO_ENCONTRADO');
    }

    public function test_exige_autenticacao(): void
    {
        $this->patchJson('/pedidos/1/status', ['status' => 'EM_PREPARO'])->assertStatus(401);
    }
}
