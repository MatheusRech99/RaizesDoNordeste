<?php

namespace Tests\Feature;

use App\Domain\Pedidos\CanalPedido;
use App\Domain\Pedidos\HistoricoStatus;
use App\Domain\Pedidos\Pedido;
use App\Domain\Pedidos\StatusPedido;
use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsultaPedidosTest extends TestCase
{
    use RefreshDatabase;

    private Unidade $u1;
    private Unidade $u2;
    private Usuario $ana;
    private Usuario $bia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->u1 = Unidade::factory()->create();
        $this->u2 = Unidade::factory()->create();
        $this->ana = Usuario::factory()->create(['perfil' => Perfil::CLIENTE]);
        $this->bia = Usuario::factory()->create(['perfil' => Perfil::CLIENTE]);
    }

    private function pedido(Unidade $u, ?Usuario $cliente, array $extra = []): Pedido
    {
        return Pedido::factory()->create($extra + [
            'unidade_id' => $u->id,
            'cliente_id' => $cliente?->id,
            'criado_por' => $cliente?->id ?? $this->ana->id,
        ]);
    }

    private function equipe(Perfil $perfil, Unidade $u): Usuario
    {
        return Usuario::factory()->create(['perfil' => $perfil, 'unidade_id' => $u->id]);
    }

    private function admin(): Usuario
    {
        return Usuario::factory()->create(['perfil' => Perfil::ADMIN]);
    }

    private function ids($r): array
    {
        return collect($r->json('data'))->pluck('id')->sort()->values()->all();
    }

    // ---------- listagem ----------

    public function test_exige_autenticacao(): void
    {
        $this->getJson('/pedidos')->assertStatus(401)->assertJsonPath('error', 'NAO_AUTENTICADO');
    }

    public function test_cliente_so_ve_os_proprios_pedidos(): void
    {
        $a1 = $this->pedido($this->u1, $this->ana);
        $a2 = $this->pedido($this->u2, $this->ana);
        $this->pedido($this->u1, $this->bia);
        $this->pedido($this->u1, null, ['canal_pedido' => CanalPedido::BALCAO]);

        $r = $this->comoUsuario($this->ana)->getJson('/pedidos')->assertOk();

        $this->assertSame([$a1->id, $a2->id], $this->ids($r));
    }

    public function test_cliente_ve_pedido_que_o_atendente_abriu_para_ele(): void
    {
        $atendente = $this->equipe(Perfil::ATENDENTE, $this->u1);
        $p = $this->pedido($this->u1, $this->ana, ['criado_por' => $atendente->id, 'canal_pedido' => CanalPedido::WEB]);

        $this->assertSame([$p->id], $this->ids($this->comoUsuario($this->ana)->getJson('/pedidos')));
    }

    public function test_equipe_so_ve_pedidos_da_propria_unidade(): void
    {
        $p1 = $this->pedido($this->u1, $this->ana);
        $this->pedido($this->u2, $this->ana);

        foreach ([Perfil::GERENTE, Perfil::ATENDENTE, Perfil::COZINHA] as $perfil) {
            $r = $this->comoUsuario($this->equipe($perfil, $this->u1))->getJson('/pedidos')->assertOk();
            $this->assertSame([$p1->id], $this->ids($r), $perfil->value);
        }
    }

    public function test_admin_ve_a_rede_inteira(): void
    {
        $p1 = $this->pedido($this->u1, $this->ana);
        $p2 = $this->pedido($this->u2, $this->bia);

        $this->assertSame([$p1->id, $p2->id], $this->ids($this->comoUsuario($this->admin())->getJson('/pedidos')));
    }

    public function test_filtra_por_canal_status_e_unidade(): void
    {
        $app = $this->pedido($this->u1, $this->ana, ['canal_pedido' => CanalPedido::APP]);
        $totem = $this->pedido($this->u1, $this->ana, ['canal_pedido' => CanalPedido::TOTEM, 'status' => StatusPedido::PAGO]);
        $outra = $this->pedido($this->u2, $this->ana, ['canal_pedido' => CanalPedido::TOTEM]);
        $admin = $this->comoUsuario($this->admin());

        $this->assertSame([$totem->id, $outra->id], $this->ids($admin->getJson('/pedidos?canalPedido=TOTEM')));
        $this->assertSame([$totem->id], $this->ids($admin->getJson('/pedidos?status=PAGO')));
        $this->assertSame([$app->id, $totem->id], $this->ids($admin->getJson('/pedidos?unidadeId='.$this->u1->id)));
        $this->assertSame([$totem->id], $this->ids($admin->getJson('/pedidos?unidadeId='.$this->u1->id.'&canalPedido=TOTEM')));
    }

    public function test_cliente_filtra_os_proprios_por_unidade(): void
    {
        $a1 = $this->pedido($this->u1, $this->ana);
        $this->pedido($this->u2, $this->ana);
        $this->pedido($this->u1, $this->bia);

        $r = $this->comoUsuario($this->ana)->getJson('/pedidos?unidadeId='.$this->u1->id);

        $this->assertSame([$a1->id], $this->ids($r));
    }

    public function test_equipe_filtrando_outra_unidade_leva_403(): void
    {
        $this->pedido($this->u2, $this->ana);

        $this->comoUsuario($this->equipe(Perfil::GERENTE, $this->u1))
            ->getJson('/pedidos?unidadeId='.$this->u2->id)
            ->assertStatus(403)->assertJsonPath('error', 'ACESSO_NEGADO');
    }

    public function test_equipe_filtrando_a_propria_unidade_funciona(): void
    {
        $p = $this->pedido($this->u1, $this->ana);

        $r = $this->comoUsuario($this->equipe(Perfil::GERENTE, $this->u1))->getJson('/pedidos?unidadeId='.$this->u1->id);

        $this->assertSame([$p->id], $this->ids($r));
    }

    public function test_filtros_invalidos_dao_422(): void
    {
        $admin = $this->comoUsuario($this->admin());

        $admin->getJson('/pedidos?canalPedido=TELEGRAMA')->assertStatus(422)->assertJsonPath('details.0.campo', 'canalPedido');
        $admin->getJson('/pedidos?status=SUMIU')->assertStatus(422)->assertJsonPath('details.0.campo', 'status');
        $admin->getJson('/pedidos?unidadeId=abc')->assertStatus(422)->assertJsonPath('details.0.campo', 'unidadeId');
        $admin->getJson('/pedidos?limit=1000')->assertStatus(422)->assertJsonPath('details.0.campo', 'limit');
    }

    public function test_paginacao_e_do_mais_novo_para_o_mais_antigo(): void
    {
        $ps = collect(range(1, 5))->map(fn () => $this->pedido($this->u1, $this->ana));

        $r = $this->comoUsuario($this->ana)->getJson('/pedidos?limit=2&page=2')->assertOk();

        $this->assertSame([$ps[2]->id, $ps[1]->id], collect($r->json('data'))->pluck('id')->all());
        $r->assertJsonPath('meta.total', 5)->assertJsonPath('meta.per_page', 2)->assertJsonPath('meta.current_page', 2);
    }

    public function test_listagem_traz_itens_mas_nao_historico(): void
    {
        $this->pedido($this->u1, $this->ana);

        $r = $this->comoUsuario($this->ana)->getJson('/pedidos')->assertOk();

        $this->assertArrayHasKey('itens', $r->json('data.0'));
        $this->assertArrayNotHasKey('historico', $r->json('data.0'));
    }

    // ---------- detalhe ----------

    public function test_cliente_consulta_pedido_com_itens_e_historico(): void
    {
        $p = $this->pedido($this->u1, $this->ana);
        HistoricoStatus::create(['pedido_id' => $p->id, 'status_anterior' => null, 'status_novo' => StatusPedido::AGUARDANDO_PAGAMENTO, 'usuario_id' => $this->ana->id]);
        HistoricoStatus::create(['pedido_id' => $p->id, 'status_anterior' => StatusPedido::AGUARDANDO_PAGAMENTO, 'status_novo' => StatusPedido::PAGO, 'usuario_id' => $this->ana->id]);

        $r = $this->comoUsuario($this->ana)->getJson('/pedidos/'.$p->id)->assertOk();

        $r->assertJsonPath('data.id', $p->id)
            ->assertJsonPath('data.total', 25)
            ->assertJsonCount(2, 'data.historico')
            ->assertJsonPath('data.historico.0.statusAnterior', null)
            ->assertJsonPath('data.historico.0.statusNovo', 'AGUARDANDO_PAGAMENTO')
            ->assertJsonPath('data.historico.1.statusAnterior', 'AGUARDANDO_PAGAMENTO')
            ->assertJsonPath('data.historico.1.statusNovo', 'PAGO');
    }

    public function test_pedido_de_outro_cliente_da_404_sem_revelar(): void
    {
        $p = $this->pedido($this->u1, $this->bia);

        $this->comoUsuario($this->ana)->getJson('/pedidos/'.$p->id)
            ->assertStatus(404)->assertJsonPath('error', 'NAO_ENCONTRADO');
    }

    public function test_pedido_inexistente_da_o_mesmo_404(): void
    {
        $this->comoUsuario($this->ana)->getJson('/pedidos/99999')
            ->assertStatus(404)->assertJsonPath('error', 'NAO_ENCONTRADO');
    }

    public function test_pedido_balcao_anonimo_nao_e_de_nenhum_cliente(): void
    {
        $p = $this->pedido($this->u1, null, ['canal_pedido' => CanalPedido::BALCAO]);

        $this->comoUsuario($this->ana)->getJson('/pedidos/'.$p->id)->assertStatus(404);
        $this->comoUsuario($this->equipe(Perfil::ATENDENTE, $this->u1))->getJson('/pedidos/'.$p->id)->assertOk();
    }

    public function test_equipe_de_outra_unidade_leva_403(): void
    {
        $p = $this->pedido($this->u1, $this->ana);

        foreach ([Perfil::GERENTE, Perfil::ATENDENTE, Perfil::COZINHA] as $perfil) {
            $this->comoUsuario($this->equipe($perfil, $this->u2))->getJson('/pedidos/'.$p->id)
                ->assertStatus(403)->assertJsonPath('error', 'ACESSO_NEGADO');
        }
    }

    public function test_equipe_da_unidade_e_admin_consultam(): void
    {
        $p = $this->pedido($this->u1, $this->ana);

        foreach ([Perfil::GERENTE, Perfil::ATENDENTE, Perfil::COZINHA] as $perfil) {
            $this->comoUsuario($this->equipe($perfil, $this->u1))->getJson('/pedidos/'.$p->id)->assertOk();
        }
        $this->comoUsuario($this->admin())->getJson('/pedidos/'.$p->id)->assertOk();
    }

    public function test_detalhe_exige_autenticacao_e_id_numerico(): void
    {
        $this->getJson('/pedidos/1')->assertStatus(401);
        $this->comoUsuario($this->admin())->getJson('/pedidos/abc')->assertStatus(404);
    }

    public function test_resposta_nao_vaza_dados_pessoais(): void
    {
        $p = $this->pedido($this->u1, $this->ana);

        $texto = $this->comoUsuario($this->admin())->getJson('/pedidos/'.$p->id)->getContent();

        $this->assertStringNotContainsString('senha', $texto);
        $this->assertStringNotContainsString('dataNascimento', $texto);
        $this->assertStringNotContainsString($this->ana->email, $texto);
    }
}
