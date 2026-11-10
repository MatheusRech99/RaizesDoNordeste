<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditoriaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'usuarioId' => $this->usuario_id,
            'acao' => $this->acao->value,
            'entidade' => $this->entidade,
            'entidadeId' => $this->entidade_id,
            'dadosAntes' => $this->dados_antes,
            'dadosDepois' => $this->dados_depois,
            'ip' => $this->ip,
            'em' => $this->created_at->toIso8601String(),
        ];
    }
}
