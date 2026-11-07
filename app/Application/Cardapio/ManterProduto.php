<?php

namespace App\Application\Cardapio;

use App\Domain\Cardapio\ItemCardapio;
use App\Domain\Cardapio\Produto;
use App\Domain\Exceptions\CodigoErro;
use App\Domain\Exceptions\NegocioException;
use App\Domain\Unidades\FormatoUnidade;

class ManterProduto
{
    public function criar(array $dados): Produto
    {
        return Produto::create($this->colunas($dados));
    }

    public function atualizar(Produto $produto, array $dados): Produto
    {
        $colunas = $this->colunas($dados);

        // RN17: nao da para passar a exigir cozinha completa se ele ja esta no cardapio de unidade reduzida
        if (($colunas['exige_cozinha_completa'] ?? false) && ! $produto->exige_cozinha_completa) {
            $itens = ItemCardapio::where('produto_id', $produto->id)
                ->whereHas('unidade', fn ($q) => $q->where('formato', FormatoUnidade::REDUZIDA))
                ->with('unidade')
                ->get();

            if ($itens->isNotEmpty()) {
                throw new NegocioException(
                    CodigoErro::PRODUTO_INCOMPATIVEL_COM_UNIDADE,
                    'O produto está no cardápio de unidades em formato reduzido',
                    $itens->map(fn ($i) => ['unidadeId' => $i->unidade_id, 'nome' => $i->unidade->nome])->all(),
                );
            }
        }

        $produto->update($colunas);

        return $produto;
    }

    private function colunas(array $dados): array
    {
        $mapa = [
            'nome' => 'nome',
            'descricao' => 'descricao',
            'categoria' => 'categoria',
            'exigeCozinhaCompleta' => 'exige_cozinha_completa',
            'ativo' => 'ativo',
        ];

        $colunas = [];
        foreach ($mapa as $campo => $coluna) {
            if (array_key_exists($campo, $dados)) {
                $colunas[$coluna] = $dados[$campo];
            }
        }

        return $colunas;
    }
}
