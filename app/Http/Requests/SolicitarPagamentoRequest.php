<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SolicitarPagamentoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'pedidoId' => 'required|integer|min:1',
            'valor' => ['nullable', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'simularResultado' => 'nullable|in:APROVADO,RECUSADO,ERRO',
        ];
    }

    public function messages(): array
    {
        return [
            'pedidoId.required' => 'Informe o pedido',
            'valor.regex' => 'Informe o valor em reais, com até 2 casas decimais',
            'simularResultado.in' => 'Use APROVADO, RECUSADO ou ERRO',
        ];
    }

    // o mock so simula resultado fora de producao
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->filled('simularResultado') && app()->environment('production')) {
                $validator->errors()->add('simularResultado', 'Não é aceito em produção');
            }
        }];
    }
}
