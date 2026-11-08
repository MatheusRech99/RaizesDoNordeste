<?php

namespace Database\Seeders;

use App\Application\Clientes\CadastrarCliente;
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

        // passa pelo cadastro de verdade para ja ter os consentimentos gravados
        app(CadastrarCliente::class)->executar([
            'nome' => 'Cliente Teste',
            'email' => 'cliente@raizes.com',
            'senha' => self::SENHA,
            'dataNascimento' => '1995-04-10',
            'consentimentoFidelidade' => true,
            'consentimentoMarketing' => false,
        ], '127.0.0.1');
    }
}
