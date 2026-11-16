<?php

namespace Tests\Feature;

use App\Application\Estoque\MovimentarEstoque;
use App\Domain\Cardapio\ItemCardapio;
use App\Domain\Cardapio\Produto;
use App\Domain\Estoque\Estoque;
use App\Domain\Estoque\MovimentacaoEstoque;
use App\Domain\Estoque\TipoMovimentacao;
use App\Domain\Pagamentos\Pagamento;
use App\Domain\Pagamentos\StatusPagamento;
use App\Domain\Pedidos\HistoricoStatus;
use App\Domain\Pedidos\Pedido;
use App\Domain\Pedidos\StatusPedido;
use App\Domain\Unidades\HorarioFuncionamento;
use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Concorrencia de verdade (RNF09): sobe o servidor embutido do PHP com varios workers e dispara
 * requisicoes ao mesmo tempo. So roda no MariaDB, porque precisa de lock de linha e de dados commitados:
 *   DB_CONNECTION=mysql DB_DATABASE=raizes_nordeste_teste php artisan test --filter=ConcorrenciaTest
 */
class ConcorrenciaTest extends TestCase
{
    use DatabaseTruncation;

    private ?Process $servidor = null;
    private string $url;
    private Unidade $unidade;
    private Produto $produto;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concorrência real precisa do MariaDB (DB_CONNECTION=mysql DB_DATABASE=raizes_nordeste_teste).');
        }

        $this->subirServidor();

        $this->unidade = Unidade::factory()->create();
        foreach (range(0, 6) as $dia) {
            HorarioFuncionamento::create(['unidade_id' => $this->unidade->id, 'dia_semana' => $dia, 'abre' => '00:00:00', 'fecha' => '23:59:59']);
        }
        $this->produto = Produto::factory()->create(['nome' => 'Tapioca']);
        ItemCardapio::factory()->create(['unidade_id' => $this->unidade->id, 'produto_id' => $this->produto->id, 'preco' => '10.00']);
    }

    protected function tearDown(): void
    {
        $this->servidor?->stop(2);
        $this->limparTabelas();
        parent::tearDown();
    }

    // os dados daqui sao commitados (o servidor e outro processo), entao nao podem sobrar para os outros testes
    private function limparTabelas(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::disableForeignKeyConstraints();
        foreach (Schema::getTables() as $tabela) {
            if ($tabela['name'] !== 'migrations') {
                DB::table($tabela['name'])->truncate();
            }
        }
        Schema::enableForeignKeyConstraints();
    }

    private function subirServidor(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $porta = (int) parse_url(stream_socket_get_name($socket, false), PHP_URL_PORT);
        fclose($socket);
        $this->url = "http://127.0.0.1:{$porta}";

        $banco = config('database.connections.mysql');
        $this->servidor = new Process(
            [PHP_BINARY, '-S', "127.0.0.1:{$porta}", base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],
            base_path('public'),
            [
                'APP_ENV' => 'testing',
                'APP_DEBUG' => 'false',
                'APP_KEY' => config('app.key'),
                'JWT_SECRET' => config('jwt.secret'),
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => $banco['host'],
                'DB_PORT' => $banco['port'],
                'DB_DATABASE' => $banco['database'],
                'DB_USERNAME' => $banco['username'],
                'DB_PASSWORD' => $banco['password'],
                'DB_URL' => '',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'LOG_CHANNEL' => 'null',
                'PHP_CLI_SERVER_WORKERS' => '12',
            ],
        );
        $this->servidor->start();

        for ($i = 0; $i < 50; $i++) {
            try {
                if (Http::timeout(1)->get($this->url.'/up')->successful()) {
                    return;
                }
            } catch (\Throwable) {
                usleep(200_000);
            }
        }

        $this->fail('Servidor de teste não subiu: '.$this->servidor->getErrorOutput());
    }

    // ---------- helpers ----------

    private function estoque(int $qtd): void
    {
        $gerente = Usuario::factory()->create(['perfil' => Perfil::GERENTE, 'unidade_id' => $this->unidade->id]);
        app(MovimentarEstoque::class)->executar($this->unidade, $this->produto->id, TipoMovimentacao::ENTRADA, $qtd, 'Carga', $gerente);
    }

    private function saldo(): int
    {
        return (int) Estoque::where('unidade_id', $this->unidade->id)->where('produto_id', $this->produto->id)->value('quantidade');
    }

    private function cliente(): Usuario
    {
        return Usuario::factory()->create(['perfil' => Perfil::CLIENTE]);
    }

    private function corpoPedido(int $qtd = 1): array
    {
        return [
            'canalPedido' => 'APP',
            'unidadeId' => $this->unidade->id,
            'itens' => [['produtoId' => $this->produto->id, 'quantidade' => $qtd]],
        ];
    }

    /** @param array<int, array{0: Usuario, 1: string, 2: string, 3: array, 4?: array}> $chamadas [usuario, metodo, caminho, corpo, headers] */
    private function paralelo(array $chamadas): array
    {
        $tokens = [];
        foreach ($chamadas as $i => $c) {
            $tokens[$i] = auth('api')->login($c[0]);
        }

        $respostas = Http::pool(function (Pool $pool) use ($chamadas, $tokens) {
            $reqs = [];
            foreach ($chamadas as $i => [$usuario, $metodo, $caminho, $corpo]) {
                $reqs[] = $pool->as((string) $i)->timeout(60)->acceptJson()->withToken($tokens[$i])
                    ->withHeaders($chamadas[$i][4] ?? [])
                    ->send($metodo, $this->url.$caminho, ['json' => $corpo]);
            }

            return $reqs;
        });

        foreach ($respostas as $r) {
            $this->assertInstanceOf(Response::class, $r, 'requisição não chegou ao servidor');
        }

        return array_values($respostas);
    }

    private function contar(array $respostas): array
    {
        $por = [];
        foreach ($respostas as $r) {
            $por[$r->status()] = ($por[$r->status()] ?? 0) + 1;
        }
        ksort($por);

        return $por;
    }

    private function pedirUm(Usuario $quem): int
    {
        return $this->paralelo([[$quem, 'POST', '/pedidos', $this->corpoPedido()]])[0]->json('data.id');
    }

    // ---------- estoque ----------

    public function test_dez_pedidos_ao_mesmo_tempo_para_saldo_de_cinco(): void
    {
        $this->estoque(5);

        $chamadas = array_map(fn () => [$this->cliente(), 'POST', '/pedidos', $this->corpoPedido()], range(1, 10));
        $respostas = $this->paralelo($chamadas);

        $this->assertSame([201 => 5, 409 => 5], $this->contar($respostas));
        foreach ($respostas as $r) {
            if ($r->status() === 409) {
                $this->assertSame('ESTOQUE_INSUFICIENTE', $r->json('error'));
            }
        }
        $this->assertSame(0, $this->saldo());
        $this->assertSame(5, Pedido::count());
        $this->assertSame(5, MovimentacaoEstoque::where('tipo', TipoMovimentacao::SAIDA)->whereNotNull('pedido_id')->count());
        $this->assertSame(-5, (int) MovimentacaoEstoque::where('tipo', TipoMovimentacao::SAIDA)->sum('quantidade'));
    }

    public function test_pedidos_de_quantidade_maior_nunca_estouram_o_saldo(): void
    {
        $this->estoque(5);

        // 6 pedidos de 2 unidades para 5 em estoque: so 2 cabem (4 unidades), sobra 1
        $chamadas = array_map(fn () => [$this->cliente(), 'POST', '/pedidos', $this->corpoPedido(2)], range(1, 6));
        $respostas = $this->paralelo($chamadas);

        $this->assertSame([201 => 2, 409 => 4], $this->contar($respostas));
        $this->assertSame(1, $this->saldo());
        $this->assertSame(2, Pedido::count());
    }

    public function test_pedido_recusado_por_falta_de_estoque_nao_deixa_rastro_parcial(): void
    {
        $this->estoque(3);
        $outro = Produto::factory()->create(['nome' => 'Cuscuz']);
        ItemCardapio::factory()->create(['unidade_id' => $this->unidade->id, 'produto_id' => $outro->id, 'preco' => '8.00']);
        $gerente = Usuario::factory()->create(['perfil' => Perfil::GERENTE, 'unidade_id' => $this->unidade->id]);
        app(MovimentarEstoque::class)->executar($this->unidade, $outro->id, TipoMovimentacao::ENTRADA, 100, 'Carga', $gerente);

        // todos pedem 1 cuscuz + 1 tapioca; so 3 tapiocas existem, entao 3 pedidos passam e o cuscuz volta dos outros
        $corpo = ['canalPedido' => 'APP', 'unidadeId' => $this->unidade->id, 'itens' => [
            ['produtoId' => $outro->id, 'quantidade' => 1],
            ['produtoId' => $this->produto->id, 'quantidade' => 1],
        ]];
        $respostas = $this->paralelo(array_map(fn () => [$this->cliente(), 'POST', '/pedidos', $corpo], range(1, 8)));

        $this->assertSame([201 => 3, 409 => 5], $this->contar($respostas));
        $this->assertSame(0, $this->saldo());
        $this->assertSame(97, (int) Estoque::where('produto_id', $outro->id)->value('quantidade'));
        $this->assertSame(3, Pedido::count());
    }

    // ---------- idempotencia ----------

    public function test_mesma_chave_ao_mesmo_tempo_cria_um_unico_pedido(): void
    {
        $this->estoque(10);
        $cliente = $this->cliente();
        $headers = ['Idempotency-Key' => 'chave-simultanea'];

        $respostas = $this->paralelo(array_map(fn () => [$cliente, 'POST', '/pedidos', $this->corpoPedido(), $headers], range(1, 6)));

        $this->assertSame(1, Pedido::count());
        $this->assertSame(9, $this->saldo());
        $this->assertSame(1, MovimentacaoEstoque::where('tipo', TipoMovimentacao::SAIDA)->count());

        $originais = array_filter($respostas, fn (Response $r) => $r->status() === 201 && ! $r->hasHeader('Idempotent-Replayed'));
        $this->assertCount(1, $originais, 'só uma requisição executa de verdade');
        foreach ($respostas as $r) {
            // as outras ou repetem a resposta original ou, se chegaram durante o processamento, levam 409
            $this->assertContains($r->status(), [201, 409]);
            if ($r->status() === 201) {
                $this->assertSame(Pedido::first()->id, $r->json('data.id'));
            } else {
                $this->assertSame('CHAVE_IDEMPOTENCIA_CONFLITANTE', $r->json('error'));
            }
        }
    }

    // ---------- pagamento ----------

    public function test_pagamentos_simultaneos_do_mesmo_pedido_cobram_uma_vez(): void
    {
        $this->estoque(5);
        $cliente = $this->cliente();
        $pedidoId = $this->pedirUm($cliente);

        $respostas = $this->paralelo(array_map(fn () => [$cliente, 'POST', '/pagamentos', ['pedidoId' => $pedidoId]], range(1, 6)));

        $this->assertSame([200 => 1, 409 => 5], $this->contar($respostas));
        $this->assertSame(1, Pagamento::count());
        $this->assertSame(StatusPagamento::APROVADO, Pagamento::first()->status);
        $this->assertSame(StatusPedido::PAGO, Pedido::find($pedidoId)->status);
        $this->assertSame(4, $this->saldo());
    }

    public function test_pagar_e_cancelar_ao_mesmo_tempo_termina_consistente(): void
    {
        $this->estoque(5);
        $cliente = $this->cliente();
        $pedidoId = $this->pedirUm($cliente);

        $chamadas = [];
        foreach (range(1, 4) as $i) {
            $chamadas[] = [$cliente, 'POST', '/pagamentos', ['pedidoId' => $pedidoId]];
            $chamadas[] = [$cliente, 'POST', "/pedidos/{$pedidoId}/cancelamento", ['motivo' => 'Desisti']];
        }
        $this->paralelo($chamadas);

        $pedido = Pedido::find($pedidoId);
        $aprovados = Pagamento::where('status', StatusPagamento::APROVADO)->count();

        if ($pedido->status === StatusPedido::PAGO) {
            // pagou antes do cancelamento: o cliente nao cancela pedido pago, o estoque continua baixado
            $this->assertSame(1, $aprovados);
            $this->assertSame(4, $this->saldo());
        } else {
            // cancelou antes: nao pode ter cobranca aprovada e o estoque volta inteiro, uma vez so
            $this->assertSame(StatusPedido::CANCELADO, $pedido->status);
            $this->assertSame(0, $aprovados);
            $this->assertSame(5, $this->saldo());
            $this->assertSame(1, MovimentacaoEstoque::where('pedido_id', $pedidoId)->where('tipo', TipoMovimentacao::ESTORNO)->count());
        }
        $this->assertSame(1, HistoricoStatus::where('pedido_id', $pedidoId)->where('status_novo', $pedido->status)->count());
    }

    // ---------- status ----------

    public function test_mudancas_de_status_simultaneas_passam_uma_vez_so(): void
    {
        $this->estoque(5);
        $cliente = $this->cliente();
        $cozinha = Usuario::factory()->create(['perfil' => Perfil::COZINHA, 'unidade_id' => $this->unidade->id]);
        $pedidoId = $this->pedirUm($cliente);
        $this->paralelo([[$cliente, 'POST', '/pagamentos', ['pedidoId' => $pedidoId]]]);

        $respostas = $this->paralelo(array_map(fn () => [$cozinha, 'PATCH', "/pedidos/{$pedidoId}/status", ['status' => 'EM_PREPARO']], range(1, 6)));

        $this->assertSame([200 => 1, 409 => 5], $this->contar($respostas));
        $this->assertSame(1, HistoricoStatus::where('pedido_id', $pedidoId)->where('status_novo', StatusPedido::EM_PREPARO)->count());
    }
}
