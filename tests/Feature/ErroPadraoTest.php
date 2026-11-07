<?php

namespace Tests\Feature;

use App\Domain\Exceptions\CodigoErro;
use App\Domain\Exceptions\NegocioException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErroPadraoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/teste/validacao', function (Request $request) {
            $request->validate([
                'canalPedido' => 'required|in:APP,TOTEM',
                'itens' => 'required|array|min:1',
            ]);
        });
        Route::get('/teste/negocio', function () {
            throw new NegocioException(
                CodigoErro::ESTOQUE_INSUFICIENTE,
                'Estoque insuficiente',
                [['produtoId' => 3, 'disponivel' => 1]],
            );
        });
        Route::get('/teste/auth', fn () => throw new AuthenticationException());
        Route::get('/teste/proibido', fn () => throw new AuthorizationException());
        Route::get('/teste/erro', fn () => throw new \RuntimeException('segredo do banco'));
    }

    private function assertFormatoPadrao($resposta, string $codigo, int $status): void
    {
        $resposta->assertStatus($status);
        $resposta->assertJsonStructure(['error', 'message', 'details', 'timestamp', 'path', 'requestId']);
        $this->assertSame($codigo, $resposta->json('error'));
        $this->assertIsArray($resposta->json('details'));
        $this->assertSame($resposta->headers->get('X-Request-Id'), $resposta->json('requestId'));
    }

    public function test_422_do_laravel_vira_o_formato_padrao(): void
    {
        $r = $this->postJson('/teste/validacao', ['canalPedido' => 'XYZ']);

        $this->assertFormatoPadrao($r, 'VALIDACAO', 422);
        $this->assertSame('/teste/validacao', $r->json('path'));

        $campos = array_column($r->json('details'), 'campo');
        $this->assertContains('canalPedido', $campos);
        $this->assertContains('itens', $campos);
    }

    public function test_excecao_de_negocio_usa_codigo_status_e_detalhes_dela(): void
    {
        $r = $this->getJson('/teste/negocio');

        $this->assertFormatoPadrao($r, 'ESTOQUE_INSUFICIENTE', 409);
        $this->assertSame('Estoque insuficiente', $r->json('message'));
        $this->assertSame(3, $r->json('details.0.produtoId'));
    }

    public function test_401_sem_autenticacao(): void
    {
        $this->assertFormatoPadrao($this->getJson('/teste/auth'), 'NAO_AUTENTICADO', 401);
    }

    public function test_403_sem_permissao(): void
    {
        $this->assertFormatoPadrao($this->getJson('/teste/proibido'), 'ACESSO_NEGADO', 403);
    }

    public function test_404_rota_inexistente(): void
    {
        $r = $this->getJson('/nao-existe');

        $this->assertFormatoPadrao($r, 'NAO_ENCONTRADO', 404);
        $this->assertSame('/nao-existe', $r->json('path'));
    }

    public function test_405_metodo_errado(): void
    {
        $r = $this->deleteJson('/teste/negocio');

        $this->assertFormatoPadrao($r, 'METODO_NAO_PERMITIDO', 405);
        $this->assertNotEmpty($r->headers->get('Allow'));
    }

    public function test_500_nao_vaza_a_mensagem_original(): void
    {
        $r = $this->getJson('/teste/erro');

        $this->assertFormatoPadrao($r, 'ERRO_INTERNO', 500);
        $this->assertStringNotContainsString('segredo', $r->getContent());
    }

    public function test_aproveita_o_request_id_enviado_pelo_cliente(): void
    {
        $r = $this->withHeader('X-Request-Id', 'abc-123')->getJson('/teste/negocio');

        $this->assertSame('abc-123', $r->json('requestId'));
    }

    public function test_sem_header_gera_um_request_id_novo(): void
    {
        $r = $this->getJson('/teste/negocio');

        $this->assertNotEmpty($r->json('requestId'));
    }

    public function test_erro_sai_em_json_mesmo_sem_header_accept(): void
    {
        $r = $this->get('/nao-existe');

        $this->assertFormatoPadrao($r, 'NAO_ENCONTRADO', 404);
    }
}
