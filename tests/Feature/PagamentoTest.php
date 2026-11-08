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

class PagamentoTest extends TestCase
{
    use RefreshDatabase;

    private Unidade $unidade;
    private Usuario $cliente;
    private Usuario $atendente;
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
        $this->atendente = Usuario::factory()->create(['perfil' => Perfil::ATENDENTE, 'unidade_id' => $this->unidade->id]);

        $this->tapioca = Produto::factory()->create(['nome' => 'Tapioca']);
        ItemCardapio::factory()->create(['unidade_id' => $this->unidade->id, 'produto_id' => $this->tapioca->id, 'preco' => '12.50']);
        app(MovimentarEstoque::class)->executar($this->unidade, $this->tapioca->id, TipoMovimentacao::ENTRADA, 10, 'Carga', Usuario::factory()->create(['perfil' => Perfil::GERENTE, 'unidade_id' => $this->unidade->id]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // pedido de 2 tapiocas (total 25.00) criado pelo fluxo real, com o estoque baixado
    private function pedido(?Usuario $quem = null): int
    {
        return $this->comoUsuario($quem ?? $this->cliente)->postJson('/pedidos', [
            'canalPedido' => 'APP',
            'unidadeId' => $this->unidade->id,
            'itens' => [['produtoId' => $this->tapioca->id, 'quantidade' => 2]],
        ])->assertCreated()->json('data.id');
    }

    private function pagar(int $pedidoId, array $extra = [], ?Usuario $quem = null, array $headers = [])
    {
        return $this->comoUsuario($quem ?? $this->cliente)->postJson('/pagamentos', ['pedidoId' => $pedidoId] + $extra, $headers);
    }

    private function saldo(): int
    {
        return Estoque::where('unidade_id', $this->unidade->id)->where('produto_id', $this->tapioca->id)->value('quantidade');
    }

    // ---------- aprovado ----------

    public function test_pagamento_aprovado_marca_pedido_como_pago(): void
    {
        $id = $this->pedido();

        $this->pagar($id)
            ->assertOk()
            ->assertJsonPath('data.status', 'APROVADO')
            ->assertJsonPath('data.pedidoId', $id)
            ->assertJsonPath('data.valor', 25)
            ->assertJsonPath('data.metodo', 'MOCK')
            ->assertJsonPath('data.pedidoStatus', 'PAGO');

        $pag = Pagamento::first();
        $this->assertSame(StatusPagamento::APROVADO, $pag->status);
        $this->assertStringStartsWith('MOCK-', $pag->transacao_externa_id);
        $this->assertNotNull($pag->processado_em);
        $this->assertSame(StatusPedido::PAGO, Pedido::find($id)->status);
        $this->assertSame(8, $this->saldo());
    }

    public function test_aprovado_grava_historico_e_auditoria(): void
    {
        $id = $this->pedido();
        $this->pagar($id, ['simularResultado' => 'APROVADO'])->assertOk();

        $h = Pedido::find($id)->historico->last();
        $this->assertSame(StatusPedido::AGUARDANDO_PAGAMENTO, $h->status_anterior);
        $this->assertSame(StatusPedido::PAGO, $h->status_novo);
        $this->assertSame($this->cliente->id, $h->usuario_id);

        $a = RegistroAuditoria::where('acao', Acao::PAGAMENTO_PEDIDO)->first();
        $this->assertSame('APROVADO', $a->dados_depois['status']);
        $this->assertSame($id, $a->dados_depois['pedidoId']);
        $this->assertSame(0, RegistroAuditoria::where('acao', Acao::CANCELAMENTO_PEDIDO)->count());
    }

    public function test_valor_igual_ao_total_e_aceito(): void
    {
        $id = $this->pedido();

        $this->pagar($id, ['valor' => 25])->assertOk();
    }

    // ---------- recusado ----------

    public function test_pagamento_recusado_cancela_o_pedido_e_devolve_o_estoque(): void
    {
        $id = $this->pedido();
        $this->assertSame(8, $this->saldo());

        $this->pagar($id, ['simularResultado' => 'RECUSADO'])
            ->assertStatus(402)
            ->assertJsonPath('error', 'PAGAMENTO_RECUSADO')
            ->assertJsonPath('details.0.status', 'RECUSADO')
            ->assertJsonPath('details.0.motivo', 'Cartão recusado (simulado)');

        $pedido = Pedido::find($id);
        $this->assertSame(StatusPedido::CANCELADO, $pedido->status);
        $this->assertSame('PAGAMENTO_RECUSADO', $pedido->motivo_cancelamento);
        $this->assertSame(StatusPagamento::RECUSADO, Pagamento::first()->status);
        $this->assertSame(10, $this->saldo());

        $estorno = MovimentacaoEstoque::where('pedido_id', $id)->where('tipo', TipoMovimentacao::ESTORNO)->first();
        $this->assertSame(2, $estorno->quantidade);
        $this->assertSame(10, $estorno->saldo_apos);
    }

    public function test_recusado_grava_historico_e_auditoria_de_cancelamento(): void
    {
        $id = $this->pedido();
        $this->pagar($id, ['simularResultado' => 'RECUSADO'])->assertStatus(402);

        $h = Pedido::find($id)->historico->last();
        $this->assertSame(StatusPedido::CANCELADO, $h->status_novo);

        $c = RegistroAuditoria::where('acao', Acao::CANCELAMENTO_PEDIDO)->first();
        $this->assertSame($id, $c->entidade_id);
        $this->assertSame('PAGAMENTO_RECUSADO', $c->dados_depois['motivo']);
        $this->assertSame('RECUSADO', RegistroAuditoria::where('acao', Acao::PAGAMENTO_PEDIDO)->first()->dados_depois['status']);
    }

    public function test_pedido_cancelado_nao_pode_ser_pago_de_novo(): void
    {
        $id = $this->pedido();
        $this->pagar($id, ['simularResultado' => 'RECUSADO'])->assertStatus(402);

        $this->pagar($id)->assertStatus(409)->assertJsonPath('error', 'PEDIDO_NAO_PAGAVEL');
        $this->assertSame(1, Pagamento::count());
    }

    // ---------- erro do gateway ----------

    public function test_erro_no_gateway_mantem_o_pedido_aguardando_e_permite_nova_tentativa(): void
    {
        $id = $this->pedido();

        $this->pagar($id, ['simularResultado' => 'ERRO'])
            ->assertStatus(503)
            ->assertJsonPath('error', 'GATEWAY_INDISPONIVEL')
            ->assertJsonPath('details.0.status', 'ERRO');

        $erro = Pagamento::first();
        $this->assertSame(StatusPagamento::ERRO, $erro->status);
        $this->assertNull($erro->transacao_externa_id);
        $this->assertSame(StatusPedido::AGUARDANDO_PAGAMENTO, Pedido::find($id)->status);
        $this->assertSame(8, $this->saldo());

        // segunda tentativa aprova; as duas ficam registradas
        $this->pagar($id)->assertOk()->assertJsonPath('data.pedidoStatus', 'PAGO');
        $this->assertSame([StatusPagamento::ERRO, StatusPagamento::APROVADO], Pagamento::orderBy('id')->get()->pluck('status')->all());
    }

    public function test_varios_erros_seguidos_nao_quebram_o_unique_da_transacao(): void
    {
        $id = $this->pedido();

        $this->pagar($id, ['simularResultado' => 'ERRO'])->assertStatus(503);
        $this->pagar($id, ['simularResultado' => 'ERRO'])->assertStatus(503);

        $this->assertSame(2, Pagamento::where('status', StatusPagamento::ERRO)->count());
    }

    // ---------- RN08 ----------

    public function test_pedido_ja_pago_nao_e_pago_de_novo(): void
    {
        $id = $this->pedido();
        $this->pagar($id)->assertOk();

        $this->pagar($id)->assertStatus(409)->assertJsonPath('error', 'PEDIDO_NAO_PAGAVEL')->assertJsonPath('details.0.status', 'PAGO');
        $this->assertSame(1, Pagamento::count());
    }

    public function test_valor_diferente_do_total_da_409_e_nao_cobra(): void
    {
        $id = $this->pedido();

        $this->pagar($id, ['valor' => 24.99])->assertStatus(409)->assertJsonPath('error', 'PEDIDO_NAO_PAGAVEL');
        $this->assertSame(0, Pagamento::count());
        $this->assertSame(StatusPedido::AGUARDANDO_PAGAMENTO, Pedido::find($id)->status);
    }

    // ---------- idempotencia (RN07) ----------

    public function test_mesma_chave_devolve_o_mesmo_resultado_sem_cobrar_duas_vezes(): void
    {
        $id = $this->pedido();
        $h = ['Idempotency-Key' => 'pag-1'];

        $a = $this->pagar($id, [], null, $h)->assertOk();
        $b = $this->pagar($id, [], null, $h)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($a->json('data.transacaoExternaId'), $b->json('data.transacaoExternaId'));
        $this->assertSame(1, Pagamento::count());
    }

    public function test_chave_repetida_com_corpo_diferente_da_409(): void
    {
        $id = $this->pedido();
        $h = ['Idempotency-Key' => 'pag-2'];

        $this->pagar($id, [], null, $h)->assertOk();
        $this->pagar($id, ['valor' => 25], null, $h)->assertStatus(409)->assertJsonPath('error', 'CHAVE_IDEMPOTENCIA_CONFLITANTE');
    }

    public function test_recusa_repetida_com_a_mesma_chave_devolve_o_402_original(): void
    {
        $id = $this->pedido();
        $h = ['Idempotency-Key' => 'pag-3'];

        $this->pagar($id, ['simularResultado' => 'RECUSADO'], null, $h)->assertStatus(402);
        $this->pagar($id, ['simularResultado' => 'RECUSADO'], null, $h)->assertStatus(402)->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(1, Pagamento::count());
        $this->assertSame(10, $this->saldo());
    }

    public function test_erro_libera_a_chave_para_repetir_a_mesma_requisicao(): void
    {
        $id = $this->pedido();
        $h = ['Idempotency-Key' => 'pag-4'];

        $this->pagar($id, ['simularResultado' => 'ERRO'], null, $h)->assertStatus(503);
        $this->pagar($id, ['simularResultado' => 'APROVADO'], null, $h)->assertOk();
    }

    // ---------- acesso ----------

    public function test_cliente_nao_paga_pedido_de_outro_cliente(): void
    {
        $id = $this->pedido();
        $outro = Usuario::factory()->create(['perfil' => Perfil::CLIENTE]);

        $this->pagar($id, [], $outro)->assertStatus(404)->assertJsonPath('error', 'NAO_ENCONTRADO');
        $this->assertSame(0, Pagamento::count());
    }

    public function test_atendente_de_outra_unidade_leva_403(): void
    {
        $id = $this->pedido();
        $de_fora = Usuario::factory()->create(['perfil' => Perfil::ATENDENTE, 'unidade_id' => Unidade::factory()->create()->id]);

        $this->pagar($id, [], $de_fora)->assertStatus(403);
    }

    public function test_atendente_paga_pedido_de_balcao_da_sua_unidade(): void
    {
        $id = $this->comoUsuario($this->atendente)->postJson('/pedidos', [
            'canalPedido' => 'BALCAO',
            'unidadeId' => $this->unidade->id,
            'itens' => [['produtoId' => $this->tapioca->id, 'quantidade' => 1]],
        ])->assertCreated()->json('data.id');

        $this->pagar($id, [], $this->atendente)->assertOk()->assertJsonPath('data.pedidoStatus', 'PAGO');
    }

    public function test_perfis_internos_sem_permissao_levam_403(): void
    {
        $id = $this->pedido();

        foreach ([Perfil::ADMIN, Perfil::GERENTE, Perfil::COZINHA] as $perfil) {
            $u = Usuario::factory()->create(['perfil' => $perfil, 'unidade_id' => $this->unidade->id]);
            $this->pagar($id, [], $u)->assertStatus(403);
        }
    }

    public function test_exige_autenticacao(): void
    {
        $this->postJson('/pagamentos', ['pedidoId' => 1])->assertStatus(401);
    }

    // ---------- validacao ----------

    public function test_corpo_invalido_da_422(): void
    {
        $id = $this->pedido();

        $this->pagar(0)->assertStatus(422)->assertJsonPath('details.0.campo', 'pedidoId');
        $this->comoUsuario($this->cliente)->postJson('/pagamentos', [])->assertStatus(422)->assertJsonPath('details.0.campo', 'pedidoId');
        $this->pagar($id, ['simularResultado' => 'TALVEZ'])->assertStatus(422)->assertJsonPath('details.0.campo', 'simularResultado');
        $this->pagar($id, ['valor' => 'abc'])->assertStatus(422)->assertJsonPath('details.0.campo', 'valor');
        $this->assertSame(0, Pagamento::count());
    }

    public function test_pedido_inexistente_da_404(): void
    {
        $this->pagar(99999)->assertStatus(404)->assertJsonPath('error', 'NAO_ENCONTRADO');
    }

    public function test_simular_resultado_e_recusado_em_producao(): void
    {
        $id = $this->pedido();
        $this->app['env'] = 'production';

        $this->pagar($id, ['simularResultado' => 'RECUSADO'])->assertStatus(422)->assertJsonPath('details.0.campo', 'simularResultado');
        $this->assertSame(StatusPedido::AGUARDANDO_PAGAMENTO, Pedido::find($id)->status);
        $this->pagar($id)->assertOk();
    }

    // ---------- gateway mock ----------

    public function test_gateway_esta_ligado_ao_mock(): void
    {
        $this->assertInstanceOf(MockPaymentGateway::class, app(PaymentGateway::class));
    }

    public function test_mock_e_deterministico_e_estorna(): void
    {
        $mock = new MockPaymentGateway();
        $pedido = Pedido::factory()->create();

        $this->assertSame(StatusPagamento::APROVADO, $mock->cobrar($pedido, 'k')->status);
        $this->assertSame(StatusPagamento::APROVADO, $mock->cobrar($pedido, 'k', 'APROVADO')->status);
        $this->assertSame(StatusPagamento::RECUSADO, $mock->cobrar($pedido, 'k', 'RECUSADO')->status);

        $pag = Pagamento::create(['pedido_id' => $pedido->id, 'status' => StatusPagamento::APROVADO, 'valor' => '25.00', 'transacao_externa_id' => 'MOCK-1']);
        $this->assertSame(StatusPagamento::ESTORNADO, $mock->estornar($pag)->status);

        $this->expectException(FalhaNoGateway::class);
        $mock->cobrar($pedido, 'k', 'ERRO');
    }

    public function test_banco_barra_transacao_externa_repetida(): void
    {
        $pedido = Pedido::factory()->create();
        Pagamento::create(['pedido_id' => $pedido->id, 'status' => StatusPagamento::APROVADO, 'valor' => '1.00', 'transacao_externa_id' => 'MOCK-X']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        Pagamento::create(['pedido_id' => $pedido->id, 'status' => StatusPagamento::APROVADO, 'valor' => '1.00', 'transacao_externa_id' => 'MOCK-X']);
    }
}
