<?php

namespace App\Domain\Pedidos;

enum CanalPedido: string
{
    case APP = 'APP';
    case TOTEM = 'TOTEM';
    case BALCAO = 'BALCAO';
    case PICKUP = 'PICKUP';
    case WEB = 'WEB';
}
