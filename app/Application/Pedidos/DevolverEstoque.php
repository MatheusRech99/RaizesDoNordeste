<?php

namespace App\Application\Pedidos;

use App\Application\Estoque\MovimentarEstoque;
use App\Domain\Estoque\TipoMovimentacao;
use App\Domain\Pedidos\Pedido;
use App\Domain\Usuarios\Usuario;

// usado quando o pedido e cancelado (pagamento recusado ou cancelamento); roda dentro da transacao de quem chama
class DevolverEstoque
{
    public function __construct(private MovimentarEstoque $estoque)
    {
    }

    public function executar(Pedido $pedido, Usuario $quem, string $motivo): void
    {
        $pedido->loadMissing('unidade');

        // mesma ordem da baixa (produto_id) para nao dar deadlock
        foreach ($pedido->itens()->orderBy('produto_id')->get() as $item) {
            $this->estoque->executar($pedido->unidade, $item->produto_id, TipoMovimentacao::ESTORNO, $item->quantidade, $motivo, $quem, $pedido->id);
        }
    }
}
