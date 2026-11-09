<?php

namespace App\Application\Fidelidade;

use App\Domain\Fidelidade\PontosFidelidade;
use App\Domain\Fidelidade\TipoPontos;
use App\Domain\Pedidos\Pedido;

// RN10: chamado no cancelamento; so faz algo se o pedido tinha gerado pontos
class EstornarPontos
{
    public function executar(Pedido $pedido): ?PontosFidelidade
    {
        $credito = PontosFidelidade::where('pedido_id', $pedido->id)->where('tipo', TipoPontos::CREDITO)->first();
        if (! $credito) {
            return null;
        }

        return PontosFidelidade::firstOrCreate(
            ['pedido_id' => $pedido->id, 'tipo' => TipoPontos::ESTORNO],
            ['usuario_id' => $credito->usuario_id, 'pontos' => -$credito->pontos],
        );
    }
}
