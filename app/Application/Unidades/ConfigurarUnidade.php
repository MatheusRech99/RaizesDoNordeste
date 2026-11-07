<?php

namespace App\Application\Unidades;

use App\Domain\Exceptions\CodigoErro;
use App\Domain\Exceptions\NegocioException;
use App\Domain\Unidades\FormatoUnidade;
use App\Domain\Unidades\Unidade;
use Illuminate\Support\Facades\DB;

class ConfigurarUnidade
{
    public function executar(Unidade $unidade, array $dados): Unidade
    {
        // RN17: nao da para virar reduzida com produto de cozinha completa no cardapio
        if (($dados['formato'] ?? null) === FormatoUnidade::REDUZIDA->value) {
            $incompativeis = $unidade->itensCardapio()
                ->whereHas('produto', fn ($q) => $q->where('exige_cozinha_completa', true))
                ->with('produto')
                ->get();

            if ($incompativeis->isNotEmpty()) {
                throw new NegocioException(
                    CodigoErro::PRODUTO_INCOMPATIVEL_COM_UNIDADE,
                    'O cardápio tem produtos que exigem cozinha completa',
                    $incompativeis->map(fn ($i) => ['produtoId' => $i->produto_id, 'nome' => $i->produto->nome])->all(),
                );
            }
        }

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
