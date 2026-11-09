<?php

namespace Tests\Feature;

use App\Application\Estoque\MovimentarEstoque;
use App\Domain\Cardapio\ItemCardapio;
use App\Domain\Cardapio\Produto;
use App\Domain\Estoque\TipoMovimentacao;
use App\Domain\Fidelidade\PontosFidelidade;
use App\Domain\Fidelidade\TipoPontos;
use App\Domain\Pedidos\Pedido;
use App\Domain\Unidades\HorarioFuncionamento;
use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Consentimento;
use App\Domain\Usuarios\Finalidade;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FidelidadeTest extends TestCase
{
    use RefreshDatabase;

    private Unidade $unidade;
    private Usuario $cliente;
    private Usuario $atendente;
    private Produto $tapioca;
    private Produto $bala;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-09 12:00:00'));

        $this->unidade = Unidade::factory()->create();
        foreach (range(0, 6) as $dia) {
            HorarioFuncionamento::create(['unidade_id' => $this->unidade->id, 'dia_semana' => $dia, 'abre' => '08:00:00', 'fecha' => '22:00:00']);
        }
        $this->cliente = Usuario::factory()->create(['perfil' => Perfil::CLIENTE]);
        $this->consentir($this->cliente);
        $this->atendente = Usuario::factory()->create(['perfil' => Perfil::ATENDENTE, 'unidade_id' => $this->unidade->id]);

        $this->tapioca = $this->noCardapio('Tapioca', '12.50');
        $this->bala = $this->noCardapio('Bala', '0.50');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function consentir(Usuario $u, bool $aceito = true): void
    {
        Consentimento::create(['usuario_id' => $u->id, 'finalidade' => Finalidade::FIDELIDADE, 'aceito' => $aceito, 'versao_termo' => '1.0', 'ip' => '127.0.0.1']);
    }

    private function noCardapio(string $nome, string $preco): Produto
    {
        $p = Produto::factory()->create(['nome' => $nome]);
        ItemCardapio::factory()->create(['unidade_id' => $this->unidade->id, 'produto_id' => $p->id, 'preco' => $preco]);
        app(MovimentarEstoque::class)->executar($this->unidade, $p->id, TipoMovimentacao::ENTRADA, 50, 'Carga', Usuario::factory()->create(['perfil' => Perfil::GERENTE, 'unidade_id' => $this->unidade->id]));

        return $p;
    }

    private function pedido(Usuario $quem, Produto $produto, int $qtd, array $extra = []): int
    {
        return $this->comoUsuario($quem)->postJson('/pedidos', $extra + [
            'canalPedido' => 'APP',
            'unidadeId' => $this->unidade->id,
            'itens' => [['produtoId' => $produto->id, 'quantidade' => $qtd]],
        ])->assertCreated()->json('data.id');
    }

    private function pagar(int $id, array $extra = [], ?Usuario $quem = null)
    {
        return $this->comoUsuario($quem ?? $this->cliente)->postJson('/pagamentos', ['pedidoId' => $id] + $extra);
    }

    private function saldo(?Usuario $u = null): int
    {
        return PontosFidelidade::saldoDe(($u ?? $this->cliente)->id);
    }

    // ---------- credito ----------

    public function test_aprovacao_credita_um_ponto_por_real(): void
    {
        $id = $this->pedido($this->cliente, $this->tapioca, 2);
        $this->assertSame(0, $this->saldo());

        $this->pagar($id)->assertOk();

        $p = PontosFidelidade::first();
        $this->assertSame(TipoPontos::CREDITO, $p->tipo);
        $this->assertSame(25, $p->pontos);
        $this->assertSame($id, $p->pedido_id);
        $this->assertSame($this->cliente->id, $p->usuario_id);
        $this->assertSame(25, $this->saldo());
    }

    public function test_centavos_nao_contam(): void
    {
        $this->pagar($this->pedido($this->cliente, $this->tapioca, 3))->assertOk(); // 37.50

        $this->assertSame(37, $this->saldo());
    }

    public function test_total_abaixo_de_um_real_nao_gera_ponto(): void
    {
        $this->pagar($this->pedido($this->cliente, $this->bala, 1))->assertOk(); // 0.50

        $this->assertSame(0, PontosFidelidade::count());
    }

    public function test_sem_consentimento_de_fidelidade_nao_pontua(): void
    {
        $sem = Usuario::factory()->create(['perfil' => Perfil::CLIENTE]);
        $this->pagar($this->pedido($sem, $this->tapioca, 2), [], $sem)->assertOk();

        $this->assertSame(0, PontosFidelidade::count());
    }

    public function test_consentimento_recusado_ou_revogado_nao_pontua(): void
    {
        $recusou = Usuario::factory()->create(['perfil' => Perfil::CLIENTE]);
        $this->consentir($recusou, false);
        $this->pagar($this->pedido($recusou, $this->tapioca, 1), [], $recusou)->assertOk();

        Consentimento::where('usuario_id', $this->cliente->id)->update(['revogado_em' => now()]);
        $this->pagar($this->pedido($this->cliente, $this->tapioca, 1))->assertOk();

        $this->assertSame(0, PontosFidelidade::count());
    }

    public function test_pagamento_recusado_ou_com_erro_nao_gera_ponto(): void
    {
        $id = $this->pedido($this->cliente, $this->tapioca, 2);
        $this->pagar($id, ['simularResultado' => 'ERRO'])->assertStatus(503);
        $this->assertSame(0, $this->saldo());

        $this->pagar($id, ['simularResultado' => 'RECUSADO'])->assertStatus(402);
        $this->assertSame(0, PontosFidelidade::count());
    }

    public function test_erro_seguido_de_aprovacao_credita_so_uma_vez(): void
    {
        $id = $this->pedido($this->cliente, $this->tapioca, 2);
        $this->pagar($id, ['simularResultado' => 'ERRO'])->assertStatus(503);
        $this->pagar($id)->assertOk();

        $this->assertSame(25, $this->saldo());
        $this->assertSame(1, PontosFidelidade::count());
    }

    public function test_pedido_de_balcao_sem_cliente_nao_pontua(): void
    {
        $id = $this->pedido($this->atendente, $this->tapioca, 2, ['canalPedido' => 'BALCAO']);
        $this->pagar($id, [], $this->atendente)->assertOk();

        $this->assertSame(0, PontosFidelidade::count());
    }

    public function test_pedido_aberto_pelo_atendente_pontua_o_cliente_do_pedido(): void
    {
        $id = $this->pedido($this->atendente, $this->tapioca, 2, ['canalPedido' => 'WEB', 'clienteId' => $this->cliente->id]);
        $this->pagar($id, [], $this->atendente)->assertOk();

        $this->assertSame(25, $this->saldo());
        $this->assertSame($this->cliente->id, PontosFidelidade::first()->usuario_id);
    }

    // ---------- estorno ----------

    public function test_cancelar_pedido_pago_estorna_os_pontos(): void
    {
        $id = $this->pedido($this->cliente, $this->tapioca, 2);
        $this->pagar($id)->assertOk();

        $this->comoUsuario($this->atendente)->postJson("/pedidos/{$id}/cancelamento", ['motivo' => 'Erro no pedido'])->assertOk();

        $estorno = PontosFidelidade::where('tipo', TipoPontos::ESTORNO)->first();
        $this->assertSame(-25, $estorno->pontos);
        $this->assertSame($id, $estorno->pedido_id);
        $this->assertSame(0, $this->saldo());
    }

    public function test_estorno_acontece_mesmo_se_o_cliente_revogou_depois(): void
    {
        $id = $this->pedido($this->cliente, $this->tapioca, 2);
        $this->pagar($id)->assertOk();
        Consentimento::where('usuario_id', $this->cliente->id)->update(['revogado_em' => now()]);

        $this->comoUsuario($this->atendente)->postJson("/pedidos/{$id}/cancelamento", ['motivo' => 'Erro no pedido'])->assertOk();

        $this->assertSame(0, $this->saldo());
    }

    public function test_cancelar_pedido_sem_pontos_nao_gera_estorno(): void
    {
        $id = $this->pedido($this->cliente, $this->tapioca, 2);
        $this->comoUsuario($this->cliente)->postJson("/pedidos/{$id}/cancelamento", ['motivo' => 'Desisti'])->assertOk();

        $this->assertSame(0, PontosFidelidade::count());

        $sem = Usuario::factory()->create(['perfil' => Perfil::CLIENTE]);
        $id2 = $this->pedido($sem, $this->tapioca, 2);
        $this->pagar($id2, [], $sem)->assertOk();
        $this->comoUsuario($this->atendente)->postJson("/pedidos/{$id2}/cancelamento", ['motivo' => 'Erro no pedido'])->assertOk();

        $this->assertSame(0, PontosFidelidade::count());
    }

    public function test_cancelamento_que_falha_no_estorno_do_gateway_nao_estorna_pontos(): void
    {
        $id = $this->pedido($this->cliente, $this->tapioca, 2);
        $this->pagar($id)->assertOk();

        $this->app->bind(\App\Domain\Pagamentos\PaymentGateway::class, fn () => new class extends \App\Infrastructure\Pagamentos\MockPaymentGateway {
            public function estornar(\App\Domain\Pagamentos\Pagamento $pagamento): \App\Domain\Pagamentos\RespostaGateway
            {
                throw new \App\Domain\Pagamentos\FalhaNoGateway('fora do ar');
            }
        });

        $this->comoUsuario($this->atendente)->postJson("/pedidos/{$id}/cancelamento", ['motivo' => 'Erro no pedido'])->assertStatus(503);

        $this->assertSame(25, $this->saldo());
        $this->assertSame(1, PontosFidelidade::count());
    }

    public function test_banco_barra_credito_ou_estorno_duplicado_do_mesmo_pedido(): void
    {
        $pedido = Pedido::factory()->create();
        PontosFidelidade::create(['usuario_id' => $pedido->cliente_id, 'pedido_id' => $pedido->id, 'tipo' => TipoPontos::CREDITO, 'pontos' => 10]);

        $this->expectException(UniqueConstraintViolationException::class);
        PontosFidelidade::create(['usuario_id' => $pedido->cliente_id, 'pedido_id' => $pedido->id, 'tipo' => TipoPontos::CREDITO, 'pontos' => 10]);
    }

    // ---------- consulta ----------

    public function test_cliente_consulta_saldo_e_extrato(): void
    {
        $a = $this->pedido($this->cliente, $this->tapioca, 2);
        $this->pagar($a)->assertOk();
        $b = $this->pedido($this->cliente, $this->tapioca, 1);
        $this->pagar($b)->assertOk();
        $this->comoUsuario($this->atendente)->postJson("/pedidos/{$a}/cancelamento", ['motivo' => 'Erro no pedido'])->assertOk();

        $this->comoUsuario($this->cliente)->getJson('/clientes/me/fidelidade/saldo')->assertOk()->assertJsonPath('data.saldo', 12);

        $r = $this->comoUsuario($this->cliente)->getJson('/clientes/me/fidelidade/extrato')->assertOk();
        $r->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.tipo', 'ESTORNO')
            ->assertJsonPath('data.0.pontos', -25)
            ->assertJsonPath('data.0.pedidoId', $a)
            ->assertJsonPath('data.1.tipo', 'CREDITO')
            ->assertJsonPath('data.1.pontos', 12)
            ->assertJsonPath('data.2.pontos', 25)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_extrato_pagina(): void
    {
        $this->pagar($this->pedido($this->cliente, $this->tapioca, 2))->assertOk();
        $this->pagar($this->pedido($this->cliente, $this->tapioca, 2))->assertOk();

        $this->comoUsuario($this->cliente)->getJson('/clientes/me/fidelidade/extrato?limit=1&page=2')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 2);
        $this->comoUsuario($this->cliente)->getJson('/clientes/me/fidelidade/extrato?limit=0')->assertStatus(422);
    }

    public function test_cliente_so_ve_os_proprios_pontos(): void
    {
        $this->pagar($this->pedido($this->cliente, $this->tapioca, 2))->assertOk();
        $outro = Usuario::factory()->create(['perfil' => Perfil::CLIENTE]);

        $this->comoUsuario($outro)->getJson('/clientes/me/fidelidade/saldo')->assertOk()->assertJsonPath('data.saldo', 0);
        $this->comoUsuario($outro)->getJson('/clientes/me/fidelidade/extrato')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_so_cliente_consulta(): void
    {
        $this->comoUsuario($this->atendente)->getJson('/clientes/me/fidelidade/saldo')->assertStatus(403);
        $this->comoUsuario($this->atendente)->getJson('/clientes/me/fidelidade/extrato')->assertStatus(403);
    }

    public function test_exige_login(): void
    {
        $this->getJson('/clientes/me/fidelidade/saldo')->assertStatus(401);
    }
}
