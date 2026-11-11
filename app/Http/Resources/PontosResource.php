<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Fidelidade\PontosFidelidade */
class PontosResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo->value,
            'pontos' => $this->pontos,
            'pedidoId' => $this->pedido_id,
            'em' => $this->created_at->toIso8601String(),
        ];
    }
}
