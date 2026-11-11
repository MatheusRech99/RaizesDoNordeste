<?php

namespace App\Application\Relatorios;

use App\Domain\Usuarios\Usuario;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

// RF26: produtos mais vendidos (por quantidade) no periodo
class RankingProdutos
{
    public function executar(Usuario $quem, array $f, int $limite): LengthAwarePaginator
    {
        return FiltrosRelatorio::pedidosVendidos($quem, $f)
            ->join('itens_pedido as i', 'i.pedido_id', '=', 'p.id')
            ->join('produtos as pr', 'pr.id', '=', 'i.produto_id')
            ->selectRaw('pr.id as produto_id, pr.nome, sum(i.quantidade) as quantidade, sum(i.subtotal) as faturamento, count(distinct p.id) as pedidos')
            ->groupBy('pr.id', 'pr.nome')
            ->orderByDesc('quantidade')
            ->orderBy('pr.id')
            ->paginate($limite);
    }
}
