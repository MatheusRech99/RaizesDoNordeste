<?php

namespace Tests\Feature;

use App\Application\Estoque\MovimentarEstoque;
use App\Domain\Auditoria\Acao;
use App\Domain\Auditoria\RegistroAuditoria;
use App\Domain\Cardapio\ItemCardapio;
use App\Domain\Cardapio\Produto;
use App\Domain\Estoque\Estoque;
use App\Domain\Estoque\MovimentacaoEstoque;
use App\Domain\Estoque\TipoMovimentacao;
use App\Domain\Pagamentos\FalhaNoGateway;
use App\Domain\Pagamentos\Pagamento;
use App\Domain\Pagamentos\PaymentGateway;
use App\Domain\Pagamentos\RespostaGateway;
use App\Domain\Pagamentos\StatusPagamento;
use App\Domain\Pedidos\Pedido;
use App\Domain\Pedidos\StatusPedido;
use App\Domain\Unidades\HorarioFuncionamento;
use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use App\Infrastructure\Pagamentos\MockPaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CancelamentoTest extends TestCase
{
    use RefreshDatabase;

    private Unidade $unidade;
    private Usuario $cliente;
    private Usuario $atendente;
    private Usuario $gerente;
    private Usuario $cozinha;
    private Produto $tapioca;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-09 12:00:00'));

        $this->unidade = Unidade::factory()->create();
        foreach (range(0, 6) as $dia) {
            HorarioFuncionamento::create(['unidade_id' => $this->unidade->id, 'dia_semana' => $dia, 'abre' => '08:00:00', 'fecha' => '22:00:00']);
        }
        $this->cliente = Usuario::factory()->create(['perfil' => Perfil::CLIENTE]);
        $this->atendente = $this->equipe(Perfil::ATENDENTE, $this->unidade);
        $this->gerente = $this->equipe(Perfil::GERENTE, $this->unidade);
        $this->cozinha = $this->equipe(Perfil::COZINHA, $this->unidade);

        $this->tapioca = Produto::factory()->create(['nome' => 'Tapioca']);
        ItemCardapio::factory()->create(['unidade_id' => $this->unidade->id, 'produto_id' => $this->tapioca->id, 'preco' => '12.50']);
        app(MovimentarEstoque::class)->executar($this->unidade, $this->tapioca->id, TipoMovimentacao::ENTRADA, 10, 'Carga', $this->gerente);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function equipe(Perfil $perfil, Unidade $u): Usuario
    {
        return Usuario::factory()->create(['perfil' => $perfil, 'unidade_id' => $u->id]);
    }

    // pedido de 2 tapiocas pelo fluxo real (estoque 10 -> 8), levado ate o status pedido
    private function pedidoEm(StatusPedido $status): int
    {
        $id = $this->comoUsuario($this->cliente)->postJson('/pedidos', [
            'canalPedido' => 'APP',
            'unidadeId' => $this->unidade->id,
            'itens' => [['produtoId' => $this->tapioca->id, 'quantidade' => 2]],
        ])->assertCreated()->json('data.id');

        if ($status === StatusPedido::AGUARDANDO_PAGAMENTO) {
            return $id;
        }

        $this->comoUsuario($this->cliente)->postJson('/pagamentos', ['pedidoId' => $id])->assertOk();

        if ($status === StatusPedido::PAGO) {
            return $id;
        }

        $passos = [[StatusPedido::EM_PREPARO, $this->cozinha], [StatusPedido::PRONTO, $this->cozinha], [StatusPedido::ENTREGUE, $this->atendente]];
        foreach ($passos as [$passo, $quem]) {
            $this->comoUsuario($quem)->patchJson("/pedidos/{$id}/status", ['status' => $passo->value])->assertOk();
            if ($passo === $status) {
                break;
            }
        }

        return $id;
    }

    private function cancelar(int $id, ?Usuario $quem = null, array $corpo = ['motivo' => 'Cliente desistiu'])
    {
        return $this->comoUsuario($quem ?? $this->cliente)->postJson("/pedidos/{$id}/cancelamento", $corpo);
    }

    private function saldo(): int
    {
        return Estoque::where('unidade_id', $this->unidade->id)->where('produto_id', $this->tapioca->id)->value('quantidade');
    }

    // ---------- cliente ----------

    public function test_cliente_cancela_pedido_aguardando_pagamento_e_o_estoque_volta(): void
    {
        $id = $this->pedidoEm(StatusPedido::AGUARDANDO_PAGAMENTO);
        $this->assertSame(8, $this->saldo());

        $this->cancelar($id)
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELADO')
            ->assertJsonPath('data.motivoCancelamento', 'Cliente desistiu')
            ->assertJsonPath('data.historico.1.statusAnterior', 'AGUARDANDO_PAGAMENTO')
            ->assertJsonPath('data.historico.1.statusNovo', 'CANCELADO');

        $this->assertSame(10, $this->saldo());
        $estorno = MovimentacaoEstoque::where('pedido_id', $id)->where('tipo', TipoMovimentacao::ESTORNO)->first();
        $this->assertSame(2, $estorno->quantidade);
        $this->assertSame(10, $estorno->saldo_apos);
        $this->assertSame(0, Pagamento::count());
    }

    public function test_cancelamento_grava_auditoria(): void
    {
        $id = $this->pedidoEm(StatusPedido::AGUARDANDO_PAGAMENTO);
        $this->cancelar($id)->assertOk();

        $a = RegistroAuditoria::where('acao', Acao::CANCELAMENTO_PEDIDO)->first();
        $this->assertSame($this->cliente->id, $a->usuario_id);
        $this->assertSame($id, $a->entidade_id);
        $this->assertSame('AGUARDANDO_PAGAMENTO', $a->dados_antes['status']);
        $this->assertSame('Cliente desistiu', $a->dados_depois['motivo']);
        $this->assertFalse($a->dados_depois['pagamentoEstornado']);
    }

    public function test_cliente_nao_cancela_pedido_ja_pago(): void
    {
        $id = $this->pedidoEm(StatusPedido::PAGO);

        $this->cancelar($id)->assertStatus(403)->assertJsonPath('error', 'ACESSO_NEGADO');

        $this->assertSame(StatusPedido::PAGO, Pedido::find($id)->status);
        $this->assertSame(8, $this->saldo());
    }

    public function test_cliente_de_outro_pedido_leva_404(): void
    {
        $id = $this->pedidoEm(StatusPedido::AGUARDANDO_PAGAMENTO);
        $outro = Usuario::factory()->create(['perfil' => Perfil::CLIENTE]);

        $this->cancelar($id, $outro)->assertStatus(404)->assertJsonPath('error', 'NAO_ENCONTRADO');
        $this->assertSame(StatusPedido::AGUARDANDO_PAGAMENTO, Pedido::find($id)->status);
    }

    // ---------- pedido pago: estorno ----------

    public function test_atendente_cancela_pedido_pago_com_estorno_e_devolucao_de_estoque(): void
    {
        $id = $this->pedidoEm(StatusPedido::PAGO);

        $this->cancelar($id, $this->atendente, ['motivo' => 'Erro no pedido'])->assertOk()->assertJsonPath('data.status', 'CANCELADO');

        $pag = Pagamento::first();
        $this->assertSame(StatusPagamento::ESTORNADO, $pag->status);
        $this->assertStringStartsWith('MOCK-', $pag->transacao_externa_id);
        $this->assertStringStartsWith('MOCK-ESTORNO-', $pag->resposta_gateway['estorno']['transacao']);
        $this->assertSame(10, $this->saldo());

        $a = RegistroAuditoria::where('acao', Acao::CANCELAMENTO_PEDIDO)->first();
        $this->assertSame($this->atendente->id, $a->usuario_id);
        $this->assertTrue($a->dados_depois['pagamentoEstornado']);
        $this->assertSame('ESTORNADO', RegistroAuditoria::where('acao', Acao::PAGAMENTO_PEDIDO)->latest('id')->first()->dados_depois['status']);
    }

    public function test_atendente_nao_cancela_pedido_em_preparo(): void
    {
        $id = $this->pedidoEm(StatusPedido::EM_PREPARO);

        $this->cancelar($id, $this->atendente)->assertStatus(403);
        $this->assertSame(StatusPedido::EM_PREPARO, Pedido::find($id)->status);
    }

    public function test_gerente_cancela_ate_em_preparo(): void
    {
        $id = $this->pedidoEm(StatusPedido::EM_PREPARO);

        $this->cancelar($id, $this->gerente, ['motivo' => 'Falta de ingrediente'])->assertOk();

        $this->assertSame(StatusPagamento::ESTORNADO, Pagamento::first()->status);
        $this->assertSame(10, $this->saldo());
    }

    // ---------- estados finais (RN06) ----------

    public function test_pronto_entregue_e_cancelado_nao_cancelam(): void
    {
        foreach ([StatusPedido::PRONTO, StatusPedido::ENTREGUE] as $status) {
            $id = $this->pedidoEm($status);
            $saldo = $this->saldo();

            $this->cancelar($id, $this->gerente)->assertStatus(409)->assertJsonPath('error', 'TRANSICAO_INVALIDA');
            $this->assertSame($saldo, $this->saldo());
            $this->assertSame($status, Pedido::find($id)->status);
        }
    }

    public function test_cancelar_duas_vezes_da_409_e_nao_devolve_estoque_de_novo(): void
    {
        $id = $this->pedidoEm(StatusPedido::AGUARDANDO_PAGAMENTO);
        $this->cancelar($id)->assertOk();

        $this->cancelar($id)->assertStatus(409)->assertJsonPath('error', 'TRANSICAO_INVALIDA');
        $this->assertSame(10, $this->saldo());
        $this->assertSame(1, MovimentacaoEstoque::where('pedido_id', $id)->where('tipo', TipoMovimentacao::ESTORNO)->count());
    }

    // ---------- falha no estorno ----------

    public function test_falha_no_estorno_desfaz_o_cancelamento(): void
    {
        $id = $this->pedidoEm(StatusPedido::PAGO);

        $this->app->bind(PaymentGateway::class, fn () => new class extends MockPaymentGateway {
            public function estornar(Pagamento $pagamento): RespostaGateway
            {
                throw new FalhaNoGateway('fora do ar');
            }
        });

        $this->cancelar($id, $this->atendente)->assertStatus(503)->assertJsonPath('error', 'GATEWAY_INDISPONIVEL');

        $this->assertSame(StatusPedido::PAGO, Pedido::find($id)->status);
        $this->assertNull(Pedido::find($id)->motivo_cancelamento);
        $this->assertSame(StatusPagamento::APROVADO, Pagamento::first()->status);
        $this->assertSame(8, $this->saldo());
        $this->assertSame(0, RegistroAuditoria::where('acao', Acao::CANCELAMENTO_PEDIDO)->count());
    }

    // ---------- acesso e validacao ----------

    public function test_equipe_de_outra_unidade_leva_403(): void
    {
        $id = $this->pedidoEm(StatusPedido::AGUARDANDO_PAGAMENTO);
        $outra = Unidade::factory()->create();

        foreach ([Perfil::ATENDENTE, Perfil::GERENTE] as $perfil) {
            $this->cancelar($id, $this->equipe($perfil, $outra))->assertStatus(403);
        }
        $this->assertSame(8, $this->saldo());
    }

    public function test_cozinha_e_admin_nao_cancelam(): void
    {
        $id = $this->pedidoEm(StatusPedido::AGUARDANDO_PAGAMENTO);

        $this->cancelar($id, $this->cozinha)->assertStatus(403);
        $this->cancelar($id, Usuario::factory()->create(['perfil' => Perfil::ADMIN]))->assertStatus(403);
        $this->assertSame(StatusPedido::AGUARDANDO_PAGAMENTO, Pedido::find($id)->status);
    }

    public function test_motivo_e_obrigatorio(): void
    {
        $id = $this->pedidoEm(StatusPedido::AGUARDANDO_PAGAMENTO);

        $this->cancelar($id, null, [])->assertStatus(422)->assertJsonPath('details.0.campo', 'motivo');
        $this->cancelar($id, null, ['motivo' => 'ab'])->assertStatus(422);
        $this->cancelar($id, null, ['motivo' => str_repeat('x', 256)])->assertStatus(422);
        $this->assertSame(8, $this->saldo());
    }

    public function test_pedido_inexistente_da_404(): void
    {
        $this->cancelar(99999)->assertStatus(404);
    }

    public function test_exige_login(): void
    {
        $this->postJson('/pedidos/1/cancelamento', ['motivo' => 'teste'])->assertStatus(401);
    }
}
