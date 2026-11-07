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
}
