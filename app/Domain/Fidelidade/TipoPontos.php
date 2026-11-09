<?php

namespace App\Domain\Fidelidade;

enum TipoPontos: string
{
    case CREDITO = 'CREDITO';
    case DEBITO = 'DEBITO';
    case ESTORNO = 'ESTORNO';
}
