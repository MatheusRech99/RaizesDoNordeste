<?php

namespace App\Http\Requests;

class ListarPedidosRequest extends ListagemRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'canalPedido' => 'in:APP,TOTEM,BALCAO,PICKUP,WEB',
            'status' => 'in:AGUARDANDO_PAGAMENTO,PAGO,EM_PREPARO,PRONTO,ENTREGUE,CANCELADO',
            'unidadeId' => 'integer|min:1',
        ];
    }
}
