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

    // RN06: so as setas da maquina de status
    public function podeIrPara(StatusPedido $novo): bool
    {
        return match ($this) {
            self::AGUARDANDO_PAGAMENTO => in_array($novo, [self::PAGO, self::CANCELADO]),
            self::PAGO => in_array($novo, [self::EM_PREPARO, self::CANCELADO]),
            self::EM_PREPARO => in_array($novo, [self::PRONTO, self::CANCELADO]),
            self::PRONTO => $novo === self::ENTREGUE,
            self::ENTREGUE, self::CANCELADO => false,
        };
    }
}
