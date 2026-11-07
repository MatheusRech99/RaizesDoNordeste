<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListagemRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'page' => 'integer|min:1',
            'limit' => 'integer|min:1|max:100',
        ];
    }

    public function limite(): int
    {
        return (int) $this->query('limit', 15);
    }
}
