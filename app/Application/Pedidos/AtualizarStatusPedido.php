<?php

namespace App\Application\Pedidos;

use App\Domain\Auditoria\Acao;
use App\Domain\Pedidos\Pedido;
use App\Domain\Pedidos\StatusPedido;
use App\Domain\Usuarios\Usuario;
use App\Infrastructure\Auditoria\AuditLogger;
use Illuminate\Support\Facades\DB;

class AtualizarStatusPedido
{
    public function __construct(private AuditLogger $auditoria)
    {
    }

    public function executar(Usuario $quem, Pedido $pedido, StatusPedido $novo): Pedido
    {
        return DB::transaction(function () use ($quem, $pedido, $novo) {
            // lock e releitura: dois cliques ao mesmo tempo nao podem passar pela mesma transicao
            $pedido = Pedido::lockForUpdate()->findOrFail($pedido->id);
            $antes = $pedido->status;

            $pedido->transicionarPara($novo, $quem->id);

            $this->auditoria->registrar(
                Acao::ATUALIZACAO_STATUS_PEDIDO,
                $pedido,
                ['status' => $antes->value],
                ['status' => $novo->value],
                $quem->id,
            );

            return $pedido->load(['itens.produto', 'historico']);
        });
    }
}
