<?php

namespace Database\Factories;

use App\Domain\Cardapio\ItemCardapio;
use App\Domain\Cardapio\Produto;
use App\Domain\Unidades\Unidade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemCardapio>
 */
class ItemCardapioFactory extends Factory
{
    protected $model = ItemCardapio::class;

    public function definition(): array
    {
        return [
            'unidade_id' => Unidade::factory(),
            'produto_id' => Produto::factory(),
            'preco' => '12.50',
            'disponivel' => true,
        ];
    }

    public function sazonal(string $inicio, string $fim): static
    {
        return $this->state(['inicio_sazonal' => $inicio, 'fim_sazonal' => $fim]);
    }

    public function indisponivel(): static
    {
        return $this->state(['disponivel' => false]);
    }
}
