<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ItemCardapioRequest extends FormRequest
{
    private const MES_DIA = '/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/';

    public function rules(): array
    {
        return [
            'preco' => 'required|numeric|min:0.01|max:99999999.99',
            'disponivel' => 'sometimes|boolean',
            'inicioSazonal' => ['nullable', 'required_with:fimSazonal', 'regex:'.self::MES_DIA],
            'fimSazonal' => ['nullable', 'required_with:inicioSazonal', 'regex:'.self::MES_DIA],
            'descricaoRegional' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'preco.required' => 'Informe o preço',
            'preco.min' => 'O preço deve ser maior que zero',
            'inicioSazonal.regex' => 'Use o formato MM-DD, por exemplo 06-01',
            'fimSazonal.regex' => 'Use o formato MM-DD, por exemplo 07-15',
            'inicioSazonal.required_with' => 'Informe o início e o fim do período sazonal',
            'fimSazonal.required_with' => 'Informe o início e o fim do período sazonal',
        ];
    }

    // o regex deixa passar 02-31, entao confere o dia de verdade (2024 e bissexto, aceita 02-29)
    public function after(): array
    {
        return [function (Validator $validator) {
            foreach (['inicioSazonal', 'fimSazonal'] as $campo) {
                $valor = $this->input($campo);

                if (! is_string($valor) || $validator->errors()->has($campo)) {
                    continue;
                }

                [$mes, $dia] = explode('-', $valor);
                if (! checkdate((int) $mes, (int) $dia, 2024)) {
                    $validator->errors()->add($campo, 'Data inexistente');
                }
            }
        }];
    }
}
