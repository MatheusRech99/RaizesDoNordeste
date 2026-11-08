<?php

namespace App\Http\Requests;

class MovimentacoesRequest extends ListagemRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'produtoId' => 'integer|min:1',
            'tipo' => 'in:ENTRADA,SAIDA,AJUSTE,ESTORNO',
        ];
    }
}
