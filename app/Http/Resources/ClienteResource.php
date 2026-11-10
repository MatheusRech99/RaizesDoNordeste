<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// visao do cliente para perfil interno: e-mail e telefone mascarados, so a faixa etaria (RNF04)
class ClienteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'email' => $this->emailMascarado(),
            'telefone' => $this->telefoneMascarado(),
            'faixaEtaria' => $this->faixaEtaria(),
            'criadoEm' => $this->created_at->toIso8601String(),
        ];
    }
}
