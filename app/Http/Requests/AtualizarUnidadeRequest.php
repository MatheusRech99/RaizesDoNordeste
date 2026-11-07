<?php

namespace App\Http\Requests;

use App\Domain\Unidades\FormatoUnidade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AtualizarUnidadeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'formato' => ['required_without:horarios', Rule::enum(FormatoUnidade::class)],
            'horarios' => 'required_without:formato|array|min:1',
            'horarios.*.diaSemana' => 'required|integer|between:0,6|distinct',
            'horarios.*.abre' => 'required|date_format:H:i',
            'horarios.*.fecha' => 'required|date_format:H:i',
        ];
    }

    public function messages(): array
    {
        return [
            'formato.required_without' => 'Informe o formato ou os horários',
            'formato.enum' => 'Formato inválido, use COMPLETA ou REDUZIDA',
            'horarios.required_without' => 'Informe os horários ou o formato',
            'horarios.*.diaSemana.between' => 'O dia da semana vai de 0 (domingo) a 6 (sábado)',
            'horarios.*.diaSemana.distinct' => 'Dia da semana repetido',
            'horarios.*.abre.date_format' => 'Use o formato HH:MM',
            'horarios.*.fecha.date_format' => 'Use o formato HH:MM',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $horarios = $this->input('horarios');
            if (! is_array($horarios)) {
                return;
            }

            foreach ($horarios as $i => $h) {
                if (! is_array($h) || $validator->errors()->hasAny(["horarios.$i.abre", "horarios.$i.fecha"])) {
                    continue;
                }
                if (isset($h['abre'], $h['fecha']) && $h['fecha'] <= $h['abre']) {
                    $validator->errors()->add("horarios.$i.fecha", 'O fechamento deve ser depois da abertura');
                }
            }
        }];
    }
}
