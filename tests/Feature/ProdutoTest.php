<?php

namespace Tests\Feature;

use App\Domain\Cardapio\ItemCardapio;
use App\Domain\Cardapio\Produto;
use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProdutoTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(Perfil $perfil): Usuario
    {
        return Usuario::factory()->create(['perfil' => $perfil]);
    }

    public function test_admin_cadastra_produto(): void
    {
        $r = $this->comoUsuario($this->usuario(Perfil::ADMIN))->postJson('/produtos', [
            'nome' => 'Tapioca',
            'descricao' => 'Com coco',
            'categoria' => 'Lanches',
            'exigeCozinhaCompleta' => true,
        ]);

        $r->assertStatus(201)
            ->assertJsonPath('data.nome', 'Tapioca')
            ->assertJsonPath('data.exigeCozinhaCompleta', true)
            ->assertJsonPath('data.ativo', true);
        $this->assertDatabaseHas('produtos', ['nome' => 'Tapioca', 'exige_cozinha_completa' => true]);
    }

    public function test_cadastro_valida_campos_obrigatorios(): void
    {
        $r = $this->comoUsuario($this->usuario(Perfil::ADMIN))->postJson('/produtos', ['exigeCozinhaCompleta' => 'talvez']);

        $r->assertStatus(422)->assertJsonPath('error', 'VALIDACAO');
        $campos = array_column($r->json('details'), 'campo');
        $this->assertContains('nome', $campos);
        $this->assertContains('categoria', $campos);
        $this->assertContains('exigeCozinhaCompleta', $campos);
    }

    public function test_so_admin_cadastra_e_edita(): void
    {
        $produto = Produto::factory()->create();
        $corpo = ['nome' => 'X', 'categoria' => 'Y'];

        foreach ([Perfil::GERENTE, Perfil::ATENDENTE, Perfil::COZINHA, Perfil::CLIENTE] as $perfil) {
            $this->comoUsuario($this->usuario($perfil))->postJson('/produtos', $corpo)->assertStatus(403);
            $this->comoUsuario($this->usuario($perfil))->putJson("/produtos/{$produto->id}", $corpo)->assertStatus(403);
        }
    }

    public function test_sem_token_da_401(): void
    {
        $this->getJson('/produtos')->assertStatus(401);
    }

    public function test_admin_e_gerente_listam_e_consultam(): void
    {
        $produto = Produto::factory()->create();

        foreach ([Perfil::ADMIN, Perfil::GERENTE] as $perfil) {
            $usuario = $this->usuario($perfil);
            $this->comoUsuario($usuario)->getJson('/produtos')->assertOk()->assertJsonCount(1, 'data');
            $this->comoUsuario($usuario)->getJson("/produtos/{$produto->id}")->assertOk()->assertJsonPath('data.id', $produto->id);
        }
    }

    public function test_atendente_e_cliente_nao_listam(): void
    {
        foreach ([Perfil::ATENDENTE, Perfil::COZINHA, Perfil::CLIENTE] as $perfil) {
            $this->comoUsuario($this->usuario($perfil))->getJson('/produtos')->assertStatus(403);
        }
    }

    public function test_lista_pagina(): void
    {
        Produto::factory()->count(3)->create();

        $r = $this->comoUsuario($this->usuario(Perfil::ADMIN))->getJson('/produtos?limit=2&page=2');

        $r->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
    }

    public function test_produto_inexistente_da_404(): void
    {
        $admin = $this->usuario(Perfil::ADMIN);

        $this->comoUsuario($admin)->getJson('/produtos/999')->assertStatus(404)->assertJsonPath('error', 'PRODUTO_NAO_ENCONTRADO');
        $this->comoUsuario($admin)->putJson('/produtos/999', ['nome' => 'X', 'categoria' => 'Y'])
            ->assertStatus(404)->assertJsonPath('error', 'PRODUTO_NAO_ENCONTRADO');
    }

    public function test_admin_edita_e_desativa(): void
    {
        $produto = Produto::factory()->create(['nome' => 'Velho']);

        $r = $this->comoUsuario($this->usuario(Perfil::ADMIN))
            ->putJson("/produtos/{$produto->id}", ['nome' => 'Novo', 'categoria' => 'Lanches', 'ativo' => false]);

        $r->assertOk()->assertJsonPath('data.nome', 'Novo')->assertJsonPath('data.ativo', false);
        $this->assertFalse($produto->fresh()->ativo);
    }

    public function test_nao_passa_a_exigir_cozinha_completa_se_esta_em_unidade_reduzida(): void
    {
        $produto = Produto::factory()->create();
        $reduzida = Unidade::factory()->reduzida()->create();
        ItemCardapio::factory()->create(['unidade_id' => $reduzida->id, 'produto_id' => $produto->id]);

        $r = $this->comoUsuario($this->usuario(Perfil::ADMIN))->putJson("/produtos/{$produto->id}", [
            'nome' => $produto->nome,
            'categoria' => $produto->categoria,
            'exigeCozinhaCompleta' => true,
        ]);

        $r->assertStatus(422)->assertJsonPath('error', 'PRODUTO_INCOMPATIVEL_COM_UNIDADE');
        $r->assertJsonPath('details.0.unidadeId', $reduzida->id);
        $this->assertFalse($produto->fresh()->exige_cozinha_completa);
    }

    public function test_passa_a_exigir_cozinha_completa_se_so_esta_em_unidade_completa(): void
    {
        $produto = Produto::factory()->create();
        ItemCardapio::factory()->create(['unidade_id' => Unidade::factory()->create()->id, 'produto_id' => $produto->id]);

        $this->comoUsuario($this->usuario(Perfil::ADMIN))->putJson("/produtos/{$produto->id}", [
            'nome' => $produto->nome,
            'categoria' => $produto->categoria,
            'exigeCozinhaCompleta' => true,
        ])->assertOk();
    }
}
