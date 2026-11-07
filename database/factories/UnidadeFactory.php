<?php

namespace Database\Factories;

use App\Domain\Unidades\FormatoUnidade;
use App\Domain\Unidades\Unidade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unidade>
 */
class UnidadeFactory extends Factory
{
    protected $model = Unidade::class;

    public function definition(): array
    {
        return [
            'nome' => 'Raízes '.fake()->city(),
            'cidade' => fake()->city(),
            'uf' => 'PE',
            'regiao' => 'NE',
            'formato' => FormatoUnidade::COMPLETA,
            'ativa' => true,
        ];
    }

    public function inativa(): static
    {
        return $this->state(['ativa' => false]);
    }

    public function reduzida(): static
    {
        return $this->state(['formato' => FormatoUnidade::REDUZIDA]);
    }
}
