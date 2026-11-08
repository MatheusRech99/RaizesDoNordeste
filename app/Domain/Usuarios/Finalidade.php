<?php

namespace App\Domain\Usuarios;

enum Finalidade: string
{
    case TERMOS = 'TERMOS';
    case FIDELIDADE = 'FIDELIDADE';
    case MARKETING = 'MARKETING';
}
