<?php

namespace App\Application\Pagamentos;

use App\Application\Fidelidade\CreditarPontos;
use App\Application\Pedidos\DevolverEstoque;
use App\Domain\Auditoria\Acao;
use App\Domain\Pagamentos\Pagamento;
use App\Domain\Pagamentos\RespostaGateway;
use App\Domain\Pagamentos\StatusPagamento;
use App\Domain\Pedidos\StatusPedido;
use App\Domain\Usuarios\Usuario;
use App\Infrastructure\Auditoria\AuditLogger;

// RF20: aplica a resposta do gateway no pagamento e no pedido. Roda dentro da transacao de quem chama
class ProcessarRetornoGateway
{
    public function __construct(
        private DevolverEstoque $devolverEstoque,
        private CreditarPontos $creditarPontos,
        private AuditLogger $auditoria,
    ) {
    }

    public function executar(Pagamento $pagamento, RespostaGateway $resposta, Usuario $quem): Pagamento
    {
        // RN08: retorno repetido da mesma transacao nao mexe em nada
        if ($pagamento->status !== StatusPagamento::PENDENTE) {
            return $pagamento;
        }

        $pedido = $pagamento->pedido;

        $pagamento->update([
            'status' => $resposta->status,
            'transacao_externa_id' => $resposta->transacaoId,
            'resposta_gateway' => $resposta->bruta,
            'processado_em' => now(),
        ]);

        $this->auditoria->registrar(Acao::PAGAMENTO_PEDIDO, $pagamento, ['status' => 'PENDENTE'], [
            'status' => $resposta->status->value,
            'valor' => $pagamento->valor,
            'pedidoId' => $pedido->id,
        ], $quem->id);

        if ($resposta->status === StatusPagamento::APROVADO) {
            $pedido->transicionarPara(StatusPedido::PAGO, $quem->id);
            $this->creditarPontos->executar($pedido);

            return $pagamento;
        }

        // RN09: recusado cancela o pedido e devolve o estoque
        $motivo = 'PAGAMENTO_RECUSADO';
        $pedido->transicionarPara(StatusPedido::CANCELADO, $quem->id);
        $pedido->update(['motivo_cancelamento' => $motivo]);
        $this->devolverEstoque->executar($pedido, $quem, "Cancelamento do pedido #{$pedido->id}: {$motivo}");

        $this->auditoria->registrar(Acao::CANCELAMENTO_PEDIDO, $pedido, ['status' => 'AGUARDANDO_PAGAMENTO'], [
            'status' => 'CANCELADO',
            'motivo' => $motivo,
        ], $quem->id);

        return $pagamento;
    }
}
