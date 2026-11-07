<?php

namespace Tests\Feature;

use App\Domain\Cardapio\ItemCardapio;
use App\Domain\Cardapio\Produto;
use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CardapioTest extends TestCase
{
    use RefreshDatabase;

    private function gerente(Unidade $unidade): Usuario
    {
        return Usuario::factory()->create(['perfil' => Perfil::GERENTE, 'unidade_id' => $unidade->id]);
    }

    private function item(Unidade $unidade, array $produto = [], array $item = []): ItemCardapio
    {
        return ItemCardapio::factory()->create($item + [
            'unidade_id' => $unidade->id,
            'produto_id' => Produto::factory()->create($produto)->id,
        ]);
    }

    // ---------- consulta publica (RF11) ----------

    public function test_cardapio_e_publico_e_mostra_preco_da_unidade(): void
    {
        $unidade = Unidade::factory()->create();
        $this->item($unidade, ['nome' => 'Tapioca', 'categoria' => 'Lanches'], ['preco' => '12.50']);

        $r = $this->getJson("/unidades/{$unidade->id}/cardapio");

        $r->assertOk()->assertJsonCount(1, 'data');
        $r->assertJsonPath('data.0.nome', 'Tapioca');
        $r->assertJsonPath('data.0.preco', 12.5);
        $r->assertJsonPath('data.0.categoria', 'Lanches');
    }

    public function test_mesmo_produto_pode_ter_preco_diferente_em_cada_unidade(): void
    {
        $produto = Produto::factory()->create();
        $a = Unidade::factory()->create();
        $b = Unidade::factory()->create();
        ItemCardapio::factory()->create(['unidade_id' => $a->id, 'produto_id' => $produto->id, 'preco' => '10.00']);
        ItemCardapio::factory()->create(['unidade_id' => $b->id, 'produto_id' => $produto->id, 'preco' => '15.00']);

        $this->getJson("/unidades/{$a->id}/cardapio")->assertJsonPath('data.0.preco', 10);
        $this->getJson("/unidades/{$b->id}/cardapio")->assertJsonPath('data.0.preco', 15);
    }

    public function test_nao_mostra_item_indisponivel_nem_produto_inativo_nem_item_de_outra_unidade(): void
    {
        $unidade = Unidade::factory()->create();
        $this->item($unidade, ['nome' => 'Visivel']);
        $this->item($unidade, ['nome' => 'Indisponivel'], ['disponivel' => false]);
        $this->item($unidade, ['nome' => 'Inativo', 'ativo' => false]);
        $this->item(Unidade::factory()->create(), ['nome' => 'De outra']);

        $r = $this->getJson("/unidades/{$unidade->id}/cardapio");

        $r->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome', 'Visivel');
    }

    public function test_item_sazonal_so_aparece_dentro_do_periodo(): void
    {
        $unidade = Unidade::factory()->create();
        $this->item($unidade, ['nome' => 'Canjica'], ['inicio_sazonal' => '05-15', 'fim_sazonal' => '07-15']);

        $this->getJson("/unidades/{$unidade->id}/cardapio?data=2026-06-20")->assertJsonCount(1, 'data');
        $this->getJson("/unidades/{$unidade->id}/cardapio?data=2026-05-15")->assertJsonCount(1, 'data');
        $this->getJson("/unidades/{$unidade->id}/cardapio?data=2026-07-15")->assertJsonCount(1, 'data');
        $this->getJson("/unidades/{$unidade->id}/cardapio?data=2026-05-14")->assertJsonCount(0, 'data');
        $this->getJson("/unidades/{$unidade->id}/cardapio?data=2026-07-16")->assertJsonCount(0, 'data');
        // repete todo ano
        $this->getJson("/unidades/{$unidade->id}/cardapio?data=2031-06-20")->assertJsonCount(1, 'data');
    }

    public function test_sem_parametro_data_usa_o_dia_de_hoje(): void
    {
        $unidade = Unidade::factory()->create();
        $this->item($unidade, [], ['inicio_sazonal' => '05-15', 'fim_sazonal' => '07-15']);

        Carbon::setTestNow('2026-06-20 12:00');
        $this->getJson("/unidades/{$unidade->id}/cardapio")->assertJsonCount(1, 'data');

        Carbon::setTestNow('2026-11-07 12:00');
        $this->getJson("/unidades/{$unidade->id}/cardapio")->assertJsonCount(0, 'data');

        Carbon::setTestNow();
    }

    public function test_descricao_regional_substitui_a_do_catalogo(): void
    {
        $unidade = Unidade::factory()->create();
        $this->item($unidade, ['descricao' => 'Descricao padrao'], ['descricao_regional' => 'Versao cearense']);
        $outra = Unidade::factory()->create();
        $this->item($outra, ['descricao' => 'Descricao padrao']);

        $this->getJson("/unidades/{$unidade->id}/cardapio")->assertJsonPath('data.0.descricao', 'Versao cearense');
        $this->getJson("/unidades/{$outra->id}/cardapio")->assertJsonPath('data.0.descricao', 'Descricao padrao');
    }

    public function test_cardapio_pagina_e_valida_a_data(): void
    {
        $unidade = Unidade::factory()->create();
        foreach (range(1, 3) as $i) {
            $this->item($unidade);
        }

        $this->getJson("/unidades/{$unidade->id}/cardapio?limit=2&page=2")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson("/unidades/{$unidade->id}/cardapio?data=amanha")->assertStatus(422)->assertJsonPath('error', 'VALIDACAO');
    }

    public function test_cardapio_de_unidade_inexistente_ou_inativa_da_404(): void
    {
        $inativa = Unidade::factory()->inativa()->create();

        $this->getJson('/unidades/999/cardapio')->assertStatus(404)->assertJsonPath('error', 'UNIDADE_NAO_ENCONTRADA');
        $this->getJson("/unidades/{$inativa->id}/cardapio")->assertStatus(404)->assertJsonPath('error', 'UNIDADE_NAO_ENCONTRADA');
    }

    // ---------- montar o cardapio (RF10) ----------

    public function test_gerente_adiciona_produto_ao_cardapio_e_depois_atualiza(): void
    {
        $unidade = Unidade::factory()->create();
        $produto = Produto::factory()->create();
        $gerente = $this->gerente($unidade);

        $r = $this->comoUsuario($gerente)->putJson("/unidades/{$unidade->id}/cardapio/{$produto->id}", [
            'preco' => 15.9,
            'inicioSazonal' => '06-01',
            'fimSazonal' => '06-30',
            'descricaoRegional' => 'Receita da casa',
        ]);

        $r->assertStatus(201)
            ->assertJsonPath('data.preco', 15.9)
            ->assertJsonPath('data.disponivel', true)
            ->assertJsonPath('data.inicioSazonal', '06-01')
            ->assertJsonPath('data.descricao', 'Receita da casa');

        $r = $this->comoUsuario($gerente)->putJson("/unidades/{$unidade->id}/cardapio/{$produto->id}", [
            'preco' => 18,
            'disponivel' => false,
        ]);

        $r->assertOk()->assertJsonPath('data.preco', 18)->assertJsonPath('data.disponivel', false);
        $this->assertSame(1, ItemCardapio::where('unidade_id', $unidade->id)->count());
        // o PUT substitui: o periodo e a descricao regional que nao foram enviados ficam vazios
        $item = ItemCardapio::first();
        $this->assertNull($item->inicio_sazonal);
        $this->assertNull($item->descricao_regional);
    }

    public function test_so_o_gerente_da_unidade_monta_o_cardapio(): void
    {
        $unidade = Unidade::factory()->create();
        $outra = Unidade::factory()->create();
        $produto = Produto::factory()->create();
        $url = "/unidades/{$unidade->id}/cardapio/{$produto->id}";

        // gerente de outra unidade
        $this->comoUsuario($this->gerente($outra))->putJson($url, ['preco' => 10])
            ->assertStatus(403)->assertJsonPath('error', 'ACESSO_NEGADO');

        foreach ([Perfil::ADMIN, Perfil::ATENDENTE, Perfil::COZINHA, Perfil::CLIENTE] as $perfil) {
            $usuario = Usuario::factory()->create(['perfil' => $perfil, 'unidade_id' => $unidade->id]);
            $this->comoUsuario($usuario)->putJson($url, ['preco' => 10])->assertStatus(403);
        }

        $this->assertSame(0, ItemCardapio::count());
    }

    public function test_sem_token_da_401_ao_montar_cardapio(): void
    {
        $unidade = Unidade::factory()->create();
        $produto = Produto::factory()->create();

        $this->putJson("/unidades/{$unidade->id}/cardapio/{$produto->id}", ['preco' => 10])->assertStatus(401);
    }

    public function test_validacoes_do_item(): void
    {
        $unidade = Unidade::factory()->create();
        $produto = Produto::factory()->create();
        $gerente = $this->gerente($unidade);

        $casos = [
            'sem preco' => [[], 'preco'],
            'preco zero' => [['preco' => 0], 'preco'],
            'preco negativo' => [['preco' => -5], 'preco'],
            'preco texto' => [['preco' => 'barato'], 'preco'],
            'so inicio' => [['preco' => 10, 'inicioSazonal' => '06-01'], 'fimSazonal'],
            'so fim' => [['preco' => 10, 'fimSazonal' => '06-30'], 'inicioSazonal'],
            'mes 13' => [['preco' => 10, 'inicioSazonal' => '13-01', 'fimSazonal' => '06-30'], 'inicioSazonal'],
            'formato errado' => [['preco' => 10, 'inicioSazonal' => '6/1', 'fimSazonal' => '06-30'], 'inicioSazonal'],
            '31 de fevereiro' => [['preco' => 10, 'inicioSazonal' => '02-31', 'fimSazonal' => '06-30'], 'inicioSazonal'],
            'disponivel invalido' => [['preco' => 10, 'disponivel' => 'sim'], 'disponivel'],
        ];

        foreach ($casos as $nome => [$corpo, $campo]) {
            $r = $this->comoUsuario($gerente)->putJson("/unidades/{$unidade->id}/cardapio/{$produto->id}", $corpo);

            $r->assertStatus(422);
            $this->assertContains($campo, array_column($r->json('details'), 'campo'), $nome);
        }
    }

    public function test_aceita_29_de_fevereiro_e_periodo_que_vira_o_ano(): void
    {
        $unidade = Unidade::factory()->create();
        $produto = Produto::factory()->create();

        $this->comoUsuario($this->gerente($unidade))
            ->putJson("/unidades/{$unidade->id}/cardapio/{$produto->id}", [
                'preco' => 10, 'inicioSazonal' => '12-01', 'fimSazonal' => '02-29',
            ])->assertStatus(201);
    }

    public function test_produto_inexistente_ou_inativo_da_404(): void
    {
        $unidade = Unidade::factory()->create();
        $inativo = Produto::factory()->inativo()->create();
        $gerente = $this->gerente($unidade);

        $this->comoUsuario($gerente)->putJson("/unidades/{$unidade->id}/cardapio/999", ['preco' => 10])
            ->assertStatus(404)->assertJsonPath('error', 'PRODUTO_NAO_ENCONTRADO');
        $this->comoUsuario($gerente)->putJson("/unidades/{$unidade->id}/cardapio/{$inativo->id}", ['preco' => 10])
            ->assertStatus(404)->assertJsonPath('error', 'PRODUTO_NAO_ENCONTRADO');
    }

    public function test_unidade_inexistente_da_404_ao_montar(): void
    {
        $produto = Produto::factory()->create();
        $unidade = Unidade::factory()->create();

        $this->comoUsuario($this->gerente($unidade))->putJson("/unidades/999/cardapio/{$produto->id}", ['preco' => 10])
            ->assertStatus(404)->assertJsonPath('error', 'UNIDADE_NAO_ENCONTRADA');
    }

    // ---------- RN17 ----------

    public function test_produto_que_exige_cozinha_completa_nao_entra_em_unidade_reduzida(): void
    {
        $reduzida = Unidade::factory()->reduzida()->create();
        $completa = Unidade::factory()->create();
        $produto = Produto::factory()->exigeCozinhaCompleta()->create();

        $r = $this->comoUsuario($this->gerente($reduzida))
            ->putJson("/unidades/{$reduzida->id}/cardapio/{$produto->id}", ['preco' => 30]);

        $r->assertStatus(422)->assertJsonPath('error', 'PRODUTO_INCOMPATIVEL_COM_UNIDADE');
        $r->assertJsonPath('details.0.produtoId', $produto->id);
        $this->assertSame(0, ItemCardapio::count());

        $this->comoUsuario($this->gerente($completa))
            ->putJson("/unidades/{$completa->id}/cardapio/{$produto->id}", ['preco' => 30])->assertStatus(201);
    }

    public function test_unidade_nao_vira_reduzida_com_produto_de_cozinha_completa_no_cardapio(): void
    {
        $unidade = Unidade::factory()->create();
        $this->item($unidade, ['nome' => 'Baiao', 'exige_cozinha_completa' => true]);
        $admin = Usuario::factory()->create(['perfil' => Perfil::ADMIN]);

        $r = $this->comoUsuario($admin)->putJson("/unidades/{$unidade->id}", ['formato' => 'REDUZIDA']);

        $r->assertStatus(422)->assertJsonPath('error', 'PRODUTO_INCOMPATIVEL_COM_UNIDADE');
        $r->assertJsonPath('details.0.nome', 'Baiao');
        $this->assertSame('COMPLETA', $unidade->fresh()->formato->value);
    }

    public function test_unidade_vira_reduzida_se_o_cardapio_nao_tem_produto_de_cozinha_completa(): void
    {
        $unidade = Unidade::factory()->create();
        $this->item($unidade);
        $admin = Usuario::factory()->create(['perfil' => Perfil::ADMIN]);

        $this->comoUsuario($admin)->putJson("/unidades/{$unidade->id}", ['formato' => 'REDUZIDA'])->assertOk();
    }
}
