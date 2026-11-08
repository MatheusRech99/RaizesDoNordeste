<?php

namespace Database\Factories;

use App\Domain\Pedidos\CanalPedido;
use App\Domain\Pedidos\Pedido;
use App\Domain\Pedidos\StatusPedido;
use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pedido>
 */
class PedidoFactory extends Factory
{
    protected $model = Pedido::class;

    public function definition(): array
    {
        $cliente = Usuario::factory();

        return [
            'unidade_id' => Unidade::factory(),
            'cliente_id' => $cliente,
            'criado_por' => $cliente,
            'canal_pedido' => CanalPedido::APP,
            'status' => StatusPedido::AGUARDANDO_PAGAMENTO,
            'subtotal' => '25.00',
            'desconto' => '0.00',
            'total' => '25.00',
        ];
    }
}
