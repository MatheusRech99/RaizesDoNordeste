<?php

namespace Tests\Feature;

use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MensagensValidacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_regra_sem_mensagem_propria_sai_em_portugues(): void
    {
        $cliente = Usuario::factory()->create(['perfil' => Perfil::CLIENTE]);

        // page e limit nao tem mensagem customizada: vem do lang/pt_BR
        $r = $this->comoUsuario($cliente)->getJson('/pedidos?page=abc&limit=0');

        $r->assertStatus(422);

        $textos = collect($r->json('details'))->pluck('mensagem')->implode(' | ');

        $this->assertStringContainsString('deve ser um número inteiro', $textos);
        $this->assertStringNotContainsString('must be', $textos);
        $this->assertStringNotContainsString('field', $textos);
    }

    public function test_campo_obrigatorio_usa_o_nome_amigavel(): void
    {
        $r = $this->postJson('/auth/login', []);

        $r->assertStatus(422);
        $this->assertStringNotContainsString('field is required', $r->getContent());
    }
}
