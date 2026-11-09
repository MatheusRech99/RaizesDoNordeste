<?php

namespace App\Application\Fidelidade;

use App\Domain\Fidelidade\PontosFidelidade;
use App\Domain\Fidelidade\TipoPontos;
use App\Domain\Pedidos\Pedido;
use App\Domain\Usuarios\Finalidade;
use App\Domain\Usuarios\Usuario;

// RN10: chamado na aprovacao do pagamento, dentro da transacao dela
class CreditarPontos
{
    public function executar(Pedido $pedido): ?PontosFidelidade
    {
        // pedido de balcao sem cliente nao pontua
        if (! $pedido->cliente_id) {
            return null;
        }

        $cliente = Usuario::find($pedido->cliente_id);
        if (! $cliente->temConsentimento(Finalidade::FIDELIDADE)) {
            return null;
        }

        // 1 ponto por real inteiro (centavos nao contam)
        $pontos = intdiv((int) round($pedido->total * 100), 100);
        if ($pontos === 0) {
            return null;
        }

        return PontosFidelidade::firstOrCreate(
            ['pedido_id' => $pedido->id, 'tipo' => TipoPontos::CREDITO],
            ['usuario_id' => $cliente->id, 'pontos' => $pontos],
        );
    }
}
