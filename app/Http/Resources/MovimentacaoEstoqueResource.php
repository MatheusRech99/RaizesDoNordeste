<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Estoque\MovimentacaoEstoque */
class MovimentacaoEstoqueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'produtoId' => $this->estoque->produto_id,
            'tipo' => $this->tipo->value,
            'quantidade' => $this->quantidade,
            'saldoApos' => $this->saldo_apos,
            'motivo' => $this->motivo,
            'pedidoId' => $this->pedido_id,
            'usuarioId' => $this->usuario_id,
            'criadoEm' => $this->created_at->toIso8601String(),
        ];
    }
}
