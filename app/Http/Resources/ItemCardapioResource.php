<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemCardapioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'produtoId' => $this->produto_id,
            'nome' => $this->produto->nome,
            // a descricao regional da unidade vale mais que a do catalogo
            'descricao' => $this->descricao_regional ?? $this->produto->descricao,
            'categoria' => $this->produto->categoria,
            'preco' => (float) $this->preco,
            'disponivel' => $this->disponivel,
            'inicioSazonal' => $this->inicio_sazonal,
            'fimSazonal' => $this->fim_sazonal,
        ];
    }
}
