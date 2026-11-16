<?php

namespace Tests\Feature;

use App\Domain\Auditoria\Acao;
use App\Domain\Auditoria\RegistroAuditoria;
use App\Domain\Cardapio\Produto;
use App\Domain\Estoque\Estoque;
use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// jornada de ponta a ponta so por HTTP, com os dados do seed (o mesmo roteiro da colecao Postman)
class FluxoCompletoTest extends TestCase
{
    use RefreshDatabase;

    private int $recife;

    protected function setUp(): void
    {
        parent::setUp();

        // segunda-feira ao meio-dia: as 3 unidades do seed estao abertas
        Carbon::setTestNow(Carbon::parse('2026-11-09 12:00:00'));
        $this->seed();

        // no MariaDB o auto-increment nao volta com o rollback dos outros testes, entao nao da pra fixar os ids
        $this->recife = Unidade::where('cidade', 'Recife')->value('id');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function produto(string $nome): int
    {
        return Produto::where('nome', 'like', $nome.'%')->value('id');
    }

    private function saldo(int $produtoId): int
    {
        return Estoque::where('unidade_id', $this->recife)->where('produto_id', $produtoId)->value('quantidade');
    }

    private function token(string $email, string $senha = 'senha123'): string
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();

        $r = $this->postJson('/auth/login', ['email' => $email, 'senha' => $senha])->assertOk();

        return $r->json('accessToken');
    }

    public function test_do_cadastro_do_cliente_a_entrega_com_pontos_relatorio_e_auditoria(): void
    {
        // 1. cadastro com fidelidade e login
        $this->postJson('/clientes', [
            'nome' => 'Joana Teste', 'email' => 'joana@exemplo.com', 'senha' => 'senha1234',
            'dataNascimento' => '1992-03-10', 'aceiteTermos' => true, 'consentimentoFidelidade' => true,
        ])->assertCreated();
        $cliente = $this->token('joana@exemplo.com', 'senha1234');

        // 2. cardapio publico da unidade 1
        $tapioca = collect($this->getJson("/unidades/{$this->recife}/cardapio?limit=100")->assertOk()->json('data'))->firstWhere('nome', 'Tapioca de queijo coalho');
        // o preco do seed varia com o id da unidade, entao tudo sai desse valor
        $total = round($tapioca['preco'] * 3, 2);
        $pontos = (int) floor($total);

        // 3. pedido de 3 tapiocas com chave de idempotencia
        $pedido = $this->comToken($cliente)->postJson('/pedidos', [
            'canalPedido' => 'TOTEM', 'unidadeId' => $this->recife, 'itens' => [['produtoId' => $tapioca['produtoId'], 'quantidade' => 3]],
        ], ['Idempotency-Key' => 'fluxo-1'])->assertCreated()->assertJsonPath('data.status', 'AGUARDANDO_PAGAMENTO')->json('data');
        $this->assertEquals($total, $pedido['total']);
        $this->assertSame(47, $this->saldo($tapioca['produtoId']));

        // 4. pagamento aprovado e pontos
        $this->comToken($cliente)->postJson('/pagamentos', ['pedidoId' => $pedido['id'], 'simularResultado' => 'APROVADO'])
            ->assertOk()->assertJsonPath('data.pedidoStatus', 'PAGO');
        $this->comToken($cliente)->getJson('/clientes/me/fidelidade/saldo')->assertOk()->assertJsonPath('data.saldo', $pontos);

        // 5. cozinha e atendente levam o pedido ate a entrega
        $cozinha = $this->token('cozinha1@raizes.com');
        $atendente = $this->token('atendente1@raizes.com');
        $this->comToken($cozinha)->patchJson("/pedidos/{$pedido['id']}/status", ['status' => 'EM_PREPARO'])->assertOk();
        $this->comToken($cozinha)->patchJson("/pedidos/{$pedido['id']}/status", ['status' => 'PRONTO'])->assertOk();
        $this->comToken($atendente)->patchJson("/pedidos/{$pedido['id']}/status", ['status' => 'ENTREGUE'])->assertOk();

        $historico = $this->comToken($cliente)->getJson("/pedidos/{$pedido['id']}")->assertOk()->assertJsonPath('data.status', 'ENTREGUE')->json('data.historico');
        $this->assertSame(['AGUARDANDO_PAGAMENTO', 'PAGO', 'EM_PREPARO', 'PRONTO', 'ENTREGUE'], array_column($historico, 'statusNovo'));

        // 6. a equipe consulta o cliente (dados mascarados, com auditoria de acesso)
        $joana = $this->comToken($atendente)->getJson('/clientes/'.Usuario::where('email', 'joana@exemplo.com')->value('id'))->assertOk();
        $this->assertSame('j***@exemplo.com', $joana->json('data.email'));
        $this->assertSame('25_34', $joana->json('data.faixaEtaria'));

        // 7. matriz: relatorios e trilha de auditoria
        $admin = $this->token('admin@raizes.com');
        $vendas = $this->comToken($admin)->getJson('/relatorios/vendas?de=2026-11-09&ate=2026-11-09')
            ->assertOk()->assertJsonPath('totais.pedidos', 1)->assertJsonPath('data.0.unidadeId', $this->recife);
        $this->assertEquals($total, $vendas->json('totais.faturamento'));
        $this->comToken($admin)->getJson('/relatorios/produtos-mais-vendidos?de=2026-11-09&ate=2026-11-09')
            ->assertOk()->assertJsonPath('data.0.nome', 'Tapioca de queijo coalho')->assertJsonPath('data.0.quantidade', 3);

        $acoes = RegistroAuditoria::pluck('acao')->map->value->all();
        foreach (['CADASTRO_CLIENTE', 'CRIACAO_PEDIDO', 'PAGAMENTO_PEDIDO', 'ATUALIZACAO_STATUS_PEDIDO', 'ACESSO_DADOS_PESSOAIS'] as $esperada) {
            $this->assertContains($esperada, $acoes);
        }
        $this->comToken($admin)->getJson('/auditoria?acao='.Acao::ACESSO_DADOS_PESSOAIS->value)->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_pedido_cancelado_depois_de_pago_devolve_tudo(): void
    {
        $cliente = $this->token('cliente@raizes.com');
        $atendente = $this->token('atendente1@raizes.com');

        $pedido = $this->comToken($cliente)->postJson('/pedidos', [
            'canalPedido' => 'APP', 'unidadeId' => $this->recife, 'itens' => [['produtoId' => $this->produto('Tapioca'), 'quantidade' => 2]],
        ])->assertCreated()->json('data');
        $this->comToken($cliente)->postJson('/pagamentos', ['pedidoId' => $pedido['id']])->assertOk();
        $saldoPago = $this->comToken($cliente)->getJson('/clientes/me/fidelidade/saldo')->json('data.saldo');
        $this->assertGreaterThan(0, $saldoPago);

        $this->comToken($atendente)->postJson("/pedidos/{$pedido['id']}/cancelamento", ['motivo' => 'Erro no pedido'])->assertOk();

        $this->assertSame(50, $this->saldo($this->produto('Tapioca')));
        $this->comToken($cliente)->getJson('/clientes/me/fidelidade/saldo')->assertJsonPath('data.saldo', 0);
        $this->assertSame(1, RegistroAuditoria::where('acao', Acao::CANCELAMENTO_PEDIDO)->count());
    }

    public function test_pagamento_recusado_e_estoque_insuficiente_pelo_seed(): void
    {
        $cliente = $this->token('cliente@raizes.com');

        // carne de sol tem so 5 no seed
        $carne = $this->produto('Carne de sol');
        $this->comToken($cliente)->postJson('/pedidos', [
            'canalPedido' => 'WEB', 'unidadeId' => $this->recife, 'itens' => [['produtoId' => $carne, 'quantidade' => 6]],
        ])->assertStatus(409)->assertJsonPath('error', 'ESTOQUE_INSUFICIENTE')->assertJsonPath('details.0.disponivel', 5);

        $pedido = $this->comToken($cliente)->postJson('/pedidos', [
            'canalPedido' => 'WEB', 'unidadeId' => $this->recife, 'itens' => [['produtoId' => $carne, 'quantidade' => 5]],
        ])->assertCreated()->json('data');
        $this->assertSame(0, $this->saldo($carne));

        $this->comToken($cliente)->postJson('/pagamentos', ['pedidoId' => $pedido['id'], 'simularResultado' => 'RECUSADO'])
            ->assertStatus(402)->assertJsonPath('error', 'PAGAMENTO_RECUSADO');

        $this->assertSame(5, $this->saldo($carne));
        $this->comToken($cliente)->getJson("/pedidos/{$pedido['id']}")->assertJsonPath('data.status', 'CANCELADO');
    }
}
