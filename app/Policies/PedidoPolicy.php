<?php

namespace App\Policies;

use App\Domain\Pedidos\Pedido;
use App\Domain\Pedidos\StatusPedido;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use Illuminate\Auth\Access\Response;

class PedidoPolicy
{
    // RF16/RN13: cliente so ve o proprio (404 pra nao revelar), equipe so da unidade, ADMIN tudo
    public function ver(Usuario $usuario, Pedido $pedido): bool|Response
    {
        if ($usuario->perfil === Perfil::ADMIN) {
            return true;
        }

        if ($usuario->perfil === Perfil::CLIENTE) {
            return $pedido->cliente_id === $usuario->id ? true : Response::denyAsNotFound();
        }

        return $usuario->unidade_id === $pedido->unidade_id;
    }

    // RF17: cozinha prepara, atendente entrega; sempre na propria unidade
    public function mudarStatus(Usuario $usuario, Pedido $pedido, StatusPedido $novo): bool
    {
        if ($usuario->unidade_id !== $pedido->unidade_id) {
            return false;
        }

        return match ($novo) {
            StatusPedido::EM_PREPARO, StatusPedido::PRONTO => $usuario->perfil === Perfil::COZINHA,
            StatusPedido::ENTREGUE => $usuario->perfil === Perfil::ATENDENTE,
            default => false,
        };
    }
}
