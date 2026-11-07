<?php

namespace Database\Seeders;

use App\Domain\Unidades\FormatoUnidade;
use App\Domain\Unidades\Unidade;
use Illuminate\Database\Seeder;

class UnidadeSeeder extends Seeder
{
    public function run(): void
    {
        $unidades = [
            ['nome' => 'Raízes Recife - Boa Viagem', 'cidade' => 'Recife', 'uf' => 'PE', 'regiao' => 'NE', 'formato' => FormatoUnidade::COMPLETA, 'domingo' => true],
            ['nome' => 'Raízes Fortaleza - Aldeota', 'cidade' => 'Fortaleza', 'uf' => 'CE', 'regiao' => 'NE', 'formato' => FormatoUnidade::REDUZIDA, 'domingo' => false],
            ['nome' => 'Raízes São Paulo - Pinheiros', 'cidade' => 'São Paulo', 'uf' => 'SP', 'regiao' => 'SE', 'formato' => FormatoUnidade::COMPLETA, 'domingo' => false],
        ];

        foreach ($unidades as $dados) {
            $abreDomingo = $dados['domingo'];
            unset($dados['domingo']);

            $unidade = Unidade::create($dados);

            // domingo (0) so abre onde estiver marcado, o resto vai de segunda a sabado
            foreach (range(0, 6) as $dia) {
                if ($dia === 0 && ! $abreDomingo) {
                    continue;
                }
                $unidade->horarios()->create(['dia_semana' => $dia, 'abre' => '10:00:00', 'fecha' => '22:00:00']);
            }
        }
    }
}
