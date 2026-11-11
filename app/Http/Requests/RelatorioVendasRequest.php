<?php

namespace App\Http\Requests;

class RelatorioVendasRequest extends RelatorioRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'canalPedido' => 'in:APP,TOTEM,BALCAO,PICKUP,WEB',
            'agruparPor' => 'in:unidade,regiao,canal',
        ];
    }
}
