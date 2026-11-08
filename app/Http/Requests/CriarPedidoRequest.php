<?php

namespace App\Http\Requests;

use App\Domain\Pedidos\CanalPedido;
use App\Domain\Usuarios\Perfil;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CriarPedidoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'canalPedido' => ['required', Rule::in(array_column(CanalPedido::cases(), 'value'))],
            'unidadeId' => 'required|integer|min:1',
            'clienteId' => ['nullable', 'integer', Rule::exists('usuarios', 'id')->where('perfil', Perfil::CLIENTE->value)],
            'itens' => 'required|array|min:1|max:50',
            'itens.*.produtoId' => 'required|integer|min:1|distinct',
            'itens.*.quantidade' => 'required|integer|min:1|max:99',
        ];
    }

    public function messages(): array
    {
        return [
            'canalPedido.required' => 'Informe o canal do pedido',
            'canalPedido.in' => 'Canal inválido, use APP, TOTEM, BALCAO, PICKUP ou WEB',
            'unidadeId.required' => 'Informe a unidade',
            'clienteId.exists' => 'Cliente não encontrado',
            'itens.required' => 'O pedido precisa de pelo menos um item',
            'itens.min' => 'O pedido precisa de pelo menos um item',
            'itens.max' => 'No máximo 50 itens por pedido',
            'itens.*.produtoId.required' => 'Informe o produto do item',
            'itens.*.produtoId.distinct' => 'O mesmo produto não pode aparecer em dois itens',
            'itens.*.quantidade.required' => 'Informe a quantidade do item',
            'itens.*.quantidade.integer' => 'A quantidade deve ser um número inteiro',
            'itens.*.quantidade.min' => 'A quantidade deve ser pelo menos 1',
            'itens.*.quantidade.max' => 'No máximo 99 unidades por item',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $perfil = $this->user()->perfil;
            $canal = $this->input('canalPedido');

            if ($perfil === Perfil::CLIENTE) {
                if ($canal === CanalPedido::BALCAO->value) {
                    $validator->errors()->add('canalPedido', 'O canal BALCAO é só do atendente');
                }
                if ($this->filled('clienteId')) {
                    $validator->errors()->add('clienteId', 'O cliente não informa clienteId, o pedido é dele');
                }

                return;
            }

            // atendente: fora do balcao o pedido tem que ter cliente
            if ($canal !== CanalPedido::BALCAO->value && ! $this->filled('clienteId') && ! $validator->errors()->has('canalPedido')) {
                $validator->errors()->add('clienteId', 'Informe o cliente nos canais que não são BALCAO');
            }
        }];
    }
}
