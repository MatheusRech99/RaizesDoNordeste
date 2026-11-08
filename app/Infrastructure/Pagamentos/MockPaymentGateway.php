<?php

namespace App\Infrastructure\Pagamentos;

use App\Domain\Pagamentos\FalhaNoGateway;
use App\Domain\Pagamentos\Pagamento;
use App\Domain\Pagamentos\PaymentGateway;
use App\Domain\Pagamentos\RespostaGateway;
use App\Domain\Pagamentos\StatusPagamento;
use App\Domain\Pedidos\Pedido;
use Illuminate\Support\Str;

class MockPaymentGateway implements PaymentGateway
{
    // sem simularResultado o mock aprova
    public function cobrar(Pedido $pedido, string $chave, ?string $simularResultado = null): RespostaGateway
    {
        $resultado = $simularResultado ?? 'APROVADO';

        if ($resultado === 'ERRO') {
            throw new FalhaNoGateway('Timeout simulado no gateway');
        }

        $transacao = 'MOCK-'.Str::uuid();

        if ($resultado === 'RECUSADO') {
            return new RespostaGateway(StatusPagamento::RECUSADO, $transacao, 'Cartão recusado (simulado)', [
                'transacao' => $transacao, 'resultado' => 'RECUSADO', 'motivo' => 'Cartão recusado (simulado)',
            ]);
        }

        return new RespostaGateway(StatusPagamento::APROVADO, $transacao, null, [
            'transacao' => $transacao, 'resultado' => 'APROVADO',
        ]);
    }

    public function estornar(Pagamento $pagamento): RespostaGateway
    {
        $transacao = 'MOCK-ESTORNO-'.Str::uuid();

        return new RespostaGateway(StatusPagamento::ESTORNADO, $transacao, null, [
            'transacao' => $transacao, 'estornoDe' => $pagamento->transacao_externa_id,
        ]);
    }
}
