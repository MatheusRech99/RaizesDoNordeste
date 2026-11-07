<?php

namespace App\Application\Cardapio;

use App\Domain\Cardapio\ItemCardapio;
use App\Domain\Cardapio\Produto;
use App\Domain\Exceptions\CodigoErro;
use App\Domain\Exceptions\NegocioException;
use App\Domain\Unidades\FormatoUnidade;
use App\Domain\Unidades\Unidade;

class MontarCardapio
{
    // cria ou substitui o item do produto no cardapio da unidade
    public function executar(Unidade $unidade, int $produtoId, array $dados): ItemCardapio
    {
        $produto = Produto::acharOuFalha($produtoId);

        if (! $produto->ativo) {
            throw new NegocioException(CodigoErro::PRODUTO_NAO_ENCONTRADO, 'Produto inativo no catálogo');
        }

        if ($produto->exige_cozinha_completa && $unidade->formato === FormatoUnidade::REDUZIDA) {
            throw new NegocioException(
                CodigoErro::PRODUTO_INCOMPATIVEL_COM_UNIDADE,
                'Este produto exige cozinha completa e a unidade tem formato reduzido',
                [['produtoId' => $produto->id, 'nome' => $produto->nome]],
            );
        }

        $item = ItemCardapio::firstOrNew(['unidade_id' => $unidade->id, 'produto_id' => $produto->id]);
        $item->preco = $dados['preco'];
        $item->disponivel = $dados['disponivel'] ?? true;
        $item->inicio_sazonal = $dados['inicioSazonal'] ?? null;
        $item->fim_sazonal = $dados['fimSazonal'] ?? null;
        $item->descricao_regional = $dados['descricaoRegional'] ?? null;
        $item->save();

        return $item->load('produto');
    }
}
