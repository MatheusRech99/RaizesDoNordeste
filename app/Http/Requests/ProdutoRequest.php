<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProdutoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'nome' => 'required|string|max:255',
            'descricao' => 'nullable|string|max:2000',
            'categoria' => 'required|string|max:100',
            'exigeCozinhaCompleta' => 'sometimes|boolean',
            'ativo' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'Informe o nome do produto',
            'categoria.required' => 'Informe a categoria',
            'exigeCozinhaCompleta.boolean' => 'Use true ou false',
            'ativo.boolean' => 'Use true ou false',
        ];
    }
}
