<?php

namespace Database\Seeders;

use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use Illuminate\Database\Seeder;

class UsuarioSeeder extends Seeder
{
    // senha unica so para ambiente de desenvolvimento
    private const SENHA = 'senha123';

    public function run(): void
    {
        Usuario::create([
            'nome' => 'Admin Matriz',
            'email' => 'admin@raizes.com',
            'senha' => self::SENHA,
            'perfil' => Perfil::ADMIN,
        ]);

        $unidades = Unidade::orderBy('id')->get();

        foreach ($unidades as $i => $unidade) {
            $n = $i + 1;

            foreach ([Perfil::GERENTE, Perfil::ATENDENTE, Perfil::COZINHA] as $perfil) {
                $nome = strtolower($perfil->value);

                Usuario::create([
                    'nome' => ucfirst($nome)." Unidade $n",
                    'email' => "$nome$n@raizes.com",
                    'senha' => self::SENHA,
                    'perfil' => $perfil,
                    'unidade_id' => $unidade->id,
                ]);
            }
        }

        Usuario::create([
            'nome' => 'Cliente Teste',
            'email' => 'cliente@raizes.com',
            'senha' => self::SENHA,
            'perfil' => Perfil::CLIENTE,
        ]);
    }
}
