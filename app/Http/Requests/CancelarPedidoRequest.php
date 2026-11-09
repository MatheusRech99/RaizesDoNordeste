<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelarPedidoRequest extends FormRequest
{
    public function rules(): array
    {
        return ['motivo' => 'required|string|min:3|max:255'];
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'Informe o motivo do cancelamento',
            'motivo.min' => 'O motivo deve ter pelo menos 3 caracteres',
            'motivo.max' => 'O motivo deve ter no máximo 255 caracteres',
        ];
    }
}
