<?php

namespace Tests\Feature;

use App\Application\Estoque\MovimentarEstoque;
use App\Domain\Auditoria\Acao;
use App\Domain\Auditoria\RegistroAuditoria;
use App\Domain\Cardapio\Produto;
use App\Domain\Estoque\Estoque;
use App\Domain\Estoque\MovimentacaoEstoque;
use App\Domain\Estoque\TipoMovimentacao;
use App\Domain\Pedidos\Pedido;
use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EstoqueTest extends TestCase
{
    use RefreshDatabase;

    private Unidade $unidade;
    private Produto $produto;
    private Usuario $gerente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unidade = Unidade::factory()->create();
        $this->produto = Produto::factory()->create(['nome' => 'Tapioca']);
        $this->gerente = $this->usuario(Perfil::GERENTE, $this->unidade);
    }

    private function usuario(Perfil $perfil, ?Unidade $unidade = null): Usuario
    {
        return Usuario::factory()->create(['perfil' => $perfil, 'unidade_id' => $unidade?->id]);
    }

    private function saldo(?Unidade $unidade = null): ?int
    {
        return Estoque::where('unidade_id', ($unidade ?? $this->unidade)->id)
            ->where('produto_id', $this->produto->id)->value('quantidade');
    }

    private function mover(string $tipo, int $quantidade, ?Usuario $usuario = null, ?Unidade $unidade = null)
    {
        return $this->comoUsuario($usuario ?? $this->gerente)->postJson('/unidades/'.($unidade ?? $this->unidade)->id.'/estoque/movimentacoes', [
            'produtoId' => $this->produto->id,
            'tipo' => $tipo,
            'quantidade' => $quantidade,
            'motivo' => 'Teste',
        ]);
    }

    public function test_entrada_cria_o_estoque_e_registra_a_movimentacao(): void
    {
        $this->mover('ENTRADA', 20)
            ->assertCreated()
            ->assertJsonPath('data.tipo', 'ENTRADA')
            ->assertJsonPath('data.quantidade', 20)
            ->assertJsonPath('data.saldoApos', 20)
            ->assertJsonPath('data.usuarioId', $this->gerente->id)
            ->assertJsonPath('data.produtoId', $this->produto->id);

        $this->assertSame(20, $this->saldo());
    }

    public function test_saida_e_ajuste_mexem_no_saldo(): void
    {
        $this->mover('ENTRADA', 20)->assertCreated();
        $this->mover('SAIDA', 5)->assertCreated()->assertJsonPath('data.quantidade', -5)->assertJsonPath('data.saldoApos', 15);
        $this->mover('AJUSTE', -3)->assertCreated()->assertJsonPath('data.saldoApos', 12);
        $this->mover('AJUSTE', 8)->assertCreated()->assertJsonPath('data.saldoApos', 20);

        $this->assertSame(20, $this->saldo());
        $this->assertSame(4, MovimentacaoEstoque::count());
    }

    public function test_saida_maior_que_o_saldo_da_409_e_nao_grava_nada(): void
    {
        $this->mover('ENTRADA', 5)->assertCreated();

        $this->mover('SAIDA', 6)
            ->assertStatus(409)
            ->assertJsonPath('error', 'ESTOQUE_INSUFICIENTE')
            ->assertJsonPath('details.0.disponivel', 5)
            ->assertJsonPath('details.0.solicitado', 6);

        $this->mover('AJUSTE', -6)->assertStatus(409);

        $this->assertSame(5, $this->saldo());
        $this->assertSame(1, MovimentacaoEstoque::count());
        $this->assertSame(1, RegistroAuditoria::count());
    }

    public function test_saida_de_produto_sem_estoque_da_409(): void
    {
        $this->mover('SAIDA', 1)->assertStatus(409)->assertJsonPath('details.0.disponivel', 0);
        // a linha criada para travar foi desfeita junto
        $this->assertNull($this->saldo());
    }

    public function test_valida_o_corpo(): void
    {
        $url = '/unidades/'.$this->unidade->id.'/estoque/movimentacoes';
        $c = $this->comoUsuario($this->gerente);

        $c->postJson($url, [])->assertStatus(422)->assertJsonPath('error', 'VALIDACAO')->assertJsonCount(4, 'details');
        $c->postJson($url, ['produtoId' => $this->produto->id, 'tipo' => 'ESTORNO', 'quantidade' => 1, 'motivo' => 'x outro'])
            ->assertStatus(422)->assertJsonPath('details.0.campo', 'tipo');
        $this->mover('ENTRADA', 0)->assertStatus(422);
        $this->mover('ENTRADA', -4)->assertStatus(422);
        $this->mover('SAIDA', 0)->assertStatus(422);
        $this->mover('AJUSTE', 0)->assertStatus(422);
        $c->postJson($url, ['produtoId' => $this->produto->id, 'tipo' => 'ENTRADA', 'quantidade' => 1.5, 'motivo' => 'Teste'])->assertStatus(422);

        $this->assertSame(0, MovimentacaoEstoque::count());
    }

    public function test_produto_inexistente_da_404(): void
    {
        $this->comoUsuario($this->gerente)->postJson('/unidades/'.$this->unidade->id.'/estoque/movimentacoes', [
            'produtoId' => 9999, 'tipo' => 'ENTRADA', 'quantidade' => 1, 'motivo' => 'Teste',
        ])->assertNotFound()->assertJsonPath('error', 'PRODUTO_NAO_ENCONTRADO');
    }

    public function test_movimentacao_manual_gera_auditoria(): void
    {
        $this->mover('ENTRADA', 10)->assertCreated();
        $this->mover('AJUSTE', -4)->assertCreated();

        $r = RegistroAuditoria::where('acao', Acao::AJUSTE_ESTOQUE)->orderByDesc('id')->first();
        $this->assertSame($this->gerente->id, $r->usuario_id);
        $this->assertSame('estoques', $r->entidade);
        $this->assertSame(['quantidade' => 10], $r->dados_antes);
        $this->assertSame(6, $r->dados_depois['quantidade']);
        $this->assertSame('AJUSTE', $r->dados_depois['tipo']);
        $this->assertSame(2, RegistroAuditoria::count());
    }

    public function test_movimentacao_de_pedido_nao_gera_auditoria_de_ajuste(): void
    {
        $this->mover('ENTRADA', 10)->assertCreated();
        $pedido = Pedido::factory()->create(['unidade_id' => $this->unidade->id]);

        app(MovimentarEstoque::class)->executar($this->unidade, $this->produto->id, TipoMovimentacao::SAIDA, 2, 'Pedido', $this->gerente, $pedido->id);

        $this->assertSame(1, RegistroAuditoria::count());
        $this->assertSame($pedido->id, MovimentacaoEstoque::orderByDesc('id')->first()->pedido_id);
        $this->assertSame(8, $this->saldo());
    }

    public function test_estorno_devolve_ao_saldo(): void
    {
        $this->mover('ENTRADA', 10)->assertCreated();
        $pedido = Pedido::factory()->create(['unidade_id' => $this->unidade->id]);
        $mov = app(MovimentarEstoque::class)->executar($this->unidade, $this->produto->id, TipoMovimentacao::ESTORNO, 3, 'Cancelamento', $this->gerente, $pedido->id);

        $this->assertSame(3, $mov->quantidade);
        $this->assertSame(13, $this->saldo());
    }

    public function test_falha_depois_de_mexer_no_saldo_desfaz_tudo(): void
    {
        $this->mover('ENTRADA', 10)->assertCreated();
        $pedido = Pedido::factory()->create(['unidade_id' => $this->unidade->id]);

        try {
            DB::transaction(function () use ($pedido) {
                app(MovimentarEstoque::class)->executar($this->unidade, $this->produto->id, TipoMovimentacao::SAIDA, 4, 'Pedido', $this->gerente, $pedido->id);
                throw new \RuntimeException('segundo item sem estoque');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(10, $this->saldo());
        $this->assertSame(1, MovimentacaoEstoque::count());
    }

    public function test_usa_lock_na_leitura_do_saldo(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('o sqlite nao tem SELECT ... FOR UPDATE');
        }

        DB::enableQueryLog();
        $this->mover('ENTRADA', 1)->assertCreated();

        $achou = collect(DB::getQueryLog())->contains(fn ($q) => str_contains(strtolower($q['query']), 'for update'));
        $this->assertTrue($achou);
    }

    public function test_banco_nao_aceita_saldo_negativo(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('o sqlite dos testes nao cria o CHECK');
        }

        $this->mover('ENTRADA', 1)->assertCreated();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('estoques')->update(['quantidade' => -1]);
    }

    public function test_so_o_gerente_da_unidade_movimenta(): void
    {
        $outra = Unidade::factory()->create();

        $this->mover('ENTRADA', 1, $this->usuario(Perfil::GERENTE, $outra))->assertForbidden();
        $this->mover('ENTRADA', 1, $this->usuario(Perfil::ADMIN))->assertForbidden();
        $this->mover('ENTRADA', 1, $this->usuario(Perfil::ATENDENTE, $this->unidade))->assertForbidden();
        $this->mover('ENTRADA', 1, $this->usuario(Perfil::CLIENTE))->assertForbidden();

        $this->assertSame(0, MovimentacaoEstoque::count());
    }

    public function test_movimentar_exige_login_e_unidade_valida(): void
    {
        $this->postJson('/unidades/'.$this->unidade->id.'/estoque/movimentacoes', [])->assertUnauthorized();

        $this->mover('ENTRADA', 1, null, Unidade::factory()->create(['ativa' => false]))->assertNotFound();
    }

    public function test_consulta_de_saldo_por_perfil(): void
    {
        $outra = Unidade::factory()->create();
        $outroProduto = Produto::factory()->create(['nome' => 'Cuscuz']);
        $this->mover('ENTRADA', 7)->assertCreated();
        app(MovimentarEstoque::class)->executar($this->unidade, $outroProduto->id, TipoMovimentacao::ENTRADA, 3, 'Carga', $this->gerente);
        app(MovimentarEstoque::class)->executar($outra, $this->produto->id, TipoMovimentacao::ENTRADA, 99, 'Carga', $this->usuario(Perfil::GERENTE, $outra));

        $url = '/unidades/'.$this->unidade->id.'/estoque';

        $r = $this->comoUsuario($this->usuario(Perfil::ATENDENTE, $this->unidade))->getJson($url)->assertOk();
        $this->assertSame(['Cuscuz' => 3, 'Tapioca' => 7], collect($r->json('data'))->pluck('quantidade', 'nome')->all());
        $this->assertSame(['Cuscuz', 'Tapioca'], collect($r->json('data'))->pluck('nome')->all());

        $this->comoUsuario($this->gerente)->getJson($url)->assertOk();
        $this->comoUsuario($this->usuario(Perfil::ADMIN))->getJson($url)->assertOk()->assertJsonCount(2, 'data');

        $this->comoUsuario($this->usuario(Perfil::GERENTE, $outra))->getJson($url)->assertForbidden();
        $this->comoUsuario($this->usuario(Perfil::ATENDENTE, $outra))->getJson($url)->assertForbidden();
        $this->comoUsuario($this->usuario(Perfil::COZINHA, $this->unidade))->getJson($url)->assertForbidden();
        $this->comoUsuario($this->usuario(Perfil::CLIENTE))->getJson($url)->assertForbidden();
    }

    public function test_consulta_exige_login(): void
    {
        $this->getJson('/unidades/'.$this->unidade->id.'/estoque')->assertUnauthorized();
        $this->getJson('/unidades/'.$this->unidade->id.'/estoque/movimentacoes')->assertUnauthorized();
    }

    public function test_historico_do_mais_novo_para_o_mais_antigo_com_filtros_e_paginacao(): void
    {
        $outraUnidade = Unidade::factory()->create();
        $this->mover('ENTRADA', 10)->assertCreated();
        $this->mover('SAIDA', 2)->assertCreated();
        $this->mover('AJUSTE', -1)->assertCreated();
        app(MovimentarEstoque::class)->executar($outraUnidade, $this->produto->id, TipoMovimentacao::ENTRADA, 50, 'Carga', $this->usuario(Perfil::GERENTE, $outraUnidade));

        $url = '/unidades/'.$this->unidade->id.'/estoque/movimentacoes';
        $c = $this->comoUsuario($this->usuario(Perfil::ATENDENTE, $this->unidade));

        $r = $c->getJson($url)->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(['AJUSTE', 'SAIDA', 'ENTRADA'], collect($r->json('data'))->pluck('tipo')->all());

        $c->getJson($url.'?tipo=SAIDA')->assertOk()->assertJsonCount(1, 'data');
        $c->getJson($url.'?produtoId='.$this->produto->id)->assertOk()->assertJsonCount(3, 'data');
        $c->getJson($url.'?produtoId=9999')->assertOk()->assertJsonCount(0, 'data');
        $c->getJson($url.'?limit=2&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
        $c->getJson($url.'?tipo=OUTRO')->assertStatus(422);
    }

    public function test_historico_nao_vaza_para_outra_unidade(): void
    {
        $this->mover('ENTRADA', 10)->assertCreated();
        $outra = Unidade::factory()->create();

        $this->comoUsuario($this->usuario(Perfil::GERENTE, $outra))->getJson('/unidades/'.$this->unidade->id.'/estoque/movimentacoes')->assertForbidden();
    }
}
