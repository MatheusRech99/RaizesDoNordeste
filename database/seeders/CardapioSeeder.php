<?php

namespace Database\Seeders;

use App\Domain\Cardapio\ItemCardapio;
use App\Domain\Cardapio\Produto;
use App\Domain\Unidades\FormatoUnidade;
use App\Domain\Unidades\Unidade;
use Illuminate\Database\Seeder;

class CardapioSeeder extends Seeder
{
    // preco base por produto; o periodo sazonal so vale para os produtos juninos
    private const PRECOS = [
        'Tapioca de queijo coalho' => 12.00,
        'Cuscuz com ovo' => 14.00,
        'Macaxeira frita' => 18.00,
        'Baião de dois' => 32.00,
        'Carne de sol com macaxeira' => 42.00,
        'Bolo de milho' => 9.00,
        'Canjica' => 10.00,
        'Suco de caju' => 8.00,
        'Refrigerante lata' => 6.00,
    ];

    private const JUNINOS = ['Bolo de milho', 'Canjica'];

    public function run(): void
    {
        $produtos = Produto::all();

        foreach (Unidade::all() as $unidade) {
            // cada unidade cobra um pouco diferente (so para ter dado variado no teste)
            $ajuste = 1 + ($unidade->id - 1) * 0.05;

            foreach ($produtos as $produto) {
                if ($produto->exige_cozinha_completa && $unidade->formato === FormatoUnidade::REDUZIDA) {
                    continue;
                }

                $junino = in_array($produto->nome, self::JUNINOS);

                ItemCardapio::create([
                    'unidade_id' => $unidade->id,
                    'produto_id' => $produto->id,
                    'preco' => round(self::PRECOS[$produto->nome] * $ajuste, 2),
                    'inicio_sazonal' => $junino ? '05-15' : null,
                    'fim_sazonal' => $junino ? '07-15' : null,
                    'descricao_regional' => $this->regional($unidade, $produto),
                ]);
            }
        }
    }

    private function regional(Unidade $unidade, Produto $produto): ?string
    {
        if ($unidade->cidade === 'Fortaleza' && $produto->nome === 'Tapioca de queijo coalho') {
            return 'Tapioca cearense com coco ralado e queijo coalho';
        }

        return null;
    }
}
