# Raízes do Nordeste — API Back-End

API REST única para a rede de lanchonetes Raízes do Nordeste (canais APP, TOTEM, BALCAO, PICKUP e WEB),
com cardápio e estoque por unidade e pagamento externo simulado (mock).
Projeto Multidisciplinar UNINTER, trilha Back-End.

## Stack

Laravel 13, PHP 8.3+, MySQL 8, autenticação JWT, OpenAPI/Swagger.

## Como executar

```bash
composer install
cp .env.example .env
php artisan key:generate
# ajuste DB_* no .env e crie o banco raizes_nordeste
php artisan migrate --seed
php artisan serve
```

## Testes

```bash
php artisan test
```

Os testes usam SQLite em memória por padrão (`phpunit.xml`).

## Estado

Em desenvolvimento por etapas; o histórico de commits acompanha a evolução do MVP.
