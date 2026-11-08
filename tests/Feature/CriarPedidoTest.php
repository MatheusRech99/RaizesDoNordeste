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
use App\Domain\Pedidos\HistoricoStatus;
use App\Domain\Pedidos\ItemPedido;
use App\Domain\Pedidos\Pedido;
use App\Domain\Unidades\HorarioFuncionamento;
use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CriarPedidoTest extends TestCase
{
    use RefreshDatabase;

    private Unidade $unidade;
    private Usuario $cliente;
    private Usuario $atendente;
    private Produto $tapioca;
    private Produto $cuscuz;

    protected function setUp(): void
    {
        parent::setUp();

        // segunda-feira ao meio-dia, com a unidade aberta o dia todo
        Carbon::setTestNow(Carbon::parse('2026-11-09 12:00:00'));

        $this->unidade = Unidade::factory()->create();
        $this->abrir($this->unidade);
        $this->cliente = Usuario::factory()->create(['perfil' => Perfil::CLIENTE]);
        $this->atendente = Usuario::factory()->create(['perfil' => Perfil::ATENDENTE, 'unidade_id' => $this->unidade->id]);
        $this->tapioca = $this->noCardapio('Tapioca', '12.50', 10);
        $this->cuscuz = $this->noCardapio('Cuscuz', '8.33', 10);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function abrir(Unidade $unidade): void
    {
        foreach (range(0, 6) as $dia) {
            HorarioFuncionamento::create(['unidade_id' => $unidade->id, 'dia_semana' => $dia, 'abre' => '08:00:00', 'fecha' => '22:00:00']);
        }
    }

    private function noCardapio(string $nome, string $preco, ?int $saldo, ?Unidade $unidade = null, array $item = []): Produto
    {
        $unidade ??= $this->unidade;
        $produto = Produto::factory()->create(['nome' => $nome]);
        ItemCardapio::factory()->create($item + ['unidade_id' => $unidade->id, 'produto_id' => $produto->id, 'preco' => $preco]);

        if ($saldo !== null) {
            app(MovimentarEstoque::class)->executar($unidade, $produto->id, TipoMovimentacao::ENTRADA, $saldo, 'Carga', Usuario::factory()->create(['perfil' => Perfil::GERENTE, 'unidade_id' => $unidade->id]));
        }

        return $produto;
    }

    private function saldo(Produto $produto): ?int
    {
        return Estoque::where('unidade_id', $this->unidade->id)->where('produto_id', $produto->id)->value('quantidade');
    }

    private function corpo(array $extra = []): array
    {
        return $extra + [
            'canalPedido' => 'APP',
            'unidadeId' => $this->unidade->id,
            'itens' => [['produtoId' => $this->tapioca->id, 'quantidade' => 2]],
        ];
    }

    private function pedir(array $corpo, ?Usuario $quem = null, array $headers = [])
    {
        return $this->comoUsuario($quem ?? $this->cliente)->postJson('/pedidos', $corpo, $headers);
    }

    private function nada(): void
    {
        $this->assertSame(0, Pedido::count());
        $this->assertSame(0, ItemPedido::count());
        $this->assertSame(0, HistoricoStatus::count());
        $this->assertSame(0, MovimentacaoEstoque::whereNotNull('pedido_id')->count());
        $this->assertSame(0, RegistroAuditoria::where('acao', Acao::CRIACAO_PEDIDO)->count());
    }

    // ---------- sucesso ----------

    public function test_cliente_cria_pedido_com_total_calculado_no_servidor(): void
    {
        $r = $this->pedir($this->corpo(['itens' => [
            ['produtoId' => $this->tapioca->id, 'quantidade' => 3],
            ['produtoId' => $this->cuscuz->id, 'quantidade' => 2],
        ]]));

        $r->assertCreated()
            ->assertJsonPath('data.status', 'AGUARDANDO_PAGAMENTO')
            ->assertJsonPath('data.canalPedido', 'APP')
            ->assertJsonPath('data.unidadeId', $this->unidade->id)
            ->assertJsonPath('data.clienteId', $this->cliente->id)
            ->assertJsonPath('data.criadoPor', $this->cliente->id)
            ->assertJsonPath('data.subtotal', 54.16)
            ->assertJsonPath('data.desconto', 0)
            ->assertJsonPath('data.total', 54.16)
            ->assertJsonCount(2, 'data.itens');

        $this->assertEquals(
            ['Tapioca' => [3, 12.5, 37.5], 'Cuscuz' => [2, 8.33, 16.66]],
            collect($r->json('data.itens'))->mapWithKeys(fn ($i) => [$i['nome'] => [$i['quantidade'], $i['precoUnitario'], $i['subtotal']]])->all(),
        );
        $this->assertSame('54.16', Pedido::first()->total);
    }

    public function test_preco_enviado_pelo_cliente_e_ignorado(): void
    {
        $corpo = $this->corpo(['total' => 0.01]);
        $corpo['itens'][0]['preco'] = 0.01;
        $corpo['itens'][0]['precoUnitario'] = 0.01;

        $this->pedir($corpo)->assertCreated()->assertJsonPath('data.total', 25);
    }

    public function test_pedido_guarda_o_preco_do_momento(): void
    {
        $this->pedir($this->corpo())->assertCreated();

        ItemCardapio::where('produto_id', $this->tapioca->id)->update(['preco' => '99.00']);

        $this->assertSame('12.50', ItemPedido::first()->preco_unitario);
        $this->assertSame('25.00', Pedido::first()->total);
    }

    public function test_baixa_o_estoque_e_grava_historico_movimentacao_e_auditoria(): void
    {
        $r = $this->pedir($this->corpo())->assertCreated();
        $id = $r->json('data.id');

        $this->assertSame(8, $this->saldo($this->tapioca));

        $h = HistoricoStatus::first();
        $this->assertSame($id, $h->pedido_id);
        $this->assertNull($h->status_anterior);
        $this->assertSame('AGUARDANDO_PAGAMENTO', $h->status_novo->value);
        $this->assertSame($this->cliente->id, $h->usuario_id);

        $mov = MovimentacaoEstoque::where('pedido_id', $id)->first();
        $this->assertSame(TipoMovimentacao::SAIDA, $mov->tipo);
        $this->assertSame(-2, $mov->quantidade);
        $this->assertSame(8, $mov->saldo_apos);

        $a = RegistroAuditoria::where('acao', Acao::CRIACAO_PEDIDO)->first();
        $this->assertSame($this->cliente->id, $a->usuario_id);
        $this->assertSame('pedidos', $a->entidade);
        $this->assertSame($id, $a->entidade_id);
        $this->assertSame('APP', $a->dados_depois['canalPedido']);
    }

    public function test_item_sazonal_dentro_do_periodo_pode_ser_pedido(): void
    {
        $junina = $this->noCardapio('Canjica', '10.00', 5, null, ['inicio_sazonal' => '05-15', 'fim_sazonal' => '12-31']);

        $this->pedir($this->corpo(['itens' => [['produtoId' => $junina->id, 'quantidade' => 1]]]))->assertCreated();
    }

    public function test_aceita_todos_os_canais_de_cliente(): void
    {
        foreach (['APP', 'TOTEM', 'PICKUP', 'WEB'] as $canal) {
            $this->pedir($this->corpo(['canalPedido' => $canal, 'itens' => [['produtoId' => $this->tapioca->id, 'quantidade' => 1]]]))
                ->assertCreated()->assertJsonPath('data.canalPedido', $canal);
        }
    }

    // ---------- validacao (RN01, RN03) ----------

    public function test_canal_ausente_ou_invalido_da_422_e_nao_grava_nada(): void
    {
        $semCanal = $this->corpo();
        unset($semCanal['canalPedido']);

        $this->pedir($semCanal)->assertStatus(422)->assertJsonPath('error', 'VALIDACAO')->assertJsonPath('details.0.campo', 'canalPedido');
        $this->pedir($this->corpo(['canalPedido' => 'TELEGRAMA']))->assertStatus(422)->assertJsonPath('details.0.campo', 'canalPedido');

        $this->nada();
        $this->assertSame(10, $this->saldo($this->tapioca));
    }

    public function test_itens_invalidos_dao_422(): void
    {
        $p = $this->tapioca->id;

        $this->pedir($this->corpo(['itens' => []]))->assertStatus(422);
        $this->pedir($this->corpo(['itens' => [['produtoId' => $p, 'quantidade' => 0]]]))->assertStatus(422);
        $this->pedir($this->corpo(['itens' => [['produtoId' => $p, 'quantidade' => -1]]]))->assertStatus(422);
        $this->pedir($this->corpo(['itens' => [['produtoId' => $p, 'quantidade' => 1.5]]]))->assertStatus(422);
        $this->pedir($this->corpo(['itens' => [['produtoId' => $p]]]))->assertStatus(422);
        $this->pedir($this->corpo(['itens' => [['quantidade' => 1]]]))->assertStatus(422);
        $this->pedir($this->corpo(['itens' => 'tapioca']))->assertStatus(422);
        $this->pedir($this->corpo(['unidadeId' => null]))->assertStatus(422);

        $this->nada();
    }

    public function test_produto_repetido_em_dois_itens_da_422(): void
    {
        $p = $this->tapioca->id;

        $this->pedir($this->corpo(['itens' => [['produtoId' => $p, 'quantidade' => 1], ['produtoId' => $p, 'quantidade' => 2]]]))
            ->assertStatus(422)->assertJsonPath('error', 'VALIDACAO');

        $this->nada();
    }

    public function test_cliente_nao_usa_balcao_nem_manda_clienteId(): void
    {
        $this->pedir($this->corpo(['canalPedido' => 'BALCAO']))->assertStatus(422)->assertJsonPath('details.0.campo', 'canalPedido');
        $this->pedir($this->corpo(['clienteId' => $this->cliente->id]))->assertStatus(422)->assertJsonPath('details.0.campo', 'clienteId');

        $this->nada();
    }

    // ---------- unidade e produto (RN02) ----------

    public function test_unidade_inexistente_ou_inativa_da_404(): void
    {
        $this->pedir($this->corpo(['unidadeId' => 9999]))->assertNotFound()->assertJsonPath('error', 'UNIDADE_NAO_ENCONTRADA');

        $this->unidade->update(['ativa' => false]);
        $this->pedir($this->corpo())->assertNotFound()->assertJsonPath('error', 'UNIDADE_NAO_ENCONTRADA');

        $this->nada();
    }

    public function test_produto_que_nao_esta_no_cardapio_da_unidade_da_404(): void
    {
        $outra = Unidade::factory()->create();
        $deOutra = $this->noCardapio('Baião', '30.00', 5, $outra);
        $semCardapio = Produto::factory()->create();

        $this->pedir($this->corpo(['itens' => [
            ['produtoId' => $this->tapioca->id, 'quantidade' => 1],
            ['produtoId' => $deOutra->id, 'quantidade' => 1],
            ['produtoId' => $semCardapio->id, 'quantidade' => 1],
            ['produtoId' => 9999, 'quantidade' => 1],
        ]]))
            ->assertNotFound()
            ->assertJsonPath('error', 'PRODUTO_NAO_ENCONTRADO')
            ->assertJsonCount(3, 'details');

        $this->nada();
        $this->assertSame(10, $this->saldo($this->tapioca));
    }

    // ---------- horario (RN15) ----------

    public function test_unidade_fechada_da_409(): void
    {
        // antes de abrir, depois de fechar e dia sem horario
        foreach (['2026-11-09 07:59:00', '2026-11-09 22:00:00'] as $momento) {
            Carbon::setTestNow(Carbon::parse($momento));
            $this->pedir($this->corpo())->assertStatus(409)->assertJsonPath('error', 'UNIDADE_FECHADA');
        }

        Carbon::setTestNow(Carbon::parse('2026-11-09 12:00:00'));
        HorarioFuncionamento::where('unidade_id', $this->unidade->id)->where('dia_semana', 1)->delete();
        $this->pedir($this->corpo())->assertStatus(409)->assertJsonPath('error', 'UNIDADE_FECHADA');

        $this->nada();
        $this->assertSame(10, $this->saldo($this->tapioca));
    }

    public function test_aberta_na_hora_exata_de_abrir(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 08:00:00'));

        $this->pedir($this->corpo())->assertCreated();
    }

    // ---------- disponibilidade (RN16) ----------

    public function test_produto_indisponivel_fora_de_epoca_ou_inativo_da_409(): void
    {
        $desligado = $this->noCardapio('Desligado', '5.00', 5, null, ['disponivel' => false]);
        $foraDeEpoca = $this->noCardapio('Bolo junino', '9.00', 5, null, ['inicio_sazonal' => '05-15', 'fim_sazonal' => '07-15']);
        $inativo = $this->noCardapio('Descontinuado', '7.00', 5);
        $inativo->update(['ativo' => false]);

        foreach ([$desligado, $foraDeEpoca, $inativo] as $produto) {
            $this->pedir($this->corpo(['itens' => [
                ['produtoId' => $this->tapioca->id, 'quantidade' => 1],
                ['produtoId' => $produto->id, 'quantidade' => 1],
            ]]))
                ->assertStatus(409)
                ->assertJsonPath('error', 'PRODUTO_INDISPONIVEL')
                ->assertJsonPath('details.0.produtoId', $produto->id)
                ->assertJsonPath('details.0.nome', $produto->nome);
        }

        $this->nada();
        $this->assertSame(10, $this->saldo($this->tapioca));
    }

    // ---------- estoque (RN05) ----------

    public function test_estoque_insuficiente_da_409_e_desfaz_tudo(): void
    {
        $cuscuz = $this->cuscuz;

        $this->pedir($this->corpo(['itens' => [
            ['produtoId' => $this->tapioca->id, 'quantidade' => 4],
            ['produtoId' => $cuscuz->id, 'quantidade' => 11],
        ]]))
            ->assertStatus(409)
            ->assertJsonPath('error', 'ESTOQUE_INSUFICIENTE')
            ->assertJsonCount(1, 'details')
            ->assertJsonPath('details.0.produtoId', $cuscuz->id)
            ->assertJsonPath('details.0.disponivel', 10)
            ->assertJsonPath('details.0.solicitado', 11);

        $this->nada();
        // o item que tinha saldo tambem nao foi baixado
        $this->assertSame(10, $this->saldo($this->tapioca));
        $this->assertSame(10, $this->saldo($cuscuz));
    }

    public function test_avisa_de_todos_os_itens_sem_saldo(): void
    {
        $this->pedir($this->corpo(['itens' => [
            ['produtoId' => $this->tapioca->id, 'quantidade' => 11],
            ['produtoId' => $this->cuscuz->id, 'quantidade' => 12],
        ]]))->assertStatus(409)->assertJsonCount(2, 'details');

        $this->nada();
    }

    public function test_produto_no_cardapio_sem_nenhum_estoque_da_409(): void
    {
        $novo = $this->noCardapio('Sem carga', '5.00', null);

        $this->pedir($this->corpo(['itens' => [['produtoId' => $novo->id, 'quantidade' => 1]]]))
            ->assertStatus(409)->assertJsonPath('details.0.disponivel', 0);

        $this->nada();
        $this->assertNull($this->saldo($novo));
    }

    public function test_pode_levar_todo_o_estoque(): void
    {
        $this->pedir($this->corpo(['itens' => [['produtoId' => $this->tapioca->id, 'quantidade' => 10]]]))->assertCreated();
        $this->assertSame(0, $this->saldo($this->tapioca));

        $this->pedir($this->corpo(['itens' => [['produtoId' => $this->tapioca->id, 'quantidade' => 1]]]))->assertStatus(409);
        $this->assertSame(1, Pedido::count());
    }

    // ---------- atendente e escopo (RN13) ----------

    public function test_atendente_cria_pedido_de_balcao_sem_cliente(): void
    {
        $this->pedir($this->corpo(['canalPedido' => 'BALCAO']), $this->atendente)
            ->assertCreated()
            ->assertJsonPath('data.clienteId', null)
            ->assertJsonPath('data.criadoPor', $this->atendente->id)
            ->assertJsonPath('data.canalPedido', 'BALCAO');

        $this->assertSame($this->atendente->id, HistoricoStatus::first()->usuario_id);
        $this->assertSame($this->atendente->id, MovimentacaoEstoque::whereNotNull('pedido_id')->first()->usuario_id);
    }

    public function test_atendente_pode_identificar_o_cliente(): void
    {
        $this->pedir($this->corpo(['canalPedido' => 'BALCAO', 'clienteId' => $this->cliente->id]), $this->atendente)
            ->assertCreated()->assertJsonPath('data.clienteId', $this->cliente->id);

        $this->pedir($this->corpo(['canalPedido' => 'PICKUP', 'clienteId' => $this->cliente->id]), $this->atendente)
            ->assertCreated()->assertJsonPath('data.criadoPor', $this->atendente->id);
    }

    public function test_atendente_fora_do_balcao_precisa_informar_o_cliente(): void
    {
        $this->pedir($this->corpo(['canalPedido' => 'PICKUP']), $this->atendente)
            ->assertStatus(422)->assertJsonPath('details.0.campo', 'clienteId');
    }

    public function test_clienteId_tem_que_ser_de_um_cliente(): void
    {
        $this->pedir($this->corpo(['canalPedido' => 'BALCAO', 'clienteId' => $this->atendente->id]), $this->atendente)->assertStatus(422);
        $this->pedir($this->corpo(['canalPedido' => 'BALCAO', 'clienteId' => 9999]), $this->atendente)->assertStatus(422);

        $this->nada();
    }

    public function test_atendente_de_outra_unidade_leva_403(): void
    {
        $outra = Unidade::factory()->create();
        $atendenteDeOutra = Usuario::factory()->create(['perfil' => Perfil::ATENDENTE, 'unidade_id' => $outra->id]);

        $this->pedir($this->corpo(['canalPedido' => 'BALCAO']), $atendenteDeOutra)
            ->assertForbidden()->assertJsonPath('error', 'ACESSO_NEGADO');

        $this->nada();
    }

    public function test_visitante_nao_cria_pedido(): void
    {
        $this->postJson('/pedidos', $this->corpo())->assertUnauthorized();
        $this->nada();
    }

    public function test_outros_perfis_nao_criam_pedido(): void
    {
        foreach ([Perfil::GERENTE, Perfil::COZINHA, Perfil::ADMIN] as $perfil) {
            $u = Usuario::factory()->create(['perfil' => $perfil, 'unidade_id' => $perfil === Perfil::ADMIN ? null : $this->unidade->id]);
            $this->pedir($this->corpo(['canalPedido' => 'BALCAO']), $u)->assertForbidden();
        }

        $this->nada();
    }

    // ---------- idempotencia (RN07) ----------

    public function test_mesma_chave_nao_duplica_o_pedido_nem_a_baixa(): void
    {
        $h = ['Idempotency-Key' => 'pedido-123'];

        $primeira = $this->pedir($this->corpo(), null, $h)->assertCreated();
        $segunda = $this->pedir($this->corpo(), null, $h)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($primeira->json('data.id'), $segunda->json('data.id'));
        $this->assertSame(1, Pedido::count());
        $this->assertSame(8, $this->saldo($this->tapioca));
        $this->assertSame('pedido-123', Pedido::first()->idempotency_key);
    }

    public function test_mesma_chave_com_itens_diferentes_da_409(): void
    {
        $h = ['Idempotency-Key' => 'pedido-123'];

        $this->pedir($this->corpo(), null, $h)->assertCreated();
        $this->pedir($this->corpo(['itens' => [['produtoId' => $this->tapioca->id, 'quantidade' => 5]]]), null, $h)
            ->assertStatus(409)->assertJsonPath('error', 'CHAVE_IDEMPOTENCIA_CONFLITANTE');

        $this->assertSame(1, Pedido::count());
        $this->assertSame(8, $this->saldo($this->tapioca));
    }

    public function test_sem_chave_cada_requisicao_cria_um_pedido(): void
    {
        $this->pedir($this->corpo())->assertCreated();
        $this->pedir($this->corpo())->assertCreated();

        $this->assertSame(2, Pedido::count());
        $this->assertSame(6, $this->saldo($this->tapioca));
    }

    public function test_erro_de_validacao_nao_gasta_a_chave(): void
    {
        $h = ['Idempotency-Key' => 'pedido-123'];

        $this->pedir($this->corpo(['canalPedido' => 'X']), null, $h)->assertStatus(422);
        $this->pedir($this->corpo(), null, $h)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    }

    public function test_banco_barra_chave_repetida_do_mesmo_usuario(): void
    {
        $this->pedir($this->corpo(), null, ['Idempotency-Key' => 'x'])->assertCreated();

        $this->expectException(QueryException::class);
        Pedido::factory()->create(['criado_por' => $this->cliente->id, 'unidade_id' => $this->unidade->id, 'idempotency_key' => 'x']);
    }
}
