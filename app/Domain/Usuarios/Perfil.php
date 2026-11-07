<?php

namespace App\Domain\Usuarios;

enum Perfil: string
{
    case ADMIN = 'ADMIN';
    case GERENTE = 'GERENTE';
    case ATENDENTE = 'ATENDENTE';
    case COZINHA = 'COZINHA';
    case CLIENTE = 'CLIENTE';
}
