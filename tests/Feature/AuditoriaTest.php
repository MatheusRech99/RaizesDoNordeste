<?php

namespace Tests\Feature;

use App\Domain\Auditoria\Acao;
use App\Domain\Auditoria\RegistroAuditoria;
use App\Domain\Usuarios\Finalidade;
use App\Domain\Usuarios\Usuario;
use App\Infrastructure\Auditoria\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class AuditoriaTest extends TestCase
{
    use RefreshDatabase;

    public function test_registra_quem_fez_o_que_com_antes_e_depois(): void
    {
        $usuario = Usuario::factory()->create();
        $alvo = Usuario::factory()->create();
        auth('api')->login($usuario);

        app(AuditLogger::class)->registrar(Acao::REVOGACAO_CONSENTIMENTO, $alvo, ['aceito' => true], ['aceito' => false]);

        $r = RegistroAuditoria::first();
        $this->assertSame($usuario->id, $r->usuario_id);
        $this->assertSame(Acao::REVOGACAO_CONSENTIMENTO, $r->acao);
        $this->assertSame('usuarios', $r->entidade);
        $this->assertSame($alvo->id, $r->entidade_id);
        $this->assertSame(['aceito' => true], $r->dados_antes);
        $this->assertSame(['aceito' => false], $r->dados_depois);
        $this->assertNotNull($r->created_at);
    }

    public function test_usuario_pode_ser_informado_ou_ficar_vazio(): void
    {
        $usuario = Usuario::factory()->create();
        $logger = app(AuditLogger::class);

        $logger->registrar(Acao::CADASTRO_CLIENTE, $usuario);
        $logger->registrar(Acao::CADASTRO_CLIENTE, $usuario, null, null, $usuario->id);

        $this->assertNull(RegistroAuditoria::orderBy('id')->first()->usuario_id);
        $this->assertSame($usuario->id, RegistroAuditoria::orderByDesc('id')->first()->usuario_id);
    }

    public function test_grava_o_ip_da_requisicao(): void
    {
        $this->postJson('/clientes', [
            'nome' => 'Maria', 'email' => 'maria@exemplo.com', 'senha' => 'senha1234', 'aceiteTermos' => true,
        ], ['REMOTE_ADDR' => '203.0.113.9'])->assertCreated();

        $this->assertSame('203.0.113.9', RegistroAuditoria::first()->ip);
    }

    public function test_rollback_da_operacao_leva_a_auditoria_junto(): void
    {
        $usuario = Usuario::factory()->create();

        try {
            DB::transaction(function () use ($usuario) {
                app(AuditLogger::class)->registrar(Acao::CADASTRO_CLIENTE, $usuario);
                throw new LogicException('falhou depois de auditar');
            });
        } catch (LogicException) {
        }

        $this->assertSame(0, RegistroAuditoria::count());
    }

    public function test_model_nao_deixa_alterar_nem_apagar(): void
    {
        $usuario = Usuario::factory()->create();
        $r = app(AuditLogger::class)->registrar(Acao::CADASTRO_CLIENTE, $usuario);

        try {
            $r->update(['acao' => Acao::REVOGACAO_CONSENTIMENTO]);
            $this->fail('deveria barrar o update');
        } catch (LogicException) {
        }

        try {
            $r->delete();
            $this->fail('deveria barrar o delete');
        } catch (LogicException) {
        }

        $this->assertSame(1, RegistroAuditoria::count());
    }

    public function test_banco_barra_update_e_delete_direto(): void
    {
        $usuario = Usuario::factory()->create();
        app(AuditLogger::class)->registrar(Acao::CADASTRO_CLIENTE, $usuario);

        try {
            DB::table('auditoria')->update(['ip' => '1.1.1.1']);
            $this->fail('deveria barrar o update');
        } catch (QueryException $e) {
            $this->assertStringContainsString('auditoria nao pode ser alterada', $e->getMessage());
        }

        try {
            DB::table('auditoria')->delete();
            $this->fail('deveria barrar o delete');
        } catch (QueryException $e) {
            $this->assertStringContainsString('auditoria nao pode ser apagada', $e->getMessage());
        }

        $this->assertSame(1, RegistroAuditoria::count());
    }

    public function test_cadastro_de_cliente_gera_auditoria(): void
    {
        $this->postJson('/clientes', [
            'nome' => 'Maria', 'email' => 'maria@exemplo.com', 'senha' => 'senha1234', 'aceiteTermos' => true,
            'consentimentoFidelidade' => true, 'dataNascimento' => '1990-05-20',
        ])->assertCreated();

        $cliente = Usuario::first();
        $r = RegistroAuditoria::first();
        $this->assertSame(Acao::CADASTRO_CLIENTE, $r->acao);
        $this->assertSame($cliente->id, $r->usuario_id);
        $this->assertSame($cliente->id, $r->entidade_id);
        $this->assertSame(['TERMOS' => true, 'FIDELIDADE' => true, 'MARKETING' => false], $r->dados_depois['consentimentos']);
        $this->assertStringNotContainsString('1990', json_encode($r->getAttributes()));
        $this->assertStringNotContainsString('senha', json_encode($r->getAttributes()));
    }

    public function test_revogar_consentimento_gera_auditoria(): void
    {
        $this->postJson('/clientes', [
            'nome' => 'Maria', 'email' => 'maria@exemplo.com', 'senha' => 'senha1234', 'aceiteTermos' => true,
            'consentimentoMarketing' => true,
        ])->assertCreated();
        $cliente = Usuario::first();

        $this->comoUsuario($cliente)->deleteJson('/clientes/me/consentimentos/MARKETING')->assertOk();

        $r = RegistroAuditoria::where('acao', Acao::REVOGACAO_CONSENTIMENTO)->first();
        $this->assertSame($cliente->id, $r->usuario_id);
        $this->assertSame('consentimentos', $r->entidade);
        $this->assertSame(
            $cliente->consentimentos()->where('finalidade', Finalidade::MARKETING)->first()->id,
            $r->entidade_id,
        );
        $this->assertTrue($r->dados_antes['aceito']);
        $this->assertFalse($r->dados_depois['aceito']);
        $this->assertNotNull($r->dados_depois['revogadoEm']);
    }

    public function test_revogacao_recusada_nao_gera_auditoria(): void
    {
        $this->postJson('/clientes', [
            'nome' => 'Maria', 'email' => 'maria@exemplo.com', 'senha' => 'senha1234', 'aceiteTermos' => true,
        ])->assertCreated();

        $this->comoUsuario(Usuario::first())->deleteJson('/clientes/me/consentimentos/MARKETING')->assertNotFound();
        $this->comoUsuario(Usuario::first())->deleteJson('/clientes/me/consentimentos/TERMOS')->assertStatus(422);

        $this->assertSame(0, RegistroAuditoria::where('acao', Acao::REVOGACAO_CONSENTIMENTO)->count());
    }
}
