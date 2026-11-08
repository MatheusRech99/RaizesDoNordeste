<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class MovimentarEstoqueRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'produtoId' => 'required|integer|min:1',
            'tipo' => 'required|in:ENTRADA,SAIDA,AJUSTE',
            'quantidade' => 'required|integer|between:-1000000,1000000',
            'motivo' => 'required|string|min:3|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'produtoId.required' => 'Informe o produto',
            'tipo.required' => 'Informe o tipo da movimentação',
            'tipo.in' => 'Use ENTRADA, SAIDA ou AJUSTE',
            'quantidade.required' => 'Informe a quantidade',
            'quantidade.integer' => 'A quantidade deve ser um número inteiro',
            'quantidade.between' => 'Quantidade fora do limite',
            'motivo.required' => 'Informe o motivo',
            'motivo.min' => 'O motivo deve ter pelo menos 3 caracteres',
        ];
    }

    // entrada e saida sao sempre positivas; o ajuste pode ser negativo mas nao zero
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('quantidade') || $validator->errors()->has('tipo')) {
                return;
            }

            $qtd = (int) $this->input('quantidade');

            if ($this->input('tipo') === 'AJUSTE' && $qtd === 0) {
                $validator->errors()->add('quantidade', 'O ajuste não pode ser zero');
            }

            if ($this->input('tipo') !== 'AJUSTE' && $qtd <= 0) {
                $validator->errors()->add('quantidade', 'A quantidade deve ser maior que zero');
            }
        }];
    }
}
