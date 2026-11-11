<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

class RelatorioRequest extends ListagemRequest
{
    private const MAX_DIAS = 366;

    // RNF12: periodo sempre obrigatorio e limitado
    public function rules(): array
    {
        return parent::rules() + [
            'de' => 'required|date_format:Y-m-d',
            'ate' => 'required|date_format:Y-m-d|after_or_equal:de',
            'unidadeId' => 'integer|min:1',
            'regiao' => 'in:NE,SE,S,CO,N',
        ];
    }

    public function messages(): array
    {
        return [
            'de.required' => 'Informe o início do período (AAAA-MM-DD)',
            'ate.required' => 'Informe o fim do período (AAAA-MM-DD)',
            'ate.after_or_equal' => 'O fim do período não pode ser antes do início',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['de', 'ate'])) {
                return;
            }

            if ($this->date('de')->diffInDays($this->date('ate')) > self::MAX_DIAS) {
                $validator->errors()->add('ate', 'O período pode ter no máximo '.self::MAX_DIAS.' dias');
            }
        }];
    }
}
