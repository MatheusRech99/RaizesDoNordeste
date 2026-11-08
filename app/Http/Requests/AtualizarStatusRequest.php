<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AtualizarStatusRequest extends FormRequest
{
    // PAGO e CANCELADO nao passam por aqui: um vem do pagamento e o outro do cancelamento
    public function rules(): array
    {
        return ['status' => 'required|in:EM_PREPARO,PRONTO,ENTREGUE'];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Informe o novo status',
            'status.in' => 'Use EM_PREPARO, PRONTO ou ENTREGUE',
        ];
    }
}
