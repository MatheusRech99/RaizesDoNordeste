<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsentimentoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'finalidade' => $this->finalidade->value,
            'aceito' => $this->ativo(),
            'versaoTermo' => $this->versao_termo,
            'concedidoEm' => $this->created_at->toIso8601String(),
            'revogadoEm' => $this->revogado_em?->toIso8601String(),
        ];
    }
}
