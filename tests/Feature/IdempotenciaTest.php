<?php

namespace Tests\Feature;

use App\Domain\Usuarios\Usuario;
use App\Infrastructure\Idempotencia\ChaveIdempotencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// ainda nao ha rota de pedido, entao testo o middleware numa rota so de teste
class IdempotenciaTest extends TestCase
{
    use RefreshDatabase;

    private int $execucoes = 0;
    private Usuario $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = Usuario::factory()->create();

        Route::middleware(['auth:api', 'idempotente'])->post('/teste/idempotencia', function (Request $request) {
            $this->execucoes++;
            $status = (int) $request->input('status', 201);

            if ($status === 500) {
                throw new \RuntimeException('falha inesperada');
            }

            return response()->json(['execucao' => $this->execucoes, 'item' => $request->input('item')], $status);
        });

        Route::middleware(['auth:api', 'idempotente'])->post('/teste/outra', fn () => response()->json(['ok' => true], 201));
    }

    private function enviar(array $corpo, ?string $chave = 'chave-1', ?Usuario $usuario = null, string $url = '/teste/idempotencia')
    {
        $headers = $chave === null ? [] : ['Idempotency-Key' => $chave];

        return $this->comoUsuario($usuario ?? $this->usuario)->postJson($url, $corpo, $headers);
    }

    public function test_sem_a_chave_executa_sempre(): void
    {
        $this->enviar(['item' => 1], null)->assertCreated();
        $this->enviar(['item' => 1], null)->assertCreated();

        $this->assertSame(2, $this->execucoes);
        $this->assertSame(0, ChaveIdempotencia::count());
    }

    public function test_repeticao_devolve_a_resposta_original_sem_executar_de_novo(): void
    {
        $primeira = $this->enviar(['item' => 1])->assertCreated()->assertJsonPath('execucao', 1);
        $segunda = $this->enviar(['item' => 1]);

        $segunda->assertCreated()->assertJsonPath('execucao', 1)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($primeira->getContent(), $segunda->getContent());
        $this->assertFalse($primeira->headers->has('Idempotent-Replayed'));
        $this->assertSame(1, $this->execucoes);
        $this->assertSame(1, ChaveIdempotencia::count());
    }

    public function test_mesmo_corpo_com_campos_em_outra_ordem_vale_como_repeticao(): void
    {
        $this->enviar(['item' => ['b' => 2, 'a' => 1], 'extra' => true])->assertCreated();
        $this->enviar(['extra' => true, 'item' => ['a' => 1, 'b' => 2]])->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(1, $this->execucoes);
    }

    public function test_corpo_diferente_com_a_mesma_chave_da_409(): void
    {
        $this->enviar(['item' => 1])->assertCreated();

        $this->enviar(['item' => 2])
            ->assertStatus(409)
            ->assertJsonPath('error', 'CHAVE_IDEMPOTENCIA_CONFLITANTE');

        $this->assertSame(1, $this->execucoes);
    }

    public function test_a_chave_e_por_usuario_e_por_rota(): void
    {
        $this->enviar(['item' => 1])->assertCreated();

        $this->enviar(['item' => 1], 'chave-1', Usuario::factory()->create())->assertJsonPath('execucao', 2);
        $this->enviar(['item' => 1], 'chave-1', null, '/teste/outra')->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame(2, $this->execucoes);
        $this->assertSame(3, ChaveIdempotencia::count());
    }

    public function test_chaves_diferentes_sao_pedidos_diferentes(): void
    {
        $this->enviar(['item' => 1], 'a')->assertJsonPath('execucao', 1);
        $this->enviar(['item' => 1], 'b')->assertJsonPath('execucao', 2);
    }

    public function test_depois_de_24_horas_a_chave_vale_de_novo(): void
    {
        $this->enviar(['item' => 1])->assertJsonPath('execucao', 1);

        Carbon::setTestNow(now()->addHours(23));
        $this->enviar(['item' => 1])->assertHeader('Idempotent-Replayed', 'true');

        Carbon::setTestNow(now()->addHours(2));
        $this->enviar(['item' => 9])->assertCreated()->assertJsonPath('execucao', 2)->assertHeaderMissing('Idempotent-Replayed');

        Carbon::setTestNow();
    }

    public function test_recusa_de_regra_de_negocio_e_guardada_como_resposta_original(): void
    {
        foreach ([402, 404, 409] as $i => $status) {
            $chave = "chave-$status";
            $this->enviar(['status' => $status], $chave)->assertStatus($status);
            $this->enviar(['status' => $status], $chave)->assertStatus($status)->assertHeader('Idempotent-Replayed', 'true');
        }

        $this->assertSame(3, $this->execucoes);
    }

    public function test_validacao_e_erro_do_servidor_liberam_a_chave_para_tentar_de_novo(): void
    {
        foreach ([422, 500] as $status) {
            $chave = "chave-$status";
            $this->enviar(['status' => $status], $chave)->assertStatus($status);
            $this->assertSame(0, ChaveIdempotencia::where('chave', $chave)->count());

            $this->enviar(['status' => 201], $chave)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
        }
    }

    public function test_requisicao_ainda_em_andamento_da_409(): void
    {
        ChaveIdempotencia::create([
            'usuario_id' => $this->usuario->id,
            'escopo' => 'POST teste/idempotencia',
            'chave' => 'chave-1',
            'hash_corpo' => hash('sha256', json_encode(['item' => 1])),
            'expira_em' => now()->addDay(),
        ]);

        $this->enviar(['item' => 1])
            ->assertStatus(409)
            ->assertJsonPath('error', 'CHAVE_IDEMPOTENCIA_CONFLITANTE');

        $this->assertSame(0, $this->execucoes);
    }

    public function test_corrida_entre_duas_requisicoes_iguais_so_uma_executa(): void
    {
        // simula a outra requisicao gravando a chave entre a consulta e o insert
        ChaveIdempotencia::creating(function ($registro) {
            DB::table('chaves_idempotencia')->insert([
                'usuario_id' => $registro->usuario_id,
                'escopo' => $registro->escopo,
                'chave' => $registro->chave,
                'hash_corpo' => $registro->hash_corpo,
                'expira_em' => now()->addDay(),
            ]);
        });

        $this->enviar(['item' => 1])
            ->assertStatus(409)
            ->assertJsonPath('error', 'CHAVE_IDEMPOTENCIA_CONFLITANTE');

        ChaveIdempotencia::flushEventListeners();
        $this->assertSame(0, $this->execucoes);
        $this->assertSame(1, ChaveIdempotencia::count());
    }

    public function test_chave_invalida_da_422(): void
    {
        foreach ([str_repeat('a', 101), 'com espaco', "com\ttab", 'acentuação'] as $chave) {
            $this->enviar(['item' => 1], $chave)
                ->assertStatus(422)
                ->assertJsonPath('error', 'VALIDACAO')
                ->assertJsonPath('details.0.campo', 'Idempotency-Key');
        }

        $this->assertSame(0, $this->execucoes);
    }

    public function test_sem_login_nao_chega_no_middleware(): void
    {
        $this->postJson('/teste/idempotencia', [], ['Idempotency-Key' => 'x'])->assertUnauthorized();

        $this->assertSame(0, ChaveIdempotencia::count());
    }

    public function test_limpeza_apaga_so_as_vencidas(): void
    {
        $this->enviar(['item' => 1], 'velha');
        $this->enviar(['item' => 1], 'nova');
        ChaveIdempotencia::where('chave', 'velha')->update(['expira_em' => now()->subMinute()]);

        $this->artisan('model:prune', ['--model' => ChaveIdempotencia::class])->assertSuccessful();

        $this->assertSame(['nova'], ChaveIdempotencia::pluck('chave')->all());
    }
}
