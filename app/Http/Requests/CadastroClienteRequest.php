<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CadastroClienteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'nome' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:usuarios,email',
            'senha' => 'required|string|min:8|max:72',
            'telefone' => 'nullable|string|max:20',
            'dataNascimento' => 'nullable|date_format:Y-m-d|before:today',
            'aceiteTermos' => 'required|accepted',
            'consentimentoFidelidade' => 'sometimes|boolean',
            'consentimentoMarketing' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'Informe o nome',
            'email.required' => 'Informe o e-mail',
            'email.email' => 'E-mail inválido',
            'email.unique' => 'Já existe um cadastro com esse e-mail',
            'senha.required' => 'Informe a senha',
            'senha.min' => 'A senha deve ter pelo menos 8 caracteres',
            'dataNascimento.date_format' => 'Use o formato AAAA-MM-DD',
            'dataNascimento.before' => 'A data de nascimento deve estar no passado',
            'aceiteTermos.required' => 'É necessário aceitar os termos',
            'aceiteTermos.accepted' => 'É necessário aceitar os termos',
            'consentimentoFidelidade.boolean' => 'Use true ou false',
            'consentimentoMarketing.boolean' => 'Use true ou false',
        ];
    }

    // RN14: so aceita nascimento se o cliente consentiu com a fidelidade
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('dataNascimento')) {
                return;
            }

            if ($this->filled('dataNascimento') && ! $this->boolean('consentimentoFidelidade')) {
                $validator->errors()->add('dataNascimento', 'A data de nascimento só pode ser informada com o consentimento de fidelidade');
            }
        }];
    }
}
