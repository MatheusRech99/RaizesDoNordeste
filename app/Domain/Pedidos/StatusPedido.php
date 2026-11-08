<?php

namespace App\Domain\Pedidos;

enum StatusPedido: string
{
    case AGUARDANDO_PAGAMENTO = 'AGUARDANDO_PAGAMENTO';
    case PAGO = 'PAGO';
    case EM_PREPARO = 'EM_PREPARO';
    case PRONTO = 'PRONTO';
    case ENTREGUE = 'ENTREGUE';
    case CANCELADO = 'CANCELADO';
}
