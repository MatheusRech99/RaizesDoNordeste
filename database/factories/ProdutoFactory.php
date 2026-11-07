<?php

namespace Database\Factories;

use App\Domain\Cardapio\Produto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Produto>
 */
class ProdutoFactory extends Factory
{
    protected $model = Produto::class;

    public function definition(): array
    {
        return [
            'nome' => fake()->unique()->words(2, true),
            'descricao' => fake()->sentence(),
            'categoria' => 'Lanches',
            'exige_cozinha_completa' => false,
            'ativo' => true,
        ];
    }

    public function exigeCozinhaCompleta(): static
    {
        return $this->state(['exige_cozinha_completa' => true]);
    }

    public function inativo(): static
    {
        return $this->state(['ativo' => false]);
    }
}
