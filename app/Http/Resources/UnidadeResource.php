<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Unidades\Unidade */
class UnidadeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'cidade' => $this->cidade,
            'uf' => $this->uf,
            'regiao' => $this->regiao,
            'formato' => $this->formato->value,
            'horarios' => $this->horarios->map(fn ($h) => [
                'diaSemana' => $h->dia_semana,
                'abre' => substr($h->abre, 0, 5),
                'fecha' => substr($h->fecha, 0, 5),
            ])->values(),
        ];
    }
}
