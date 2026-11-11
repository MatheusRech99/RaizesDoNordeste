<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Usuarios\Usuario */
class UsuarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // senha e data de nascimento nunca saem aqui (RNF04)
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'email' => $this->email,
            'perfil' => $this->perfil->value,
            'unidadeId' => $this->unidade_id,
            'telefone' => $this->telefone,
            'consentimentos' => ConsentimentoResource::collection($this->whenLoaded('consentimentos')),
        ];
    }
}
