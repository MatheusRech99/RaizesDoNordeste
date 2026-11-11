<?php

namespace Tests\Feature;

use App\Domain\Cardapio\Produto;
use App\Domain\Pedidos\CanalPedido;
use App\Domain\Pedidos\ItemPedido;
use App\Domain\Pedidos\Pedido;
use App\Domain\Pedidos\StatusPedido;
use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelatoriosTest extends TestCase
{
    use RefreshDatabase;

    private Unidade $recife;
    private Unidade $olinda;
    private Unidade $sp;
    private Usuario $admin;
    private Usuario $gerente;
    private Produto $tapioca;
    private Produto $cuscuz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recife = Unidade::factory()->create(['nome' => 'Recife', 'regiao' => 'NE']);
        $this->olinda = Unidade::factory()->create(['nome' => 'Olinda', 'regiao' => 'NE']);
        $this->sp = Unidade::factory()->create(['nome' => 'Sao Paulo', 'regiao' => 'SE']);
        $this->admin = Usuario::factory()->create(['perfil' => Perfil::ADMIN]);
        $this->gerente = Usuario::factory()->create(['perfil' => Perfil::GERENTE, 'unidade_id' => $this->recife->id]);
        $this->tapioca = Produto::factory()->create(['nome' => 'Tapioca']);
        $this->cuscuz = Produto::factory()->create(['nome' => 'Cuscuz']);
    }

    // pedido com um item de cada produto informado ([produto, qtd, preco]); total = soma dos itens
    private function pedido(Unidade $u, array $itens, array $extra = []): Pedido
    {
        $total = array_sum(array_map(fn ($i) => $i[1] * $i[2], $itens));
        $pedido = Pedido::factory()->create($extra + [
            'unidade_id' => $u->id,
            'status' => StatusPedido::ENTREGUE,
            'canal_pedido' => CanalPedido::APP,
            'subtotal' => $total,
            'total' => $total,
            'created_at' => '2026-11-05 12:00:00',
        ]);
        foreach ($itens as [$produto, $qtd, $preco]) {
            ItemPedido::create(['pedido_id' => $pedido->id, 'produto_id' => $produto->id, 'quantidade' => $qtd, 'preco_unitario' => $preco, 'subtotal' => $qtd * $preco]);
        }

        return $pedido;
    }

    private function vendas(string $query = '', ?Usuario $quem = null)
    {
        return $this->comoUsuario($quem ?? $this->admin)->getJson('/relatorios/vendas?de=2026-11-01&ate=2026-11-30'.$query);
    }

    private function ranking(string $query = '', ?Usuario $quem = null)
    {
        return $this->comoUsuario($quem ?? $this->admin)->getJson('/relatorios/produtos-mais-vendidos?de=2026-11-01&ate=2026-11-30'.$query);
    }

    // ---------- vendas por unidade ----------

    public function test_vendas_por_unidade_somam_pedidos_pagos(): void
    {
        $this->pedido($this->recife, [[$this->tapioca, 2, 10]]);                                  // 20
        $this->pedido($this->recife, [[$this->tapioca, 1, 10], [$this->cuscuz, 2, 5]]);          // 20
        $this->pedido($this->sp, [[$this->cuscuz, 3, 5]], ['status' => StatusPedido::PAGO]);     // 15

        $r = $this->vendas()->assertOk();

        $r->assertJsonPath('agruparPor', 'unidade')
            ->assertJsonPath('periodo.de', '2026-11-01')
            ->assertJsonPath('totais.pedidos', 3)
            ->assertJsonPath('totais.faturamento', 55)
            ->assertJsonPath('totais.ticketMedio', 18.33)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.unidade', 'Recife')
            ->assertJsonPath('data.0.unidadeId', $this->recife->id)
            ->assertJsonPath('data.0.regiao', 'NE')
            ->assertJsonPath('data.0.pedidos', 2)
            ->assertJsonPath('data.0.faturamento', 40)
            ->assertJsonPath('data.0.ticketMedio', 20)
            ->assertJsonPath('data.1.unidade', 'Sao Paulo')
            ->assertJsonPath('data.1.faturamento', 15);
    }

    public function test_so_status_de_venda_entram(): void
    {
        foreach ([StatusPedido::AGUARDANDO_PAGAMENTO, StatusPedido::CANCELADO] as $fora) {
            $this->pedido($this->recife, [[$this->tapioca, 1, 10]], ['status' => $fora]);
        }
        foreach ([StatusPedido::PAGO, StatusPedido::EM_PREPARO, StatusPedido::PRONTO, StatusPedido::ENTREGUE] as $dentro) {
            $this->pedido($this->recife, [[$this->tapioca, 1, 10]], ['status' => $dentro]);
        }

        $this->vendas()->assertJsonPath('totais.pedidos', 4)->assertJsonPath('totais.faturamento', 40);
    }

    public function test_periodo_inclui_o_dia_inteiro_nas_pontas(): void
    {
        $this->pedido($this->recife, [[$this->tapioca, 1, 10]], ['created_at' => '2026-11-01 00:00:00']);
        $this->pedido($this->recife, [[$this->tapioca, 1, 10]], ['created_at' => '2026-11-30 23:59:59']);
        $this->pedido($this->recife, [[$this->tapioca, 1, 10]], ['created_at' => '2026-10-31 23:59:59']);
        $this->pedido($this->recife, [[$this->tapioca, 1, 10]], ['created_at' => '2026-12-01 00:00:00']);

        $this->vendas()->assertJsonPath('totais.pedidos', 2);
    }

    public function test_periodo_sem_vendas_devolve_zeros(): void
    {
        $this->vendas()->assertOk()->assertJsonPath('totais.pedidos', 0)->assertJsonPath('totais.faturamento', 0)->assertJsonPath('totais.ticketMedio', 0)->assertJsonCount(0, 'data');
    }

    // ---------- agrupamentos e filtros ----------

    public function test_vendas_por_regiao(): void
    {
        $this->pedido($this->recife, [[$this->tapioca, 1, 10]]);
        $this->pedido($this->olinda, [[$this->tapioca, 1, 30]]);
        $this->pedido($this->sp, [[$this->tapioca, 1, 5]]);

        $r = $this->vendas('&agruparPor=regiao')->assertOk();

        $r->assertJsonPath('agruparPor', 'regiao')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.regiao', 'NE')
            ->assertJsonPath('data.0.pedidos', 2)
            ->assertJsonPath('data.0.faturamento', 40)
            ->assertJsonPath('data.1.regiao', 'SE');
    }

    public function test_vendas_por_canal(): void
    {
        $this->pedido($this->recife, [[$this->tapioca, 1, 10]], ['canal_pedido' => CanalPedido::APP]);
        $this->pedido($this->recife, [[$this->tapioca, 1, 10]], ['canal_pedido' => CanalPedido::APP]);
        $this->pedido($this->recife, [[$this->tapioca, 1, 50]], ['canal_pedido' => CanalPedido::TOTEM]);

        $r = $this->vendas('&agruparPor=canal')->assertOk();

        $r->assertJsonPath('data.0.canalPedido', 'TOTEM')->assertJsonPath('data.0.faturamento', 50)
            ->assertJsonPath('data.1.canalPedido', 'APP')->assertJsonPath('data.1.pedidos', 2)->assertJsonPath('data.1.faturamento', 20);
    }

    public function test_filtros_de_unidade_regiao_e_canal(): void
    {
        $this->pedido($this->recife, [[$this->tapioca, 1, 10]], ['canal_pedido' => CanalPedido::APP]);
        $this->pedido($this->olinda, [[$this->tapioca, 1, 20]], ['canal_pedido' => CanalPedido::TOTEM]);
        $this->pedido($this->sp, [[$this->tapioca, 1, 40]], ['canal_pedido' => CanalPedido::TOTEM]);

        $this->vendas('&unidadeId='.$this->olinda->id)->assertJsonPath('totais.faturamento', 20);
        $this->vendas('&regiao=NE')->assertJsonPath('totais.faturamento', 30);
        $this->vendas('&canalPedido=TOTEM')->assertJsonPath('totais.faturamento', 60);
        $this->vendas('&regiao=NE&canalPedido=TOTEM&agruparPor=canal')->assertJsonPath('totais.faturamento', 20)->assertJsonCount(1, 'data');
    }

    public function test_vendas_paginam_os_grupos_e_o_total_cobre_todos(): void
    {
        $this->pedido($this->recife, [[$this->tapioca, 1, 30]]);
        $this->pedido($this->olinda, [[$this->tapioca, 1, 20]]);
        $this->pedido($this->sp, [[$this->tapioca, 1, 10]]);

        $r = $this->vendas('&limit=2&page=2')->assertOk();

        $r->assertJsonCount(1, 'data')->assertJsonPath('data.0.unidade', 'Sao Paulo')
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('totais.faturamento', 60);
    }

    // ---------- escopo (RN13) ----------

    public function test_gerente_so_ve_a_propria_unidade(): void
    {
        $this->pedido($this->recife, [[$this->tapioca, 1, 10]]);
        $this->pedido($this->olinda, [[$this->tapioca, 1, 99]]);

        $r = $this->vendas('', $this->gerente)->assertOk();

        $r->assertJsonPath('totais.faturamento', 10)->assertJsonCount(1, 'data')->assertJsonPath('data.0.unidade', 'Recife');
        $this->vendas('&unidadeId='.$this->recife->id, $this->gerente)->assertOk();
        $this->vendas('&agruparPor=regiao', $this->gerente)->assertJsonPath('data.0.faturamento', 10);
    }

    public function test_gerente_pedindo_outra_unidade_leva_403(): void
    {
        $this->vendas('&unidadeId='.$this->olinda->id, $this->gerente)->assertStatus(403)->assertJsonPath('error', 'ACESSO_NEGADO');
        $this->ranking('&unidadeId='.$this->olinda->id, $this->gerente)->assertStatus(403);
    }

    public function test_outros_perfis_nao_acessam(): void
    {
        foreach ([Perfil::ATENDENTE, Perfil::COZINHA, Perfil::CLIENTE] as $perfil) {
            $u = Usuario::factory()->create(['perfil' => $perfil, 'unidade_id' => $this->recife->id]);
            $this->vendas('', $u)->assertStatus(403);
            $this->ranking('', $u)->assertStatus(403);
        }
    }

    public function test_exige_login(): void
    {
        $this->getJson('/relatorios/vendas?de=2026-11-01&ate=2026-11-30')->assertStatus(401);
        $this->getJson('/relatorios/produtos-mais-vendidos?de=2026-11-01&ate=2026-11-30')->assertStatus(401);
    }

    // ---------- validacao (RNF12) ----------

    public function test_periodo_e_obrigatorio_e_validado(): void
    {
        $admin = $this->comoUsuario($this->admin);

        $admin->getJson('/relatorios/vendas')->assertStatus(422)->assertJsonPath('details.0.campo', 'de');
        $admin->getJson('/relatorios/vendas?de=2026-11-01')->assertStatus(422)->assertJsonPath('details.0.campo', 'ate');
        $admin->getJson('/relatorios/vendas?de=01/11/2026&ate=2026-11-30')->assertStatus(422)->assertJsonPath('details.0.campo', 'de');
        $admin->getJson('/relatorios/vendas?de=2026-11-30&ate=2026-11-01')->assertStatus(422)->assertJsonPath('details.0.campo', 'ate');
        $admin->getJson('/relatorios/produtos-mais-vendidos')->assertStatus(422);
    }

    public function test_periodo_muito_longo_e_recusado(): void
    {
        $this->comoUsuario($this->admin)->getJson('/relatorios/vendas?de=2024-01-01&ate=2026-11-30')
            ->assertStatus(422)->assertJsonPath('details.0.campo', 'ate');
        $this->comoUsuario($this->admin)->getJson('/relatorios/vendas?de=2026-01-01&ate=2026-12-31')->assertOk();
    }

    public function test_filtros_invalidos_dao_422(): void
    {
        $this->vendas('&agruparPor=cor')->assertStatus(422)->assertJsonPath('details.0.campo', 'agruparPor');
        $this->vendas('&regiao=XX')->assertStatus(422)->assertJsonPath('details.0.campo', 'regiao');
        $this->vendas('&canalPedido=TELEGRAMA')->assertStatus(422)->assertJsonPath('details.0.campo', 'canalPedido');
        $this->vendas('&unidadeId=abc')->assertStatus(422)->assertJsonPath('details.0.campo', 'unidadeId');
    }

    // ---------- ranking de produtos ----------

    public function test_ranking_ordena_por_quantidade(): void
    {
        $this->pedido($this->recife, [[$this->tapioca, 2, 10], [$this->cuscuz, 5, 4]]);
        $this->pedido($this->olinda, [[$this->tapioca, 4, 10]]);

        $r = $this->ranking()->assertOk();

        $r->assertJsonPath('periodo.ate', '2026-11-30')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.posicao', 1)
            ->assertJsonPath('data.0.nome', 'Tapioca')
            ->assertJsonPath('data.0.produtoId', $this->tapioca->id)
            ->assertJsonPath('data.0.quantidade', 6)
            ->assertJsonPath('data.0.faturamento', 60)
            ->assertJsonPath('data.0.pedidos', 2)
            ->assertJsonPath('data.1.posicao', 2)
            ->assertJsonPath('data.1.nome', 'Cuscuz')
            ->assertJsonPath('data.1.quantidade', 5)
            ->assertJsonPath('data.1.faturamento', 20)
            ->assertJsonPath('data.1.pedidos', 1);
    }

    public function test_ranking_ignora_pedido_cancelado_ou_fora_do_periodo(): void
    {
        $this->pedido($this->recife, [[$this->tapioca, 1, 10]]);
        $this->pedido($this->recife, [[$this->cuscuz, 50, 4]], ['status' => StatusPedido::CANCELADO]);
        $this->pedido($this->recife, [[$this->cuscuz, 50, 4]], ['created_at' => '2026-09-01 10:00:00']);

        $this->ranking()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome', 'Tapioca');
    }

    public function test_ranking_por_unidade_e_regiao(): void
    {
        $this->pedido($this->recife, [[$this->tapioca, 1, 10]]);
        $this->pedido($this->sp, [[$this->cuscuz, 9, 4]]);

        $this->ranking('&regiao=SE')->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome', 'Cuscuz');
        $this->ranking('&unidadeId='.$this->recife->id)->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome', 'Tapioca');
        $this->ranking('', $this->gerente)->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome', 'Tapioca');
    }

    public function test_ranking_pagina_com_posicao_continua(): void
    {
        $terceiro = Produto::factory()->create(['nome' => 'Baiao']);
        $this->pedido($this->recife, [[$this->tapioca, 5, 1], [$this->cuscuz, 4, 1], [$terceiro, 3, 1]]);

        $r = $this->ranking('&limit=2&page=2')->assertOk();

        $r->assertJsonCount(1, 'data')->assertJsonPath('data.0.posicao', 3)->assertJsonPath('data.0.nome', 'Baiao')
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2);
    }

    public function test_relatorios_nao_expoem_dados_de_cliente(): void
    {
        $this->pedido($this->recife, [[$this->tapioca, 1, 10]]);

        foreach ([$this->vendas()->getContent(), $this->ranking()->getContent()] as $texto) {
            $this->assertStringNotContainsString('email', $texto);
            $this->assertStringNotContainsString('clienteId', $texto);
        }
    }
}
