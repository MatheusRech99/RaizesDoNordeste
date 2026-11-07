<?php

namespace Database\Seeders;

use App\Domain\Cardapio\Produto;
use Illuminate\Database\Seeder;

class ProdutoSeeder extends Seeder
{
    public function run(): void
    {
        $produtos = [
            ['Tapioca de queijo coalho', 'Tapioca recheada com queijo coalho derretido', 'Lanches', false],
            ['Cuscuz com ovo', 'Cuscuz de milho com ovo mexido e manteiga de garrafa', 'Lanches', false],
            ['Macaxeira frita', 'Porção de macaxeira frita com molho da casa', 'Porções', false],
            ['Baião de dois', 'Arroz com feijão de corda, queijo coalho e nata', 'Pratos', true],
            ['Carne de sol com macaxeira', 'Carne de sol na chapa com macaxeira cozida e vinagrete', 'Pratos', true],
            ['Bolo de milho', 'Fatia de bolo de milho verde', 'Sobremesas', false],
            ['Canjica', 'Canjica cremosa com amendoim', 'Sobremesas', false],
            ['Suco de caju', 'Suco natural de caju, 400 ml', 'Bebidas', false],
            ['Refrigerante lata', 'Lata de 350 ml', 'Bebidas', false],
        ];

        foreach ($produtos as [$nome, $descricao, $categoria, $completa]) {
            Produto::create([
                'nome' => $nome,
                'descricao' => $descricao,
                'categoria' => $categoria,
                'exige_cozinha_completa' => $completa,
            ]);
        }
    }
}
