<?php

namespace App\Application\Pedidos;

use App\Domain\Auditoria\Acao;
use App\Domain\Exceptions\CodigoErro;
use App\Domain\Exceptions\NegocioException;
use App\Domain\Pagamentos\FalhaNoGateway;
use App\Domain\Pagamentos\PaymentGateway;
use App\Domain\Pagamentos\StatusPagamento;
use App\Domain\Pedidos\Pedido;
use App\Domain\Pedidos\StatusPedido;
use App\Domain\Usuarios\Usuario;
use App\Infrastructure\Auditoria\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CancelarPedido
{
    public function __construct(
        private DevolverEstoque $devolverEstoque,
        private PaymentGateway $gateway,
        private AuditLogger $auditoria,
    ) {
    }

    // tudo ou nada: se o estorno no gateway falhar, o pedido continua como estava
    public function executar(Usuario $quem, Pedido $pedido, string $motivo): Pedido
    {
        return DB::transaction(function () use ($quem, $pedido, $motivo) {
            $pedido = Pedido::lockForUpdate()->findOrFail($pedido->id);
            $antes = $pedido->status;

            // estado final ou fora da maquina vira 409 la embaixo; so confere o perfil quando da pra cancelar
            if ($antes->podeIrPara(StatusPedido::CANCELADO)) {
                Gate::forUser($quem)->authorize('cancelar', $pedido);
            }

            $pedido->transicionarPara(StatusPedido::CANCELADO, $quem->id);
            $pedido->update(['motivo_cancelamento' => $motivo]);
            $this->devolverEstoque->executar($pedido, $quem, "Cancelamento do pedido #{$pedido->id}: {$motivo}");

            $estornou = $this->estornarPagamento($pedido, $quem);

            $this->auditoria->registrar(Acao::CANCELAMENTO_PEDIDO, $pedido, ['status' => $antes->value], [
                'status' => 'CANCELADO',
                'motivo' => $motivo,
                'pagamentoEstornado' => $estornou,
            ], $quem->id);

            return $pedido->load(['itens.produto', 'historico']);
        });
    }

    // RN09: pedido ja pago gera estorno mock
    private function estornarPagamento(Pedido $pedido, Usuario $quem): bool
    {
        $pagamento = $pedido->pagamentos()->where('status', StatusPagamento::APROVADO)->first();
        if (! $pagamento) {
            return false;
        }

        try {
            $resposta = $this->gateway->estornar($pagamento);
        } catch (FalhaNoGateway) {
            $resposta = null;
        }

        if (! $resposta || $resposta->status !== StatusPagamento::ESTORNADO) {
            throw new NegocioException(CodigoErro::GATEWAY_INDISPONIVEL, 'Não foi possível estornar o pagamento; o pedido não foi cancelado, tente novamente', [[
                'pagamentoId' => $pagamento->id,
            ]]);
        }

        $pagamento->update([
            'status' => StatusPagamento::ESTORNADO,
            'resposta_gateway' => ($pagamento->resposta_gateway ?? []) + ['estorno' => $resposta->bruta],
        ]);

        $this->auditoria->registrar(Acao::PAGAMENTO_PEDIDO, $pagamento, ['status' => 'APROVADO'], [
            'status' => 'ESTORNADO',
            'valor' => $pagamento->valor,
            'pedidoId' => $pedido->id,
        ], $quem->id);

        return true;
    }
}
