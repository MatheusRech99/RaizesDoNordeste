<?php

namespace App\Providers;

use App\Domain\Pedidos\Pedido;
use App\Domain\Unidades\Unidade;
use App\Policies\PedidoPolicy;
use App\Policies\UnidadePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Unidade::class, UnidadePolicy::class);
        Gate::policy(Pedido::class, PedidoPolicy::class);
    }
}
