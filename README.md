# Raízes do Nordeste — API Back-End

API REST única para a rede de lanchonetes Raízes do Nordeste (canais APP, TOTEM, BALCAO, PICKUP e WEB),
com cardápio e estoque por unidade e pagamento externo simulado (mock).
Projeto Multidisciplinar UNINTER, trilha Back-End.

## Stack

Laravel 13, PHP 8.3+, MariaDB 10.11+ (compatível com MySQL 8), autenticação JWT, OpenAPI/Swagger.

## Como executar

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
```

Crie o banco e o usuário (como root do MariaDB):

```bash
sudo mysql -e "CREATE DATABASE raizes_nordeste CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'raizes'@'localhost' IDENTIFIED BY 'raizes123';
GRANT ALL ON raizes_nordeste.* TO 'raizes'@'localhost';"
```

Os valores de `DB_*` do `.env.example` já batem com isso. Depois:

```bash
php artisan migrate --seed
php artisan serve
```

## Autenticação

JWT com expiração de 60 minutos. O token vai no header `Authorization: Bearer <token>`.

| Método | Rota | O que faz |
| --- | --- | --- |
| POST | `/auth/login` | recebe `email` e `senha`, devolve `accessToken` |
| POST | `/auth/refresh` | troca o token por um novo (aceita expirado, dentro de 24 h) |
| POST | `/auth/logout` | invalida o token |
| GET | `/auth/me` | dados do usuário logado |

Perfis: ADMIN, GERENTE, ATENDENTE, COZINHA e CLIENTE.

## Unidades

| Método | Rota | Quem acessa | O que faz |
| --- | --- | --- | --- |
| GET | `/unidades` | público | lista as unidades ativas, com `page` e `limit` |
| GET | `/unidades/{id}` | público | consulta uma unidade com formato e horários |
| PUT | `/unidades/{id}` | ADMIN, GERENTE da unidade | muda `formato` e/ou `horarios` |

Os horários vão como `[{ "diaSemana": 1, "abre": "10:00", "fecha": "22:00" }]` (0 = domingo).
A lista enviada substitui a anterior, e dia que não estiver nela fica fechado.

## Produtos e cardápio

O catálogo de produtos é único para a rede. Cada unidade monta o próprio cardápio, com preço, disponibilidade, período sazonal e descrição regional.

| Método | Rota | Quem acessa | O que faz |
| --- | --- | --- | --- |
| GET | `/produtos`, `/produtos/{id}` | ADMIN, GERENTE | consulta o catálogo (`page` e `limit`) |
| POST | `/produtos` | ADMIN | cadastra produto |
| PUT | `/produtos/{id}` | ADMIN | edita ou desativa (`ativo: false`) |
| GET | `/unidades/{id}/cardapio` | público | itens disponíveis hoje; `?data=AAAA-MM-DD` consulta outro dia |
| PUT | `/unidades/{id}/cardapio/{produtoId}` | GERENTE da unidade | cria ou substitui o item no cardápio |

Corpo do `PUT` do cardápio: `preco` (obrigatório), `disponivel` (padrão `true`), `inicioSazonal` e `fimSazonal` no formato `MM-DD` (o período repete todo ano e pode passar da virada, como `12-01` a `01-31`) e `descricaoRegional`. O que não for enviado é limpo.

Produto que exige cozinha completa não entra no cardápio de unidade reduzida, e a unidade não vira reduzida com um desses no cardápio (`PRODUTO_INCOMPATIVEL_COM_UNIDADE`).

## Estoque

Saldo por produto em cada unidade. Toda mudança vira uma movimentação com tipo, quantidade, motivo, usuário e saldo depois dela.

| Método | Rota | Quem acessa | O que faz |
| --- | --- | --- | --- |
| GET | `/unidades/{id}/estoque` | ADMIN, GERENTE e ATENDENTE da unidade | saldo atual por produto (`page` e `limit`) |
| GET | `/unidades/{id}/estoque/movimentacoes` | idem | histórico, do mais novo ao mais antigo; filtros `produtoId` e `tipo` |
| POST | `/unidades/{id}/estoque/movimentacoes` | GERENTE da unidade | registra `ENTRADA`, `SAIDA` ou `AJUSTE` |

Corpo do `POST`: `produtoId`, `tipo`, `quantidade` e `motivo`. Em `ENTRADA` e `SAIDA` a quantidade é positiva; o `AJUSTE` aceita valor negativo, mas não zero. Se o saldo ficasse negativo, a resposta é 409 `ESTOQUE_INSUFICIENTE` com o saldo disponível em `details`.

O saldo é lido com `SELECT ... FOR UPDATE` dentro de uma transação, e o banco tem um `CHECK (quantidade >= 0)` como segunda barreira. Movimentações manuais geram auditoria. O tipo `ESTORNO` é só interno (devolução de pedido cancelado).

## Pedidos

| Método | Rota | Quem acessa | O que faz |
| --- | --- | --- | --- |
| POST | `/pedidos` | CLIENTE, ATENDENTE da unidade | cria o pedido (aceita `Idempotency-Key`) |
| GET | `/pedidos` | qualquer perfil logado (com escopo) | lista paginada, mais novos primeiro |
| GET | `/pedidos/{id}` | qualquer perfil logado (com escopo) | pedido com itens e histórico de status |
| PATCH | `/pedidos/{id}/status` | COZINHA, ATENDENTE da unidade | avança o status do pedido |
| POST | `/pedidos/{id}/cancelamento` | CLIENTE, ATENDENTE, GERENTE | cancela o pedido (corpo: `motivo`) |

Corpo: `canalPedido` (`APP`, `TOTEM`, `BALCAO`, `PICKUP` ou `WEB`), `unidadeId`, `itens` (`[{ "produtoId": 1, "quantidade": 2 }]`) e, só para o atendente, `clienteId`. Preço e total nunca vêm do cliente: o preço é o do cardápio da unidade no momento do pedido e fica gravado no item.

- O cliente usa qualquer canal menos `BALCAO`, e o pedido é dele. O atendente só pede na própria unidade; no `BALCAO` o cliente é opcional, nos outros canais o `clienteId` é obrigatório.
- O pedido nasce em `AGUARDANDO_PAGAMENTO`, com o estoque já baixado, histórico de status e auditoria, tudo numa transação. Se um item não tiver saldo, nada é gravado.
- Erros: 422 corpo inválido (canal, itens vazios, quantidade, produto repetido); 404 unidade ou produto fora do cardápio; 409 `UNIDADE_FECHADA`, `PRODUTO_INDISPONIVEL` (desligado, fora do período sazonal ou inativo) e `ESTOQUE_INSUFICIENTE` (com o saldo de cada item em `details`).

Consulta: `GET /pedidos` aceita `canalPedido`, `status`, `unidadeId`, `page` e `limit` (padrão 15, máximo 100). O que cada um vê:

- CLIENTE: só os próprios pedidos (inclusive os que o atendente abriu no nome dele).
- ATENDENTE, COZINHA e GERENTE: só os da sua unidade. Filtrar por outra unidade dá 403 `ACESSO_NEGADO`.
- ADMIN: a rede toda.

No detalhe, pedido de outro cliente ou inexistente dá o mesmo 404, para não revelar que existe; equipe de outra unidade leva 403.

Status: `PATCH /pedidos/{id}/status` com `{ "status": "EM_PREPARO" }`. Só avança pela máquina `AGUARDANDO_PAGAMENTO → PAGO → EM_PREPARO → PRONTO → ENTREGUE`.

- A COZINHA move `PAGO → EM_PREPARO → PRONTO`; o ATENDENTE move `PRONTO → ENTREGUE`. Outro perfil, ou outra unidade, leva 403.
- Pular etapa, voltar ou mexer em pedido já `ENTREGUE`/`CANCELADO` dá 409 `TRANSICAO_INVALIDA`, com `statusAtual` e `statusSolicitado` em `details`.
- Só aceita `EM_PREPARO`, `PRONTO` e `ENTREGUE` (422 nos demais): `PAGO` vem do pagamento e `CANCELADO` do cancelamento.
- Cada mudança grava histórico e auditoria na mesma transação, com lock no pedido para duas chamadas simultâneas não passarem pela mesma transição.

Cancelamento: `POST /pedidos/{id}/cancelamento` com `{ "motivo": "..." }` (3 a 255 caracteres). Até onde cada perfil cancela:

| Perfil | Cancela pedido em |
| --- | --- |
| CLIENTE (só o dele) | `AGUARDANDO_PAGAMENTO` |
| ATENDENTE da unidade | `AGUARDANDO_PAGAMENTO`, `PAGO` |
| GERENTE da unidade | `AGUARDANDO_PAGAMENTO`, `PAGO`, `EM_PREPARO` |

- Fora desses limites, 403 `ACESSO_NEGADO`. Pedido `PRONTO`, `ENTREGUE` ou já `CANCELADO` dá 409 `TRANSICAO_INVALIDA` para qualquer perfil. COZINHA e ADMIN não cancelam.
- O estoque volta por movimentação `ESTORNO` e, se o pedido já estava pago, o pagamento vira `ESTORNADO` (estorno no gateway mock).
- Tudo na mesma transação, com auditoria `CANCELAMENTO_PEDIDO`. Se o estorno falhar, 503 `GATEWAY_INDISPONIVEL` e o pedido fica como estava.

## Pagamentos

| Método | Rota | Quem acessa | O que faz |
| --- | --- | --- | --- |
| POST | `/pagamentos` | CLIENTE (dono do pedido), ATENDENTE da unidade | cobra o pedido no gateway (aceita `Idempotency-Key`) |

Corpo: `pedidoId` e, opcionais, `valor` (se vier, tem que ser igual ao total do pedido) e `simularResultado`. O gateway é um mock (`MockPaymentGateway`, trocável pelo binding da interface `PaymentGateway` no `AppServiceProvider`).

- `simularResultado` aceita `APROVADO`, `RECUSADO` ou `ERRO` e deixa o resultado do mock previsível. Sem ele, o mock aprova. Em produção (`APP_ENV=production`) o campo é recusado com 422.
- `APROVADO`: 200, pagamento `APROVADO`, pedido vai para `PAGO`.
- `RECUSADO`: 402 `PAGAMENTO_RECUSADO`. O pagamento fica `RECUSADO` e o pedido é cancelado (motivo `PAGAMENTO_RECUSADO`) com o estoque devolvido (movimentação `ESTORNO`).
- `ERRO` (timeout, gateway fora): 503 `GATEWAY_INDISPONIVEL`. O pagamento fica `ERRO`, o pedido continua `AGUARDANDO_PAGAMENTO` e dá para tentar de novo; cada tentativa é uma linha em `pagamentos`.
- Pedido fora de `AGUARDANDO_PAGAMENTO`, ou `valor` diferente do total: 409 `PEDIDO_NAO_PAGAVEL`. Pedido de outro cliente: 404; de outra unidade: 403.
- A cobrança roda numa transação com lock no pedido, então duas chamadas ao mesmo tempo não cobram duas vezes. A resposta do gateway vira pagamento, histórico e auditoria na mesma transação.

## Idempotência

`POST /pedidos` e `POST /pagamentos` aceitam o header `Idempotency-Key` (1 a 100 caracteres visíveis, sem espaço). A chave é opcional e vale por usuário, por rota e por 24 horas.

- Mesma chave e mesmo corpo: devolve a resposta original, com o header `Idempotent-Replayed: true`, sem executar de novo.
- Mesma chave com corpo diferente, ou ainda em processamento: 409 `CHAVE_IDEMPOTENCIA_CONFLITANTE`.
- A resposta só é guardada quando vale como resultado: sucesso (2xx) e recusas de regra de negócio (402, 404, 409). Erro de validação (422) e de servidor (5xx, como o 503 do gateway) liberam a chave, para o cliente corrigir e tentar de novo com a mesma chave.

As chaves vencidas são limpas pelo agendador (`php artisan schedule:work` em dev, ou `schedule:run` no cron).

## Clientes e consentimentos (LGPD)

| Método | Rota | Quem acessa | O que faz |
| --- | --- | --- | --- |
| POST | `/clientes` | público | cadastra o cliente com os consentimentos |
| GET | `/clientes/me/consentimentos` | CLIENTE | consulta os próprios consentimentos |
| DELETE | `/clientes/me/consentimentos/{finalidade}` | CLIENTE | revoga `FIDELIDADE` ou `MARKETING` |

Corpo do `POST /clientes`: `nome`, `email`, `senha` (mínimo 8), `telefone` e `dataNascimento` (opcionais), `aceiteTermos` (obrigatório, `true`), `consentimentoFidelidade` e `consentimentoMarketing` (padrão `false`).

Fidelidade e marketing são consentimentos separados. Cada um é gravado com a versão do termo (`LGPD_VERSAO_TERMO`), a data e o IP. A data de nascimento só é aceita junto com o consentimento de fidelidade e é apagada se ele for revogado. Ela nunca aparece nas respostas. Os termos não são revogáveis por essa rota (422).

## Auditoria

Operações sensíveis gravam um registro na tabela `auditoria` (quem, ação, entidade, dados antes e depois, IP e data) dentro da mesma transação da operação, então se a operação desfaz o registro some junto. Por enquanto são o cadastro de cliente e a revogação de consentimento; as demais entram com as etapas de pedido, estoque e cancelamento.

A tabela só aceita INSERT: o model lança exceção em `update`/`delete` e o banco tem triggers que barram qualquer alteração ou exclusão direta. Dados como senha e data de nascimento nunca entram nos registros.

## Usuários do seed

`php artisan migrate --seed` cria 3 unidades, 9 produtos com cardápio e estoque (50 de cada, e só 5 de carne de sol, para testar o estoque insuficiente) e estes usuários (todos com a senha `senha123`, só para desenvolvimento):

| E-mail | Perfil |
| --- | --- |
| `admin@raizes.com` | ADMIN |
| `gerente1@raizes.com`, `atendente1@raizes.com`, `cozinha1@raizes.com` | unidade 1 (Recife) |
| `gerente2@raizes.com`, `atendente2@raizes.com`, `cozinha2@raizes.com` | unidade 2 (Fortaleza) |
| `gerente3@raizes.com`, `atendente3@raizes.com`, `cozinha3@raizes.com` | unidade 3 (São Paulo) |
| `cliente@raizes.com` | CLIENTE (com termos e fidelidade aceitos) |

## Testes

```bash
php artisan test
```

Os testes usam SQLite em memória por padrão (`phpunit.xml`), então não precisam do MariaDB.

Para rodar no MariaDB (cobre o `CHECK` e as chaves estrangeiras, que o SQLite não valida), crie um banco de teste e passe as variáveis:

```bash
sudo mysql -e "CREATE DATABASE raizes_nordeste_teste CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL ON raizes_nordeste_teste.* TO 'raizes'@'localhost';"

DB_CONNECTION=mysql DB_DATABASE=raizes_nordeste_teste php artisan test
```

## Estado

Em desenvolvimento por etapas; o histórico de commits acompanha a evolução do MVP.
