<?php

namespace App\Http\Requests;

use App\Domain\Auditoria\Acao;
use Illuminate\Validation\Rule;

class ListarAuditoriaRequest extends ListagemRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'acao' => [Rule::in(array_column(Acao::cases(), 'value'))],
            'entidade' => 'string|max:60',
            'entidadeId' => 'integer|min:1',
            'usuarioId' => 'integer|min:1',
            'de' => 'date_format:Y-m-d',
            'ate' => 'date_format:Y-m-d|after_or_equal:de',
        ];
    }
}
