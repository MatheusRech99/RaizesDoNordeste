<?php

namespace App\Application\Pagamentos;

use App\Domain\Auditoria\Acao;
use App\Domain\Exceptions\CodigoErro;
use App\Domain\Exceptions\NegocioException;
use App\Domain\Pagamentos\FalhaNoGateway;
use App\Domain\Pagamentos\Pagamento;
use App\Domain\Pagamentos\PaymentGateway;
use App\Domain\Pagamentos\StatusPagamento;
use App\Domain\Pedidos\Pedido;
use App\Domain\Pedidos\StatusPedido;
use App\Domain\Usuarios\Usuario;
use App\Infrastructure\Auditoria\AuditLogger;
use Illuminate\Support\Facades\DB;

class SolicitarPagamento
{
    public function __construct(
        private PaymentGateway $gateway,
        private ProcessarRetornoGateway $processarRetorno,
        private AuditLogger $auditoria,
    ) {
    }

    // $valor e opcional: se vier, tem que bater com o total do pedido (RN08).
    // Recusado e erro gravam o resultado e so depois viram 402/503, por isso a excecao sai depois do commit
    public function executar(Usuario $quem, Pedido $pedido, ?string $valor = null, ?string $chave = null, ?string $simularResultado = null): Pagamento
    {
        $pagamento = DB::transaction(function () use ($quem, $pedido, $valor, $chave, $simularResultado) {
            // o lock no pedido segura a cobranca: duas chamadas juntas nao cobram duas vezes
            $pedido = Pedido::lockForUpdate()->findOrFail($pedido->id);

            if ($pedido->status !== StatusPedido::AGUARDANDO_PAGAMENTO) {
                throw new NegocioException(CodigoErro::PEDIDO_NAO_PAGAVEL, 'Só é possível pagar pedido em AGUARDANDO_PAGAMENTO', [
                    ['pedidoId' => $pedido->id, 'status' => $pedido->status->value],
                ]);
            }

            if ($valor !== null && $this->centavos($valor) !== $this->centavos($pedido->total)) {
                throw new NegocioException(CodigoErro::PEDIDO_NAO_PAGAVEL, 'O valor do pagamento deve ser igual ao total do pedido', [
                    ['pedidoId' => $pedido->id, 'total' => $pedido->total, 'valor' => $valor],
                ]);
            }

            $pagamento = Pagamento::create([
                'pedido_id' => $pedido->id,
                'status' => StatusPagamento::PENDENTE,
                'valor' => $pedido->total,
                'metodo' => 'MOCK',
                'idempotency_key' => $chave,
            ]);
            $pagamento->setRelation('pedido', $pedido);

            try {
                $resposta = $this->gateway->cobrar($pedido, $chave ?? "pedido-{$pedido->id}-pagamento-{$pagamento->id}", $simularResultado);
            } catch (FalhaNoGateway $e) {
                // RN09: pedido continua aguardando e o cliente pode tentar de novo
                $pagamento->update([
                    'status' => StatusPagamento::ERRO,
                    'resposta_gateway' => ['erro' => $e->getMessage()],
                    'processado_em' => now(),
                ]);
                $this->auditoria->registrar(Acao::PAGAMENTO_PEDIDO, $pagamento, ['status' => 'PENDENTE'], [
                    'status' => 'ERRO',
                    'valor' => $pagamento->valor,
                    'pedidoId' => $pedido->id,
                ], $quem->id);

                return $pagamento;
            }

            return $this->processarRetorno->executar($pagamento, $resposta, $quem);
        });

        if ($pagamento->status === StatusPagamento::RECUSADO) {
            throw new NegocioException(CodigoErro::PAGAMENTO_RECUSADO, 'Pagamento recusado; o pedido foi cancelado', [[
                'pagamentoId' => $pagamento->id,
                'status' => 'RECUSADO',
                'motivo' => $pagamento->resposta_gateway['motivo'] ?? null,
            ]]);
        }

        if ($pagamento->status === StatusPagamento::ERRO) {
            throw new NegocioException(CodigoErro::GATEWAY_INDISPONIVEL, 'Gateway de pagamento indisponível; tente novamente', [[
                'pagamentoId' => $pagamento->id,
                'status' => 'ERRO',
            ]]);
        }

        return $pagamento->load('pedido');
    }

    private function centavos(string $valor): int
    {
        return (int) round(((float) $valor) * 100);
    }
}
