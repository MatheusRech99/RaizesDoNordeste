<?php

namespace Tests\Feature;

use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnidadeTest extends TestCase
{
    use RefreshDatabase;

    private function unidadeComHorario(): Unidade
    {
        $unidade = Unidade::factory()->create();
        $unidade->horarios()->create(['dia_semana' => 1, 'abre' => '10:00:00', 'fecha' => '22:00:00']);

        return $unidade;
    }

    private function usuario(Perfil $perfil, ?Unidade $unidade = null): Usuario
    {
        return Usuario::factory()->create(['perfil' => $perfil, 'unidade_id' => $unidade?->id]);
    }

    public function test_lista_e_publica_e_so_traz_unidades_ativas(): void
    {
        $this->unidadeComHorario();
        Unidade::factory()->inativa()->create();

        $r = $this->getJson('/unidades');

        $r->assertOk()->assertJsonCount(1, 'data');
        $r->assertJsonPath('data.0.horarios.0.abre', '10:00');
        $r->assertJsonPath('data.0.horarios.0.fecha', '22:00');
        $r->assertJsonPath('data.0.formato', 'COMPLETA');
    }

    public function test_lista_pagina_com_page_e_limit(): void
    {
        Unidade::factory()->count(5)->create();

        $r = $this->getJson('/unidades?limit=2&page=3');

        $r->assertOk()->assertJsonCount(1, 'data');
        $r->assertJsonPath('meta.total', 5);
        $r->assertJsonPath('meta.per_page', 2);
        $r->assertJsonPath('meta.current_page', 3);
    }

    public function test_limit_acima_de_100_da_422(): void
    {
        $this->getJson('/unidades?limit=101')->assertStatus(422)->assertJsonPath('error', 'VALIDACAO');
    }

    public function test_consulta_uma_unidade(): void
    {
        $unidade = $this->unidadeComHorario();

        $this->getJson("/unidades/{$unidade->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $unidade->id)
            ->assertJsonPath('data.horarios.0.diaSemana', 1);
    }

    public function test_unidade_inexistente_ou_inativa_da_404(): void
    {
        $inativa = Unidade::factory()->inativa()->create();

        $this->getJson('/unidades/9999')->assertStatus(404)->assertJsonPath('error', 'UNIDADE_NAO_ENCONTRADA');
        $this->getJson("/unidades/{$inativa->id}")->assertStatus(404)->assertJsonPath('error', 'UNIDADE_NAO_ENCONTRADA');
    }

    public function test_admin_muda_formato_e_horarios(): void
    {
        $unidade = $this->unidadeComHorario();

        $r = $this->comoUsuario($this->usuario(Perfil::ADMIN))->putJson("/unidades/{$unidade->id}", [
            'formato' => 'REDUZIDA',
            'horarios' => [
                ['diaSemana' => 2, 'abre' => '11:00', 'fecha' => '20:30'],
                ['diaSemana' => 3, 'abre' => '11:00', 'fecha' => '20:30'],
            ],
        ]);

        $r->assertOk()->assertJsonPath('data.formato', 'REDUZIDA');
        $r->assertJsonCount(2, 'data.horarios');
        $r->assertJsonPath('data.horarios.0.diaSemana', 2);
        $r->assertJsonPath('data.horarios.0.fecha', '20:30');

        // o horario antigo (segunda) foi substituido
        $this->assertDatabaseMissing('horarios_funcionamento', ['unidade_id' => $unidade->id, 'dia_semana' => 1]);
        $this->assertSame('REDUZIDA', $unidade->fresh()->formato->value);
    }

    public function test_pode_mandar_so_o_formato_e_os_horarios_ficam(): void
    {
        $unidade = $this->unidadeComHorario();

        $r = $this->comoUsuario($this->usuario(Perfil::ADMIN))
            ->putJson("/unidades/{$unidade->id}", ['formato' => 'REDUZIDA']);

        $r->assertOk()->assertJsonCount(1, 'data.horarios');
    }

    public function test_gerente_altera_a_propria_unidade(): void
    {
        $unidade = $this->unidadeComHorario();

        $this->comoUsuario($this->usuario(Perfil::GERENTE, $unidade))
            ->putJson("/unidades/{$unidade->id}", ['formato' => 'REDUZIDA'])
            ->assertOk();
    }

    public function test_gerente_nao_altera_unidade_de_outro(): void
    {
        $minha = Unidade::factory()->create();
        $outra = Unidade::factory()->create();

        $this->comoUsuario($this->usuario(Perfil::GERENTE, $minha))
            ->putJson("/unidades/{$outra->id}", ['formato' => 'REDUZIDA'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'ACESSO_NEGADO');

        $this->assertSame('COMPLETA', $outra->fresh()->formato->value);
    }

    public function test_outros_perfis_levam_403_e_sem_token_401(): void
    {
        $unidade = Unidade::factory()->create();

        foreach ([Perfil::ATENDENTE, Perfil::COZINHA, Perfil::CLIENTE] as $perfil) {
            $this->comoUsuario($this->usuario($perfil, $unidade))
                ->putJson("/unidades/{$unidade->id}", ['formato' => 'REDUZIDA'])
                ->assertStatus(403);
        }

        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->flushHeaders();
        $this->putJson("/unidades/{$unidade->id}", ['formato' => 'REDUZIDA'])->assertStatus(401);
    }

    public function test_update_valida_o_corpo(): void
    {
        $unidade = Unidade::factory()->create();
        $admin = $this->usuario(Perfil::ADMIN);

        $casos = [
            'corpo vazio' => [[], 'formato'],
            'formato invalido' => [['formato' => 'GRANDE'], 'formato'],
            'dia 7' => [['horarios' => [['diaSemana' => 7, 'abre' => '10:00', 'fecha' => '20:00']]], 'horarios.0.diaSemana'],
            'dia repetido' => [['horarios' => [
                ['diaSemana' => 1, 'abre' => '10:00', 'fecha' => '20:00'],
                ['diaSemana' => 1, 'abre' => '11:00', 'fecha' => '21:00'],
            ]], 'horarios.0.diaSemana'],
            'fecha antes de abrir' => [['horarios' => [['diaSemana' => 1, 'abre' => '20:00', 'fecha' => '10:00']]], 'horarios.0.fecha'],
            'fecha igual a abre' => [['horarios' => [['diaSemana' => 1, 'abre' => '10:00', 'fecha' => '10:00']]], 'horarios.0.fecha'],
            'hora mal formatada' => [['horarios' => [['diaSemana' => 1, 'abre' => '10h', 'fecha' => '20:00']]], 'horarios.0.abre'],
        ];

        foreach ($casos as $nome => [$corpo, $campo]) {
            $r = $this->comoUsuario($admin)->putJson("/unidades/{$unidade->id}", $corpo);

            $r->assertStatus(422);
            $this->assertContains($campo, array_column($r->json('details'), 'campo'), $nome);
        }
    }

    public function test_update_em_unidade_inexistente_da_404(): void
    {
        $this->comoUsuario($this->usuario(Perfil::ADMIN))
            ->putJson('/unidades/9999', ['formato' => 'REDUZIDA'])
            ->assertStatus(404)
            ->assertJsonPath('error', 'UNIDADE_NAO_ENCONTRADA');
    }
}
