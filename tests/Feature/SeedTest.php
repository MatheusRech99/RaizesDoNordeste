<?php

namespace Tests\Feature;

use App\Domain\Cardapio\ItemCardapio;
use App\Domain\Cardapio\Produto;
use App\Domain\Unidades\FormatoUnidade;
use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_cria_unidades_e_usuarios_de_todos_os_perfis(): void
    {
        $this->seed();

        $this->assertSame(3, Unidade::count());
        foreach (Perfil::cases() as $perfil) {
            $this->assertTrue(Usuario::where('perfil', $perfil)->exists(), $perfil->value);
        }
    }

    public function test_usuario_interno_tem_unidade_e_admin_e_cliente_nao(): void
    {
        $this->seed();

        $this->assertSame(0, Usuario::whereIn('perfil', [Perfil::GERENTE, Perfil::ATENDENTE, Perfil::COZINHA])->whereNull('unidade_id')->count());
        $this->assertSame(0, Usuario::whereIn('perfil', [Perfil::ADMIN, Perfil::CLIENTE])->whereNotNull('unidade_id')->count());
    }

    public function test_usuario_do_seed_consegue_logar(): void
    {
        $this->seed();

        $this->postJson('/auth/login', ['email' => 'admin@raizes.com', 'senha' => 'senha123'])->assertOk();
    }

    public function test_seed_monta_cardapio_sem_produto_de_cozinha_completa_em_unidade_reduzida(): void
    {
        $this->seed();

        $this->assertGreaterThan(0, Produto::count());
        $this->assertGreaterThan(0, ItemCardapio::count());

        $incompativeis = ItemCardapio::whereHas('unidade', fn ($q) => $q->where('formato', FormatoUnidade::REDUZIDA))
            ->whereHas('produto', fn ($q) => $q->where('exige_cozinha_completa', true))
            ->count();
        $this->assertSame(0, $incompativeis);
    }
}
