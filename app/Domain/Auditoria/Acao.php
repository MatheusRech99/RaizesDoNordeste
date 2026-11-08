<?php

namespace App\Domain\Auditoria;

enum Acao: string
{
    case CADASTRO_CLIENTE = 'CADASTRO_CLIENTE';
    case REVOGACAO_CONSENTIMENTO = 'REVOGACAO_CONSENTIMENTO';
}
