<?php

namespace Database\Factories;

use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Usuario>
 */
class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    public function definition(): array
    {
        return [
            'nome' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'senha' => 'senha123',
            'perfil' => Perfil::CLIENTE,
            'telefone' => fake()->numerify('819########'),
            'data_nascimento' => fake()->date(),
        ];
    }
}
