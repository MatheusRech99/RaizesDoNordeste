<?php

namespace App\Application\Unidades;

use App\Domain\Unidades\Unidade;
use Illuminate\Support\Facades\DB;

class ConfigurarUnidade
{
    // RN17 (produto que exige cozinha completa x formato reduzido) entra na etapa 4, junto com o cardapio
    public function executar(Unidade $unidade, array $dados): Unidade
    {
        DB::transaction(function () use ($unidade, $dados) {
            if (isset($dados['formato'])) {
                $unidade->update(['formato' => $dados['formato']]);
            }

            // a lista de horarios enviada substitui a que existia; dia fora da lista fica fechado
            if (isset($dados['horarios'])) {
                $unidade->horarios()->delete();

                foreach ($dados['horarios'] as $h) {
                    $unidade->horarios()->create([
                        'dia_semana' => $h['diaSemana'],
                        'abre' => $h['abre'].':00',
                        'fecha' => $h['fecha'].':00',
                    ]);
                }
            }
        });

        return $unidade->load('horarios');
    }
}
