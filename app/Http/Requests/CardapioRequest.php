<?php

namespace App\Http\Requests;

class CardapioRequest extends ListagemRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'data' => 'date_format:Y-m-d',
        ];
    }
}
