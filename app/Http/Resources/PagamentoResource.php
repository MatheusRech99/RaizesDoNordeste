<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PagamentoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pedidoId' => $this->pedido_id,
            'status' => $this->status->value,
            'valor' => (float) $this->valor,
            'metodo' => $this->metodo,
            'transacaoExternaId' => $this->transacao_externa_id,
            'processadoEm' => $this->processado_em?->toIso8601String(),
            'pedidoStatus' => $this->whenLoaded('pedido', fn () => $this->pedido->status->value),
        ];
    }
}
