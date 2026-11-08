<?php

namespace App\Domain\Estoque;

enum TipoMovimentacao: string
{
    case ENTRADA = 'ENTRADA';
    case SAIDA = 'SAIDA';
    case AJUSTE = 'AJUSTE';
    case ESTORNO = 'ESTORNO';

    // quanto o saldo muda; so a saida tira, o ajuste ja vem com sinal
    public function delta(int $quantidade): int
    {
        return $this === self::SAIDA ? -$quantidade : $quantidade;
    }
}
