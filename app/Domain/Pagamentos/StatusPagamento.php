<?php

namespace App\Domain\Pagamentos;

enum StatusPagamento: string
{
    case PENDENTE = 'PENDENTE';
    case APROVADO = 'APROVADO';
    case RECUSADO = 'RECUSADO';
    case ERRO = 'ERRO';
    case ESTORNADO = 'ESTORNADO';
}
