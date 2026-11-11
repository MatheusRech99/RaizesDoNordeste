<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Rotas;
use Tests\TestCase;

class OpenApiTest extends TestCase
{
    private function spec(): array
    {
        return json_decode(file_get_contents(base_path('docs/openapi.json')), true);
    }

    public function test_toda_rota_da_api_esta_documentada(): void
    {
        $spec = $this->spec();

        foreach (Rotas::getRoutes() as $rota) {
            /** @var Route $rota */
            $uri = $rota->uri();
            if ($uri === 'up' || str_starts_with($uri, 'storage') || str_starts_with($uri, 'docs') || str_starts_with($uri, '_scramble')) {
                continue;
            }

            foreach (array_diff($rota->methods(), ['HEAD']) as $metodo) {
                $caminho = '/'.$uri;
                $this->assertArrayHasKey($caminho, $spec['paths'], "$metodo $caminho fora da spec");
                $this->assertArrayHasKey(strtolower($metodo), $spec['paths'][$caminho], "$metodo $caminho fora da spec");
            }
        }
    }

    public function test_autenticacao_jwt_e_rotas_publicas(): void
    {
        $spec = $this->spec();

        $this->assertSame('bearer', $spec['components']['securitySchemes']['http']['scheme']);
        $this->assertSame('JWT', $spec['components']['securitySchemes']['http']['bearerFormat']);

        $publicas = ['/auth/login' => 'post', '/clientes' => 'post', '/unidades' => 'get', '/unidades/{id}' => 'get', '/unidades/{id}/cardapio' => 'get'];
        foreach ($publicas as $caminho => $metodo) {
            $this->assertSame([], $spec['paths'][$caminho][$metodo]['security'], "$caminho deveria ser publico");
        }

        $this->assertArrayNotHasKey('security', $spec['paths']['/pedidos']['post']);
        $this->assertArrayNotHasKey('security', $spec['paths']['/auditoria']['get']);
    }

    public function test_erros_seguem_o_formato_padrao(): void
    {
        $spec = $this->spec();

        $this->assertEqualsCanonicalizing(
            ['error', 'message', 'details', 'timestamp', 'path', 'requestId'],
            array_keys($spec['components']['schemas']['Erro']['properties']),
        );

        foreach (['ValidationException', 'AuthenticationException', 'AuthorizationException'] as $nome) {
            $this->assertSame('#/components/schemas/Erro', $spec['components']['responses'][$nome]['content']['application/json']['schema']['$ref']);
        }
    }

    public function test_idempotency_key_nos_posts_que_aceitam(): void
    {
        $spec = $this->spec();

        foreach (['/pedidos', '/pagamentos'] as $caminho) {
            $nomes = array_column($spec['paths'][$caminho]['post']['parameters'], 'name');
            $this->assertContains('Idempotency-Key', $nomes);
        }
        $this->assertNotContains('Idempotency-Key', array_column($spec['paths']['/pedidos/{id}/cancelamento']['post']['parameters'], 'name'));
    }

    public function test_operacoes_tem_resumo_e_respostas_dos_codigos_de_negocio_ficam_na_descricao(): void
    {
        $spec = $this->spec();

        foreach ($spec['paths'] as $caminho => $metodos) {
            foreach ($metodos as $metodo => $operacao) {
                $this->assertNotEmpty($operacao['summary'] ?? null, "$metodo $caminho sem resumo");
            }
        }

        $this->assertStringContainsString('ESTOQUE_INSUFICIENTE', $spec['paths']['/pedidos']['post']['description']);
        $this->assertStringContainsString('PAGAMENTO_RECUSADO', $spec['paths']['/pagamentos']['post']['description']);
    }

    public function test_respostas_nao_expoem_senha_nem_data_de_nascimento(): void
    {
        $schemas = $this->spec()['components']['schemas'];

        foreach ($schemas as $nome => $schema) {
            if (! str_ends_with($nome, 'Resource')) {
                continue;
            }
            $campos = array_map('strtolower', array_keys($schema['properties'] ?? []));
            foreach (['senha', 'datanascimento', 'data_nascimento'] as $proibido) {
                $this->assertNotContains($proibido, $campos, "$nome expoe $proibido");
            }
        }
    }
}
