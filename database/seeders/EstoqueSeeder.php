<?php

namespace Database\Seeders;

use App\Application\Estoque\MovimentarEstoque;
use App\Domain\Cardapio\ItemCardapio;
use App\Domain\Estoque\TipoMovimentacao;
use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use Illuminate\Database\Seeder;

class EstoqueSeeder extends Seeder
{
    // carne de sol fica baixa de proposito, para testar o 409 de estoque insuficiente
    private const BAIXOS = ['Carne de sol com macaxeira' => 5];

    public function run(): void
    {
        $movimentar = app(MovimentarEstoque::class);

        foreach (Unidade::all() as $unidade) {
            $gerente = Usuario::where('perfil', Perfil::GERENTE)->where('unidade_id', $unidade->id)->first();

            $itens = ItemCardapio::where('unidade_id', $unidade->id)->with('produto')->get();

            foreach ($itens as $item) {
                $qtd = self::BAIXOS[$item->produto->nome] ?? 50;

                $movimentar->executar($unidade, $item->produto_id, TipoMovimentacao::ENTRADA, $qtd, 'Carga inicial', $gerente);
            }
        }
    }
}
