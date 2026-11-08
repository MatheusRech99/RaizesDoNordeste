<?php

namespace App\Providers;

use App\Domain\Pagamentos\PaymentGateway;
use App\Domain\Pedidos\Pedido;
use App\Infrastructure\Pagamentos\MockPaymentGateway;
use App\Domain\Unidades\Unidade;
use App\Policies\PedidoPolicy;
use App\Policies\UnidadePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, MockPaymentGateway::class);
    }

    public function boot(): void
    {
        Gate::policy(Unidade::class, UnidadePolicy::class);
        Gate::policy(Pedido::class, PedidoPolicy::class);
    }
}
