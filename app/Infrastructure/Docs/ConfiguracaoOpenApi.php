<?php

namespace App\Infrastructure\Docs;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Routing\Route;

// RNF14: ajustes do Scramble para a spec refletir o contrato real da API
class ConfiguracaoOpenApi
{
    public static function aplicar(): void
    {
        Scramble::configure()
            ->routes(fn (Route $rota) => ! in_array($rota->uri(), ['up']) && ! str_starts_with($rota->uri(), 'storage'))
            ->withOperationTransformers(function (Operation $operacao, RouteInfo $rota) {
                // rota sem auth:api e publica (login, cadastro, unidades, cardapio); o refresh recebe o token mesmo expirado
                if (! in_array('auth:api', $rota->route->gatherMiddleware()) && $rota->route->uri() !== 'auth/refresh') {
                    $operacao->security = [];
                }

                // RN07: POST /pedidos e POST /pagamentos aceitam a chave de idempotencia
                if (in_array('idempotente', $rota->route->gatherMiddleware())) {
                    $operacao->addParameters([
                        (new Parameter('Idempotency-Key', 'header'))
                            ->description('Opcional. 1 a 100 caracteres visíveis, sem espaço. A mesma chave com o mesmo corpo em até 24 h devolve a resposta original (header `Idempotent-Replayed: true`); corpo diferente dá 409 `CHAVE_IDEMPOTENCIA_CONFLITANTE`.')
                            ->setSchema(Schema::fromType(new StringType)),
                    ]);
                }
            })
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->info->title = 'API Raízes do Nordeste';
                $openApi->info->description = 'API REST única para os canais APP, TOTEM, BALCAO, PICKUP e WEB. Autenticação por JWT (header `Authorization: Bearer <token>`). Toda falha segue o formato do schema `Erro`.';

                $openApi->secure(SecurityScheme::http('bearer', 'JWT'));

                $erro = (new ObjectType)
                    ->addProperty('error', (new StringType)->setDescription('Código fixo do erro, por exemplo `ESTOQUE_INSUFICIENTE`'))
                    ->addProperty('message', new StringType)
                    ->addProperty('details', (new ArrayType)->setItems(new ObjectType)->setDescription('Detalhes por item; na validação, `campo` e `mensagem`'))
                    ->addProperty('timestamp', (new StringType)->format('date-time'))
                    ->addProperty('path', new StringType)
                    ->addProperty('requestId', new StringType)
                    ->setRequired(['error', 'message', 'details', 'timestamp', 'path', 'requestId']);
                $referencia = $openApi->components->addSchema('Erro', Schema::fromType($erro));

                // as respostas de erro que o Scramble infere usam outro formato; troca pelo nosso
                $respostas = [
                    'ValidationException' => [422, 'Dados inválidos (`VALIDACAO`)'],
                    'AuthenticationException' => [401, 'Token ausente, inválido ou expirado (`NAO_AUTENTICADO`)'],
                    'AuthorizationException' => [403, 'Sem permissão para a operação ou para a unidade (`ACESSO_NEGADO`)'],
                ];
                foreach ($respostas as $nome => [$codigo, $descricao]) {
                    $openApi->components->responses[$nome] = Response::make($codigo)
                        ->setDescription($descricao)
                        ->setContent('application/json', Schema::fromType($referencia));
                }
            });
    }
}
