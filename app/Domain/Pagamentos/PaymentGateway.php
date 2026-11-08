<?php

namespace App\Domain\Pagamentos;

use App\Domain\Pedidos\Pedido;

interface PaymentGateway
{
    // $simularResultado (APROVADO, RECUSADO, ERRO) so o mock usa; gateway real ignora
    // Lanca FalhaNoGateway se nao conseguir falar com o gateway
    public function cobrar(Pedido $pedido, string $chave, ?string $simularResultado = null): RespostaGateway;

    public function estornar(Pagamento $pagamento): RespostaGateway;
}
