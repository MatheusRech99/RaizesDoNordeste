<?php

namespace App\Policies;

use App\Domain\Pedidos\Pedido;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;

class UsuarioPolicy
{
    // RN13: a matriz ve qualquer cliente; gerente e atendente so quem ja pediu na unidade deles
    public function verCliente(Usuario $quem, Usuario $cliente): bool
    {
        if ($quem->perfil === Perfil::ADMIN) {
            return true;
        }

        if (! in_array($quem->perfil, [Perfil::GERENTE, Perfil::ATENDENTE])) {
            return false;
        }

        return Pedido::where('cliente_id', $cliente->id)->where('unidade_id', $quem->unidade_id)->exists();
    }
}
