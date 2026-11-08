<?php

namespace App\Policies;

use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;

class UnidadePolicy
{
    // RN13: ADMIN mexe em qualquer unidade, GERENTE so na dele
    public function configurar(Usuario $usuario, Unidade $unidade): bool
    {
        if ($usuario->perfil === Perfil::ADMIN) {
            return true;
        }

        return $usuario->perfil === Perfil::GERENTE && $usuario->unidade_id === $unidade->id;
    }

    // RF10: so o gerente da unidade monta o cardapio dela
    public function montarCardapio(Usuario $usuario, Unidade $unidade): bool
    {
        return $usuario->perfil === Perfil::GERENTE && $usuario->unidade_id === $unidade->id;
    }

    // RF13/RN13: a matriz ve tudo, gerente e atendente so a propria unidade
    public function verEstoque(Usuario $usuario, Unidade $unidade): bool
    {
        if ($usuario->perfil === Perfil::ADMIN) {
            return true;
        }

        return in_array($usuario->perfil, [Perfil::GERENTE, Perfil::ATENDENTE]) && $usuario->unidade_id === $unidade->id;
    }

    // RF12: so o gerente da unidade mexe no estoque
    public function movimentarEstoque(Usuario $usuario, Unidade $unidade): bool
    {
        return $usuario->perfil === Perfil::GERENTE && $usuario->unidade_id === $unidade->id;
    }
}
