<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PedidoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'unidadeId' => $this->unidade_id,
            'clienteId' => $this->cliente_id,
            'criadoPor' => $this->criado_por,
            'canalPedido' => $this->canal_pedido->value,
            'status' => $this->status->value,
            'subtotal' => (float) $this->subtotal,
            'desconto' => (float) $this->desconto,
            'total' => (float) $this->total,
            'motivoCancelamento' => $this->motivo_cancelamento,
            'itens' => $this->itens->map(fn ($i) => [
                'produtoId' => $i->produto_id,
                'nome' => $i->produto->nome,
                'quantidade' => $i->quantidade,
                'precoUnitario' => (float) $i->preco_unitario,
                'subtotal' => (float) $i->subtotal,
            ])->all(),
            'historico' => $this->whenLoaded('historico', fn () => $this->historico->map(fn ($h) => [
                'statusAnterior' => $h->status_anterior?->value,
                'statusNovo' => $h->status_novo->value,
                'usuarioId' => $h->usuario_id,
                'em' => $h->created_at->toIso8601String(),
            ])->all()),
            'criadoEm' => $this->created_at->toIso8601String(),
        ];
    }
}
