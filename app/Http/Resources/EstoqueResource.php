<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Estoque\Estoque */
class EstoqueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'produtoId' => $this->produto_id,
            'nome' => $this->produto->nome,
            'quantidade' => $this->quantidade,
        ];
    }
}
